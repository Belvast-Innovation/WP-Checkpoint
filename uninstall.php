<?php
/**
 * Uninstall handler.
 *
 * Runs after the plugin has been deactivated and its main file is no longer
 * loaded, so constants and the autoloader are defined here independently.
 * Backups are user data: they are only deleted when the user opted in on the
 * Settings tab (option wpcheckpoint_delete_data_on_uninstall).
 *
 * @package WPCheckpoint
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'WPCHECKPOINT_DIR' ) ) {
	define( 'WPCHECKPOINT_DIR', plugin_dir_path( __FILE__ ) );
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'WPCheckpoint\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = WPCHECKPOINT_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

WPCheckpoint\Support\Uninstaller::run();
