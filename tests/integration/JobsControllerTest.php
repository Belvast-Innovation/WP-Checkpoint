<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\LockFile;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Jobs\ClosureStep;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

final class JobsControllerTest extends JobTestCase {

	public function test_tick_drives_a_job_and_responses_carry_no_paths_or_cursor(): void {
		$this->register( 'export', array( $this->counting_step( 'files', 3, static function ( int $n, JobContext $ctx ): void {
			$ctx->logger()->info( 'wrote ' . ABSPATH . 'wp-content/uploads/' . $n . '.zip for ' . home_url() . ' with ' . DB_PASSWORD );
		} ) ) );
		$job = Plugin::instance()->jobs()->create( 'export', self::$admin_id, array( 'table' => 'wp_posts' ) );

		$response = $this->rest( 'POST', 'jobs/' . $job->id . '/tick' );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'completed', $data['result'], 'a large budget finishes the three units in one tick' );
		$this->assertSame( -1, $data['retry_after'] );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertSame( Job::COMPLETED, $data['job']['status'] );
		$this->assertSame( 100, $data['job']['progress'] );
		$this->assertArrayNotHasKey( 'storage_path', $data['job'] );
		$this->assertArrayNotHasKey( 'cursor', $data['job'] );
		$this->assertArrayNotHasKey( 'log_path', $data['job'] );
		$json = (string) wp_json_encode( $data );
		$this->assertStringNotContainsString( rtrim( ABSPATH, '/' ), $json, 'no server path' );
		$this->assertStringNotContainsString( DB_PASSWORD, $json );
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$this->assertStringNotContainsString( $host, $json, 'no site host' );
		$this->assertStringContainsString( '{wp-content}/uploads/1.zip', $data['job']['log_tail'], 'the relative part of a path is kept' );
		$this->assertStringContainsString( '{site-host}', $data['job']['log_tail'] );
		$this->assertStringContainsString( 'Job completed', $data['job']['log_tail'] );

