<?php
/**
 * Contract for a kind of job.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

/**
 * Registered through JobTypes (filter wpcheckpoint_job_types). T011 adds the
 * Step contract the steps() method returns instances of; T010 only needs the
 * identity and ordering of steps to validate and display jobs.
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
}
