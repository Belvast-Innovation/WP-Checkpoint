<?php
/**
 * One request WordPress would make of the maintenance file, in a process of its own: what wp-config.php gives (ABSPATH,
 * the database constants, $table_prefix, MULTISITE on a network) and nothing else: no WordPress, no autoloader, no
 * class of this plugin's but what the runtime next to the maintenance file declares. It requires ABSPATH/.maintenance
 * as wp_is_maintenance_mode() does ($upgrading global, in a function's scope), and prints one JSON line: $upgrading and
 * its type, whether WordPress or an autoloader was there at the end (they must never be), and whether the runtime's
 * entry class was declared. A fatal error prints CODE-CHILD-FATAL and its message.
 *
 * WP_CONTENT_DIR is defined before the maintenance file, as WordPress defines it ("content"). A request the maintenance
 * file lets through (as WordPress judges it: its time ten minutes ago or more) goes on as WordPress does, as far as
 * the must-use plugins: the object cache's flush is there by then (a stand-in that writes a byte to "flushed" each
 * time it is called, and throws while the file "flush_fails" is there), and each .php file of the must-use plugins
 * directory is included in the order WordPress sorts them. The JSON line then says "site": the request got past them
 * (run by php-cgi, the swap's loader ends it before with its own answer, and no JSON line is printed).
 *
 * Usage: php code-child.php <config.json>. Config: abspath, content, db (name, user, password, host, prefix),
 * multisite, host, flushed, flush_fails, pass (the request's X-WPCheckpoint-Pass header). Without a maintenance file the
 * request goes on to the must-use plugins as well.
 *
 * @package WPCheckpoint
 */

// phpcs:disable -- a test fixture run as a script.

$config = json_decode( (string) file_get_contents( $argv[1] ), true );
if ( ! is_array( $config ) ) {
	error_log( 'no config' );
	exit( 2 );
}

register_shutdown_function(
	static function (): void {
		$error = error_get_last();
		if ( null !== $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
			echo 'CODE-CHILD-FATAL ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'] . "\n"; // Not STDOUT: php-cgi has no such constant.
		}
	}
);

// What wp-config.php defines.
define( 'ABSPATH', rtrim( (string) $config['abspath'], '/' ) . '/' );
if ( isset( $config['content'] ) ) {
	define( 'WP_CONTENT_DIR', (string) $config['content'] ); // wp_initial_constants(), before the maintenance file.
}
define( 'DB_NAME', (string) $config['db']['name'] );
define( 'DB_USER', (string) $config['db']['user'] );
define( 'DB_PASSWORD', (string) $config['db']['password'] );
define( 'DB_HOST', (string) $config['db']['host'] );
$table_prefix = (string) $config['db']['prefix'];
if ( ! empty( $config['multisite'] ) ) {
	define( 'MULTISITE', true );
}
$_SERVER['HTTP_HOST']          = (string) ( $config['host'] ?? '' );
$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );
if ( isset( $config['pass'] ) ) {
	$_SERVER['HTTP_X_WPCHECKPOINT_PASS'] = (string) $config['pass'];
}

$upgrading = null;
$held = file_exists( ABSPATH . '.maintenance' ); // wp_is_maintenance_mode(): no file, no maintenance mode.
if ( $held ) {
	( static function (): void {
		global $upgrading;
		require ABSPATH . '.maintenance';
	} )();
}

$site = false;
if ( ! $held || ( is_numeric( $upgrading ) && ( time() - $upgrading ) >= 600 ) ) {
	// Not in maintenance mode, as wp_is_maintenance_mode() judges it: WordPress goes on to its must-use plugins.
	if ( isset( $config['flushed'] ) ) {
		function wp_cache_flush() {
			global $config;
			if ( isset( $config['flush_fails'] ) && file_exists( (string) $config['flush_fails'] ) ) {
				throw new RuntimeException( 'The object cache\'s server does not answer.' );
			}
			file_put_contents( (string) $config['flushed'], 'x', FILE_APPEND );
			return true;
		}
	}
	$mu    = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content' ) . '/mu-plugins';
	$names = array();
	foreach ( is_dir( $mu ) ? (array) scandir( $mu ) : array() as $name ) {
		if ( '.php' === substr( (string) $name, -4 ) ) {
			$names[] = $mu . '/' . $name;
		}
	}
	sort( $names ); // wp_get_mu_plugins().
	foreach ( $names as $file ) {
		include_once $file;
	}
	$site = true;
}

echo json_encode(
	array(
		'site'       => $site,
		'sapi'       => PHP_SAPI,
		'flush_pass' => defined( 'WPCHECKPOINT_FLUSH_PASS' ),
		'upgrading'  => $upgrading,
		'type'       => gettype( $upgrading ),
		'wordpress'  => array_values( array_filter( array( 'add_action', '__', 'apply_filters', 'is_multisite', 'get_option' ), 'function_exists' ) ),
		'autoloader' => false !== spl_autoload_functions() && array() !== spl_autoload_functions(),
		'entry'      => class_exists( 'WPCheckpoint\Jobs\WebEntry', false ),
	)
) . "\n";
exit( 0 );
