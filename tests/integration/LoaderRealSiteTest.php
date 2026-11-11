<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Backups\Estimate;
use WPCheckpoint\Jobs\EstimateJob;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\VisitorFile;
use WPCheckpoint\Jobs\WebRuntime;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\Loader;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Tests\Fixtures\ActivePlugin;
use WPCheckpoint\Tests\Fixtures\ContentLeftovers;
use WPCheckpoint\Tests\Fixtures\RealAbspath;
use WPCheckpoint\Tests\Fixtures\RealMuPlugins;

/**
 * The restore's must-use plugin (Restore\Loader) on the test site itself: loaded by its web server and by real WP-CLI
 * processes (registered exceptions, CLAUDE.md: the files in the real ABSPATH through RealAbspath, the loader in the
 * real must-use plugins directory through RealMuPlugins).
 *
 * - Of requests made at once while the site waits for its object cache's flush, the maintenance file's code lets one
 *   through, counted before it goes; the loader ends that one too with the maintenance page.
 * - With the site held, the loader up and .maintenance deleted, the web server's requests are held by the loader (the
 *   control), and WP-CLI's "job status" and "job run" run as ever: the loader does nothing on the command line.
 */
final class LoaderRealSiteTest extends WP_UnitTestCase {

	/** @var string The web server's address. */
	private $host = '';

	/** @var string The Host header its WordPress answers to. */
	private $header = '';

	public function set_up(): void {
		parent::set_up();
		$this->host = rtrim( (string) getenv( 'WPCHECKPOINT_TEST_LOOPBACK_HOST' ), '/' );
		if ( '' === $this->host ) {
			$this->markTestSkipped( 'Needs the web server of the test site (WPCHECKPOINT_TEST_LOOPBACK_HOST).' );
		}
		RealAbspath::claim();
		RealMuPlugins::claim();
		$index        = wp_remote_get( $this->host . '/?rest_route=/', array( 'timeout' => 20 ) );
		$home         = (string) ( json_decode( (string) wp_remote_retrieve_body( $index ), true )['home'] ?? '' );
		$this->header = (string) wp_parse_url( $home, PHP_URL_HOST ) . ( null !== wp_parse_url( $home, PHP_URL_PORT ) ? ':' . (int) wp_parse_url( $home, PHP_URL_PORT ) : '' );
		$this->assertNotSame( '', $this->header, 'the web server\'s WordPress says its address' );
	}

	public function tear_down(): void {
		foreach ( RealMuPlugins::listing() as $left ) {
			RealMuPlugins::remove( basename( $left ) );
		}
		foreach ( RealAbspath::listing() as $left ) {
			RealAbspath::remove( basename( $left ) );
		}
		parent::tear_down();
	}

	/**
	 * The site held for a restore no job has: the data file (held, naming the runtime), the runtime, the visitor file
	 * (its time past; with $flush, waiting for the flush) and the runtime log; with $file, .maintenance too.
	 *
	 * @param string $mark  Mark.
	 * @param bool   $file  Whether .maintenance is up.
	 * @param bool   $flush Whether the visitor file awaits the flush.
	 * @return void
	 */
	private function held( string $mark, bool $file, bool $flush ): void {
		$built = WebRuntime::build( $mark );
		RealAbspath::write( Maintenance::RUNTIME, $built['code'] );
		RealAbspath::write( Maintenance::VISITOR, "<?php exit; ?>\n# " . $mark . "\n{\"plugin\":\"wp-checkpoint\\/wp-checkpoint.php\"" . ( $flush ? ',"flush":{"n":0,"next":0}' : '' ) . "}\n" );
		RealAbspath::touch( Maintenance::VISITOR, time() - 60 );
		RealAbspath::write( Maintenance::LOG, "<?php exit; ?>\n# " . $mark . "\n" );
		RealAbspath::write( Maintenance::DATA, ( new Maintenance( ABSPATH, $mark ) )->data( Maintenance::held(), Maintenance::NO_PASS, $built['hash'] ) );
		if ( $file ) {
			RealAbspath::write( Maintenance::FILE, Maintenance::CODE );
		}
	}

	/**
	 * The runtime log as it is.
	 *
	 * @return string
	 */
	private static function runtime_log(): string {
		clearstatcache();
		$path = rtrim( ABSPATH, '/' ) . '/' . Maintenance::LOG;
		return is_file( $path ) ? (string) file_get_contents( $path ) : '';
	}

