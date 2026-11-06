<?php
/**
 * The jobs' storage for a run made without WordPress.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Restore\ImportSession;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Restore\SwapPlan;
use WPCheckpoint\Support\Paths;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * What the Runner asks of the jobs' storage (RunnerRepository) where WordPress is not loaded: the same statements as
 * in WordPress (JobStore, through SessionExecutor), for a job that holds the site only, taken only on positive
 * evidence that it is this site's (here()), and only with what such a run can do; anything else throws NotHere
 * before it writes (the Runner then leaves the job as it is, for WP-CLI). The claim is the same statement as
 * WordPress's but for who manages the job: here, the managing token as read, and the maintenance file's mark as the
 * data file says it (WordPress asks for a token this installation holds, which only WordPress can tell).
 */
final class WebJobs implements RunnerRepository {

	/**
	 * What this storage does.
	 */
	const CAN = array( 'files_base', 'owns_files_of', 'log_event' );

	/**
	 * Connection.
	 *
	 * @var ImportSession
	 */
	private $session;

	/**
	 * The statements.
	 *
	 * @var JobStore
	 */
	private $store;

	/**
	 * The table prefix (wp-config.php's $table_prefix).
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * The WordPress directory (ABSPATH).
	 *
	 * @var string
	 */
	private $abspath;

	/**
	 * Told what this run did not take on, or could not tell: function( string $line ): void.
	 *
	 * @var callable
	 */
	private $note;

	/**
	 * Database clock minus local clock, read once.
	 *
	 * @var int|null
	 */
	private $offset = null;

	/**
	 * Constructor.
	 *
	 * @param ImportSession $session Connection, made without WordPress.
	 * @param string        $prefix  The table prefix (wp-config.php's $table_prefix).
	 * @param string        $abspath The WordPress directory.
	 * @param callable      $note    function( string $line ): void, for what this run did not take on or could not tell.
	 */
	public function __construct( ImportSession $session, string $prefix, string $abspath, callable $note ) {
		$this->session = $session;
		$this->prefix  = $prefix;
		$this->abspath = $abspath;
		$this->note    = $note;
		$redactor      = new Redactor( Redactor::installation_secrets() );
		$this->store   = new JobStore(
			new SessionExecutor( $session ),
			$prefix . Schema::JOBS_TABLE,
			$prefix . UninstallFence::TABLE,
			array( $this, 'now' ),
			array( PlainJson::class, 'encode' ),
			array( $redactor, 'redact' ),
			array( JobStore::class, 'cut_message' ),
			static function ( array $value ): void {
				CursorSecrets::assert_none( PlainJson::encode( $value ) );
			},
			static function (): bool {
				return false; // Such a run never enters the site: it is never asked.
			}
		);
	}

	/**
	 * Whether this storage can do a method here: the basic ones, and its own few (CAN).
	 *
	 * @param string $method A method of RunnerRepository.
	 * @return bool
	 */
	public function can( string $method ): bool {
		return in_array( $method, self::BASIC, true ) || in_array( $method, self::CAN, true );
	}

	/**
	 * Current time on the database's clock (as JobRepository::now()).
	 *
	 * @return int
	 */
	public function now(): int {
		if ( null === $this->offset ) {
			$rows = $this->session->rows( 'SELECT UNIX_TIMESTAMP()' );
			if ( ! isset( $rows[0][0] ) || ! is_numeric( $rows[0][0] ) ) {
				return time(); // Not kept: the next call asks again.
			}
			$this->offset = (int) $rows[0][0] - time();
		}
		return time() + $this->offset;
	}

	/**
	 * Load a job.
	 *
	 * @param int $id Job id.
	 * @return Job|null
	 */
	public function find( int $id ) {
		return $this->store->find( $id );
	}

