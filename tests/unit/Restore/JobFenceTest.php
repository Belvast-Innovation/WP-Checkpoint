<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Jobs\LockLost;
use WPCheckpoint\Restore\JobFence;
use WPCheckpoint\Restore\Queries;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The fence's shape on a recorded connection: a transaction whose first statement locks the job row under the token,
 * the writes, a commit; a quiet rollback and the exception when the writes throw; no row under the token, LockLost
 * and nothing written; a table that is not InnoDB refused before any locked read; the writers' guard refusing
 * outside the fence; the bounded wait put back; an empty token refused before any statement; the engine read on
 * every fence, not once per connection. The database's own behaviour is the server matrix's and CarryFenceTest's;
 * here the statements and their order.
 */
final class JobFenceTest extends TestCase {

	/**
	 * A connection that records statements and answers from a script.
	 *
	 * @param array<string, array<int, array<int, string>>> $answers Substring of a statement => rows.
	 * @return Queries&object{sent: string[]}
	 */
	private static function recorder( array $answers ): Queries {
		return new class( $answers ) implements Queries {
			/** @var string[] */
			public $sent = array();
			/** @var array<string, array<int, array<int, string>>> */
			private $answers;
			public function __construct( array $answers ) {
				$this->answers = $answers;
			}
			public function run( string $sql ): int {
				$this->sent[] = $sql;
				return 0;
			}
			public function rows( string $sql, array $params = array() ): array {
				$this->sent[] = $sql . ' [' . implode( ',', $params ) . ']';
				foreach ( $this->answers as $needle => $rows ) {
					if ( false !== strpos( $sql, $needle ) ) {
						return $rows;
					}
				}
				return array();
			}
			public function write( string $sql, array $params ): int {
				$this->sent[] = $sql . ' [' . implode( ',', $params ) . ']';
				return 1;
			}
		};
	}

	private static function fence(): array {
		return array(
			'table' => 'wp_wpcheckpoint_jobs',
			'id'    => 7,
			'token' => 'tok',
		);
	}

	/** @var array<string, array<int, array<int, string>>> */
	const INNODB_HELD = array(
		'information_schema.TABLES' => array( array( 'InnoDB' ) ),
		'innodb_lock_wait_timeout'  => array( array( '50' ) ),
		'FOR UPDATE'                => array( array( '7' ) ),
	);

	public function test_within_locks_the_row_first_runs_the_writes_and_commits(): void {
		$db     = self::recorder( self::INNODB_HELD );
		$inside = null;
		$result = JobFence::within(
			$db,
			self::fence(),
			static function () use ( $db, &$inside ) {
				JobFence::assert_locked( $db );
				$inside = count( $db->sent );
				$db->write( 'UPDATE t SET a = 1', array() );
				return 'done';
			}
		);
		$this->assertSame( 'done', $result );
		$begin = array_search( 'START TRANSACTION', $db->sent, true );
		$this->assertNotFalse( $begin );
		$before = implode( "\n", array_slice( $db->sent, 0, $begin ) );
		$this->assertStringContainsString( 'information_schema.TABLES', $before, 'the engine is read before the transaction opens' );
		$this->assertStringContainsString( '@@SESSION.innodb_lock_wait_timeout', $before, 'and so is the session\'s wait: no read view before the locking read' );
		$this->assertStringContainsString( 'FOR UPDATE [7,tok]', $db->sent[ $begin + 2 ], 'the locking read is the first read of the transaction (after the wait is set)' );
		$this->assertSame( 'SET SESSION innodb_lock_wait_timeout = ' . JobFence::WAIT, $db->sent[ $begin + 1 ] );
		$this->assertSame( 'UPDATE t SET a = 1 []', $db->sent[ $inside ] );
		$this->assertSame( 'COMMIT', end( $db->sent ) );
		$this->assertNotContains( 'ROLLBACK', $db->sent );
		$this->expectException( \LogicException::class );
		JobFence::assert_locked( $db ); // After the fence: the guard refuses.
	}

	public function test_a_fence_inside_another_on_the_same_connection_is_refused(): void {
		$db = self::recorder( self::INNODB_HELD );
		try {
			JobFence::within(
				$db,
				self::fence(),
				static function () use ( $db ): void {
					JobFence::within( $db, self::fence(), static function (): void {} );
				}
			);
			$this->fail( 'a nested fence was run' );
		} catch ( \LogicException $e ) {
			$this->assertStringContainsString( 'inside another', $e->getMessage() );
		}
		$this->assertSame( 1, count( array_keys( $db->sent, 'START TRANSACTION', true ) ), 'the inner transaction never opened (it would have committed the outer\'s writes)' );
		$this->assertSame( 'ROLLBACK', end( $db->sent ) );
	}

