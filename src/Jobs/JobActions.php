<?php
/**
 * The operations every driver shares: tick, cancel, retry.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Support\Schema;
use WPCheckpoint\Support\HostFunctions;
use WPCheckpoint\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * REST, WP-CLI and the cron callback all go through here so the sequence
 * (schema check, housekeeping, tick, deferred cleanup, next hop) is the
 * same everywhere.
 */
final class JobActions {

	/**
	 * Site transient throttling sweep_events().
	 */
	const SWEPT = 'wpcheckpoint_jobs_swept';

	/**
	 * Cron requests in a row that may put a job's tick off for starting too
	 * late (see cron_tick()); the next one ticks it anyway.
	 */
	const MAX_CRON_DEFERRALS = 3;

	/**
	 * A budget start that leaves no time: the tick runs its first unit (the
	 * first unit of a tick always runs) and stops.
	 */
	const NO_TIME_LEFT = 0.0;

	/**
	 * Repository.
	 *
	 * @var JobRepository
	 */
	private $repository;

	/**
	 * Runner.
	 *
	 * @var Runner
	 */
	private $runner;

	/**
	 * Loopback / cron follow-up.
	 *
	 * @var Loopback
	 */
	private $loopback;

	/**
	 * Bytes the last web tick printed (see web_tick()).
	 *
	 * @var int
	 */
	private static $last_output_bytes = 0;

	/**
	 * Constructor.
	 *
	 * @param JobRepository $repository Repository.
	 * @param Runner        $runner     Runner.
	 * @param Loopback      $loopback   Loopback.
	 */
	public function __construct( JobRepository $repository, Runner $runner, Loopback $loopback ) {
		$this->repository = $repository;
		$this->runner     = $runner;
		$this->loopback   = $loopback;
	}

	/**
	 * When the current driver's budget started. A web request counts from
	 * its start (bootstrap included); WP-CLI (including "wp cron event run",
	 * which processes several events in one process) counts from now.
	 *
	 * @param bool|null $cli Whether this is a WP-CLI process; null detects (tests inject).
	 * @return float
	 */
	public static function started_at( $cli = null ): float {
		if ( null === $cli ) {
			$cli = defined( 'WP_CLI' ) && WP_CLI;
		}
		if ( $cli ) {
			return microtime( true );
		}
		if ( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) && is_numeric( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
			return (float) $_SERVER['REQUEST_TIME_FLOAT'];
		}
		return microtime( true );
	}

	/**
	 * Why a stored job must not run next to the active jobs created before it
	 * (lower id), or '' when it may.
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	public function conflict_for( Job $job ): string {
		$before = array_values(
			array_filter(
				$this->active(),
				static function ( Job $other ) use ( $job ): bool {
					return $other->id < $job->id;
				}
			)
		);
		return JobConflicts::conflict( $job->type, $job->options, $before );
	}

	/**
	 * Run work that must not interleave with a job start (deleting a
	 * backup): under the start lock, with the active jobs read inside it,
	 * so no job can be created between the conflict check the work makes
	 * and its last change.
	 *
	 * @template T
	 * @param callable(Job[]): T $work Gets the queued, running and paused jobs.
	 * @return T
	 * @throws JobsUnavailable When the lock is held elsewhere beyond the timeout.
	 * @throws \RuntimeException What the work throws (after the lock is released).
	 */
	public function exclusive( callable $work ) {
		if ( ! $this->repository->lock_starts() ) {
			throw new JobsUnavailable( 'Another job is being started right now; try again in a moment.' );
		}
		try {
			return $work( $this->active() );
		} finally {
			$this->repository->unlock_starts();
		}
	}

