<?php
/**
 * LinkedTargets::judge() in a process of its own, so a test can run it under an open_basedir restriction (as Plesk,
 * ISPConfig and many DirectAdmin hosts set it). Reads the cases from the JSON file given, prints each verdict.
 *
 * Usage: php -d open_basedir=... judge-child.php <cases.json>
 * Cases: [{"given": "...", "zones": ["...", ...]}, ...]. Prints JSON: ["site"|"outside"|"installation"|"unknown", ...].
 *
 * @package WPCheckpoint
 */

// phpcs:disable -- a test fixture run as a script.

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
	$out[] = \WPCheckpoint\Restore\LinkedTargets::judge( (string) $case['given'], array_map( 'strval', (array) $case['zones'] ) )['verdict'];
}
echo json_encode( $out );
exit( 0 );
