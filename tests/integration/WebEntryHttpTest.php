<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Jobs\WebRuntime;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Tests\Fixtures\RealAbspath;

/**
 * The maintenance file's code and the runtime through the test site's web server (registered exception: CLAUDE.md;
 * every write and deletion in its real ABSPATH through RealAbspath): a held request runs the runtime, whose entry
 * connects with wp-config.php's settings (here it finds no job with the file's mark, and says so in the runtime log),
 * sets the visitor file's time on first, and ends with the maintenance page (503, Retry-After); a request within that
 * time does not run it; a runtime of another build does nothing but say so; the runtime, the visitor file and the
 * runtime log requested directly say nothing.
 */
final class WebEntryHttpTest extends WP_UnitTestCase {

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
		$index        = wp_remote_get( $this->host . '/?rest_route=/', array( 'timeout' => 20 ) );
		$home         = (string) ( json_decode( (string) wp_remote_retrieve_body( $index ), true )['home'] ?? '' );
		$this->header = (string) wp_parse_url( $home, PHP_URL_HOST ) . ( null !== wp_parse_url( $home, PHP_URL_PORT ) ? ':' . (int) wp_parse_url( $home, PHP_URL_PORT ) : '' );
		$this->assertNotSame( '', $this->header, 'the web server\'s WordPress says its address' );
	}

	public function tear_down(): void {
		foreach ( RealAbspath::listing() as $left ) {
			RealAbspath::remove( basename( $left ) );
		}
		parent::tear_down();
	}

	/**
	 * A request to the web server.
	 *
	 * @param string $path Path and query.
	 * @return array{code: int, body: string, retry: string}
	 */
	private function get( string $path ): array {
		$response = wp_remote_get(
			$this->host . $path,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => array( 'Host' => $this->header ),
			)
		);
		$this->assertNotWPError( $response );
		return array(
			'code'  => (int) wp_remote_retrieve_response_code( $response ),
			'body'  => (string) wp_remote_retrieve_body( $response ),
			'retry' => (string) wp_remote_retrieve_header( $response, 'retry-after' ),
		);
	}

	/**
	 * Put up a held maintenance file of a restore no job has, naming $hash, its runtime and its visitor file (its time
	 * past).
	 *
	 * @param string $mark Mark.
	 * @param string $hash The hash the data file names.
	 * @return string The runtime's own hash.
	 */
	private function held( string $mark, string $hash = '' ): string {
		$built = WebRuntime::build( $mark );
		RealAbspath::write( Maintenance::RUNTIME, $built['code'] );
		RealAbspath::write( Maintenance::VISITOR, "<?php exit; ?>\n# " . $mark . "\n{\"plugin\":\"wp-checkpoint\\/wp-checkpoint.php\"}\n" );
		RealAbspath::touch( Maintenance::VISITOR, time() - 60 );
		RealAbspath::write( Maintenance::DATA, ( new Maintenance( ABSPATH, $mark ) )->data( Maintenance::held(), Maintenance::NO_PASS, '' === $hash ? $built['hash'] : $hash ) );
		RealAbspath::write( Maintenance::FILE, Maintenance::CODE );
		return $built['hash'];
	}

	/**
	 * The runtime log as it is ('' when there is none).
	 *
	 * @return string
	 */
	private static function runtime_log(): string {
		clearstatcache();
		$path = rtrim( ABSPATH, '/' ) . '/' . Maintenance::LOG;
		return is_file( $path ) ? (string) file_get_contents( $path ) : '';
	}

	public function test_a_held_request_runs_the_runtime_once_in_its_time_and_ends_with_the_maintenance_page(): void {
		$mark = Maintenance::new_mark();
		$this->held( $mark );
		$this->assertSame( '', self::runtime_log(), 'the control: no log before' );
		$before = time();
		$first  = $this->get( '/?wpcheckpoint_nocache=' . bin2hex( random_bytes( 4 ) ) );
		$this->assertSame( 503, $first['code'], 'the maintenance page' );
		$this->assertNotSame( '', $first['retry'], 'with Retry-After' );
		$log = self::runtime_log();
		$this->assertStringContainsString( 'No job holds the site with this maintenance file', $log, 'the runtime ran, connected with wp-config.php\'s settings and found no job of this mark' );
		$this->assertSame( $mark, Maintenance::web_mark( rtrim( ABSPATH, '/' ) . '/' . Maintenance::LOG ), 'the log names the restore' );
		clearstatcache();
		$this->assertGreaterThanOrEqual( $before + 5, filemtime( rtrim( ABSPATH, '/' ) . '/' . Maintenance::VISITOR ), 'the visitor file\'s time set on' );

		$second = $this->get( '/?wpcheckpoint_nocache=' . bin2hex( random_bytes( 4 ) ) );
		$this->assertSame( 503, $second['code'] );
		$this->assertSame( $log, self::runtime_log(), 'within its time: the runtime did not run again' );

		RealAbspath::touch( Maintenance::VISITOR, time() - 1 );
		$this->get( '/?wpcheckpoint_nocache=' . bin2hex( random_bytes( 4 ) ) );
		$this->assertSame( 2, substr_count( self::runtime_log(), 'No job holds the site with this maintenance file' ), 'its time come again: it ran again' );
	}

	public function test_a_runtime_of_another_build_does_nothing_but_say_so(): void {
		$this->held( Maintenance::new_mark(), str_repeat( 'a', 64 ) );
		$out = $this->get( '/?wpcheckpoint_nocache=' . bin2hex( random_bytes( 4 ) ) );
		$this->assertSame( 503, $out['code'] );
		$log = self::runtime_log();
		$this->assertStringContainsString( 'The runtime here is not the one the restore wrote', $log );
		$this->assertStringNotContainsString( 'No job holds the site', $log, 'nothing more was done' );
	}

	public function test_the_runtime_the_visitor_file_and_the_log_requested_directly_say_nothing(): void {
		$mark = Maintenance::new_mark();
		$hash = $this->held( $mark );
		$this->get( '/?wpcheckpoint_nocache=' . bin2hex( random_bytes( 4 ) ) );
		$this->assertNotSame( '', self::runtime_log(), 'the control: the log is there' );
		foreach ( array( Maintenance::RUNTIME, Maintenance::VISITOR, Maintenance::LOG, Maintenance::DATA ) as $name ) {
			$out = $this->get( '/' . $name );
			$this->assertStringNotContainsString( substr( $mark, -32 ), $out['body'], $name . ' says nothing of the restore' );
			$this->assertStringNotContainsString( $hash, $out['body'], $name . ' says nothing of the runtime' );
			$this->assertSame( '', trim( $out['body'] ), $name . ': ' . $out['code'] );
		}
	}
}
