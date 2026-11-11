<?php

namespace WPCheckpoint\Tests\Fixtures;

use PHPUnit\Framework\Assert;
use WPCheckpoint\Restore\Loader;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\DeletionRefused;

/**
 * The one way the integration tests write and delete in the test site's real must-use plugins directory (a registered
 * exception: tests otherwise delete only in their own sandboxes): a restore's loader (Restore\Loader::NAME) and the
 * temporary names of a write of it, for the tests that need the test site itself to load it (its web server, a WP-CLI
 * process). The safeguards are here and nowhere else:
 *
 * 1. claim(): ABSPATH is the test site's WordPress directory (RealAbspath::refusal()) and its must-use plugins
 *    directory is a directory there, checked again before every write and deletion;
 * 2. claim(): no file under the loader's name (nor a temporary name of it) is there when a test starts: nothing is
 *    written over;
 * 3. listing() and clear(), which the leftover check (Leftovers) calls after every test and at the end of the run:
 *    what is left is reported, and only a file whose contents are a loader this plugin wrote (by their SHA-256), or a
 *    temporary name, is removed, through the Deleter;
 * 4. before a run (leftovers-precheck.php, ContentLeftovers::refusal()), a file under the loader's name or a temporary
 *    name of it, whatever it holds, stops the run (status 77) and nothing is removed.
 */
final class RealMuPlugins {

	/**
	 * The test site's must-use plugins directory, checked (safeguard 1).
	 *
	 * @return string
	 */
	private static function checked(): string {
		$why = self::refusal();
		if ( '' !== $why ) {
			Assert::fail( $why );
		}
		return ContentLeftovers::mu_dir();
	}

	/**
	 * Why the must-use plugins directory is not the test site's, or ''.
	 *
	 * @return string
	 */
	public static function refusal(): string {
		$why = RealAbspath::refusal();
		if ( '' !== $why ) {
			return $why;
		}
		$dir  = ContentLeftovers::mu_dir();
		$real = realpath( $dir );
		if ( false === $real || ! is_dir( $real ) || is_link( $dir ) ) {
			return 'The test site has no must-use plugins directory of its own.';
		}
		$repository = (string) realpath( dirname( __DIR__, 2 ) );
		if ( $repository === $real || 0 === strpos( $real . '/', $repository . '/' ) ) {
			return 'The must-use plugins directory is the repository, or inside it.';
		}
		return '';
	}

	/**
	 * Claim the directory for a test: safeguards 1 and 2.
	 *
	 * @return string The directory.
	 */
	public static function claim(): string {
		$dir = self::checked();
		foreach ( self::listing( $dir ) as $item ) {
			Assert::fail( 'A file under the loader\'s name was already in the must-use plugins directory, and is not written over: ' . $item );
		}
		return $dir;
	}

	/**
	 * Put the loader up, as the swap does (Loader::put(): a temporary name, then the rename).
	 *
	 * @return void
	 */
	public static function put(): void {
		Assert::assertSame( '', Loader::put( self::checked() ), 'the loader is put up' );
	}

	/**
	 * Take the loader (or a temporary name of it) away, through the Deleter: by its contents.
	 *
	 * @param string $name The loader's name, or a temporary name of it.
	 * @return void
	 */
	public static function remove( string $name = Loader::NAME ): void {
		Assert::assertTrue( Deleter::delete_loader_file( self::checked(), $name, Loader::digests() ), 'removed: ' . $name );
	}

	/**
	 * The paths in a must-use plugins directory under the loader's name or a temporary name of it.
	 *
	 * @param string|null $dir The directory (the test site's when null).
	 * @return string[]
	 */
	public static function listing( $dir = null ): array {
		$dir = null === $dir ? ContentLeftovers::mu_dir() : rtrim( str_replace( '\\', '/', (string) $dir ), '/' );
		$out = array();
		foreach ( is_dir( $dir ) ? (array) scandir( $dir ) : array() as $entry ) {
			if ( self::is_loader( (string) $entry ) ) {
				$out[] = $dir . '/' . $entry;
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Whether a name is the loader's or a temporary name of it.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function is_loader( string $name ): bool {
		return 1 === preg_match( Deleter::LOADER_NAMES, $name );
	}

	/**
	 * Whether a path listing() gave is a file this plugin wrote: under the loader's name, contents whose SHA-256 is a
	 * loader's; a temporary name whatever it holds (only AtomicFile makes those).
	 *
	 * @param string $path A path listing() gave.
	 * @return bool
	 */
	public static function ours( string $path ): bool {
		$name = basename( $path );
		if ( ! self::is_loader( $name ) || is_link( $path ) || ! is_file( $path ) ) {
			return false;
		}
		if ( Loader::NAME !== $name ) {
			return true;
		}
		$text = @file_get_contents( $path, false, null, 0, Deleter::LOADER_MAX_READ + 1 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a test fixture.
		return is_string( $text ) && in_array( hash( 'sha256', $text ), Loader::digests(), true );
	}

	/**
	 * Remove what listing() found that this plugin wrote; say the rest and leave it.
	 *
	 * @param string      $path A path listing() gave.
	 * @param string|null $dir  The directory it must be in (the test site's when null, checked).
	 * @return void
	 */
	public static function clear( string $path, $dir = null ): void {
		$in = null === $dir ? ContentLeftovers::mu_dir() : rtrim( str_replace( '\\', '/', (string) $dir ), '/' );
		if ( dirname( str_replace( '\\', '/', $path ) ) !== $in || ! self::ours( $path ) || ( null === $dir && '' !== self::refusal() ) ) {
			fwrite( STDERR, "\nA file under the name of the restore's must-use plugin that is not this plugin's (or not in the test site's must-use plugins directory) was left in place: {$path}\n" );
			return;
		}
		try {
			Deleter::delete_loader_file( $in, basename( $path ), Loader::digests() );
		} catch ( DeletionRefused $e ) {
			fwrite( STDERR, "\n" . $e->getMessage() . "\n" );
		}
	}
}
