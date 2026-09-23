<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Backups\VerifyRecord;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Plugin;
use WPCheckpoint\Tests\Fixtures\Archive\ArchiveBuilder;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * The backups routes as the Backups screen will call them: list, details
 * with download links, delete, and a verify job that runs to its record,
 * each refused while a job holds the backup.
 */
final class BackupsControllerTest extends JobTestCase {

	const BASE = ArchiveBuilder::BASE;

	/** @var ArchiveBuilder */
	private $builder;

	/** @var string */
	private $backups;

	public function set_up(): void {
		parent::set_up();
		$this->builder = ( new ArchiveBuilder() )->typical()->build();
		$this->backups = Plugin::instance()->directories()->backups();
		$this->assertNotSame( '', $this->backups );
		foreach ( array_merge( $this->builder->volumes, array( $this->builder->manifest_path ) ) as $file ) {
			copy( $file, $this->backups . '/' . basename( $file ) );
		}
	}

	public function tear_down(): void {
		$this->builder->cleanup();
		$this->builder = null;
		parent::tear_down();
	}

	/**
	 * Assert the response carries neither the storage path nor, for an error, the site's host.
	 *
	 * @param \WP_REST_Response $response Response.
	 */
	private function assert_clean( $response ): void {
		$json = (string) wp_json_encode( $response->get_data() );
		$this->assertStringNotContainsString( Plugin::instance()->directories()->base(), $json );
		$this->assertStringNotContainsString( str_replace( '/', '\\/', Plugin::instance()->directories()->base() ), $json );
		if ( $response->get_status() >= 400 ) {
			$this->assertStringNotContainsString( (string) wp_parse_url( home_url(), PHP_URL_HOST ), $json );
		}
	}

	private function restore_job(): Job {
		return Plugin::instance()->jobs()->create( 'restore', self::$admin_id, array(), array( 'base' => self::BASE ) );
	}

	public function test_the_list_shows_each_backup_with_its_summary(): void {
		$response = $this->rest( 'GET', 'backups' );
		$this->assertSame( 200, $response->get_status() );
		$this->assert_clean( $response );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$data = $response->get_data();
		$this->assertSame( 1, $data['total'] );
		$this->assertSame( self::BASE, $data['backups'][0]['base'] );
		$this->assertTrue( $data['backups'][0]['valid'] );
		$this->assertTrue( $data['backups'][0]['complete'] );
		$this->assertSame( 'none', $data['backups'][0]['verification']['state'] );
	}

	public function test_details_link_every_file_and_say_they_belong_together(): void {
		$response = $this->rest( 'GET', 'backups/' . self::BASE );
		$this->assertSame( 200, $response->get_status() );
		$this->assert_clean( $response );
		$backup = $response->get_data()['backup'];
		$this->assertCount( count( $this->builder->volumes ), $backup['volume_files'] );
		foreach ( $backup['volume_files'] as $i => $volume ) {
			$this->assertSame( basename( $this->builder->volumes[ $i ] ), $volume['name'] );
			$this->assertSame( filesize( $this->builder->volumes[ $i ] ), $volume['bytes'] );
			if ( filesize( $this->builder->volumes[ $i ] ) > ArchiveBuilder::CHUNK_BYTES ) {
				$this->assertNull( $volume['sha256'], 'a block-hashed volume has no whole-file hash to compare' );
				$this->assertStringStartsWith( 'Check the file size. For a full check, use Verify in the plugin, or check the file block by block', $volume['check'] );
			} else {
				$this->assertSame( hash_file( 'sha256', $this->builder->volumes[ $i ] ), $volume['sha256'] );
				$this->assertSame( 'Check the file size and its SHA-256.', $volume['check'] );
			}
			$this->assertStringContainsString( 'file=backups/' . $volume['name'] . '&', $volume['download'] );
			$this->assertStringContainsString( '_wpnonce=', $volume['download'] );
		}
		$this->assertStringContainsString( 'file=backups/' . self::BASE . '.manifest.json&', $backup['manifest_file']['download'] );
		$this->assertStringContainsString( 'every file', $backup['downloads_note'] );
		$this->assertSame( hash_file( 'sha256', $this->builder->manifest_path ), $backup['manifest_file']['sha256'] );
	}