		$response = $this->rest( 'GET', 'jobs/' . $job->id );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Job::COMPLETED, $response->get_data()['job']['status'] );
		$this->assertSame( 'finished', $this->rest( 'POST', 'jobs/' . $job->id . '/tick' )->get_data()['result'] );

		$list = $this->rest( 'GET', 'jobs' )->get_data()['jobs'];
		$this->assertCount( 1, $list );
		$this->assertArrayNotHasKey( 'log_tail', $list[0], 'the list has no log tails' );
		$request = new \WP_REST_Request( 'GET', '/wp-checkpoint/v1/jobs' );
		$request->set_query_params( array( 'status' => array( 'failed' ) ) );
		$this->assertSame( array(), rest_get_server()->dispatch( $request )->get_data()['jobs'] );
	}

	public function test_log_tail_with_invalid_utf8_still_serialises(): void {
		$this->register( 'bytes', array( new ClosureStep( 'b', static function ( JobContext $ctx ): StepResult {
			$ctx->logger()->info( "half \xE2\x82 character and \xB1\x31 bytes" );
			return StepResult::done();
		} ) ) );
		$job  = Plugin::instance()->jobs()->create( 'bytes' );
		$data = $this->rest( 'POST', 'jobs/' . $job->id . '/tick' )->get_data();
		$this->assertIsString( wp_json_encode( $data ), 'the response can be encoded' );
		$this->assertStringContainsString( 'half', $data['job']['log_tail'] );
		$this->assertStringContainsString( "\xEF\xBF\xBD", $data['job']['log_tail'], 'invalid bytes became U+FFFD' );
	}

	public function test_missing_job_conflicts_and_unavailable_schema(): void {
		$this->assertSame( 404, $this->rest( 'GET', 'jobs/424242' )->get_status() );
		$this->assertSame( 404, $this->rest( 'POST', 'jobs/424242/tick' )->get_status() );
		$this->assertSame( 404, $this->rest( 'POST', 'jobs/424242/cancel' )->get_status() );
		$this->assertSame( 404, $this->rest( 'POST', 'jobs/424242/retry' )->get_status() );

		$this->register( 'plain', array( $this->counting_step( 'p', 1 ) ) );
		$job = Plugin::instance()->jobs()->create( 'plain' );
		$this->assertSame( 409, $this->rest( 'POST', 'jobs/' . $job->id . '/retry' )->get_status(), 'only failed jobs can be retried' );
		$this->rest( 'POST', 'jobs/' . $job->id . '/tick' );
		$this->assertSame( 409, $this->rest( 'POST', 'jobs/' . $job->id . '/cancel' )->get_status(), 'completed jobs cannot be cancelled' );

		\WPCheckpoint\Support\Options::set( Schema::OPTION, array( 'version' => Schema::CURRENT + 2, 'min_compatible' => Schema::CURRENT + 1 ) );
		$response = $this->rest( 'POST', 'jobs/' . $job->id . '/tick' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'finished', $response->get_data()['result'], 'a finished job stays readable' );
	}

	public function test_incompatible_schema_blocks_ticks_and_create_is_503(): void {
		$this->register( 'plain', array( $this->counting_step( 'p', 1 ) ) );
		$job = Plugin::instance()->jobs()->create( 'plain' );
		\WPCheckpoint\Support\Options::set( Schema::OPTION, array( 'version' => Schema::CURRENT + 2, 'min_compatible' => Schema::CURRENT + 1 ) );
		$data = $this->rest( 'POST', 'jobs/' . $job->id . '/tick' )->get_data();
		$this->assertSame( 'blocked', $data['result'] );
		$this->assertStringContainsString( 'newer version', $data['message'] );
	}

	public function test_cancel_takes_the_lock_and_cleans_up_or_defers_to_the_holder(): void {
		$cleaned = 0;
		$this->register( 'slow', array( new ClosureStep( 's', static function ( JobContext $ctx ): StepResult {
			return StepResult::progress( array( 'n' => 1 ), 10 );
		}, function () use ( &$cleaned ): void {
			++$cleaned;
		} ) ) );

		// Not locked: the cancel takes the lock and cleans up itself, without counting an attempt.
		$job = Plugin::instance()->jobs()->create( 'slow' );
		mkdir( Residue::work_dir( $job->storage_path, $job->id ), 0700, true );
		touch( Residue::work_dir( $job->storage_path, $job->id ) . '/v.partial' );
		$temp_table = TempTables::name( Plugin::instance()->directories()->state()['token'], $job->id, 'beef', 'posts' );
		$GLOBALS['wpdb']->query( "CREATE TABLE `{$temp_table}` (id int)" );
		$data = $this->rest( 'POST', 'jobs/' . $job->id . '/cancel' )->get_data();
		$this->assertSame( 'cancelled', $data['result'] );
		$this->assertTrue( $data['cleaned'] );
		$this->assertSame( 'The job was cancelled.', $data['message'] );
		$this->assertSame( 1, $cleaned );
		$this->assertDirectoryDoesNotExist( Residue::work_dir( $job->storage_path, $job->id ), 'the engine removes the work directory after the steps cleaned up' );
		$this->assertNull( $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SHOW TABLES LIKE %s', $temp_table ) ), 'and drops the temporary table' );
		$stored = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::CANCELLED, $stored->status );
		$this->assertSame( 0, $stored->attempts, 'never attempted' );
		$this->assertSame( 0, $stored->started_at, 'never started' );
		$this->assertSame( '', $stored->lock_token );
		$this->assertFileDoesNotExist( LockFile::path( $job->storage_path, $job->id ) );

		// Locked by another driver: the cancel cannot take the lock; the holder cleans up when its next write refuses.
		$job  = Plugin::instance()->jobs()->create( 'slow' );
		$held = Plugin::instance()->jobs()->acquire( $job->id );
		$this->assertNotNull( $held );
		$data = $this->rest( 'POST', 'jobs/' . $job->id . '/cancel' )->get_data();
		$this->assertSame( 'cancelled', $data['result'] );
		$this->assertFalse( $data['cleaned'] );
		$this->assertSame( 1, $cleaned, 'not cleaned while the holder may still be writing' );
		$this->assertStringContainsString( 'current step stops', $data['message'] );
		$this->assertFalse( Plugin::instance()->jobs()->heartbeat( $held['job'], $held['token'] ), 'the holder is fenced off' );
	}

	public function test_log_tail_only_reads_inside_the_logs_directory(): void {
		global $wpdb;
		$this->register( 'plain', array( $this->counting_step( 'p', 1 ) ) );
		$job    = Plugin::instance()->jobs()->create( 'plain' );
		$secret = dirname( dirname( $job->storage_path ) ) . '/wpcheckpoint-secret-' . bin2hex( random_bytes( 3 ) ) . '.txt';
		file_put_contents( $secret, "top secret\n" );
		file_put_contents( $job->storage_path . '/owner.txt', "owner\n" );
		try {
			foreach ( array( '../../' . basename( $secret ), 'logs/../../../' . basename( $secret ), 'logs/../owner.txt', '/etc/passwd', 'owner.txt' ) as $log_path ) {
				$wpdb->update( \WPCheckpoint\Support\Schema::jobs_table(), array( 'log_path' => $log_path ), array( 'id' => $job->id ) );
				$data = $this->rest( 'GET', 'jobs/' . $job->id )->get_data();
				$this->assertSame( '', $data['job']['log_tail'], $log_path );
			}

			// A job bound to another storage directory (a copied database on a shared file system): its log is not ours to read.
			$other = dirname( $job->storage_path ) . '/wpcheckpoint-other-' . bin2hex( random_bytes( 3 ) );
			mkdir( $other . '/logs', 0755, true );
			file_put_contents( $other . '/logs/job-1-abcdef01.log', "the other site's log\n" );
			$wpdb->update( \WPCheckpoint\Support\Schema::jobs_table(), array( 'storage_path' => $other, 'log_path' => 'logs/job-1-abcdef01.log' ), array( 'id' => $job->id ) );
			$data = $this->rest( 'GET', 'jobs/' . $job->id )->get_data();
			$this->assertSame( '', $data['job']['log_tail'], 'another directory' );
			\WPCheckpoint\Support\Deleter::empty_directory( $other );
			@rmdir( $other );
		} finally {
			unlink( $secret );
		}
	}

	public function test_a_failed_cancel_write_releases_the_lock_and_leaves_the_job_tickable(): void {
		$this->register( 'plain', array( $this->counting_step( 'p', 1 ) ) );
		$job  = Plugin::instance()->jobs()->create( 'plain' );
		$done = false;
		add_filter( 'query', static function ( string $sql ) use ( &$done ): string {
			// The status write of the cancel fails (database error simulated by a query that changes nothing).
			if ( ! $done && false !== strpos( $sql, 'UPDATE' ) && false !== strpos( $sql, "'cancelled'" ) ) {
				$done = true;
				return 'SELECT 0 WHERE 1 = 0';
			}
			return $sql;
		} );
		try {
			Plugin::instance()->job_actions()->cancel( $job->id );
			$this->fail( 'expected StaleJob' );
		} catch ( \WPCheckpoint\Jobs\StaleJob $e ) {
			$this->assertTrue( $done );
		} finally {
			remove_all_filters( 'query' );
		}
		$stored = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::QUEUED, $stored->status );
		$this->assertSame( '', $stored->lock_token, 'the lock taken for the cancel was released' );
		$this->assertSame( 'completed', $this->rest( 'POST', 'jobs/' . $job->id . '/tick' )->get_data()['result'], 'still tickable' );
	}

	public function test_concurrent_cancels_clean_up_exactly_once(): void {
		$cleaned = 0;
		$this->register( 'slow', array( new ClosureStep( 's', static function (): StepResult {
			return StepResult::progress( array( 'n' => 1 ), 10 );
		}, function () use ( &$cleaned ): void {
			++$cleaned;
		} ) ) );
		$job  = Plugin::instance()->jobs()->create( 'slow' );
		$done = false;
		$b    = null;
		add_filter( 'query', function ( string $sql ) use ( &$done, &$b, $job ): string {
			// A holds the cancel lock; before its status write runs, B cancels the same job (B sees a holder).
			if ( ! $done && false !== strpos( $sql, 'UPDATE' ) && false !== strpos( $sql, "'cancelled'" ) ) {
				$done = true;
				$b    = Plugin::instance()->job_actions()->cancel( $job->id );
			}
			return $sql;
		} );
		try {
			$a = Plugin::instance()->job_actions()->cancel( $job->id );
		} finally {
			remove_all_filters( 'query' );
		}
		$this->assertSame( 'holder', $b['reason'], 'B saw A holding the lock and left the cleanup to it' );
		$this->assertFalse( $b['cleaned'] );
		$this->assertSame( 'cleaned', $a['reason'], 'A, whose compare-and-set succeeded, cleaned up after its stale write' );
		$this->assertTrue( $a['cleaned'] );
		$this->assertSame( 1, $cleaned, 'exactly once' );
		$stored = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::CANCELLED, $stored->status );
		$this->assertSame( '', $stored->lock_token );
	}

	public function test_a_holder_that_loses_the_lock_to_a_cancel_cleans_up_in_the_same_request(): void {
		$cleaned = 0;
		$this->register( 'cancelled-mid-step', array( new ClosureStep( 's', function ( JobContext $ctx ) use ( &$cleaned ): StepResult {
			$ctx->checkpoint( array( 'i' => 1 ), 10 );
			$this->rest( 'POST', 'jobs/' . $ctx->job()->id . '/cancel' ); // An administrator cancels meanwhile.
			$ctx->checkpoint( array( 'i' => 2 ), 20 ); // Throws LockLost.
			return StepResult::done();
		}, function () use ( &$cleaned ): void {
			++$cleaned;
		} ) ) );
		$job  = Plugin::instance()->jobs()->create( 'cancelled-mid-step' );
		$data = $this->rest( 'POST', 'jobs/' . $job->id . '/tick' )->get_data();
		$this->assertSame( 'lost', $data['result'] );
		$this->assertSame( Job::CANCELLED, $data['job']['status'] );
		$this->assertSame( 1, $cleaned, 'cleanup ran once the step had stopped' );
	}

	public function test_retry_requeues_a_failed_job_with_its_cursor(): void {
		$calls = 0;
		$this->register( 'flaky', array( new ClosureStep( 'f', function ( JobContext $ctx ) use ( &$calls ): StepResult {
			++$calls;
			if ( 1 === $calls ) {
				$ctx->checkpoint( array( 'at' => 7 ), 50 );
				throw new \RuntimeException( 'disk full' );
			}
			$this->assertSame( array( 'at' => 7 ), $ctx->cursor(), 'the cursor survived the retry' );
			return StepResult::done();
		} ) ) );
		$job  = Plugin::instance()->jobs()->create( 'flaky' );
		$data = $this->rest( 'POST', 'jobs/' . $job->id . '/tick' )->get_data();
		$this->assertSame( 'failed', $data['result'] );
		$this->assertStringContainsString( 'RuntimeException: disk full', $data['job']['last_error'] );
		$data = $this->rest( 'POST', 'jobs/' . $job->id . '/retry' )->get_data();
		$this->assertSame( 'queued', $data['result'] );
		$this->assertSame( Job::QUEUED, $data['job']['status'] );
		$this->assertSame( 'completed', $this->rest( 'POST', 'jobs/' . $job->id . '/tick' )->get_data()['result'] );
	}

	public function test_retry_is_refused_with_the_reason_once_the_work_files_passed_retention(): void {
		$this->register( 'boom', array( new ClosureStep( 'b', static function (): StepResult {
			throw new \RuntimeException( 'no' );
		} ) ) );
		$job = Plugin::instance()->jobs()->create( 'boom' );
		$this->rest( 'POST', 'jobs/' . $job->id . '/tick' );
		$this->assertSame( Job::FAILED, Plugin::instance()->jobs()->find( $job->id )->status );
		$data = $this->rest( 'GET', 'jobs/' . $job->id )->get_data()['job'];
		$this->assertTrue( $data['retryable'] );
		$this->assertSame( '', $data['retry_note'] );

		$GLOBALS['wpdb']->update( Schema::jobs_table(), array( 'work_expired_at' => time() ), array( 'id' => $job->id ) );
		$data = $this->rest( 'GET', 'jobs/' . $job->id )->get_data()['job'];
		$this->assertFalse( $data['retryable'] );
		$this->assertStringContainsString( 'retention period', $data['retry_note'] );
		$response = $this->rest( 'POST', 'jobs/' . $job->id . '/retry' );
		$this->assertSame( 409, $response->get_status() );
		$this->assertStringContainsString( 'retention period', $response->get_data()['message'] );
		$this->assertSame( Job::FAILED, Plugin::instance()->jobs()->find( $job->id )->status );
	}

	public function test_a_paused_job_shows_its_questions_and_a_tick_reports_paused(): void {
		$this->register( 'asks', array( new ClosureStep( 'q', static function ( JobContext $ctx ): StepResult {
			$answers = $ctx->options()['answers'] ?? array();
			return empty( $answers['oversize'] )
				? StepResult::ask( array(), array( array( 'id' => 'oversize', 'tables' => array( 'wp_options' ) ) ), 'a row is too large' )
				: StepResult::done();
		} ) ) );
		$job  = Plugin::instance()->jobs()->create( 'asks' );
		$data = $this->rest( 'POST', 'jobs/' . $job->id . '/tick' )->get_data();
		$this->assertSame( 'paused', $data['result'] );
		$this->assertSame( -1, $data['retry_after'] );
		$this->assertSame( Job::PAUSED, $data['job']['status'] );
		$this->assertSame( array( array( 'id' => 'oversize', 'tables' => array( 'wp_options' ) ) ), $data['job']['questions'] );
		$this->assertArrayNotHasKey( 'options', $data['job'] );
		$data = $this->rest( 'GET', 'jobs/' . $job->id )->get_data()['job'];
		$this->assertSame( 'oversize', $data['questions'][0]['id'] );
		$this->assertSame( 'paused', $this->rest( 'POST', 'jobs/' . $job->id . '/tick' )->get_data()['result'], 'ticking again does not resume it' );

		Plugin::instance()->jobs()->answer( Plugin::instance()->jobs()->find( $job->id ), array( 'oversize' => 'exclude' ) );
		$this->assertNull( $this->rest( 'GET', 'jobs/' . $job->id )->get_data()['job']['questions'] );
		$this->assertSame( 'completed', $this->rest( 'POST', 'jobs/' . $job->id . '/tick' )->get_data()['result'] );
	}

	public function test_subscribers_cannot_read_or_cancel_jobs(): void {
		$this->register( 'plain', array( $this->counting_step( 'p', 1 ) ) );
		$job = Plugin::instance()->jobs()->create( 'plain' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		foreach ( array( array( 'GET', '' ), array( 'POST', '/tick' ), array( 'POST', '/cancel' ), array( 'POST', '/retry' ) ) as list( $method, $suffix ) ) {
			$this->assertSame( 403, $this->rest( $method, 'jobs/' . $job->id . $suffix )->get_status(), $method . ' ' . $suffix );
		}
		if ( is_multisite() ) {
			// A site administrator without network rights: the table and its logs are network-wide.
			$site_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $site_admin );
			$this->assertFalse( current_user_can( 'manage_network_options' ) );
			$this->assertSame( 403, $this->rest( 'POST', 'jobs/' . $job->id . '/cancel' )->get_status() );
			$this->assertSame( 403, $this->rest( 'GET', 'jobs/' . $job->id )->get_status() );
		}
		$this->assertSame( Job::QUEUED, Plugin::instance()->jobs()->find( $job->id )->status );
	}
}