	/**
	 * Whether a job may be ticked here: one that holds the site, on positive evidence that it is this site's (here()).
	 *
	 * @param Job $job Job.
	 * @return array{allowed: bool, reason: string, message: string, retry_after: int}
	 */
	public function gate( Job $job ): array {
		$why = Job::SITE_UNTOUCHED === $job->site_state ? 'The job does not hold the site.' : $this->here( $job );
		if ( '' !== $why ) {
			$this->note( sprintf( 'Job %d is not taken on here: %s', $job->id, $why ) );
		}
		return array(
			'allowed'     => '' === $why,
			'reason'      => '' === $why ? '' : 'not_here',
			'message'     => $why,
			'retry_after' => -1,
		);
	}

	/**
	 * Why this site cannot be told to be the job's ('' when it can): five pieces of positive evidence, all of them.
	 * What cannot be read is not evidence: the job is not taken on.
	 *
	 * 1. The WordPress directory, resolved, is the one the swap's plan was written for (its site entry).
	 * 2. The table prefix is the one the plan records.
	 * 3. The maintenance file here is this job's: its data file carries the job's mark.
	 * 4. The job's storage directory is here, with the job's own files in it (its log, its lock or its work directory).
	 * 5. Of each directory the plan swaps, the staged copy or the moved-aside one is here (their names are the job's own).
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	public function here( Job $job ): string {
		$site = $this->site_entry( $job );
		if ( null === $site ) {
			return 'The swap\'s plan cannot be read.';
		}
		$real = Paths::real( $this->abspath );
		if ( false === $real ) {
			return 'The WordPress directory cannot be resolved.';
		}
		if ( rtrim( Paths::normalize( $real ), '/' ) !== $site['live'] ) {
			return 'The WordPress directory is not the one the swap was planned for.';
		}
		if ( $this->prefix !== $site['stage'] ) {
			return 'The table prefix is not the one the swap was planned for.';
		}
		if ( '' === $job->site_mark || Maintenance::OURS !== ( new Maintenance( $this->abspath, $job->site_mark ) )->state() ) {
			return 'The maintenance file here is not this job\'s.';
		}
		if ( '' === $this->files_base( $job ) ) {
			return 'The job\'s storage directory, with its files, is not here.';
		}
		foreach ( $this->plan( $job, (int) $site['attempt'] ) as $entry ) {
			if ( SwapPlan::DIR === $entry['kind'] && ! self::exists( $entry['stage'] ) && ! self::exists( $entry['old'] ) ) {
				return 'A directory the swap moved is not here.';
			}
		}
		return '';
	}

	/**
	 * The plan's site entry of the job's attempt (the cursor's, or the latest the plan has), with the attempt.
	 *
	 * @param Job $job Job.
	 * @return array{live: string, stage: string, attempt: int}|null
	 */
	private function site_entry( Job $job ) {
		try {
			$plan    = new SwapPlan( $this->session, $this->prefix . SwapPlan::TABLE );
			$attempt = (int) ( $job->cursor['attempt'] ?? 0 );
			if ( $attempt <= 0 ) {
				$attempts = $plan->attempts( $job->id );
				$attempt  = isset( $attempts[0]['attempt'] ) ? (int) $attempts[0]['attempt'] : 0;
			}
			$first = $attempt > 0 ? $plan->read( $job->id, $attempt, -1, 1 ) : array();
		} catch ( \RuntimeException $e ) {
			return null;
		}
		if ( ! isset( $first[0] ) || SwapPlan::SITE !== $first[0]['kind'] || 0 !== $first[0]['seq'] ) {
			return null;
		}
		return array(
			'live'    => $first[0]['live'],
			'stage'   => $first[0]['stage'],
			'attempt' => $attempt,
		);
	}

	/**
	 * The plan's entries of an attempt.
	 *
	 * @param Job $job     Job.
	 * @param int $attempt Attempt.
	 * @return array<int, array{seq: int, kind: string, live: string, stage: string, old: string, had_live: bool}>
	 */
	private function plan( Job $job, int $attempt ): array {
		$plan  = new SwapPlan( $this->session, $this->prefix . SwapPlan::TABLE );
		$out   = array();
		$after = -1;
		do {
			$page = $plan->read( $job->id, $attempt, $after, 500 );
			$read = count( $page );
			foreach ( $page as $entry ) {
				$out[] = $entry;
				$after = $entry['seq'];
			}
		} while ( 500 === $read );
		return $out;
	}

