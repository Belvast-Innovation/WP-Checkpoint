<?php
/**
 * A step whose cursor carries the mark of the maintenance file it holds the site with.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * The Runner writes the mark a cursor of this step carries into the job row (Job::$site_mark), in the statement that
 * writes the cursor, on every checkpoint and every result; a cursor without a mark leaves the stored one (an ended
 * job keeps it). The step's part: a cursor carrying the mark is checkpointed before the file is first held, so that
 * whenever a held file with a mark is there, its job's row already says that mark.
 */
interface MarksSite {

	/**
	 * The maintenance file's mark a cursor of this step carries, or null to leave the stored one.
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @return string|null
	 */
	public static function site_mark( array $cursor );
}