	/**
	 * Create a job, unless it must not run next to the active ones
	 * (JobConflicts). Checked after the insert against the active jobs with
	 * a lower id, so two requests racing each other cannot both start: the
	 * later one sees the earlier and removes itself before anything ran.
	 *
	 * @param string               $type       Job type.
	 * @param int                  $owner_user Creating user.
	 * @param array<string, mixed> $options    Options.
	 * @return Job
	 * @throws JobsUnavailable When jobs cannot be created now.
	 * @throws JobConflict When the job must not run next to an active one (the message says which).
	 */
	public function start( string $type, int $owner_user, array $options ): Job {
		if ( ! $this->repository->lock_starts() ) {
			throw new JobsUnavailable( 'Another job is being started right now; try again in a moment.' );
		}
		try {
			if ( in_array( $type, array( JobConflicts::EXPORT, JobConflicts::RESTORE ), true ) ) {
				$this->cancel_yielding();
			}
			$job    = $this->repository->create( $type, $owner_user, array(), $options );
			$reason = $this->conflict_for( $job );
		} finally {
			$this->repository->unlock_starts();
		}
		if ( '' === $reason ) {
			// Whoever started it (a page, WP-CLI) usually ticks it at once; if not, cron does.
			Loopback::schedule( $job->id, Loopback::FALLBACK_SECONDS );
			return $job;
		}
		if ( ! $this->repository->discard_unstarted( $job->id ) ) {
			try {
				$this->cancel( $job->id ); // A driver picked it up in the meantime.
			} catch ( \RuntimeException $e ) {
				unset( $e ); // Finished or changed meanwhile: the conflict is reported all the same.
			} catch ( \LogicException $e ) {
				unset( $e );
			}
		}
		throw new JobConflict( esc_html( $reason ) );
	}

	/**
	 * Load a job.
	 *
	 * @param int $id Job id.
	 * @return Job|null
	 */
	public function find( int $id ) {
		return $this->repository->find( $id );
	}

	/**
	 * Cancel the jobs that give way (an estimate) before an export or a
	 * restore starts. Silently: the user started a backup, the estimate was
	 * ours; a cancel that fails (it finished meanwhile) changes nothing.
	 *
	 * @return void
	 */
	private function cancel_yielding(): void {
		foreach ( $this->active() as $job ) {
			if ( ! in_array( $job->type, JobConflicts::YIELDING, true ) ) {
				continue;
			}
			try {
				$this->cancel( $job->id );
			} catch ( \RuntimeException $e ) {
				unset( $e );
			} catch ( \LogicException $e ) {
				unset( $e );
			}
		}
	}

	/**
	 * The user's jobs, newest first: the jobs that give way (an estimate)
	 * are the plugin's own and are left out.
	 *
	 * @param string[] $statuses Statuses (empty for all).
	 * @param int      $limit    Maximum.
	 * @return Job[]
	 */
	public function list_user_jobs( array $statuses = array(), int $limit = 50 ): array {
		return array_values(
			array_filter(
				$this->repository->list_jobs( $statuses, $limit ),
				static function ( Job $job ): bool {
					return ! in_array( $job->type, JobConflicts::YIELDING, true );
				}
			)
		);
	}

	/**
	 * Queued, running and paused jobs: the ones JobConflicts weighs.
	 *
	 * @return Job[]
	 */
	public function active(): array {
		return $this->repository->list_jobs( array( Job::QUEUED, Job::RUNNING, Job::PAUSED ), 500 );
	}

	/**
	 * Jobs, newest first.
	 *
	 * @param string[] $statuses Statuses (empty for all).
	 * @param int      $limit    Maximum.
	 * @return Job[]
	 */
	public function list_jobs( array $statuses = array(), int $limit = 50 ): array {
		return $this->repository->list_jobs( $statuses, $limit );
	}

	/**
	 * A tick driven by a web request (the REST tick route, the loopback hop,
	 * a cron event): the request keeps running when the client goes away,
	 * and nothing it prints reaches the client.
	 *
	 * PHP notices a client that went away only when it tries to send output,
	 * and then ends the script: a tick killed like that holds its lock until
	 * the lease expires and counts as a takeover (three at the same position
	 * fail the job). ignore_user_abort( true ) keeps the request running;
	 * the output buffer makes sure a tick never sends anything, so a stray
	 * notice with display_errors on cannot be that write either. What was
	 * captured is discarded and its size logged. Such a tick does not turn
	 * into an orphan process: it is bounded by its time budget and its lease
	 * like any other.
	 *
	 * @param int        $id         Job id.
	 * @param float|null $started_at Budget start.
	 * @return TickResult
	 */
	public function web_tick( int $id, $started_at = null ): TickResult {
		return $this->captured(
			$id,
			function () use ( $id, $started_at ): TickResult {
				return $this->tick( $id, $started_at );
			}
		);
	}

