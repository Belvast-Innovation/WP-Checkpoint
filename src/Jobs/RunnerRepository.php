<?php
/**
 * What the Runner asks of the jobs' storage.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * The methods the Runner calls on the jobs' storage, as JobRepository answers them in WordPress (each documented
 * there). A storage that cannot do one of them where it runs (a run made without WordPress) throws NotHere from it,
 * before it writes anything; the Runner then lets the job go and leaves it as it is (it never fails a job for that).
 */
interface RunnerRepository {

	/**
	 * Whether this storage can do one of its methods where it runs. The Runner asks before the first write of a path
	 * that calls a method after a write (pausing for an answer after the cursor, the cleanup after a cancel), so that
	 * a method it cannot do is never reached with the row half written; a method that is the first write of its path
	 * throws NotHere itself.
	 *
	 * @param string $method A method of this interface.
	 * @return bool
	 */
	public function can( string $method ): bool;

	/**
	 * Current time on the database's clock.
	 *
	 * @return int
	 */
	public function now(): int;

	/**
	 * Load a job.
	 *
	 * @param int $id Job id.
	 * @return Job|null
	 */
	public function find( int $id );

	/**
	 * Whether a job may be ticked now, and how long to wait otherwise.
	 *
	 * @param Job $job Job.
	 * @return array{allowed: bool, reason: string, message: string, retry_after: int}
	 */
	public function gate( Job $job ): array;

	/**
	 * Count a refused tick for the gate's back-off.
	 *
	 * @param Job $job Job.
	 * @return int
	 */
	public function record_blocked( Job $job ): int;

	/**
	 * Clear the count of late cron requests that kept the job from running.
	 *
	 * @param int $id Job id.
	 * @return void
	 */
	public function reset_cron_deferrals( int $id ): void;

	/**
	 * Take a job: one compare-and-set.
	 *
	 * @param int $id    Job id.
	 * @param int $lease Lock duration in seconds.
	 * @return array{job: Job, token: string, healed: bool, taken_over: bool}|null
	 */
	public function acquire( int $id, int $lease = JobRepository::LOCK_SECONDS );

	/**
	 * Extend the lease.
	 *
	 * @param Job    $job   Job.
	 * @param string $token Lock token.
	 * @param int    $lease Lock duration in seconds.
	 * @return bool Whether the lock is still this run's.
	 */
	public function heartbeat( Job $job, string $token, int $lease = JobRepository::LOCK_SECONDS ): bool;

	/**
	 * Let the job go.
	 *
	 * @param Job    $job   Job.
	 * @param string $token Lock token.
	 * @return bool
	 */
	public function release( Job $job, string $token ): bool;

	/**
	 * Persist a step's position.
	 *
	 * @param Job                  $job        Job.
	 * @param string               $token      Lock token.
	 * @param string               $step       Step id.
	 * @param array<string, mixed> $cursor     Cursor.
	 * @param int                  $progress   0-100.
	 * @param string               $message    Progress text.
	 * @param bool                 $advanced   Whether the position moved.
	 * @param int|null             $site_state Job::SITE_*, or null.
	 * @param string|null          $site_mark  The maintenance file's mark, or null.
	 * @return int
	 */
	public function save_progress( Job $job, string $token, string $step, array $cursor, int $progress, string $message = '', bool $advanced = true, $site_state = null, $site_mark = null ): int;

	/**
	 * Record a fact of the run on the job's row.
	 *
	 * @param Job    $job   Job.
	 * @param string $token Lock token.
	 * @param string $key   Name.
	 * @param mixed  $value A scalar or null.
	 * @return void
	 */
	public function save_record( Job $job, string $token, string $key, $value ): void;

	/**
	 * Record a step's questions and pause the job.
	 *
	 * @param Job                              $job       Job.
	 * @param string                           $token     Lock token.
	 * @param array<int, array<string, mixed>> $questions Questions.
	 * @return Job
	 */
	public function pause_for_answer( Job $job, string $token, array $questions ): Job;

	/**
	 * Move a job to another status.
	 *
	 * @param Job      $job        Job.
	 * @param string   $to         Target status.
	 * @param string   $error      Error message for failed.
	 * @param string   $token      Lock token.
	 * @param string   $failure    Job::FAILURE_* for failed.
	 * @param string   $retry_from The step a retry starts at.
	 * @param string[] $forget     Answers a retry asks again.
	 * @param int      $at         For undone: the time it was undone at.
	 * @return Job
	 */
	public function transition( Job $job, string $to, string $error = '', string $token = '', string $failure = '', string $retry_from = '', array $forget = array(), int $at = 0 ): Job;

	/**
	 * Fail a job whose row cannot be read.
	 *
	 * @param Job    $job     Job.
	 * @param string $message Why.
	 * @return string
	 */
	public function fail_unreadable( Job $job, string $message ): string;

	/**
	 * Fail a job whose table lacks columns.
	 *
	 * @param Job      $job      Job.
	 * @param string   $message  Why.
	 * @param string[] $unusable The columns.
	 * @return string
	 */
	public function fail_for_missing_columns( Job $job, string $message, array $unusable ): string;

	/**
	 * Note why a job that holds the site is held back.
	 *
	 * @param Job    $job     Job.
	 * @param string $message Why.
	 * @return bool
	 */
	public function note_held_back( Job $job, string $message ): bool;

	/**
	 * The storage directory a job's files are in, here ('' when not here).
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	public function files_base( Job $job ): string;

	/**
	 * Whether a job's files are this installation's.
	 *
	 * @param Job $job Job.
	 * @return bool
	 */
	public function owns_files_of( Job $job ): bool;

	/**
	 * Reclaim a job's work directory and temporary tables.
	 *
	 * @param Job $job    Job.
	 * @param int $budget Entries at most.
	 * @return bool
	 */
	public function reclaim_work( Job $job, int $budget = JobRepository::RECLAIM_MAX_ENTRIES ): bool;

	/**
	 * One line in the storage log.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function log_event( string $message ): void;
}
