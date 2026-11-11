<?php

namespace WPCheckpoint\Tests\Fixtures;

use PHPUnit\Framework\Assert;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\DeletionRefused;

/**
 * The one way the integration tests write and delete a page cache drop-in in the test site's real wp-content
 * directory (a registered exception: tests otherwise delete only in their own sandboxes): advanced-cache.php, with
 * CODE (a stand-in that answers every request with a fixed "cached page" and exits), and the temporary name it is
 * written under first. WordPress includes the drop-in only where WP_CACHE is true, which the test site's
 * wp-config.php does not define: the test's own php-cgi requests define it (auto_prepend_file), no other request of
 * the test site loads the file. The safeguards are here and nowhere else:
 *
 * 1. claim(): ABSPATH is the test site's WordPress directory (RealAbspath::refusal()) and its wp-content is a
 *    directory there, checked again before every write and deletion;
 * 2. claim(): no file under the name (nor a temporary name of it) is there when a test starts: nothing is written over;
 * 3. listing() and clear(), which the leftover check (Leftovers) calls after every test and at the end of the run:
 *    what is left is reported, and only a file whose contents are CODE (by their SHA-256), or a temporary name, is
 *    removed, through the Deleter;
 * 4. before a run (leftovers-precheck.php, refusal_before_run()), a file under the name or a temporary name of it,
 *    whatever it holds, stops the run (status 77) and nothing is removed.
 */
final class RealAdvancedCache {

	const NAME = 'advanced-cache.php';

	/**
	 * The name or a temporary name of a write of it.
	 */
	const NAMES = '/\Aadvanced-cache\.php(\.[0-9a-f]{16}\.tmp)?\z/';

	/**
	 * What the stand-in answers every request with.
	 */
	const PAGE = 'WPCHECKPOINT-TEST-CACHED-PAGE';

	/**
	 * The stand-in: a page cache that has every page.
	 */
	const CODE = "<?php\n// WP Checkpoint test fixture: a page cache drop-in that answers every request from its cache.\necho 'WPCHECKPOINT-TEST-CACHED-PAGE';\nexit;\n";

	/**
	 * Why the wp-content directory is not the test site's, or ''.
	 *
	 * @return string
	 */
	public static function refusal(): string {
		$why = RealAbspath::refusal();
		if ( '' !== $why ) {
			return $why;
		}
		$dir  = ContentLeftovers::dir();
		$real = realpath( $dir );
		if ( false === $real || ! is_dir( $real ) || is_link( $dir ) ) {
			return 'The test site has no wp-content directory of its own.';
		}
		$repository = (string) realpath( dirname( __DIR__, 2 ) );
		if ( $repository === $real || 0 === strpos( $real . '/', $repository . '/' ) ) {
			return 'The wp-content directory is the repository, or inside it.';
		}
		return '';
	}

	/**
	 * The test site's wp-content directory, checked (safeguard 1).
	 *
	 * @return string
	 */
	private static function checked(): string {
		$why = self::refusal();
		if ( '' !== $why ) {
			Assert::fail( $why );
		}
		return ContentLeftovers::dir();
	}

	/**
	 * Claim the name for a test: safeguards 1 and 2.
	 *
	 * @return void
	 */
	public static function claim(): void {
		foreach ( self::listing( self::checked() ) as $item ) {
			Assert::fail( 'A page cache drop-in was already in wp-content, and is not written over: ' . $item );
		}
	}

	/**
	 * Put the stand-in up: under a temporary name first, then renamed. Never over a file that is there.
	 *
	 * @return void
	 */
	public static function put(): void {
		$dir = self::checked();
		Assert::assertSame( array(), self::listing( $dir ), 'nothing under the name is written over' );
		$tmp = $dir . '/' . self::NAME . '.' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		Assert::assertNotFalse( file_put_contents( $tmp, self::CODE ), 'written' );
		Assert::assertTrue( rename( $tmp, $dir . '/' . self::NAME ), 'put in place' );
	}

