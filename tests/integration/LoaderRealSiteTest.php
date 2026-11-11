<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Backups\Estimate;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\VisitorFile;
use WPCheckpoint\Jobs\WebRuntime;
use WPCheckpoint\Restore\Loader;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Tests\Fixtures\ActivePlugin;
use WPCheckpoint\Tests\Fixtures\ContentLeftovers;
use WPCheckpoint\Tests\Fixtures\RealAbspath;
use WPCheckpoint\Tests\Fixtures\RealAdvancedCache;
use WPCheckpoint\Tests\Fixtures\RealMuPlugins;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use WPCheckpoint\Tests\Fixtures\StorageDirs;

/**
 * The restore's must-use plugin (Restore\Loader) on the test site itself: loaded by its web server and by real WP-CLI
 * processes (registered exceptions, CLAUDE.md: the files in the real ABSPATH through RealAbspath, the loader in the
 * real must-use plugins directory through RealMuPlugins).
 *
 * - Of requests made at once while the site waits for its object cache's flush, the maintenance file's code lets one
 *   through, counted before it goes; the loader ends that one too with the maintenance page.
 * - With the site held, the loader up and .maintenance deleted, the web server's requests are held by the loader (the
 *   control), and WP-CLI's "job status" and "job run" run as ever: the loader does nothing on the command line.
 * - With a page cache drop-in in the real wp-content (RealAdvancedCache, the third registered exception) and WP_CACHE
 *   true for the request (php-cgi on the test site's own index.php, the constant from an auto_prepend_file: the test
 *   site's wp-config.php is not changed), the request let through does not load the drop-in and reaches the loader;
 *   with the same code less its filter, the cache answers it and the loader is never reached (the control).
 */
final class LoaderRealSiteTest extends WP_UnitTestCase {

	/** @var string The web server's address. */
	private $host = '';

	/** @var string The Host header its WordPress answers to. */
	private $header = '';

	/** @var string A sandbox of the test's own ('' when none was made). */
	private $sandbox = '';

	public function set_up(): void {
		parent::set_up();
		$this->host = rtrim( (string) getenv( 'WPCHECKPOINT_TEST_LOOPBACK_HOST' ), '/' );
		if ( '' === $this->host ) {
			$this->markTestSkipped( 'Needs the web server of the test site (WPCHECKPOINT_TEST_LOOPBACK_HOST).' );
		}
		RealAbspath::claim();
		RealMuPlugins::claim();
		RealAdvancedCache::claim();
		$index        = wp_remote_get( $this->host . '/?rest_route=/', array( 'timeout' => 20 ) );
		$home         = (string) ( json_decode( (string) wp_remote_retrieve_body( $index ), true )['home'] ?? '' );
		$this->header = (string) wp_parse_url( $home, PHP_URL_HOST ) . ( null !== wp_parse_url( $home, PHP_URL_PORT ) ? ':' . (int) wp_parse_url( $home, PHP_URL_PORT ) : '' );
		$this->assertNotSame( '', $this->header, 'the web server\'s WordPress says its address' );
	}

