<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\LockLost;
use WPCheckpoint\Jobs\RestoreFilesPreflightStep;
use WPCheckpoint\Jobs\RestorePreflightStep;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\StagingLayout;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * A restore that starts over at its preflight reclaims what its earlier attempt recorded (its temporary tables and
 * staging roots) before it makes them again, and nothing else: not a table or a staging root of the job that was
 * not recorded, not a staging root holding what a rollback moved aside. A run stopped right before any of the
 * deletions is followed by one that goes on from where the list stood.
 */
final class RestoreRetryReclaimTest extends SwapTestCase {

	/**
	 * What the attempt that brought the job to the swap recorded: its tables and staging roots.
	 *
	 * @return array{tables: string[], roots: string[], random: string, parent: string}
	 */
	private function recorded( Job $job ): array {
		$job     = Plugin::instance()->jobs()->find( $job->id );
		$work    = $this->work( $job );
		$plan    = RestorePreflightStep::load_plan( $work );
		$tables  = array_values( array_map( 'strval', array_column( $plan['plan']->tables(), 'temporary' ) ) );
		$staging = RestoreFilesPreflightStep::staging( $work );
		$layout  = RestoreFilesPreflightStep::layout_of( $staging, $job );
		$roots   = array();
		$parent  = '';
		foreach ( (array) $staging['staged'] as $group ) {
			$roots[ $layout->root( (string) $group ) ] = true;
			$parent                                    = $layout->parent( (string) $group );
		}
		$tables[] = TempTables::ledger( $job->storage_token, $job->id, (string) $plan['random'] );
		return array(
			'tables' => $tables,
			'roots'  => array_keys( $roots ),
			'random' => (string) $plan['random'],
			'parent' => $parent,
		);
	}

	/**
	 * The uploads in a directory of another parent: the restore stages them in a staging root of their own (as a
	 * group on another disk), so the earlier attempt left two.
	 */
	private function uploads_elsewhere(): void {
		$far = $this->sandbox . '/far';
		mkdir( $far );
		rename( $this->dirs['uploads'], $far . '/uploads' );
		$this->dirs['uploads'] = $far . '/uploads';
	}

	private static function table_there( string $name ): bool {
		global $wpdb;
		return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
	}

