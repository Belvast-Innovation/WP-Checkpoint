<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobActions;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\Loopback;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Tests\Fixtures\Jobs\ClosureStep;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * A cron event whose callback starts late in its request (wp-cron.php ran
 * other events first) puts the tick off to the next cron request, at most
 * JobActions::MAX_CRON_DEFERRALS times in a row; the next late one ticks
 * with no time left, so it runs the first unit only, when it has time
 * left for one, and at JobActions::CRON_DEFERRAL_LIMIT the job fails. The
 * count is of cron requests in a row that did not hand the job to the
 * Runner: any driver that does sets it back. Unless a test says why, the
 * jobs here have run one tick, as the drivers usually find them.
 */
final class LateCronTest extends JobTestCase {

	const LATE = 10;

	public function set_up(): void {
		parent::set_up();
		// No time limit unless a test sets one: the tests of the first three deferrals do not depend on the
		// limit of the process that runs them.
		$this->time_limit( 0 );
	}

	/**
	 * A job of the fixture type 'plain' (five units) that has run one tick, unless $ran is false.
	 */
	private function plain( bool $ran = true ): int {
		$this->register( 'plain', array( $this->counting_step( 'p', 5 ) ) );
		return $this->job_of( 'plain', $ran );
	}

	/**
	 * How many ticks the job's log shows.
	 */
	private function ticks( Job $job ): int {
		return count( $this->log_lines( $job, 'Tick started' ) );
	}

	/**
	 * One cron request that starts on time.
	 */
	private function on_time_cron( int $id ): void {
		$this->late_cron( $id, 0.0 );
	}

