<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\NotHere;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\ImportSession;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * A swap a WP-CLI run left half made, put back by runs made without WordPress (WebRun): they end the job as WP-CLI
 * would (the job row column by column, the error with the visitor's sentence more); they stop before a flush they
 * cannot do and leave the job as it is, run after run, for WP-CLI to flush and end it; their carry back is bounded by
 * one deadline and counted as busy, never left out; a NotHere in it leaves the job to WP-CLI.
 */
final class WebRollbackTest extends SwapTestCase {

	/**
	 * A restore killed once two of its table batches were renamed (every entry a batch of its own: the options table
	 * first, so the carry back has its pair where the swap left it).
	 *
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
	 * A job's row as stored.
	 *
	 * @param int $id Job id.
	 * @return array<string, string|null>
	 */
	private static function row( int $id ): array {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . JobRepository::table() . ' WHERE id = %d', $id ), ARRAY_A );
	}

	/**
	 * What two runs of the same case must have alike: the columns the user named, the cursor without what is the job's
	 * own (its attempt and mark, put as placeholders) and without the Runner's reserved keys but the step a retry
	 * starts at (JobRepository::RETRY_FROM_KEY), which is given apart.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return array<string, mixed>
	 */
	private static function alike( array $row ): array {
		$cursor     = json_decode( (string) $row['cursor_json'], true );
		$cursor     = is_array( $cursor ) ? $cursor : array();
		$retry_from = $cursor[ JobRepository::RETRY_FROM_KEY ] ?? null;
		foreach ( array_keys( $cursor ) as $key ) {
			if ( 0 === strpos( (string) $key, '__runner' ) ) {
				unset( $cursor[ $key ] );
			}
		}
		foreach ( array( 'attempt', 'mark' ) as $own ) {
			if ( isset( $cursor[ $own ] ) ) {
				$cursor[ $own ] = '(' . $own . ')';
			}
		}
		ksort( $cursor );
		return array(
			'status'       => $row['status'],
			'step'         => $row['step'],
			'retry_from'   => $retry_from,
			'failure_kind' => $row['failure_kind'],
			'site_state'   => $row['site_state'],
			'progress'     => $row['progress'],
			'cursor'       => $cursor,
		);
	}

	public function test_runs_without_wordpress_put_a_half_swap_back_and_end_the_job_as_wp_cli_does(): void {
		list( $job, $before ) = $this->half();
		$cli                  = $this->cli_run( $job );
		$this->assertSame( $before, $this->site(), 'the control: WP-CLI put the site back' );
		$by_cli = self::row( $cli->id );

		$this->tear_down_swap();
		$this->set_up_swap();
		list( $job, $before ) = $this->half();
		list( $web, $runs )   = $this->run_without_wordpress( $job );
		$this->assertNotSame( TickResult::LEFT, end( $runs )->status, 'not left to WP-CLI: ' . end( $runs )->message . ' ' . implode( ' | ', $this->web_notes ) );
		$this->assertSame( $before, $this->site(), 'put back as it was before the restore' );
		$this->assertSame( array(), $this->maintenance_files() );
		$by_web = self::row( $web->id );

		$this->assertSame( Job::FAILED, $by_cli['status'], 'the control: a rollback fails the job, for a retry' );
		$this->assertSame( self::alike( $by_cli ), self::alike( $by_web ) );
		$this->assertSame( \WPCheckpoint\Jobs\SwapCheckStep::ID, self::alike( $by_web )['retry_from'], 'a retry from the final check' );
		$visitor = ' A visitor\'s request put the site back, as no WP-CLI run went on with the swap.';
		$this->assertStringContainsString( trim( $visitor ), (string) $by_web['last_error'] );
		$this->assertSame( (string) $by_cli['last_error'], str_replace( $visitor, '', (string) $by_web['last_error'] ), 'the error differs only by the visitor\'s sentence' );
	}

	public function test_a_run_that_cannot_flush_stops_before_it_and_leaves_the_job_as_it_is_for_wp_cli(): void {
		list( $job, $before ) = $this->half();
		list( $now, $runs )   = $this->run_without_wordpress( $job, array(), false );
		$last                 = end( $runs );
		$this->assertSame( TickResult::LEFT, $last->status, $last->message );
		$this->assertStringContainsString( 'object cache can only be flushed by WP-CLI', $last->message );
		$site = $this->site();
		$this->assertTrue( $site['maintenance'], 'the maintenance file stays' );
		$site['maintenance'] = false;
		$this->assertSame( $before, $site, 'everything renamed back' );
		$this->assertSame( Job::SITE_CHANGING, $now->site_state, 'still held' );
		$this->assertSame( 'rollback', $now->cursor['phase'] ?? '', 'not recorded as restored' );

		$lease = array( 'lock_token', 'locked_until', 'updated_at' );
		$held  = array_diff_key( self::row( $now->id ), array_flip( $lease ) );
		for ( $i = 1; $i <= Runner::MAX_NO_PROGRESS + 2; $i++ ) {
			$again = $this->tick_without_wordpress( $now, array(), false );
			$this->assertSame( TickResult::LEFT, $again->status, "run {$i}: " . $again->message );
			$this->assertSame( $held, array_diff_key( self::row( $now->id ), array_flip( $lease ) ), "run {$i}: the row as it was but its lease" );
		}

		$flushes = 0;
		$this->retype(
			$now,
			array(
				'flush' => static function () use ( &$flushes ): void {
					++$flushes;
				},
			)
		);
		$done = $this->cli_run( Plugin::instance()->jobs()->find( $now->id ) );
		$this->assertSame( 1, $flushes, 'WP-CLI flushed' );
		$this->assertSame( Job::FAILED, $done->status, $done->last_error );
		$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state );
		$this->assertSame( $before, $this->site() );
		$this->assertStringNotContainsString( 'as no WP-CLI run went on with the swap.', (string) $done->last_error, 'WP-CLI ended it (no visitor\'s sentence)' );
	}

	/**
	 * The options table the swap moved aside (where the carry back writes), and the rows of this plugin's own there.
	 *
	 * @param Job $job Job.
	 * @return array{0: string, 1: array<int, array<string, string>>}
	 */
	private function moved_aside_options( Job $job ): array {
		global $wpdb;
		$old = '';
		foreach ( $this->plan_of( $job )['tables'] as $entry ) {
			if ( $wpdb->base_prefix . 'options' === $entry['live'] ) {
				$old = (string) $entry['old'];
			}
		}
		$this->assertNotSame( '', $old, 'the control: the options table is in the plan' );
		$wpdb->query( 'COMMIT' );
		$rows = (array) $wpdb->get_results( "SELECT option_id, option_name, option_value FROM `{$old}` WHERE option_name LIKE 'wpcheckpoint%' ORDER BY option_id", ARRAY_A );
		return array( $old, $rows );
	}

	/**
	 * Another connection holding every row of a table, in a transaction left open.
	 *
	 * @param string $table Table.
	 * @return ImportSession
	 */
	private static function holding( string $table ): ImportSession {
		$other = ImportSession::open( Credentials::from_wordpress() );
		$other->run( 'START TRANSACTION' );
		$other->rows( 'SELECT option_id FROM `' . $table . '` FOR UPDATE' );
		return $other;
	}

	public function test_a_carry_back_held_up_by_locks_ends_by_its_deadline_counted_as_busy_and_goes_on_later(): void {
		list( $job, $before )    = $this->half();
		list( $old, $old_rows )  = $this->moved_aside_options( $job );
		$this->assertNotSame( array(), $old_rows, 'the control: the old table has rows of this plugin\'s own' );
		$other = self::holding( $old );
		try {
			$this->seams = array();
			$started     = microtime( true );
			$result      = $this->tick_without_wordpress( $job );
			$took        = microtime( true ) - $started;
		} finally {
			$other->run( 'ROLLBACK' );
			$other->close();
		}
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( TickResult::MORE, $result->status, $result->message . ' ' . $now->last_error );
		$this->assertContains( 'carry_back_busy', $this->seams, 'the control: the carry back waited out the locks' );
		$this->assertNotContains( 'carried_back', $this->seams );
		$this->assertLessThan( SwapStep::LOCK_WAIT + 3, $took, 'bounded by its deadline (with the claim and one unit of margin)' );
		$this->assertSame( 1, $now->cursor['busy'] ?? null );
		$this->assertSame( SwapStep::CARRY_SEQ, $now->cursor['busy_for'] ?? null );
		$this->assertSame( 'rollback', $now->cursor['phase'] ?? '' );
		$this->assertSame( Job::SITE_CHANGING, $now->site_state );
		$this->assertTrue( Maintenance::held_in( $this->abspath ), 'the file held' );
		$this->assertSame( $old_rows, $this->moved_aside_options( $job )[1], 'the old table as it was: the try rolled back whole' );
		$log = Plugin::instance()->job_presenter()->log_tail( $now );
		$this->assertStringNotContainsString( 'not carried back', $log, 'never put back without the carry for that' );

		$this->seams        = array();
		list( $done, $runs ) = $this->run_without_wordpress( $now );
		$this->assertContains( 'carried_back', $this->seams, 'carried back once the locks were gone' );
		$this->assertSame( Job::FAILED, $done->status, end( $runs )->message . ' ' . $done->last_error );
		$this->assertArrayNotHasKey( 'busy', $done->cursor );
		$this->assertSame( $before, $this->site() );
	}

	public function test_a_carry_back_whose_tries_are_spent_is_left_held_up_and_put_back_once_the_locks_are_gone(): void {
		global $wpdb;
		list( $job, $before )   = $this->half();
		list( $old, $old_rows ) = $this->moved_aside_options( $job );
		// The last try left (as a run that took the job over after three would find it).
		$wpdb->query( 'COMMIT' );
		$cursor = array(
			'phase'      => 'rollback',
			'attempt'    => $job->cursor['attempt'],
			'mark'       => $job->cursor['mark'],
			'reason'     => SwapStep::INTERRUPTED,
			'busy'       => count( SwapStep::RETRIES ),
			'busy_for'   => SwapStep::CARRY_SEQ,
			'busy_after' => 0,
		);
		$wpdb->update( JobRepository::table(), array( 'cursor_json' => wp_json_encode( $cursor ) ), array( 'id' => $job->id ) );
		$wpdb->query( 'COMMIT' );
		$row   = self::row( $job->id );
		$other = self::holding( $old );
		try {
			$this->seams = array();
			$result      = $this->tick_without_wordpress( $job );
		} finally {
			$other->run( 'ROLLBACK' );
			$other->close();
		}
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertContains( 'carry_back_locked', $this->seams, 'the control: the carry back made its try' );
		$this->assertSame( TickResult::LEFT, $result->status, $result->message . ' ' . $now->last_error );
		$this->assertTrue( $result->held_up, 'held up' );
		$this->assertSame( \WPCheckpoint\Jobs\WebPace::SCHEDULE[0], $result->retry_after, 'the next try in the first wait' );
		$this->assertNotSame( Job::FAILED, $now->status );
		$claim = array_flip( array( 'lock_token', 'locked_until', 'updated_at', 'takeovers', 'takeover_mark' ) );
		$this->assertSame( array_diff_key( $row, $claim ), array_diff_key( self::row( $job->id ), $claim ), 'the row as it was but the lease (and the claim\'s count)' );
		$this->assertSame( $old_rows, $this->moved_aside_options( $job )[1], 'the old table as it was' );
		$log = Plugin::instance()->job_presenter()->log_tail( $now );
		$this->assertStringContainsString( 'left to WP-CLI, held up', $log );
		$this->assertStringContainsString( '"busy_for":' . SwapStep::CARRY_SEQ, $log, 'the tries said in the log' );
		$this->assertStringNotContainsString( 'not carried back', $log );
		$this->assertTrue( Maintenance::held_in( $this->abspath ), 'the site still held' );
		// The locks gone, the next run without WordPress carries back and puts the site back.
		$this->seams        = array();
		list( $done, $runs ) = $this->run_without_wordpress( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertContains( 'carried_back', $this->seams, end( $runs )->message );
		$this->assertSame( Job::FAILED, $done->status, 'a rollback\'s end, for a retry: ' . $done->last_error );
		$this->assertSame( $before, $this->site() );
	}

	public function test_a_rename_back_whose_tries_are_spent_is_left_held_up_and_put_back_once_the_lock_is_gone(): void {
		global $wpdb;
		list( $job, $before ) = $this->half( 1 + count( self::site_tables() ) ); // A table of the test's renamed after the site's.
		$entry                = null;
		$wpdb->query( 'COMMIT' );
		foreach ( $this->plan_of( $job )['tables'] as $table ) {
			if ( 1 !== preg_match( '/(options|sitemeta)\z/', (string) $table['live'] ) && '' !== (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( (string) $table['old'] ) ) ) ) {
				$entry = $table;
			}
		}
		$this->assertNotNull( $entry, 'the control: a table of the test\'s moved aside' );
		$cursor = array(
			'phase'      => 'rollback',
			'attempt'    => $job->cursor['attempt'],
			'mark'       => $job->cursor['mark'],
			'reason'     => SwapStep::INTERRUPTED,
			'busy'       => count( SwapStep::RETRIES ),
			'busy_for'   => (int) $entry['seq'],
			'busy_after' => 0,
		);
		$wpdb->update( JobRepository::table(), array( 'cursor_json' => wp_json_encode( $cursor ) ), array( 'id' => $job->id ) );
		$wpdb->query( 'COMMIT' );
		$other = ImportSession::open( Credentials::from_wordpress() );
		$other->run( 'LOCK TABLES `' . $entry['old'] . '` READ' );
		try {
			$this->seams = array();
			// The carry back is a unit of its own first: the rename may come in the next run.
			for ( $i = 0; $i < 3; $i++ ) {
				$result = $this->tick_without_wordpress( Plugin::instance()->jobs()->find( $job->id ) );
				if ( TickResult::MORE !== $result->status ) {
					break;
				}
			}
		} finally {
			$other->run( 'UNLOCK TABLES' );
			$other->close();
		}
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( TickResult::LEFT, $result->status, $result->message . ' ' . $now->last_error );
		$this->assertTrue( $result->held_up );
		$this->assertNotContains( 'table_back', $this->seams, 'nothing renamed' );
		$this->assertNotSame( Job::FAILED, $now->status );
		$this->assertSame( count( SwapStep::RETRIES ), $now->cursor['busy'] ?? null, 'the tries as last checkpointed' );
		$this->assertStringContainsString( '"busy_for":' . (int) $entry['seq'], Plugin::instance()->job_presenter()->log_tail( $now ) );
		$this->assertTrue( Maintenance::held_in( $this->abspath ) );
		list( $done, $runs ) = $this->run_without_wordpress( $now );
		$this->assertSame( Job::FAILED, $done->status, end( $runs )->message . ' ' . $done->last_error );
		$this->assertSame( $before, $this->site() );
	}

	public function test_a_transient_failure_through_every_retry_is_left_held_up_and_gone_on_with_once_it_passes(): void {
		list( $job, $before ) = $this->half();
		$failing             = true;
		$parts               = array(
			'at' => function ( string $point ) use ( &$failing ): void {
				$this->seams[] = $point;
				if ( $failing && 'rollback' === $point ) {
					throw new \WPCheckpoint\Jobs\TransientFailure( 'The test\'s database went away for a moment.' );
				}
			},
		);
		$results = array();
		for ( $i = 0; $i <= \WPCheckpoint\Jobs\Runner::MAX_RETRIES + 1; $i++ ) {
			$was       = self::row( $job->id );
			$result    = $this->tick_without_wordpress( Plugin::instance()->jobs()->find( $job->id ), $parts );
			$results[] = $result->status;
			if ( TickResult::LEFT === $result->status ) {
				break;
			}
		}
		$this->assertSame( TickResult::LEFT, end( $results ), 'left after every retry: ' . implode( ', ', $results ) );
		$this->assertSame( \WPCheckpoint\Jobs\Runner::MAX_RETRIES, count( array_keys( $results, TickResult::WAITING, true ) ), 'the control: it waited out each retry first: ' . implode( ', ', $results ) );
		$this->assertTrue( $result->held_up );
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertNotSame( Job::FAILED, $now->status, (string) $now->last_error );
		$claim = array_flip( array( 'lock_token', 'locked_until', 'updated_at', 'takeovers', 'takeover_mark' ) );
		$this->assertSame( array_diff_key( $was, $claim ), array_diff_key( self::row( $job->id ), $claim ), 'the last run wrote nothing but the lease' );
		$this->assertTrue( Maintenance::held_in( $this->abspath ), 'the site still held' );
		// It passes: the next run goes on and puts the site back.
		$failing             = false;
		list( $done, $runs ) = $this->run_without_wordpress( Plugin::instance()->jobs()->find( $job->id ), $parts );
		$this->assertSame( Job::FAILED, $done->status, end( $runs )->message . ' ' . $done->last_error );
		$this->assertSame( $before, $this->site() );
	}

	public function test_a_not_here_in_the_carry_back_leaves_the_job_to_wp_cli_with_nothing_carried_or_left_out(): void {
		list( $job, $before )   = $this->half();
		list( $old, $old_rows ) = $this->moved_aside_options( $job );
		$row                    = self::row( $job->id );
		$throw                  = array(
			'at' => function ( string $point ): void {
				$this->seams[] = $point;
				if ( 'carry_back_1' === $point ) {
					throw new NotHere( 'The test cannot go on here.' );
				}
			},
		);
		$this->seams = array();
		$result      = $this->tick_without_wordpress( $job, $throw );
		$this->assertContains( 'carry_back_1', $this->seams, 'the control: the carry back sent its first statement' );
		$this->assertSame( TickResult::LEFT, $result->status, $result->message );
		$this->assertSame( $old_rows, $this->moved_aside_options( $job )[1], 'rolled back whole' );
		// The claim's own writes (the killed run's lease taken over: its count and mark), the lease, and the decision to
		// roll back (checkpointed before the carry) aside.
		$lease = array( 'lock_token', 'locked_until', 'updated_at', 'takeovers', 'takeover_mark', 'cursor_json', 'progress', 'progress_message', 'progress_at' );
		$after = self::row( $job->id );
		$this->assertSame( array_diff_key( $row, array_flip( $lease ) ), array_diff_key( $after, array_flip( $lease ) ), 'nothing else written' );
		$this->assertSame( 'rollback', json_decode( (string) $after['cursor_json'], true )['phase'] ?? '', 'the decision to roll back recorded before the carry, as WP-CLI goes on from' );
		$this->assertStringNotContainsString( 'not carried back', Plugin::instance()->job_presenter()->log_tail( Plugin::instance()->jobs()->find( $job->id ) ) );
		$this->assertNotContains( 'table_back', $this->seams, 'nothing renamed' );
		$done = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( $before, $this->site(), $done->last_error );
	}
}
