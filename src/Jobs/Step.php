<?php
/**
 * One unit of a job.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

/**
 * A step does a bounded amount of work per run() call and reports where it
 * stopped through the cursor. It must be idempotent or replayable from the
 * cursor: a tick can die at any moment and the next one starts from the last
 * checkpoint. Between units of work (a batch of rows, one file) it calls
 * JobContext::should_stop() and returns StepResult::progress() when told to.
 */
interface Step {

	/**
	 * Stable identifier stored in the jobs table, unique within the job type.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Do some work.
	 *
	 * @param JobContext $context Cursor, budget, logger, checkpointing.
	 * @return StepResult
	 * @throws TransientFailure For a failure that is worth retrying later (network, temporary write error).
	 * @throws \Throwable Any other exception fails the job.
	 */
	public function run( JobContext $context ): StepResult;

	/**
	 * Remove what the step left behind (temporary tables, files) after the
	 * job was cancelled. Must not throw for things that do not exist.
	 *
	 * @param JobContext $context Context without checkpointing.
	 * @return void
	 */
	public function cleanup( JobContext $context ): void;
}
