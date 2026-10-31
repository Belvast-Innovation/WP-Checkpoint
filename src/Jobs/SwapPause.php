<?php
/**
 * A rollback of the swap that stops for now with a result of its own.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by SwapStep's rollback, which otherwise only ends the job, when it stops for now: the question of an undo
 * whose carry back failed (StepResult::ask()), or the wait between checks of the site after an undo
 * (StepResult::wait()). SwapStep::run() returns the result it carries. Never leaves SwapStep.
 */
final class SwapPause extends \RuntimeException {

	/**
	 * The result.
	 *
	 * @var StepResult
	 */
	private $result;

	/**
	 * Constructor.
	 *
	 * @param StepResult $result What the step returns.
	 */
	public function __construct( StepResult $result ) {
		parent::__construct( 'The rollback stops for now.' );
		$this->result = $result;
	}

	/**
	 * What the step returns.
	 *
	 * @return StepResult
	 */
	public function result(): StepResult {
		return $this->result;
	}
}