	public function tear_down(): void {
		foreach ( RealAdvancedCache::listing() as $left ) {
			RealAdvancedCache::clear( $left );
		}
		if ( '' !== $this->sandbox ) {
			Sandbox::remove( $this->sandbox );
			$this->sandbox = '';
		}
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
	 * One request of the test site's own WordPress by php-cgi, with WP_CACHE true (defined by an auto_prepend_file), or
	 * with another auto_prepend_file's code.
	 *
	 * @param string $prepend The auto_prepend_file's code.
	 * @return array{headers: string, body: string, said: string}
	 */
	private function cached_request( string $prepend = "<?php\ndefine( 'WP_CACHE', true );\n" ): array {
		$cgi = dirname( PHP_BINARY ) . '/php-cgi';
		$this->assertTrue( is_executable( $cgi ), 'php-cgi is next to the PHP binary (the test site\'s image has it)' );
		if ( '' === $this->sandbox ) {
			$this->sandbox = Sandbox::make( 'loader-real' );
		}
		file_put_contents( $this->sandbox . '/prepend.php', $prepend );
		$index = rtrim( ABSPATH, '/' ) . '/index.php';
		$env   = array(
			'REDIRECT_STATUS' => '200',
			'SCRIPT_FILENAME' => $index,
			'SCRIPT_NAME'     => '/index.php',
			'REQUEST_METHOD'  => 'GET',
			'REQUEST_URI'     => '/?wpcheckpoint_nocache=' . bin2hex( random_bytes( 4 ) ),
			'QUERY_STRING'    => '',
			'HTTP_HOST'       => $this->header,
			'SERVER_NAME'     => (string) strtok( $this->header, ':' ),
			'SERVER_PROTOCOL' => 'HTTP/1.1',
			'REMOTE_ADDR'     => '127.0.0.1',
		) + (array) getenv();
		$pipes   = array();
		$process = proc_open( array( $cgi, '-d', 'auto_prepend_file=' . $this->sandbox . '/prepend.php', '-d', 'display_errors=1', $index ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, rtrim( ABSPATH, '/' ), $env );
		$this->assertIsResource( $process );
		$out    = (string) stream_get_contents( $pipes[1] );
		$err    = (string) stream_get_contents( $pipes[2] );
		$status = proc_close( $process );
		$parts  = explode( "\r\n\r\n", $out, 2 );
		return array(
			'headers' => $parts[0],
			'body'    => (string) ( $parts[1] ?? '' ),
			'said'    => "exit {$status}\nstdout:\n" . substr( $out, 0, 1500 ) . "\nstderr:\n" . substr( $err, 0, 1500 ),
		);
	}

	public function test_the_request_let_through_loads_no_page_cache_and_reaches_the_loader(): void {
		$mark = Maintenance::new_mark();
		$this->held( $mark, false, true );
		RealMuPlugins::put();
		RealAdvancedCache::put();
		$visitor = new VisitorFile( rtrim( ABSPATH, '/' ), $mark );
		$ran     = 'No job holds the site with this maintenance file'; // What the runtime says when the loader runs it here.

		// The control: the same code less the filter (a test's fixture under the maintenance file's name). The request
		// is let through and counted, WP_CACHE is true, and the page cache answers it: the loader is never reached.
		$count   = 0;
		$without = preg_replace( '/^\t+if \( function_exists\( \'add_filter\' \) \) \{\n\t+add_filter\( \'enable_loading_advanced_cache_dropin\'[^\n]*\n\t+\}\n/m', '', Maintenance::CODE, -1, $count );
		$this->assertSame( 1, $count, 'the code has the filter, once' );
		$this->assertStringNotContainsString( 'enable_loading_advanced_cache_dropin', (string) $without );
		RealAbspath::write( Maintenance::FILE, RealAbspath::fixture( (string) $without ) );
		$cached = $this->cached_request();
		$this->assertStringContainsString( RealAdvancedCache::PAGE, $cached['body'], 'the control: the page cache answered: ' . $cached['said'] );
		$this->assertSame( 1, $visitor->flush()['n'], 'the request was let through, and counted' );
		$this->assertStringNotContainsString( $ran, self::runtime_log(), 'and never reached the loader' );
		RealAbspath::remove( Maintenance::FILE );

		// The code as it is: the request let through loads no page cache, reaches the loader, which runs the runtime
		// and ends the request with the maintenance page's answer.
		RealAbspath::write( Maintenance::FILE, Maintenance::CODE );
		RealAbspath::touch( Maintenance::VISITOR, time() - 1 );
		$let = $this->cached_request();
		$this->assertStringNotContainsString( RealAdvancedCache::PAGE, $let['body'], 'not answered from the page cache: ' . $let['said'] );
		$this->assertSame( 1, preg_match( '/^Status: 503\b/m', $let['headers'] ), 'the loader ended it: ' . $let['said'] );
		$this->assertStringContainsString( 'Briefly unavailable for scheduled maintenance', $let['body'] );
		$this->assertSame( 2, $visitor->flush()['n'], 'let through, and counted' );
		$this->assertSame( 1, substr_count( self::runtime_log(), $ran ), 'the loader ran the runtime: ' . self::runtime_log() );
	}

	public function test_a_request_let_through_that_ends_before_the_loader_after_its_window_is_let_through_once(): void {
		$mark = Maintenance::new_mark();
		$this->held( $mark, true, true );
		$visitor = new VisitorFile( rtrim( ABSPATH, '/' ), $mark );
		// A content directory of the test's own (WP_CONTENT_DIR from the auto_prepend_file: the test site's is not
		// touched): the loader where WordPress would load it from, and an object cache drop-in that stands for a
		// request that hangs: WordPress includes it after .maintenance let the request through and before the
		// must-use plugins; it waits past the window of the request let through (five seconds), and ends.
		$this->sandbox = Sandbox::make( 'loader-real' );
		$content       = $this->sandbox . '/content';
		mkdir( $content . '/mu-plugins', 0755, true );
		file_put_contents( Loader::path( $content . '/mu-plugins' ), Loader::CODE );
		file_put_contents( $content . '/object-cache.php', "<?php\nfile_put_contents( __DIR__ . '/ran', 'x', FILE_APPEND );\nsleep( 6 );\nexit;\n" );
		$prepend = "<?php\ndefine( 'WP_CONTENT_DIR', " . var_export( $content, true ) . " );\n";
		$ran     = static function () use ( $content ): int {
			clearstatcache();
			return is_file( $content . '/ran' ) ? (int) filesize( $content . '/ran' ) : 0;
		};

		// The control: the same code less its check of WPCHECKPOINT_FLUSH_PASS (a test's fixture under the maintenance
		// file's name). WordPress requires .maintenance again when the request ends (its shutdown handler asks
		// wp_is_maintenance_mode()); the window has come again by then, and the one request is let through twice.
		$count   = 0;
		$without = str_replace( ' && ! defined( \'WPCHECKPOINT_FLUSH_PASS\' )', '', Maintenance::CODE, $count );
		$this->assertSame( 1, $count, 'the code has the check, once' );
		RealAbspath::write( Maintenance::FILE, RealAbspath::fixture( $without ) );
		$began = microtime( true );
		$twice = $this->cached_request( $prepend );
		$this->assertGreaterThanOrEqual( 6.0, microtime( true ) - $began, 'the control: the request hung past its window: ' . $twice['said'] );
		$this->assertSame( 1, $ran(), 'the control: one request, let through to the drop-in once: ' . $twice['said'] );
		$this->assertSame( 2, $visitor->flush()['n'], 'the control: without the check the one request is counted twice: ' . self::runtime_log() );
		$this->assertSame( 1, substr_count( self::runtime_log(), '(try 2)' ), self::runtime_log() );
		RealAbspath::remove( Maintenance::FILE );

		// The code as it is, from the start: one request, counted once, said once.
		RealAbspath::remove( Maintenance::VISITOR );
		RealAbspath::remove( Maintenance::LOG );
		RealAbspath::write( Maintenance::VISITOR, "<?php exit; ?>\n# " . $mark . "\n{\"plugin\":\"wp-checkpoint\\/wp-checkpoint.php\",\"flush\":{\"n\":0,\"next\":0}}\n" );
		RealAbspath::touch( Maintenance::VISITOR, time() - 60 );
		RealAbspath::write( Maintenance::LOG, "<?php exit; ?>\n# " . $mark . "\n" );
		RealAbspath::write( Maintenance::FILE, Maintenance::CODE );
		$began = microtime( true );
		$once  = $this->cached_request( $prepend );
		$this->assertGreaterThanOrEqual( 6.0, microtime( true ) - $began, 'the request hung past its window: ' . $once['said'] );
		$this->assertSame( 2, $ran(), 'it was let through to the drop-in, once more: ' . $once['said'] );
		$this->assertSame( 1, $visitor->flush()['n'], 'one request, counted once: ' . self::runtime_log() );
		$this->assertSame( 1, substr_count( self::runtime_log(), 'a request was let through to flush it (try ' ), 'and said once: ' . self::runtime_log() );
		$this->assertStringNotContainsString( 'No job holds the site with this maintenance file', self::runtime_log(), 'it ended before the loader' );
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

	/**
	 * The stored state of the storage directory as the database has it (null for none), past any cache.
	 *
	 * @return string|null
	 */
	private static function stored_state() {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		return is_multisite()
			? $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key = %s AND site_id = %d", Directories::OPTION, get_current_network_id() ) )
			: $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Directories::OPTION ) );
	}

	/**
	 * Put the stored state back as it was in the database (the WP-CLI processes may have made a storage directory of
	 * their own and stored it: this process's is in its transaction or its cache only).
	 *
	 * @param string|null $was stored_state() before.
	 * @return void
	 */
	private static function restore_state( $was ): void {
		global $wpdb;
		if ( self::stored_state() === $was ) {
			return;
		}
		if ( is_multisite() ) {
			$where = array(
				'meta_key' => Directories::OPTION, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one row of the test's.
				'site_id'  => get_current_network_id(),
			);
			null === $was ? $wpdb->delete( $wpdb->sitemeta, $where ) : $wpdb->update( $wpdb->sitemeta, array( 'meta_value' => $was ), $where ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
		} else {
			$where = array( 'option_name' => Directories::OPTION );
			null === $was ? $wpdb->delete( $wpdb->options, $where ) : $wpdb->update( $wpdb->options, array( 'option_value' => $was ), $where );
		}
		$wpdb->query( 'COMMIT' );
	}

	public function test_with_the_loader_up_and_the_maintenance_file_deleted_wp_cli_shows_and_runs_a_job_as_ever(): void {
		global $wpdb;
		$mark    = Maintenance::new_mark();
		$id      = 0;
		$storage = StorageDirs::listing();
		$state   = self::stored_state();
		ActivePlugin::on();
		try {
			$this->held( $mark, false, false ); // Held by its data file; .maintenance deleted.
			RealMuPlugins::put();
			$this->assertTrue( ( new Maintenance( rtrim( ABSPATH, '/' ), $mark ) )->left_held() );

			// The control: in this state the loader holds the web server's requests (it is loaded, and it answers).
			$held = $this->at_once( 1 );
			$this->assertSame( 503, $held[0]['code'], substr( $held[0]['body'], 0, 300 ) );
			$this->assertStringContainsString( 'Briefly unavailable for scheduled maintenance', $held[0]['body'] );
			$this->assertStringContainsString( 'No job holds the site with this maintenance file', self::runtime_log(), 'and ran the runtime' );

			// A job of the WP-CLI processes' own making (their storage directory is the one the database names, which
			// need not be this process's): an estimate, which changes nothing of the site.
			list( $status, $out, $err ) = $this->wp( 'eval', 'echo \WPCheckpoint\Plugin::instance()->jobs()->create( \WPCheckpoint\Jobs\EstimateJob::ID, 0 )->id;' );
			$this->assertSame( 0, $status, "WP-CLI runs with the loader up.\nstdout: {$out}\nstderr: {$err}" );
			$this->assertSame( 1, preg_match( '/\A[0-9]+\z/', trim( $out ) ), $out . $err );
			$id = (int) trim( $out );

			list( $status, $out, $err ) = $this->wp( 'wpcheckpoint', 'job', 'status', (string) $id, '--format=json' );
			$this->assertSame( 0, $status, "job status runs.\nstdout: {$out}\nstderr: {$err}" );
			$shown = json_decode( $out, true );
			$this->assertIsArray( $shown, $out );
			$this->assertStringContainsString( Job::QUEUED, (string) wp_json_encode( $shown ), 'it shows the job as it is' );
			$this->assertStringNotContainsString( 'Briefly unavailable', $out . $err, 'the loader said nothing on the command line' );

			list( $status, $out, $err ) = $this->wp( 'wpcheckpoint', 'job', 'run', (string) $id, '--wait' );
			$this->assertSame( 0, $status, "job run runs.\nstdout: {$out}\nstderr: {$err}" );
			$this->assertStringNotContainsString( 'Briefly unavailable', $out . $err );
			$wpdb->query( 'COMMIT' );
			$this->assertSame( Job::COMPLETED, $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . JobRepository::table() . ' WHERE id = %d', $id ) ), "the job ran to its end.\nstdout: {$out}\nstderr: {$err}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the jobs table.
			$this->assertSame( array( ContentLeftovers::mu_dir() . '/' . Loader::NAME ), RealMuPlugins::listing(), 'the control: the loader was there all along' );
		} finally {
			if ( $id > 0 ) {
				$wpdb->delete( JobRepository::table(), array( 'id' => $id ) );
			}
			delete_option( Estimate::OPTION );
			delete_site_option( Estimate::OPTION );
			$wpdb->query( 'COMMIT' );
			self::restore_state( $state );
			StorageDirs::remove_made_since( $storage );
			ActivePlugin::off();
		}
	}
}
