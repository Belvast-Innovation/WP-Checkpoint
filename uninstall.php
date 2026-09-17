<?php
/**
 * Uninstall handler.
 *
 * Runs after the plugin has been deactivated and its main file is no longer
 * loaded, so the autoloader is defined here independently of the main file.
 * Backups are user data: they are only deleted when the user opted in on the
 * Settings tab (option wpcheckpoint_delete_data_on_uninstall).
 *
 * @package WPCheckpoint
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'WPCheckpoint\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

WPCheckpoint\Support\Uninstaller::run();
