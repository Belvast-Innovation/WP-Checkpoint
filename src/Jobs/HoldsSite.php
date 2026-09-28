<?php
/**
 * A step that changes the site, and says how far.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * A step whose cursor tells what it has done to the site (Job::$site_state).
 * The Runner writes the value in the statement that writes the cursor, on
 * every checkpoint and every result the step returns: the row never shows a
 * cursor of one state and a site_state of another. What the engine cannot
 * enforce is the step's part: it checkpoints a cursor of SITE_CHANGING
 * before it changes anything, and confirms its lease right before each
 * change it cannot take back, so that every rule that leaves such a job
 * alone already sees it (a checkpoint the database refuses stops the run).
 */
interface HoldsSite {

	/**
	 * The site state a cursor of this step stands for: Job::SITE_UNTOUCHED, SITE_CHANGING or SITE_SWAPPED, or
	 * null to leave the stored one (an empty cursor, as the Runner stores it when the step is done, is one).
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @return int|null
	 */
	public static function site_state( array $cursor );
}
