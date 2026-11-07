<?php
/**
 * A step a run made without WordPress does not run.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Stands in for one of a job type's steps in a run made without WordPress (WebRestoreJob): it has the step's id, so
 * that the Runner finds every step a failure may name to retry from (RetryFrom), and it leaves the job to WP-CLI when
 * it is reached.
 */
final class LeaveStep implements Step {

	/**
	 * The step's id.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Constructor.
	 *
	 * @param string $id The step's id.
	 */
	public function __construct( string $id ) {
		$this->id = $id;
	}

	/**
	 * Step id.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Leave the job to WP-CLI.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 */
	public function run( JobContext $context ): StepResult {
		return StepResult::leave( sprintf( 'Only WP-CLI runs this part of the job (%s).', $this->id ) );
	}

	/**
	 * Nothing to clean up: it does nothing.
	 *
	 * @param JobContext $context Context.
	 * @return void
	 */
	public function cleanup( JobContext $context ): void {
		unset( $context );
	}
}
