<?php
/**
 * A restore's swap undone after the restored site was let out, or a redo that did not stand.
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
 *
 * A redo (wp wpcheckpoint job redo) that did not stand ends undone again with the time of the undo it set out from
 * (back()): the time a restore's undone copy is kept, and a redo can be made in, is not moved by it.
 */
final class Undone extends \RuntimeException {

	/**
	 * The job's finished_at to write, or 0 for now.
	 *
	 * @var int
	 */
	private $at = 0;

	/**
	 * Back to undone as of the undo at $at (a redo that did not stand).
	 *
	 * @param string $message The job's progress message.
	 * @param int    $at      Unix time of the undo.
	 * @return self
	 */
	public static function back( string $message, int $at ): self {
		$out     = new self( $message );
		$out->at = max( 0, $at );
		return $out;
	}

	/**
	 * The job's finished_at to write (0: now).
	 *
	 * @return int
	 */
	public function at(): int {
		return $this->at;
	}
}
