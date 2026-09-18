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
 * else a step throws fails the job. Domain classes subclass it for their
 * own transient conditions (Archive\InsufficientSpace).
 */
class TransientFailure extends \RuntimeException {
}