	/**
	 * Run $work for a web entry point: the request keeps running without its client, and what it prints is
	 * captured, discarded and its size logged (see web_tick()).
	 *
	 * @template T
	 * @param int           $id   Job id (for the log line).
	 * @param callable(): T $work The work.
	 * @return T
	 */
	private function captured( int $id, callable $work ) {
		HostFunctions::ignore_user_abort();
		ob_start();
		try {
			return $work();
		} finally {
			$output                  = ob_get_clean();
			self::$last_output_bytes = is_string( $output ) ? strlen( $output ) : 0;
			if ( self::$last_output_bytes > 0 ) {
				$this->repository->log_event( sprintf( 'Job %d: the tick printed %d bytes; discarded, nothing was sent to the client.', $id, self::$last_output_bytes ) );
			}
		}
	}

	/**
	 * The tick of a cron event. wp-cron.php runs every due event in one
	 * request, and a tick's budget counts from the request start. A tick
	 * that starts LATE_CRON_SECONDS or more into its request would still run
	 * its first unit and could pass the server's time limit (then counted
	 * as a takeover), so it is put off to the next cron request, but at
	 * most MAX_CRON_DEFERRALS times in a row: on a host where every cron
	 * request starts that late, the job would otherwise never move on from
	 * cron. The next late request ticks it with no time left, so it runs
	 * its first unit only. The count is kept on the job row and set back to
	 * 0 by any tick that makes progress (and by a retry or an answer); after
	 * such a tick that makes none, every late request ticks it that way
	 * until one does. Each deferral and each such tick is logged. Like
	 * web_tick(), nothing is sent to the client.
	 *
	 * @param int   $id         Job id.
	 * @param float $started_at When the cron request started (see started_at()).
	 * @return TickResult|null Null when the tick was put off.
	 */
	public function cron_tick( int $id, float $started_at ): ?TickResult {
		return $this->captured(
			$id,
			function () use ( $id, $started_at ): ?TickResult {
				if ( microtime( true ) - $started_at < Loopback::LATE_CRON_SECONDS ) {
					return $this->tick( $id, $started_at );
				}
				return $this->late_cron_tick( $id, round( microtime( true ) - $started_at, 1 ), $started_at );
			}
		);
	}

	/**
	 * A cron tick that starts late in its request: put off, or run with no time left (see cron_tick()).
	 *
	 * @param int   $id         Job id.
	 * @param float $late       Seconds into the request.
	 * @param float $started_at When the cron request started.
	 * @return TickResult|null Null when the tick was put off.
	 */
	private function late_cron_tick( int $id, float $late, float $started_at ): ?TickResult {
		Schema::ensure(); // The count is written before the tick, which would otherwise be what migrates.
		$job = $this->repository->find( $id );
		if ( null === $job || ! in_array( $job->status, array( Job::QUEUED, Job::RUNNING, Job::PAUSED ), true ) || $job->awaiting_answer() ) {
			// Nothing of it runs (the tick only reports the job and settles its event): no reason to wait.
			return $this->tick( $id, $started_at );
		}
		// The next cron request is asked for before the deferral is counted: a request that dies in between
		// leaves an event and a lower count, never a count without an event (WP-Cron removed the one that ran).
		Loopback::schedule( $id, 1, Loopback::EARLIER );
		if ( $this->repository->count_cron_deferral( $id, self::MAX_CRON_DEFERRALS ) ) {
			$counted = $this->repository->find( $id );
			$this->runner->note(
				$job,
				Logger::INFO,
				'Tick put off to the next cron request: this one started too late',
				array(
					'late_seconds' => $late,
					'deferrals'    => null !== $counted ? $counted->cron_deferrals : $job->cron_deferrals + 1,
					'limit'        => self::MAX_CRON_DEFERRALS,
				)
			);
			return null;
		}
		// The tick runs after all: its event is the one it would have had (the tick adjusts it as usual), not the
		// one just set for the next cron request.
		Loopback::schedule( $id, Loopback::FALLBACK_SECONDS, Loopback::REPLACE );
		$job = $this->repository->find( $id );
		if ( null === $job || ! in_array( $job->status, array( Job::QUEUED, Job::RUNNING, Job::PAUSED ), true ) ) {
			return $this->tick( $id, $started_at ); // It ended meanwhile.
		}
		$this->runner->note(
			$job,
			Logger::WARNING,
			$job->cron_deferrals >= self::MAX_CRON_DEFERRALS
				? sprintf( 'Cron requests start too slowly: the tick was put off in %d cron requests in a row, so this one runs the first unit only', $job->cron_deferrals )
				: 'This cron request started too late, and the deferral could not be recorded: it runs the first unit only',
			array( 'late_seconds' => $late )
		);
		return $this->tick( $id, self::NO_TIME_LEFT );
	}

