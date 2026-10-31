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
 * failing it, with the message as the job's progress message. Only with the site recorded as untouched again: thrown
 * while the job holds the site changed, the job fails as for any other exception.
 */
final class Undone extends \RuntimeException {
}
