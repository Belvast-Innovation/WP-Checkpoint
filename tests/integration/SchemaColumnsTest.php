<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobActions;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\JobsUnavailable;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\LedgerOutdated;
use WPCheckpoint\Support\Check;
use WPCheckpoint\Support\Environment;
use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Jobs\ClosureStep;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * The schema version is recorded only when the job table has the columns
 * of that version, read back from the server (dbDelta() says nothing when
 * a statement fails, as without the ALTER privilege); a job whose row lacks
 * columns fails with the reason instead of reading defaults as values.
 */
final class SchemaColumnsTest extends JobTestCase {

	/**
	 * Refuse the statements matching $pattern the way a server refuses a user without the privilege: the
	 * statement fails. Counts them.
	 */
	private function refuse( string $pattern, int &$hits ): void {
		add_filter(
			'query',
			static function ( $query ) use ( $pattern, &$hits ) {
				if ( 1 === preg_match( $pattern, (string) $query ) ) {
					++$hits;
					return 'ALTER TABLE wpcheckpoint_refused_no_such_table ADD COLUMN x int';
				}
				return $query;
			}
		);
	}

	/**
	 * Schema::ensure() with wpdb's error output off (the refused statements are expected).
	 *
	 * @return array<string, mixed>
	 */
	private static function ensure(): array {
		global $wpdb;
		$quiet = $wpdb->suppress_errors( true );
		try {
			return Schema::ensure();
		} finally {
			$wpdb->suppress_errors( $quiet );
		}
	}

	/**
	 * The wait after a failed attempt is over.
	 */
	private static function ten_minutes_later(): void {
		$last = Options::get( Schema::RETRY_OPTION, null );
		if ( ! is_array( $last ) || ! isset( $last['after'] ) ) {
			throw new \RuntimeException( 'no failed attempt is recorded' );
		}
		$last['after'] = time() - 1;
		Options::set( Schema::RETRY_OPTION, $last );
	}

	/**
	 * Count the statements that change the job table's structure (ALTER TABLE, CREATE TABLE).
	 */
	private function count_changes( int &$changes ): void {
		add_filter(
			'query',
			static function ( $query ) use ( &$changes ) {
				if ( 1 === preg_match( '/^\s*(ALTER|CREATE) TABLE \S*wpcheckpoint_jobs\b/i', (string) $query ) ) {
					++$changes;
				}
				return $query;
			},
			5 // Before refuse(): the statement as sent.
		);
	}

	private static function drop_column( string $column ): void {
		global $wpdb;
		$wpdb->query( 'ALTER TABLE ' . Schema::jobs_table() . ' DROP COLUMN ' . $column );
		// The control: the column is really gone.
		if ( in_array( $column, $wpdb->get_col( 'SHOW COLUMNS FROM ' . Schema::jobs_table() ), true ) ) {
			throw new \RuntimeException( "{$column} is still there" );
		}
	}

