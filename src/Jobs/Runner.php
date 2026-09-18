<?php
/**
 * Runs the steps of a job within one tick's budget.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Support\Environment;
use WPCheckpoint\Support\Logger;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Support\Report;

defined( 'ABSPATH' ) || exit;

/**
 * One tick: gate, acquire the lock, run steps until the budget is spent or
 * the job is done, release. Every write after the acquire is fenced by the
 * lock token; when a fenced write refuses (cancelled, taken over) the runner
 * stops at once (LockLost) and writes nothing more.
 *
 * The runner keeps its own state (retry and no-progress counters) inside the
 * cursor under a reserved key that steps never see and cannot overwrite.
 */
final class Runner {

	/**
	 * TransientFailure is retried this many times (back-off 5, 15, 60, 300 s).
	 */
	const MAX_RETRIES = 5;

	/**
	 * No-progress guard: a step that returns progress with an unchanged
	 * cursor this many times in a row fails the job. A checkpoint with an
	 * unchanged cursor does not count as progress either. It catches a unit
	 * of work that cannot fit into one budget; a step that knows it cannot
	 * proceed should throw instead of relying on it.
	 */
	const MAX_NO_PROGRESS = 3;

	/**
	 * StepResult::wait() is capped at this many seconds (the gate back-off maximum).
	 */
	const MAX_WAIT_SECONDS   = 300;
	const BUSY_RETRY_SECONDS = 5;
	const RESERVED_KEY       = JobContext::RESERVED_PREFIX;

	/**
	 * Repository.
	 *
	 * @var JobRepository
	 */
	private $repository;

	/**
	 * Job types.
	 *
	 * @var JobTypes
	 */
	private $types;

	/**
	 * Redactor for logs and error messages.
	 *
	 * @var Redactor
	 */
	private $redactor;

	/**
	 * Returns the current time as a float.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Returns real memory usage in bytes.
	 *
	 * @var callable
	 */
	private $memory;

	/**
	 * Budget, or a callable( int $memory_at_start ): Budget.
	 *
	 * @var Budget|callable|null
	 */
	private $budget;

	/**
	 * Lock lease in seconds.
	 *
	 * @var int
	 */
	private $lease;

	/**
	 * The memory_limit of this process in bytes; <= 0 unlimited or unknown.
	 *
	 * @var int
	 */
	private $memory_limit;

	/**
	 * Placeholder => path, masked in error messages.
	 *
	 * @var array<string, string>
	 */
	private $paths;

	/**
	 * Constructor.
	 *
	 * @param JobRepository        $repository Repository.
	 * @param JobTypes             $types      Job types.
	 * @param Redactor             $redactor   Redactor.
	 * @param array<string, mixed> $options    clock (callable: float), memory (callable: int), budget (Budget|callable), lease (int), memory_limit (int bytes), paths (placeholder => path).
	 */
	public function __construct( JobRepository $repository, JobTypes $types, Redactor $redactor, array $options = array() ) {
		$this->repository = $repository;
		$this->types      = $types;
		$this->redactor   = $redactor;
		$this->clock      = isset( $options['clock'] ) && is_callable( $options['clock'] ) ? $options['clock'] : static function (): float {
			return microtime( true );
		};
		$this->memory     = isset( $options['memory'] ) && is_callable( $options['memory'] ) ? $options['memory'] : static function (): int {
			return memory_get_usage( true );
		};
		$this->budget     = isset( $options['budget'] ) && ( $options['budget'] instanceof Budget || is_callable( $options['budget'] ) ) ? $options['budget'] : null;
		$this->lease      = isset( $options['lease'] ) ? max( 10, (int) $options['lease'] ) : JobRepository::LOCK_SECONDS;
		if ( array_key_exists( 'memory_limit', $options ) ) {
			$this->memory_limit = (int) $options['memory_limit'];
		} else {
			$this->memory_limit = (int) wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		}
		$this->paths = isset( $options['paths'] ) && is_array( $options['paths'] ) ? $options['paths'] : array(
			'{abspath}'    => rtrim( ABSPATH, '/\\' ),
			'{wp-content}' => WP_CONTENT_DIR,
		);
	}

