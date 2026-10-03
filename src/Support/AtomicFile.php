<?php
/**
 * Writing a file so that it is either complete under its name or not there.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

use WPCheckpoint\Restore\StagingLayout;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- a plugin-owned file written byte for byte.

/**
 * The contents go to a temporary file in the same directory whose name does
 * not end like the final one ("{name}.{16 hex}.tmp": a ".php" never ends
 * that way, so nothing loads it as a plugin), are flushed, then the file is
 * renamed to its final name and read back. A failure attempts to remove
 * what the attempt left and throws AtomicWriteFailed.
 *
 * What a process that dies can leave: before the rename, only the
 * temporary file (the final name is untouched); after it, the whole final
 * file. Never a partial file under the final name. Against a crash of the
 * machine itself (power loss) the temporary file is synced to disk before
 * the rename where PHP can (fsync(), PHP 8.1 and later). Known limit: on
 * PHP 7.4 and 8.0 there is no fsync(), and a power loss right after the
 * rename may leave the final name with incomplete contents (the file
 * system may commit the rename before the data). Only names the residue
 * catalogue recognises are written (StagingLayout::parse(), which also
 * knows their temporary names), so whatever is left is reclaimed.
 *
 * The rename cannot be undone: the "confirm" option (a job's lease check)
 * is called right before it, as for any irreversible step. A final file
 * that does not read back as written is removed without asking (its name
 * is this call's alone, and a file of unknown contents must not stay
 * where it may be loaded). A read-back that fails (the file could not be
 * opened) leaves the file: no evidence that it is wrong.
 */
final class AtomicFile {

	/**
	 * The largest contents written: the file is read back whole.
	 */
	const MAX_BYTES = 1048576;

	/**
	 * Files written into a staging root (its protection from web access).
	 */
	const ROOT_FILES = array( 'index.php', '.htaccess' );

	/**
	 * Write $contents to $dir/$name.
	 *
	 * @param string               $dir      Directory (exists).
	 * @param string               $name     File name (no separator; a name StagingLayout::parse() recognises, as
	 *                                       its temporary name must be too, one of ROOT_FILES in a staging root, or
	 *                                       the maintenance file in ABSPATH, whose temporary names the residue
	 *                                       catalogue knows there: Residue::scan_maintenance()).
	 * @param string               $contents Contents (at most MAX_BYTES).
	 * @param array<string, mixed> $options  "confirm": function(): void, called right before the rename (throws to
	 *                                       stop; the temporary file is removed);
	 *                                       "at" (tests): function( string $stage ): void at "written" (before the
	 *                                       rename) and "renamed" (before the read-back), where throwing stands in
	 *                                       for a process that dies there; "sync" (tests): function( resource ):
	 *                                       bool in place of sync() (null: none).
	 * @return string The final path.
	 * @throws \InvalidArgumentException When the name is not a registered file name or the contents are too large.
	 * @throws AtomicWriteFailed When the file could not be put in place as written.
	 */
	public static function write( string $dir, string $name, string $contents, array $options = array() ): string {
		$suffix = '.' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		// The temporary name must be one the reaper recognises too, or a process dying before the rename leaves it forever:
		// a probe's name, or a protection file of a staging root (the root is reclaimed whole, whatever is in it).
		$registered = null !== StagingLayout::parse( $name ) && null !== StagingLayout::parse( $name . $suffix );
		$protection = in_array( $name, self::ROOT_FILES, true ) && 'stage' === ( StagingLayout::parse( basename( rtrim( $dir, '/\\' ) ) )['kind'] ?? '' );
		$upgrading  = '.maintenance' === $name && self::maintenance_dir( $dir );
		if ( '' === $name || false !== strpbrk( $name, "/\\\0" ) || ! ( $registered || $protection || $upgrading ) ) {
			throw new \InvalidArgumentException( 'Not a file name this plugin writes.' );
		}
		if ( strlen( $contents ) > self::MAX_BYTES ) {
			throw new \InvalidArgumentException( 'Too large to write this way.' );
		}
		$confirm = isset( $options['confirm'] ) && is_callable( $options['confirm'] ) ? $options['confirm'] : null;
		$at      = isset( $options['at'] ) && is_callable( $options['at'] ) ? $options['at'] : null;
		$final   = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $name;
		$temp    = $final . $suffix;
		// Silenced: a warning would put the path into the error log; the exception says what failed.
		$handle = @fopen( $temp, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		if ( false === $handle ) {
			throw new AtomicWriteFailed( 'The file could not be created.' );
		}
		$sync    = array_key_exists( 'sync', $options ) ? $options['sync'] : self::sync();
		$written = @fwrite( $handle, $contents ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		$flushed = @fflush( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		$synced  = null === $sync || false !== call_user_func( $sync, $handle );
		$closed  = @fclose( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		if ( strlen( $contents ) !== $written || ! $flushed || ! $closed ) {
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			throw new AtomicWriteFailed( 'The file could not be written in full.' );
		}
		if ( ! $synced ) {
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			throw new AtomicWriteFailed( 'The file could not be synced to disk.' );
		}
		if ( null !== $at ) {
			call_user_func( $at, 'written' );
		}
		if ( null !== $confirm ) {
			try {
				call_user_func( $confirm );
			} catch ( \Throwable $e ) {
				@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
				throw $e;
			}
		}
		if ( ! @rename( $temp, $final ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			throw new AtomicWriteFailed( 'The file could not be moved into place.' );
		}
		if ( null !== $at ) {
			call_user_func( $at, 'renamed' );
		}
		clearstatcache( true, $final );
		$back = @file_get_contents( $final ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		if ( false === $back ) {
			throw new AtomicWriteFailed( 'The file could not be read back.' );
		}
		if ( $back !== $contents ) {
			// Removed without asking the lease first: the name is random and this call's alone, and a file of unknown
			// contents must not stay where it may be loaded (a loader probe in mu-plugins).
			@unlink( $final ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			throw new AtomicWriteFailed( 'The file did not read back as written.' );
		}
		return $final;
	}

	/**
	 * Whether the maintenance file may be written in a directory: ABSPATH, where the reaper looks for its temporary
	 * names, or a directory the plugin may delete in (Deleter::allow(): the tests' stand-in for ABSPATH).
	 *
	 * @param string $dir Directory.
	 * @return bool
	 */
	private static function maintenance_dir( string $dir ): bool {
		return ( defined( 'ABSPATH' ) && Paths::same_location( $dir, (string) ABSPATH ) ) || '' === Deleter::refusal( rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . '.maintenance' );
	}

	/**
	 * What syncs a file's contents to disk before the rename: fsync() where PHP has it (8.1 and later, and not
	 * disabled by the host), otherwise null (see the class description's known limit).
	 *
	 * @return callable|null
	 */
	public static function sync() {
		if ( ! function_exists( 'fsync' ) ) {
			return null;
		}
		return static function ( $handle ): bool {
			return @fsync( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would put the path into the error log; failure is reported.
		};
	}
}
