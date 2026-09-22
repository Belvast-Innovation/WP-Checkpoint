<?php
/**
 * Another process writes the same volume.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Archive;

use WPCheckpoint\Jobs\TransientFailure;

/**
 * Thrown by Packer when the open volume's length is not what this process
 * wrote: another process (a run that outlived its lease) is writing the
 * same work directory. The run stops without touching the volume; being a
 * TransientFailure, the job is retried after the back-off, and the retry
 * cuts the volume back to the last checkpoint (Packer::resume()) while
 * the other process stops at its next lease check. The Runner writes a
 * line of its own to the job log for it (the only trace of such a run).
 */
final class ConcurrentWriter extends TransientFailure {
}
