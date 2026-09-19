<?php
/**
 * What a step sees while it runs.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Support\Logger;

/**
 * Cursor, budget, logger and checkpointing for one Step::run() call. Pure
 * PHP: the clock and the memory reader are injected, so tests drive both.
 *
 * Time is wall-clock time since the start the driver passed to the runner
 * (PHP's max_execution_time only counts CPU time, but the web server's
 * timeout that actually kills a request is wall-clock). Memory is the real
 * size the Zend allocator took from the system (what memory_limit is
 * enforced against), compared as growth since the tick started and, when
 * the limit is known, as an absolute value with headroom.
 */
final class JobContext {

	/**
	 * Cursor keys starting with this belong to the runner, never to a step.
	 */
	const RESERVED_PREFIX = '__runner';

	const STOP_TIME   = 'time';
	const STOP_MEMORY = 'memory';

	/**
	 * Checkpoint rhythm: persist the cursor after this many seconds or this
	 * many bytes since the last checkpoint, whichever comes first. Every step
	 * uses these two numbers; 16 MiB is also the archive's hash chunk and
	 * database chunk size, so a finished chunk is a natural checkpoint.
	 */
	const CHECKPOINT_SECONDS = 2.0;
	const CHECKPOINT_BYTES   = 16777216;

	/**
	 * Job.
	 *
	 * @var Job
	 */
	private $job;

	/**
	 * Step cursor (reserved keys removed).
	 *
	 * @var array<string, mixed>
	 */
	private $cursor;

	/**
	 * Budget.
	 *
	 * @var Budget
	 */
	private $budget;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Returns the current time as a float.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Returns the current real memory usage in bytes.
	 *
	 * @var callable
	 */
	private $memory;

	/**
	 * When the tick started.
	 *
	 * @var float
	 */
	private $started_at;

	/**
	 * Real memory usage when the tick started.
	 *
	 * @var int
	 */
	private $memory_at_start;

	/**
	 * The memory_limit of this process in bytes; <= 0 when unlimited or unknown.
	 *
	 * @var int
	 */
	private $memory_limit;

	/**
	 * Persists a checkpoint; null when checkpointing is not possible (cleanup).
	 *
	 * @var callable|null
	 */
	private $checkpoint;

	/**
	 * When the cursor was last persisted (tick start before the first checkpoint).
	 *
	 * @var float
	 */
	private $checkpointed_at;

	/**
	 * Constructor.
	 *
	 * @param Job                  $job             Job.
	 * @param array<string, mixed> $cursor          Cursor (reserved keys are removed).
	 * @param Budget               $budget          Budget.
	 * @param Logger               $logger          Logger.
	 * @param callable             $clock           Returns the current time (float seconds).
	 * @param callable             $memory          Returns real memory usage in bytes.
	 * @param float                $started_at      Start of the tick.
	 * @param int                  $memory_limit    memory_limit in bytes; <= 0 unlimited or unknown.
	 * @param callable|null        $checkpoint      function( array $cursor, int $percent, string $message ): void.
	 */
	public function __construct( Job $job, array $cursor, Budget $budget, Logger $logger, callable $clock, callable $memory, float $started_at, int $memory_limit, $checkpoint = null ) {
		$this->job             = $job;
		$this->cursor          = self::strip_reserved( $cursor );
		$this->budget          = $budget;
		$this->logger          = $logger;
		$this->clock           = $clock;
		$this->memory          = $memory;
		$this->started_at      = $started_at;
		$this->memory_at_start = (int) call_user_func( $memory );
		$this->memory_limit    = $memory_limit;
		$this->checkpoint      = is_callable( $checkpoint ) ? $checkpoint : null;
		$this->checkpointed_at = $started_at;
	}

	/**
	 * Job.
	 *
	 * @return Job
	 */
	public function job(): Job {
		return $this->job;
	}

	/**
	 * Where the step stopped last time (empty at the start of a step).
	 *
	 * @return array<string, mixed>
	 */
	public function cursor(): array {
		return $this->cursor;
	}

	/**
	 * Budget of this tick.
	 *
	 * @return Budget
	 */
	public function budget(): Budget {
		return $this->budget;
	}

	/**
	 * Job log.
	 *
	 * @return Logger
	 */
	public function logger(): Logger {
		return $this->logger;
	}

	/**
	 * Storage directory the job is bound to. Temporary files go to
	 * work_path() and finished results to backups/ below this directory
	 * (the store step moves them there), nowhere else. Take it from the
	 * context on every run(); never keep it in the cursor or a property
	 * across ticks, because the engine only touches files inside the
	 * current storage directory and that directory can change between
	 * ticks.
	 *
	 * @return string
	 */
	public function storage_path(): string {
		return $this->job->storage_path;
	}

