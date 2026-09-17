<?php

namespace WPCheckpoint\Tests\Integration;

use ErrorException;
use WP_UnitTestCase;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Uninstaller;

/**
 * Activation and deactivation must not emit warnings, notices or output.
 */
final class ActivationTest extends WP_UnitTestCase {

	private const BASENAME = 'wp-checkpoint/wp-checkpoint.php';

	private function run_silently( callable $callback ) {
		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ): bool {
				throw new ErrorException( $message, 0, $severity, $file, $line );
			}
		);
		ob_start();
		try {
			$result = $callback();
		} finally {
			$output = ob_get_clean();
			restore_error_handler();
		}
		$this->assertSame( '', $output, 'Unexpected output during activation/deactivation' );
		return $result;
	}

	public function test_activation_hook_stores_version_without_warnings(): void {
		delete_option( Uninstaller::OPTION_VERSION );

		$this->run_silently( static function () {
			Plugin::activate();
			return null;
		} );

		$this->assertSame( WPCHECKPOINT_VERSION, get_option( Uninstaller::OPTION_VERSION ) );
	}

	public function test_deactivation_hook_keeps_options(): void {
		update_option( Uninstaller::OPTION_VERSION, WPCHECKPOINT_VERSION );
		update_option( Uninstaller::OPTION_DELETE_DATA, true );

		$this->run_silently( static function () {
			Plugin::deactivate();
			return null;
		} );

		$this->assertSame( WPCHECKPOINT_VERSION, get_option( Uninstaller::OPTION_VERSION ) );
		$this->assertTrue( (bool) get_option( Uninstaller::OPTION_DELETE_DATA ) );
	}

	public function test_activate_plugin_through_wordpress_is_silent(): void {
		$this->assertFileExists( WP_PLUGIN_DIR . '/' . self::BASENAME );

		$result = $this->run_silently( static function () {
			return activate_plugin( self::BASENAME );
		} );
		$this->assertNull( $result, 'activate_plugin() returned an error' );
		$this->assertTrue( is_plugin_active( self::BASENAME ) );

		$this->run_silently( static function () {
			deactivate_plugins( self::BASENAME );
			return null;
		} );
		$this->assertFalse( is_plugin_active( self::BASENAME ) );
	}
}
