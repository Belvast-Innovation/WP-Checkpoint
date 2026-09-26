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
 * file. Never a partial file under the final name. It is not a promise
 * against a crash of the machine itself (no fsync). Only names the residue
 * catalogue recognises are written (StagingLayout::parse(), which also
 * knows their temporary names), so whatever is left is reclaimed.
 *
 * The rename and the removal of a final file that does not read back as
 * written cannot be undone: the "confirm" option (a job's lease check) is
 * called right before each, as for any irreversible step. A read-back that
 * fails (the file could not be opened) leaves the file: no evidence that
 * it is wrong.
 */
final class AtomicFile {

	/**
	 * The largest contents written: the file is read back whole.
	 */
	const MAX_BYTES = 1048576;

	/**
	 * Write $contents to $dir/$name.
	 *
	 * @param string               $dir      Directory (exists).
	 * @param string               $name     File name (no separator; a name StagingLayout::parse() recognises).
	 * @param string               $contents Contents (at most MAX_BYTES).
	 * @param array<string, mixed> $options  "confirm": function(): void, called right before the rename and before
	 *                                       removing a final file that did not read back (throws to stop);
	 *                                       "at" (tests): function( string $stage ): void at "written" (before the
	 *                                       rename) and "renamed" (before the read-back), where throwing stands in
	 *                                       for a process that dies there.
	 * @return string The final path.
	 * @throws \InvalidArgumentException When the name is not a registered file name or the contents are too large.
	 * @throws AtomicWriteFailed When the file could not be put in place as written.
	 */
	public static function write( string $dir, string $name, string $contents, array $options = array() ): string {
		if ( '' === $name || false !== strpbrk( $name, "/\\\0" ) || null === StagingLayout::parse( $name ) ) {
			throw new \InvalidArgumentException( 'Not a file name this plugin writes.' );
		}
		if ( strlen( $contents ) > self::MAX_BYTES ) {
			throw new \InvalidArgumentException( 'Too large to write this way.' );
		}
		$confirm = isset( $options['confirm'] ) && is_callable( $options['confirm'] ) ? $options['confirm'] : null;
		$at      = isset( $options['at'] ) && is_callable( $options['at'] ) ? $options['at'] : null;
		$final   = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $name;
		$temp    = $final . '.' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		// Silenced: a warning would put the path into the error log; the exception says what failed.
		$handle = @fopen( $temp, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		if ( false === $handle ) {
			throw new AtomicWriteFailed( 'The file could not be created.' );
		}
		$written = @fwrite( $handle, $contents ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		$flushed = @fflush( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		$closed  = @fclose( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		if ( strlen( $contents ) !== $written || ! $flushed || ! $closed ) {
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			throw new AtomicWriteFailed( 'The file could not be written in full.' );
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
			if ( null !== $confirm ) {
				call_user_func( $confirm );
			}
			@unlink( $final ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			throw new AtomicWriteFailed( 'The file did not read back as written.' );
		}
		return $final;
	}
}