	/**
	 * Bytes the last web tick printed (and discarded); 0 on a clean tick. For tests.
	 *
	 * @return int
	 */
	public static function last_output_bytes(): int {
		return self::$last_output_bytes;
	}

	/**
	 * One tick: schema, housekeeping, run, deferred cleanup, next hop.
	 *
	 * @param int        $id         Job id.
	 * @param float|null $started_at Budget start (see started_at()).
	 * @param bool       $follow_up  Whether to arrange the next tick (self-request or cron event). A driver
	 *                               that keeps ticking itself (the WP-CLI loop) passes false and calls
	 *                               follow_up() once when it stops before the job is finished.
	 * @return TickResult
	 */
	public function tick( int $id, $started_at = null, bool $follow_up = true ): TickResult {
		if ( $follow_up ) {
			// Before anything else: a request the server kills (in the tick, or in the reap before it) never
			// reaches its follow-up, and if cron started it, that event is used up. Only makes sure one exists
			// (a wait's later time stays); the result adjusts or clears it afterwards.
			Loopback::schedule( $id, Loopback::FALLBACK_SECONDS, Loopback::KEEP );
		}
		Schema::ensure();
		$this->repository->maintenance();
		$this->sweep_events();
		$result = $this->runner->tick( $id, null === $started_at ? self::started_at() : (float) $started_at );
		if ( TickResult::LOST === $result->status && null !== $result->job && Job::CANCELLED === $result->job->status ) {
			// The cancel happened while this driver held the lock: the step has stopped now, so clean up here.
			$this->runner->cleanup( $result->job );
		}
		if ( $follow_up ) {
			$this->follow_up( $result );
			if ( TickResult::MISSING === $result->status ) {
				Loopback::unschedule( $id ); // No job to follow up: take back the event set before the tick.
			}
		}
		return $result;
	}

	/**
	 * Remove cron events of jobs no driver should tick any more (throttled
	 * like the reap): a job that ended without a follow-up, one that waits
	 * for an answer, one that was purged.
	 *
	 * @return void
	 */
	private function sweep_events(): void {
		if ( false !== get_site_transient( self::SWEPT ) ) {
			return;
		}
		set_site_transient( self::SWEPT, 1, JobRepository::REAP_THROTTLE );
		Loopback::sweep(
			function ( int $id ): bool {
				global $wpdb;
				$job = $this->repository->find( $id );
				if ( null === $job ) {
					return '' !== (string) $wpdb->last_error; // A failed query proves nothing: keep the event.
				}
				// An answered job stays paused, with no questions, until its next tick takes it.
				return in_array( $job->status, array( Job::QUEUED, Job::RUNNING ), true ) || ( Job::PAUSED === $job->status && ! $job->awaiting_answer() );
			}
		);
	}

	/**
	 * Arrange the next tick for a result: a self-request for "more", a cron
	 * event for waits, nothing (and a clean-up of both) for the rest.
	 *
	 * @param TickResult $result Tick result.
	 * @return void
	 */
	public function follow_up( TickResult $result ): void {
		$this->loopback->after_tick( $result );
	}

