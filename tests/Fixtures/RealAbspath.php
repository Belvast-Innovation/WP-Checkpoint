<?php
/**
 * The one place tests write to and delete from the test site's real ABSPATH.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Tests\Fixtures;

use PHPUnit\Framework\Assert;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\DeletionRefused;

/**
 * WordPress reads ABSPATH/.maintenance only, the maintenance code reads the data file next to it, and the web server
 * and WP-CLI run from that directory: the tests of the maintenance file (the registered exception: CLAUDE.md) put the
 * two files there, and nothing else. Every write and deletion of a test there goes through here, and the three
 * safeguards are here only:
 * 1. claim(): ABSPATH resolves to the test site's WordPress directory (it has wp-settings.php; it is not the
 *    repository, not inside it, not the working directory), checked again before every write and deletion;
 * 2. claim(): none of the files (nor a temporary name of one) is there when a test starts: nothing is written over;
 * 3. listing() and clear(), which the leftover check (Leftovers) calls after every test and at the start and end of
 *    the run: what is left is reported, and only what this plugin writes is removed (the code, a data file of its form,
 *    a single file with a restore's mark, a temporary name of either), through the Deleter.
 *
 * A file a test writes as someone else's (fixture(): it carries FIXTURE_MARK on a line of its own) is someone else's
 * to everything during the run; only the check before a run (maintenance-precheck.php, clear_before_run()) takes it
 * as a test's leftover and removes it: a run killed while such a test held it would otherwise leave a .maintenance
 * that stops every later run. A file without the mark is still left, and still stops the run.
 */
final class RealAbspath {

	/**
	 * The files: the maintenance file and its data file.
	 */
	const NAMES = array( Maintenance::FILE, Maintenance::DATA );

	/**
	 * The line a test's own "someone else's" maintenance file carries (fixture()).
	 */
	const FIXTURE_MARK = '// WP Checkpoint test fixture';

	/**
	 * Contents for a file a test writes as someone else's: the contents, then FIXTURE_MARK on a line of its own.
	 *
	 * @param string $contents Contents (a PHP file's ends in a line of PHP, so the mark is a comment there).
	 * @return string
	 */
	public static function fixture( string $contents ): string {
		return rtrim( $contents, "\n" ) . "\n" . self::FIXTURE_MARK . "\n";
	}

