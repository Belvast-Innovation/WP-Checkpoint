<?php
/**
 * The tables a swap renames stayed locked by other work through every try.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by SwapStep when a RENAME waited out the lock through every retry: the swap is rolled back for it with a
 * reason of its own (SwapStep::BUSY), which its cursor keeps for the runs that finish the rollback.
 */
final class TablesBusy extends \RuntimeException {
}
