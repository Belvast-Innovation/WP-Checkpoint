<?php
/**
 * Thrown when a volume cannot be written for lack of disk space.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Archive;

use WPCheckpoint\Jobs\TransientFailure;

/**
 * A transient failure: the runner backs off (5, 15, 60, 300 s) and gives
 * up after five retries, which leaves room for another process to free
 * space without waiting forever.
 */
final class InsufficientSpace extends TransientFailure {
}