	/**
	 * Advance a job as far as one budget allows.
	 *
	 * @param int        $job_id     Job id.
	 * @param float|null $started_at When the budget started: the web driver passes the request start
	 *                               (bootstrap time counts), a CLI loop passes the current time before
	 *                               each tick; null means now.
	 * @return TickResult
	 */
	public function tick( int $job_id, $started_at = null ): TickResult {
		$job = $this->repository->find( $job_id );
		if ( null === $job ) {
			return new TickResult( TickResult::MISSING, -1, null );
		}
		if ( ! in_array( $job->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
			return new TickResult( TickResult::FINISHED, -1, $job );
		}

		$gate = $this->repository->gate( $job );
		if ( ! $gate['allowed'] ) {
			// The wait for this refusal comes from the gate (5, 15, 60, 300 s); record_blocked() prepares the next one.
			$this->repository->record_blocked( $job );
			return new TickResult( TickResult::BLOCKED, $gate['retry_after'], $job, $gate['message'] );
		}

		$held = $this->repository->acquire( $job_id, $this->lease );
		if ( null === $held ) {
			$job = $this->repository->find( $job_id );
			if ( null === $job ) {
				return new TickResult( TickResult::MISSING, -1, null );
			}
			if ( ! in_array( $job->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				return new TickResult( TickResult::FINISHED, -1, $job );
			}
			return new TickResult( TickResult::BUSY, self::BUSY_RETRY_SECONDS, $job, __( 'Another process is working on this job.', 'wp-checkpoint' ) );
		}

		$job    = $held['job'];
		$token  = $held['token'];
		$start  = is_numeric( $started_at ) ? (float) $started_at : $this->now();
		$logger = $this->logger_for( $job );
		$budget = $this->budget_for( (int) call_user_func( $this->memory ) );

		try {
			return $this->run_steps( $job, $token, $logger, $budget, $start );
		} catch ( LockLost $e ) {
			$logger->warning( 'Lock lost; stopping without further writes', array( 'error' => $this->describe( $e ) ) );
			return new TickResult( TickResult::LOST, 0, $this->repository->find( $job_id ), __( 'The job was cancelled or taken over by another process.', 'wp-checkpoint' ) );
		}
	}

	/**
	 * The step loop of one tick, under the lock.
	 *
	 * @param Job    $job    Job (updated in place).
	 * @param string $token  Lock token.
	 * @param Logger $logger Job log.
	 * @param Budget $budget Budget.
	 * @param float  $start  When the budget started.
	 * @return TickResult
	 * @throws LockLost When a fenced write refused; nothing is written afterwards.
	 */
	private function run_steps( Job $job, string $token, Logger $logger, Budget $budget, float $start ): TickResult {
		$type = $this->types->get( $job->type );
		if ( null === $type ) {
			return $this->fail( $job, $token, $logger, sprintf( 'Unknown job type "%s".', $job->type ) );
		}
		$steps = array();
		foreach ( $type->steps() as $step ) {
			if ( $step instanceof Step ) {
				$steps[ $step->id() ] = $step;
			}
		}
		if ( array() === $steps ) {
			return $this->fail( $job, $token, $logger, sprintf( 'Job type "%s" has no steps.', $job->type ) );
		}
		$ids = array_keys( $steps );

		$logger->info(
			'Tick started',
			array(
				'attempt'        => $job->attempts,
				'step'           => '' === $job->step ? $ids[0] : $job->step,
				'budget_seconds' => $budget->seconds,
				'budget_mb'      => (int) round( $budget->memory_bytes / 1048576 ),
				'assumed'        => $budget->assumed,
			)
		);

		while ( true ) {
			$step_id = '' === $job->step ? $ids[0] : $job->step;
			if ( ! isset( $steps[ $step_id ] ) ) {
				return $this->fail( $job, $token, $logger, sprintf( 'Unknown step "%s".', $step_id ) );
			}
			$step   = $steps[ $step_id ];
			$index  = (int) array_search( $step_id, $ids, true );
			$count  = count( $ids );
			$state  = $this->state_of( $job->cursor );
			$before = wp_json_encode( JobContext::strip_reserved( $job->cursor ) );

			$context = $this->context(
				$job,
				$job->cursor,
				$budget,
				$logger,
				$start,
				function ( array $cursor, int $percent, string $message ) use ( &$job, $token, $step_id, $index, $count, &$state ) {
					// A checkpoint is progress only when the cursor moved; an identical one keeps the counters and the stall timestamp.
					$advanced = wp_json_encode( JobContext::strip_reserved( $job->cursor ) ) !== wp_json_encode( JobContext::strip_reserved( $cursor ) );
					if ( $advanced ) {
						$state = $this->reset( $state );
					}
					$this->persist( $job, $token, $step_id, $cursor, $state, self::overall( $index, $count, $percent ), $message, $advanced );
					$this->maybe_heartbeat( $job, $token );
				}
			);

			try {
				$result = $step->run( $context );
			} catch ( LockLost $e ) {
				throw $e; // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- rethrown unchanged.
			} catch ( StaleJob $e ) {
				throw new LockLost( $e->getMessage(), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
			} catch ( TransientFailure $e ) {
				$state['retries'] = (int) $state['retries'] + 1;
				$message          = $this->describe( $e );
				if ( $state['retries'] > self::MAX_RETRIES ) {
					return $this->fail( $job, $token, $logger, sprintf( 'Step "%s" failed %d times: %s', $step_id, $state['retries'], $message ) );
				}
				$wait = JobRepository::BACKOFF_SECONDS[ min( $state['retries'] - 1, count( JobRepository::BACKOFF_SECONDS ) - 1 ) ];
				$logger->warning(
					'Step failed; will retry',
					array(
						'step'    => $step_id,
						'attempt' => $state['retries'],
						'wait'    => $wait,
						'error'   => $message,
					)
				);
				$this->persist( $job, $token, $step_id, $context->cursor(), $state, $job->progress, $job->progress_message, false );
				$this->release( $job, $token );
				return new TickResult( TickResult::WAITING, $wait, $job, $message );
			} catch ( \Throwable $e ) {
				return $this->fail( $job, $token, $logger, sprintf( 'Step "%s": %s', $step_id, $this->describe( $e ) ) );
			}

			if ( StepResult::DONE === $result->kind ) {
				$logger->info( 'Step done', array( 'step' => $step_id ) );
				$state = $this->reset( $state );
				if ( $index + 1 >= $count ) {
					$this->persist( $job, $token, $step_id, array(), $state, 100, $result->message, true );
					$this->transition( $job, $token, Job::COMPLETED, '' );
					$logger->info( 'Job completed' );
					return new TickResult( TickResult::COMPLETED, -1, $job, $result->message );
				}
				$this->persist( $job, $token, $ids[ $index + 1 ], array(), $state, self::overall( $index + 1, $count, 0 ), $result->message, true );
				$this->maybe_heartbeat( $job, $token );
				if ( $context->should_stop() ) {
					return $this->pause( $job, $token, $logger, $context->stop_reason() );
				}
				continue;
			}

			if ( StepResult::WAIT === $result->kind ) {
				$seconds = $result->seconds;
				if ( $seconds > self::MAX_WAIT_SECONDS ) {
					$logger->info(
						'Wait shortened to the maximum',
						array(
							'requested' => $seconds,
							'maximum'   => self::MAX_WAIT_SECONDS,
						)
					);
					$seconds = self::MAX_WAIT_SECONDS;
				}
				$logger->info(
					'Step is waiting',
					array(
						'step'    => $step_id,
						'seconds' => $seconds,
						'reason'  => $result->message,
					)
				);
				$this->persist( $job, $token, $step_id, $result->cursor, $state, $job->progress, $result->message, false );
				$this->release( $job, $token );
				return new TickResult( TickResult::WAITING, $seconds, $job, $result->message );
			}

			// Progress: compared with the cursor before run(), so checkpoints made during the run count.
			$after   = wp_json_encode( JobContext::strip_reserved( $result->cursor ) );
			$percent = self::overall( $index, $count, $result->percent );
			if ( $before === $after ) {
				$state['no_progress'] = (int) $state['no_progress'] + 1;
				if ( $state['no_progress'] >= self::MAX_NO_PROGRESS ) {
					return $this->fail( $job, $token, $logger, sprintf( 'Step "%s" could not make progress within the budget (%d attempts).', $step_id, $state['no_progress'] ) );
				}
				$logger->warning(
					'Step made no progress',
					array(
						'step'    => $step_id,
						'attempt' => $state['no_progress'],
					)
				);
				$this->persist( $job, $token, $step_id, $result->cursor, $state, $percent, $result->message, false );
				$this->release( $job, $token );
				return new TickResult( TickResult::MORE, 0, $job, $result->message );
			}
			$state = $this->reset( $state );
			$this->persist( $job, $token, $step_id, $result->cursor, $state, $percent, $result->message, true );
			$this->maybe_heartbeat( $job, $token );
			if ( $context->should_stop() ) {
				return $this->pause( $job, $token, $logger, $context->stop_reason() );
			}
		}
	}

	/**
	 * Run the cleanup of every step up to and including the current one,
	 * after a job was cancelled. Best effort: each step's failure is logged.
	 *
	 * Only for cancelled jobs. A failed job is never cleaned up: it keeps its
	 * cursor and its temporary files for a retry. Nothing reclaims those
	 * files yet (the purge only removes the lock file and the log); T013
	 * adds the per-job work directory that the purge and the reaper remove.
	 *
	 * @param Job $job Cancelled job.
	 * @return int Steps cleaned.
	 */
	public function cleanup( Job $job ): int {
		$type = $this->types->get( $job->type );
		if ( null === $type ) {
			return 0;
		}
		$logger  = $this->logger_for( $job );
		$budget  = $this->budget_for( (int) call_user_func( $this->memory ) );
		$context = $this->context( $job, $job->cursor, $budget, $logger, $this->now(), null );
		$cleaned = 0;
		foreach ( $type->steps() as $step ) {
			if ( ! $step instanceof Step ) {
				continue;
			}
			try {
				$step->cleanup( $context );
				++$cleaned;
			} catch ( \Throwable $e ) {
				$logger->warning(
					'Cleanup failed',
					array(
						'step'  => $step->id(),
						'error' => $this->describe( $e ),
					)
				);
			}
			if ( $step->id() === $job->step ) {
				break;
			}
		}
		$logger->info( 'Cleanup finished', array( 'steps' => $cleaned ) );
		return $cleaned;
	}

	/**
	 * Current time.
	 *
	 * @return float
	 */
	private function now(): float {
		return (float) call_user_func( $this->clock );
	}

	/**
	 * Budget for this tick.
	 *
	 * @param int $memory_at_start Real memory usage at the start.
	 * @return Budget
	 */
	private function budget_for( int $memory_at_start ): Budget {
		if ( $this->budget instanceof Budget ) {
			return $this->budget;
		}
		if ( is_callable( $this->budget ) ) {
			$budget = call_user_func( $this->budget, $memory_at_start );
			if ( $budget instanceof Budget ) {
				return $budget;
			}
		}
		return Budget::from_probe( Environment::cached_runtime(), $memory_at_start );
	}

	/**
	 * Logger writing to the job's log file inside the storage directory.
	 *
	 * @param Job $job Job.
	 * @return Logger
	 */
	private function logger_for( Job $job ): Logger {
		$relative = '' !== $job->log_path ? $job->log_path : 'logs/job-' . $job->id . '.log';
		return new Logger( $job->storage_path . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative ), $this->redactor );
	}

	/**
	 * Build a step context.
	 *
	 * @param Job                  $job        Job.
	 * @param array<string, mixed> $cursor     Stored cursor.
	 * @param Budget               $budget     Budget.
	 * @param Logger               $logger     Logger.
	 * @param float                $started_at Tick start.
	 * @param callable|null        $checkpoint Checkpoint callback.
	 * @return JobContext
	 */
	private function context( Job $job, array $cursor, Budget $budget, Logger $logger, float $started_at, $checkpoint ): JobContext {
		return new JobContext( $job, $cursor, $budget, $logger, $this->clock, $this->memory, $started_at, $this->memory_limit, $checkpoint );
	}

	/**
	 * Runner state stored in the cursor.
	 *
	 * @param array<string, mixed> $cursor Stored cursor.
	 * @return array{retries: int, no_progress: int}
	 */
	private function state_of( array $cursor ): array {
		$state = isset( $cursor[ self::RESERVED_KEY ] ) && is_array( $cursor[ self::RESERVED_KEY ] ) ? $cursor[ self::RESERVED_KEY ] : array();
		return array(
			'retries'     => isset( $state['retries'] ) ? (int) $state['retries'] : 0,
			'no_progress' => isset( $state['no_progress'] ) ? (int) $state['no_progress'] : 0,
		);
	}

	/**
	 * State after real progress.
	 *
	 * @param array{retries: int, no_progress: int} $state State.
	 * @return array{retries: int, no_progress: int}
	 */
	private function reset( array $state ): array {
		$state['retries']     = 0;
		$state['no_progress'] = 0;
		return $state;
	}

	/**
	 * Overall percentage from the step index and the progress inside it.
	 *
	 * @param int $index   Step index.
	 * @param int $count   Number of steps.
	 * @param int $percent Progress within the step.
	 * @return int
	 */
	public static function overall( int $index, int $count, int $percent ): int {
		if ( $count <= 0 ) {
			return 0;
		}
		$percent = max( 0, min( 100, $percent ) );
		return (int) floor( ( $index + $percent / 100 ) / $count * 100 );
	}

	/**
	 * Fenced cursor write: the step's cursor is stripped of reserved keys,
	 * then the runner's state is added.
	 *
	 * @param Job                                   $job      Job (updated in place).
	 * @param string                                $token    Lock token.
	 * @param string                                $step     Step id.
	 * @param array<string, mixed>                  $cursor   Step cursor.
	 * @param array{retries: int, no_progress: int} $state    Runner state.
	 * @param int                                   $percent  Overall progress.
	 * @param string                                $message  Progress text.
	 * @param bool                                  $advanced Whether real progress was made (drives the stall timestamp).
	 * @return void
	 * @throws LockLost When the write refused.
	 */
	private function persist( Job $job, string $token, string $step, array $cursor, array $state, int $percent, string $message, bool $advanced ): void {
		$cursor                       = JobContext::strip_reserved( $cursor );
		$cursor[ self::RESERVED_KEY ] = $state;
		try {
			$this->repository->save_progress( $job, $token, $step, $cursor, $percent, $message, $advanced );
		} catch ( StaleJob $e ) {
			throw new LockLost( $e->getMessage(), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
		}
	}

	/**
	 * Extend the lease when less than half of it is left.
	 *
	 * @param Job    $job   Job.
	 * @param string $token Lock token.
	 * @return void
	 * @throws LockLost When the heartbeat refused.
	 */
	private function maybe_heartbeat( Job $job, string $token ): void {
		if ( $job->locked_until - (int) floor( $this->now() ) >= (int) ( $this->lease / 2 ) ) {
			return;
		}
		if ( ! $this->repository->heartbeat( $job, $token, $this->lease ) ) {
			throw new LockLost( sprintf( 'Job %d: the lock is no longer held.', $job->id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
		}
	}

	/**
	 * Fenced status change.
	 *
	 * @param Job    $job   Job.
	 * @param string $token Lock token.
	 * @param string $to    Target status.
	 * @param string $error Error message.
	 * @return void
	 * @throws LockLost When the write refused.
	 */
	private function transition( Job $job, string $token, string $to, string $error ): void {
		try {
			$this->repository->transition( $job, $to, $error, $token );
		} catch ( StaleJob $e ) {
			throw new LockLost( $e->getMessage(), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
		}
	}

	/**
	 * Give the lock back between ticks; a refusal means it was lost.
	 *
	 * @param Job    $job   Job.
	 * @param string $token Lock token.
	 * @return void
	 * @throws LockLost When the lock was not ours.
	 */
	private function release( Job $job, string $token ): void {
		if ( ! $this->repository->release( $job, $token ) ) {
			throw new LockLost( sprintf( 'Job %d: the lock is no longer held.', $job->id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
		}
	}

	/**
	 * Budget spent: release and ask for the next tick.
	 *
	 * @param Job    $job    Job.
	 * @param string $token  Lock token.
	 * @param Logger $logger Logger.
	 * @param string $reason Stop reason.
	 * @return TickResult
	 */
	private function pause( Job $job, string $token, Logger $logger, string $reason ): TickResult {
		$logger->info(
			'Budget spent',
			array(
				'reason'   => $reason,
				'progress' => $job->progress,
			)
		);
		$this->release( $job, $token );
		return new TickResult( TickResult::MORE, 0, $job, $job->progress_message );
	}

	/**
	 * Fail the job (fenced).
	 *
	 * @param Job    $job     Job.
	 * @param string $token   Lock token.
	 * @param Logger $logger  Logger.
	 * @param string $message Error message (paths already masked).
	 * @return TickResult
	 */
	private function fail( Job $job, string $token, Logger $logger, string $message ): TickResult {
		$logger->error( 'Job failed', array( 'error' => $message ) );
		$this->transition( $job, $token, Job::FAILED, $message );
		return new TickResult( TickResult::FAILED, -1, $job, $this->redactor->redact( $message ) );
	}

	/**
	 * Class and message of an exception, with installation paths masked
	 * (fail closed to a fixed text). Secrets are redacted by the logger and
	 * the repository when the text is stored.
	 *
	 * @param \Throwable $e Exception.
	 * @return string
	 */
	private function describe( \Throwable $e ): string {
		$class  = get_class( $e );
		$short  = false !== strrpos( $class, '\\' ) ? substr( $class, strrpos( $class, '\\' ) + 1 ) : $class;
		$text   = $short . ': ' . $e->getMessage();
		$paths  = $this->paths;
		$masked = Report::mask_paths( $text, $paths );
		return is_string( $masked ) ? $masked : $short . ': ' . Report::failure_text();
	}
}
