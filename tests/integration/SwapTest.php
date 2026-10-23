<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Jobs\SwapCheckStep;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * The swap, driven by WP-CLI in this process: the restored directories and
 * tables take the site's place, or anything that stops it before the
 * tables are all renamed puts the site back as it was, in the same tick.
 */
final class SwapTest extends SwapTestCase {

	public function test_the_restored_site_takes_the_place_of_the_live_one(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->assertSame( 'live upload', $before['files']['uploads/live.txt'] );
		$this->assertSame( 'live', $before['tables']['swt_keep'][0]['v'] );
		$this->assertSame( Job::SITE_UNTOUCHED, $job->site_state, 'the control: nothing changed before the swap' );
		$done = $this->cli_run( $job );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$this->assertSame( Job::SITE_SWAPPED, $done->site_state );
		$this->assertRestored( $before );
		// The live site's directories and tables are kept where the plan put them.
		$plan = $this->plan_of( $done );
		foreach ( $plan['dirs'] as $entry ) {
			if ( $entry['had_live'] ) {
				$this->assertFileExists( $entry['old'], 'kept: ' . $entry['live'] );
			}
		}
		$this->assertSame( 'live upload', file_get_contents( $plan['dirs'][ array_search( $this->dirs['uploads'], array_column( $plan['dirs'], 'live' ), true ) ]['old'] . '/live.txt' ) );
		foreach ( array( 'entered', 'maintenance', 'dir_in', 'carried', 'batch_sent', 'flushed', 'rewrite', 'cron', 'exited' ) as $seam ) {
			$this->assertContains( $seam, $this->seams, 'the control for the crash tests: the swap passes ' . $seam );
		}
	}

