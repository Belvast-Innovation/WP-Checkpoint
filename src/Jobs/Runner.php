<?php
/**
 * Runs the steps of a job within one tick's budget.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Archive\ConcurrentWriter;
use WPCheckpoint\Restore\LedgerOutdated;
use WPCheckpoint\Support\Environment;
use WPCheckpoint\Support\Logger;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Support\Thresholds;
use WPCheckpoint\Support\Report;
use WPCheckpoint\Support\Schema;

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
	 * Takeovers at the same position before the job fails (see JobRepository::acquire()).
	 */
	const MAX_TAKEOVERS = 3;

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
	 * Called before every cursor write (tests only; see persist()).
	 *
	 * @var callable|null
	 */
	private $on_persist;

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
		$this->on_persist = isset( $options['on_persist'] ) && is_callable( $options['on_persist'] ) ? $options['on_persist'] : null;
		$this->paths      = isset( $options['paths'] ) && is_array( $options['paths'] ) ? $options['paths'] : array(
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
	 * @param string[]   $schema     What Schema::ensure() found the job table lacks (its problems), when the
	 *                               driver's migration failed; the job's own row may show more.
	 * @return TickResult
	 */
	public function tick( int $job_id, $started_at = null, array $schema = array() ): TickResult {
		$job = $this->repository->find( $job_id );
		if ( null === $job ) {
			return new TickResult( TickResult::MISSING, -1, null );
		}
		if ( ! in_array( $job->status, array( Job::QUEUED, Job::RUNNING, Job::PAUSED ), true ) ) {
			return new TickResult( TickResult::FINISHED, -1, $job );
		}
		$problems = array_values( array_unique( array_merge( $schema, $job->missing_columns ) ) );
		if ( array() !== $problems ) {
			// The table lacks columns this code writes and reads, or holds them narrower: a later write would
			// fail, or a default would stand in for a value. Failed with the reason, before anything else.
			return $this->fail_for_missing_columns( $job, $problems );
		}

		$gate = $this->repository->gate( $job );
		if ( ! $gate['allowed'] ) {
			if ( 'awaiting_answer' === $gate['reason'] ) {
				// Nothing to wait out: the job resumes when the answers are stored.
				return new TickResult( TickResult::PAUSED, -1, $job, $gate['message'] );
			}
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
			if ( $job->awaiting_answer() ) {
				return new TickResult( TickResult::PAUSED, -1, $job, __( 'The job is waiting for your decision.', 'wp-checkpoint' ) );
			}
			if ( ! in_array( $job->status, array( Job::QUEUED, Job::RUNNING, Job::PAUSED ), true ) ) {
				return new TickResult( TickResult::FINISHED, -1, $job );
			}
			return new TickResult( TickResult::BUSY, self::BUSY_RETRY_SECONDS, $job, __( 'Another process is working on this job.', 'wp-checkpoint' ) );
		}

		$job    = $held['job'];
		$token  = $held['token'];
		$start  = is_numeric( $started_at ) ? (float) $started_at : $this->now();
		$logger = $this->logger_for( $job );
		if ( ! empty( $held['taken_over'] ) ) {
			$logger->warning(
				'The previous run ended without finishing; continuing from the last checkpoint',
				array(
					'step'      => $job->step,
					'takeovers' => $job->takeovers,
				)
			);
			if ( $job->takeovers >= self::MAX_TAKEOVERS ) {
				try {
					return $this->fail( $job, $token, $logger, self::takeover_message( $job ) );
				} catch ( LockLost $lost ) {
					return new TickResult( TickResult::LOST, 0, $this->repository->find( $job_id ), __( 'The job was cancelled or taken over by another process.', 'wp-checkpoint' ) );
				}
			}
		}
		if ( ! empty( $held['healed'] ) ) {
			// Recorded, not hidden: the questions of a pause that never completed (or of a job failed while
			// waiting and retried) were cleared; the step asks again if it still needs to.
			$logger->warning( 'Stale questions cleared: the job was not paused when it carried them' );
		}
		try {
			$budget = $this->budget_for( (int) call_user_func( $this->memory ) );
		} catch ( BudgetExhausted $e ) {
			// The real cause, once, instead of three empty ticks and "could not make progress".
			try {
				return $this->fail( $job, $token, $logger, $e->getMessage() );
			} catch ( LockLost $lost ) {
				return new TickResult( TickResult::LOST, 0, $this->repository->find( $job_id ), __( 'The job was cancelled or taken over by another process.', 'wp-checkpoint' ) );
			}
		}

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
				},
				$token
			);

			try {
				$result = $step->run( $context );
			} catch ( LockLost $e ) {
				throw $e; // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- rethrown unchanged.
			} catch ( StaleJob $e ) {
				throw new LockLost( $e->getMessage(), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
			} catch ( TransientFailure $e ) {
				if ( $e instanceof ConcurrentWriter ) {
					// Kept in the job log only (never in last_error): the one trace of two processes on one work
					// directory, and what to look for when a backup made around a takeover is questioned.
					$logger->warning( 'Another process wrote the same work directory; the volume is cut back to the last checkpoint and the step retried', array( 'step' => $step_id ) );
				}
				$state['retries'] = (int) $state['retries'] + 1;
				$message          = $this->describe( $e );
				if ( $state['retries'] > self::MAX_RETRIES ) {
					// A problem of the moment that outlasted the back-off (a full disk, the database away): retrying may help.
					return $this->fail( $job, $token, $logger, sprintf( 'Step "%s" failed %d times: %s', $step_id, $state['retries'], $message ), Job::FAILURE_TEMPORARY );
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
				// Lost work files, and a table changed under the export, are final; anything else may pass once its cause is fixed.
				return $this->fail( $job, $token, $logger, sprintf( 'Step "%s": %s', $step_id, $this->describe( $e ) ), self::failure_of( $e ) );
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

			if ( StepResult::ASK === $result->kind ) {
				// Not progress (the counters and the stall timestamp stay), not a wait: the job pauses until a
				// person answers, and no driver follows up. The same step runs again after the answer.
				$logger->info(
					'Step asks for a decision',
					array(
						'step'      => $step_id,
						'questions' => count( $result->questions ),
					)
				);
				$this->persist( $job, $token, $step_id, $result->cursor, $state, $job->progress, $result->message, false );
				try {
					$this->repository->pause_for_answer( $job, $token, $result->questions );
				} catch ( StaleJob $e ) {
					throw new LockLost( $e->getMessage(), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
				} catch ( \InvalidArgumentException $e ) {
					// Malformed questions: nothing was written (validation runs before the statement).
					return $this->fail( $job, $token, $logger, sprintf( 'Step "%s": %s', $step_id, $this->describe( $e ) ) );
				}
				return new TickResult( TickResult::PAUSED, -1, $job, $result->message );
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
	 * Only for cancelled jobs. A failed job is never cleaned up here: it
	 * keeps its cursor and its work directory for a retry until the purge
	 * reclaims them after JobRepository::WORK_RETENTION_SECONDS. After the
	 * steps ran, the engine removes the whole work directory and the job's
	 * temporary tables itself (JobRepository::reclaim_work()), whatever the
	 * steps missed.
	 *
	 * @param Job $job Cancelled job.
	 * @return int Steps cleaned.
	 */
	public function cleanup( Job $job ): int {
		$type = $this->types->get( $job->type );
		if ( null === $type ) {
			return 0;
		}
		$logger = $this->logger_for( $job );
		try {
			$budget = $this->budget_for( (int) call_user_func( $this->memory ) );
		} catch ( BudgetExhausted $e ) {
			$budget = new Budget( Thresholds::BUDGET_MIN_SECONDS, 0, true ); // Cleanup does not need a budget to run.
		}
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
		$this->repository->reclaim_work( $job );
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
		// May throw BudgetExhausted: the callers decide whether that fails the job (tick) or is ignored (cleanup).
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
	 * One line about a job from outside a tick (a driver's decision about
	 * its tick): in the job's log when the job's files are in the current
	 * storage directory, otherwise in the storage log, with the job's id
	 * and without the context.
	 *
	 * @param Job                  $job     Job.
	 * @param string               $level   Logger::INFO, Logger::WARNING or Logger::ERROR.
	 * @param string               $message Message (no paths, no site data).
	 * @param array<string, mixed> $context Context (numbers and identifiers).
	 * @return void
	 */
	public function note( Job $job, string $level, string $message, array $context = array() ): void {
		if ( $this->repository->owns_files_of( $job ) ) {
			$this->logger_for( $job )->log( $level, $message, $context );
			return;
		}
		$this->repository->log_event( sprintf( 'Job %d: %s', $job->id, $message ) );
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
	 * @param string               $token      Lock token ('' outside a run: no lease checks).
	 * @return JobContext
	 */
	private function context( Job $job, array $cursor, Budget $budget, Logger $logger, float $started_at, $checkpoint, string $token = '' ): JobContext {
		$lease = '' === $token ? null : function ( bool $force ) use ( $job, $token ): void {
			if ( $force ) {
				if ( ! $this->repository->heartbeat( $job, $token, $this->lease ) ) {
					throw new LockLost( sprintf( 'Job %d: the lock is no longer held.', $job->id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
				}
				return;
			}
			$this->maybe_heartbeat( $job, $token );
		};
		return new JobContext( $job, $cursor, $budget, $logger, $this->clock, $this->memory, $started_at, $this->memory_limit, $checkpoint, $lease );
	}

	/**
	 * Why a job that was taken over MAX_TAKEOVERS times at the same position
	 * fails: which step and phase, never a path or the site. A takeover means
	 * the run ended without releasing the lock: killed at the time limit, a
	 * fatal error at the memory limit (the more common one at 128 MB), a
	 * crash, a restarted worker. The message does not guess which.
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	private static function takeover_message( Job $job ): string {
		$phase = isset( $job->cursor['phase'] ) && is_string( $job->cursor['phase'] ) && 1 === preg_match( '/\A[a-z_]{1,32}\z/', $job->cursor['phase'] ) ? $job->cursor['phase'] : '-';
		return sprintf(
			'Stopped: step "%1$s" (phase %2$s) was interrupted at the same point %3$d times in a row without finishing (ended by the server\'s time or memory limit, or a crash). The job log shows where it stopped; the PHP error log shows why. Please report it with both.',
			'' === $job->step ? '-' : $job->step,
			$phase,
			$job->takeovers
		);
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
		if ( null !== $this->on_persist ) {
			// Test seam: a replay test throws LockLost here to simulate a process that died after the disk
			// changed and before this cursor was written; nothing else is written after LockLost.
			call_user_func( $this->on_persist, $job, $step, $cursor );
		}
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
		// The repository's clock is the database's (JobRepository::now()): lease written and judged on one clock.
		if ( $job->locked_until - $this->repository->now() >= (int) ( $this->lease / 2 ) ) {
			return;
		}
		if ( ! $this->repository->heartbeat( $job, $token, $this->lease ) ) {
			throw new LockLost( sprintf( 'Job %d: the lock is no longer held.', $job->id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
		}
	}

	/**
	 * Fenced status change.
	 *
	 * @param Job    $job     Job.
	 * @param string $token   Lock token.
	 * @param string $to      Target status.
	 * @param string $error   Error message.
	 * @param string $failure Kind of a failure (Job::FAILURE_*).
	 * @return void
	 * @throws LockLost When the write refused.
	 */
	private function transition( Job $job, string $token, string $to, string $error, string $failure = '' ): void {
		try {
			$this->repository->transition( $job, $to, $error, $token, $failure );
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
	 * @param string $failure Job::FAILURE_TEMPORARY (a passing problem), Job::FAILURE_FINAL (lost work
	 *                        files: a retry fails the same way; with a reason, see failure_of()), or ''
	 *                        when the cause does not say.
	 * @return TickResult
	 */
	private function fail( Job $job, string $token, Logger $logger, string $message, string $failure = '' ): TickResult {
		$logger->error( 'Job failed', array( 'error' => $message ) );
		$this->transition( $job, $token, Job::FAILED, $message, $failure );
		return new TickResult( TickResult::FAILED, -1, $job, $this->redactor->redact( $message ) );
	}

	/**
	 * Fail a job the table cannot hold (JobRepository::fail_for_missing_columns()).
	 *
	 * @param Job      $job      Job, as read.
	 * @param string[] $problems What the table lacks (Schema::column_problems()).
	 * @return TickResult
	 */
	private function fail_for_missing_columns( Job $job, array $problems ): TickResult {
		$message  = Schema::problem_message( $problems );
		$unusable = array_map( array( Schema::class, 'problem_column' ), $problems );
		$outcome  = $this->repository->fail_for_missing_columns( $job, $message, $unusable );
		if ( JobRepository::FAIL_ERROR === $outcome ) {
			// Not even the failure could be written: say why, and try again later (the columns may come back).
			return new TickResult( TickResult::BLOCKED, JobRepository::BACKOFF_SECONDS[ count( JobRepository::BACKOFF_SECONDS ) - 1 ], $job, $this->redactor->redact( $message ) );
		}
		if ( JobRepository::FAIL_HELD === $outcome ) {
			// A live run holds it (its own next tick fails it), or it ended meanwhile.
			$now = $this->repository->find( $job->id );
			if ( null === $now ) {
				return new TickResult( TickResult::MISSING, -1, null );
			}
			if ( ! in_array( $now->status, array( Job::QUEUED, Job::RUNNING, Job::PAUSED ), true ) ) {
				return new TickResult( TickResult::FINISHED, -1, $now );
			}
			return new TickResult( TickResult::BUSY, self::BUSY_RETRY_SECONDS, $now, __( 'Another process is working on this job.', 'wp-checkpoint' ) );
		}
		$this->note( $job, Logger::ERROR, 'Job failed', array( 'error' => $message ) );
		return new TickResult( TickResult::FAILED, -1, $job, $this->redactor->redact( $message ) );
	}

	/**
	 * The kind of failure an exception from a step means (Job::stamp_failure()):
	 * final for lost work files, a table that changed under the export and a
	 * restore ledger of an older version, no kind for anything else.
	 *
	 * @param \Throwable $e Exception.
	 * @return string
	 */
	private static function failure_of( \Throwable $e ): string {
		if ( $e instanceof TableChanged ) {
			return Job::FAILURE_FINAL . ':' . Job::REASON_TABLE_CHANGED;
		}
		return $e instanceof WorkLost || $e instanceof LedgerOutdated ? Job::FAILURE_FINAL : '';
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
