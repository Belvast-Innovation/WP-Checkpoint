<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * The rollback of a swap in bounded units (SwapStep::roll_back()): one entry's renames a unit, the first of a run
 * always going, a run with its budget spent stopping between units and the next going on from what is there, under
 * WP-CLI; the swap step stays WP-CLI's only, so neither cron nor a request of its own moves a rollback on.
 */
final class SwapRollbackUnitsTest extends SwapTestCase {

	/**
	 * A restore killed once two of its table batches were renamed (every entry a batch of its own): its rollback has
	 * the directories and two table entries to put back.
	 *
	 * @param int $batches Table batches renamed before the kill.
	 * @return array{0: Job, 1: array<string, mixed>} The job, the site before the restore.
	 */
	private function half( int $batches = 2 ): array {
		$this->swap_parts['batch'] = 1;
		$this->register_type();
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'batch_sent', $batches );
		$half = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::SITE_CHANGING, $half->site_state, 'the control: the swap half made' );
		$this->assertNotSame( $before, $this->site(), 'the control: renamed' );
		return array( $half, $before );
	}

	/**
	 * A WP-CLI tick that starts with no time left (started an hour ago, on a fixed budget).
	 *
	 * @param Job $job Job.
	 * @return TickResult
	 */
	private function cli_tick_with_no_time_left( Job $job ): TickResult {
		$runner = new Runner(
			Plugin::instance()->jobs(),
			Plugin::instance()->job_types(),
			new Redactor( Redactor::installation_secrets() ),
			array(
				'cli'    => true,
				'budget' => new Budget( 20, 32 * 1048576, false ),
			)
		);
		return $this->autocommit(
			static function () use ( $runner, $job ): TickResult {
				return $runner->tick( $job->id, microtime( true ) - 3600 );
			}
		);
	}

	/**
	 * A tick of a driver other than WP-CLI (cron, the request of its own), with time to spare.
	 *
	 * @param Job $job Job.
	 * @return TickResult
	 */
	private function web_tick( Job $job ): TickResult {
		$runner = new Runner( Plugin::instance()->jobs(), Plugin::instance()->job_types(), new Redactor( Redactor::installation_secrets() ) );
		return $this->autocommit(
			static function () use ( $runner, $job ): TickResult {
				return $runner->tick( $job->id, microtime( true ) );
			}
		);
	}

	/**
	 * How many units of the rollback ran: its renames, a table entry's one statement or a directory entry's renames.
	 *
	 * @return int
	 */
	private function units(): int {
		$units = 0;
		$dir   = false;
		foreach ( $this->seams as $seam ) {
			if ( 'table_back' === $seam ) {
				++$units;
			}
			if ( 'dir_back' === $seam && ! $dir ) {
				++$units; // A directory entry's renames are one unit; counted at its first.
			}
			$dir = 'dir_back' === $seam;
		}
		return $units;
	}

	public function test_with_no_time_left_a_rollback_puts_back_one_unit_a_run_until_the_site_is_back(): void {
		list( $job, $before ) = $this->half();
		$runs                 = array();
		for ( $i = 1; $i <= 30; $i++ ) {
			$this->seams = array();
			$was         = Plugin::instance()->jobs()->find( $job->id );
			$result      = $this->cli_tick_with_no_time_left( $was );
			$now         = Plugin::instance()->jobs()->find( $job->id );
			$runs[]      = array(
				'status' => $result->status,
				'units'  => $this->units(),
				'back'   => (int) ( $now->cursor['back'] ?? -1 ),
				'paused' => in_array( 'back_paused', $this->seams, true ),
			);
			if ( ! in_array( $now->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				break;
			}
			$this->assertSame( TickResult::MORE, $result->status, "run {$i}: " . $result->message );
			$this->assertSame( 1, $this->units(), "run {$i}: one unit, the first of the run" );
			$this->assertTrue( $runs[ $i - 1 ]['paused'], "run {$i}: stopped before the next unit" );
			$this->assertSame( (int) ( $was->cursor['back'] ?? 0 ) + 1, $runs[ $i - 1 ]['back'], "run {$i}: counted, so the run made progress" );
			$this->assertSame( Job::SITE_CHANGING, $now->site_state, "run {$i}: the site still being put back" );
			$this->assertNotSame( array(), $this->maintenance_files(), "run {$i}: its maintenance file held between runs" );
		}
		$done = Plugin::instance()->jobs()->find( $job->id );
		$this->assertGreaterThanOrEqual( 3, count( $runs ), 'the control: more than one run: ' . wp_json_encode( $runs ) );
		$this->assertSame( Job::FAILED, $done->status, $done->last_error . ' ' . wp_json_encode( $runs ) );
		$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state );
		$this->assertSame( $before, $this->site(), 'the site as it was before the restore' );
		$this->assertSame( array(), $this->maintenance_files() );
		$this->assertTrue( $done->retry_useful(), 'a retry from the final check, as after any rollback' );
	}

	public function test_a_later_unit_does_not_start_when_the_time_left_does_not_cover_the_slowest_so_far(): void {
		list( $job, $before ) = $this->half();
		// The first directory put back takes 2 seconds, the rest none; the run starts with 4 seconds of its budget
		// left. After that unit about 2 seconds are left: the budget is not spent (should_stop() is false), but less
		// than the slowest unit with its margin (3 seconds), so no other unit starts.
		$calls = 0;
		$this->retype(
			$job,
			array(
				'rename' => static function ( string $from, string $to ) use ( &$calls ): bool {
					if ( 0 === $calls++ ) {
						sleep( 2 );
					}
					return rename( $from, $to );
				},
			)
		);
		$runner      = new Runner(
			Plugin::instance()->jobs(),
			Plugin::instance()->job_types(),
			new Redactor( Redactor::installation_secrets() ),
			array(
				'cli'    => true,
				'budget' => new Budget( 20, 32 * 1048576, false ),
			)
		);
		$this->seams = array();
		$result      = $this->autocommit(
			static function () use ( $runner, $job ): TickResult {
				return $runner->tick( $job->id, microtime( true ) - 16 );
			}
		);
		$this->assertSame( TickResult::MORE, $result->status, $result->message );
		$this->assertSame( 1, $this->dir_units(), 'one slow directory, then about 2 seconds left: less than the slowest unit with its margin, so no other starts: ' . implode( ' ', $this->seams ) );
		$this->assertSame( 1, $calls > 0 ? 1 : 0, 'the control: the slow rename ran' );
		$this->assertContains( 'back_paused', $this->seams );
		// The control: there was another directory to put back, and the next runs put it back.
		$this->seams = array();
		$done        = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertGreaterThanOrEqual( 1, $this->dir_units(), 'the control: more directories were left' );
		$this->assertSame( Job::FAILED, $done->status, (string) $done->last_error );
		$this->assertSame( $before, $this->site() );
	}

	/**
	 * How many directory entries the rollback put back (each entry's renames one unit).
	 *
	 * @return int
	 */
	private function dir_units(): int {
		$units = 0;
		$dir   = false;
		foreach ( $this->seams as $seam ) {
			if ( 'dir_back' === $seam && ! $dir ) {
				++$units;
			}
			$dir = 'dir_back' === $seam;
		}
		return $units;
	}

	public function test_a_rollback_after_locked_tables_that_takes_more_than_one_run_ends_saying_the_database_was_busy(): void {
		global $wpdb;
		$job    = $this->at_swap();
		$before = $this->site();
		$this->swap_parts['sleep'] = static function ( int $seconds ): void {}; // The waits between the tries: none.
		$this->register_type();
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . \WPCheckpoint\Jobs\JobRepository::table() . ' SET type = %s WHERE id = %d', $this->type, $job->id ) );
		// Another connection holds a lock on a live table the swap renames, until the swap gave up and is rolling back.
		$other = \WPCheckpoint\Restore\ImportSession::open( \WPCheckpoint\Standalone\Credentials::from_wordpress() );
		$other->run( 'LOCK TABLES `' . $wpdb->prefix . 'swt_keep` READ' );
		try {
			for ( $i = 0; $i < 30; $i++ ) {
				$this->cli_tick_with_no_time_left( Plugin::instance()->jobs()->find( $job->id ) );
				$now = Plugin::instance()->jobs()->find( $job->id );
				if ( 'rollback' === ( $now->cursor['phase'] ?? '' ) || ! in_array( $now->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
					break;
				}
			}
		} finally {
			$other->run( 'UNLOCK TABLES' );
			$other->close();
		}
		$this->assertSame( 'rollback', $now->cursor['phase'] ?? '', 'the control: rolling back, not ended in that run: ' . $now->status . ' ' . $now->last_error );
		$this->assertSame( \WPCheckpoint\Jobs\SwapStep::BUSY, $now->cursor['reason'] ?? '', 'why, kept with the rollback' );
		$runs = 0;
		while ( in_array( $now->status, array( Job::QUEUED, Job::RUNNING ), true ) && $runs < 30 ) {
			$this->cli_tick_with_no_time_left( $now );
			$now = Plugin::instance()->jobs()->find( $job->id );
			++$runs;
		}
		$this->assertGreaterThanOrEqual( 1, $runs, 'the control: finished by a later run' );
		$this->assertSame( Job::FAILED, $now->status );
		$this->assertStringContainsString( 'The database is busy', (string) $now->last_error, 'said by the run that finished it' );
		$this->assertSame( $before, $this->site() );
	}

	/**
	 * Another connection that holds the tables the swap moved aside (the old names: only the entries it renamed have
	 * them), but the options and sitemeta tables the carry back writes first, so a RENAME of the rollback waits for the
	 * lock.
	 *
	 * @param Job  $job   The job.
	 * @param int  $least Tables it must find to lock (the control).
	 * @param bool $live  Also the test's own tables under their live names (the entries that rename those first, or
	 *                    only those, such as a table only the backup has: its RENAME waits too).
	 * @return \WPCheckpoint\Restore\ImportSession
	 */
	private function lock_moved_aside( Job $job, int $least = 1, bool $live = false ): \WPCheckpoint\Restore\ImportSession {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$prefix = \WPCheckpoint\Jobs\TempTables::OLD_PREFIX . substr( \WPCheckpoint\Jobs\TempTables::job_prefix( $job->storage_token, $job->id ), strlen( \WPCheckpoint\Jobs\TempTables::PREFIX ) );
		$tables = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LEFT(TABLE_NAME, %d) = %s', strlen( $prefix ), $prefix ) );
		// Not the options and sitemeta tables: the carry back writes them before anything is renamed back.
		$tables = array_values(
			array_filter(
				$tables,
				static function ( string $table ): bool {
					return 1 !== preg_match( '/(options|sitemeta)\z/', $table );
				}
			)
		);
		if ( $live ) {
			foreach ( array( 'swt_keep', 'swt_new', 'swt_gone' ) as $name ) {
				if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . $name ) ) ) !== '' ) {
					$tables[] = $wpdb->prefix . $name;
				}
			}
		}
		$this->assertGreaterThanOrEqual( $least, count( $tables ), 'the control: tables moved aside by the swap, other than the ones the carry back writes' );
		$other = \WPCheckpoint\Restore\ImportSession::open( \WPCheckpoint\Standalone\Credentials::from_wordpress() );
		$other->run( 'LOCK TABLES ' . implode( ', ', array_map( static function ( string $t ): string { return '`' . $t . '` READ'; }, $tables ) ) );
		return $other;
	}

	public function test_a_try_the_time_left_does_not_cover_with_its_wait_is_left_to_the_next_tick(): void {
		list( $job, $before ) = $this->half( 1 + count( self::site_tables() ) ); // A table of the test's renamed after the site's.
		$slept = array();
		$this->retype(
			$job,
			array(
				'sleep' => static function ( int $seconds ) use ( &$slept ): void {
					$slept[] = $seconds; // Waited without the time passing: what was decided before it is what counts.
				},
			)
		);
		$other       = $this->lock_moved_aside( $job );
		$this->seams = array();
		try {
			// 18 seconds left: the first try waits out the lock (5 seconds); 13 left covers the wait of 1 and a try
			// (7.5 with its margin), so it waits and tries again (5 more); 8 left do not cover the wait of 3 and a try.
			$runner = new Runner(
				Plugin::instance()->jobs(),
				Plugin::instance()->job_types(),
				new Redactor( Redactor::installation_secrets() ),
				array(
					'cli'    => true,
					'budget' => new Budget( 20, 32 * 1048576, false ),
				)
			);
			$result = $this->autocommit(
				static function () use ( $runner, $job ): TickResult {
					return $runner->tick( $job->id, microtime( true ) - 2 );
				}
			);
		} finally {
			$other->run( 'UNLOCK TABLES' );
			$other->close();
		}
		$this->assertSame( TickResult::MORE, $result->status, $result->message );
		$this->assertSame( 2, count( array_keys( $this->seams, 'back_busy', true ) ), 'two tries, each waiting out the lock: ' . implode( ' ', $this->seams ) );
		$this->assertSame( array( 1 ), $slept, 'waited before the second try, not before a third: left to the next tick' );
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 2, $now->cursor['busy'] ?? null, 'the tries recorded' );
		$this->assertGreaterThan( 0, (int) ( $now->cursor['busy_after'] ?? 0 ) );
		$this->assertNotSame( $before, $this->site(), 'the control: not put back yet' );
		// The lock gone, the next tick's first unit waits what is owed, tries and goes on.
		$done = $this->cli_run( $now );
		$this->assertSame( Job::FAILED, $done->status, (string) $done->last_error );
		$this->assertSame( $before, $this->site() );
		$this->assertSame( array(), $this->maintenance_files() );
	}

	public function test_a_rollback_killed_after_a_try_waited_out_the_lock_goes_on_from_the_tries_recorded(): void {
		list( $job, $before ) = $this->half( 1 + count( self::site_tables() ) ); // A table of the test's renamed after the site's.
		$this->retype(
			$job,
			array(
				'sleep' => static function ( int $seconds ): void {},
			)
		);
		$other = $this->lock_moved_aside( $job );
		try {
			$this->killed_at( Plugin::instance()->jobs()->find( $job->id ), 'back_busy', 1 );
			$this->assertSame( 1, Plugin::instance()->jobs()->find( $job->id )->cursor['busy'] ?? null, 'the failed try recorded before the kill' );
			// The next run, the lock still held: its first unit is the second try, not a first one.
			$this->cli_tick_with_no_time_left( Plugin::instance()->jobs()->find( $job->id ) );
		} finally {
			$other->run( 'UNLOCK TABLES' );
			$other->close();
		}
		$killed = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 2, $killed->cursor['busy'] ?? null, 'counted on from the tries recorded' );
		$this->assertNotSame( $before, $this->site(), 'the control: not put back yet' );
		$done = $this->cli_run( $killed );
		$this->assertSame( Job::FAILED, $done->status, (string) $done->last_error );
		$this->assertSame( $before, $this->site(), 'put back by the next run' );
		$this->assertSame( array(), $this->maintenance_files() );
	}

	public function test_a_rollback_whose_tries_all_wait_out_the_lock_fails_as_the_database_busy_still_holding_the_site(): void {
		list( $job, $before ) = $this->half( 1 + count( self::site_tables() ) ); // A table of the test's renamed after the site's.
		$this->retype(
			$job,
			array(
				'sleep' => static function ( int $seconds ): void {},
			)
		);
		$other = $this->lock_moved_aside( $job );
		try {
			foreach ( array( 'the first run', 'a retry, the lock still held' ) as $which ) {
				$this->seams = array();
				$done        = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
				$this->assertSame( count( \WPCheckpoint\Jobs\SwapStep::RETRIES ), count( array_keys( $this->seams, 'back_busy', true ) ), $which . ': every try but the last recorded, as many as a forward RENAME gets: ' . implode( ' ', $this->seams ) );
				$this->assertSame( Job::FAILED, $done->status, $which );
				$this->assertStringContainsString( 'The database is busy', (string) $done->last_error, $which );
				$this->assertSame( Job::SITE_CHANGING, $done->site_state, $which . ': the site still held: a retry puts it back' );
				$this->assertArrayNotHasKey( 'busy', $done->cursor, $which . ': the tries are the run\'s, not kept for a retry' );
				$this->assertNotSame( array(), $this->maintenance_files(), $which );
				Plugin::instance()->job_actions()->retry( $job->id );
			}
		} finally {
			$other->run( 'UNLOCK TABLES' );
			$other->close();
		}
		// The control: retried with the lock gone (retried at the end of the loop), it is put back.
		$back = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::FAILED, $back->status, (string) $back->last_error );
		$this->assertSame( $before, $this->site() );
	}

	public function test_tries_recorded_for_one_entry_are_not_the_next_ones(): void {
		// Every table entry renamed but the last (the test's three and the site's options, and sitemeta on a network;
		// with all of them renamed a run after the kill goes forward): the rollback puts back more than one of the
		// test's tables, each locked below under whichever name it renames first.
		list( $job, $before ) = $this->half( 2 + count( self::site_tables() ) );
		$this->retype(
			$job,
			array(
				'sleep' => static function ( int $seconds ): void {},
			)
		);
		$other = $this->lock_moved_aside( $job, 2, true );
		try {
			$this->killed_at( Plugin::instance()->jobs()->find( $job->id ), 'back_busy', 1 );
		} finally {
			$other->run( 'UNLOCK TABLES' );
			$other->close();
		}
		$first = Plugin::instance()->jobs()->find( $job->id )->cursor;
		$this->assertSame( 1, $first['busy'] ?? null, 'the control: a try of the first entry recorded' );
		// That entry goes through, and the run is killed before anything else is stored: its count stays stored.
		$this->killed_at( Plugin::instance()->jobs()->find( $job->id ), 'table_back', 1 );
		$stored = Plugin::instance()->jobs()->find( $job->id )->cursor;
		$this->assertSame( $first['busy_for'] ?? null, $stored['busy_for'] ?? null, 'the control: the first entry\'s count is what is stored' );
		// The next entry, locked: its first try is its own first, not the second of the first entry's.
		$other = $this->lock_moved_aside( $job, 2, true );
		try {
			$this->cli_tick_with_no_time_left( Plugin::instance()->jobs()->find( $job->id ) );
		} finally {
			$other->run( 'UNLOCK TABLES' );
			$other->close();
		}
		$next = Plugin::instance()->jobs()->find( $job->id )->cursor;
		$this->assertSame( 1, $next['busy'] ?? null, 'counted from its own first try' );
		$this->assertNotSame( $first['busy_for'] ?? null, $next['busy_for'] ?? null, 'of the next entry' );
		$done = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::FAILED, $done->status, (string) $done->last_error );
		$this->assertSame( $before, $this->site() );
	}

	public function test_with_time_left_a_rollback_ends_in_one_run(): void {
		list( $job, $before ) = $this->half();
		$this->seams          = array();
		$result               = $this->cli_tick( $job );
		$done                 = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::FAILED, $done->status, $result->message );
		$this->assertGreaterThanOrEqual( 3, $this->units(), 'the control: every unit in this run' );
		$this->assertNotContains( 'back_paused', $this->seams, 'never stopped between units' );
		$this->assertSame( $before, $this->site() );
	}

	public function test_neither_cron_nor_a_request_of_its_own_moves_a_rollback_on(): void {
		list( $job ) = $this->half();
		$this->cli_tick_with_no_time_left( $job ); // One unit put back: a rollback under way.
		$held = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 'rollback', $held->cursor['phase'] ?? '', 'the control: a rollback under way' );
		$this->assertSame( 1, (int) ( $held->cursor['back'] ?? 0 ), 'the control: one unit done' );
		$site        = $this->site();
		$this->seams = array();
		$result      = $this->web_tick( $held );
		$this->assertSame( TickResult::CLI, $result->status, 'a step only WP-CLI runs: ' . $result->message );
		$this->assertSame( array(), $this->seams, 'the swap step did not run' );
		$after = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( $held->cursor, $after->cursor, 'its position as it was' );
		$this->assertSame( $site, $this->site(), 'nothing put back' );
		// The control: WP-CLI moves it on.
		$this->seams = array();
		$this->cli_tick_with_no_time_left( $after );
		$this->assertSame( 1, $this->units(), 'WP-CLI puts back the next unit' );
	}

	public function test_a_rollback_killed_between_units_after_a_stop_is_finished_by_the_next_run(): void {
		list( $job, $before ) = $this->half();
		$this->cli_tick_with_no_time_left( $job );
		$stopped = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 1, (int) ( $stopped->cursor['back'] ?? 0 ), 'the control: stopped after one unit' );
		$this->killed_at( $stopped, 'table_back', 1 ); // The next run killed right after its first unit.
		$killed = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 'rollback', $killed->cursor['phase'] ?? '', 'the control: still putting back' );
		$this->assertNotSame( $before, $this->site(), 'the control: not all put back' );
		$done = $this->cli_run( $killed );
		$this->assertSame( Job::FAILED, $done->status, (string) $done->last_error );
		$this->assertSame( $before, $this->site(), 'finished from what is there' );
		$this->assertSame( array(), $this->maintenance_files() );
	}
}
