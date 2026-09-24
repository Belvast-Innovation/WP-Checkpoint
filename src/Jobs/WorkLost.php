<?php
/**
 * Thrown by a step when the job's own work files are gone or not what it wrote.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

/**
 * A work file that is missing, shorter than its checkpoint recorded, or
 * holds something other than what the job wrote: the work directory was
 * lost, changed or damaged. Retrying resumes from those same files, so it
 * fails the same way; the runner records the failure as final and the
 * screen offers a new job instead of Retry. A write that fails for want of
 * space is not this (TransientFailure, Archive\InsufficientSpace).
 */
final class WorkLost extends \RuntimeException {
}
