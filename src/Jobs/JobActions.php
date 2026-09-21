<?php
/**
 * The operations every driver shares: tick, cancel, retry.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * REST, WP-CLI and the cron callback all go through here so the sequence
 * (schema check, housekeeping, tick, deferred cleanup, next hop) is the
 * same everywhere.
 */
final class JobActions {

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
	 * Load a job.
	 *
	 * @param int $id Job id.
	 * @return Job|null
	 */
	public function find( int $id ) {
		return $this->repository->find( $id );
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
		Schema::ensure();
		$this->repository->maintenance();
		$result = $this->runner->tick( $id, null === $started_at ? self::started_at() : (float) $started_at );
		if ( TickResult::LOST === $result->status && null !== $result->job && Job::CANCELLED === $result->job->status ) {
			// The cancel happened while this driver held the lock: the step has stopped now, so clean up here.
			$this->runner->cleanup( $result->job );
		}
		if ( $follow_up ) {
			$this->follow_up( $result );
		}
		return $result;
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
		return $this->repository->answer( $job, $answers );
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
		return $this->repository->transition( $job, Job::QUEUED );
	}
}