	/**
	 * Take it away (the stand-in by its contents, a temporary name whatever it holds).
	 *
	 * @return void
	 */
	public static function remove(): void {
		$dir = self::checked();
		foreach ( self::listing( $dir ) as $path ) {
			Assert::assertTrue( self::ours( $path ), 'only the tests\' own drop-in is removed: ' . $path );
			self::delete( $dir, $path );
		}
		Assert::assertSame( array(), self::listing( $dir ), 'removed' );
	}

	/**
	 * The paths in a wp-content directory under the name or a temporary name of it.
	 *
	 * @param string|null $dir The directory (the test site's when null).
	 * @return string[]
	 */
	public static function listing( $dir = null ): array {
		$dir = null === $dir ? ContentLeftovers::dir() : rtrim( str_replace( '\\', '/', (string) $dir ), '/' );
		$out = array();
		foreach ( is_dir( $dir ) ? (array) scandir( $dir ) : array() as $entry ) {
			if ( self::is_name( (string) $entry ) ) {
				$out[] = $dir . '/' . $entry;
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Whether a name is the drop-in's or a temporary name of it.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function is_name( string $name ): bool {
		return 1 === preg_match( self::NAMES, $name );
	}

	/**
	 * Whether a path listing() gave is the tests' own: under the name, contents whose SHA-256 is CODE's; a temporary
	 * name whatever it holds. A regular file, not a link.
	 *
	 * @param string $path A path listing() gave.
	 * @return bool
	 */
	public static function ours( string $path ): bool {
		$name = basename( $path );
		if ( ! self::is_name( $name ) || is_link( $path ) || ! is_file( $path ) ) {
			return false;
		}
		if ( self::NAME !== $name ) {
			return true;
		}
		$text = @file_get_contents( $path, false, null, 0, strlen( self::CODE ) + 1 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a test fixture.
		return is_string( $text ) && hash_equals( hash( 'sha256', self::CODE ), hash( 'sha256', $text ) );
	}

	/**
	 * Remove what listing() found that is the tests' own; say the rest and leave it.
	 *
	 * @param string      $path A path listing() gave.
	 * @param string|null $dir  The directory it must be in (the test site's when null, checked).
	 * @return void
	 */
	public static function clear( string $path, $dir = null ): void {
		$in = null === $dir ? ContentLeftovers::dir() : rtrim( str_replace( '\\', '/', (string) $dir ), '/' );
		if ( dirname( str_replace( '\\', '/', $path ) ) !== $in || ! self::ours( $path ) || ( null === $dir && '' !== self::refusal() ) ) {
			fwrite( STDERR, "\nA page cache drop-in that is not the tests' own (or not in the test site's wp-content directory) was left in place: {$path}\n" );
			return;
		}
		self::delete( $in, $path );
	}

	/**
	 * Delete one file judged the tests' own, through the Deleter (registered for this deletion only).
	 *
	 * @param string $dir  The wp-content directory.
	 * @param string $path The file.
	 * @return void
	 */
	private static function delete( string $dir, string $path ): void {
		$roots = Deleter::replace_roots( array() );
		Deleter::replace_roots( $roots );
		try {
			Deleter::allow( $path );
			Deleter::delete_tree( $dir, $path );
		} catch ( DeletionRefused $e ) {
			fwrite( STDERR, "\n" . $e->getMessage() . "\n" );
		} finally {
			Deleter::replace_roots( $roots );
		}
	}

	/**
	 * Why a run does not start ('' when it may): a file under the name or a temporary name of it in the wp-content
	 * directory, whatever it holds. Nothing is removed.
	 *
	 * @param string $dir The wp-content directory.
	 * @return string
	 */
	public static function refusal_before_run( string $dir ): string {
		$found = self::listing( $dir );
		if ( array() === $found ) {
			return '';
		}
		$lines = array_map(
			static function ( string $path ): string {
				return $path . ( self::ours( $path ) ? ' (the tests\' own)' : ' (not the tests\')' );
			},
			$found
		);
		return "The test site's wp-content directory holds a page cache drop-in (advanced-cache.php, or a temporary name of it); the run does not start, and nothing was removed. Remove them first:\n  " . implode( "\n  ", $lines ) . "\n";
	}
}
