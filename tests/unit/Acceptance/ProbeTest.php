<?php

namespace WPCheckpoint\Tests\Unit\Acceptance;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The acceptance probe (tests/acceptance/mu-plugin) is development tooling,
 * but someone may copy it onto a real site: there it must do nothing, and
 * where it writes must not be served.
 */
final class ProbeTest extends TestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		// Under the CLI the probe defines its functions and returns before doing anything.
		require_once dirname( __DIR__, 2 ) . '/acceptance/mu-plugin/wpcheckpoint-acceptance-probe.php';
	}

	public function test_it_runs_only_for_web_requests_of_a_local_or_development_site(): void {
		$this->assertTrue( wpcheckpoint_acceptance_should_run( 'apache2handler', 'local' ) );
		$this->assertTrue( wpcheckpoint_acceptance_should_run( 'fpm-fcgi', 'development' ) );
		$this->assertFalse( wpcheckpoint_acceptance_should_run( 'apache2handler', 'production' ) );
		$this->assertFalse( wpcheckpoint_acceptance_should_run( 'fpm-fcgi', 'staging' ) );
		$this->assertFalse( wpcheckpoint_acceptance_should_run( 'cli', 'local' ) );
	}

	public function test_its_directories_are_private_and_denied_to_the_web_server(): void {
		$dir = sys_get_temp_dir() . '/wpcheckpoint-probe-' . bin2hex( random_bytes( 4 ) ) . '/wpcheckpoint-acceptance';
		$this->assertTrue( wpcheckpoint_acceptance_prepare_dir( $dir ) );
		if ( '\\' !== DIRECTORY_SEPARATOR ) {
			$this->assertSame( 0700, fileperms( $dir ) & 0777 );
		}
		$this->assertFileExists( $dir . '/index.php' );
		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( $dir . '/.htaccess' ) );
		self::remove( dirname( $dir ) );
	}

	private static function remove( string $path ): void {
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			foreach ( scandir( $path ) ?: array() as $name ) {
				if ( '.' !== $name && '..' !== $name ) {
					self::remove( $path . DIRECTORY_SEPARATOR . $name );
				}
			}
			rmdir( $path );
			return;
		}
		unlink( $path );
	}
}
