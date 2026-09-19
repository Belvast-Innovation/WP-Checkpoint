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
 * checkpoint.
 *
 * Work happens in units (a batch of rows, one file, one 16 MiB chunk).
 * Between units the step calls JobContext::should_stop() and returns
 * StepResult::progress() when told to; the budget is only ever checked
 * between units, nothing interrupts a unit from the outside. A unit must
 * therefore fit into one budget on its own: a unit that does not will hit
 * the budget on every tick and, once the cursor has stopped moving three
 * times, fail the job. Inside a unit, JobContext::should_checkpoint() says
 * when to persist the cursor (after 2 seconds or 16 MiB, whichever first).
 *
 * A step never calls the write methods of JobRepository (save_progress,
 * transition, heartbeat, release): every write goes through
 * JobContext::checkpoint() and the runner, which fence it with the lock
 * token. Temporary files live under JobContext::work_path() (the job's own
 * directory, removed by the engine), finished results are handed to
 * backups/ by the store step, and those paths are taken from the context
 * on every run, never cached across ticks: the storage directory can
 * change between ticks and the engine only touches files inside the
 * current one.
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
	 * Remove what the engine does not know about (temporary tables, external
	 * resources) after the job was cancelled. Files under work_path() need no
	 * handling: the engine removes the whole work directory after the steps
	 * ran, and reclaims it after retention for a failed job. A step that
	 * creates nothing else leaves this empty. Called by the canceller that
	 * took the lock, or by the holder that lost it, for every step up to the
	 * current one. It is never called for a failed job: a failed job keeps
	 * its cursor and its work files so that a retry can continue from them.
	 *
	 * Must tolerate everything: files that no longer exist, tables that were
	 * never created, and deletions that fail. Never throw, never let a fatal
	 * error escape. During the window in which a lease has expired but the
	 * previous holder is still alive, two processes may be writing the same
	 * files; the lock token fences database writes, not fwrite().
	 *
	 * @param JobContext $context Context without checkpointing.
	 * @return void
	 */
	public function cleanup( JobContext $context ): void;
}
