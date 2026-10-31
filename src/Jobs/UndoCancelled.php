<?php
/**
 * A cancel requested before an undo changed the site.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by JobRepository::save_progress() when the write that records an undo as changing the site (site state from
 * swapped to changing) found a cancel requested on the row: the two are written on the same row and only one can
 * win. Nothing was written; the swap step gives the undo up (SwapStep: the restored site stays, the job completed).
 */
final class UndoCancelled extends \RuntimeException {
}
