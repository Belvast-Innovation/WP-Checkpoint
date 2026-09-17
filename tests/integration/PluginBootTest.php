<?php
/**
 * Verifies that the plugin loads inside a real WordPress install.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Plugin;

/**
 * Smoke test for the integration environment.
 */
final class PluginBootTest extends WP_UnitTestCase {

	/**
	 * The plugin constants are defined once WordPress has loaded the plugin.
	 *
	 * @return void
	 */
	public function test_plugin_constants_are_defined(): void {
		$this->assertTrue( defined( 'WPCHECKPOINT_VERSION' ) );
		$this->assertSame( dirname( __DIR__, 2 ) . '/wp-checkpoint.php', WPCHECKPOINT_FILE );
		$this->assertSame( trailingslashit( dirname( __DIR__, 2 ) ), WPCHECKPOINT_DIR );
	}

	/**
	 * The plugin booted on plugins_loaded and registered its init hook.
	 *
	 * @return void
	 */
	public function test_plugin_booted(): void {
		$plugin = Plugin::instance();

		$this->assertSame( $plugin, Plugin::instance() );
		$this->assertSame( 10, has_action( 'init', array( $plugin, 'load_textdomain' ) ) );
	}

	/**
	 * The classes in src/ are found by the plugin's own autoloader.
	 *
	 * @return void
	 */
	public function test_autoloader_resolves_namespace(): void {
		$this->assertTrue( class_exists( Plugin::class ) );
		$this->assertFalse( class_exists( 'WPCheckpoint\\DoesNotExist' ) );
	}
}
