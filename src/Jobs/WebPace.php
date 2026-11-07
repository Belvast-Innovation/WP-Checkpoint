<?php
/**
 * When a run made without WordPress may try a job again after it was held up.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * The pace of the runs made without WordPress over a job they were held up on (TickResult::$held_up: a lock that
 * stayed taken through every try, a TransientFailure through every retry): the first wait SCHEDULE[0] seconds, the
 * next SCHEDULE[1], and so on, then the last one each time; a run that gets through clears it. Kept outside the job
 * (the job row is left as it is); where it is kept is the implementation's (WebRun takes one; MemoryPace keeps it
 * in the process).
 */
interface WebPace {

	/**
	 * Seconds between held-up runs: the n-th wait is SCHEDULE[n - 1], and the last one after that.
	 */
	const SCHEDULE = array( 5, 30, 120, 300 );

	/**
	 * Seconds before a run may try the job again (0: now).
	 *
	 * @param int $job_id Job id.
	 * @param int $now    Unix time.
	 * @return int
	 */
	public function wait( int $job_id, int $now ): int;

	/**
	 * A run was held up on the job: one more, and the seconds before the next may try (from SCHEDULE).
	 *
	 * @param int $job_id Job id.
	 * @param int $now    Unix time.
	 * @return int
	 */
	public function held_up( int $job_id, int $now ): int;

	/**
	 * A run got through (anything but held up): the pace starts over.
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	public function cleared( int $job_id ): void;
}
