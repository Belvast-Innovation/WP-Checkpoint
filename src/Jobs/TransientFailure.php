<?php
/**
 * Thrown by a step for a failure worth retrying.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

/**
 * Network timeouts, temporary write failures, remote 5xx: the runner keeps
 * the cursor, backs off and tries again a limited number of times. Anything
 * else a step throws fails the job.
 */
final class TransientFailure extends \RuntimeException {
}
