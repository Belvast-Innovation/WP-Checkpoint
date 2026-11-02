<?php
/**
 * A step's end of a job whose cancel was requested.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by a step that found a cancel request (Job::$cancel_requested) and
 * has undone what it did to the site: the Runner cancels the job and runs
 * the cleanup, as for a cancel taken with the lock. The step's last cursor
 * must say that the site is as it was (HoldsSite::site_state() of it is
 * Job::SITE_UNTOUCHED): the row refuses to cancel a job that holds the site
 * changed, and the run then ends as one that lost its lock.
 */
class Cancelled extends \RuntimeException {

	/**
	 * Whether the message is what the job says at its end (its progress message), not only this tick's: a put-back's
	 * end, with what the check of the site as put back found (SwapStep::ended()).
	 *
	 * @var bool
	 */
	public $said = false;

	/**
	 * Whether the job's work is kept at the cancel (kept()).
	 *
	 * @var bool
	 */
	public $keep_work = false;

	/**
	 * A cancel whose message the job keeps.
	 *
	 * @param string $message What the job says at its end.
	 * @return self
	 */
	public static function said( string $message ): self {
		$e       = new self( $message );
		$e->said = true;
		return $e;
	}

	/**
	 * A put-back's cancel that keeps the restored copy (SwapStep::keeps_work()): the Runner does not reclaim the job's
	 * work at the cancel; it goes after JobRepository::WORK_RETENTION_SECONDS, as a failed job's.
	 *
	 * @param string $message What the job says at its end.
	 * @return self
	 */
	public static function kept( string $message ): self {
		$e            = self::said( $message );
		$e->keep_work = true;
		return $e;
	}
}
