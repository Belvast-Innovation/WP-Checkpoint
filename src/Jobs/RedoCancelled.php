<?php
/**
 * A cancel requested before a redo changed the site.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by JobRepository::save_progress() when the write that records a redo as changing the site (site state from
 * untouched to changing, with the redo's undo time in the cursor) found a cancel requested on the row: the two are
 * written on the same row and only one can win. Nothing was written; the swap step gives the redo up (SwapStep: the
 * site stays as the undo left it, the job undone again).
 */
final class RedoCancelled extends \RuntimeException {
}
