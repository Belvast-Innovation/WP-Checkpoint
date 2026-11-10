<?php
/**
 * One request WordPress would make of the maintenance file, in a process of its own: what wp-config.php gives (ABSPATH,
 * the database constants, $table_prefix, MULTISITE on a network) and nothing else: no WordPress, no autoloader, no
 * class of this plugin's but what the runtime next to the maintenance file declares. It requires ABSPATH/.maintenance
 * as wp_is_maintenance_mode() does ($upgrading global, in a function's scope), and prints one JSON line: $upgrading and
 * its type, whether WordPress or an autoloader was there at the end (they must never be), and whether the runtime's
 * entry class was declared. A fatal error prints CODE-CHILD-FATAL and its message.
 *
 * Usage: php code-child.php <config.json>. Config: abspath, db (name, user, password, host, prefix), multisite, host.
 *
 * @package WPCheckpoint
 */

// phpcs:disable -- a test fixture run as a script.

$config = json_decode( (string) file_get_contents( $argv[1] ), true );
if ( ! is_array( $config ) ) {
	fwrite( STDERR, "no config\n" );
	exit( 2 );
}

register_shutdown_function(
	static function (): void {
		$error = error_get_last();
		if ( null !== $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
			fwrite( STDOUT, 'CODE-CHILD-FATAL ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'] . "\n" );
		}
	}
);

// What wp-config.php defines.
define( 'ABSPATH', rtrim( (string) $config['abspath'], '/' ) . '/' );
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

$upgrading = null;
( static function (): void {
	global $upgrading;
	require ABSPATH . '.maintenance';
} )();

echo json_encode(
	array(
		'upgrading'  => $upgrading,
		'type'       => gettype( $upgrading ),
		'wordpress'  => array_values( array_filter( array( 'add_action', '__', 'apply_filters', 'is_multisite', 'get_option' ), 'function_exists' ) ),
		'autoloader' => false !== spl_autoload_functions() && array() !== spl_autoload_functions(),
		'entry'      => class_exists( 'WPCheckpoint\Jobs\WebEntry', false ),
	)
) . "\n";
exit( 0 );
