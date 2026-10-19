<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\LockLost;
use WPCheckpoint\Jobs\RestorePreflightStep;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Jobs\SwapCheckStep;
use WPCheckpoint\Jobs\TempTableCount;
use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\ImportSession;
use WPCheckpoint\Restore\JobFence;
use WPCheckpoint\Restore\Ledger;
use WPCheckpoint\Restore\StateCarry;
use WPCheckpoint\Restore\SwapPlan;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * The job row as the fence of the restore's writes outside it (Restore\JobFence), with two real connections: a
 * carry that holds the row in its transaction makes a take-over wait, and a take-over made first makes the old
 * run's carry, plan write and ownership record fail at their first statement, with nothing written.
 */
final class CarryFenceTest extends SwapTestCase {

	/** @var ImportSession|null The old run's connection. */
	private $old = null;

	public function tear_down(): void {
		if ( null !== $this->old ) {
			$this->old->rollback();
			$this->old->close();
			$this->old = null;
		}
		parent::tear_down();
	}

	/**
	 * A restore at its swap, held by "the old run" under a token (as a run that acquired it), the lease short.
	 *
	 * @return array{job: Job, token: string, fence: array{table: string, id: int, token: string}}
	 */
	private function held(): array {
		$this->register_type();
		$job  = $this->at_swap();
		$jobs = Plugin::instance()->jobs();
		$held = $this->autocommit(
			static function () use ( $jobs, $job ) {
				return $jobs->acquire( $job->id, 1 );
			}
		);
		$this->assertNotNull( $held, 'the control: the old run holds the job' );
		$token = (string) $held['token'];
		return array(
			'job'   => $job,
			'token' => $token,
			'fence' => array(
				'table' => JobRepository::table(),
				'id'    => $job->id,
				'token' => $token,
			),
		);
	}

	/**
	 * The take-over by another run, once the lease ran out: a new token, or null when it could not (the row held).
	 *
	 * @return array{token: string}|null
	 */
	private function take_over( Job $job, int $wait ) {
		global $wpdb;
		$jobs = Plugin::instance()->jobs();
		return $this->autocommit(
			static function () use ( $jobs, $job, $wait, $wpdb ) {
				$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = ' . $wait );
				$quiet = $wpdb->suppress_errors( true ); // A lock wait that runs out is the expected answer here.
				try {
					$wpdb->query( $wpdb->prepare( 'UPDATE ' . JobRepository::table() . ' SET locked_until = %d WHERE id = %d', time() - 1, $job->id ) );
					return $jobs->acquire( $job->id );
				} finally {
					$wpdb->suppress_errors( $quiet );
					$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 50' );
				}
			}
		);
	}

	/**
	 * The job's temporary options table.
	 */
	private function temp_options( Job $job ): string {
		global $wpdb;
		foreach ( $this->plan_of( $job )['tables'] as $entry ) {
			if ( SwapPlan::TABLE_OF === $entry['kind'] && $wpdb->prefix . 'options' === $entry['live'] ) {
				return $entry['stage'];
			}
		}
		$this->fail( 'no entry for the options table' );
	}

	private function count_rows( string $table ): int {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}

	public function test_a_take_over_waits_for_a_carry_that_holds_the_job_row_and_goes_through_once_it_committed(): void {
		$held      = $this->held();
		$this->old = ImportSession::open( Credentials::from_wordpress() );
		$this->old->begin();
		JobFence::lock( $this->old, $held['fence']['table'], $held['fence']['id'], $held['fence']['token'] );
		// The row is locked in the old run's transaction: a take-over (another connection) waits for it, here for a
		// second, then gives up (1205: acquire() answers null).
		$start = microtime( true );
		$taken = $this->take_over( $held['job'], 1 );
		$this->assertGreaterThanOrEqual( 0.9, microtime( true ) - $start, 'the take-over waited for the row' );
		$this->assertNull( $taken, 'and could not pass the carry that holds it' );
		$this->assertSame( $held['token'], Plugin::instance()->jobs()->find( $held['job']->id )->lock_token, 'the control: the old run still holds it' );
		$this->old->commit();
		$taken = $this->take_over( $held['job'], 1 );
		$this->assertNotNull( $taken, 'once the carry committed, the take-over goes through' );
		$this->assertNotSame( $held['token'], $taken['token'] );
	}

