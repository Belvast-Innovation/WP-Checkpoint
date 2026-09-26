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

	public function test_retry_and_answer_are_refused_with_the_reason_while_a_column_cannot_be_added_back(): void {
		global $wpdb;
		$id = $this->running_job();
		self::drop_column( 'cron_deferrals' );
		Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT );
		$this->assertSame( Job::FAILED, Plugin::instance()->jobs()->find( $id )->status );
		$hits = 0;
		$this->refuse( '/^ALTER TABLE \S+ ADD COLUMN `?cron_deferrals`? /i', $hits );

		$response = $this->rest( 'POST', 'jobs/' . $id . '/retry' );
		$this->assertGreaterThan( 0, $hits, 'the control: the retry tried to add the column back' );
		$this->assertSame( 503, $response->get_status() );
		$this->assertStringContainsString( '(cron_deferrals)', $response->as_error()->get_error_message() );
		$this->assertSame( Job::FAILED, Plugin::instance()->jobs()->find( $id )->status );
		try {
			Plugin::instance()->job_actions()->answer( $id, array( 'x' => 'go' ) );
			$this->fail( 'answered on a table that lacks a column' );
		} catch ( JobsUnavailable $e ) {
			$this->assertStringContainsString( '(cron_deferrals)', $e->getMessage() );
		}

		// Once the database lets it (and ten minutes later): the retry adds the column back and goes through,
		// with the version as it was.
		remove_all_filters( 'query' );
		self::ten_minutes_later();
		$this->assertSame( 200, $this->rest( 'POST', 'jobs/' . $id . '/retry' )->get_status() );
		$this->assertSame( Job::QUEUED, Plugin::instance()->jobs()->find( $id )->status );
		$this->assertContains( 'cron_deferrals', $wpdb->get_col( 'SHOW COLUMNS FROM ' . Schema::jobs_table() ) );
		$this->assertSame( Schema::CURRENT, Schema::stored()['version'] );
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

		// A request that is neither admin, cron nor WP-CLI (phpunit detected as such, like a REST request) only reads.
		$changes = 0;
		$this->assertSame( 'failed', Schema::ensure()['action'], 'the last attempt\'s problems' );
		$this->assertSame( array( 'cron_deferrals' ), Schema::ensure( true )['problems'] );
		$tick = $this->rest( 'POST', 'jobs/' . $id . '/tick' );
		$this->assertSame( 200, $tick->get_status() );
		$this->assertSame( 0, $changes, 'no ALTER from a request that may not upgrade' );
		$this->assertSame( Job::FAILED, Plugin::instance()->jobs()->find( $id )->status, 'the tick failed the job with the reason' );

		// Within the ten minutes, a request that may upgrade does not try either.
		Schema::set_upgrade_context( true );
		$this->assertSame( 'failed', Schema::ensure()['action'] );
		$this->assertSame( 0, $changes, 'no ALTER within the wait' );
		// After them, it does.
		self::ten_minutes_later();
		Schema::ensure();
		$this->assertGreaterThan( 0, $changes, 'tried again after the wait' );
		$this->assertLessThanOrEqual( time() + Schema::RETRY_SECONDS, Options::get( Schema::RETRY_OPTION )['after'] );
		$this->assertGreaterThan( time() + Schema::RETRY_SECONDS - 60, Options::get( Schema::RETRY_OPTION )['after'], 'and waits again' );
	}

	public function test_a_due_upgrade_holds_jobs_until_a_request_that_may_upgrade_does_it(): void {
		$id = $this->running_job();
		// An update of the plugin brought a new version; nothing has upgraded the table yet.
		self::drop_column( 'cron_deferrals' );
		Options::set( Schema::OPTION, array( 'version' => 6, 'min_compatible' => 1 ) );
		$changes = 0;
		$this->count_changes( $changes );

		Schema::set_upgrade_context( null ); // phpunit: neither admin, cron nor WP-CLI.
		$this->assertSame( 'pending', Schema::ensure()['action'] );
		$result = Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT );
		$this->assertSame( 0, $changes, 'no ALTER' );
		$this->assertSame( 'blocked', $result->status );
		$this->assertStringContainsString( 'has to update its database table first', $result->message );
		$this->assertSame( Job::RUNNING, Plugin::instance()->jobs()->find( $id )->status, 'waiting, not failed: nothing has tried yet' );
		try {
			Plugin::instance()->jobs()->create( 'plain' );
			$this->fail( 'a job was created before the upgrade' );
		} catch ( JobsUnavailable $e ) {
			$this->assertStringContainsString( 'has to update its database table first', $e->getMessage() );
		}

		// The admin (the plugin's page) upgrades; then the job goes on.
		set_current_screen( 'dashboard' );
		try {
			$this->assertTrue( is_admin() );
			$this->assertSame( 'migrated', Schema::ensure( true )['action'] );
		} finally {
			set_current_screen( 'front' );
		}
		$this->assertGreaterThan( 0, $changes );
		Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT );
		$this->assertSame( 2, (int) JobContext::strip_reserved( Plugin::instance()->jobs()->find( $id )->cursor )['n'] );
	}
}
