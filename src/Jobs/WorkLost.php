<?php
/**
 * Thrown by a step when the job's own work files are gone or not what it wrote.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

/**
 * A work file that is not there, shorter than its checkpoint recorded, or
 * holding something other than what the job wrote (a malformed line, a
 * hash that does not match, a key that no longer fits its table). Retrying
 * resumes from those same files, so it fails the same way; the runner
 * records the failure as final and the screen offers a new job instead of
 * Retry. A file that is there but cannot be opened or read is not this
 * (permissions, too many open files, a storage error may pass), and neither
 * is one whose absence cannot be seen in a directory listing: absent(),
 * or_unreadable().
 */
final class WorkLost extends \RuntimeException {

	/**
	 * The exception for a work file that could not be opened or read:
	 * WorkLost when it is not there, a plain RuntimeException (no kind of
	 * failure, Retry offered) when it is.
	 *
	 * @param string $path       File.
	 * @param string $missing    Message when it is not there.
	 * @param string $unreadable Message when it is there.
	 * @return \RuntimeException
	 */
	public static function or_unreadable( string $path, string $missing, string $unreadable ): \RuntimeException {
		return self::absent( $path ) ? new self( $missing ) : new \RuntimeException( $unreadable );
	}

	/**
	 * Whether a file is known to be gone: a directory above it could be
	 * listed and does not hold the next part of its path (the file itself,
	 * or the directory it was in, as when the whole work directory was
	 * reclaimed). Up to three levels. When nothing could be listed, or a
	 * directory is there but cannot be listed (a permission, open_basedir, a
	 * storage error), nothing is known and the answer is no: a failure then
	 * records no kind and Retry stays offered. Silenced: a warning would put
	 * the full path into the error log.
	 *
	 * @param string $path File.
	 * @return bool
	 */
	public static function absent( string $path ): bool {
		$child = $path;
		for ( $level = 0; $level < 3; $level++ ) {
			clearstatcache( true, $child );
			if ( @file_exists( $child ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
				return false; // The file, or a directory that cannot be listed: nothing says it is gone.
			}
			$dir  = dirname( $child );
			$list = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			if ( is_array( $list ) ) {
				return ! in_array( basename( $child ), $list, true );
			}
			if ( $dir === $child ) {
				return false;
			}
			$child = $dir;
		}
		return false;
	}
}
