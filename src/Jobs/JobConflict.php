<?php
/**
 * A job that must not run next to the active ones.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by JobActions::start() with the reason from JobConflicts: the job
 * was not created (or was removed before it ran).
 */
final class JobConflict extends \RuntimeException {
}