	/**
	 * One cron request that runs the job's event 10 s after the request started. Like WP-Cron, the event is
	 * removed before its callback runs, so an event afterwards is one the callback set.
	 */
	private function late_cron( int $id, float $late = self::LATE ): void {
		foreach ( $this->events( $id ) as $time ) {
			wp_unschedule_event( $time, Loopback::HOOK, array( $id ) );
		}
		$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true ) - $late;
		Plugin::instance()->cron_tick( $id );
	}

	/**
	 * The cron requests' time limit as PHP reports it (max_execution_time; 0: none).
	 */
	private function time_limit( int $seconds ): void {
		$this->replace_internal(
			Plugin::instance()->job_actions(),
			'runtime',
			static function () use ( $seconds ): array {
				return array(
					'memory_bytes'       => 268435456,
					'max_execution_time' => $seconds,
				);
			}
		);
	}

	/**
	 * A 'query' filter that hands the deferral count's UPDATE to $change (and counts it).
	 */
	private function on_count( callable $change, int &$hits ): void {
		add_filter(
			'query',
			static function ( $query ) use ( $change, &$hits ) {
				if ( false !== strpos( (string) $query, 'cron_deferrals = cron_deferrals + 1' ) ) {
					++$hits;
					return $change( $query );
				}
				return $query;
			}
		);
	}

	private function job( int $id ): Job {
		return Plugin::instance()->jobs()->find( $id );
	}

	private static function units( Job $job ): int {
		return (int) ( JobContext::strip_reserved( $job->cursor )['n'] ?? 0 );
	}

	/**
	 * Cron events of a job.
	 *
	 * @return int[]
	 */
	private function events( int $id ): array {
		$times = array();
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			foreach ( (array) ( $hooks[ Loopback::HOOK ] ?? array() ) as $event ) {
				if ( array( $id ) === $event['args'] ) {
					$times[] = (int) $timestamp;
				}
			}
		}
		return $times;
	}

	/**
	 * The job's log lines that contain $text.
	 *
	 * @return string[]
	 */
	private function log_lines( Job $job, string $text ): array {
		$file = $job->storage_path . '/' . $job->log_path;
		$this->assertFileExists( $file );
		return array_values(
			array_filter(
				explode( "\n", (string) file_get_contents( $file ) ),
				static function ( string $line ) use ( $text ): bool {
					return false !== strpos( $line, $text );
				}
			)
		);
	}

	public function test_a_job_whose_cron_requests_all_start_late_moves_on_every_fourth_one(): void {
		$id = $this->plain();
		$this->assertSame( 1, $this->ticks( $this->job( $id ) ), 'the control: a tick is seen in the log' );

		for ( $i = 1; $i <= JobActions::MAX_CRON_DEFERRALS; $i++ ) {
			$this->late_cron( $id );
			$job = $this->job( $id );
			$this->assertSame( 1, $this->ticks( $job ), "request {$i}: not ticked" );
			$this->assertSame( $i, $job->cron_deferrals, "request {$i}: counted" );
			$this->assertCount( 1, $this->events( $id ), "request {$i}: set again for the next cron request" );
			$this->assertLessThanOrEqual( time() + 1, $this->events( $id )[0] );
		}

		$this->late_cron( $id );
		$job = $this->job( $id );
		$this->assertSame( 2, $this->ticks( $job ), 'the fourth late request ticks' );
		// With the time the request had left (the budget less 10 s) the tick would have run all the units.
		$this->assertSame( 2, self::units( $job ), 'the first unit only' );
		$this->assertSame( Job::RUNNING, $job->status );
		$this->assertSame( 0, $job->cron_deferrals, 'reaching the Runner starts the count over' );
		$this->assertCount( 1, $this->events( $id ), 'and it is followed up' );
		$this->assertGreaterThan( time() + Loopback::FALLBACK_SECONDS / 2, $this->events( $id )[0], 'as any tick that left work: not by the event set for a deferral' );

		$this->late_cron( $id );
		$this->assertSame( 2, self::units( $this->job( $id ) ), 'the next late request puts it off again' );
		$this->assertSame( 1, $this->job( $id )->cron_deferrals );

		// The control: a request that starts on time ticks with its full budget and runs the rest.
		$this->on_time_cron( $id );
		$this->assertSame( Job::COMPLETED, $this->job( $id )->status );
	}

	public function test_a_tick_by_another_driver_in_between_starts_the_count_over(): void {
		$id = $this->plain();
		$this->late_cron( $id );
		$this->late_cron( $id );
		$this->assertSame( 2, $this->job( $id )->cron_deferrals );

		// Another driver (a page open on the job) hands it to the Runner.
		Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT );
		$this->assertSame( 2, $this->ticks( $this->job( $id ) ) );
		$this->assertSame( 0, $this->job( $id )->cron_deferrals, 'reaching the Runner starts the count over' );

		// So the next late request is the first of three again, not the one that ticks.
		$this->late_cron( $id );
		$this->assertSame( 2, $this->ticks( $this->job( $id ) ), 'put off' );
		$this->assertSame( 1, $this->job( $id )->cron_deferrals );
	}

	/**
	 * A job whose step only waits (as behind another job).
	 */
	private function waiting(): int {
		$this->register(
			'waits',
			array(
				new ClosureStep(
					'w',
					static function ( JobContext $ctx ): StepResult {
						return StepResult::wait( 60, $ctx->cursor(), 'waiting' );
					}
				),
			)
		);
		return $this->job_of( 'waits' );
	}

	public function test_a_forced_tick_starts_the_count_over_even_without_progress(): void {
		$id = $this->waiting();
		for ( $i = 0; $i < JobActions::MAX_CRON_DEFERRALS; $i++ ) {
			$this->late_cron( $id );
		}
		$this->assertSame( JobActions::MAX_CRON_DEFERRALS, $this->job( $id )->cron_deferrals, 'the control: counted' );
		$this->late_cron( $id );
		$this->assertSame( 2, $this->ticks( $this->job( $id ) ), 'the fourth ran the tick' );
		$this->assertSame( 0, $this->job( $id )->cron_deferrals, 'it reached the Runner: the count starts over, although the job only waits' );
		$this->late_cron( $id );
		$this->assertSame( 2, $this->ticks( $this->job( $id ) ), 'the next late request is put off again' );
		$this->assertSame( 1, $this->job( $id )->cron_deferrals );
	}

	public function test_a_job_that_waits_or_is_refused_is_never_failed_while_on_time_cron_requests_reach_it(): void {
		global $wpdb;
		$this->time_limit( 30 );
		$waits   = $this->waiting();
		$refused = $this->plain();
		// Refused by the storage gate from now on (its directory changed).
		$wpdb->update( \WPCheckpoint\Support\Schema::jobs_table(), array( 'storage_token' => 'another' ), array( 'id' => $refused ) );
		foreach ( array( 'waiting' => $waits, 'refused' => $refused ) as $what => $id ) {
			// Twenty requests: three late ones (too little time left), then one on time, over and over.
			for ( $round = 1; $round <= 5; $round++ ) {
				for ( $i = 0; $i < JobActions::MAX_CRON_DEFERRALS; $i++ ) {
					$this->late_cron( $id, 26.5 );
				}
				$this->assertSame( JobActions::MAX_CRON_DEFERRALS, $this->job( $id )->cron_deferrals, "{$what}, round {$round}: counted" );
				$this->on_time_cron( $id );
				$this->assertSame( 0, $this->job( $id )->cron_deferrals, "{$what}, round {$round}: an on-time request reached the Runner" );
			}
			$this->assertNotSame( Job::FAILED, $this->job( $id )->status, "{$what}: 15 late requests, never ten in a row" );
			// The control: ten late ones in a row fail it.
			for ( $i = 0; $i < JobActions::CRON_DEFERRAL_LIMIT; $i++ ) {
				$this->late_cron( $id, 26.5 );
			}
			$this->assertSame( Job::FAILED, $this->job( $id )->status, "{$what}: the control" );
			$this->assertStringContainsString( 'start too late to run this job', $this->job( $id )->last_error, "{$what}: for this reason" );
		}
	}

	public function test_every_deferral_and_the_tick_after_them_are_logged_with_the_reason(): void {
		$id = $this->plain();
		for ( $i = 0; $i <= JobActions::MAX_CRON_DEFERRALS; $i++ ) {
			$this->late_cron( $id );
		}
		$job = $this->job( $id );

		$put_off = $this->log_lines( $job, 'Tick put off to the next cron request: this one started too late' );
		$this->assertCount( JobActions::MAX_CRON_DEFERRALS, $put_off );
		foreach ( $put_off as $n => $line ) {
			$this->assertStringStartsWith( '[', $line );
			$this->assertStringContainsString( ' INFO ', $line );
			$this->assertStringContainsString( '"deferrals":' . ( $n + 1 ) . ',', $line );
			$this->assertStringContainsString( '"limit":' . JobActions::MAX_CRON_DEFERRALS, $line );
			$this->assertMatchesRegularExpression( '/"late_seconds":1\d(\.\d)?[,}]/', $line );
		}
		$ran = $this->log_lines( $job, 'Cron requests start too slowly' );
		$this->assertCount( 1, $ran );
		$this->assertStringContainsString( ' WARNING Cron requests start too slowly: 3 cron requests in a row started too late to run the job, so this one runs the first unit only', $ran[0] );
		$this->assertMatchesRegularExpression( '/"late_seconds":1\d(\.\d)?[,}]/', $ran[0] );
		$this->assertSame( 2, $this->ticks( $job ), 'then the tick itself' );
		// Not in the storage log as well.
		$storage = Plugin::instance()->directories()->base() . '/logs/storage.log';
		$this->assertStringNotContainsString( 'Tick put off', is_file( $storage ) ? (string) file_get_contents( $storage ) : '', 'in the job log, not the storage log' );
		// The control: written there, the line is found there.
		global $wpdb;
		$wpdb->update( \WPCheckpoint\Support\Schema::jobs_table(), array( 'storage_path' => Plugin::instance()->directories()->base() . '-elsewhere' ), array( 'id' => $id ) );
		$this->late_cron( $id );
		$this->assertStringContainsString( 'Job ' . $id . ': Tick put off', (string) file_get_contents( $storage ) );
	}

	public function test_a_job_whose_files_are_in_another_directory_is_logged_in_the_storage_log(): void {
		global $wpdb;
		// Never run: a run would have written its log here already, and the test looks for none.
		$id    = $this->plain( false );
		$job   = $this->job( $id );
		$other = Plugin::instance()->directories()->base() . '-elsewhere';
		$wpdb->update( \WPCheckpoint\Support\Schema::jobs_table(), array( 'storage_path' => $other ), array( 'id' => $id ) );
		$this->late_cron( $id );
		$this->assertSame( 1, $this->job( $id )->cron_deferrals );
		$this->assertDirectoryDoesNotExist( $other, 'nothing written where the job says its files are' );
		$log = (string) file_get_contents( Plugin::instance()->directories()->base() . '/logs/storage.log' );
		$this->assertStringContainsString( 'Job ' . $id . ': Tick put off to the next cron request: this one started too late', $log );
		$this->assertFileDoesNotExist( Plugin::instance()->directories()->base() . '/' . $job->log_path, 'nor in the job log here' );
		// The control: a job whose files are here gets its line in its log here.
		$here = $this->job( $this->plain( false ) );
		$this->late_cron( $here->id );
		$this->assertFileExists( Plugin::instance()->directories()->base() . '/' . $here->log_path );
	}

	public function test_a_late_request_for_a_job_that_runs_nothing_is_not_counted(): void {
		$this->register( 'plain', array( $this->counting_step( 'p', 1 ) ) );
		$id = $this->job_of( 'plain', false ); // One unit: its first tick ends it.
		$this->on_time_cron( $id );
		$this->assertSame( Job::COMPLETED, $this->job( $id )->status );
		$this->late_cron( $id );
		$this->assertSame( 0, $this->job( $id )->cron_deferrals, 'a finished job' );
		$this->assertSame( array(), $this->events( $id ), 'and its event is settled as before' );
	}

	public function test_cron_requests_that_all_start_late_finish_the_job(): void {
		$id = $this->plain();
		for ( $requests = 1; $requests <= 100; $requests++ ) {
			$this->late_cron( $id );
			if ( Job::COMPLETED === $this->job( $id )->status ) {
				break;
			}
			$this->assertCount( 1, $this->events( $id ), "request {$requests}: a next cron request is due" );
		}
		$this->assertSame( Job::COMPLETED, $this->job( $id )->status );
		$this->assertSame( 4 * ( JobActions::MAX_CRON_DEFERRALS + 1 ), $requests, 'each of the four units left after three deferrals' );
	}

	public function test_a_job_waiting_for_an_answer_is_not_counted_and_the_answer_starts_the_count_over(): void {
		$this->register(
			'asks',
			array(
				new ClosureStep(
					'q',
					static function ( JobContext $ctx ): StepResult {
						return empty( $ctx->options()['answers']['x'] ) ? StepResult::ask( array(), array( array( 'id' => 'x', 'kind' => 'x', 'choices' => array( 'go' ) ) ), 'decide' ) : StepResult::done();
					}
				),
			)
		);
		// Never run: its first tick asks, and the deferrals must come before the question.
		$id    = $this->job_of( 'asks', false );
		$tries = 0;
		$this->on_count(
			static function ( string $query ): string {
				return $query;
			},
			$tries
		);
		$this->late_cron( $id );
		$this->late_cron( $id );
		$this->assertSame( 2, $this->job( $id )->cron_deferrals );
		$this->assertSame( 2, $tries, 'the control: each late request tried to count' );
		$this->assertCount( 2, $this->log_lines( $this->job( $id ), 'Tick put off' ), 'the control: deferrals are seen in the log' );
		Plugin::instance()->job_actions()->tick( $id, microtime( true ) );
		$this->assertTrue( $this->job( $id )->awaiting_answer() );

		$this->late_cron( $id );
		$this->assertSame( 2, $tries, 'waiting for an answer: not even tried' );
		$this->assertCount( 2, $this->log_lines( $this->job( $id ), 'Tick put off' ), 'nor logged' );
		$this->assertCount( 0, $this->log_lines( $this->job( $id ), 'could not be recorded' ) );
		$this->assertSame( array(), $this->events( $id ), 'nor set again' );

		global $wpdb;
		$wpdb->update( \WPCheckpoint\Support\Schema::jobs_table(), array( 'cron_deferrals' => 2 ), array( 'id' => $id ) ); // A count to start over.
		Plugin::instance()->job_actions()->answer( $id, array( 'x' => 'go' ) );
		$this->assertSame( 0, $this->job( $id )->cron_deferrals, 'the answer starts the count over' );
	}

	public function test_a_retry_starts_the_count_over(): void {
		$this->register(
			'flaky',
			array(
				new ClosureStep(
					'f',
					static function (): StepResult {
						throw new \RuntimeException( 'broken' );
					}
				),
			)
		);
		global $wpdb;
		$id = $this->job_of( 'flaky', false ); // Never run: its first tick fails it.
		Plugin::instance()->job_actions()->tick( $id, microtime( true ) );
		$this->assertSame( Job::FAILED, $this->job( $id )->status );
		// A count left over (from late requests before the failure).
		$wpdb->update( \WPCheckpoint\Support\Schema::jobs_table(), array( 'cron_deferrals' => 2 ), array( 'id' => $id ) );
		$this->assertSame( 2, $this->job( $id )->cron_deferrals, 'the control: the count is there' );
		Plugin::instance()->job_actions()->retry( $id );
		$this->assertSame( Job::QUEUED, $this->job( $id )->status );
		$this->assertSame( 0, $this->job( $id )->cron_deferrals, 'the retry starts it over' );
	}

	public function test_a_deferral_that_cannot_be_recorded_runs_the_first_unit_and_says_why(): void {
		$id   = $this->plain();
		$hits = 0;
		$this->on_count(
			static function ( string $query ): string {
				// Something printed on the way, as display_errors would print a notice (wpdb prints its own error
				// only on a single site, so it cannot be the control here): nothing of it reaches the client.
				echo 'Notice: printed while counting';
				return str_replace( 'cron_deferrals = cron_deferrals + 1', 'no_such_column = 1', $query );
			},
			$hits
		);
		$this->expectOutputString( '' );
		$this->late_cron( $id );
		$this->assertSame( 1, $hits, 'the control: the count was tried' );
		$this->assertGreaterThan( 0, JobActions::last_output_bytes(), 'the control: the notice was printed, and captured' );
		$this->assertSame( 2, self::units( $this->job( $id ) ), 'the first unit only' );
		$this->assertCount( 1, $this->log_lines( $this->job( $id ), 'started too late, and the deferral could not be recorded: it runs the first unit only' ) );
	}

	public function test_a_request_that_dies_between_setting_the_event_and_counting_leaves_the_event(): void {
		$id   = $this->plain();
		$hits = 0;
		$this->on_count(
			static function (): string {
				throw new \RuntimeException( 'the request dies here' );
			},
			$hits
		);
		$this->late_cron( $id );
		$this->assertSame( 1, $hits, 'the control: it died at the count' );
		$job = $this->job( $id );
		$this->assertSame( 0, $job->cron_deferrals, 'not counted' );
		$this->assertSame( 1, $this->ticks( $job ), 'not ticked' );
		$this->assertCount( 1, $this->events( $id ), 'but the next cron request is due' );
		$this->assertLessThanOrEqual( time() + 1, $this->events( $id )[0] );
	}

	public function test_a_late_request_first_brings_the_table_up_to_date(): void {
		global $wpdb;
		$id    = $this->plain();
		$table = \WPCheckpoint\Support\Schema::jobs_table();
		// The plugin was updated in the background: the table is still version 6 when the first cron request comes.
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN cron_deferrals" );
		\WPCheckpoint\Support\Options::set( \WPCheckpoint\Support\Schema::OPTION, array( 'version' => 6, 'min_compatible' => 1 ) );
		$this->late_cron( $id );
		$this->assertContains( 'cron_deferrals', $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) );
		$this->assertSame( 1, $this->job( $id )->cron_deferrals, 'counted: the first of three' );
		$this->assertSame( 1, $this->ticks( $this->job( $id ) ), 'not ticked' );
	}

	public function test_a_request_with_too_little_time_left_for_a_unit_puts_it_off_until_the_job_fails_with_the_reason(): void {
		$id = $this->plain();
		$this->time_limit( 30 );
		// Every cron request starts 26 s into a 30 s limit: about 4 s left, less than one unit.
		for ( $i = 1; $i < JobActions::CRON_DEFERRAL_LIMIT; $i++ ) {
			$this->late_cron( $id, 26 );
			$job = $this->job( $id );
			$this->assertSame( 1, $this->ticks( $job ), "request {$i}: not ticked" );
			$this->assertSame( $i, $job->cron_deferrals, "request {$i}: counted, the first three included" );
			$this->assertCount( 1, $this->events( $id ), "request {$i}: the next cron request is due" );
		}
		$lines = $this->log_lines( $this->job( $id ), 'less time left of its time limit than one unit needs' );
		$this->assertCount( JobActions::CRON_DEFERRAL_LIMIT - 1 - JobActions::MAX_CRON_DEFERRALS, $lines );
		$this->assertMatchesRegularExpression( '/"left_seconds":[34](\.\d)?[,}]/', $lines[0] );
		$this->assertFileExists( $this->job( $id )->storage_path . '/tmp/job-' . $id . '.lock', 'the control: the running job holds its lock file' );

		$this->late_cron( $id, 26 );
		$job = $this->job( $id );
		$this->assertSame( Job::FAILED, $job->status, 'the tenth fails the job, a running one too' );
		$this->assertSame( 1, $this->ticks( $job ), 'nothing ran' );
		$this->assertStringContainsString( '10 in a row started too late to run it (and nothing else ran it in between); the last 7 had less than 5 seconds of the request', $job->last_error );
		$this->assertStringContainsString( 'counted in real time', $job->last_error );
		$this->assertStringContainsString( 'wp cron event run --due-now', $job->last_error );
		$this->assertSame( Job::FAILURE_TEMPORARY, $job->failure_kind, 'retry is offered (once jobs are driven otherwise)' );
		$this->assertSame( '', $job->lock_token );
		$this->assertFileDoesNotExist( $job->storage_path . '/tmp/job-' . $id . '.lock', 'the lock file goes with it' );
		$this->assertSame( array(), $this->events( $id ) );
		$this->assertCount( 1, $this->log_lines( $job, 'ERROR Job failed' ) );
	}

	public function test_the_floor_is_one_unit_of_time_left(): void {
		$this->time_limit( 30 );
		// About 4.2 s left: put off.
		$short = $this->plain();
		for ( $i = 0; $i <= JobActions::MAX_CRON_DEFERRALS; $i++ ) {
			$this->late_cron( $short, 25.8 );
		}
		$this->assertSame( 1, $this->ticks( $this->job( $short ) ), 'put off' );
		$this->assertSame( JobActions::MAX_CRON_DEFERRALS + 1, $this->job( $short )->cron_deferrals );
		// About 7.5 s left: the first unit runs, as before.
		$room = $this->plain();
		for ( $i = 0; $i <= JobActions::MAX_CRON_DEFERRALS; $i++ ) {
			$this->late_cron( $room, 22.5 );
		}
		$this->assertSame( 2, self::units( $this->job( $room ) ), 'the first unit ran' );
		$this->assertSame( 0, $this->job( $room )->cron_deferrals );
	}

	public function test_without_a_time_limit_the_first_unit_runs_as_before(): void {
		$id = $this->plain();
		$this->time_limit( 0 ); // None set (or one outside PHP, which PHP cannot see).
		for ( $i = 0; $i < JobActions::MAX_CRON_DEFERRALS; $i++ ) {
			$this->late_cron( $id, 27 );
		}
		$this->late_cron( $id, 27 );
		$this->assertSame( 2, self::units( $this->job( $id ) ), 'the first unit ran' );
	}

	public function test_a_tick_in_between_starts_the_count_toward_the_limit_over(): void {
		$id = $this->plain();
		$this->time_limit( 30 );
		for ( $i = 0; $i < JobActions::CRON_DEFERRAL_LIMIT - 1; $i++ ) {
			$this->late_cron( $id, 27 );
		}
		$this->assertSame( JobActions::CRON_DEFERRAL_LIMIT - 1, $this->job( $id )->cron_deferrals );
		Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT ); // A page open on it.
		$this->assertSame( 0, $this->job( $id )->cron_deferrals );
		$this->late_cron( $id, 27 );
		$this->assertSame( 1, $this->job( $id )->cron_deferrals, 'the first of ten again, not the tenth' );
		$this->assertSame( Job::RUNNING, $this->job( $id )->status );
	}

	public function test_a_job_a_live_run_holds_is_not_failed_at_the_limit(): void {
		global $wpdb;
		$id = $this->plain();
		$this->time_limit( 30 );
		for ( $i = 0; $i < JobActions::CRON_DEFERRAL_LIMIT - 1; $i++ ) {
			$this->late_cron( $id, 26.5 );
		}
		$wpdb->update( \WPCheckpoint\Support\Schema::jobs_table(), array( 'lock_token' => 'live', 'locked_until' => time() + 600 ), array( 'id' => $id ) );
		$this->late_cron( $id, 26.5 );
		$this->assertSame( JobActions::CRON_DEFERRAL_LIMIT - 1, $this->job( $id )->cron_deferrals, 'not counted while a live run holds it' );
		$this->assertSame( Job::RUNNING, $this->job( $id )->status, 'a live run drives it' );
		$this->assertCount( 1, $this->log_lines( $this->job( $id ), 'a live run holds the job, so this late request is not counted' ) );
		// The control: without the live lease, the next such request fails it.
		$wpdb->update( \WPCheckpoint\Support\Schema::jobs_table(), array( 'lock_token' => '', 'locked_until' => 0 ), array( 'id' => $id ) );
		$this->late_cron( $id, 26.5 );
		$this->assertSame( Job::FAILED, $this->job( $id )->status );
	}

	public function test_a_deferral_past_three_that_cannot_be_recorded_is_logged_and_fails_nothing(): void {
		$id = $this->plain();
		$this->time_limit( 30 );
		for ( $i = 0; $i < JobActions::MAX_CRON_DEFERRALS; $i++ ) {
			$this->late_cron( $id, 26.5 );
		}
		$hits = 0;
		$this->on_count(
			static function ( string $query ): string {
				return false === strpos( $query, 'cron_deferrals < ' . JobActions::CRON_DEFERRAL_LIMIT ) ? $query : str_replace( 'cron_deferrals = cron_deferrals + 1', 'no_such_column = 1', $query );
			},
			$hits
		);
		global $wpdb;
		$quiet = $wpdb->suppress_errors( true );
		try {
			$this->late_cron( $id, 26.5 );
		} finally {
			$wpdb->suppress_errors( $quiet );
		}
		$this->assertSame( 2, $hits, 'the control: both counts were tried (up to three, then up to ten)' );
		$this->assertSame( JobActions::MAX_CRON_DEFERRALS, $this->job( $id )->cron_deferrals );
		$this->assertSame( 1, $this->ticks( $this->job( $id ) ), 'not ticked' );
		$this->assertCount( 1, $this->log_lines( $this->job( $id ), 'and the deferral could not be recorded' ) );
	}

	public function test_a_failure_at_the_limit_that_cannot_be_written_is_logged_and_waits(): void {
		$id = $this->plain();
		$this->time_limit( 30 );
		for ( $i = 1; $i < JobActions::CRON_DEFERRAL_LIMIT; $i++ ) {
			$this->late_cron( $id, 26 );
		}
		$hits = 0;
		add_filter(
			'query',
			static function ( $query ) use ( &$hits ) {
				if ( false !== strpos( (string) $query, 'AND cron_deferrals >= ' . JobActions::CRON_DEFERRAL_LIMIT ) ) {
					++$hits;
					return 'UPDATE wpcheckpoint_no_such_table SET x = 1';
				}
				return $query;
			}
		);
		global $wpdb;
		$quiet = $wpdb->suppress_errors( true );
		try {
			$this->late_cron( $id, 26 );
		} finally {
			$wpdb->suppress_errors( $quiet );
		}
		$this->assertSame( 1, $hits, 'the control: the failure was tried' );
		$this->assertSame( Job::RUNNING, $this->job( $id )->status );
		$this->assertCount( 1, $this->log_lines( $this->job( $id ), 'the failure could not be written' ) );
		$this->assertCount( 0, $this->log_lines( $this->job( $id ), 'a live run holds it, or it changed meanwhile' ), 'not reported as a refusal' );
	}
}
