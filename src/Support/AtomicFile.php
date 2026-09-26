<?php
/**
 * Writing a file so that it is either complete under its name or not there.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- a plugin-owned file written byte for byte.

/**
 * The contents go to a temporary file in the same directory whose name does
 * not end like the final one ("{name}.{16 hex}.tmp": a ".php" never ends
 * that way, so nothing loads it as a plugin), are flushed, then the file is
 * renamed to its final name and read back. Any failure removes what the
 * attempt left and throws AtomicWriteFailed.
 *
 * The states a process that dies can leave: before the rename, only the
 * temporary file (the final name is untouched); after it, the complete
 * final file. Never a partial file under the final name.
 */
final class AtomicFile {

	/**
	 * Write $contents to $dir/$name.
	 *
	 * @param string        $dir      Directory (exists).
	 * @param string        $name     File name (no separator).
	 * @param string        $contents Contents.
	 * @param callable|null $at       Tests: function( string $stage ): void, called at "written" (the temporary file is
	 *                                complete, before the rename) and "renamed" (before the read-back); throwing there
	 *                                stands in for a process that dies at that point.
	 * @return string The final path.
	 * @throws \InvalidArgumentException When the name is not a file name.
	 * @throws AtomicWriteFailed When the file could not be put in place as written.
	 */
	public static function write( string $dir, string $name, string $contents, $at = null ): string {
		if ( '' === $name || '.' === $name || '..' === $name || false !== strpbrk( $name, "/\\\0" ) ) {
			throw new \InvalidArgumentException( 'Not a file name.' );
		}
		$final = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $name;
		$temp  = $final . '.' . bin2hex( random_bytes( 8 ) ) . '.tmp';
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
		if ( is_callable( $at ) ) {
			call_user_func( $at, 'written' );
		}
		if ( ! @rename( $temp, $final ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			throw new AtomicWriteFailed( 'The file could not be moved into place.' );
		}
		if ( is_callable( $at ) ) {
			call_user_func( $at, 'renamed' );
		}
		clearstatcache( true, $final );
		$back = @file_get_contents( $final ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		if ( $back !== $contents ) {
			@unlink( $final ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			throw new AtomicWriteFailed( 'The file did not read back as written.' );
		}
		return $final;
	}
}