	public function test_during_a_restore_of_the_backup_there_are_no_links_and_nothing_can_be_done_with_it(): void {
		$restore = $this->restore_job();
		$backup  = $this->rest( 'GET', 'backups/' . self::BASE )->get_data()['backup'];
		$this->assertSame( '', $backup['manifest_file']['download'] );
		$this->assertSame( array( '' ), array_unique( array_column( $backup['volume_files'], 'download' ) ) );
		$this->assertSame( sprintf( 'This backup is being restored (job %d).', $restore->id ), $backup['downloads_note'] );

		$delete = $this->rest( 'DELETE', 'backups/' . self::BASE );
		$this->assertSame( 409, $delete->get_status() );
		$this->assert_clean( $delete );
		$verify = $this->rest( 'POST', 'backups/' . self::BASE . '/verify' );
		$this->assertSame( 409, $verify->get_status() );
		$this->assertStringStartsWith( sprintf( 'A restore is in progress (job %d)', $restore->id ), $verify->get_data()['message'] );
		$this->assertFileExists( $this->backups . '/' . self::BASE . '.manifest.json' );
		$this->assertCount( 1, Plugin::instance()->jobs()->list_jobs(), 'the refused verify job left no row' );
	}

	public function test_a_verify_job_runs_to_its_record_and_the_list_shows_it(): void {
		$response = $this->rest( 'POST', 'backups/' . self::BASE . '/verify', array( 'depth' => 'full' ) );
		$this->assertSame( 201, $response->get_status() );
		$this->assert_clean( $response );
		$id = $response->get_data()['job']['id'];
		$this->assertSame( 'verify', Plugin::instance()->jobs()->find( $id )->type );

		$again = $this->rest( 'POST', 'backups/' . self::BASE . '/verify' );
		$this->assertSame( 409, $again->get_status() );
		$this->assertSame( sprintf( 'This backup is being verified (job %d); wait until the check has ended.', $id ), $again->get_data()['message'] );
		$this->assertSame( 409, $this->rest( 'DELETE', 'backups/' . self::BASE )->get_status(), 'a backup being verified is not deleted' );

		for ( $i = 0; $i < 50; $i++ ) {
			$tick = $this->rest( 'POST', 'jobs/' . $id . '/tick' )->get_data();
			if ( 'completed' === $tick['result'] ) {
				break;
			}
		}
		$this->assertSame( Job::COMPLETED, Plugin::instance()->jobs()->find( $id )->status );
		$this->assertSame( 'Archive is intact.', $tick['job']['message'] );
		$this->assertFileExists( $this->backups . '/' . VerifyRecord::file_name( self::BASE ) );

		$summary = $this->rest( 'GET', 'backups' )->get_data()['backups'][0];
		$this->assertSame( 'current', $summary['verification']['state'] );
		$this->assertSame( 'passed', $summary['verification']['record']['outcome'] );
		$this->assertSame( $id, $summary['verification']['record']['job'] );
	}

	public function test_delete_removes_the_backup_and_then_it_is_gone(): void {
		file_put_contents( $this->backups . '/' . self::BASE . '.manifest.json.bak', 'kept' );
		$response = $this->rest( 'DELETE', 'backups/' . self::BASE );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( count( $this->builder->volumes ) + 1, $response->get_data()['deleted'] );
		$this->assertFileExists( $this->backups . '/' . self::BASE . '.manifest.json.bak' );
		foreach ( array( 'GET', 'DELETE' ) as $method ) {
			$gone = $this->rest( $method, 'backups/' . self::BASE );
			$this->assertSame( 404, $gone->get_status(), $method );
			$this->assert_clean( $gone );
		}
		$this->assertSame( 404, $this->rest( 'POST', 'backups/' . self::BASE . '/verify' )->get_status() );
		$this->assertSame( 0, $this->rest( 'GET', 'backups' )->get_data()['total'] );
	}

	private function start_lock_name(): string {
		return 'wpcheckpoint_start_' . substr( md5( DB_NAME . '.' . $GLOBALS['wpdb']->base_prefix . 'wpcheckpoint_jobs' ), 0, 16 );
	}

