<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Tests\Fixtures\ActivePlugin;
use WPCheckpoint\Tests\Fixtures\RealAbspath;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use WP_UnitTestCase;

/**
 * A request the maintenance file lets through for a restore's checks runs no cron, on a site with ALTERNATE_WP_CRON
 * (where WordPress runs the due events in the request itself): the file's code defines DISABLE_WP_CRON, and, that
 * layer taken away (DISABLE_WP_CRON already false), the plugin has no cron job be ready. The control: the same request
 * once the site is not held runs the due event.
 *
 * Each request is a PHP process of its own that loads WordPress as the web server does (request-child.php: the test
 * site's tables and active plugins, with the constants the test site's wp-config.php does not define), so that a
 * constant defined there does not stay in the suite. The maintenance file is put in the real ABSPATH through RealAbspath
 * (the registered exception); the plugin is made active in the test site's tables for the test (ActivePlugin).
 */
final class MaintenancePassCronTest extends WP_UnitTestCase {

	const HOOK = 'wpcheckpoint_test_pass_cron';

	/** @var string A sandbox for the requests' configurations. */
	private $dir = '';

	public function set_up(): void {
		parent::set_up();
		RealAbspath::claim();
		$this->dir = Sandbox::make( 'pass-cron' );
	}

	public function tear_down(): void {
		if ( '' !== $this->dir ) {
			Sandbox::remove( $this->dir );
		}
		parent::tear_down();
	}

	/**
	 * One request for the home page in a process of its own.
	 *
	 * @param string|null          $key       The pass header, or none.
	 * @param array<string, mixed> $constants Constants defined before WordPress loads.
	 * @param string               $query     More of the query string ("&name=value…").
	 * @return array{loaded: bool, disable: mixed}
	 */
	private function request( $key, array $constants, string $query = '' ): array {
		$config = $this->dir . '/request-' . bin2hex( random_bytes( 4 ) ) . '.json';
		file_put_contents(
			$config,
			(string) wp_json_encode(
				array(
					'abspath'   => ABSPATH,
					'host'      => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
					'uri'       => '/?wpcheckpoint_nocache=' . bin2hex( random_bytes( 4 ) ) . $query,
					'headers'   => null === $key ? array() : array( Maintenance::PASS_HEADER => $key ),
					'constants' => $constants + array( 'ALTERNATE_WP_CRON' => true, 'WP_HTTP_BLOCK_EXTERNAL' => true ),
				)
			)
		);
		$pipes   = array();
		$process = proc_open( array( PHP_BINARY, dirname( __DIR__ ) . '/Fixtures/Restore/request-child.php', $config ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		$out    = (string) stream_get_contents( $pipes[1] );
		$err    = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$status = proc_close( $process );
		$this->assertSame( 1, preg_match( '/^WPCHECKPOINT-REQUEST: (\{.*\})$/m', $out, $found ), "the request said what it got to.\nexit: {$status}\nstdout: {$out}\nstderr: {$err}" );
		return json_decode( $found[1], true );
	}

	/**
	 * Whether the event is still scheduled (WordPress takes it off before it runs it), read past any cache.
	 *
	 * @return bool
	 */
	private function scheduled(): bool {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		return false !== strpos( (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'cron'" ), self::HOOK );
	}

	public function test_a_request_let_through_runs_no_cron_on_a_site_with_alternate_wp_cron(): void {
		global $wpdb;
		$key  = bin2hex( random_bytes( 32 ) );
		$file = new Maintenance( ABSPATH, Maintenance::new_mark() );
		ActivePlugin::on();
		try {
			wp_schedule_single_event( time() - 60, self::HOOK );
			$wpdb->query( 'COMMIT' );
			$this->assertTrue( $this->scheduled(), 'the control: it is due' );
			RealAbspath::write( Maintenance::DATA, $file->data( Maintenance::held(), hash( 'sha256', $key ) ) );
			RealAbspath::write( Maintenance::FILE, Maintenance::CODE );

			$run = $this->request( null, array( 'DISABLE_WP_CRON' => false ) );
			$this->assertFalse( $run['loaded'], 'without the header: the maintenance page' );
			$this->assertTrue( $this->scheduled() );

			$run = $this->request( $key, array() );
			$this->assertTrue( $run['loaded'], 'let through' );
			$this->assertTrue( $run['disable'], 'the file\'s code defined DISABLE_WP_CRON' );
			$this->assertTrue( $this->scheduled(), 'no event ran' );

			$run = $this->request( $key, array( 'DISABLE_WP_CRON' => false ) );
			$this->assertTrue( $run['loaded'], 'let through' );
			$this->assertFalse( $run['disable'], 'that layer taken away' );
			$this->assertTrue( $this->scheduled(), 'the plugin\'s layer: no event ran' );

			// The control: once the site is not held, the same request runs the due event in itself.
			RealAbspath::remove( Maintenance::FILE );
			RealAbspath::remove( Maintenance::DATA );
			$run = $this->request( null, array( 'DISABLE_WP_CRON' => false ) );
			$this->assertTrue( $run['loaded'] );
			$this->assertFalse( $this->scheduled(), 'the event ran in the request' );
		} finally {
			foreach ( RealAbspath::listing() as $left ) {
				RealAbspath::remove( basename( $left ) );
			}
			wp_clear_scheduled_hook( self::HOOK );
			ActivePlugin::off();
		}
	}

	public function test_wordpresss_check_for_fatal_errors_after_an_edit_does_not_get_past_the_file(): void {
		$scrape = static function ( int $time ): string {
			return '&wp_scrape_key=' . md5( (string) $time ) . '&wp_scrape_nonce=' . $time;
		};
		$file  = new Maintenance( ABSPATH, Maintenance::new_mark() );
		$quiet = array(
			'ALTERNATE_WP_CRON' => false,
			'DISABLE_WP_CRON'   => true, // A request let through runs no cron in the test site's tables.
		);
		try {
			RealAbspath::write( Maintenance::DATA, $file->data( Maintenance::held() ) );
			RealAbspath::write( Maintenance::FILE, Maintenance::CODE );
			$this->assertFalse( $this->request( null, $quiet, $scrape( Maintenance::held() ) )['loaded'], 'held: the time is public, and it does not let the request through' );
			RealAbspath::remove( Maintenance::DATA ); // The code with no data file: blocked with the time now.
			$now = time();
			foreach ( range( $now - 1, $now + 3 ) as $time ) {
				$this->assertFalse( $this->request( null, $quiet, $scrape( $time ) )['loaded'], 'no data file: the time now does not let it through either' );
			}
			// The control: the single file #82 wrote holds an integer time, and WordPress lets the same request through
			// it (the parameters reach WordPress; that form's known limit).
			RealAbspath::remove( Maintenance::FILE );
			RealAbspath::write( Maintenance::FILE, $file->legacy( Maintenance::held() ) );
			$this->assertTrue( $this->request( null, $quiet, $scrape( Maintenance::held() ) )['loaded'], 'the single file: let through' );
			$this->assertFalse( $this->request( null, $quiet )['loaded'], 'and without the parameters, blocked' );
		} finally {
			foreach ( RealAbspath::listing() as $left ) {
				RealAbspath::remove( basename( $left ) );
			}
		}
	}
}
