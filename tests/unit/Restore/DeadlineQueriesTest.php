<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Jobs\TransientFailure;
use WPCheckpoint\Restore\DeadlineQueries;
use WPCheckpoint\Restore\JobFence;
use WPCheckpoint\Restore\Queries;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * A carry back's statements in a run made without WordPress wait for locks no later than its deadline: before each
 * one that may wait, the session's two lock waits are set to the whole seconds left (at least one); with less than a
 * second left it is not sent and fails as a timed-out lock wait (1205); in a fenced transaction that failure rolls the
 * whole transaction back.
 */
final class DeadlineQueriesTest extends TestCase {

	/**
	 * What was sent.
	 *
	 * @var string[]
	 */
	private $sent = array();

	/**
	 * The clock's time now.
	 *
	 * @var float
	 */
	private $now = 100.0;

	/**
	 * A connection that records what it is sent; each statement it runs takes $step seconds on the clock.
	 *
	 * @param float $step Seconds a statement takes.
	 * @return Queries
	 */
	private function connection( float $step = 0.0 ): Queries {
		$test = $this;
		return new class( $test, $step ) implements Queries {
			/**
			 * The test.
			 *
			 * @var DeadlineQueriesTest
			 */
			private $test;
			/**
			 * Seconds a statement takes.
			 *
			 * @var float
			 */
			private $step;
			public function __construct( DeadlineQueriesTest $test, float $step ) {
				$this->test = $test;
				$this->step = $step;
			}
			public function run( string $sql ): int {
				$this->test->sent( $sql, $this->step );
				return 0;
			}
			public function rows( string $sql, array $params = array() ): array {
				$this->test->sent( $sql, $this->step );
				if ( false !== strpos( $sql, 'information_schema' ) ) {
					return array( array( 'InnoDB' ) );
				}
				if ( false !== strpos( $sql, '@@SESSION' ) ) {
					return array( array( '50' ) );
				}
				return array( array( '1' ) );
			}
			public function write( string $sql, array $params ): int {
				$this->test->sent( $sql, $this->step );
				return 1;
			}
		};
	}

	/**
	 * A statement sent (the fake connection's record), and the time it took.
	 *
	 * @param string $sql  Statement.
	 * @param float  $step Seconds.
	 * @return void
	 */
	public function sent( string $sql, float $step ): void {
		$this->sent[] = $sql;
		if ( ! DeadlineQueries::waits_for_nothing( $sql ) ) {
			$this->now += $step; // Only a statement that may wait takes time here.
		}
	}

	/**
	 * A bounded connection over the fake, its deadline $seconds from now.
	 *
	 * @param float $seconds Seconds to the deadline.
	 * @param float $step    Seconds a statement takes.
	 * @return DeadlineQueries
	 */
	private function bounded( float $seconds, float $step = 0.0 ): DeadlineQueries {
		$test = $this;
		return new DeadlineQueries(
			$this->connection( $step ),
			static function () use ( $test ): float {
				return $test->now();
			},
			$this->now + $seconds
		);
	}

	/**
	 * The clock.
	 *
	 * @return float
	 */
	public function now(): float {
		return $this->now;
	}

	public function test_the_waits_are_the_whole_seconds_left_and_shrink(): void {
		$db = $this->bounded( 5.0, 1.5 );
		$db->rows( 'SELECT a FROM t WHERE x = ? LOCK IN SHARE MODE', array( '1' ) );
		$db->write( 'REPLACE INTO t SELECT * FROM u', array() );
		$db->run( 'DELETE FROM t' );
		$this->assertSame(
			array(
				'SET SESSION innodb_lock_wait_timeout = 5',
				'SET SESSION lock_wait_timeout = 5',
				'SELECT a FROM t WHERE x = ? LOCK IN SHARE MODE',
				'SET SESSION innodb_lock_wait_timeout = 3', // 3.5 left.
				'SET SESSION lock_wait_timeout = 3',
				'REPLACE INTO t SELECT * FROM u',
				'SET SESSION innodb_lock_wait_timeout = 2', // 2.0 left.
				'SET SESSION lock_wait_timeout = 2',
				'DELETE FROM t',
			),
			$this->sent
		);
	}

	public function test_with_a_second_left_the_wait_is_one(): void {
		$db = $this->bounded( 1.0 );
		$db->run( 'DELETE FROM t' );
		$this->assertSame( array( 'SET SESSION innodb_lock_wait_timeout = 1', 'SET SESSION lock_wait_timeout = 1', 'DELETE FROM t' ), $this->sent );
	}

	public function test_with_less_than_a_second_left_nothing_is_sent_and_it_fails_as_a_lock_wait(): void {
		$db = $this->bounded( 2.5, 1.6 );
		$db->run( 'DELETE FROM t' ); // The control: sent, with 2.5 left.
		$this->assertContains( 'DELETE FROM t', $this->sent );
		$before = $this->sent;
		try {
			$db->run( 'DELETE FROM u' ); // 0.9 left.
			$this->fail( 'Sent with less than a second left.' );
		} catch ( TransientFailure $e ) {
			$this->assertSame( 1205, $e->getCode() );
		}
		$this->assertSame( $before, $this->sent, 'nothing more was sent' );
	}

	public function test_statements_that_wait_for_no_lock_pass_as_they_are(): void {
		$db = $this->bounded( 0.5 ); // No second left: only such statements may go.
		foreach ( array( 'SET SESSION x = 1', 'select @@SESSION.innodb_lock_wait_timeout', 'START TRANSACTION', '  COMMIT', 'ROLLBACK' ) as $sql ) {
			if ( 0 === stripos( ltrim( $sql ), 'select' ) ) {
				$db->rows( $sql );
			} else {
				$db->run( $sql );
			}
		}
		$this->assertSame( array( 'SET SESSION x = 1', 'select @@SESSION.innodb_lock_wait_timeout', 'START TRANSACTION', '  COMMIT', 'ROLLBACK' ), $this->sent );
		$this->assertFalse( DeadlineQueries::waits_for_nothing( 'SELECT id FROM t FOR UPDATE' ) );
		$this->assertFalse( DeadlineQueries::waits_for_nothing( 'COMMITTED_TABLE_READ' ) );
		$this->assertFalse( DeadlineQueries::waits_for_nothing( 'SETTINGS' ) );
	}

	public function test_in_a_fenced_transaction_running_out_rolls_the_whole_transaction_back(): void {
		$db = $this->bounded( 3.0, 1.0 );
		try {
			JobFence::within(
				$db,
				array(
					'table' => 'wp_wpcheckpoint_jobs',
					'id'    => 7,
					'token' => 'token',
				),
				static function () use ( $db ): void {
					JobFence::assert_locked( $db );
					$db->rows( 'REPLACE INTO t SELECT * FROM u', array() );
					$db->rows( 'DELETE FROM t', array() );
					$db->rows( 'DELETE FROM u', array() );
				}
			);
			$this->fail( 'The carry went past its deadline.' );
		} catch ( TransientFailure $e ) {
			$this->assertSame( 1205, $e->getCode() );
		}
		$this->assertContains( 'START TRANSACTION', $this->sent );
		$this->assertContains( 'REPLACE INTO t SELECT * FROM u', $this->sent, 'the control: statements went before the deadline' );
		$this->assertNotContains( 'COMMIT', $this->sent );
		$this->assertSame( 'ROLLBACK', end( $this->sent ), 'the whole transaction rolled back' );
	}
}