	/**
	 * Whether something is at a path (positive evidence: lstat() answered).
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function exists( string $path ): bool {
		return '' !== $path && false !== @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would put the path into the error log.
	}

	/**
	 * Take a job that holds the site, on the evidence (here()): the claim's statement, with who manages it as read
	 * and the mark the maintenance file carries.
	 *
	 * @param int $id    Job id.
	 * @param int $lease Lock duration in seconds.
	 * @return array{job: Job, token: string, healed: bool, taken_over: bool}|null
	 */
	public function acquire( int $id, int $lease = JobRepository::LOCK_SECONDS ) {
		$job = $this->find( $id );
		if ( null === $job || ! in_array( $job->status, array( Job::QUEUED, Job::RUNNING, Job::PAUSED ), true ) || $job->awaiting_answer() || Job::SITE_UNTOUCHED === $job->site_state || '' !== $this->here( $job ) ) {
			return null;
		}
		$token = bin2hex( random_bytes( 16 ) );
		if ( ! $this->store->claim( $id, $token, $lease, 'site_state <> 0 AND ' . Job::MANAGING_SQL . ' = %s AND site_mark = %s', array( $job->managing_token(), $job->site_mark ) ) ) {
			return null;
		}
		$held = $this->find( $id );
		if ( null === $held ) {
			return null;
		}
		$this->lock_file( $held, $token, $held->locked_until );
		return array(
			'job'        => $held,
			'token'      => $token,
			'healed'     => array() !== $job->questions,
			'taken_over' => $held->takeovers !== $job->takeovers || $held->takeover_mark !== $job->takeover_mark,
		);
	}

	/**
	 * Extend the lease.
	 *
	 * @param Job    $job   Job.
	 * @param string $token Lock token.
	 * @param int    $lease Lock duration in seconds.
	 * @return bool
	 */
	public function heartbeat( Job $job, string $token, int $lease = JobRepository::LOCK_SECONDS ): bool {
		$until = $this->store->heartbeat( $job, $token, $lease );
		if ( null === $until ) {
			return false;
		}
		$this->lock_file( $job, $token, $until );
		return true;
	}

	/**
	 * Let the job go.
	 *
	 * @param Job    $job   Job.
	 * @param string $token Lock token.
	 * @return bool
	 */
	public function release( Job $job, string $token ): bool {
		return $this->store->release( $job, $token );
	}

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
	public function save_progress( Job $job, string $token, string $step, array $cursor, int $progress, string $message = '', bool $advanced = true, $site_state = null, $site_mark = null ): int {
		return $this->store->save_progress( $job, $token, $step, $cursor, $progress, $message, $advanced, $site_state, $site_mark );
	}

	/**
	 * Move a job to another status (a run without WordPress ends one; it never retries one).
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
	 * @throws NotHere For a retry (to queued), which a run without WordPress never makes.
	 */
	public function transition( Job $job, string $to, string $error = '', string $token = '', string $failure = '', string $retry_from = '', array $forget = array(), int $at = 0 ): Job {
		if ( Job::QUEUED === $to ) {
			throw new NotHere( 'This run does not retry a job.' );
		}
		$job = $this->store->transition( $job, $to, $error, $token, $failure, $retry_from, $forget, $at, JobRepository::RETRY_FROM_KEY );
		if ( in_array( $to, array( Job::COMPLETED, Job::FAILED, Job::CANCELLED, Job::UNDONE ), true ) ) {
			$base = $this->files_base( $job );
			if ( '' !== $base ) {
				LockFile::remove( $base, $job->id );
			}
		}
		return $job;
	}

	/**
	 * The job's storage directory, when it is here with the job's own files in it (its log, its lock or its work
	 * directory); '' otherwise.
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	public function files_base( Job $job ): string {
		$base = rtrim( $job->storage_path, '/\\' );
		if ( '' === $base || ! @is_dir( $base ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would put the path into the error log.
			return '';
		}
		$own = array( LockFile::path( $base, $job->id ), Residue::work_dir( $base, $job->id ) );
		if ( '' !== $job->log_path ) {
			$own[] = $base . '/' . ltrim( $job->log_path, '/' );
		}
		foreach ( $own as $path ) {
			if ( self::exists( $path ) ) {
				return $base;
			}
		}
		return '';
	}

	/**
	 * Whether the job's files are here.
	 *
	 * @param Job $job Job.
	 * @return bool
	 */
	public function owns_files_of( Job $job ): bool {
		return '' !== $this->files_base( $job );
	}

