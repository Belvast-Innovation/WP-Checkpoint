<?php
/**
 * LinkedTargets::judge() in a process of its own, so a test can run it under an open_basedir restriction (as Plesk,
 * ISPConfig and many DirectAdmin hosts set it). Reads the cases from the JSON file given, prints each verdict.
 *
 * Usage: php judge-child.php <cases.json> [<open_basedir>]. The restriction is set here, before anything is loaded,
 * rather than with -d: on Windows its separator ";" would start a comment in the -d value (an INI line).
 * Cases: [{"given": "...", "zones": ["...", ...]}, ...], or "zone_args": [abspath, trusted, config_dir] in place of "zones"
 * to have the zones found under the restriction too (LinkedTargets::zones(), no directory taken as a home). Prints JSON: ["site"|"outside"|"installation"|"unknown", ...].
 *
 * @package WPCheckpoint
 */

// phpcs:disable -- a test fixture run as a script.

if ( isset( $argv[2] ) && ( false === ini_set( 'open_basedir', $argv[2] ) || ini_get( 'open_basedir' ) !== $argv[2] ) ) {
	fwrite( STDERR, "open_basedir not set\n" );
	exit( 3 );
}
require dirname( __DIR__, 3 ) . '/vendor/autoload.php';
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 3 ) . '/build/unit-abspath/' );
}

$cases = json_decode( (string) file_get_contents( $argv[1] ), true );
if ( ! is_array( $cases ) ) {
	fwrite( STDERR, "no cases\n" );
	exit( 2 );
}
$out = array();
foreach ( $cases as $case ) {
	$zones = isset( $case['zone_args'] )
		? \WPCheckpoint\Restore\LinkedTargets::zones( ...array_merge( array_map( 'strval', (array) $case['zone_args'] ), array( static function (): bool {
			return false;
		} ) ) )
		: array_map( 'strval', (array) $case['zones'] );
	$out[] = \WPCheckpoint\Restore\LinkedTargets::judge( (string) $case['given'], $zones )['verdict'];
}
echo json_encode( $out );
exit( 0 );
