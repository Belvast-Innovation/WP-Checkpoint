<?php
/**
 * A step's failure after which a retry starts over at a step it names.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by a step that failed in a way a retry of the step itself cannot
 * repair, but a retry from an earlier step (or from its own start) can:
 * what the earlier steps found or checked may no longer hold by the time
 * the user retries. The job fails as it would for any other exception; in
 * the same write the Runner records the step (a reserved key of the
 * cursor), and a retry of the job starts at that step with an empty cursor
 * (JobRepository::transition()). Until the retry the failed job's step
 * stays the failing one: the job shows where it failed.
 *
 * The step names the step explicitly: the Runner does not infer it. The
 * name must be the failing step's id or one before it in the job type,
 * or the job fails without it (a retry then continues the failing step,
 * as after any failure). The step that throws must leave nothing behind
 * that the steps from the named one on would not redo: they run again
 * from their start and their cleanup is not called in between.
 */
class RetryFrom extends \RuntimeException {

	/**
	 * The step a retry starts at.
	 *
	 * @var string
	 */
	private $step;

	/**
	 * Constructor.
	 *
	 * @param string          $message  Why the step failed.
	 * @param string          $step     Id of the step a retry starts at.
	 * @param \Throwable|null $previous What made it fail.
	 */
	public function __construct( string $message, string $step, $previous = null ) {
		parent::__construct( $message, 0, $previous instanceof \Throwable ? $previous : null );
		$this->step = $step;
	}

	/**
	 * Id of the step a retry starts at.
	 *
	 * @return string
	 */
	public function step(): string {
		return $this->step;
	}
}