	public function test_the_recount_moves_on_in_every_tick_with_no_time_left(): void {
		global $wpdb;
		// A table without transactions is counted once more by the swap, a range of its key per unit.
		$wpdb->query( "ALTER TABLE `{$wpdb->prefix}swt_keep` ENGINE=MyISAM" );
		$this->swap_parts['rows'] = 1;
		$this->register_type();
		$job    = $this->at_swap();
		$runner = new Runner(
			Plugin::instance()->jobs(),
			Plugin::instance()->job_types(),
			new Redactor( Redactor::installation_secrets() ),
			array(
				'cli'          => true,
				'clock'        => static function (): float {
					static $now = 1800000000.0;
					$now += 100.0; // Every look at the clock finds the budget spent.
					return $now;
				},
				'budget'       => new Budget( 5, 32 * 1048576, false ),
				'memory_limit' => -1,
			)
		);
		$positions = array();
		$judged    = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$this->autocommit(
				static function () use ( $runner, $job ) {
					return $runner->tick( $job->id, microtime( true ) );
				}
			);
			$now = Plugin::instance()->jobs()->find( $job->id );
			if ( 'judge' === ( $now->cursor['phase'] ?? '' ) ) {
				// The judgement before it walks the usermeta table a window per unit: each tick moves that on too.
				$judged[] = wp_json_encode( $now->cursor['meta'] ?? null );
				$this->assertSame( count( $judged ), count( array_unique( $judged ) ), 'every tick of the judgement moved on' );
				continue;
			}
			if ( 'recount' !== ( $now->cursor['phase'] ?? '' ) ) {
				break;
			}
			$positions[] = wp_json_encode( array( $now->cursor['i'], $now->cursor['key'], $now->cursor['sum'] ) );
		}
		$this->assertGreaterThanOrEqual( 3, count( $positions ), 'the control: the recount took several ticks (three rows, one per unit)' );
		$this->assertSame( count( $positions ), count( array_unique( $positions ) ), 'every tick moved on' );
		$this->assertSame( Job::COMPLETED, Plugin::instance()->jobs()->find( $job->id )->status, 'and the recount ended: the swap went on in the same tick' );
		$this->assertContains( 'entered', $this->seams );
	}

	public function test_a_table_without_transactions_changed_since_the_check_ends_the_job_with_the_site_untouched(): void {
		global $wpdb;
		$wpdb->query( "ALTER TABLE `{$wpdb->prefix}swt_keep` ENGINE=MyISAM" );
		$job    = $this->at_swap();
		$before = $this->site();
		$temp   = $this->temporary_names( $job )[ $wpdb->prefix . 'swt_keep' ];
		$wpdb->query( "INSERT INTO `{$temp}` (id, v) VALUES (9, 'late')" );
		$done = $this->cli_run( $job );
		$this->assertSame( Job::FAILED, $done->status );
		$this->assertSame( Job::FAILURE_FINAL, $done->failure_kind, (string) $done->last_error );
		$this->assertStringContainsString( 'was changed after the import', (string) $done->last_error );
		$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state );
		$this->assertSame( $before, $this->site(), 'nothing of the site changed' );
		$this->assertNotContains( 'entered', $this->seams );
	}

	public function test_someone_elses_maintenance_file_makes_the_swap_wait_and_it_goes_on_once_it_is_gone(): void {
		$job = $this->at_swap();
		file_put_contents( $this->abspath . '/.maintenance', "<?php \$upgrading = time();\n" );
		$before = $this->site();
		$result = $this->cli_tick( $job );
		$this->assertSame( TickResult::WAITING, $result->status );
		$this->assertStringContainsString( 'Another maintenance file is in the WordPress directory', $result->message );
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 'ready', $now->cursor['phase'] );
		$this->assertSame( Job::SITE_UNTOUCHED, $now->site_state );
		$this->assertSame( $before, $this->site(), 'nothing changed, the other file included' );
		$this->assertSame( array( '.maintenance' => "<?php \$upgrading = time();\n" ), $this->maintenance_files() );
		$this->remove_maintenance();
		$done = $this->cli_run( $job );
		$this->assertSame( Job::COMPLETED, $done->status, 'the control: without it the swap goes on' );
		$this->assertRestored( $before );
	}

	/**
	 * A row of an earlier restore whose maintenance file carries $mark.
	 *
	 * @param array<string, mixed> $columns Columns of the row (status, site_state, lock...).
	 * @param string               $mark    The mark its row recorded.
	 * @return Job
	 */
	private function earlier_restore( array $columns, string $mark ): Job {
		global $wpdb;
		$earlier = Plugin::instance()->jobs()->create( \WPCheckpoint\Jobs\RestoreJob::ID, self::$admin_id );
		$wpdb->update( \WPCheckpoint\Jobs\JobRepository::table(), array( 'site_mark' => $mark ) + $columns, array( 'id' => $earlier->id ) );
		$wpdb->query( 'COMMIT' );
		return Plugin::instance()->jobs()->find( $earlier->id );
	}

	/**
	 * The job's log.
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	private function log_of( Job $job ): string {
		return (string) file_get_contents( $job->storage_path . '/' . ( '' !== $job->log_path ? $job->log_path : 'logs/job-' . $job->id . '.log' ) );
	}

	public function test_a_maintenance_file_left_by_a_restore_that_has_ended_is_taken_down_and_the_swap_goes_on(): void {
		$first = true;
		foreach ( array( 'two', 'single' ) as $form ) {
			foreach ( array(
				'completed, lapsed'           => array( array( 'status' => Job::COMPLETED, 'site_state' => Job::SITE_SWAPPED ), time() - 3600 ),
				'cancelled (put back), held'  => array( array( 'status' => Job::CANCELLED, 'site_state' => Job::SITE_CHANGING ), Maintenance::held() ),
				'failed before it swapped'    => array( array( 'status' => Job::FAILED, 'site_state' => Job::SITE_UNTOUCHED ), time() - 3600 ),
			) as $case => list( $columns, $time ) ) {
				$case = $form . ', ' . $case;
				if ( ! $first ) {
					$this->tear_down_swap();
					$this->set_up_swap();
				}
				$first   = false;
				$job     = $this->at_swap();
				$mark    = Maintenance::new_mark();
				$earlier = $this->earlier_restore( $columns, $mark );
				$this->put_maintenance( $mark, $time, $form );
				$before = $this->site();
				$done   = $this->cli_run( $job );
				$this->assertSame( Job::COMPLETED, $done->status, $case . ': ' . (string) $done->last_error );
				$this->assertRestored( $before );
				$this->assertSame( array(), $this->maintenance_files(), $case );
				$this->assertStringContainsString( sprintf( 'The maintenance file left by restore job %d, which has ended, was taken down', $earlier->id ), $this->log_of( $done ), $case );
				$this->assertSame( $columns['status'], Plugin::instance()->jobs()->find( $earlier->id )->status, $case . ': the earlier job is not changed' );
			}
		}
	}

	public function test_a_maintenance_file_of_a_restore_that_has_not_ended_or_that_no_job_recorded_stays_and_the_swap_waits(): void {
		$first = true;
		foreach ( array( 'two', 'single' ) as $form ) {
			foreach ( array(
				'holds the site'       => array( 'status' => Job::FAILED, 'site_state' => Job::SITE_SWAPPED ),
				'queued again'         => array( 'status' => Job::QUEUED, 'site_state' => Job::SITE_UNTOUCHED ),
				'a run holds it'       => array(
					'status'       => Job::FAILED,
					'site_state'   => Job::SITE_UNTOUCHED,
					'lock_token'   => 'live',
					'locked_until' => time() + 300,
				),
				'no job recorded it'   => null,
			) as $case => $columns ) {
				$case = $form . ', ' . $case;
				if ( ! $first ) {
					$this->tear_down_swap();
					$this->set_up_swap();
				}
				$first    = false;
				$job      = $this->at_swap();
				$mark     = Maintenance::new_mark();
				$earlier  = null === $columns ? null : $this->earlier_restore( $columns, $mark );
				$contents = $this->put_maintenance( $mark, time() - 3600, $form );
				$before   = $this->site();
				$result   = $this->cli_tick( $job );
				$this->assertSame( TickResult::WAITING, $result->status, $case );
				if ( null === $earlier ) {
					$this->assertStringContainsString( 'that no job here recorded', $result->message, $case );
				} else {
					$this->assertStringContainsString( sprintf( 'The maintenance file of restore job %1$d is in the WordPress directory', $earlier->id ), $result->message, $case );
					$this->assertStringContainsString( sprintf( 'wp wpcheckpoint job status %d', $earlier->id ), $result->message, $case );
				}
				$now = Plugin::instance()->jobs()->find( $job->id );
				$this->assertSame( 'ready', $now->cursor['phase'], $case );
				$this->assertSame( Job::SITE_UNTOUCHED, $now->site_state, $case );
				$this->assertSame( $before, $this->site(), $case . ': nothing changed, the file included' );
				$this->assertSame( $contents, $this->maintenance_files(), $case );
				$this->remove_maintenance();
			}
		}
	}

	public function test_a_file_with_an_ended_restores_mark_that_it_did_not_write_whole_stays_until_it_is_that_restores_own(): void {
		$first = true;
		foreach ( array(
			'the single file added to'  => array( 'single', '.maintenance', "\$upgrading = time();\n", 'Another maintenance file is in the WordPress directory' ),
			'the code added to'         => array( 'two', '.maintenance', "\$upgrading = time();\n", 'Another maintenance file is in the WordPress directory' ),
			'the data file added to'    => array( 'two', Maintenance::DATA, "more\n", 'without the data file that names its restore' ),
		) as $case => list( $form, $name, $added, $says ) ) {
			if ( ! $first ) {
				$this->tear_down_swap();
				$this->set_up_swap();
			}
			$first   = false;
			$job     = $this->at_swap();
			$mark    = Maintenance::new_mark();
			$earlier = $this->earlier_restore( array( 'status' => Job::COMPLETED, 'site_state' => Job::SITE_SWAPPED ), $mark );
			$own     = $this->put_maintenance( $mark, time() - 3600, $form );
			// Someone added to it: no longer the file that restore wrote.
			file_put_contents( $this->abspath . '/' . $name, $own[ $name ] . $added );
			$changed = $this->maintenance_files();
			$result  = $this->cli_tick( $job );
			$this->assertSame( TickResult::WAITING, $result->status, $case );
			$this->assertStringContainsString( $says, $result->message, $case );
			$this->assertSame( $changed, $this->maintenance_files(), $case . ': it stays as it is' );
			$taken = sprintf( 'The maintenance file left by restore job %d, which has ended, was taken down', $earlier->id );
			$this->assertStringNotContainsString( $taken, $this->log_of( Plugin::instance()->jobs()->find( $job->id ) ), $case );
			// The control: once it is that restore's own file, it goes, and the log says so.
			file_put_contents( $this->abspath . '/' . $name, $own[ $name ] );
			$done = $this->cli_run( $job );
			$this->assertSame( Job::COMPLETED, $done->status, $case . ': ' . (string) $done->last_error );
			$this->assertStringContainsString( $taken, $this->log_of( $done ), $case );
			$this->assertSame( array(), $this->maintenance_files(), $case );
		}
	}

	public function test_the_file_of_an_ended_restore_stays_when_that_job_is_queued_again_right_before_it_goes(): void {
		global $wpdb;
		$job     = $this->at_swap();
		$mark    = Maintenance::new_mark();
		$earlier = $this->earlier_restore( array( 'status' => Job::FAILED, 'site_state' => Job::SITE_UNTOUCHED ), $mark );
		$file    = $this->put_maintenance( $mark, time() - 3600 );
		$judged  = 0;
		$actions = new \WPCheckpoint\Jobs\JobActions(
			Plugin::instance()->jobs(),
			Plugin::instance()->runner(),
			new \WPCheckpoint\Jobs\Loopback( false ),
			new \WPCheckpoint\Jobs\HeldSite(
				array(
					'at' => static function ( string $point ) use ( $wpdb, $earlier, &$judged ): void {
						if ( 'left_found' === $point ) {
							++$judged;
							// A retry between finding the file and its going: its attempt carries the same mark.
							$wpdb->update( \WPCheckpoint\Jobs\JobRepository::table(), array( 'status' => Job::QUEUED ), array( 'id' => $earlier->id ) );
							$wpdb->query( 'COMMIT' );
						}
					},
				)
			)
		);
		$this->retype(
			$job,
			array(
				'left' => static function ( string $dir, callable $lease ) use ( $actions ): array {
					return $actions->release_left( $dir, $lease );
				},
			)
		);
		$result = $this->cli_tick( $job );
		$this->assertSame( 1, $judged, 'the control: the file was found, and its job queued again after that' );
		$this->assertSame( TickResult::WAITING, $result->status );
		$this->assertStringContainsString( sprintf( 'The maintenance file of restore job %1$d is in the WordPress directory', $earlier->id ), $result->message );
		$this->assertSame( $file, $this->maintenance_files(), 'checked again right before it goes: it stays' );
		$this->assertSame( Job::SITE_UNTOUCHED, Plugin::instance()->jobs()->find( $job->id )->site_state );
	}

	public function test_a_process_that_keeps_a_time_limit_does_not_start_the_swap(): void {
		$job = $this->at_swap();
		$this->register_type(
			array(
				'limit' => static function (): string {
					return '30';
				},
			)
		);
		Plugin::instance()->jobs()->find( $job->id );
		$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'UPDATE ' . \WPCheckpoint\Jobs\JobRepository::table() . ' SET type = %s WHERE id = %d', $this->type, $job->id ) );
		$before = $this->site();
		$done   = $this->cli_run( $job );
		$this->assertSame( Job::FAILED, $done->status );
		$this->assertStringContainsString( 'keeps a time limit of 30 seconds', (string) $done->last_error );
		$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state );
		$this->assertSame( $before, $this->site() );
	}

	public function test_a_rename_that_fails_during_the_swap_puts_the_site_back_in_the_same_tick(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$plan   = $this->plan_of( $job );
		$last   = $plan['dirs'][ count( $plan['dirs'] ) - 1 ];
		// The last unit's staged copy is gone: its rename fails after every other unit was swapped.
		rename( $last['stage'], $last['stage'] . '-away' );
		$done = $this->cli_run( $job );
		rename( $last['stage'] . '-away', $last['stage'] );
		$this->assertSame( Job::FAILED, $done->status );
		$this->assertStringContainsString( 'The swap stopped: Moving', (string) $done->last_error );
		$this->assertStringContainsString( 'the site is as it was before the restore', (string) $done->last_error );
		$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state );
		$this->assertContains( 'dir_in', $this->seams, 'the control: other units were swapped first' );
		$this->assertContains( 'dir_back', $this->seams );
		$this->assertSame( $before, $this->site(), 'the site is as it was' );
		$this->assertSame( SwapCheckStep::ID, $done->cursor[ \WPCheckpoint\Jobs\JobRepository::RETRY_FROM_KEY ] ?? null, 'a retry starts over at the final check' );
	}

	public function test_a_table_made_under_a_restored_name_since_the_check_puts_the_site_back(): void {
		global $wpdb;
		$job    = $this->at_swap();
		$before = $this->site();
		// The backup's swt_new did not exist live when the plan was written; someone makes it now.
		$this->create( $wpdb->prefix . 'swt_new', '(id INT UNSIGNED NOT NULL PRIMARY KEY, v VARCHAR(20) NOT NULL) ENGINE=InnoDB' );
		$wpdb->query( "INSERT INTO `{$wpdb->prefix}swt_new` (id, v) VALUES (7, 'someone')" );
		$wpdb->query( 'COMMIT' );
		$before['tables']['swt_new'] = array( array( 'id' => '7', 'v' => 'someone' ) );
		$done                        = $this->cli_run( $job );
		$this->assertSame( Job::FAILED, $done->status );
		$this->assertStringContainsString( '1050', (string) $done->last_error );
		$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state );
		$this->assertContains( 'carried', $this->seams, 'the control: the directories were swapped and the tables tried' );
		$this->assertSame( $before, $this->site(), 'the site is as it was, the new table included' );
	}

	public function test_tables_locked_through_every_retry_put_the_site_back(): void {
		global $wpdb;
		$job    = $this->at_swap();
		$before = $this->site();
		$slept  = array();
		$this->swap_parts['sleep'] = static function ( int $seconds ) use ( &$slept ): void {
			$slept[] = $seconds;
		};
		$this->register_type();
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . \WPCheckpoint\Jobs\JobRepository::table() . ' SET type = %s WHERE id = %d', $this->type, $job->id ) );
		// Another connection holds a lock on a live table the swap renames.
		$other = \WPCheckpoint\Restore\ImportSession::open( \WPCheckpoint\Standalone\Credentials::from_wordpress() );
		$other->run( 'LOCK TABLES `' . $wpdb->prefix . 'swt_keep` READ' );
		try {
			$done = $this->cli_run( $job );
		} finally {
			$other->run( 'UNLOCK TABLES' );
			$other->close();
		}
		$this->assertSame( Job::FAILED, $done->status );
		$this->assertStringContainsString( 'The database is busy', (string) $done->last_error );
		$this->assertSame( SwapStep::RETRIES, $slept, 'waited 1, 3 and 6 seconds between the tries' );
		$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state );
		$this->assertSame( $before, $this->site() );
	}

	public function test_no_driver_but_wp_cli_moves_a_job_at_the_swap(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$cursor = Plugin::instance()->jobs()->find( $job->id )->cursor;
		$result = Plugin::instance()->runner()->tick( $job->id, microtime( true ) );
		$this->assertSame( TickResult::CLI, $result->status );
		$this->assertSame( $cursor, Plugin::instance()->jobs()->find( $job->id )->cursor );
		$this->assertSame( $before, $this->site() );
		$this->assertSame( TickResult::COMPLETED, $this->cli_tick( $job )->status, 'the control: WP-CLI moves it' );
	}

	public function test_a_directory_of_the_site_moved_since_the_check_starts_the_restore_over_at_the_check(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$was    = $this->dirs['uploads'];
		$this->dirs['uploads'] = $this->sandbox . '/elsewhere/uploads';
		mkdir( $this->dirs['uploads'], 0755, true );
		$done = $this->cli_run( $job );
		$this->dirs['uploads'] = $was;
		$this->assertSame( Job::FAILED, $done->status );
		$this->assertStringContainsString( 'The uploads directory of the site moved since the restore staged its files', (string) $done->last_error );
		$this->assertSame( SwapCheckStep::ID, $done->cursor[ \WPCheckpoint\Jobs\JobRepository::RETRY_FROM_KEY ] ?? null );
		$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state );
		$this->assertNotContains( 'entered', $this->seams );
		$this->assertSame( $before, $this->site() );
	}

	public function test_after_the_swap_the_restored_rewrite_rules_are_gone_without_any_call_to_wordpress(): void {
		global $wpdb;
		update_option( 'rewrite_rules', array( 'old/?$' => 'index.php' ) );
		$wpdb->query( 'COMMIT' );
		$job  = $this->at_swap();
		$temp = $this->temporary_names( $job )[ $wpdb->base_prefix . 'options' ];
		$this->assertSame( '1', (string) $wpdb->get_var( "SELECT COUNT(*) FROM `{$temp}` WHERE option_name = 'rewrite_rules'" ), 'the control: the restored options have them' );
		$calls = array();
		$count = static function ( string $name ) use ( &$calls ): void {
			$calls[] = $name;
		};
		add_action( 'delete_option', $count );
		add_action( 'update_option', $count );
		add_action( 'add_option', $count );
		try {
			$done = $this->cli_run( $job );
		} finally {
			remove_action( 'delete_option', $count );
			remove_action( 'update_option', $count );
			remove_action( 'add_option', $count );
		}
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$this->assertSame( '0', (string) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->base_prefix}options` WHERE option_name = 'rewrite_rules'" ), 'removed from the restored options' );
		$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->base_prefix}options` WHERE option_name = %s", \WPCheckpoint\Support\StoredNames::AFTER_SWAP ) ), 'the mark for the next request' );
		$this->assertNotContains( 'rewrite_rules', $calls, 'not through WordPress' );
		$this->assertNotContains( \WPCheckpoint\Support\StoredNames::AFTER_SWAP, $calls, 'not through WordPress' );
		$this->assertNotContains( 'cron', $calls, 'the cron option is not written in the process that swapped' );
		// The control: the same hooks do see a call through WordPress.
		add_option( 'wpcheckpoint_test_seen', 'x' );
		add_action( 'delete_option', $count );
		delete_option( 'wpcheckpoint_test_seen' );
		remove_action( 'delete_option', $count );
		$this->assertContains( 'wpcheckpoint_test_seen', $calls );
	}

	public function test_the_next_request_sets_the_fallback_event_of_a_job_still_running(): void {
		$this->register( 'swap_bystander', array( $this->counting_step( 'b', 1 ) ) );
		$other = Plugin::instance()->jobs()->create( 'swap_bystander', self::$admin_id );
		$job   = $this->at_swap();
		\WPCheckpoint\Jobs\Loopback::schedule( $other->id, 60 );
		$GLOBALS['wpdb']->query( 'COMMIT' );
		$done = $this->cli_run( $job );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		wp_cache_flush(); // A request after the swap: nothing of the old site's options in memory.
		$this->assertFalse( wp_next_scheduled( \WPCheckpoint\Jobs\Loopback::HOOK, array( $other->id ) ), 'the restored cron option has no event for it' );
		Plugin::instance()->after_swap();
		$this->assertNotFalse( wp_next_scheduled( \WPCheckpoint\Jobs\Loopback::HOOK, array( $other->id ) ), 'set again by the next request' );
		$this->assertFalse( get_option( \WPCheckpoint\Support\StoredNames::AFTER_SWAP ), 'once' );
		do_action( \WPCheckpoint\Jobs\Loopback::HOOK, $other->id );
		$this->assertSame( Job::COMPLETED, Plugin::instance()->jobs()->find( $other->id )->status, 'and WP-Cron drives it to its end' );
		// The control: without the mark a request sets nothing.
		$third = Plugin::instance()->jobs()->create( 'swap_bystander', self::$admin_id );
		Plugin::instance()->after_swap();
		$this->assertFalse( wp_next_scheduled( \WPCheckpoint\Jobs\Loopback::HOOK, array( $third->id ) ) );
	}

	public function test_the_object_cache_is_flushed_once_the_swap_is_made(): void {
		unset( $this->swap_parts['flush'] ); // The real flush.
		$this->register_type();
		$job = $this->at_swap();
		wp_cache_set( 'wpcheckpoint_test_marker', 'old site', 'wpcheckpoint_test' );
		$this->assertSame( 'old site', wp_cache_get( 'wpcheckpoint_test_marker', 'wpcheckpoint_test' ), 'the control: the cache holds it' );
		$done = $this->cli_run( $job );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$this->assertFalse( wp_cache_get( 'wpcheckpoint_test_marker', 'wpcheckpoint_test' ), 'flushed: nothing of the old site is read from it' );
	}

	public function test_discarding_abandoned_jobs_after_the_jobs_table_was_dropped_reads_nothing(): void {
		global $wpdb;
		$table = \WPCheckpoint\Jobs\JobRepository::table();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // As a test's own tear-down does before the shared one runs.
		$quiet = $wpdb->suppress_errors( true );
		$wpdb->get_col( "SELECT id FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's table.
		$this->assertNotSame( '', $wpdb->last_error, 'the control: reading the dropped table is an error, seen here' );
		$wpdb->last_error = '';
		$this->discard_abandoned();
		$wpdb->suppress_errors( $quiet );
		$this->assertSame( '', $wpdb->last_error, 'the dropped table was not read' );
	}
}
