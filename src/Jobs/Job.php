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
 * terminal. A job between two ticks is still "running": the database lock
 * is released, the status and the lock file are not.
 */
final class Job {

	const QUEUED    = 'queued';
	const RUNNING   = 'running';
	const PAUSED    = 'paused';
	const COMPLETED = 'completed';
	const FAILED    = 'failed';
	const CANCELLED = 'cancelled';

	/**
	 * Kinds of failure (failure_kind). FAILURE_FINAL carries three meanings
	 * for now: the job's work files are gone or not what it wrote (WorkLost);
	 * going on would give a wrong backup (TableChanged, recorded with the
	 * reason REASON_TABLE_CHANGED so the screen can say which); and nobody
	 * answered its question within 7 days (its work files are reclaimed at
	 * once, so the screen says that instead). All hide Retry for the same
	 * answer, a new job; a meaning that needs a different answer gets a kind
	 * of its own.
	 */
	const FAILURE_TEMPORARY = 'temporary';
	const FAILURE_FINAL     = 'final';

	/**
	 * Why a final failure is final, when it is not lost work files: a table's
	 * structure changed while it was exported.
	 */
	const REASON_TABLE_CHANGED = 'table_changed';

	/**
	 * Whether the job is paused because a step asked for a decision. Such
	 * a job is not ticked until JobRepository::answer() stored the answers;
	 * a paused job without questions is resumed by the next tick.
	 *
	 * @return bool
	 */
	public function awaiting_answer(): bool {
		return self::PAUSED === $this->status && array() !== $this->questions;
	}

	/**
	 * Whether retrying is worth offering: the job failed, its work files are
	 * still there, and the failure is not known to repeat (FAILURE_FINAL).
	 *
	 * @return bool
	 */
	public function retry_useful(): bool {
		return self::FAILED === $this->status && $this->can_retry() && self::FAILURE_FINAL !== $this->failure_kind;
	}

	/**
	 * The kind of failure as stored ("kind:finished_at"), or '' unless it is
	 * a known kind stamped with this failure's time. Anything else counts as
	 * no kind, which offers Retry: an unknown value, and a kind left from an
	 * earlier failure by code that does not know the column (it retries and
	 * fails the job again without clearing it, and moves finished_at). The
	 * worst a wrong reading can do is offer Retry once too often.
	 *
	 * @param string $stored      failure_kind column.
	 * @param int    $finished_at finished_at column.
	 * @return string FAILURE_TEMPORARY, FAILURE_FINAL or ''.
	 */
	public static function read_failure_kind( string $stored, int $finished_at ): string {
		return self::parse_failure( $stored, $finished_at )[0];
	}

	/**
	 * The reason stored with a final failure ("final:finished_at:reason"),
	 * or '' (none, or not a kind this failure has: read_failure_kind()).
	 *
	 * @param string $stored      failure_kind column.
	 * @param int    $finished_at finished_at column.
	 * @return string REASON_TABLE_CHANGED or ''.
	 */
	public static function read_failure_reason( string $stored, int $finished_at ): string {
		return self::parse_failure( $stored, $finished_at )[1];
	}

	/**
	 * The column value for a failure at $now: $failure is FAILURE_TEMPORARY,
	 * FAILURE_FINAL, or FAILURE_FINAL . ':' . a known reason; anything else
	 * records no kind (''). The one writer of the format read_failure_kind()
	 * and read_failure_reason() read.
	 *
	 * @param string $failure Kind, with a reason for a final one.
	 * @param int    $now     finished_at of the failure.
	 * @return string
	 */
	public static function stamp_failure( string $failure, int $now ): string {
		$parts = explode( ':', $failure );
		if ( 1 === count( $parts ) && in_array( $parts[0], array( self::FAILURE_TEMPORARY, self::FAILURE_FINAL ), true ) ) {
			return $parts[0] . ':' . $now;
		}
		if ( 2 === count( $parts ) && self::FAILURE_FINAL === $parts[0] && self::REASON_TABLE_CHANGED === $parts[1] ) {
			return $parts[0] . ':' . $now . ':' . $parts[1];
		}
		return '';
	}

	/**
	 * Kind and reason of a stored failure, both '' unless the value is one
	 * stamp_failure() writes and its time is this failure's.
	 *
	 * @param string $stored      failure_kind column.
	 * @param int    $finished_at finished_at column.
	 * @return array{0: string, 1: string}
	 */
	private static function parse_failure( string $stored, int $finished_at ): array {
		$parts = explode( ':', $stored );
		$count = count( $parts );
		if ( ( 2 !== $count && 3 !== $count ) || ! in_array( $parts[0], array( self::FAILURE_TEMPORARY, self::FAILURE_FINAL ), true ) ) {
			return array( '', '' );
		}
		if ( 3 === $count && ( self::FAILURE_FINAL !== $parts[0] || self::REASON_TABLE_CHANGED !== $parts[2] ) ) {
			return array( '', '' ); // A reason this code does not know: no kind, Retry offered.
		}
		if ( ! ctype_digit( $parts[1] ) || $finished_at <= 0 || (int) $parts[1] !== $finished_at ) {
			return array( '', '' );
		}
		return array( $parts[0], 3 === $count ? $parts[2] : '' );
	}

