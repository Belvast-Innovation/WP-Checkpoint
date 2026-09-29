<?php
/**
 * Run as its own PHP process (see FileScannerLinksTest), with or without
 * -d disable_functions=readlink: scans a sandbox whose uploads root is a
 * link (a symbolic link on POSIX, a junction on Windows) to a directory
 * holding a file and an empty directory, and prints what the scan saw as
 * one JSON object. The sandbox is made below the directory given as the
 * first argument and taken apart again by name (links first, as links);
 * nothing is removed recursively.
 *
 * @package WPCheckpoint
 */

require dirname( __DIR__, 2 ) . '/bootstrap.php';

use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Files\Exclusions;
use WPCheckpoint\Files\FileScanner;
use WPCheckpoint\Support\HostFunctions;

$base    = rtrim( (string) ( $argv[1] ?? '' ), '/\\' );
$windows = 'Windows' === PHP_OS_FAMILY;
if ( '' === $base || ! is_dir( $base ) ) {
	fwrite( STDERR, "Usage: php scan-links.php <empty directory>\n" );
	exit( 2 );
}
mkdir( $base . '/site' );
mkdir( $base . '/shared' );
mkdir( $base . '/shared/empty' );
file_put_contents( $base . '/shared/photo.jpg', 'x' );
if ( $windows ) {
	exec( 'cmd /c mklink /J "' . str_replace( '/', '\\', $base . '/uploads' ) . '" "' . str_replace( '/', '\\', $base . '/shared' ) . '" 2>&1', $output, $code );
} else {
	$code = symlink( $base . '/shared', $base . '/uploads' ) ? 0 : 1;
}
if ( 0 !== $code ) {
	fwrite( STDERR, "The link could not be made.\n" );
	exit( 3 );
}

$scanner = new FileScanner(
	array( array( 'group' => 'uploads', 'path' => $base . '/uploads', 'prefix' => 'wp-content/uploads' ) ),
	new Exclusions( array(), array() ),
	PHP_INT_SIZE,
	Manifest::DEFAULT_CHUNK,
	array( 'abspath' => $base . '/site' )
);
$lines = array();
$state = FileScanner::initial_state();
for ( $units = 0; empty( $state['done'] ) && $units < 100; $units++ ) {
	$state = $scanner->scan_unit(
		$state,
		static function ( array $line ) use ( &$lines ): void {
			$lines[] = $line['p'];
		}
	);
}
$out = array(
	'readlink'   => HostFunctions::available( 'readlink' ),
	'lines'      => $lines,
	'links'      => $state['counts']['links'],
	'undecided'  => $state['counts']['undecided'],
	'unreadable' => $state['lists']['unreadable'],
	'warnings'   => $state['warnings'],
);

// Taken apart by name: the link as a link, then what it led to.
if ( $windows ) {
	rmdir( $base . '/uploads' );
} else {
	unlink( $base . '/uploads' );
}
unlink( $base . '/shared/photo.jpg' );
rmdir( $base . '/shared/empty' );
rmdir( $base . '/shared' );
rmdir( $base . '/site' );
echo json_encode( $out, JSON_UNESCAPED_SLASHES ), "\n";