	public function test_a_delete_waits_for_job_starts_and_touches_nothing_without_the_lock(): void {
		// Another request is in the middle of starting a job (a restore, say): it holds the start lock.
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $this->start_lock_name() ) ) );
		try {
			$response = $this->rest( 'DELETE', 'backups/' . self::BASE );
			$this->assertSame( 503, $response->get_status() );
			$this->assert_clean( $response );
		} finally {
			$other->query( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $this->start_lock_name() ) );
			$other->close();
		}
		foreach ( array_merge( $this->builder->volumes, array( $this->builder->manifest_path ) ) as $file ) {
			$this->assertFileExists( $this->backups . '/' . basename( $file ) );
		}
		$this->assertSame( 200, $this->rest( 'DELETE', 'backups/' . self::BASE )->get_status(), 'once the start is done, the delete goes through' );
	}

	public function test_a_partly_deleted_backup_says_so_and_is_not_verified(): void {
		file_put_contents( $this->backups . '/' . self::BASE . '.deleting', '' );
		unlink( $this->backups . '/' . basename( $this->builder->volumes[0] ) );
		$summary = $this->rest( 'GET', 'backups' )->get_data()['backups'][0];
		$this->assertTrue( $summary['delete_incomplete'] );
		$this->assertFalse( $summary['complete'] );
		$verify = $this->rest( 'POST', 'backups/' . self::BASE . '/verify' );
		$this->assertSame( 409, $verify->get_status() );
		$this->assertSame( 'This backup was only partly deleted; delete it again.', $verify->get_data()['message'] );
		$this->assertSame( array(), Plugin::instance()->jobs()->list_jobs() );
		$this->assertSame( 200, $this->rest( 'DELETE', 'backups/' . self::BASE )->get_status() );
		$this->assertSame( array(), array_values( preg_grep( '/\A' . preg_quote( self::BASE, '/' ) . '\./', (array) scandir( $this->backups ) ) ), 'nothing of the backup is left, the marker included' );
	}

	private function post( string $path, array $body ) {
		$request = new \WP_REST_Request( 'POST', '/wp-checkpoint/v1/' . $path );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_backup_is_made_with_the_chosen_contents_and_asks_its_questions(): void {
		$response = $this->post( 'backups', array( 'contents' => 'database', 'exclude_tables' => array( 'wp_links' ) ) );
		$this->assertSame( 201, $response->get_status() );
		$this->assert_clean( $response );
		$job = Plugin::instance()->jobs()->find( $response->get_data()['job']['id'] );
		$this->assertSame( 'export', $job->type );
		$this->assertSame( array(), $job->options['contents']['files'], 'database only' );
		$this->assertTrue( $job->options['contents']['database'] );
		$this->assertSame( array( 'wp_links' ), $job->options['exclude_tables'] );
		$this->assertSame( 'ask', $job->options['policy']['unreadable'], 'the screen answers questions: no policy' );

		$again = $this->post( 'backups', array() );
		$this->assertSame( 409, $again->get_status(), 'one export at a time' );
		$this->assertSame( sprintf( 'A backup is already being made (job %d).', $job->id ), $again->get_data()['message'] );
	}

	public function test_a_backup_request_with_bad_options_is_refused_and_starts_nothing(): void {
		$this->assertSame( 400, $this->post( 'backups', array( 'contents' => 'everything' ) )->get_status() );
		$this->assertSame( 400, $this->post( 'backups', array( 'exclude_tables' => array( 'a' ), 'include_tables' => array( 'a' ) ) )->get_status() );
		$this->assertSame( array(), Plugin::instance()->jobs()->list_jobs() );
	}

	public function test_the_estimate_starts_once_counts_the_files_and_is_then_used_for_a_week(): void {
		foreach ( glob( $this->backups . '/*' ) as $file ) {
			if ( is_file( $file ) && 'index.php' !== basename( $file ) ) {
				unlink( $file );
			}
		}
		$first = $this->post( 'backups/estimate', array() )->get_data()['estimate'];
		$this->assertSame( 'running', $first['state'] );
		$this->assertGreaterThan( 0, $first['job'] );
		$this->assertGreaterThan( 0, $first['database_bytes'] );
		$this->assertSame( $first['job'], $this->post( 'backups/estimate', array() )->get_data()['estimate']['job'], 'the same job, not a second one' );
		$this->assertSame( array(), array_column( $this->rest( 'GET', 'jobs' )->get_data()['jobs'], 'id' ), 'the plugin\'s own job is not among the user\'s' );

		for ( $i = 0; $i < 50 && 'completed' !== ( $tick = $this->rest( 'POST', 'jobs/' . $first['job'] . '/tick' )->get_data() )['result']; $i++ ) {
			continue;
		}
		$this->assertSame( 'completed', $tick['result'] );
		$ready = $this->post( 'backups/estimate', array() )->get_data()['estimate'];
		$this->assertSame( 'ready', $ready['state'] );
		$this->assertGreaterThan( 0, $ready['files'] );
		$this->assertGreaterThan( 0, $ready['files_bytes'] );
		$this->assertCount( 1, Plugin::instance()->jobs()->list_jobs(), 'a fresh count starts no job' );
		$this->assertNull( $ready['seconds'], 'no export measured here yet: no time, rather than a guess' );
	}

	public function test_a_failed_estimate_is_not_retried_within_a_day_and_shows_no_error(): void {
		global $wpdb;
		$job = Plugin::instance()->jobs()->create( 'estimate', self::$admin_id );
		$wpdb->update( \WPCheckpoint\Support\Schema::jobs_table(), array( 'status' => 'failed', 'finished_at' => time() - 3600, 'last_error' => 'boom' ), array( 'id' => $job->id ) );
		$status = $this->post( 'backups/estimate', array() )->get_data()['estimate'];
		$this->assertSame( 'none', $status['state'] );
		$this->assertCount( 1, Plugin::instance()->jobs()->list_jobs(), 'not started again' );
		$wpdb->update( \WPCheckpoint\Support\Schema::jobs_table(), array( 'finished_at' => time() - 90000 ), array( 'id' => $job->id ) );
		$this->assertSame( 'running', $this->post( 'backups/estimate', array() )->get_data()['estimate']['state'], 'a day later it is tried again' );
	}

	public function test_starting_a_backup_cancels_the_estimate_silently_and_an_estimate_waits_for_the_backup(): void {
		$estimate = $this->post( 'backups/estimate', array() )->get_data()['estimate'];
		$this->assertSame( 'running', $estimate['state'] );
		$made = $this->post( 'backups', array() );
		$this->assertSame( 201, $made->get_status(), 'the estimate never holds a backup up' );
		$this->assertSame( Job::CANCELLED, Plugin::instance()->jobs()->find( $estimate['job'] )->status );
		$this->assertSame( array( $made->get_data()['job']['id'] ), array_column( $this->rest( 'GET', 'jobs' )->get_data()['jobs'], 'id' ), 'nothing about the estimate in the user\'s list' );
		$this->assertSame( 'none', $this->post( 'backups/estimate', array() )->get_data()['estimate']['state'], 'no estimate while a backup is made' );
	}

	public function test_requests_outside_the_backup_names_are_refused_before_any_file_is_touched(): void {
		$this->assertSame( 400, $this->per_page_status( 51 ) );
		$this->assertSame( 400, $this->rest( 'POST', 'backups/' . self::BASE . '/verify', array( 'depth' => 'quick' ) )->get_status() );
		foreach ( array( '..%2F' . self::BASE, 'EXAMPLE-20260918-100000-a1b2', self::BASE . '.manifest.json', 'x-2026091-100000-a1b2' ) as $name ) {
			$this->assertSame( 404, $this->rest( 'DELETE', 'backups/' . $name )->get_status(), $name );
		}
		$this->assertFileExists( $this->backups . '/' . self::BASE . '.manifest.json' );
		$this->assertSame( array(), Plugin::instance()->jobs()->list_jobs() );
	}

	private function per_page_status( int $per_page ): int {
		$request = new \WP_REST_Request( 'GET', '/wp-checkpoint/v1/backups' );
		$request->set_query_params( array( 'per_page' => $per_page ) );
		return rest_get_server()->dispatch( $request )->get_status();
	}
}
