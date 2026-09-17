<?php
/**
 * Thrown when a job changed in the database since it was loaded.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

/**
 * The guarded UPDATE affected no row: another process changed the status
 * (for example cancelled a running job). Reload and decide again.
 */
final class StaleJob extends \RuntimeException {
}
