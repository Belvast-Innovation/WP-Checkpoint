<?php
/**
 * One request as far as WordPress takes it to its maintenance file, in a process of its own with nothing of WordPress:
 * ABSPATH and what else the test defines (WP_CONTENT_DIR, WPMU_PLUGIN_DIR), then ABSPATH/.maintenance required as
 * wp_is_maintenance_mode() does ($upgrading global, in a function's scope). Prints one JSON line: $upgrading and its
 * type, and which constants the maintenance file's code defined. With "gate" it waits for that file first (up to ten
 * seconds), so that several of these start their request at once.
 *
 * Usage: php maintenance-child.php <config.json>. Config: abspath, define (name => value), gate.
 *
 * @package WPCheckpoint
 */

// phpcs:disable -- a test fixture run as a script.

$config = json_decode( (string) file_get_contents( $argv[1] ), true );
if ( ! is_array( $config ) ) {
	fwrite( STDERR, "no config\n" );
	exit( 2 );
}
define( 'ABSPATH', rtrim( (string) $config['abspath'], '/' ) . '/' );
foreach ( (array) ( $config['define'] ?? array() ) as $name => $value ) {
	define( (string) $name, $value );
}
if ( isset( $config['gate'] ) ) {
	$until = microtime( true ) + 10;
	while ( ! file_exists( (string) $config['gate'] ) && microtime( true ) < $until ) {
		usleep( 1000 );
		clearstatcache( true, (string) $config['gate'] );
	}
}
$upgrading = null;
( static function (): void {
	global $upgrading;
	require ABSPATH . '.maintenance';
} )();
echo json_encode(
	array(
		'upgrading' => $upgrading,
		'type'      => gettype( $upgrading ),
		'cron'      => defined( 'DISABLE_WP_CRON' ),
		'flush'     => defined( 'WPCHECKPOINT_FLUSH_PASS' ),
		'entry'     => defined( 'WPCHECKPOINT_WEB_ENTRY' ),
		'sapi'      => PHP_SAPI,
	)
) . "\n";
exit( 0 );