	/**
	 * The job's own work directory, tmp/job-<id>/ under storage_path(),
	 * created on first use. Every temporary file a step writes (volumes
	 * being packed, extracted indexes, staged files) goes below it, and
	 * the engine removes the whole directory when the job is cancelled,
	 * when a failed job's files pass their retention, and as an orphan.
	 * Steps create their own subdirectories as they need them. Like
	 * storage_path(), take it from the context on every run().
	 *
	 * @return string
	 * @throws TransientFailure When the directory cannot be created (no space, no permission): retried with back-off.
	 */
	public function work_path(): string {
		$base = $this->job->storage_path;
		if ( '' === $base || $this->job->id <= 0 ) {
			throw new TransientFailure( 'The job has no storage directory yet.' );
		}
		$dir = Residue::work_dir( $base, $this->job->id );
		// is_dir() follows a link planted at this name (write access inside tmp/ is the web user already);
		// the reclaim side treats such an entry as a link and never enters it.
		// Silenced: a PHP warning would put the full path into the error log, bypassing the path masking.
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700 ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- see above.
			throw new TransientFailure( 'The work directory could not be created.' );
		}
		return $dir;
	}

	/**
	 * Seconds since the tick started.
	 *
	 * @return float
	 */
	public function elapsed(): float {
		return max( 0.0, (float) call_user_func( $this->clock ) - $this->started_at );
	}

	/**
	 * Seconds left in the time budget.
	 *
	 * @return float
	 */
	public function remaining_seconds(): float {
		return max( 0.0, $this->budget->seconds - $this->elapsed() );
	}

	/**
	 * Bytes the step may still allocate.
	 *
	 * @return int
	 */
	public function remaining_memory(): int {
		$usage     = (int) call_user_func( $this->memory );
		$remaining = $this->budget->memory_bytes - ( $usage - $this->memory_at_start );
		if ( $this->memory_limit > 0 ) {
			$remaining = min( $remaining, $this->memory_limit - Budget::MEMORY_HEADROOM - $usage );
		}
		return max( 0, $remaining );
	}

	/**
	 * Why the step should stop, or empty.
	 *
	 * @return string
	 */
	public function stop_reason(): string {
		if ( $this->elapsed() >= $this->budget->seconds ) {
			return self::STOP_TIME;
		}
		if ( $this->remaining_memory() <= 0 ) {
			return self::STOP_MEMORY;
		}
		return '';
	}

	/**
	 * Whether the step must return now (budget spent).
	 *
	 * @return bool
	 */
	public function should_stop(): bool {
		return '' !== $this->stop_reason();
	}

	/**
	 * Whether it is time to persist the cursor: more than CHECKPOINT_SECONDS
	 * since the last checkpoint (or the tick start), or at least
	 * CHECKPOINT_BYTES processed since then. Steps call this inside a unit
	 * of work and checkpoint() when it says so, instead of choosing their
	 * own frequency: a tighter one turns the database into the bottleneck, a
	 * looser one redoes too much after a dead tick.
	 *
	 * @param int $bytes_since_last Bytes processed since the last checkpoint.
	 * @return bool
	 */
	public function should_checkpoint( int $bytes_since_last ): bool {
		if ( $bytes_since_last >= self::CHECKPOINT_BYTES ) {
			return true;
		}
		return (float) call_user_func( $this->clock ) - $this->checkpointed_at >= self::CHECKPOINT_SECONDS;
	}

	/**
	 * Persist the position in the middle of a step, and reset the checkpoint
	 * clock (see should_checkpoint()). Throws LockLost when the lock is no
	 * longer held: the step must not catch it.
	 *
	 * @param array<string, mixed> $cursor  Cursor.
	 * @param int                  $percent Progress within the step.
	 * @param string               $message Progress text.
	 * @return void
	 * @throws \LogicException When the context has no checkpointing (cleanup).
	 */
	public function checkpoint( array $cursor, int $percent, string $message = '' ): void {
		if ( null === $this->checkpoint ) {
			throw new \LogicException( 'Checkpoints are only possible while the step runs.' );
		}
		$this->cursor = self::strip_reserved( $cursor );
		call_user_func( $this->checkpoint, $this->cursor, max( 0, min( 100, $percent ) ), $message );
		$this->checkpointed_at = (float) call_user_func( $this->clock );
	}

	/**
	 * Remove the runner's reserved keys from a cursor.
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @return array<string, mixed>
	 */
	public static function strip_reserved( array $cursor ): array {
		foreach ( array_keys( $cursor ) as $key ) {
			if ( is_string( $key ) && 0 === strpos( $key, self::RESERVED_PREFIX ) ) {
				unset( $cursor[ $key ] );
			}
		}
		return $cursor;
	}
}
