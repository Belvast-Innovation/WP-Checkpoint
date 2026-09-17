<?php
/**
 * Contract for a kind of job.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

/**
 * Registered through JobTypes (filter wpcheckpoint_job_types). The runner
 * executes steps() in order; step_ids() is the same order for display and
 * validation without instantiating the steps.
 */
interface JobType {

	/**
	 * Stable identifier stored in the jobs table, e.g. "export".
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Translated label.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Ordered step identifiers.
	 *
	 * @return string[]
	 */
	public function step_ids(): array;

	/**
	 * The steps, in execution order (ids must match step_ids()).
	 *
	 * @return Step[]
	 */
	public function steps(): array;
}