	public function test_after_a_take_over_the_old_runs_carry_fails_at_its_first_statement_and_writes_nothing(): void {
		global $wpdb;
		$held = $this->held();
		$temp = $this->temp_options( $held['job'] );
		$this->assertNotNull( $this->take_over( $held['job'], 5 ), 'the control: taken over' );
		$before    = $this->count_rows( $temp );
		$seams     = array();
		$this->old = ImportSession::open( Credentials::from_wordpress() );
		$carry     = new StateCarry(
			$this->old,
			'wp-checkpoint/wp-checkpoint.php',
			is_multisite() ? get_current_network_id() : 0,
			static function ( string $point ) use ( &$seams ): void {
				$seams[] = $point;
			}
		);
		try {
			$carry->carry( $wpdb->base_prefix . 'options', $temp, null, null, $held['fence'] );
			$this->fail( 'the old run\'s carry went through after the take-over' );
		} catch ( LockLost $e ) {
			$this->assertStringContainsString( 'no longer held', $e->getMessage() );
		}
		$this->assertSame( array(), $seams, 'not one statement of the carry ran (no seam after the lock)' );
		$this->assertSame( $before, $this->count_rows( $temp ), 'nothing written' );
		// The control: under the new token the same carry goes through.
		$fresh = Plugin::instance()->jobs()->find( $held['job']->id );
		$carry->carry( $wpdb->base_prefix . 'options', $temp, null, null, array( 'table' => JobRepository::table(), 'id' => $fresh->id, 'token' => $fresh->lock_token ) );
		$this->assertContains( 'carry_locked', $seams );
		$this->assertContains( 'carry_written', $seams );
	}

	public function test_after_a_take_over_the_old_runs_plan_write_and_ownership_record_are_refused(): void {
		global $wpdb;
		$held = $this->held();
		$this->assertNotNull( $this->take_over( $held['job'], 5 ), 'the control: taken over' );
		$this->old = ImportSession::open( Credentials::from_wordpress() );
		$plan      = new SwapPlan( $this->old, $wpdb->base_prefix . SwapPlan::TABLE );
		$attempt   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(attempt) FROM `' . $wpdb->base_prefix . SwapPlan::TABLE . '` WHERE job_id = %d', $held['job']->id ) );
		$rows      = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $wpdb->base_prefix . SwapPlan::TABLE . '` WHERE job_id = %d', $held['job']->id ) );
		try {
			$plan->write( $held['job']->id, $attempt + 1, 0, array( array( 'kind' => SwapPlan::SITE, 'live' => '/x', 'stage' => 'wp_', 'old' => '' ) ), $held['fence'] );
			$this->fail( 'the old run\'s plan write went through after the take-over' );
		} catch ( LockLost $e ) {
			$this->assertStringContainsString( 'no longer held', $e->getMessage() );
		}
		$wpdb->query( 'COMMIT' );
		$this->assertSame( $rows, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $wpdb->base_prefix . SwapPlan::TABLE . '` WHERE job_id = %d', $held['job']->id ) ), 'nothing written to the plan' );

		// The ownership record: the statement itself refuses, and the count stops with LockLost.
		$loaded = RestorePreflightStep::load_plan( Residue::work_dir( $held['job']->storage_path, $held['job']->id ) );
		$name   = TempTables::ledger( $held['job']->storage_token, $held['job']->id, (string) $loaded['random'] );
		$ledger = new Ledger( $this->old, $name, 'cccc' );
		$number = null;
		foreach ( $loaded['plan']->tables() as $table ) {
			if ( $wpdb->prefix . 'options' === $table['table'] ) {
				$number = (int) $table['number'];
			}
		}
		$this->assertNotNull( $number );
		$this->assertSame( 1, $wpdb->query( $wpdb->prepare( "UPDATE `{$name}` SET owned = -1, transactional = 0 WHERE n = %d", $number ) ) );
		$wpdb->query( 'COMMIT' );
		$this->assertFalse( $ledger->set_owned( $number, 3, JobRepository::table(), $held['job']->id, $held['token'] ), 'refused under the old token' );
		$this->assertSame( -1, $ledger->get( $number )['owned'], 'and nothing recorded' );
		$counter = new TempTableCount( $this->old, $loaded['plan'], $ledger, Residue::work_dir( $held['job']->storage_path, $held['job']->id ), 1000, null, StateCarry::counted( $wpdb->base_prefix, is_multisite() ? get_current_network_id() : 0 ), $held['fence'] );
		try {
			$counter->unit( array( 'i' => 0, 'key' => null, 'sum' => 0 ), false );
			$this->fail( 'the old run\'s count went on after the take-over' );
		} catch ( LockLost $e ) {
			$this->assertStringContainsString( 'wrote nothing', $e->getMessage() );
		}
		$this->assertSame( -1, $ledger->get( $number )['owned'] );
		// The control: under the new token the record is made.
		$fresh = Plugin::instance()->jobs()->find( $held['job']->id );
		$this->assertTrue( $ledger->set_owned( $number, 3, JobRepository::table(), $fresh->id, $fresh->lock_token ) );
		$this->assertSame( 3, $ledger->get( $number )['owned'] );
	}
}
