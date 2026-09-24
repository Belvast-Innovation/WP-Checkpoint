<?php
/**
 * Plugin Name:       WP Checkpoint
 * Plugin URI:        https://wpcheckpoint.com
 * Description:       Backup, migration and safe updates. Restores are always free, every change can be undone.
 * Version:           0.1.0-dev
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Belvast
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-checkpoint
 * Domain Path:       /languages
 * Network:           true
 *
 * @package WPCheckpoint
 */

defined( 'ABSPATH' ) || exit;

define( 'WPCHECKPOINT_VERSION', '0.1.0-dev' );
define( 'WPCHECKPOINT_FILE', __FILE__ );
define( 'WPCHECKPOINT_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPCHECKPOINT_URL', plugin_dir_url( __FILE__ ) );

/*
 * Minimal PSR-4 autoloader. The plugin ships with zero runtime Composer dependencies.
 */
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'WPCheckpoint\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = WPCHECKPOINT_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'WPCheckpoint\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPCheckpoint\\Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		WPCheckpoint\Plugin::instance()->boot();
	}
);