	/**
	 * Requests to the web server's front page, made at once.
	 *
	 * @param int $count How many.
	 * @return array<int, array{code: int, body: string}>
	 */
	private function at_once( int $count ): array {
		$this->assertTrue( function_exists( 'curl_multi_init' ), 'requests at once need the curl extension' );
		$multi   = curl_multi_init();
		$handles = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$handle = curl_init( $this->host . '/?wpcheckpoint_nocache=' . bin2hex( random_bytes( 4 ) ) );
			curl_setopt_array(
				$handle,
				array(
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_HTTPHEADER     => array( 'Host: ' . $this->header ),
					CURLOPT_TIMEOUT        => 60,
					CURLOPT_FOLLOWLOCATION => false,
				)
			);
			curl_multi_add_handle( $multi, $handle );
			$handles[] = $handle;
		}
		do {
			$status = curl_multi_exec( $multi, $running );
			if ( $running ) {
				curl_multi_select( $multi, 1.0 );
			}
		} while ( $running && CURLM_OK === $status );
		$out = array();
		foreach ( $handles as $handle ) {
			$out[] = array(
				'code' => (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ),
				'body' => (string) curl_multi_getcontent( $handle ),
			);
			curl_multi_remove_handle( $multi, $handle );
			curl_close( $handle );
		}
		curl_multi_close( $multi );
		return $out;
	}

	public function test_of_requests_made_at_once_one_is_let_through_to_flush_and_it_is_counted_first(): void {
		$mark = Maintenance::new_mark();
		$this->held( $mark, true, true );
		RealMuPlugins::put();
		$visitor = new VisitorFile( rtrim( ABSPATH, '/' ), $mark );
		$this->assertSame( array( 'n' => 0, 'next' => 0 ), $visitor->flush(), 'the control: the flush is awaited, nothing let through yet' );

		$answers = $this->at_once( 12 );
		$this->assertCount( 12, $answers );
		foreach ( $answers as $answer ) {
			$this->assertSame( 503, $answer['code'], 'every request ends with the maintenance page (the one let through by the loader): ' . substr( $answer['body'], 0, 300 ) );
			$this->assertStringContainsString( 'Briefly unavailable for scheduled maintenance', $answer['body'] );
		}
		$log = self::runtime_log();
		$this->assertSame( 1, $visitor->flush()['n'], 'one request was let through, counted: ' . $log );
		$this->assertSame( 1, substr_count( $log, 'a request was let through to flush it (try 1)' ), $log );
		$this->assertSame( 0, substr_count( $log, '(try 2)' ), $log );
		$this->assertSame( 1, substr_count( $log, 'No job holds the site with this maintenance file' ), 'the loader ran the runtime in the one let through, and in no other: ' . $log );

		// The control: its time come again, the next request is let through and counted (the count tells).
		RealAbspath::touch( Maintenance::VISITOR, time() - 1 );
		$next = $this->at_once( 1 );
		$this->assertSame( 503, $next[0]['code'] );
		$this->assertSame( 2, $visitor->flush()['n'], self::runtime_log() );
		$this->assertSame( 1, substr_count( self::runtime_log(), '(try 2)' ) );

		// The control: without the loader none is let through (the fallback is not there to end the request).
		RealMuPlugins::remove();
		RealAbspath::touch( Maintenance::VISITOR, time() - 1 );
		$this->at_once( 3 );
		$this->assertSame( 2, $visitor->flush()['n'], 'no loader, no request let through' );
	}

	/**
	 * Run wp on the test site: exit status, standard output, standard error (read apart).
	 *
	 * @param string ...$args Arguments.
	 * @return array{0: int, 1: string, 2: string}
	 */
	private function wp( string ...$args ): array {
		$wp = trim( (string) shell_exec( 'command -v wp' ) );
		$this->assertNotSame( '', $wp, 'WP-CLI (wp) is on the PATH of the test site\'s container' );
		$pipes   = array();
		$process = proc_open( array_merge( array( $wp, '--path=' . ABSPATH ), $args ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		$out = (string) stream_get_contents( $pipes[1] );
		$err = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return array( proc_close( $process ), $out, $err );
	}

	public function test_with_the_loader_up_and_the_maintenance_file_deleted_wp_cli_shows_and_runs_a_job_as_ever(): void {
		global $wpdb;
		$mark = Maintenance::new_mark();
		$job  = null;
		ActivePlugin::on();
		try {
			$job = Plugin::instance()->jobs()->create( EstimateJob::ID, 0 );
			$wpdb->query( 'COMMIT' );
			$this->held( $mark, false, false ); // Held by its data file; .maintenance deleted.
			RealMuPlugins::put();
			$this->assertTrue( ( new Maintenance( rtrim( ABSPATH, '/' ), $mark ) )->left_held() );

			// The control: in this state the loader holds the web server's requests (it is loaded, and it answers).
			$held = $this->at_once( 1 );
			$this->assertSame( 503, $held[0]['code'], substr( $held[0]['body'], 0, 300 ) );
			$this->assertStringContainsString( 'Briefly unavailable for scheduled maintenance', $held[0]['body'] );
			$this->assertStringContainsString( 'No job holds the site with this maintenance file', self::runtime_log(), 'and ran the runtime' );

			list( $status, $out, $err ) = $this->wp( 'wpcheckpoint', 'job', 'status', (string) $job->id, '--format=json' );
			$this->assertSame( 0, $status, "job status runs.\nstdout: {$out}\nstderr: {$err}" );
			$shown = json_decode( $out, true );
			$this->assertIsArray( $shown, $out );
			$this->assertStringContainsString( Job::QUEUED, (string) wp_json_encode( $shown ), 'it shows the job as it is' );
			$this->assertStringNotContainsString( 'Briefly unavailable', $out . $err, 'the loader said nothing on the command line' );

			list( $status, $out, $err ) = $this->wp( 'wpcheckpoint', 'job', 'run', (string) $job->id, '--wait' );
			$this->assertSame( 0, $status, "job run runs.\nstdout: {$out}\nstderr: {$err}" );
			$this->assertStringNotContainsString( 'Briefly unavailable', $out . $err );
			$wpdb->query( 'COMMIT' );
			$done = Plugin::instance()->jobs()->find( $job->id );
			$this->assertSame( Job::COMPLETED, $done->status, "the job ran to its end.\nstdout: {$out}\nstderr: {$err}\n" . $done->last_error );
			$this->assertSame( array( ContentLeftovers::mu_dir() . '/' . Loader::NAME ), RealMuPlugins::listing(), 'the control: the loader was there all along' );
		} finally {
			if ( null !== $job ) {
				Plugin::instance()->jobs()->reclaim_work( Plugin::instance()->jobs()->find( $job->id ) );
				$wpdb->delete( JobRepository::table(), array( 'id' => $job->id ) );
				delete_option( Estimate::OPTION );
				delete_site_option( Estimate::OPTION );
				$wpdb->query( 'COMMIT' );
			}
			ActivePlugin::off();
		}
	}
}
