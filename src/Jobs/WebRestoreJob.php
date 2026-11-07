<?php
/**
 * The restore job type, in a run made without WordPress.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * A restore as a run made without WordPress sees it: the same id and the same steps, in the same order, as RestoreJob
 * (a failure names a step to retry from, RetryFrom( …, SwapCheckStep::ID ), and the Runner must find it there, or it
 * fails the job as an ordinary failure and the retry skips the final check); only the swap runs (WebSwapStep), every
 * other step leaves the job to WP-CLI (LeaveStep).
 */
final class WebRestoreJob implements JobType {

	/**
	 * The swap step.
	 *
	 * @var WebSwapStep
	 */
	private $swap;

	/**
	 * Type id.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Constructor.
	 *
	 * @param SwapStep $swap The swap, with a SwapHost made without WordPress.
	 * @param string   $id   Type id: the restore's (a test registers its restore under an id of its own).
	 */
	public function __construct( SwapStep $swap, string $id = RestoreJob::ID ) {
		$this->swap = new WebSwapStep( $swap );
		$this->id   = $id;
	}

	/**
	 * Type id: the restore's.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Label, untranslated (no translations without WordPress).
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Restore';
	}

	/**
	 * The ids RestoreJob::step_ids() gives.
	 *
	 * @return string[]
	 */
	public function step_ids(): array {
		return array_slice( RestoreJob::STEPS, 0, -1 );
	}

	/**
	 * The steps: RestoreJob's ids in its order; only the swap runs.
	 *
	 * @return Step[]
	 */
	public function steps(): array {
		$steps = array();
		foreach ( RestoreJob::STEPS as $id ) {
			$steps[] = SwapStep::ID === $id ? $this->swap : new LeaveStep( $id );
		}
		return $steps;
	}
}