	/**
	 * Whether a path listing() gave is a test's "someone else's" file: one of the two names, with FIXTURE_MARK on a
	 * line of its own.
	 *
	 * @param string $path A path listing() gave.
	 * @return bool
	 */
	public static function marked( string $path ): bool {
		if ( ! in_array( basename( $path ), self::NAMES, true ) ) {
			return false;
		}
		$text = (string) @file_get_contents( $path, false, null, 0, 65536 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a test fixture.
		return 1 === preg_match( '/^' . preg_quote( self::FIXTURE_MARK, '/' ) . '\r?$/m', $text );
	}

	/**
	 * Claim the real ABSPATH for a test: safeguards 1 and 2.
	 *
	 * @return string ABSPATH, resolved, without a trailing separator.
	 */
	public static function claim(): string {
		$abspath = self::checked();
		foreach ( self::listing() as $item ) {
			Assert::fail( 'A maintenance file was already in ABSPATH, and is not written over: ' . $item );
		}
		return $abspath;
	}

	/**
	 * Write one of the two files, or a temporary name of one (as a run that died would leave it).
	 *
	 * @param string $name     A name the Deleter takes for a maintenance file (Deleter::MAINTENANCE_NAMES).
	 * @param string $contents Contents.
	 * @return void
	 */
	public static function write( string $name, string $contents ): void {
		Assert::assertSame( 1, preg_match( Deleter::MAINTENANCE_NAMES, $name ), 'only the maintenance file, its data file and their temporary names are written in ABSPATH' );
		Assert::assertNotFalse( file_put_contents( self::checked() . '/' . $name, $contents ), 'written: ' . $name );
	}

	/**
	 * Delete one of the two files (or a temporary name of one), through the Deleter.
	 *
	 * @param string $name A name the Deleter takes for a maintenance file.
	 * @return void
	 */
	public static function remove( string $name ): void {
		Assert::assertTrue( Deleter::delete_maintenance_file( self::checked(), $name ), 'removed: ' . $name );
	}

	/**
	 * What of the two files, and of their temporary names, is in ABSPATH: name => contents.
	 *
	 * @return array<string, string>
	 */
	public static function contents(): array {
		$out = array();
		foreach ( self::listing() as $item ) {
			$out[ basename( $item ) ] = (string) @file_get_contents( $item ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a test fixture.
		}
		return $out;
	}

	/**
	 * The paths in ABSPATH of the two files and of their temporary names (Residue::MAINTENANCE_TMP_NAME).
	 *
	 * @return string[]
	 */
	public static function listing(): array {
		if ( ! defined( 'ABSPATH' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) @scandir( ABSPATH ) as $entry ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a test fixture.
			if ( in_array( $entry, self::NAMES, true ) || 1 === preg_match( \WPCheckpoint\Jobs\Residue::MAINTENANCE_TMP_NAME, (string) $entry ) ) {
				$out[] = rtrim( ABSPATH, '/' ) . '/' . $entry;
			}
		}
		return $out;
	}

	/**
	 * Remove what listing() found that this plugin writes; report the rest and leave it.
	 *
	 * @param string $path A path listing() gave.
	 * @return void
	 */
	public static function clear( string $path ): void {
		self::clear_taking( $path, false );
	}

	/**
	 * Before a run (maintenance-precheck.php): as clear(), and a test's "someone else's" file (marked()) is removed
	 * too, as the leftover of a run killed while that test held it.
	 *
	 * @param string $path A path listing() gave.
	 * @return void
	 */
	public static function clear_before_run( string $path ): void {
		self::clear_taking( $path, true );
	}

	/**
	 * Remove what listing() found that this plugin writes (and, with $fixtures, a test's marked file); report the
	 * rest and leave it.
	 *
	 * @param string $path     A path listing() gave.
	 * @param bool   $fixtures Whether a test's marked file goes too.
	 * @return void
	 */
	private static function clear_taking( string $path, bool $fixtures ): void {
		$name = basename( $path );
		if ( ! ( self::ours( $path ) || ( $fixtures && self::marked( $path ) ) ) || '' !== self::refusal() ) {
			fwrite( STDERR, "\nA maintenance file that is not this plugin's (or not in the test site's WordPress directory) was left in place: {$path}\n" );
			return;
		}
		try {
			Deleter::delete_maintenance_file( dirname( $path ), $name );
		} catch ( DeletionRefused $e ) {
			// Not a regular file (a link, a directory), or not in the WordPress directory: said, and left.
			fwrite( STDERR, "\n" . $e->getMessage() . "\n" );
		}
	}

	/**
	 * Whether a path listing() gave is a file this plugin writes: the maintenance code (or its earlier form), a data
	 * file in its form, or a temporary name (only AtomicFile makes those).
	 *
	 * @param string $path A path listing() gave.
	 * @return bool
	 */
	public static function ours( string $path ): bool {
		$name = basename( $path );
		if ( ! in_array( $name, self::NAMES, true ) ) {
			return true;
		}
		$text = (string) @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a test fixture.
		return Maintenance::FILE === $name
			? ( Maintenance::CODE === $text || 1 === preg_match( Maintenance::LEGACY_FORM, $text ) )
			: 1 === preg_match( Maintenance::DATA_FORM, $text );
	}

	/**
	 * ABSPATH, checked (safeguard 1).
	 *
	 * @return string
	 */
	private static function checked(): string {
		$why = self::refusal();
		if ( '' !== $why ) {
			Assert::fail( $why );
		}
		return (string) realpath( ABSPATH );
	}

	/**
	 * Why ABSPATH is not the test site's WordPress directory, or ''.
	 *
	 * @return string
	 */
	public static function refusal(): string {
		$abspath = defined( 'ABSPATH' ) ? realpath( ABSPATH ) : false;
		if ( false === $abspath ) {
			return 'ABSPATH does not resolve.';
		}
		if ( ! is_file( $abspath . '/wp-settings.php' ) ) {
			return 'ABSPATH is not a WordPress directory.';
		}
		$repository = (string) realpath( dirname( __DIR__, 2 ) );
		if ( $repository === $abspath || 0 === strpos( $abspath . '/', $repository . '/' ) ) {
			return 'ABSPATH is the repository, or inside it.';
		}
		if ( (string) realpath( (string) getcwd() ) === $abspath ) {
			return 'ABSPATH is the working directory.';
		}
		return '';
	}
}