	/**
	 * Cancel a job. The lock is taken with the same compare-and-set as a
	 * tick: when that succeeds nobody is working on the job and the cleanup
	 * runs here; when it fails the holder cleans up as soon as its next
	 * fenced write refuses (see tick()).
	 *
	 * @param int $id Job id.
	 * @return array{job: Job, cleaned: bool, reason: string}|null Null when the job does not exist. reason: "cleaned",
	 *                                                            "holder" (a driver holds the lock and cleans up when it
	 *                                                            stops) or "unavailable" (the storage directory cannot be
	 *                                                            used from here; nothing will clean up).
	 * @throws InvalidTransition When the job is already finished.
	 * @throws StaleJob When the job changed meanwhile.
	 */
	public function cancel( int $id ) {
		$job = $this->repository->find( $id );
		if ( null === $job ) {
			return null;
		}
		Loopback::unschedule( $id );
		Loopback::revoke_tokens( $id );

		$held = in_array( $job->status, array( Job::QUEUED, Job::RUNNING ), true ) ? $this->repository->acquire_for_cancel( $id ) : null;
		if ( null !== $held ) {
			$job = $held['job'];
			try {
				$this->repository->transition( $job, Job::CANCELLED );
			} catch ( StaleJob $e ) {
				// The row changed under our lock: a concurrent cancel cleared the lock and reported "the holder
				// cleans up" (that holder is this request), or the write itself failed. Our compare-and-set
				// succeeded, so nobody was writing; if the job is cancelled now, clean up here. This is only
				// safe in this branch: without a successful compare-and-set a cancelled status says nothing
				// about who may still be writing.
				$current = $this->repository->find( $id );
				if ( null !== $current && Job::CANCELLED === $current->status ) {
					$this->runner->cleanup( $current );
					return array(
						'job'     => $current,
						'cleaned' => true,
						'reason'  => 'cleaned',
					);
				}
				throw $e; // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- rethrown unchanged.
			} finally {
				// Guarded by the token: a no-op when the transition already cleared the lock or someone else took it.
				$this->repository->release( $job, $held['token'] );
			}
			$this->runner->cleanup( $job );
			return array(
				'job'     => $job,
				'cleaned' => true,
				'reason'  => 'cleaned',
			);
		}

		$was_paused = Job::PAUSED === $job->status;
		$holder     = $job->is_locked( $this->repository->now() );
		$this->repository->transition( $job, Job::CANCELLED );
		if ( $was_paused ) {
			// A paused job holds no lock, so nobody is writing.
			$this->runner->cleanup( $job );
		}
		return array(
			'job'     => $job,
			'cleaned' => $was_paused,
			'reason'  => $was_paused ? 'cleaned' : ( $holder ? 'holder' : 'unavailable' ),
		);
	}

	/**
	 * Store the answers to a paused job's questions. The job is not ticked
	 * here: the caller ticks it (or hands it to the other drivers with a
	 * follow-up of a "more" result) once the answers are in.
	 *
	 * @param int                  $id      Job id.
	 * @param array<string, mixed> $answers Answers keyed by question id.
	 * @return Job|null Null when the job does not exist.
	 * @throws InvalidTransition When the job is not waiting for an answer.
	 * @throws \InvalidArgumentException When an answer carries a secret.
	 * @throws StaleJob When the job changed meanwhile.
	 */
	public function answer( int $id, array $answers ) {
		$job = $this->repository->find( $id );
		if ( null === $job ) {
			return null;
		}
		$answered = $this->repository->answer( $job, $answers );
		Loopback::schedule( $id, Loopback::FALLBACK_SECONDS ); // Answered and the page closed: the job still goes on.
		return $answered;
	}

	/**
	 * Queue a failed job again; the cursor is kept.
	 *
	 * @param int $id Job id.
	 * @return Job|null Null when the job does not exist.
	 * @throws InvalidTransition When the job is not failed.
	 * @throws StaleJob When the job changed meanwhile.
	 */
	public function retry( int $id ) {
		$job = $this->repository->find( $id );
		if ( null === $job ) {
			return null;
		}
		$queued = $this->repository->transition( $job, Job::QUEUED );
		Loopback::schedule( $id, Loopback::FALLBACK_SECONDS );
		return $queued;
	}
}
