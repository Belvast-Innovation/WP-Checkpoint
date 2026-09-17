<?php
/**
 * Thrown when the runner no longer holds the job lock.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

/**
 * The lease expired and was taken over, or the job was cancelled: every
 * fenced write refuses. The runner stops at once and writes nothing more.
 * Steps must let it propagate.
 */
final class LockLost extends \RuntimeException {
}
