<?php

namespace WPCheckpoint\Tests\Fixtures\Restore;

use WPCheckpoint\Tests\Fixtures\Sandbox;

/**
 * A small stand-in for the running copy of this plugin, which a restore stages into plugins: a development
 * checkout carries vendor/ and node_modules/, hundreds of megabytes a test restore should not copy each time.
 */
final class PluginCopy {

	/**
	 * The version its main file declares.
	 */
	const VERSION = '9.9.9-test';

	/**
	 * Make one: "{temp}/wpc-plugin-{random}/wp-checkpoint" with a main file, a class file and a readme.
	 *
	 * @return string The plugin directory.
	 */
	public static function make(): string {
		$dir = sys_get_temp_dir() . '/wpc-plugin-' . bin2hex( random_bytes( 4 ) ) . '/wp-checkpoint';
		mkdir( $dir . '/src', 0755, true );
		file_put_contents( $dir . '/wp-checkpoint.php', "<?php\n/**\n * Plugin Name:       WP Checkpoint\n * Version:           9.9.9-test\n * Text Domain:       wp-checkpoint\n */\ndefine( 'WPCHECKPOINT_VERSION', '9.9.9-test' );\n" );
		file_put_contents( $dir . '/src/Thing.php', "<?php\n// A class file of the stand-in.\n" );
		file_put_contents( $dir . '/readme.txt', "=== WP Checkpoint (test stand-in) ===\n" );
		touch( $dir . '/wp-checkpoint.php', 1700000000 );
		touch( $dir . '/src/Thing.php', 1700000100 );
		touch( $dir . '/readme.txt', 1700000200 );
		return $dir;
	}

	/**
	 * Remove one, with anything a test added to it. Only a directory of the shape make() returns, under the
	 * temporary directory (Sandbox::remove()): an empty or other path is refused before anything is touched.
	 *
	 * @param string $dir The plugin directory make() returned.
	 * @throws \LogicException When $dir is not one make() returns.
	 */
	public static function remove( string $dir ): void {
		$dir = rtrim( $dir, '/\\' );
		if ( 'wp-checkpoint' !== basename( $dir ) || 1 !== preg_match( '/\Awpc-plugin-[0-9a-f]{8}\z/', basename( dirname( $dir ) ) ) ) {
			throw new \LogicException( sprintf( 'PluginCopy::remove( %s ) refused: not a stand-in PluginCopy::make() returned. Nothing was deleted.', var_export( $dir, true ) ) );
		}
		Sandbox::remove( dirname( $dir ) );
	}
}
