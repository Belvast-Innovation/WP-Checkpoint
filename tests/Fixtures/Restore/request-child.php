<?php
/**
 * One request to the test site, made in a PHP process of its own from the command line: WordPress loaded the way the
 * web server loads it (wp-load.php, the site's own tables and active plugins), with the request's headers, and with
 * constants defined first that the test site's wp-config.php does not (ALTERNATE_WP_CRON, DISABLE_WP_CRON). Prints, last,
 * a line "WPCHECKPOINT-REQUEST: {json}" saying whether WordPress got as far as wp_loaded (a maintenance page stops it
 * before) and what DISABLE_WP_CRON is.
 *
 * Usage: php request-child.php <config.json> (abspath, host, uri, headers: name => value, constants: name => value)
 *
 * @package WPCheckpoint
 */

// phpcs:disable -- a test fixture run as a script.

$config = json_decode( (string) file_get_contents( $argv[1] ), true );
if ( ! is_array( $config ) ) {
	fwrite( STDERR, "no config\n" );
	exit( 2 );
}
$_SERVER['HTTP_HOST']       = (string) $config['host'];
$_SERVER['SERVER_NAME']     = (string) $config['host'];
$_SERVER['SERVER_PORT']     = '80';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REQUEST_URI']     = (string) $config['uri'];
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['QUERY_STRING']    = (string) parse_url( (string) $config['uri'], PHP_URL_QUERY );
parse_str( $_SERVER['QUERY_STRING'], $_GET );
$_REQUEST = $_GET;
foreach ( (array) ( $config['headers'] ?? array() ) as $name => $value ) {
	$_SERVER[ 'HTTP_' . strtoupper( str_replace( '-', '_', (string) $name ) ) ] = (string) $value;
}
foreach ( (array) ( $config['constants'] ?? array() ) as $name => $value ) {
	define( (string) $name, $value );
}
register_shutdown_function(
	static function (): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		$loaded = function_exists( 'did_action' ) && did_action( 'wp_loaded' ) > 0;
		echo "\nWPCHECKPOINT-REQUEST: " . json_encode(
			array(
				'loaded'  => $loaded,
				'disable' => defined( 'DISABLE_WP_CRON' ) ? DISABLE_WP_CRON : null,
				// What cron looked like at the end (WordPress loaded only): the events still due, the lock, the address.
				'due'     => $loaded ? array_keys( array_merge( array(), ...array_values( (array) wp_get_ready_cron_jobs() ) ) ) : null,
				'lock'    => $loaded ? get_transient( 'doing_cron' ) : null,
				'host'    => $loaded ? home_url() : null,
			)
		) . "\n";
	}
);
ob_start();
require rtrim( (string) $config['abspath'], '/' ) . '/wp-load.php';
