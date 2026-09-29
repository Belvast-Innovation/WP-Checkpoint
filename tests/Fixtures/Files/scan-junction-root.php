<?php
/**
 * Windows only, run as its own PHP process (see FileScannerJunctionRootTest), with
 * -d disable_functions=readlink: scans a content root that is a junction, whose directories can only be judged
 * by where they resolve (one with a broken junction first: undecidable; one with a working junction first:
 * judged a link through it), and prints the archive paths as JSON. The second argument says what entries are
 * compared with: "real" (the root resolved, as the scanner does) or "listed" (the junction's own path, what the
 * test must show would lose them). The sandbox is made below the first argument and taken apart by name.
 *
 * @package WPCheckpoint
 */

require dirname( __DIR__, 2 ) . '/bootstrap.php';

use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Files\Exclusions;
use WPCheckpoint\Files\FileScanner;
use WPCheckpoint\Files\Links;
use WPCheckpoint\Support\HostFunctions;

$base = rtrim( (string) ( $argv[1] ?? '' ), '/\\' );
$mode = (string) ( $argv[2] ?? 'real' );
if ( 'Windows' !== PHP_OS_FAMILY || '' === $base || ! is_dir( $base ) ) {
	fwrite( STDERR, "Usage (Windows): php scan-junction-root.php <empty directory> real|listed\n" );
	exit( 2 );
}

/**
 * A junction at $link to the directory $target.
 *
 * @param string $target Target.
 * @param string $link   Junction.
 */
function wpc_junction( string $target, string $link ): void {
	exec( 'cmd /c mklink /J "' . str_replace( '/', '\\', $link ) . '" "' . str_replace( '/', '\\', $target ) . '" 2>&1', $output, $code );
	if ( 0 !== $code ) {
		fwrite( STDERR, 'mklink /J failed: ' . implode( ' ', $output ) . "\n" );
		exit( 3 );
	}
}

mkdir( $base . '/site' );
mkdir( $base . '/target/broken', 0777, true );
mkdir( $base . '/target/first', 0777, true );
mkdir( $base . '/target/empty', 0777, true );
mkdir( $base . '/outside' );
mkdir( $base . '/gone' );
file_put_contents( $base . '/target/a.txt', 'a' );
file_put_contents( $base . '/target/broken/b.txt', 'b' );
file_put_contents( $base . '/target/first/c.txt', 'c' );
file_put_contents( $base . '/outside/o.txt', 'o' );
// First in each directory (sorted), where the probe without readlink() looks: a broken junction, a working one.
wpc_junction( $base . '/gone', $base . '/target/broken/0gone' );
rmdir( $base . '/gone' );
wpc_junction( $base . '/outside', $base . '/target/first/0out' );
// The content root itself is a junction.
wpc_junction( $base . '/target', $base . '/uploads' );
clearstatcache( true );

$options = array( 'abspath' => $base . '/site' );
if ( 'listed' === $mode ) {
	$options['root_base'] = static function ( string $root ): string {
		return Links::key( $root, false );
	};
}
$scanner = new FileScanner(
	array( array( 'group' => 'uploads', 'path' => $base . '/uploads', 'prefix' => 'wp-content/uploads' ) ),
	new Exclusions( array(), array() ),
	PHP_INT_SIZE,
	Manifest::DEFAULT_CHUNK,
	$options
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
	'readlink'  => HostFunctions::available( 'readlink' ),
	'lines'     => $lines,
	'undecided' => $state['counts']['undecided'],
	'links'     => $state['counts']['links'],
	'warnings'  => $state['warnings'],
);

// Taken apart by name: the junctions as junctions, then what they led to.
rmdir( $base . '/uploads' );
rmdir( $base . '/target/first/0out' );
rmdir( $base . '/target/broken/0gone' );
unlink( $base . '/target/a.txt' );
unlink( $base . '/target/broken/b.txt' );
unlink( $base . '/target/first/c.txt' );
unlink( $base . '/outside/o.txt' );
foreach ( array( '/target/broken', '/target/first', '/target/empty', '/target', '/outside', '/site' ) as $dir ) {
	rmdir( $base . $dir );
}
echo json_encode( $out, JSON_UNESCAPED_SLASHES ), "\n";
