<?php
/**
 * Outcome of one Runner::tick() call.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

/**
 * What the driver (T012) should do next is in retry_after: 0 means tick
 * again at once, N means wait N seconds, -1 means nothing to do.
 */
final class TickResult {

	const MORE      = 'more';       // Budget spent, more work; tick again.
	const WAITING   = 'waiting';    // A step or a transient failure asked for a pause.
	const PAUSED    = 'paused';     // A step asked the user a question; nothing to do until it is answered.
	const BLOCKED   = 'blocked';    // Storage gate refused; back off.
	const BUSY      = 'busy';       // Another driver holds the lock.
	const COMPLETED = 'completed';
	const FAILED    = 'failed';
	const LOST      = 'lost';       // Lock lost mid-tick (cancelled or taken over); reload the job.
	const FINISHED  = 'finished';   // The job was already in a state that cannot be ticked.
	const MISSING   = 'missing';

	/**
	 * One of the constants.
	 *
	 * @var string
	 */
	public $status;

	/**
	 * Seconds before the next tick; 0 at once, -1 never.
	 *
	 * @var int
	 */
	public $retry_after;

	/**
	 * The job as last seen (null when missing).
	 *
	 * @var Job|null
	 */
	public $job;

	/**
	 * Message for the driver (already redacted where it comes from an error).
	 *
	 * @var string
	 */
	public $message;

	/**
	 * Constructor.
	 *
	 * @param string   $status      Status.
	 * @param int      $retry_after Seconds.
	 * @param Job|null $job         Job.
	 * @param string   $message     Message.
	 */
	public function __construct( string $status, int $retry_after, $job, string $message = '' ) {
		$this->status      = $status;
		$this->retry_after = $retry_after;
		$this->job         = $job instanceof Job ? $job : null;
		$this->message     = $message;
	}
}
