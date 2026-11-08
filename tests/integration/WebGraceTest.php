<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Jobs\TransientFailure;
use WPCheckpoint\Jobs\WebJobs;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * A run made without WordPress takes a job only GRACE_SECONDS after the last write of a run in WordPress
 * (Job::$wp_run_at), in the claim's statement itself; its own claims and releases never move that time, so a run held
 * up is tried again at its pace, not ten minutes later; a gate's refusals (another installation's, sharing the
 * database) are no run's writes and move it neither. Version 16 adds the column: older code reads and writes the
 * table as before, and a table without it holds a job that holds the site for both runs, failing none.
 */
final class WebGraceTest extends SwapTestCase {

	/**
	 * A restore killed with a directory swapped in.
	 *
	 * @return Job
	 */
	private function killed(): Job {
		$job = $this->at_swap();
		$this->killed_at( $job, 'dir_in' );
		return Plugin::instance()->jobs()->find( $job->id );
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
	 * The last write of a run in WordPress, set $seconds ago by the database's clock.
	 *
	 * @param int $id      Job id.
	 * @param int $seconds Seconds.
	 * @return void
	 */
	private static function ran_ago( int $id, int $seconds ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . JobRepository::table() . ' SET wp_run_at = UNIX_TIMESTAMP() - %d WHERE id = %d', $seconds, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the plugin's table.
		$wpdb->query( 'COMMIT' );
	}

	public function test_after_a_wp_cli_heartbeat_a_run_without_wordpress_waits_ten_minutes(): void {
		$job               = $this->killed();
		$this->grace_over = false;
		$jobs              = Plugin::instance()->jobs();
		$held              = $jobs->acquire( $job->id );
		$this->assertNotNull( $held, 'the control: WP-CLI takes the job once the killed run\'s lease ran out' );
		$this->assertTrue( $jobs->heartbeat( $held['job'], $held['token'] ) );
		$this->assertTrue( $jobs->release( $held['job'], $held['token'] ) );
		$this->assertGreaterThanOrEqual( $jobs->now() - 1, (int) self::row( $job->id )['wp_run_at'], 'the control: WP-CLI\'s writes stamp it' );

		self::ran_ago( $job->id, 540 );
		$before      = self::row( $job->id );
		$this->seams = array();
		$waits       = $this->tick_without_wordpress( $jobs->find( $job->id ) );
		$this->assertSame( TickResult::BUSY, $waits->status, 'nine minutes after: not taken: ' . $waits->message . ' ' . implode( ' | ', $this->web_notes ) );
		$this->assertSame( $before, self::row( $job->id ), 'nothing written' );
		$this->assertSame( array(), $this->seams, 'nothing of the swap ran' );

		self::ran_ago( $job->id, 660 );
		$taken = $this->tick_without_wordpress( $jobs->find( $job->id ) );
		$this->assertNotContains( $taken->status, array( TickResult::BUSY, TickResult::LEFT ), 'eleven minutes after: taken: ' . $taken->message . ' ' . implode( ' | ', $this->web_notes ) );
		$this->assertNotSame( array(), $this->seams, 'the control: the swap ran' );
	}

	public function test_a_run_held_up_is_tried_again_at_its_pace_without_waiting_out_the_grace_period(): void {
		$job = $this->killed(); // Past the grace period (killed_at()).
		$this->grace_over = false;
		$aged             = (int) self::row( $job->id )['wp_run_at'];
		$failing          = true;
		$parts            = array(
			'at' => function ( string $point ) use ( &$failing ): void {
				$this->seams[] = $point;
				if ( $failing && 'rollback' === $point ) {
					throw new TransientFailure( 'The test\'s database went away for a moment.' );
				}
			},
		);
		$results = array();
		for ( $i = 0; $i <= \WPCheckpoint\Jobs\Runner::MAX_RETRIES + 1; $i++ ) {
			$result    = $this->tick_without_wordpress( Plugin::instance()->jobs()->find( $job->id ), $parts );
			$results[] = $result->status;
			if ( TickResult::LEFT === $result->status || TickResult::BUSY === $result->status ) {
				break;
			}
		}
		$this->assertSame( TickResult::LEFT, end( $results ), 'held up after every retry, each run taking the job again: ' . implode( ', ', $results ) );
		$this->assertTrue( $result->held_up );
		$this->assertSame( $aged, (int) self::row( $job->id )['wp_run_at'], 'the runs\' claims, checkpoints and releases left it' );
		$failing = false;
		$this->seams = array();
		$next        = $this->tick_without_wordpress( Plugin::instance()->jobs()->find( $job->id ), $parts );
		$this->assertNotContains( $next->status, array( TickResult::BUSY, TickResult::LEFT ), 'tried again at once, not after ten minutes: ' . $next->message . ' ' . implode( ' | ', $this->web_notes ) );
		$this->assertContains( 'rollback', $this->seams );
	}

	public function test_refusals_of_another_installations_gate_do_not_put_off_the_sites_run_without_wordpress(): void {
		global $wpdb;
		$job              = $this->killed(); // Its last run in WordPress more than ten minutes ago (killed_at()).
		$this->grace_over = false;
		$aged             = (int) self::row( $job->id )['wp_run_at'];
		// The job as another installation sharing the database sees it: managed by a token it does not hold (a copy of
		// the site, its own storage token). Its gate refuses it (storage_changed) and counts each refusal.
		$wpdb->update( JobRepository::table(), array( 'held_by' => str_repeat( 'c', 32 ) ), array( 'id' => $job->id ) );
		$wpdb->query( 'COMMIT' );
		for ( $i = 1; $i <= 3; $i++ ) {
			$refused = $this->cli_tick( Plugin::instance()->jobs()->find( $job->id ) );
			$this->assertSame( TickResult::BLOCKED, $refused->status, $refused->message );
			$this->assertSame( (string) $i, self::row( $job->id )['blocked_count'], 'the control: each refusal is written (record_blocked())' );
		}
		$this->assertSame( $aged, (int) self::row( $job->id )['wp_run_at'], 'no refusal moved it' );
		$this->seams = array();
		$taken       = $this->tick_without_wordpress( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertNotContains( $taken->status, array( TickResult::BUSY, TickResult::LEFT ), 'the site\'s own run takes it: ' . $taken->message . ' ' . implode( ' | ', $this->web_notes ) );
		$this->assertNotSame( array(), $this->seams );
	}

	public function test_version_16_adds_wp_run_at_and_older_code_reads_and_writes_the_table_as_before(): void {
		global $wpdb;
		$table = JobRepository::table();
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN wp_run_at" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's table.
		$this->assertNotContains( 'wp_run_at', $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ), 'the control: the table of version 15' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- as above.
		Options::set( Schema::OPTION, array( 'version' => 15, 'min_compatible' => 1 ) );
		$result = Schema::ensure();
		$this->assertSame( 'migrated', $result['action'], (string) wp_json_encode( $result ) );
		$this->assertSame( 16, Options::get( Schema::OPTION )['version'] );
		$this->assertSame( 1, Options::get( Schema::OPTION )['min_compatible'], 'older code neither reads nor writes it' );
		$this->assertContains( 'wp_run_at', $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- as above.

		// What version 15's code sends (its statements as they were, without the column): its insert, its claim, its
		// heartbeat, its checkpoint, its release, its transition; and its read of the row.
		$this->assertSame( 1, $wpdb->insert( $table, array( 'type' => 'old', 'status' => Job::QUEUED, 'storage_token' => 'tok', 'cursor_json' => '[]', 'created_at' => 1, 'updated_at' => 1 ) ) );
		$id   = (int) $wpdb->insert_id;
		$now  = time();
		$old  = array(
			'claim'      => array( "UPDATE {$table} SET takeovers = IF(lock_token <> '', IF(takeover_mark = MD5(CONCAT(step, '|', COALESCE(cursor_json, ''))), takeovers + 1, 1), takeovers), takeover_mark = IF(lock_token <> '', MD5(CONCAT(step, '|', COALESCE(cursor_json, ''))), takeover_mark), attempts = IF(status = %s, attempts + 1, attempts), started_at = IF(started_at = 0, %d, started_at), status = %s, lock_token = %s, locked_until = %d, updated_at = %d, questions_json = NULL WHERE id = %d AND status IN (%s, %s, %s) AND (status <> %s OR questions_json IS NULL OR questions_json = '' OR questions_json = '[]') AND storage_token = %s AND (lock_token = '' OR locked_until < %d)", array( Job::QUEUED, $now, Job::RUNNING, 'tkn', $now + 120, $now, $id, Job::QUEUED, Job::RUNNING, Job::PAUSED, Job::PAUSED, 'tok', $now ) ),
			'heartbeat'  => array( "UPDATE {$table} SET locked_until = %d, updated_at = %d WHERE id = %d AND lock_token = %s", array( $now + 240, $now, $id, 'tkn' ) ),
			'checkpoint' => array( "UPDATE {$table} SET step = %s, cursor_json = %s, progress = %d, progress_message = %s, updated_at = %d, progress_at = %d, blocked_count = %d WHERE id = %d AND lock_token = %s", array( 'one', '{"n":1}', 10, 'Working', $now, $now, 0, $id, 'tkn' ) ),
			'release'    => array( "UPDATE {$table} SET lock_token = '', locked_until = 0, updated_at = %d WHERE id = %d AND lock_token = %s", array( $now, $id, 'tkn' ) ),
			'transition' => array( "UPDATE {$table} SET status = %s, updated_at = %d, finished_at = %d WHERE id = %d AND status = %s", array( Job::COMPLETED, $now, $now, $id, Job::RUNNING ) ),
		);
		foreach ( $old as $what => $statement ) {
			$this->assertSame( 1, $wpdb->query( $wpdb->prepare( $statement[0], $statement[1] ) ), $what . ': ' . $wpdb->last_error ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the old statements, as they were.
		}
		$row = self::row( $id );
		$this->assertSame( Job::COMPLETED, $row['status'] );
		$this->assertSame( '0', $row['wp_run_at'], 'left at its default by older code' );
		// Version 15's read: SELECT * and every column it knows, any other passed over (JobStore::hydrate()'s rule then).
		$read = \WPCheckpoint\Jobs\JobStore::hydrate( $row + array( 'a_column_of_a_later_version' => 'x' ) );
		$this->assertSame( array(), $read->missing_columns );
		$this->assertSame( Job::COMPLETED, $read->status );
	}

	public function test_a_table_without_the_column_holds_a_job_that_holds_the_site_for_both_runs_and_fails_none(): void {
		global $wpdb;
		$job              = $this->killed();
		$this->grace_over = false; // The column is gone: nothing to move back.
		$table            = JobRepository::table();
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN wp_run_at" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's table.
		$wpdb->query( 'COMMIT' );
		$this->assertSame( array( 'wp_run_at' ), Plugin::instance()->jobs()->find( $job->id )->missing_columns, 'the control: the row lacks it' );
		$before = self::row( $job->id );

		$cli = $this->cli_tick( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( TickResult::BLOCKED, $cli->status, $cli->message );
		$this->assertStringContainsString( 'wp_run_at', $cli->message );
		$after = self::row( $job->id );
		$this->assertNotSame( Job::FAILED, $after['status'], 'not failed: a failed job no longer puts the site back' );
		$this->assertSame( $before['site_state'], $after['site_state'] );
		$this->assertSame( $before['cursor_json'], $after['cursor_json'], 'nothing ran' );

		$this->seams = array();
		$web         = $this->tick_without_wordpress( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( TickResult::LEFT, $web->status, $web->message );
		$this->assertSame( $after, self::row( $job->id ), 'nothing written by the run without WordPress' );
		$this->assertSame( array(), $this->seams );
		$this->assertTrue( Maintenance::held_in( $this->abspath ), 'the site still held' );
	}

	/**
	 * The grace period is the claim's own condition (the managing piece WebJobs gives JobStore), not a check before it.
	 */
	public function test_the_grace_period_is_a_condition_of_the_claim_statement(): void {
		$this->assertStringContainsString( 'wp_run_at <= %d', WebJobs::MANAGING );
	}
}