	private function database_check(): Check {
		$env = new Environment(
			Plugin::instance()->directories(),
			array(
				'loopback' => static function ( string $challenge ): array {
					return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( array( 'challenge' => $challenge, 'memory_limit' => '256M', 'memory_bytes' => 268435456, 'max_execution_time' => 60, 'set_time_limit' => false ) ), 'headers' => array(), 'cookies' => array(), 'filename' => null );
				},
			)
		);
		foreach ( $env->checks( true ) as $check ) {
			if ( 'database.jobs' === $check->id ) {
				return $check;
			}
		}
		$this->fail( 'no database.jobs check' );
	}

	public function test_a_column_the_migration_could_not_add_keeps_the_version_and_says_why(): void {
		global $wpdb;
		self::drop_column( 'cron_deferrals' );
		Options::set( Schema::OPTION, array( 'version' => 6, 'min_compatible' => 1 ) );
		$hits = 0;
		$this->refuse( '/^ALTER TABLE \S+ ADD COLUMN `?cron_deferrals`? /i', $hits );

		$result = self::ensure();
		$this->assertGreaterThan( 0, $hits, 'the control: the migration tried to add it' );
		$this->assertSame( 'failed', $result['action'] );
		$this->assertSame( array( 'cron_deferrals' ), $result['problems'] );
		$this->assertSame( 6, Schema::stored()['version'], 'the version does not move' );
		$this->assertNotContains( 'cron_deferrals', $wpdb->get_col( 'SHOW COLUMNS FROM ' . Schema::jobs_table() ) );

		$check = $this->database_check();
		$this->assertSame( Check::ERROR, $check->status );
		$this->assertStringContainsString( 'cron_deferrals', $check->message );
		$this->assertStringContainsString( 'ALTER privilege', $check->message );
		try {
			$quiet = $wpdb->suppress_errors( true );
			Plugin::instance()->jobs()->create( 'export' );
			$this->fail( 'a job was created on a table that lacks a column' );
		} catch ( JobsUnavailable $e ) {
			$this->assertStringContainsString( 'lacks columns this version of WP Checkpoint needs, or has them narrower (cron_deferrals)', $e->getMessage() );
		} finally {
			$wpdb->suppress_errors( $quiet );
		}

		// The next attempt, ten minutes later; with the privilege back, it adds the column and records the version.
		remove_all_filters( 'query' );
		self::ten_minutes_later();
		$this->assertNotNull( Options::get( Schema::RETRY_OPTION, null ), 'the control: the failure is recorded' );
		$this->assertSame( 'migrated', Schema::ensure()['action'] );
		$this->assertSame( Schema::CURRENT, Schema::stored()['version'] );
		$this->assertNull( Options::get( Schema::RETRY_OPTION, null ), 'and cleared by the attempt that worked' );
		$this->assertSame( Check::OK, $this->database_check()->status );
	}

	public function test_a_column_the_migration_could_not_widen_keeps_the_version(): void {
		global $wpdb;
		$wpdb->query( 'ALTER TABLE ' . Schema::jobs_table() . " MODIFY failure_kind varchar(16) NOT NULL DEFAULT ''" );
		Options::set( Schema::OPTION, array( 'version' => 5, 'min_compatible' => 1 ) );
		$hits = 0;
		$this->refuse( '/^ALTER TABLE \S+ CHANGE COLUMN `?failure_kind`? /i', $hits );
		$result = self::ensure();
		$this->assertGreaterThan( 0, $hits, 'the control: the migration tried to widen it' );
		$this->assertSame( 'failed', $result['action'] );
		$this->assertSame( array( 'failure_kind (varchar(16), needs varchar(32))' ), $result['problems'] );
		$this->assertSame( 5, Schema::stored()['version'] );
	}

	public function test_columns_that_cannot_be_read_keep_the_version(): void {
		self::drop_column( 'cron_deferrals' );
		Options::set( Schema::OPTION, array( 'version' => 6, 'min_compatible' => 1 ) );
		$hits = 0;
		$this->refuse( '/^SHOW COLUMNS FROM /i', $hits );
		$result = self::ensure();
		$this->assertSame( 1, $hits, 'the control: the columns were asked for' );
		$this->assertSame( 'failed', $result['action'], 'no answer is not an answer that all is there' );
		$this->assertNull( $result['problems'] );
		$this->assertSame( 6, Schema::stored()['version'] );
		$this->assertStringContainsString( 'could not be read', Schema::problem_message( $result['problems'] ) );
	}

	/**
	 * A job of the fixture type 'plain', ticked once with its columns all there (the control: it moves on).
	 */
	private function running_job(): int {
		$this->register( 'plain', array( $this->counting_step( 'p', 5 ) ) );
		$id = Plugin::instance()->jobs()->create( 'plain' )->id;
		Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT );
		$this->assertSame( 1, (int) JobContext::strip_reserved( Plugin::instance()->jobs()->find( $id )->cursor )['n'], 'the control: it moves on' );
		return $id;
	}

	public function test_a_job_whose_row_lacks_a_column_fails_with_the_reason(): void {
		$id = $this->running_job();
		// Gone after the version was recorded (removed by hand, a table restored from elsewhere).
		$this->assertFileExists( Plugin::instance()->jobs()->find( $id )->storage_path . '/tmp/job-' . $id . '.lock', 'the control: a running job holds its lock file' );
		self::drop_column( 'cron_deferrals' );
		$this->assertSame( array( 'cron_deferrals' ), Plugin::instance()->jobs()->find( $id )->missing_columns );

		$result = Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT );
		$job    = Plugin::instance()->jobs()->find( $id );
		$this->assertSame( 'failed', $result->status );
		$this->assertSame( Job::FAILED, $job->status, 'failed, not left running without progress' );
		$this->assertSame( 1, (int) JobContext::strip_reserved( $job->cursor )['n'], 'nothing ran' );
		$this->assertStringContainsString( 'lacks columns this version of WP Checkpoint needs, or has them narrower (cron_deferrals)', $job->last_error );
		$this->assertSame( Job::FAILURE_TEMPORARY, $job->failure_kind, 'a retry works once the column is there' );
		$this->assertSame( '', $job->lock_token );
		$this->assertFileDoesNotExist( $job->storage_path . '/tmp/job-' . $id . '.lock', 'the lock file goes with the ended job' );
		$log = (string) file_get_contents( $job->storage_path . '/' . $job->log_path );
		$this->assertStringContainsString( 'ERROR Job failed', $log );
		$this->assertStringContainsString( 'cron_deferrals', $log );
	}

	public function test_a_job_whose_row_lacks_the_failure_kind_still_fails(): void {
		$id = $this->running_job();
		self::drop_column( 'failure_kind' );
		Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT );
		$job = Plugin::instance()->jobs()->find( $id );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( '(failure_kind)', $job->last_error );
		$this->assertSame( '', $job->failure_kind );
	}

	public function test_a_job_a_live_run_holds_is_left_to_it(): void {
		global $wpdb;
		$id = $this->running_job();
		$wpdb->update( Schema::jobs_table(), array( 'lock_token' => 'live', 'locked_until' => time() + 600 ), array( 'id' => $id ) );
		self::drop_column( 'cron_deferrals' );
		$result = Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT );
		$this->assertSame( 'busy', $result->status );
		$this->assertSame( Job::RUNNING, Plugin::instance()->jobs()->find( $id )->status );
		// The control: without the live lease, the same tick fails it.
		$wpdb->update( Schema::jobs_table(), array( 'locked_until' => time() - 1 ), array( 'id' => $id ) );
		Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT );
		$this->assertSame( Job::FAILED, Plugin::instance()->jobs()->find( $id )->status );
	}

	public function test_retry_and_answer_from_the_page_are_refused_by_the_row_until_the_admin_adds_the_column(): void {
		global $wpdb;
		$id = $this->running_job();
		self::drop_column( 'cron_deferrals' );
		Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT );
		$this->assertSame( Job::FAILED, Plugin::instance()->jobs()->find( $id )->status );
		$changes = 0;
		$this->count_changes( $changes );

		// The page's Retry and answers are REST requests: they only read, and the job's row says what is missing.
		Schema::set_upgrade_context( null );
		$response = $this->rest( 'POST', 'jobs/' . $id . '/retry' );
		$this->assertSame( 503, $response->get_status() );
		$this->assertStringContainsString( '(cron_deferrals)', $response->as_error()->get_error_message() );
		try {
			Plugin::instance()->job_actions()->answer( $id, array( 'x' => 'go' ) );
			$this->fail( 'answered on a table that lacks a column' );
		} catch ( JobsUnavailable $e ) {
			$this->assertStringContainsString( '(cron_deferrals)', $e->getMessage() );
		}
		$this->assertSame( 0, $changes, 'no ALTER from REST' );
		$this->assertSame( Job::FAILED, Plugin::instance()->jobs()->find( $id )->status );

		// The plugin's page (an admin request) adds it; a refusal is recorded, and a repair that works clears it.
		$hits = 0;
		$this->refuse( '/^ALTER TABLE \S+ ADD COLUMN `?cron_deferrals`? /i', $hits );
		$this->assertSame( 'failed', $this->as_admin( true )['action'] );
		$this->assertGreaterThan( 0, $changes, 'the control: an admin request sends the ALTER' );
		$this->assertNotNull( Options::get( Schema::RETRY_OPTION, null ) );
		remove_all_filters( 'query' );
		self::ten_minutes_later();
		$this->assertSame( 'repaired', $this->as_admin( true )['action'] );
		$this->assertNull( Options::get( Schema::RETRY_OPTION, null ), 'the repair that worked clears the record' );
		$this->assertContains( 'cron_deferrals', $wpdb->get_col( 'SHOW COLUMNS FROM ' . Schema::jobs_table() ) );
		$this->assertSame( Schema::CURRENT, Schema::stored()['version'] );

		$this->assertSame( 200, $this->rest( 'POST', 'jobs/' . $id . '/retry' )->get_status(), 'then the retry goes through' );
		$this->assertSame( Job::QUEUED, Plugin::instance()->jobs()->find( $id )->status );
	}
	public function test_a_retry_is_refused_by_the_row_when_the_columns_cannot_be_read(): void {
		$id = $this->running_job();
		self::drop_column( 'cron_deferrals' );
		Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT );
		$hits = 0;
		// No answer about the columns: the row read back is the evidence that one is missing.
		$this->refuse( '/^SHOW COLUMNS FROM /i', $hits );
		$response = $this->rest( 'POST', 'jobs/' . $id . '/retry' );
		$this->assertGreaterThan( 0, $hits, 'the control: the columns were asked for' );
		$this->assertSame( 503, $response->get_status() );
		$this->assertStringContainsString( '(cron_deferrals)', $response->as_error()->get_error_message() );
		$this->assertSame( Job::FAILED, Plugin::instance()->jobs()->find( $id )->status );
	}

	public function test_a_column_lost_after_the_version_was_recorded_is_added_by_the_page_and_by_a_new_job(): void {
		global $wpdb;
		self::drop_column( 'cron_deferrals' );
		$this->assertSame( 'none', Schema::ensure()['action'], 'a tick does not look (the control: without verify, nothing sees it)' );
		$result = Schema::ensure( true ); // What the plugin's page, activation, a new job, retry and answer run.
		$this->assertSame( 'repaired', $result['action'] );
		$this->assertContains( 'cron_deferrals', $wpdb->get_col( 'SHOW COLUMNS FROM ' . Schema::jobs_table() ) );
		$this->assertSame( Schema::CURRENT, Schema::stored()['version'], 'the version was never touched' );

		self::drop_column( 'cron_deferrals' );
		$this->register( 'plain', array( $this->counting_step( 'p', 5 ) ) );
		$job = Plugin::instance()->jobs()->create( 'plain' );
		$this->assertSame( array(), Plugin::instance()->jobs()->find( $job->id )->missing_columns, 'the new job has every column' );
		Plugin::instance()->job_actions()->tick( $job->id, JobActions::NO_TIME_LEFT );
		$this->assertSame( Job::RUNNING, Plugin::instance()->jobs()->find( $job->id )->status, 'and runs' );
	}

	public function test_a_column_the_migration_could_not_widen_fails_the_running_job_without_writing_to_it(): void {
		global $wpdb;
		$id = $this->running_job();
		$wpdb->query( 'ALTER TABLE ' . Schema::jobs_table() . " MODIFY failure_kind varchar(16) NOT NULL DEFAULT ''" );
		Options::set( Schema::OPTION, array( 'version' => 5, 'min_compatible' => 1 ) );
		$hits = 0;
		$this->refuse( '/^ALTER TABLE \S+ CHANGE COLUMN `?failure_kind`? /i', $hits );
		$result = Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT );
		$this->assertGreaterThan( 0, $hits, 'the control: the migration of the tick tried to widen it' );
		$this->assertSame( 'failed', $result->status );
		$job = Plugin::instance()->jobs()->find( $id );
		$this->assertSame( Job::FAILED, $job->status, 'failed with the reason, not by a refused stamp later' );
		$this->assertStringContainsString( 'failure_kind (varchar(16), needs varchar(32))', $job->last_error );
		// get_var() gives null for an empty value; the row gives the value.
		$this->assertSame( '', $wpdb->get_row( $wpdb->prepare( 'SELECT failure_kind FROM ' . Schema::jobs_table() . ' WHERE id = %d', $id ), ARRAY_A )['failure_kind'], 'the narrow column was not written' );
	}

	public function test_a_failure_that_cannot_be_written_says_why_and_waits(): void {
		$id   = $this->running_job();
		$hits = 0;
		self::drop_column( 'cron_deferrals' );
		// Even the first version's columns refused: the failure itself cannot be recorded.
		$this->refuse( "/^UPDATE \S+ SET status = 'failed'/", $hits );
		$result = Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT );
		$this->assertSame( 1, $hits, 'the control: the failure was tried' );
		$this->assertSame( 'blocked', $result->status, 'not "another process is working on it"' );
		$this->assertStringContainsString( '(cron_deferrals)', $result->message );
		$this->assertGreaterThan( 0, $result->retry_after );
		$this->assertSame( Job::RUNNING, Plugin::instance()->jobs()->find( $id )->status );
	}

	public function test_a_table_that_cannot_be_created_is_named_as_such(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE ' . Schema::jobs_table() );
		Options::delete( Schema::OPTION );
		$hits = 0;
		$this->refuse( '/^CREATE TABLE \S*wpcheckpoint_jobs /i', $hits );
		$result = self::ensure();
		$this->assertGreaterThan( 0, $hits, 'the control: it tried to create it' );
		$this->assertSame( 'failed', $result['action'] );
		$this->assertSame( array( Schema::NO_TABLE ), $result['problems'] );
		$this->assertStringContainsString( 'could not be created', Schema::problem_message( $result['problems'] ) );
		$this->assertStringContainsString( 'CREATE privilege', Schema::problem_message( $result['problems'] ) );
		$this->assertSame( 0, Schema::stored()['version'] );
		remove_all_filters( 'query' );
		self::ten_minutes_later();
		$this->assertSame( 'created', Schema::ensure()['action'] );
	}

	public function test_a_refused_migration_writes_nothing_to_the_error_log_or_the_output(): void {
		global $wpdb;
		$log = get_temp_dir() . 'wpc-schema-' . wp_generate_password( 8, false ) . '.log';
		$was = ini_get( 'error_log' );
		ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- test only.
		$shown = $wpdb->show_errors( true );
		try {
			// The control: a refused statement outside the migration is written to the log.
			$wpdb->query( 'ALTER TABLE wpcheckpoint_refused_no_such_table ADD COLUMN x int' );
			$this->assertStringContainsString( 'WordPress database error', (string) @file_get_contents( $log ) );
			file_put_contents( $log, '' );

			self::drop_column( 'cron_deferrals' );
			Options::set( Schema::OPTION, array( 'version' => 6, 'min_compatible' => 1 ) );
			$hits = 0;
			$this->refuse( '/^ALTER TABLE \S+ ADD COLUMN `?cron_deferrals`? /i', $hits );
			ob_start();
			$result = Schema::ensure();
			$printed = (string) ob_get_clean();
			$this->assertGreaterThan( 0, $hits );
			$this->assertSame( 'failed', $result['action'] );
			$this->assertSame( '', $printed, 'nothing printed' );
			$this->assertSame( '', (string) file_get_contents( $log ), 'nothing logged: the problems are the report' );
		} finally {
			$wpdb->show_errors( $shown );
			ini_set( 'error_log', (string) $was ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- test only.
			@unlink( $log );
		}
	}

	public function test_an_outdated_restore_ledger_fails_the_job_as_final(): void {
		$this->register(
			'restoring',
			array(
				new ClosureStep(
					'import',
					static function (): StepResult {
						throw new LedgerOutdated( 'This restore was started by an older version of WP Checkpoint.' );
					}
				),
			)
		);
		$id = Plugin::instance()->jobs()->create( 'restoring' )->id;
		Plugin::instance()->job_actions()->tick( $id, microtime( true ) );
		$job = Plugin::instance()->jobs()->find( $id );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertSame( Job::FAILURE_FINAL, $job->failure_kind, 'a retry reads the same ledger: no Retry' );
		$this->assertFalse( $job->retry_useful() );
	}

	public function test_after_a_failed_upgrade_only_admin_cron_and_cli_try_again_and_not_within_ten_minutes(): void {
		$this->register( 'plain', array( $this->counting_step( 'p', 5 ) ) );
		$id = Plugin::instance()->jobs()->create( 'plain' )->id;
		self::drop_column( 'cron_deferrals' );
		Options::set( Schema::OPTION, array( 'version' => 6, 'min_compatible' => 1 ) );
		$changes = 0;
		$hits    = 0;
		$this->count_changes( $changes );
		$this->refuse( '/^ALTER TABLE \S+ ADD COLUMN `?cron_deferrals`? /i', $hits );

		// The control: a request that may upgrade (here detected as cron, as wp-cron.php is) sends the ALTER.
		Schema::set_upgrade_context( null );
		add_filter( 'wp_doing_cron', '__return_true' );
		$this->assertSame( 'failed', Schema::ensure()['action'] );
		$this->assertGreaterThan( 0, $changes, 'the control: the cron request tried' );
		remove_filter( 'wp_doing_cron', '__return_true' );

		// A request that is neither admin, cron nor WP-CLI (phpunit detected as such, like a REST request) only
		// reads the version: the job waits, with the last attempt's findings, and is not failed on them.
		$changes = 0;
		$pending = Schema::ensure( true );
		$this->assertSame( 'pending', $pending['action'] );
		$this->assertSame( array( 'cron_deferrals' ), $pending['last_problems'] );
		$tick = $this->rest( 'POST', 'jobs/' . $id . '/tick' );
		$this->assertSame( 200, $tick->get_status() );
		$this->assertSame( 'blocked', $tick->get_data()['result'] );
		$this->assertStringContainsString( 'the last attempt did not complete (cron_deferrals)', $tick->get_data()['message'] );
		$this->assertSame( 0, $changes, 'no ALTER from a request that may not upgrade' );
		$this->assertSame( Job::QUEUED, Plugin::instance()->jobs()->find( $id )->status );

		// Within the ten minutes, a request that may upgrade sends nothing either: it reads the table again, and
		// reports what it lacks now (another column went meanwhile), not what the record says.
		self::drop_column( 'takeover_mark' );
		$changes = 0;
		Schema::set_upgrade_context( true );
		$again = Schema::ensure();
		$this->assertSame( 'failed', $again['action'] );
		$this->assertSame( array( 'cron_deferrals', 'takeover_mark' ), $again['problems'], 'what the table lacks now' );
		$this->assertSame( 0, $changes, 'no ALTER within the wait' );
		// After them, it does, and waits again.
		self::ten_minutes_later();
		Schema::ensure();
		$this->assertGreaterThan( 0, $changes, 'tried again after the wait' );
		$this->assertGreaterThan( time() + Schema::RETRY_SECONDS - 60, Options::get( Schema::RETRY_OPTION )['after'], 'and waits again' );
	}
	public function test_a_due_upgrade_holds_jobs_until_cron_does_it(): void {
		global $wpdb;
		$id = $this->running_job();
		// A job queued for more than 24 hours: housekeeping would give it up.
		$stale = Plugin::instance()->jobs()->create( 'plain' )->id;
		$wpdb->update( Schema::jobs_table(), array( 'created_at' => time() - 3 * 86400, 'progress_at' => time() - 3 * 86400 ), array( 'id' => $stale ) );
		// An update of the plugin brought a new version; nothing has upgraded the table yet.
		self::drop_column( 'cron_deferrals' );
		Options::set( Schema::OPTION, array( 'version' => 6, 'min_compatible' => 1 ) );
		$changes = 0;
		$this->count_changes( $changes );

		Schema::set_upgrade_context( null ); // phpunit: neither admin, cron nor WP-CLI, like a REST request.
		$this->assertSame( 'pending', Schema::ensure()['action'] );
		delete_site_transient( 'wpcheckpoint_jobs_reaped' ); // The first tick set the throttle; housekeeping would run now.
		$result = Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT );
		$this->assertSame( 0, $changes, 'no ALTER' );
		$this->assertSame( 'blocked', $result->status );
		$this->assertStringContainsString( 'has to update its database table first', $result->message );
		$this->assertSame( Job::RUNNING, Plugin::instance()->jobs()->find( $id )->status, 'waiting, not failed: nothing has tried yet' );
		$this->assertSame( Job::QUEUED, Plugin::instance()->jobs()->find( $stale )->status, 'no housekeeping on a table that is behind' );
		$this->assertCount( 1, $this->events( $id ), 'a cron event follows it up' );
		try {
			Plugin::instance()->jobs()->create( 'plain' );
			$this->fail( 'a job was created before the upgrade' );
		} catch ( JobsUnavailable $e ) {
			$this->assertStringContainsString( 'has to update its database table first', $e->getMessage() );
		}

		// That cron request (detected as such) upgrades the table, and the job goes on.
		add_filter( 'wp_doing_cron', '__return_true' );
		$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );
		$this->assertFalse( get_site_transient( 'wpcheckpoint_jobs_reaped' ), 'the control: not throttled, so the skip above was the table' );
		Plugin::instance()->cron_tick( $id );
		remove_filter( 'wp_doing_cron', '__return_true' );
		$this->assertGreaterThan( 0, $changes, 'the cron request upgraded' );
		$this->assertSame( Schema::CURRENT, Schema::stored()['version'] );
		$this->assertSame( Job::COMPLETED, Plugin::instance()->jobs()->find( $id )->status, 'and the job went on (a full budget: to its end)' );
		$this->assertSame( Job::FAILED, Plugin::instance()->jobs()->find( $stale )->status, 'the control: housekeeping runs once the table is current' );
	}
	public function test_a_table_fixed_by_hand_is_not_failed_on_the_record_of_an_earlier_attempt(): void {
		global $wpdb;
		$id = $this->running_job();
		self::drop_column( 'cron_deferrals' );
		Options::set( Schema::OPTION, array( 'version' => 6, 'min_compatible' => 1 ) );
		$hits = 0;
		$this->refuse( '/^ALTER TABLE \S+ ADD COLUMN `?cron_deferrals`? /i', $hits );
		$this->assertSame( 'failed', Schema::ensure()['action'] );
		$this->assertGreaterThan( 0, $hits, 'the control: the attempt failed and is recorded' );
		remove_all_filters( 'query' );
		// The admin follows the message and adds the column by hand, well within the ten minutes.
		$wpdb->query( 'ALTER TABLE ' . Schema::jobs_table() . ' ADD COLUMN cron_deferrals int(10) unsigned NOT NULL DEFAULT 0' );

		// A REST tick: the record is not evidence; the job is not failed on it.
		Schema::set_upgrade_context( null );
		Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT );
		$this->assertSame( Job::RUNNING, Plugin::instance()->jobs()->find( $id )->status );
		// The plugin's page, within the wait: it reads the table, finds it complete and records the version.
		$this->assertSame( 'migrated', $this->as_admin( true )['action'] );
		$this->assertSame( Schema::CURRENT, Schema::stored()['version'] );
		$this->assertNull( Options::get( Schema::RETRY_OPTION, null ) );
		Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT );
		$this->assertSame( 2, (int) JobContext::strip_reserved( Plugin::instance()->jobs()->find( $id )->cursor )['n'] );
	}

	public function test_a_job_that_ended_or_waits_for_an_answer_is_not_blocked_by_a_due_upgrade(): void {
		$this->register(
			'asks',
			array(
				new ClosureStep(
					'q',
					static function ( JobContext $ctx ): StepResult {
						return StepResult::ask( array(), array( array( 'id' => 'x', 'kind' => 'x', 'choices' => array( 'go' ) ) ), 'decide' );
					}
				),
			)
		);
		$asks = Plugin::instance()->jobs()->create( 'asks' )->id;
		Plugin::instance()->job_actions()->tick( $asks, microtime( true ) );
		$this->assertTrue( Plugin::instance()->jobs()->find( $asks )->awaiting_answer() );
		$done = Plugin::instance()->jobs()->create( 'asks' );
		Plugin::instance()->jobs()->transition( $done, Job::CANCELLED );
		$this->register( 'plain', array( $this->counting_step( 'p', 5 ) ) );
		$plain = Plugin::instance()->jobs()->create( 'plain' );
		Options::set( Schema::OPTION, array( 'version' => 6, 'min_compatible' => 1 ) );
		Schema::set_upgrade_context( null );
		_set_cron_array( array() );

		$this->assertSame( 'paused', Plugin::instance()->job_actions()->tick( $asks, microtime( true ) )->status );
		$this->assertSame( array(), $this->events( $asks ), 'no cron event for a job that waits for an answer' );
		$this->assertSame( 'finished', Plugin::instance()->job_actions()->tick( $done->id, microtime( true ) )->status );
		// The control: a job that can run is blocked, with an event.
		$this->assertSame( 'blocked', Plugin::instance()->job_actions()->tick( $plain->id, microtime( true ) )->status );
		$this->assertCount( 1, $this->events( $plain->id ) );
	}

	public function test_a_late_cron_request_on_a_table_that_is_behind_counts_nothing(): void {
		$id = $this->running_job();
		self::drop_column( 'cron_deferrals' );
		Options::set( Schema::OPTION, array( 'version' => 6, 'min_compatible' => 1 ) );
		$counts = 0;
		add_filter(
			'query',
			static function ( $query ) use ( &$counts ) {
				if ( false !== strpos( (string) $query, 'cron_deferrals = cron_deferrals + 1' ) ) {
					++$counts;
				}
				return $query;
			}
		);
		$hits = 0;
		$this->refuse( '/^ALTER TABLE \S+ ADD COLUMN `?cron_deferrals`? /i', $hits );
		$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true ) - 10;
		Plugin::instance()->cron_tick( $id ); // Cron may upgrade; the upgrade is refused.
		$this->assertGreaterThan( 0, $hits );
		$this->assertSame( 0, $counts, 'no count written to a column that is not there' );
		$this->assertSame( Job::FAILED, Plugin::instance()->jobs()->find( $id )->status, 'the tick said why' );
		// The control: on a current table, a late cron request writes its count.
		remove_all_filters( 'query' );
		add_filter(
			'query',
			static function ( $query ) use ( &$counts ) {
				if ( false !== strpos( (string) $query, 'cron_deferrals = cron_deferrals + 1' ) ) {
					++$counts;
				}
				return $query;
			}
		);
		self::ten_minutes_later();
		$other = $this->running_job();
		$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true ) - 10;
		Plugin::instance()->cron_tick( $other );
		$this->assertSame( 1, $counts );
	}

	public function test_a_repair_that_failed_and_a_fix_by_hand_in_the_wait_clear_the_record(): void {
		global $wpdb;
		self::drop_column( 'cron_deferrals' ); // Lost after the version was recorded.
		$hits = 0;
		$this->refuse( '/^ALTER TABLE \S+ ADD COLUMN `?cron_deferrals`? /i', $hits );
		$this->assertSame( 'failed', $this->as_admin( true )['action'] );
		$this->assertNotNull( Options::get( Schema::RETRY_OPTION, null ), 'the control: the failed repair is recorded' );
		$changes = 0;
		$this->count_changes( $changes );
		$in_wait = $this->as_admin( true );
		$this->assertSame( 'failed', $in_wait['action'], 'in the wait, still missing' );
		$this->assertSame( array( 'cron_deferrals' ), $in_wait['problems'] );
		$this->assertSame( 0, $changes, 'and no repair sent in the wait' );
		remove_all_filters( 'query' );
		$wpdb->query( 'ALTER TABLE ' . Schema::jobs_table() . ' ADD COLUMN cron_deferrals int(10) unsigned NOT NULL DEFAULT 0' );
		$this->assertSame( 'none', $this->as_admin( true )['action'], 'read again in the wait: nothing missing' );
		$this->assertNull( Options::get( Schema::RETRY_OPTION, null ) );
	}

	public function test_a_wait_further_out_than_one_is_over(): void {
		self::drop_column( 'cron_deferrals' );
		Options::set( Schema::OPTION, array( 'version' => 6, 'min_compatible' => 1 ) );
		// A clock that jumped ahead when the attempt was recorded, or a damaged value.
		Options::set( Schema::RETRY_OPTION, array( 'after' => time() + 30 * 86400, 'problems' => array( 'cron_deferrals', array( 'not text' ) ) ) );
		$changes = 0;
		$this->count_changes( $changes );
		$this->assertSame( 'migrated', Schema::ensure()['action'] );
		$this->assertGreaterThan( 0, $changes, 'tried: the wait had run out' );
		// The control: a wait within one still holds.
		self::drop_column( 'cron_deferrals' );
		Options::set( Schema::OPTION, array( 'version' => 6, 'min_compatible' => 1 ) );
		Options::set( Schema::RETRY_OPTION, array( 'after' => time() + 300, 'problems' => array( 'cron_deferrals', array( 'not text' ) ) ) );
		$changes = 0;
		Schema::set_upgrade_context( null );
		$pending = Schema::ensure();
		$this->assertSame( array( 'cron_deferrals' ), $pending['last_problems'], 'only text is taken from the record' );
		Schema::set_upgrade_context( true );
		$this->assertSame( 'failed', Schema::ensure()['action'] );
		$this->assertSame( 0, $changes );
	}

	public function test_a_tick_whose_attempt_could_not_read_the_columns_holds_the_job(): void {
		$id = $this->running_job();
		self::drop_column( 'cron_deferrals' );
		Options::set( Schema::OPTION, array( 'version' => 6, 'min_compatible' => 1 ) );
		$hits = 0;
		$this->refuse( '/^SHOW COLUMNS FROM /i', $hits );
		$result = Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT );
		$this->assertGreaterThan( 0, $hits, 'the control: the attempt asked for the columns' );
		$this->assertSame( 'blocked', $result->status, 'no answer says nothing about the table: the job waits' );
		$this->assertStringContainsString( 'could not be read', $result->message );
		$this->assertSame( Job::RUNNING, Plugin::instance()->jobs()->find( $id )->status );
		$this->assertSame( 1, (int) JobContext::strip_reserved( Plugin::instance()->jobs()->find( $id )->cursor )['n'], 'nothing ran' );
	}

	public function test_a_request_that_dies_between_clearing_the_record_and_writing_the_version_migrates_again(): void {
		self::drop_column( 'cron_deferrals' );
		Options::set( Schema::OPTION, array( 'version' => 6, 'min_compatible' => 1 ) );
		Options::set( Schema::RETRY_OPTION, array( 'after' => time() - 1, 'problems' => array( 'cron_deferrals' ) ) );
		$die = static function () {
			throw new \RuntimeException( 'the request dies here' );
		};
		add_filter( 'pre_update_option_' . Schema::OPTION, $die );
		add_filter( 'pre_update_site_option_' . Schema::OPTION, $die );
		try {
			Schema::ensure();
			$this->fail( 'the version was written' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'the request dies here', $e->getMessage(), 'the control: it died at the version' );
		}
		remove_filter( 'pre_update_option_' . Schema::OPTION, $die );
		remove_filter( 'pre_update_site_option_' . Schema::OPTION, $die );
		$this->assertSame( 6, Schema::stored()['version'] );
		$this->assertNull( Options::get( Schema::RETRY_OPTION, null ), 'no record of a failure that did not happen' );
		$this->assertSame( 'migrated', Schema::ensure()['action'], 'the next attempt migrates again, at once' );
	}

	public function test_columns_that_cannot_be_read_in_the_wait_are_no_evidence(): void {
		self::drop_column( 'cron_deferrals' );
		Options::set( Schema::OPTION, array( 'version' => 6, 'min_compatible' => 1 ) );
		Options::set( Schema::RETRY_OPTION, array( 'after' => time() + 300, 'problems' => array( 'cron_deferrals' ) ) );
		$hits    = 0;
		$changes = 0;
		$this->count_changes( $changes );
		$this->refuse( '/^SHOW COLUMNS FROM /i', $hits );
		$result = Schema::ensure();
		$this->assertSame( 1, $hits, 'the control: the columns were asked for' );
		$this->assertSame( 'pending', $result['action'], 'behind, and nothing known now: wait' );
		$this->assertSame( 0, $changes );
		// With the version current, no answer lets the work go on, as it does outside the wait.
		Options::set( Schema::OPTION, array( 'version' => Schema::CURRENT, 'min_compatible' => 1 ) );
		$this->assertSame( 'none', Schema::ensure( true )['action'] );
	}

	public function test_a_retry_from_wp_cli_adds_a_lost_column_and_goes_through(): void {
		global $wpdb;
		$id = $this->running_job();
		self::drop_column( 'cron_deferrals' );
		Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT );
		$this->assertSame( Job::FAILED, Plugin::instance()->jobs()->find( $id )->status );
		Schema::set_upgrade_context( true ); // As WP-CLI is.
		$job = Plugin::instance()->job_actions()->retry( $id );
		$this->assertSame( Job::QUEUED, $job->status );
		$this->assertContains( 'cron_deferrals', $wpdb->get_col( 'SHOW COLUMNS FROM ' . Schema::jobs_table() ) );
	}

	public function test_a_late_cron_request_under_a_newer_schema_is_still_put_off(): void {
		$id = $this->running_job();
		// A downgraded plugin: the stored schema is newer and still usable (the table has every column).
		Options::set( Schema::OPTION, array( 'version' => Schema::CURRENT + 1, 'min_compatible' => 1 ) );
		$this->assertSame( 'newer', Schema::ensure()['action'] );
		$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true ) - 10;
		Plugin::instance()->cron_tick( $id );
		$job = Plugin::instance()->jobs()->find( $id );
		$this->assertSame( 1, $job->cron_deferrals, 'counted and put off' );
		$this->assertSame( 1, (int) JobContext::strip_reserved( $job->cursor )['n'], 'not ticked' );
	}

	public function test_the_plugin_page_does_no_housekeeping_on_a_table_that_is_behind(): void {
		global $wpdb;
		$this->register( 'plain', array( $this->counting_step( 'p', 5 ) ) );
		$stale = Plugin::instance()->jobs()->create( 'plain' )->id;
		$wpdb->update( Schema::jobs_table(), array( 'created_at' => time() - 3 * 86400, 'progress_at' => time() - 3 * 86400 ), array( 'id' => $stale ) );
		self::drop_column( 'cron_deferrals' );
		Options::set( Schema::OPTION, array( 'version' => 6, 'min_compatible' => 1 ) );
		$hits = 0;
		$this->refuse( '/^ALTER TABLE \S+ ADD COLUMN `?cron_deferrals`? /i', $hits );
		$page = Plugin::instance()->admin_page();
		$render = function () use ( $page ): void {
			delete_site_transient( 'wpcheckpoint_jobs_reaped' );
			set_current_screen( 'dashboard' );
			Schema::set_upgrade_context( null );
			ob_start();
			try {
				$page->render();
			} finally {
				ob_end_clean();
				set_current_screen( 'front' );
			}
		};
		$render();
		$this->assertGreaterThan( 0, $hits, 'the control: the page tried the upgrade' );
		$this->assertSame( Job::QUEUED, Plugin::instance()->jobs()->find( $stale )->status, 'no housekeeping while behind' );
		// The control: once the upgrade works, the page's housekeeping gives the job up.
		remove_all_filters( 'query' );
		self::ten_minutes_later();
		$render();
		$this->assertSame( Job::FAILED, Plugin::instance()->jobs()->find( $stale )->status );
	}

	/**
	 * Schema::ensure() in an admin request (the plugin's page), detected as such.
	 *
	 * @return array<string, mixed>
	 */
	private function as_admin( bool $verify ): array {
		Schema::set_upgrade_context( null );
		set_current_screen( 'dashboard' );
		try {
			$this->assertTrue( is_admin() );
			return Schema::ensure( $verify );
		} finally {
			set_current_screen( 'front' );
		}
	}

	/**
	 * Cron events of a job.
	 *
	 * @return int[]
	 */
	private function events( int $id ): array {
		$times = array();
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			foreach ( (array) ( $hooks[ \WPCheckpoint\Jobs\Loopback::HOOK ] ?? array() ) as $event ) {
				if ( array( $id ) === $event['args'] ) {
					$times[] = (int) $timestamp;
				}
			}
		}
		return $times;
	}
}