	/**
	 * Whether the job may be queued again: failed, and its work files were
	 * not reclaimed yet.
	 *
	 * @return bool
	 */
	public function can_retry(): bool {
		return self::FAILED === $this->status && 0 === $this->work_expired_at;
	}

	/**
	 * Allowed transitions: from => [to, ...].
	 *
	 * @var array<string, string[]>
	 */
	const TRANSITIONS = array(
		self::QUEUED    => array( self::RUNNING, self::FAILED, self::CANCELLED ),
		self::RUNNING   => array( self::COMPLETED, self::FAILED, self::PAUSED, self::CANCELLED ),
		self::PAUSED    => array( self::RUNNING, self::FAILED, self::CANCELLED ), // failed: given up unanswered, or bound to a replaced storage directory.
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
	 * Settings the job was created with (contents, exclusions, policy) plus
	 * the answers given while it was paused: identifiers, flags and rules
	 * only, never credentials or row values.
	 *
	 * @var array<string, mixed>
	 */
	public $options = array();

	/**
	 * Questions a step asked; non-empty only while the job is paused for an
	 * answer (see awaiting_answer()).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $questions = array();

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
	 * Cron requests in a row that started too late to tick the job and put
	 * the tick off (JobActions::cron_tick()). Back to 0 when a tick makes
	 * progress (JobRepository::save_progress()), on retry and on an answer.
	 *
	 * @var int
	 */
	public $cron_deferrals = 0;

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
	 * What kind of failure ended the job: FAILURE_TEMPORARY (a problem of the
	 * moment outlasted the runner's retries: a disk full, the database away),
	 * FAILURE_FINAL (the job's work files were lost or damaged (WorkLost), or
	 * its question went unanswered: a retry cannot succeed), or '' (any other
	 * cause, the storage directory changing included, a failure recorded before
	 * kinds existed, a kind that does not belong to this failure, or not
	 * failed). Read from the row through read_failure_kind().
	 *
	 * @var string
	 */
	public $failure_kind = '';

	/**
	 * Why a final failure is final when it is not lost work files
	 * (REASON_TABLE_CHANGED), or ''. Read with failure_kind.
	 *
	 * @var string
	 */
	public $failure_reason = '';

	/**
	 * Columns of Schema::COLUMNS that the row read from the table did not
	 * have (the table was not migrated, or a column was removed); not a
	 * column itself. The Runner fails such a job instead of reading the
	 * defaults of its properties as values.
	 *
	 * @var string[]
	 */
	public $missing_columns = array();

	/**
	 * When a failed job's work files were reclaimed after their retention
	 * period (0: still there, or never any). A failed job with this set
	 * cannot be retried: the position in its cursor points at files that
	 * no longer exist.
	 *
	 * @var int
	 */
	public $work_expired_at = 0;

	/**
	 * Takeovers in a row at the same position: how many times a driver took
	 * the job over from a run that ended without releasing the lock (killed
	 * at the time limit, a fatal error at the memory limit, a crash) while
	 * the job stood at the same step and cursor (takeover_mark). Reset to 1
	 * when the position differs, to 0 on retry. The Runner fails the job at
	 * Runner::MAX_TAKEOVERS: no other counter sees a run that never returns.
	 *
	 * @var int
	 */
	public $takeovers = 0;

	/**
	 * Position of the last takeover: MD5 of the step and the stored cursor.
	 *
	 * @var string
	 */
	public $takeover_mark = '';

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
	 * Unix timestamp of the last write to the row, whatever wrote it:
	 * every save, transition, heartbeat, refused tick (record_blocked())
	 * and answer. Read by the retention rule for unanswered jobs
	 * (JobRepository::reap(): a paused job waiting for an answer is given
	 * up WORK_RETENTION_SECONDS after this), which is why a refused tick
	 * of such a job must not write it (gate() checks awaiting_answer()
	 * first) and nothing else touches a waiting job's row. Not a progress
	 * clock: see progress_at.
	 *
	 * @var int
	 */
	public $updated_at = 0;

	/**
	 * Unix timestamp of the last real progress: a cursor that moved, a step
	 * that finished (save_progress() with advanced = true), or a person's
	 * answer (JobRepository::answer(): the decision is progress, a job
	 * resumed days later starts with a fresh clock). Not moved by wait,
	 * retries, zero-progress units, identical checkpoints, refused ticks
	 * or asking a question. Read by the stall rule (JobRepository::reap():
	 * an unlocked queued or running job is given up STALL_SECONDS after
	 * this; paused jobs are not subject to it).
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
	 * Whether no further transition is possible. An unknown status (a
	 * tampered row) is not terminal, and allows() rejects every move from it.
	 *
	 * @return bool
	 */
	public function is_terminal(): bool {
		return isset( self::TRANSITIONS[ $this->status ] ) && array() === self::TRANSITIONS[ $this->status ];
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
