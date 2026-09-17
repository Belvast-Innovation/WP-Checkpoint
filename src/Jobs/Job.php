<?php
/**
 * Job record and state machine.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

/**
 * Plain value object mirroring one row of the jobs table.
 *
 * Statuses: queued -> running (lock acquired), queued -> failed (given up
 * before it ever ran), running -> completed | failed | paused, paused ->
 * running, queued | running | paused -> cancelled, failed -> queued (retry,
 * cursor kept). completed and cancelled are
 * terminal. A job between two ticks is still "running": the lock is
 * released, the status is not.
 */
final class Job {

	const QUEUED    = 'queued';
	const RUNNING   = 'running';
	const PAUSED    = 'paused';
	const COMPLETED = 'completed';
	const FAILED    = 'failed';
	const CANCELLED = 'cancelled';

	/**
	 * Allowed transitions: from => [to, ...].
	 *
	 * @var array<string, string[]>
	 */
	const TRANSITIONS = array(
		self::QUEUED    => array( self::RUNNING, self::FAILED, self::CANCELLED ),
		self::RUNNING   => array( self::COMPLETED, self::FAILED, self::PAUSED, self::CANCELLED ),
		self::PAUSED    => array( self::RUNNING, self::CANCELLED ),
		self::FAILED    => array( self::QUEUED ),
		self::COMPLETED => array(),
		self::CANCELLED => array(),
	);

	/**
	 * Row id (0 before the first save).
	 *
	 * @var int
	 */
	public $id = 0;

	/**
	 * Blog id the job was created on.
	 *
	 * @var int
	 */
	public $site_id = 0;

	/**
	 * Job type id (see JobType).
	 *
	 * @var string
	 */
	public $type = '';

	/**
	 * Current status.
	 *
	 * @var string
	 */
	public $status = self::QUEUED;

	/**
	 * Identifier of the current step.
	 *
	 * @var string
	 */
	public $step = '';

	/**
	 * Position inside the current step: identifiers and offsets only, never credentials.
	 *
	 * @var array<string, mixed>
	 */
	public $cursor = array();

	/**
	 * Percentage 0-100.
	 *
	 * @var int
	 */
	public $progress = 0;

	/**
	 * Short human readable progress text.
	 *
	 * @var string
	 */
	public $progress_message = '';

	/**
	 * How many times the job was (re)started.
	 *
	 * @var int
	 */
	public $attempts = 0;

	/**
	 * Consecutive ticks refused by the storage gate; drives the retry back-off.
	 *
	 * @var int
	 */
	public $blocked_count = 0;

	/**
	 * Token of the storage directory the job was created for.
	 *
	 * @var string
	 */
	public $storage_token = '';

	/**
	 * Absolute path of that storage directory (used to remove the lock file).
	 *
	 * @var string
	 */
	public $storage_path = '';

	/**
	 * Log file path relative to the storage base directory, e.g. "logs/job-3-ab12cd34.log".
	 *
	 * @var string
	 */
	public $log_path = '';

	/**
	 * Last error message (already redacted).
	 *
	 * @var string
	 */
	public $last_error = '';

	/**
	 * User who created the job.
	 *
	 * @var int
	 */
	public $owner_user = 0;

	/**
	 * Unix timestamp (UTC) of creation.
	 *
	 * @var int
	 */
	public $created_at = 0;

	/**
	 * Unix timestamp of the first successful lock.
	 *
	 * @var int
	 */
	public $started_at = 0;

	/**
	 * Unix timestamp of the last write.
	 *
	 * @var int
	 */
	public $updated_at = 0;

	/**
	 * Unix timestamp of the last cursor or progress change.
	 *
	 * @var int
	 */
	public $progress_at = 0;

	/**
	 * Unix timestamp of reaching a terminal status or failed.
	 *
	 * @var int
	 */
	public $finished_at = 0;

	/**
	 * Lock expiry, 0 when not locked.
	 *
	 * @var int
	 */
	public $locked_until = 0;

	/**
	 * Random token of the lock holder, empty when not locked.
	 *
	 * @var string
	 */
	public $lock_token = '';

	/**
	 * All known statuses.
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		return array_keys( self::TRANSITIONS );
	}

	/**
	 * Whether a transition is allowed.
	 *
	 * @param string $from Current status.
	 * @param string $to   Target status.
	 * @return bool
	 */
	public static function allows( string $from, string $to ): bool {
		return isset( self::TRANSITIONS[ $from ] ) && in_array( $to, self::TRANSITIONS[ $from ], true );
	}

	/**
	 * Whether this job may move to $to.
	 *
	 * @param string $to Target status.
	 * @return bool
	 */
	public function can_transition( string $to ): bool {
		return self::allows( $this->status, $to );
	}

	/**
	 * Move to $to, or throw. Returns $this for chaining; the repository
	 * persists the change with a guard on the previous status.
	 *
	 * @param string $to Target status.
	 * @return Job
	 * @throws InvalidTransition When the state machine forbids it.
	 */
	public function transition( string $to ): Job {
		if ( ! $this->can_transition( $to ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message from constants and an int, never rendered as HTML.
			throw new InvalidTransition( sprintf( 'Job %d cannot go from %s to %s.', $this->id, $this->status, $to ) );
		}
		$this->status = $to;
		return $this;
	}

	/**
	 * Whether no further transition is possible.
	 *
	 * @return bool
	 */
	public function is_terminal(): bool {
		return array() === self::TRANSITIONS[ $this->status ];
	}

	/**
	 * Whether the job is holding a valid lock at $now.
	 *
	 * @param int $now Unix timestamp.
	 * @return bool
	 */
	public function is_locked( int $now ): bool {
		return '' !== $this->lock_token && $this->locked_until > $now;
	}
}