	public function test_an_engine_that_cannot_be_read_is_a_transient_failure_before_the_transaction(): void {
		$db = self::recorder( array_merge( self::INNODB_HELD, array( 'information_schema.TABLES' => array() ) ) );
		try {
			JobFence::within( $db, array( 'table' => 'unread_jobs', 'id' => 7, 'token' => 'tok' ), static function (): void {} );
			$this->fail( 'an unreadable engine was taken as a fence' );
		} catch ( \WPCheckpoint\Jobs\TransientFailure $e ) {
			$this->assertStringContainsString( 'could not be read', $e->getMessage() );
		}
		$this->assertNotContains( 'START TRANSACTION', $db->sent );
	}

	public function test_a_row_not_held_under_the_token_is_lock_lost_with_nothing_written(): void {
		$db  = self::recorder( array_merge( self::INNODB_HELD, array( 'FOR UPDATE' => array() ) ) );
		$ran = false;
		try {
			JobFence::within(
				$db,
				self::fence(),
				static function () use ( &$ran ): void {
					$ran = true;
				}
			);
			$this->fail( 'the fence let a lost job write' );
		} catch ( LockLost $e ) {
			$this->assertStringContainsString( 'no longer held', $e->getMessage() );
		}
		$this->assertFalse( $ran, 'the writes never ran' );
		$this->assertSame( 'ROLLBACK', end( $db->sent ) );
		$this->assertNotContains( 'COMMIT', $db->sent );
	}

	public function test_a_failing_write_is_rolled_back_quietly_and_rethrown(): void {
		$db = self::recorder( self::INNODB_HELD );
		try {
			JobFence::within(
				$db,
				self::fence(),
				static function (): void {
					throw new \RuntimeException( 'the write failed' );
				}
			);
			$this->fail( 'not rethrown' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'the write failed', $e->getMessage() );
		}
		$this->assertSame( 'ROLLBACK', end( $db->sent ) );
		$this->expectException( \LogicException::class );
		JobFence::assert_locked( $db );
	}

	public function test_a_table_that_is_not_innodb_is_refused_before_any_locked_read(): void {
		$db = self::recorder( array_merge( self::INNODB_HELD, array( 'information_schema.TABLES' => array( array( 'MyISAM' ) ) ) ) );
		try {
			JobFence::within( $db, array( 'table' => 'myisam_jobs', 'id' => 7, 'token' => 'tok' ), static function (): void {} );
			$this->fail( 'a MyISAM table was taken as a fence' );
		} catch ( \WPCheckpoint\Restore\PlatformUnsupported $e ) {
			$this->assertStringContainsString( 'myisam, not InnoDB', $e->getMessage() );
		}
		$this->assertStringNotContainsString( 'FOR UPDATE', implode( "\n", $db->sent ), 'no locked read on a table that cannot hold it' );
		$this->assertNotContains( 'START TRANSACTION', $db->sent, 'refused before the transaction opens' );
	}

	public function test_the_bounded_wait_is_set_and_put_back_and_left_alone_when_unreadable(): void {
		$db = self::recorder( self::INNODB_HELD );
		JobFence::bounded( $db, static function (): void {} );
		$this->assertContains( 'SET SESSION innodb_lock_wait_timeout = ' . JobFence::WAIT, $db->sent );
		$this->assertSame( 'SET SESSION innodb_lock_wait_timeout = 50', end( $db->sent ), 'put back to what it was' );
		$db = self::recorder( array() );
		JobFence::bounded( $db, static function (): void {} );
		$this->assertCount( 1, $db->sent, 'unreadable: only the read, nothing set' );
	}

	public function test_a_fence_without_a_token_is_refused_before_any_statement(): void {
		$db = self::recorder( self::INNODB_HELD );
		try {
			JobFence::within( $db, array( 'table' => 'wp_wpcheckpoint_jobs', 'id' => 1, 'token' => '' ), static function (): void {} );
			$this->fail( 'a fence without a token was run' );
		} catch ( LockLost $e ) {
			$this->assertStringContainsString( 'no lock token', $e->getMessage() );
		}
		$this->assertSame( array(), $db->sent, 'no token: refused before any statement' );
	}

	public function test_the_engine_is_read_on_every_fence_not_once_per_connection(): void {
		$db = self::recorder( self::INNODB_HELD );
		JobFence::within( $db, self::fence(), static function (): void {} );
		JobFence::within( $db, self::fence(), static function (): void {} );
		$reads = count( array_filter( $db->sent, static function ( string $sql ): bool {
			return false !== strpos( $sql, 'information_schema.TABLES' );
		} ) );
		$this->assertSame( 2, $reads, 'no cache: an object id that is recycled must not stand for a table seen before' );
	}
}