	/**
	 * One line of this run's own log (the caller's: a run without WordPress has no storage log of its own).
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function log_event( string $message ): void {
		$this->note( $message );
	}

	/**
	 * The lock file of a run that holds the job, in its storage directory when that is here.
	 *
	 * @param Job    $job   Job.
	 * @param string $token Lock token.
	 * @param int    $until End of the lease.
	 * @return void
	 */
	private function lock_file( Job $job, string $token, int $until ): void {
		$base = $this->files_base( $job );
		if ( '' === $base || ! LockFile::write( $base, $job->id, $token, $until ) ) {
			$this->note( sprintf( 'The lock file of job %d could not be written.', $job->id ) );
		}
	}

	/**
	 * A line for the caller.
	 *
	 * @param string $line Line.
	 * @return void
	 */
	private function note( string $line ): void {
		call_user_func( $this->note, $line );
	}

	// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- they only throw.

	/**
	 * Not here: counted only in WordPress.
	 *
	 * @param Job $job Job.
	 * @return int
	 * @throws NotHere Always.
	 */
	public function record_blocked( Job $job ): int {
		throw new NotHere( 'This run does not count a refused tick.' );
	}

	/**
	 * Not here.
	 *
	 * @param int $id Job id.
	 * @return void
	 * @throws NotHere Always.
	 */
	public function reset_cron_deferrals( int $id ): void {
		throw new NotHere( 'This run does not clear the late cron count.' );
	}

	/**
	 * Not here.
	 *
	 * @param Job    $job   Job.
	 * @param string $token Lock token.
	 * @param string $key   Name.
	 * @param mixed  $value Value.
	 * @return void
	 * @throws NotHere Always.
	 */
	public function save_record( Job $job, string $token, string $key, $value ): void {
		throw new NotHere( 'This run does not record facts on a job.' );
	}

	/**
	 * Not here: a run without WordPress never asks.
	 *
	 * @param Job                              $job       Job.
	 * @param string                           $token     Lock token.
	 * @param array<int, array<string, mixed>> $questions Questions.
	 * @return Job
	 * @throws NotHere Always.
	 */
	public function pause_for_answer( Job $job, string $token, array $questions ): Job {
		throw new NotHere( 'This run does not ask.' );
	}

	/**
	 * Not here.
	 *
	 * @param Job    $job     Job.
	 * @param string $message Why.
	 * @return string
	 * @throws NotHere Always.
	 */
	public function fail_unreadable( Job $job, string $message ): string {
		throw new NotHere( 'This run does not fail a job whose row cannot be read.' );
	}

	/**
	 * Not here.
	 *
	 * @param Job      $job      Job.
	 * @param string   $message  Why.
	 * @param string[] $unusable Columns.
	 * @return string
	 * @throws NotHere Always.
	 */
	public function fail_for_missing_columns( Job $job, string $message, array $unusable ): string {
		throw new NotHere( 'This run does not fail a job whose table lacks columns.' );
	}

	/**
	 * Not here.
	 *
	 * @param Job    $job     Job.
	 * @param string $message Why.
	 * @return bool
	 * @throws NotHere Always.
	 */
	public function note_held_back( Job $job, string $message ): bool {
		throw new NotHere( 'This run does not note why a job is held back.' );
	}

	/**
	 * Not here.
	 *
	 * @param Job $job    Job.
	 * @param int $budget Entries at most.
	 * @return bool
	 * @throws NotHere Always.
	 */
	public function reclaim_work( Job $job, int $budget = JobRepository::RECLAIM_MAX_ENTRIES ): bool {
		throw new NotHere( 'This run does not reclaim a job\'s work.' );
	}

	// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
}
