<?php
/**
 * Write src/Jobs/WebClasses.php: the classes a run without WordPress loads, as the scan of its code finds them
 * (tests/Fixtures/WebPathScanner.php with WebPathScanTest's registries). Run after a change to that code:
 *
 *   php bin/web-classes.php
 *
 * WebClassesTest fails while the file is not what this writes.
 *
 * @package WPCheckpoint
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
define( 'ABSPATH', __DIR__ . '/' ); // The class files' guard: nothing of WordPress is loaded.
$root = dirname( __DIR__ );
require $root . '/vendor/autoload.php';
require_once $root . '/tests/unit/Jobs/WebPathScanTest.php';
require_once $root . '/tests/unit/Jobs/WebClassesTest.php';

$reach = \WPCheckpoint\Tests\Unit\Jobs\WebPathScanTest::scanner()->run();
if ( array() !== $reach['problems'] ) {
	fwrite( STDERR, "The scan has problems; fix them first:\n  " . implode( "\n  ", $reach['problems'] ) . "\n" );
	exit( 1 );
}
$code = \WPCheckpoint\Tests\Unit\Jobs\WebClassesTest::source( $reach['loaded'] );
if ( false === file_put_contents( $root . '/src/Jobs/WebClasses.php', $code ) ) {
	fwrite( STDERR, "src/Jobs/WebClasses.php could not be written.\n" );
	exit( 1 );
}
fwrite( STDOUT, count( $reach['loaded'] ) . " classes written to src/Jobs/WebClasses.php.\n" );
