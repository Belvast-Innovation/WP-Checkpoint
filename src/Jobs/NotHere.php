<?php
/**
 * A storage that cannot do something where it runs.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by a RunnerRepository method that cannot be done where the run is made (one made without WordPress cannot
 * pause a job for an answer, for example), before anything is written. The Runner then lets the job go and leaves
 * it as it is, for WP-CLI: not a failure of the job (TickResult::LEFT).
 */
final class NotHere extends \RuntimeException {
}
