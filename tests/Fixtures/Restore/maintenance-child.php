<?php
/**
 * One request as far as WordPress takes it to its maintenance file, in a process of its own with nothing of WordPress:
 * ABSPATH and what else the test defines (WP_CONTENT_DIR, WPMU_PLUGIN_DIR), then ABSPATH/.maintenance required as
 * wp_is_maintenance_mode() does ($upgrading global, in a function's scope). Prints one JSON line: $upgrading and its
 * type, and which constants the maintenance file's code defined. With "gate" it waits for that file first (up to ten
 * seconds), so that several of these start their request at once.
 *
 * With "shutdown" it is a request that began before the site was held: it loads the classes "preload" names by the
 * autoloader "autoload" names (as the plugin, loaded, would have), and requires the maintenance file for the first time
 * in a shutdown function, as WordPress's fatal error handler does when a request ends; a shutdown function registered
 * after that one prints the JSON line (so the line says that later shutdown functions still ran).
 *
 * Usage: php maintenance-child.php <config.json>. Config: abspath, define (name => value), gate, past_mu, again,
 * shutdown, autoload, preload.
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
if ( ! empty( $config['past_mu'] ) ) {
	// As in a request that is past its must-use plugins (it began before the site was held).
	function did_action( $hook ) {
		return 'muplugins_loaded' === $hook ? 1 : 0;
	}
}
if ( isset( $config['gate'] ) ) {
	$until = microtime( true ) + 10;
	while ( ! file_exists( (string) $config['gate'] ) && microtime( true ) < $until ) {
		usleep( 1000 );
		clearstatcache( true, (string) $config['gate'] );
	}
}
if ( ! empty( $config['shutdown'] ) ) {
	if ( isset( $config['autoload'] ) ) {
		require (string) $config['autoload'];
	}
	foreach ( (array) ( $config['preload'] ?? array() ) as $class ) {
		if ( ! class_exists( (string) $class ) ) {
			fwrite( STDERR, "no class {$class}\n" );
			exit( 2 );
		}
	}
	$upgrading = null;
	register_shutdown_function(
		static function (): void {
			global $upgrading;
			require ABSPATH . '.maintenance';
		}
	);
	register_shutdown_function(
		static function (): void {
			global $upgrading;
			echo json_encode(
				array(
					'later'     => true,
					'upgrading' => $upgrading,
					'type'      => gettype( $upgrading ),
					'entry'     => defined( 'WPCHECKPOINT_WEB_ENTRY' ),
					'web_entry' => class_exists( 'WPCheckpoint\\Jobs\\WebEntry', false ),
					'flush'     => defined( 'WPCHECKPOINT_FLUSH_PASS' ),
					'cron'      => defined( 'DISABLE_WP_CRON' ),
					'sapi'      => PHP_SAPI,
				)
			) . "\n";
		}
	);
	return;
}
$upgrading = null;
( static function (): void {
	global $upgrading;
	require ABSPATH . '.maintenance';
} )();
$again = null;
$first = null;
if ( isset( $config['again'] ) ) {
	// As WordPress's shutdown handler does when the request ends: the file is required once more.
	$first     = array( $upgrading, defined( 'WPCHECKPOINT_FLUSH_PASS' ) );
	usleep( (int) ( 1000000 * (float) $config['again'] ) );
	$upgrading = null;
	( static function (): void {
		global $upgrading;
		require ABSPATH . '.maintenance';
	} )();
	$again     = array(
		'upgrading' => $upgrading,
		'flush'     => defined( 'WPCHECKPOINT_FLUSH_PASS' ),
	);
	$upgrading = $first[0];
}
echo json_encode(
	array(
		'again'     => $again,
		'upgrading' => $upgrading,
		'type'      => gettype( $upgrading ),
		'cron'      => defined( 'DISABLE_WP_CRON' ),
		'flush'     => null === $first ? defined( 'WPCHECKPOINT_FLUSH_PASS' ) : $first[1],
		'entry'     => defined( 'WPCHECKPOINT_WEB_ENTRY' ),
		'sapi'      => PHP_SAPI,
	)
) . "\n";
exit( 0 );
