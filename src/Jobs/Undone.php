<?php
/**
 * A restore's swap undone after the restored site was let out.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by the swap once it put the site back as it was before the restore, when the restored site had been let out
 * (an undo answered after the release, or wp wpcheckpoint job undo): the Runner ends the job as Job::UNDONE instead of
 * failing it, with the step a redo starts at recorded as a retry's is (RetryFrom), and the message as the job's
 * progress message. Everything else is RetryFrom's: the step must be this one or one before it, and the job must hold
 * nothing of the site (a step that throws it while it does fails the job, as RetryFrom does).
 */
final class Undone extends RetryFrom {
}
