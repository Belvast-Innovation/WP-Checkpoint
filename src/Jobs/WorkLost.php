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
 * (permissions, too many open files, a storage error may pass): or_unreadable().
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
		clearstatcache( true, $path );
		return file_exists( $path ) ? new \RuntimeException( $unreadable ) : new self( $missing );
	}
}
