<?php
/**
 * Paths::positively_gone() in a process of its own, so a test can run it under an open_basedir restriction.
 *
 * Usage: php gone-child.php <cases.json> [<open_basedir>]. The restriction is set here, before anything is loaded,
 * rather than with -d: on Windows its separator ";" would start a comment in the -d value (an INI line). The cases
 * file is read before it is set, so it may lie anywhere. Cases: {"label": "path", ...}. Prints JSON:
 * {"open_basedir": the restriction in effect, "gone": {"label": true|false, ...}}.
 *
 * @package WPCheckpoint
 */

// phpcs:disable -- a test fixture run as a script.

$cases = json_decode( (string) file_get_contents( $argv[1] ), true );
if ( ! is_array( $cases ) ) {
	fwrite( STDERR, "no cases\n" );
	exit( 2 );
}
require dirname( __DIR__, 3 ) . '/src/Support/Paths.php';
if ( isset( $argv[2] ) && ( '' === $argv[2] || false === ini_set( 'open_basedir', $argv[2] ) || ini_get( 'open_basedir' ) !== $argv[2] ) ) {
	fwrite( STDERR, "open_basedir not set\n" );
	exit( 3 );
}
$gone = array();
foreach ( $cases as $label => $path ) {
	$gone[ $label ] = \WPCheckpoint\Support\Paths::positively_gone( (string) $path );
}
echo json_encode(
	array(
		'open_basedir' => (string) ini_get( 'open_basedir' ),
		'gone'         => $gone,
	)
), "\n";