	/**
	 * The job failed with a retry from the preflight (as RetryFrom leaves it), then retried.
	 */
	private function retried_from_the_preflight( Job $job ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . JobRepository::table() . " SET status = %s, cursor_json = %s, lock_token = '', locked_until = 0, finished_at = %d WHERE id = %d", Job::FAILED, wp_json_encode( array( JobRepository::RETRY_FROM_KEY => RestorePreflightStep::ID ) ), time() - 5, $job->id ) );
		$wpdb->query( 'COMMIT' );
		$this->assertNotNull( Plugin::instance()->job_actions()->retry( $job->id ) );
		$this->assertSame( RestorePreflightStep::ID, Plugin::instance()->jobs()->find( $job->id )->step, 'the control: the retry starts at the preflight' );
	}

	/**
	 * Tick until the job stands at the swap again, each tick started an hour ago: every tick must move it on.
	 *
	 * @return int[] The position in the reclaim list after each tick that was in it.
	 */
	private function back_at_the_swap( Job $job ): array {
		$runner = new Runner(
			Plugin::instance()->jobs(),
			Plugin::instance()->job_types(),
			new Redactor( Redactor::installation_secrets() ),
			array( 'budget' => new Budget( 20, 32 * 1048576, false ) )
		);
		$units = array();
		for ( $i = 0; $i < 2000; $i++ ) {
			$was    = Plugin::instance()->jobs()->find( $job->id );
			$result = $runner->tick( $job->id, microtime( true ) - 3600 );
			$now    = Plugin::instance()->jobs()->find( $job->id );
			if ( RestorePreflightStep::ID === $now->step && 'reclaim' === ( $now->cursor['phase'] ?? '' ) ) {
				$units[] = (int) $now->cursor['units'];
			}
			if ( TickResult::CLI === $result->status ) {
				$GLOBALS['wpdb']->query( 'COMMIT' );
				$this->assertSame( SwapStep::ID, $now->step );
				return $units;
			}
			$this->assertContains( $now->status, array( Job::QUEUED, Job::RUNNING ), $result->message . ' ' . $now->last_error );
			$this->assertNotSame( wp_json_encode( array( $was->step, JobContext::strip_reserved( $was->cursor ) ) ), wp_json_encode( array( $now->step, JobContext::strip_reserved( $now->cursor ) ) ), "tick {$i} ({$now->step}) moved nothing on" );
		}
		$this->fail( 'the restore did not reach the swap again' );
	}

	/**
	 * The earlier attempt's tables and staging roots are gone, the new attempt's are there.
	 */
	private function assertReclaimed( Job $job, array $old, string $why ): void {
		foreach ( $old['tables'] as $table ) {
			$this->assertFalse( self::table_there( $table ), $why . ': the earlier attempt\'s ' . $table . ' is gone' );
		}
		foreach ( $old['roots'] as $root ) {
			$this->assertDirectoryDoesNotExist( $root, $why . ': the earlier attempt\'s staging root is gone' );
		}
		$new = $this->recorded( $job );
		$this->assertNotSame( $old['random'], $new['random'], $why . ': a new attempt' );
		foreach ( $new['tables'] as $table ) {
			$this->assertTrue( self::table_there( $table ), $why . ': the new attempt\'s ' . $table . ' is there' );
		}
		foreach ( $new['roots'] as $root ) {
			$this->assertDirectoryExists( $root, $why . ': the new attempt\'s staging root is there' );
		}
	}

	public function test_a_retry_from_the_preflight_reclaims_what_the_earlier_attempt_recorded_and_nothing_else(): void {
		global $wpdb;
		$this->uploads_elsewhere();
		$job = $this->at_swap();
		$old = $this->recorded( $job );
		// The control: what was recorded is there, two staging roots among it.
		$this->assertGreaterThan( 1, count( $old['tables'] ) );
		$this->assertCount( 2, $old['roots'] );
		foreach ( $old['tables'] as $table ) {
			$this->assertTrue( self::table_there( $table ), $table );
		}
		foreach ( $old['roots'] as $root ) {
			$this->assertDirectoryExists( $root );
		}
		// The job's own names, not recorded: a table under another random part, a staging root under another.
		$similar = TempTables::name( $job->storage_token, $job->id, 'beef' === $old['random'] ? 'feed' : 'beef', 'swt_keep' );
		$this->assertNotContains( $similar, $old['tables'] );
		$this->create( $similar, '(id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB' );
		$name  = basename( $old['roots'][0] );
		$other = $old['parent'] . '/' . substr( $name, 0, -32 ) . str_repeat( 'f', 32 );
		$this->assertNotContains( $other, $old['roots'] );
		$this->assertSame( 'stage', StagingLayout::parse( basename( $other ) )['kind'] ?? null, 'the control: a staging root of this job by its name' );
		mkdir( $other . '/uploads', 0755, true );
		file_put_contents( $other . '/uploads/kept.txt', 'not recorded' );
		try {
			$this->retried_from_the_preflight( $job );
			$units = $this->back_at_the_swap( $job );
			// Every tick in the reclaim, with no time left, did one unit more.
			$this->assertNotSame( array(), $units, 'the control: the reclaim ran' );
			$this->assertSame( range( 1, count( $units ) ), $units, 'one unit in each tick with no time left' );
			$this->assertReclaimed( $job, $old, 'reclaimed' );
			$this->assertTrue( self::table_there( $similar ), 'a table of the job that was not recorded stays' );
			$this->assertFileExists( $other . '/uploads/kept.txt', 'a staging root of the job that was not recorded stays' );
		} finally {
			$wpdb->query( "DROP TABLE IF EXISTS `{$similar}`" );
		}
	}

	public function test_an_entry_of_the_list_that_is_not_one_of_this_jobs_staging_roots_is_never_deleted(): void {
		$job     = $this->at_swap();
		$old     = $this->recorded( $job );
		$name    = basename( $old['roots'][0] );
		$foreign = $old['parent'] . '/' . str_replace( '-' . $job->id . '-', '-' . ( $job->id + 1000 ) . '-', $name );
		$this->assertNotSame( $old['parent'] . '/' . $name, $foreign );
		mkdir( $foreign . '/uploads', 0755, true );
		file_put_contents( $foreign . '/uploads/theirs.txt', 'another job\'s' );
		$this->retried_from_the_preflight( $job );
		$work                  = $this->work( Plugin::instance()->jobs()->find( $job->id ) );
		$changed               = false;
		$this->preflight_parts = array(
			'deleting' => static function () use ( &$changed, $work, $foreign ): void {
				if ( $changed ) {
					return;
				}
				// The list is changed after it was written (before the first deletion): another job's root added.
				$changed = true;
				$path    = $work . '/' . \WPCheckpoint\Restore\RestoreFiles::RECLAIM;
				$list    = json_decode( (string) file_get_contents( $path ), true );
				array_unshift(
					$list['roots'],
					array(
						'path'   => $foreign,
						'parent' => dirname( $foreign ),
					)
				);
				file_put_contents( $path, (string) wp_json_encode( $list ) );
			},
		);
		$this->retype( $job );
		$this->back_at_the_swap( $job );
		$this->assertTrue( $changed, 'the control: the list was changed' );
		$this->assertFileExists( $foreign . '/uploads/theirs.txt', 'another job\'s staging root is never deleted' );
		foreach ( $old['roots'] as $root ) {
			$this->assertDirectoryDoesNotExist( $root, 'the control: this job\'s roots went' );
		}
		$now = Plugin::instance()->jobs()->find( $job->id );
		// (Not the word after it: the test database's user is "root", and the log redacts the database credentials.)
		$this->assertStringContainsString( 'the earlier attempt of the restore left is not one of this job\'s staging', (string) file_get_contents( $now->storage_path . '/' . $now->log_path ) );
	}

	public function test_a_damaged_earlier_plan_leaves_its_tables_to_the_end_of_the_job_and_the_restore_goes_on(): void {
		global $wpdb;
		$job  = $this->at_swap();
		$old  = $this->recorded( $job );
		$path = $this->work( $job ) . '/' . \WPCheckpoint\Restore\RestoreFiles::PLAN;
		$plan = json_decode( (string) file_get_contents( $path ), true );
		$plan['random'] = 'not-hex'; // A random part no table name of this job can have.
		file_put_contents( $path, (string) wp_json_encode( $plan ) );
		$this->retried_from_the_preflight( $job );
		$this->back_at_the_swap( $job );
		$this->assertTrue( self::table_there( $old['tables'][0] ), 'no record to go by: the earlier tables wait for the end of the job' );
		foreach ( $old['roots'] as $root ) {
			$this->assertDirectoryDoesNotExist( $root, 'the staging roots have their own record' );
		}
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertStringContainsString( 'plan could not be read; its tables are left to the reclaim at the end of the job', (string) file_get_contents( $now->storage_path . '/' . $now->log_path ) );
	}

	public function test_a_staging_root_holding_what_a_rollback_moved_aside_is_kept(): void {
		$job  = $this->at_swap();
		$old  = $this->recorded( $job );
		$root = $old['roots'][0];
		mkdir( $root . '/stray/uploads-0f0f', 0755, true );
		file_put_contents( $root . '/stray/uploads-0f0f/theirs.txt', 'someone else\'s' );
		$this->retried_from_the_preflight( $job );
		$this->back_at_the_swap( $job );
		$this->assertFileExists( $root . '/stray/uploads-0f0f/theirs.txt', 'kept with what is in it' );
		foreach ( $old['tables'] as $table ) {
			$this->assertFalse( self::table_there( $table ), 'the control: the tables went: ' . $table );
		}
		foreach ( array_slice( $old['roots'], 1 ) as $other ) {
			$this->assertDirectoryDoesNotExist( $other, 'the control: the other roots went' );
		}
	}

	public function test_a_run_whose_job_was_taken_over_deletes_nothing_more(): void {
		foreach ( array( 1, 12, 25 ) as $n ) {
			$seen  = 0;
			$there = array();
			$this->uploads_elsewhere();
			$job = $this->at_swap();
			$old = $this->recorded( $job );
			$this->retried_from_the_preflight( $job );
			$this->preflight_parts = array(
				'deleting' => static function () use ( &$seen, &$there, $n, $job, $old ): void {
					++$seen;
					if ( 1 === $seen ) {
						// The first deletion is a DROP, its lease checked like any other: every table recorded is still there.
						$there = array_values( array_filter( $old['tables'], array( self::class, 'table_there' ) ) );
					}
					if ( $seen === $n ) {
						// Another process takes the job over right before this deletion: the lease check must stop it.
						$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'UPDATE ' . JobRepository::table() . " SET lock_token = 'another-process' WHERE id = %d", $job->id ) );
						$GLOBALS['wpdb']->query( 'COMMIT' );
					}
				},
			);
			$this->retype( $job );
			$runner = Plugin::instance()->runner();
			$result = null;
			for ( $i = 0; $i < 200 && $seen < $n; $i++ ) {
				$result = $runner->tick( $job->id, microtime( true ) );
			}
			$this->assertSame( $n, $seen, "the control: the run reached deletion {$n}" );
			$this->assertSame( TickResult::LOST, null !== $result ? $result->status : null, "taken over before deletion {$n}" );
			$this->assertSame( $n, $seen, "nothing more after deletion {$n}" );
			$this->assertSame( $old['tables'], $there, 'the first deletion is of a table, made only after its lease check' );
			if ( 1 === $n ) {
				foreach ( $old['tables'] as $table ) {
					$this->assertTrue( self::table_there( $table ), 'taken over before the first DROP: nothing dropped: ' . $table );
				}
			}
			$this->preflight_parts = array();
			$this->undo( Plugin::instance()->jobs()->find( $job->id ) );
			$this->release_backups();
			$this->tear_down_swap();
			$this->set_up_swap();
		}
	}

	public function test_a_run_stopped_before_any_deletion_is_followed_by_one_that_goes_on(): void {
		// How many deletions the reclaim makes, counted in a run that is not stopped.
		$count = 0;
		$this->uploads_elsewhere();
		$job = $this->at_swap();
		$this->retried_from_the_preflight( $job );
		$this->preflight_parts = array(
			'deleting' => static function () use ( &$count ): void {
				++$count;
			},
		);
		$this->retype( $job );
		$this->back_at_the_swap( $job );
		$this->assertGreaterThan( 2, $count, 'the control: tables and files were deleted' );
		$total = $count;
		for ( $n = 1; $n <= $total; $n++ ) {
			$this->undo( Plugin::instance()->jobs()->find( $job->id ) );
			$this->preflight_parts = array();
			$this->release_backups();
			$this->tear_down_swap();
			$this->set_up_swap();
			$this->uploads_elsewhere();
			$seen = 0;
			$job  = $this->at_swap();
			$old = $this->recorded( $job );
			$this->retried_from_the_preflight( $job );
			$this->preflight_parts = array(
				'deleting' => static function () use ( &$seen, $n ): void {
					++$seen;
					if ( $seen === $n ) {
						throw new LockLost( 'stopped before deletion ' . $n ); // As a run that dies there: nothing after.
					}
				},
			);
			$this->retype( $job );
			$runner = Plugin::instance()->runner();
			for ( $i = 0; $i < 200 && $seen < $n; $i++ ) {
				$result = $runner->tick( $job->id, microtime( true ) );
			}
			$this->assertSame( $n, $seen, "the control: the run reached deletion {$n}" );
			$this->assertSame( TickResult::LOST, $result->status, "stopped before deletion {$n}" );
			// The lease of the stopped run lapses; the next run takes over without the seam.
			$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'UPDATE ' . JobRepository::table() . ' SET locked_until = %d WHERE id = %d', time() - 1, $job->id ) );
			$GLOBALS['wpdb']->query( 'COMMIT' );
			$this->preflight_parts = array();
			$this->retype( $job );
			$this->back_at_the_swap( $job );
			$this->assertReclaimed( $job, $old, "stopped before deletion {$n}" );
		}
	}
}
