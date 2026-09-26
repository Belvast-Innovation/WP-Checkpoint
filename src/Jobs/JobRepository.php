<?php
/**
 * Persistence, locking and housekeeping for jobs.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Paths;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * All database access for the jobs table.
 *
 * Locking: one UPDATE with a "not locked or expired" condition is the
 * compare-and-set between drivers (browser polling, loopback, WP-CLI).
 * Everything a lock holder writes afterwards (heartbeat, release, cursor,
 * leaving the running status) is guarded by its token, so a driver whose
 * lease expired and was taken over cannot overwrite the new holder's work.
 * The lock file in the storage tmp/ directory exists for the whole running
 * phase of a job (first acquire until a terminal status) and mirrors the
 * lease for observers that cannot read this database.
 *
 * Storage gate: a job is bound to the storage directory it was created for
 * (token + path). Ticks are refused while the directory is unusable or has
 * changed (clone detected); consecutive refusals back off 5 s, 15 s, 60 s,
 * 5 min. Once the storage situation is settled, jobs bound to another
 * directory are failed. Files (lock file, log) are only ever touched inside
 * the current storage directory; another directory belongs to another
 * installation or is abandoned.
 *
 * The read methods (find, list_jobs, counts) check that the table exists
 * before querying: a query against a missing table is not an exception in
 * $wpdb but a line in the error log, and the reads are reached before
 * Schema::ensure() ran (a REST poll on a fresh install, the environment
 * page, storage settlement). One SHOW TABLES per read is the price; the
 * writes only run after acquire() found the row. The reaper does not rely
 * on that null: find_for_reclaim() treats a missing table as unsafe.
 */
final class JobRepository {

	const LOCK_SECONDS      = 120;
	const BACKOFF_SECONDS   = array( 5, 15, 60, 300 );
	const STALL_SECONDS     = 86400;
	const RETENTION_SECONDS = array(
		Job::COMPLETED => 2592000, // 30 days
		Job::CANCELLED => 2592000,
		Job::FAILED    => 7776000, // 90 days
	);
	const MAX_ROWS          = 1000;
	const REAP_THROTTLE     = 600;
	const PURGE_THROTTLE    = 86400;
	const SECRET_MIN_LENGTH = 4;
	/**
	 * A failed job keeps its work files this long after failing so it can
	 * be retried; the row itself stays for RETENTION_SECONDS[failed].
	 */
	const WORK_RETENTION_SECONDS = 604800;
	/**
	 * Entries one reclaim pass deletes at most (Deleter::delete_tree()).
	 */
	const RECLAIM_MAX_ENTRIES = 2000;

	/**
	 * Bounds on what a step may ask and a person may answer. Questions
	 * carry only identifiers and counts (see validate_questions()), so the
	 * byte cap is a backstop: MAX_QUESTIONS questions of maximal legal
	 * shape are about two thirds of it (the reachability test measures).
	 */
	const MAX_QUESTIONS       = 32;
	const MAX_QUESTIONS_BYTES = 32768;
	const MAX_ANSWERS_BYTES   = 4096;
	const MAX_CHOICES         = 16;

	/**
	 * Storage directories.
	 *
	 * @var Directories
	 */
	private $directories;

	/**
	 * Database clock minus local clock, read once (see now()).
	 *
	 * @var int|null
	 */
	private $db_offset;

	/**
	 * Time source (tests inject one).
	 *
	 * @var callable|null
	 */
	private $clock;

	/**
	 * Redactor used for stored error messages.
	 *
	 * @var Redactor
	 */
	private $redactor;

	/**
	 * Constructor.
	 *
	 * @param Directories   $directories Storage directories.
	 * @param Redactor|null $redactor    Redactor for error messages.
	 * @param callable|null $clock       Returns the current Unix timestamp.
	 */
	public function __construct( Directories $directories, $redactor = null, $clock = null ) {
		$this->directories = $directories;
		$this->redactor    = $redactor instanceof Redactor ? $redactor : new Redactor( Redactor::installation_secrets() );
		$this->clock       = is_callable( $clock ) ? $clock : null;
	}

	/**
	 * One line in the storage log (storage.log): engine events that concern
	 * no single job's log, or that must be found without one.
	 *
	 * @param string $message Message (no paths, no site data).
	 * @return void
	 */
	public function log_event( string $message ): void {
		$this->directories->log_event( $message );
	}

	/**
	 * Current time on the database's clock: leases are written and judged
	 * by every web server of a site against the one database, so a web
	 * server whose clock drifts neither steals a live lease nor keeps a dead
	 * one. The offset to the local clock is read once per instance (one
	 * query) and applied to time(). An injected clock (tests) is used as is.
	 *
	 * @return int
	 */
	public function now(): int {
		if ( null !== $this->clock ) {
			return (int) call_user_func( $this->clock );
		}
		if ( null === $this->db_offset ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the database clock, no table.
			$db = $wpdb->get_var( 'SELECT UNIX_TIMESTAMP()' );
			if ( ! is_numeric( $db ) ) {
				// Not cached: the next call asks again. Until then the local clock stands in, and the log says so.
				$this->directories->log_event( 'The database clock could not be read; the web server clock is used for this call.' );
				return time();
			}
			$this->db_offset = (int) $db - time();
		}
		return time() + $this->db_offset;
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		return Schema::jobs_table();
	}

	/**
	 * Create a queued job bound to the current storage directory.
	 *
	 * @param string               $type       Job type id.
	 * @param int                  $owner_user Creating user.
	 * @param array<string, mixed> $cursor     Initial cursor (identifiers only).
	 * @param array<string, mixed> $options    Settings for the job type (identifiers, flags and rules; never credentials).
	 * @return Job
	 * @throws JobsUnavailable When jobs cannot be created right now.
	 */
	public function create( string $type, int $owner_user = 0, array $cursor = array(), array $options = array() ): Job {
		global $wpdb;

		// The first write a request makes may be this one (a command run right after an update): the row
		// needs every column of the current schema. Where the request may upgrade (the admin, cron, WP-CLI), the
		// columns are read back and lost ones added again; elsewhere only the stored version is read.
		$schema = Schema::ensure( true );
		if ( 'failed' === $schema['action'] ) {
			throw new JobsUnavailable( esc_html( Schema::problem_message( $schema['problems'] ?? null ) ) );
		}
		if ( 'pending' === $schema['action'] ) {
			throw new JobsUnavailable( esc_html( Schema::pending_message( $schema['last_problems'] ?? null ) ) );
		}
		if ( ! Schema::is_compatible() ) {
			throw new JobsUnavailable( esc_html__( 'The database structure was created by a newer version of WP Checkpoint. Please update the plugin.', 'wp-checkpoint' ) );
		}
		$base = $this->directories->base();
		if ( '' === $base ) {
			throw new JobsUnavailable( esc_html( $this->directories->last_error() ) );
		}
		if ( 1 !== preg_match( '/^[a-z0-9_-]{1,64}$/', $type ) ) {
			throw new JobsUnavailable( esc_html__( 'Unknown job type.', 'wp-checkpoint' ) );
		}
		self::assert_cursor_has_no_secrets( $cursor );
		self::assert_cursor_has_no_secrets( $options );

		$state = $this->directories->state();
		$now   = $this->now();
		$row   = array(
			'site_id'       => get_current_blog_id(),
			'type'          => $type,
			'status'        => Job::QUEUED,
			'cursor_json'   => wp_json_encode( $cursor ),
			'options_json'  => wp_json_encode( $options ),
			'storage_token' => (string) $state['token'],
			'storage_path'  => $base,
			'owner_user'    => $owner_user,
			'created_at'    => $now,
			'updated_at'    => $now,
			'progress_at'   => $now,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$wpdb->insert( self::table(), $row, array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' ) );
		$id = (int) $wpdb->insert_id;
		if ( $id <= 0 ) {
			throw new JobsUnavailable( esc_html__( 'The job could not be stored.', 'wp-checkpoint' ) );
		}

		$log_path = 'logs/job-' . $id . '-' . bin2hex( random_bytes( 4 ) ) . '.log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$wpdb->update( self::table(), array( 'log_path' => $log_path ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );

		$job = $this->find( $id );
		if ( null === $job ) {
			throw new JobsUnavailable( esc_html__( 'The job could not be stored.', 'wp-checkpoint' ) );
		}
		return $job;
	}

	/**
	 * Serialise job starts (insert, then the conflict check) across
	 * requests with a named database lock: with interleaved auto-increment
	 * (innodb_autoinc_lock_mode = 2) two inserts can commit out of id order,
	 * and each check could then miss the other. Returns false when another
	 * request holds the lock beyond the timeout; true when the lock is held
	 * or the database has no named locks (the id order check still applies).
	 *
	 * @param int $timeout Seconds to wait.
	 * @return bool
	 */
	public function lock_starts( int $timeout = 5 ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a named lock, not data.
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::start_lock_name(), $timeout ) );
		return null === $got || '1' === (string) $got;
	}

	/**
	 * Release the lock taken by lock_starts().
	 *
	 * @return void
	 */
	public function unlock_starts(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a named lock, not data.
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::start_lock_name() ) );
	}

	/**
	 * Name of the start lock: one per jobs table (installations sharing a
	 * database server do not wait for each other). At most 64 characters.
	 *
	 * @return string
	 */
	private static function start_lock_name(): string {
		global $wpdb;
		return 'wpcheckpoint_start_' . substr( md5( ( defined( 'DB_NAME' ) ? (string) constant( 'DB_NAME' ) : '' ) . '.' . $wpdb->base_prefix . Schema::JOBS_TABLE ), 0, 16 );
	}

	/**
	 * Remove a job that has never started: still queued, never attempted,
	 * no lock. One statement, so a driver that picked the job up meanwhile
	 * makes it a no-op (the caller then cancels instead).
	 *
	 * @param int $id Job id.
	 * @return bool Whether the row was removed.
	 */
	public function discard_unstarted( int $id ): bool {
		global $wpdb;
		$table = $wpdb->base_prefix . Schema::JOBS_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table name from the prefix.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d AND status = %s AND attempts = 0 AND lock_token = ''", $id, Job::QUEUED ) );
		return 1 === $deleted;
	}

	/**
	 * Load a job.
	 *
	 * @param int $id Job id.
	 * @return Job|null
	 */
	public function find( int $id ) {
		global $wpdb;
		if ( ! Schema::table_exists() ) {
			return null;
		}
		$table = $wpdb->base_prefix . Schema::JOBS_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table name from the prefix.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Jobs, newest first.
	 *
	 * @param string[] $statuses Only these statuses (empty for all).
	 * @param int      $limit    Maximum rows.
	 * @return Job[]
	 */
	public function list_jobs( array $statuses = array(), int $limit = 50 ): array {
		global $wpdb;
		if ( ! Schema::table_exists() ) {
			return array();
		}
		$table    = $wpdb->base_prefix . Schema::JOBS_TABLE;
		$statuses = array_values( array_intersect( $statuses, Job::statuses() ) );
		$limit    = max( 1, min( 500, $limit ) );
		if ( array() === $statuses ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table name from the prefix.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
		} else {
			$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built from a validated list.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status IN ({$placeholders}) ORDER BY id DESC LIMIT %d", array_merge( $statuses, array( $limit ) ) ), ARRAY_A );
		}
		return array_map( array( __CLASS__, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Number of jobs per status.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		global $wpdb;
		$table  = $wpdb->base_prefix . Schema::JOBS_TABLE;
		$counts = array_fill_keys( Job::statuses(), 0 );
		if ( ! Schema::table_exists() ) {
			return $counts;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table name from the prefix.
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ] = (int) $row['n'];
			}
		}
		return $counts;
	}

	/**
	 * Whether a job may be ticked now, and how long to wait otherwise.
	 *
	 * @param Job $job Job.
	 * @return array{allowed: bool, reason: string, message: string, retry_after: int}
	 */
	public function gate( Job $job ): array {
		if ( $job->awaiting_answer() ) {
			// Checked first: a storage refusal must not count as a blocked tick (record_blocked() writes
			// updated_at, the clock the retention rule for unanswered jobs reads). Nothing will change
			// until a person answers, so there is no back-off either.
			return self::verdict( false, 'awaiting_answer', __( 'The job is waiting for your decision.', 'wp-checkpoint' ), -1 );
		}
		$retry = self::BACKOFF_SECONDS[ min( $job->blocked_count, count( self::BACKOFF_SECONDS ) - 1 ) ];
		if ( ! Schema::is_compatible() ) {
			return self::verdict( false, 'schema', __( 'The database structure was created by a newer version of WP Checkpoint. Please update the plugin.', 'wp-checkpoint' ), $retry );
		}
		$base = $this->directories->base();
		if ( '' === $base ) {
			return self::verdict( false, 'storage_unavailable', $this->directories->last_error(), $retry );
		}
		$state = $this->directories->state();
		// The job's files are where it was started (its row's storage_path), and no driver resolves them again:
		// a request that resolves another directory (another token, or the same token at another path) does not
		// run the job at all.
		$moved = '' !== $job->storage_path && ! Paths::same_location( $job->storage_path, $base );
		if ( (string) $state['token'] !== $job->storage_token || $moved ) {
			if ( ! empty( $state['clone_detected'] ) ) {
				$message = __( 'The storage directory changed: resolve the clone notice (continue with the original directory or keep the new one) before this job can continue.', 'wp-checkpoint' );
			} elseif ( RestoreJob::ID === $job->type ) {
				$message = __( 'This restore keeps its files in the storage directory it was started with, and this request uses another one (for example, WPCHECKPOINT_STORAGE_DIR is set differently for WP-CLI and for the web server). The restore continues only from a request that uses the same directory.', 'wp-checkpoint' );
			} else {
				$message = __( 'The storage directory changed; this job cannot continue.', 'wp-checkpoint' );
			}
			return self::verdict( false, 'storage_changed', $message, $retry );
		}
		return self::verdict( true, '', '', 0 );
	}

	/**
	 * Record a refused tick and return the suggested wait.
	 *
	 * @param Job $job Job.
	 * @return int Seconds.
	 */
	public function record_blocked( Job $job ): int {
		global $wpdb;
		++$job->blocked_count;
		$job->updated_at = $this->now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$wpdb->update(
			self::table(),
			array(
				'blocked_count' => $job->blocked_count,
				'updated_at'    => $job->updated_at,
			),
			array( 'id' => $job->id ),
			array( '%d', '%d' ),
			array( '%d' )
		);
		return self::BACKOFF_SECONDS[ min( $job->blocked_count, count( self::BACKOFF_SECONDS ) - 1 ) ];
	}

	/**
	 * Whether a job (of $type, or of any type) is queued, running or paused:
	 * true, false, or null when the jobs table could not be read (no
	 * evidence either way). Only the server's answer that the table does not
	 * exist (SHOW TABLES answering without it) is false without a count: no
	 * job exists without it.
	 *
	 * @param string|null $type Job type, or null for any.
	 * @return bool|null
	 */
	public static function has_unfinished( $type = null ) {
		global $wpdb;
		$where = null === $type ? '' : $wpdb->prepare( ' AND type = %s', (string) $type );
		$quiet = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table; read on the rare requests that would move the storage directory.
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE status IN (%s, %s, %s)', Job::QUEUED, Job::RUNNING, Job::PAUSED ) . $where ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from the prefix and a constant; $where prepared above.
		if ( null !== $count && is_numeric( $count ) ) {
			$wpdb->suppress_errors( $quiet );
			return (int) $count > 0;
		}
		// The count failed: only the server's answer that there is no such table is an answer (no job without it).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema check; wpdb clears last_error at the start of each query.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table() ) ) );
		$read  = '' === $wpdb->last_error;
		$wpdb->suppress_errors( $quiet );
		return $read && null === $found ? false : null;
	}

	/**
	 * Count a cron request that started too late to hand the job to the
	 * Runner, in one statement that counts only while fewer than $limit are
	 * counted, the job is queued, running or paused (not waiting for an
	 * answer) and no live run holds it (a job being run is not one late
	 * requests keep from running). Reaching the Runner sets the count back
	 * to 0 (reset_cron_deferrals()), and so do a retry and an answer.
	 *
	 * @param int $id    Job id.
	 * @param int $limit The count this call does not go beyond (see JobActions::cron_tick()).
	 * @return bool True when this request was counted; false when $limit were counted already, the job is
	 *              not active or waits for an answer, or the write failed.
	 */
	public function count_cron_deferral( int $id, int $limit ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table; one statement counts and checks the limit.
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET cron_deferrals = cron_deferrals + 1 WHERE id = %d AND cron_deferrals < %d AND status IN (%s, %s, %s) AND (status <> %s OR questions_json IS NULL OR questions_json = '' OR questions_json = '[]') AND (lock_token = '' OR locked_until < %d)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from the prefix and a constant.
				$id,
				$limit,
				Job::QUEUED,
				Job::RUNNING,
				Job::PAUSED,
				Job::PAUSED,
				$this->now()
			)
		);
		return 1 === $affected;
	}

	/**
	 * Set the late cron count back to 0: the job reached the Runner
	 * (Runner::tick()), whatever the tick then does. One statement.
	 *
	 * @param int $id Job id.
	 * @return void
	 */
	public function reset_cron_deferrals( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET cron_deferrals = 0 WHERE id = %d AND cron_deferrals <> 0', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from the prefix and a constant.
	}

	/**
	 * Fail a job whose late cron requests reached $limit deferrals, in one
	 * statement: only while the count is still there (no driver handed the
	 * job to the Runner, and no retry or answer set it back meanwhile), the
	 * job is queued, running or paused without
	 * questions, and no live run holds it (a running job between ticks has
	 * no lock). Its lock file goes with it, as with any ended job.
	 *
	 * @param Job    $job     Job (updated in place when failed).
	 * @param string $message Why.
	 * @param int    $limit   The count that fails it.
	 * @return string FAIL_DONE when it was failed, FAIL_HELD when the fence refused (a live run, the count set
	 *                back, a question, ended), FAIL_ERROR when the statement itself failed.
	 */
	public function fail_for_late_cron( Job $job, string $message, int $limit ): string {
		global $wpdb;
		$now = $this->now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table; the WHERE is the fence.
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = %s, last_error = %s, failure_kind = %s, finished_at = %d, updated_at = %d, lock_token = '', locked_until = 0 WHERE id = %d AND cron_deferrals >= %d AND status IN (%s, %s, %s) AND (status <> %s OR questions_json IS NULL OR questions_json = '' OR questions_json = '[]') AND (lock_token = '' OR locked_until < %d)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from the prefix and a constant.
				Job::FAILED,
				$this->redactor->redact( $message ),
				Job::stamp_failure( Job::FAILURE_TEMPORARY, $now ),
				$now,
				$now,
				$job->id,
				$limit,
				Job::QUEUED,
				Job::RUNNING,
				Job::PAUSED,
				Job::PAUSED,
				$now
			)
		);
		if ( false === $affected ) {
			return self::FAIL_ERROR;
		}
		if ( 1 !== $affected ) {
			return self::FAIL_HELD;
		}
		$this->remove_lock_file( $job );
		$read = $this->find( $job->id ); // Read back: one parser for the stamped kind.
		foreach ( get_object_vars( null !== $read ? $read : $job ) as $key => $value ) {
			$job->$key = $value;
		}
		if ( null === $read ) {
			$job->status     = Job::FAILED;
			$job->last_error = $this->redactor->redact( $message );
			$job->lock_token = '';
		}
		return self::FAIL_DONE;
	}

	/**
	 * Outcomes of fail_for_missing_columns().
	 */
	const FAIL_DONE  = 'failed';
	const FAIL_HELD  = 'held';
	const FAIL_ERROR = 'error';

	/**
	 * Fail a job the table cannot hold as this code writes it (columns
	 * missing, or narrower than needed), in one statement that writes only
	 * columns of the first schema version (status, error, times, lock) and
	 * the failure kind when it is usable: the other writes of a failure need
	 * columns the table may not have. Only a job no live run holds; its lock
	 * file goes with it, as with any ended job.
	 *
	 * @param Job      $job      Job, as read (updated in place).
	 * @param string   $message  Why.
	 * @param string[] $unusable The columns missing or too narrow.
	 * @return string FAIL_DONE when it was failed, FAIL_HELD when a live run holds it (or it ended meanwhile),
	 *                FAIL_ERROR when the statement itself failed.
	 */
	public function fail_for_missing_columns( Job $job, string $message, array $unusable ): string {
		global $wpdb;
		$now     = $this->now();
		$data    = array(
			'status'       => Job::FAILED,
			'last_error'   => $this->redactor->redact( $message ),
			'finished_at'  => $now,
			'updated_at'   => $now,
			'lock_token'   => '',
			'locked_until' => 0,
		);
		$formats = array( '%s', '%s', '%d', '%d', '%s', '%d' );
		if ( ! in_array( 'failure_kind', $unusable, true ) ) {
			// Temporary: a retry works once the columns are there.
			$data['failure_kind'] = Job::stamp_failure( Job::FAILURE_TEMPORARY, $now );
			$formats[]            = '%s';
		}
		$sets = array();
		foreach ( array_keys( $data ) as $i => $column ) {
			$sets[] = $column . ' = ' . $formats[ $i ];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table; the WHERE keeps a live run's job and an ended job as they are.
		$affected = $wpdb->query(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one value per placeholder: the SET list is built from the same array.
				'UPDATE ' . self::table() . ' SET ' . implode( ', ', $sets ) . " WHERE id = %d AND status IN (%s, %s, %s) AND (lock_token = '' OR locked_until < %d)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from the prefix and a constant; column names from a fixed list.
				array_merge( array_values( $data ), array( $job->id, Job::QUEUED, Job::RUNNING, Job::PAUSED, $now ) )
			)
		);
		if ( false === $affected ) {
			return self::FAIL_ERROR;
		}
		if ( 1 !== $affected ) {
			return self::FAIL_HELD;
		}
		$this->remove_lock_file( $job );
		foreach ( $data as $key => $value ) {
			$job->$key = $value;
		}
		// The object answers like a row read back (the same parsing as hydrate()).
		$stored              = isset( $data['failure_kind'] ) ? (string) $data['failure_kind'] : '';
		$job->failure_kind   = Job::read_failure_kind( $stored, $now );
		$job->failure_reason = Job::read_failure_reason( $stored, $now );
		return self::FAIL_DONE;
	}

	/**
	 * Try to take the lock. Only queued, running and paused jobs bound to
	 * the current storage directory can be acquired; a queued or paused job
	 * becomes running (resuming a paused job is not a restart: attempts
	 * only counts queued→running). A paused job that still waits for an
	 * answer is refused here as well as by gate(): the compare-and-set
	 * requires an empty questions column for a paused row.
	 *
	 * A queued or running row that carries questions is acquired and the
	 * questions cleared ("healed"): pause_for_answer() writes questions and
	 * status in one statement, so such a row can only come from a job that
	 * was failed while waiting (the reaper, the storage settlement) and then
	 * retried, or from a version of this code that wrote them separately.
	 * The caller logs it: how often it happens is the only measure of that
	 * window.
	 *
	 * A takeover (the row still carries another run's token, expired: that
	 * run never released, it was killed) is counted in the same statement:
	 * takeovers + 1 when the job stands where the last takeover found it
	 * (takeover_mark, MD5 of step and stored cursor), 1 when it moved.
	 * Progress alone does not reset the count, the position does: a run that
	 * checkpoints a few bounded units and then dies in an unbounded one comes
	 * back to the same position and is counted. Both assignments come before
	 * lock_token's, so they read the row's old token and mark whether the
	 * server evaluates assignments left to right or all at once.
	 *
	 * The storage token is part of the compare-and-set, so a caller that
	 * skipped gate() still cannot run a job bound to another directory.
	 *
	 * @param int $id    Job id.
	 * @param int $lease Lock duration in seconds.
	 * @return array{job: Job, token: string, healed: bool, taken_over: bool}|null Null when another driver holds the lock or the job is not runnable here.
	 */
	public function acquire( int $id, int $lease = self::LOCK_SECONDS ) {
		global $wpdb;
		$job = $this->find( $id );
		if ( null === $job || ! in_array( $job->status, array( Job::QUEUED, Job::RUNNING, Job::PAUSED ), true ) || $job->awaiting_answer() ) {
			return null;
		}
		if ( '' === $this->directories->base() ) {
			return null;
		}
		$storage_token    = (string) $this->directories->state()['token'];
		$now              = $this->now();
		$token            = bin2hex( random_bytes( 16 ) );
		$table            = $wpdb->base_prefix . Schema::JOBS_TABLE;
		$before_questions = $job->questions;
		$before_status    = $job->status;
		$before_takeovers = $job->takeovers;
		$before_mark      = $job->takeover_mark;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table name from the prefix; the WHERE clause is the compare-and-set.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET takeovers = IF(lock_token <> '', IF(takeover_mark = MD5(CONCAT(step, '|', COALESCE(cursor_json, ''))), takeovers + 1, 1), takeovers), takeover_mark = IF(lock_token <> '', MD5(CONCAT(step, '|', COALESCE(cursor_json, ''))), takeover_mark), attempts = IF(status = %s, attempts + 1, attempts), started_at = IF(started_at = 0, %d, started_at), status = %s, lock_token = %s, locked_until = %d, updated_at = %d, questions_json = NULL WHERE id = %d AND status IN (%s, %s, %s) AND (status <> %s OR questions_json IS NULL OR questions_json = '' OR questions_json = '[]') AND storage_token = %s AND (lock_token = '' OR locked_until < %d)",
				Job::QUEUED,
				$now,
				Job::RUNNING,
				$token,
				$now + $lease,
				$now,
				$id,
				Job::QUEUED,
				Job::RUNNING,
				Job::PAUSED,
				Job::PAUSED,
				$storage_token,
				$now
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 !== (int) $affected ) {
			return null;
		}
		$job = $this->find( $id );
		if ( null === $job ) {
			return null;
		}
		$this->write_lock_file( $job, $token, $job->locked_until );
		$healed = array() !== $before_questions;
		// A takeover changed the count or the mark (the row's own values, set in the statement above).
		$taken_over = $job->takeovers !== $before_takeovers || $job->takeover_mark !== $before_mark;
		if ( $healed ) {
			$this->directories->log_event( sprintf( 'Job %d: stale questions cleared on acquire (the job was %s, not paused).', $id, $before_status ) );
		}
		return array(
			'job'        => $job,
			'token'      => $token,
			'healed'     => $healed,
			'taken_over' => $taken_over,
		);
	}

	/**
	 * Take the lock in order to cancel: the same compare-and-set as acquire()
	 * (nobody else is working on the job once it succeeds), but the job is
	 * not started: attempts, started_at, the status and the lock file stay as
	 * they are, so a job cancelled before its first step still reads
	 * "never attempted".
	 *
	 * @param int $id Job id.
	 * @return array{job: Job, token: string}|null Null when another driver holds the lock or the job is not cancellable here.
	 */
	public function acquire_for_cancel( int $id ) {
		global $wpdb;
		$job = $this->find( $id );
		if ( null === $job || ! in_array( $job->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
			return null;
		}
		if ( '' === $this->directories->base() ) {
			return null;
		}
		$storage_token = (string) $this->directories->state()['token'];
		$now           = $this->now();
		$token         = bin2hex( random_bytes( 16 ) );
		$table         = $wpdb->base_prefix . Schema::JOBS_TABLE;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table name from the prefix; the WHERE clause is the compare-and-set.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET lock_token = %s, locked_until = %d, updated_at = %d WHERE id = %d AND status IN (%s, %s) AND storage_token = %s AND (lock_token = '' OR locked_until < %d)",
				$token,
				$now + self::LOCK_SECONDS,
				$now,
				$id,
				Job::QUEUED,
				Job::RUNNING,
				$storage_token,
				$now
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 !== (int) $affected ) {
			return null;
		}
		$job = $this->find( $id );
		if ( null === $job ) {
			return null;
		}
		return array(
			'job'   => $job,
			'token' => $token,
		);
	}

	/**
	 * Extend the lock.
	 *
	 * @param Job    $job   Job.
	 * @param string $token Lock token.
	 * @param int    $lease Lock duration in seconds.
	 * @return bool False when the lock is no longer ours.
	 */
	public function heartbeat( Job $job, string $token, int $lease = self::LOCK_SECONDS ): bool {
		global $wpdb;
		$until = $this->now() + $lease;
		$table = $wpdb->base_prefix . Schema::JOBS_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table name from the prefix.
		$affected = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET locked_until = %d, updated_at = %d WHERE id = %d AND lock_token = %s", $until, $this->now(), $job->id, $token ) );
		if ( 1 !== (int) $affected && ! $this->holds_lock( $job->id, $token ) ) {
			return false;
		}
		$job->locked_until = $until;
		$this->write_lock_file( $job, $token, $until );
		return true;
	}

	/**
	 * Give the database lock back between two ticks. The lock file stays: it
	 * covers the whole running phase and is removed with the terminal status.
	 *
	 * @param Job    $job   Job.
	 * @param string $token Lock token.
	 * @return bool False when the lock was not ours.
	 */
	public function release( Job $job, string $token ): bool {
		global $wpdb;
		$table = $wpdb->base_prefix . Schema::JOBS_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table name from the prefix.
		$affected = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET lock_token = '', locked_until = 0, updated_at = %d WHERE id = %d AND lock_token = %s", $this->now(), $job->id, $token ) );
		if ( 1 !== (int) $affected ) {
			return false;
		}
		$job->lock_token   = '';
		$job->locked_until = 0;
		return true;
	}

	/**
	 * Save the position of a running job; only the lock holder may.
	 *
	 * Only real progress ($advanced) moves progress_at and resets the gate
	 * back-off, in the same statement: a wait or a retried failure keeps the
	 * stall timestamp, so a job that only ever waits is still given up after
	 * 24 hours by reap().
	 *
	 * @param Job                  $job      Job.
	 * @param string               $token    Lock token.
	 * @param string               $step     Current step id.
	 * @param array<string, mixed> $cursor   Cursor (identifiers and offsets only).
	 * @param int                  $progress Percentage.
	 * @param string               $message  Progress text.
	 * @param bool                 $advanced Whether the cursor really moved.
	 * @return void
	 * @throws StaleJob When the lock is no longer held with this token.
	 */
	public function save_progress( Job $job, string $token, string $step, array $cursor, int $progress, string $message = '', bool $advanced = true ): void {
		global $wpdb;
		self::assert_cursor_has_no_secrets( $cursor );
		$now      = $this->now();
		$progress = max( 0, min( 100, $progress ) );
		$data     = array(
			'step'             => $step,
			'cursor_json'      => wp_json_encode( $cursor ),
			'progress'         => $progress,
			'progress_message' => $message,
			'updated_at'       => $now,
		);
		$formats  = array( '%s', '%s', '%d', '%s', '%d' );
		if ( $advanced ) {
			$data['progress_at']   = $now;
			$data['blocked_count'] = 0;
			$formats[]             = '%d';
			$formats[]             = '%d';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table; the WHERE on lock_token is the fence.
		$affected = $wpdb->update(
			self::table(),
			$data,
			array(
				'id'         => $job->id,
				'lock_token' => $token,
			),
			$formats,
			array( '%d', '%s' )
		);
		if ( 1 !== (int) $affected && ! $this->holds_lock( $job->id, $token ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
			throw new StaleJob( sprintf( 'Job %d is no longer locked by this driver.', $job->id ) );
		}
		$job->step             = $step;
		$job->cursor           = $cursor;
		$job->progress         = $progress;
		$job->progress_message = $message;
		$job->updated_at       = $now;
		if ( $advanced ) {
			$job->progress_at   = $now;
			$job->blocked_count = 0;
		}
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages carry field names and numbers; the runner stores them through the redactor and the presenter cleans them before display.

	/**
	 * Record a step's questions and pause the job in one fenced statement:
	 * questions_json, status = paused and the cleared lock go out together
	 * (WHERE status = running AND lock_token = token), so a tick that dies
	 * here leaves either a running job without questions (the step asks
	 * again) or a paused job with them, never a row no code path expects.
	 * The cursor was stored by save_progress() just before, with advanced
	 * = false: asking is not progress, and a paused job is not subject to
	 * the stall rule anyway (reap() only stalls queued and running jobs; a
	 * job left unanswered is given up by the retention rule instead).
	 *
	 * The lock file stays with its old lease, as for every paused job;
	 * StorageReclaim::is_busy() treats it as stale after the lease plus its
	 * grace period, so a job paused for days does not block a directory
	 * take-over.
	 *
	 * @param Job                              $job       Job (updated in place).
	 * @param string                           $token     Lock token.
	 * @param array<int, array<string, mixed>> $questions Questions (validated, see validate_questions()).
	 * @return Job
	 * @throws \InvalidArgumentException When the questions are not well-formed or carry a secret.
	 * @throws InvalidTransition When the job is not running.
	 * @throws StaleJob When the lock is no longer held with this token.
	 */
	public function pause_for_answer( Job $job, string $token, array $questions ): Job {
		$questions = self::validate_questions( $questions );
		if ( '' === $token || Job::RUNNING !== $job->status ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
			throw new InvalidTransition( sprintf( 'Job %d: only the lock holder of a running job can pause it for an answer.', $job->id ) );
		}
		$json           = wp_json_encode( $questions );
		$job            = $this->write_transition( $job, Job::PAUSED, '', $token, array( 'questions_json' => (string) $json ), array( '%s' ) );
		$job->questions = $questions;
		return $job;
	}

	/**
	 * The shape a question must have: an array with an "id" (a token that
	 * the answer is keyed by, unique within the list) and optionally
	 * "kind" (a token), "count" and "bytes" (non-negative integers),
	 * "file" (the name of a file under the job's work directory holding the
	 * details: paths, table and column names) and "choices" (up to
	 * MAX_CHOICES tokens the answer may take). Nothing else: a question is
	 * a pointer for the user interface, the details stay in the work
	 * directory, so no user data (paths, names, values) ever lands in the
	 * job row or the client payload. At most MAX_QUESTIONS questions and
	 * MAX_QUESTIONS_BYTES encoded; no secret in any of it.
	 *
	 * @param array<mixed> $questions Questions as the step gave them.
	 * @return array<int, array<string, mixed>> The same questions as a list.
	 * @throws \InvalidArgumentException When the shape is wrong.
	 */
	public static function validate_questions( array $questions ): array {
		$questions = array_values( $questions );
		if ( array() === $questions ) {
			throw new \InvalidArgumentException( 'A step that asks must ask at least one question.' );
		}
		if ( count( $questions ) > self::MAX_QUESTIONS ) {
			throw new \InvalidArgumentException( sprintf( 'A step may ask at most %d questions at once.', self::MAX_QUESTIONS ) );
		}
		$ids = array();
		foreach ( $questions as $i => $question ) {
			if ( ! is_array( $question ) ) {
				throw new \InvalidArgumentException( sprintf( 'Question %d is not an array.', $i ) );
			}
			foreach ( $question as $key => $value ) {
				switch ( $key ) {
					case 'id':
					case 'kind':
						if ( ! is_string( $value ) || 1 !== preg_match( '/\A[a-z0-9_-]{1,64}\z/', $value ) ) {
							throw new \InvalidArgumentException( sprintf( 'Question %d: "%s" must be a token of 1 to 64 characters (a-z, 0-9, _ and -).', $i, (string) $key ) );
						}
						break;
					case 'count':
					case 'bytes':
						if ( ! is_int( $value ) || $value < 0 ) {
							throw new \InvalidArgumentException( sprintf( 'Question %d: "%s" must be a non-negative integer.', $i, (string) $key ) );
						}
						break;
					case 'file':
						if ( ! is_string( $value ) || 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $value ) ) {
							throw new \InvalidArgumentException( sprintf( 'Question %d: "file" must be a plain file name.', $i ) );
						}
						break;
					case 'choices':
						if ( ! is_array( $value ) || array() === $value || count( $value ) > self::MAX_CHOICES || array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
							throw new \InvalidArgumentException( sprintf( 'Question %d: "choices" must be a list of 1 to %d tokens.', $i, self::MAX_CHOICES ) );
						}
						foreach ( $value as $choice ) {
							if ( ! is_string( $choice ) || 1 !== preg_match( '/\A[a-z0-9_-]{1,32}\z/', $choice ) ) {
								throw new \InvalidArgumentException( sprintf( 'Question %d: every choice must be a token of 1 to 32 characters.', $i ) );
							}
						}
						break;
					default:
						throw new \InvalidArgumentException( sprintf( 'Question %d: unknown field "%s"; details belong in a file under the work directory.', $i, (string) $key ) );
				}
			}
			if ( ! isset( $question['id'] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Question %d has no id.', $i ) );
			}
			if ( isset( $ids[ $question['id'] ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Question id "%s" is used twice.', $question['id'] ) );
			}
			$ids[ $question['id'] ] = true;
		}
		self::assert_cursor_has_no_secrets( $questions );
		$json = wp_json_encode( $questions );
		if ( ! is_string( $json ) ) {
			throw new \InvalidArgumentException( 'Questions cannot be encoded.' );
		}
		if ( strlen( $json ) > self::MAX_QUESTIONS_BYTES ) {
			throw new \InvalidArgumentException( sprintf( 'Questions must encode to at most %d bytes.', self::MAX_QUESTIONS_BYTES ) );
		}
		return $questions;
	}

	/**
	 * Store the answers to a paused job's questions and clear them, so the
	 * next tick resumes the job. Answers are a flat map from the ids of the
	 * questions currently asked (an answer to a question that was not
	 * asked is an error, not silently merged) to scalars (a string of at
	 * most 256 bytes, an integer or a boolean); when a question lists
	 * choices, the answer must be one of them. They replace earlier answers
	 * to the same ids under the options' "answers" key. Like the options
	 * they hold identifiers, flags and rules, never credentials or row
	 * values.
	 *
	 * The decision is progress: progress_at moves to now (a job resumed
	 * days later must not be stalled by its first non-advancing tick) and
	 * the storage back-off and the late cron count start over.
	 *
	 * @param Job                  $job     Job (updated in place).
	 * @param array<string, mixed> $answers Answers keyed by question id.
	 * @return Job
	 * @throws InvalidTransition When the job is not waiting for an answer.
	 * @throws \InvalidArgumentException When the answers are not well-formed or carry a secret.
	 * @throws StaleJob When the job changed meanwhile.
	 */
	public function answer( Job $job, array $answers ): Job {
		global $wpdb;
		if ( ! $job->awaiting_answer() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
			throw new InvalidTransition( sprintf( 'Job %d is not waiting for an answer.', $job->id ) );
		}
		$answers            = self::validate_answers( $answers, $job->questions );
		$options            = $job->options;
		$previous           = isset( $options['answers'] ) && is_array( $options['answers'] ) ? $options['answers'] : array();
		$options['answers'] = array_replace( $previous, $answers );
		$json               = wp_json_encode( $options );
		if ( ! is_string( $json ) ) {
			throw new \InvalidArgumentException( 'Answers cannot be encoded.' );
		}
		$now = $this->now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table; the WHERE keeps a concurrent cancel or answer from being undone.
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET options_json = %s, questions_json = NULL, updated_at = %d, progress_at = %d, blocked_count = 0, cron_deferrals = 0 WHERE id = %d AND status = %s AND questions_json IS NOT NULL AND questions_json <> '' AND questions_json <> '[]'", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from the prefix and a constant.
				$json,
				$now,
				$now,
				$job->id,
				Job::PAUSED
			)
		);
		if ( 1 !== (int) $affected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
			throw new StaleJob( sprintf( 'Job %d changed while it was being answered.', $job->id ) );
		}
		$job->options        = $options;
		$job->questions      = array();
		$job->updated_at     = $now;
		$job->progress_at    = $now;
		$job->blocked_count  = 0;
		$job->cron_deferrals = 0;
		return $job;
	}

	/**
	 * The shape answers must have, see answer().
	 *
	 * @param array<mixed>                     $answers   Answers as given.
	 * @param array<int, array<string, mixed>> $questions The questions currently asked.
	 * @return array<string, string|int|bool>
	 * @throws \InvalidArgumentException When the shape is wrong.
	 */
	public static function validate_answers( array $answers, array $questions ): array {
		if ( array() === $answers ) {
			throw new \InvalidArgumentException( 'At least one answer is needed.' );
		}
		$asked = array();
		foreach ( $questions as $question ) {
			if ( is_array( $question ) && isset( $question['id'] ) && is_string( $question['id'] ) ) {
				$asked[ $question['id'] ] = isset( $question['choices'] ) && is_array( $question['choices'] ) ? $question['choices'] : null;
			}
		}
		$out = array();
		foreach ( $answers as $id => $value ) {
			if ( ! is_string( $id ) || ! array_key_exists( $id, $asked ) ) {
				throw new \InvalidArgumentException( sprintf( 'No question "%s" was asked.', (string) $id ) );
			}
			if ( is_string( $value ) ) {
				if ( strlen( $value ) > 256 ) {
					throw new \InvalidArgumentException( sprintf( 'The answer to "%s" is too long.', $id ) );
				}
			} elseif ( ! is_int( $value ) && ! is_bool( $value ) ) {
				throw new \InvalidArgumentException( sprintf( 'The answer to "%s" must be a string, an integer or a boolean.', $id ) );
			}
			if ( null !== $asked[ $id ] && ! in_array( $value, $asked[ $id ], true ) ) {
				throw new \InvalidArgumentException( sprintf( 'The answer to "%s" must be one of: %s.', $id, implode( ', ', array_map( 'strval', $asked[ $id ] ) ) ) );
			}
			$out[ $id ] = $value;
		}
		self::assert_cursor_has_no_secrets( $out );
		$json = wp_json_encode( $out );
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_ANSWERS_BYTES ) {
			throw new \InvalidArgumentException( sprintf( 'Answers must encode to at most %d bytes.', self::MAX_ANSWERS_BYTES ) );
		}
		return $out;
	}

	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Change the status, guarded by the status the caller saw.
	 *
	 * Leaving "running" for completed, failed or paused is the lock holder's
	 * decision and requires its token (the fence against a driver whose
	 * lease was taken over). Every other move needs no token: cancelling is
	 * an administrator's action on any status, queued and paused jobs are
	 * not locked, and a retry starts from failed. The reaper and the storage
	 * settlement use force_transition() instead.
	 *
	 * Job::transition() on a copy validates first and throws InvalidTransition
	 * when the state machine forbids the move.
	 *
	 * @param Job    $job   Job (updated in place on success).
	 * @param string $to    Target status.
	 * @param string $error Error message for failed (redacted before storing).
	 * @param string $token Lock token; required when leaving running for anything but cancelled.
	 * @param string $failure Kind of failure for a failed job (Job::stamp_failure(), or '' when the cause does not say).
	 * @return Job
	 * @throws InvalidTransition When the state machine forbids the move or the token is missing.
	 * @throws StaleJob When the row no longer has the expected status (or the lock changed hands).
	 */
	public function transition( Job $job, string $to, string $error = '', string $token = '', string $failure = '' ): Job {
		if ( '' === $token && Job::RUNNING === $job->status && Job::CANCELLED !== $to ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
			throw new InvalidTransition( sprintf( 'Job %d: leaving running for %s requires the lock token.', $job->id, $to ) );
		}
		if ( Job::QUEUED === $to && Job::FAILED === $job->status && ! $job->can_retry() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
			throw new InvalidTransition( sprintf( 'Job %d: its work files passed their retention period and were reclaimed; it cannot be retried.', $job->id ) );
		}
		return $this->write_transition( $job, $to, $error, $token, array(), array(), $failure );
	}

	/**
	 * Fail every non-terminal job bound to a directory other than the current
	 * one, once the storage situation is settled (no pending clone notice).
	 *
	 * @return int Number of jobs failed.
	 */
	public function settle_storage(): int {
		$base = $this->directories->base();
		if ( '' === $base ) {
			return 0;
		}
		$state = $this->directories->state();
		if ( ! empty( $state['clone_detected'] ) ) {
			return 0;
		}
		$token  = (string) $state['token'];
		$failed = 0;
		foreach ( $this->list_jobs( array( Job::QUEUED, Job::RUNNING, Job::PAUSED ), 500 ) as $job ) {
			// The same token at another location: failed only when that location is positively gone (its
			// parent lists and holds no such entry); otherwise the gate refuses it and says why.
			if ( $job->storage_token === $token && ( '' === $job->storage_path || Paths::same_location( $job->storage_path, $base ) || ! self::positively_gone( $job->storage_path ) ) ) {
				continue;
			}
			try {
				// No kind: the change can be undone (the setting reverted), and the work files are intact.
				$this->force_transition( $job, Job::FAILED, __( 'The storage directory changed; the job cannot continue.', 'wp-checkpoint' ) );
				++$failed;
			} catch ( StaleJob $e ) {
				continue;
			}
		}
		return $failed;
	}

	/**
	 * Housekeeping that must not wait for cron: orphaned lock files, stalled
	 * jobs, storage settlement, then the residue catalogue.
	 *
	 * The call to settle_storage() runs before reap_residue(): a copied database
	 * carries rows that look queued or running while their files belong to
	 * the original site. The orphan rule judges those work directories by
	 * their storage token alone, so the order is not needed for that; it is
	 * kept so the row is failed in the same pass in which its files go.
	 *
	 * A job lookup that fails (not "no row") aborts the deletions of the
	 * pass, see find_for_reclaim().
	 *
	 * @return void
	 */
	public function reap(): void {
		$now  = $this->now();
		$base = $this->directories->base();

		if ( '' !== $base && is_dir( Residue::tmp( $base ) ) ) {
			$files = glob( Residue::tmp( $base ) . DIRECTORY_SEPARATOR . LockFile::PREFIX . '*' . LockFile::SUFFIX );
			foreach ( is_array( $files ) ? $files : array() as $file ) {
				// The lock file lives as long as the job is queued, running or paused;
				// only a file without such a job is an orphan (crash before the row was
				// written, table recreated, foreign copy).
				$id = LockFile::job_id_from_path( $file );
				try {
					$job = $id > 0 ? $this->find_for_reclaim( $id ) : null;
				} catch ( ReclaimUnsafe $e ) {
					$this->directories->log_event( 'Reaping lock files: ' . $e->getMessage() );
					break;
				}
				if ( null === $job || ! in_array( $job->status, array( Job::QUEUED, Job::RUNNING, Job::PAUSED ), true ) ) {
					@unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- plugin-owned orphaned file.
				}
			}
		}

		foreach ( $this->list_jobs( array( Job::QUEUED, Job::RUNNING ), 500 ) as $job ) {
			$last = max( $job->progress_at, $job->created_at );
			if ( $job->is_locked( $now ) || $last + self::STALL_SECONDS > $now ) {
				continue;
			}
			$message = 0 === $job->started_at
				? __( 'Queued for 24 hours without starting; the job was given up.', 'wp-checkpoint' )
				: __( 'No progress for 24 hours; the job was given up.', 'wp-checkpoint' );
			try {
				// Nothing drove it (no visits, no cron): not a problem of the server; the message says what happened.
				$this->force_transition( $job, Job::FAILED, $message );
			} catch ( StaleJob $e ) {
				continue;
			}
		}

		// A job nobody answered holds its work directory; after the retention period it is given up and
		// its work reclaimed at once (the same rule as a failed job's files, on the same clock).
		foreach ( $this->list_jobs( array( Job::PAUSED ), 500 ) as $job ) {
			if ( ! $job->awaiting_answer() || $job->updated_at + self::WORK_RETENTION_SECONDS > $now ) {
				continue;
			}
			try {
				$this->force_transition( $job, Job::FAILED, __( 'No answer within 7 days; the job was given up.', 'wp-checkpoint' ), Job::FAILURE_FINAL );
			} catch ( StaleJob $e ) {
				continue;
			}
			// Failed first, then expired: a retry that wins the window between the two keeps its files
			// (expire_now() refuses once the status is queued) and asks again, the same "row is the
			// authority" guard as expire_work(). The questions stay on the failed row; acquire() clears them.
			$this->expire_now( $job );
		}

		$this->settle_storage();
		$this->reap_residue();
	}

	/**
	 * Mark a failed job's work as expired and reclaim it now.
	 *
	 * @param Job $job Failed job.
	 * @return void
	 */
	private function expire_now( Job $job ): void {
		global $wpdb;
		$now = $this->now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table; the WHERE keeps a concurrent retry from being undone.
		$affected = $wpdb->update(
			self::table(),
			array( 'work_expired_at' => $now ),
			array(
				'id'              => $job->id,
				'status'          => Job::FAILED,
				'work_expired_at' => 0,
			),
			array( '%d' ),
			array( '%d', '%s', '%d' )
		);
		if ( 1 === (int) $affected ) {
			$job->work_expired_at = $now;
			$this->reclaim_work( $job );
		}
	}

	/**
	 * Remove orphaned work directories, temporary tables, verification
	 * directories and stray files in the current storage directory, at
	 * most RECLAIM_MAX_ENTRIES entries per pass (the rest next time). A job
	 * that is bound to another storage directory never owned the work
	 * directory of its id here; the files are an orphan of a copied
	 * database.
	 *
	 * @return void
	 */
	public function reap_residue(): void {
		$base = $this->directories->base();
		if ( '' === $base ) {
			return;
		}
		$now    = $this->now();
		$token  = (string) $this->directories->state()['token'];
		$budget = self::RECLAIM_MAX_ENTRIES;
		$owners = array();
		try {
			$this->reap_entries( $base, $now, $token, $budget, $owners );
		} catch ( ReclaimUnsafe $e ) {
			$this->directories->log_event( 'Reaping residue: ' . $e->getMessage() );
		}
	}

	/**
	 * The body of reap_residue(): directories first, then tables.
	 *
	 * @param string               $base   Storage base.
	 * @param int                  $now    Current time.
	 * @param string               $token  Storage token.
	 * @param int                  $budget Entries left for this pass.
	 * @param array<int, Job|null> $owners Lookup cache.
	 * @return void
	 * @throws ReclaimUnsafe When a job lookup failed.
	 */
	private function reap_entries( string $base, int $now, string $token, int $budget, array $owners ): void {
		foreach ( Residue::scan( $base ) as $entry ) {
			if ( $budget <= 0 ) {
				return;
			}
			if ( Residue::WORK_DIR === $entry['kind'] ) {
				if ( ! $this->is_work_orphan( $entry['id'], $token, $owners ) ) {
					continue;
				}
			} elseif ( ! Residue::is_expired( $entry, $now ) ) {
				continue;
			}
			$result  = Deleter::delete_tree( Residue::tmp( $base ), $entry['path'], $budget );
			$budget -= $result['deleted'] + count( $result['failed'] );
			$this->report_reclaim( $entry['kind'] . ' ' . ( $entry['id'] > 0 ? 'of job ' . $entry['id'] : basename( $entry['path'] ) ), $result );
		}
		$this->drop_tables_of(
			$token,
			0,
			function ( int $id ) use ( $token, &$owners ): bool {
				return $this->is_work_orphan( $id, $token, $owners );
			}
		);
	}

	/**
	 * Whether the work of a job id may be reclaimed: no such job, a
	 * completed or cancelled one, a failed one past its work retention, or
	 * one bound to another storage directory.
	 *
	 * @param int                  $id     Job id.
	 * @param string               $token  Current storage token.
	 * @param array<int, Job|null> $owners Lookup cache (updated).
	 * @return bool
	 */
	private function is_work_orphan( int $id, string $token, array &$owners ): bool {
		if ( ! array_key_exists( $id, $owners ) ) {
			$owners[ $id ] = $this->find_for_reclaim( $id );
		}
		$job = $owners[ $id ];
		if ( null === $job ) {
			return true;
		}
		if ( $job->storage_token !== $token ) {
			return true;
		}
		if ( in_array( $job->status, array( Job::COMPLETED, Job::CANCELLED ), true ) ) {
			return true;
		}
		return Job::FAILED === $job->status && $job->work_expired_at > 0;
	}

	/**
	 * A find() for a decision to delete something: "no such row" and "the
	 * query failed" both come back as null from $wpdb, and only the first
	 * makes an orphan. A failed query (connection lost, lock wait timeout,
	 * server gone away: routine on shared hosts) throws ReclaimUnsafe, and
	 * the caller skips every deletion of this pass rather than treating a
	 * running job's three-hour export as leftovers. The table side already
	 * fails closed (an empty listing drops nothing); this makes the
	 * directory side fail the same way.
	 *
	 * @param int $id Job id.
	 * @return Job|null
	 * @throws ReclaimUnsafe When the lookup itself failed.
	 */
	private function find_for_reclaim( int $id ) {
		global $wpdb;
		// find() answers null for a missing table too (see the class comment); for a deletion that is not
		// "no such job" either: the table may be about to be recreated, or the database is unreachable.
		if ( ! Schema::table_exists() ) {
			throw new ReclaimUnsafe( 'The job table is not there; nothing is reclaimed in this pass.' );
		}
		$job = $this->find( $id );
		if ( null === $job && '' !== (string) $wpdb->last_error ) {
			throw new ReclaimUnsafe( 'The job lookup failed; nothing is reclaimed in this pass.' );
		}
		return $job;
	}

	/**
	 * Reclaim the work directory and temporary tables of one job, inside
	 * the current storage directory only. Called by the runner after a
	 * cancelled job's steps ran their cleanup, and by the purge. Bounded:
	 * returns false while entries remain, which the next reap pass picks
	 * up as an orphan.
	 *
	 * @param Job $job Job.
	 * @return bool True when nothing of the job's work is left.
	 */
	public function reclaim_work( Job $job ): bool {
		$base = $this->files_base( $job );
		if ( '' === $base ) {
			$this->directories->log_event( sprintf( 'Job %d is bound to another storage directory; its work files were left alone.', $job->id ) );
			return false;
		}
		$token  = (string) $this->directories->state()['token'];
		$tables = $this->drop_tables_of( $token, $job->id );
		$dir    = Residue::work_dir( $base, $job->id );
		if ( ! is_dir( $dir ) ) {
			return $tables;
		}
		$result = Deleter::delete_tree( Residue::tmp( $base ), $dir, self::RECLAIM_MAX_ENTRIES );
		$this->report_reclaim( 'work directory of job ' . $job->id, $result );
		return $tables && ! $result['remaining'] && array() === $result['failed'];
	}

	/**
	 * Drop the temporary tables of a job (or, with id 0, every orphaned one
	 * the callback approves) and report what could not be dropped.
	 *
	 * @param string        $token   Storage token.
	 * @param int           $job_id  Job id, or 0 for all tables.
	 * @param callable|null $approve function( int $job_id ): bool, required with id 0.
	 * @return bool True when every table that had to go is gone.
	 */
	private function drop_tables_of( string $token, int $job_id, $approve = null ): bool {
		$tables = array();
		foreach ( $this->temp_tables( $token, $job_id ) as $name => $id ) {
			if ( 0 === $job_id && ( null === $approve || ! $approve( $id ) ) ) {
				continue;
			}
			$tables[] = $name;
		}
		if ( array() === $tables ) {
			return true;
		}
		// In an order their foreign keys allow (TempTableDropper), as many as one bounded call drops; a name outside
		// TempTables::is_safe_name() is reported as failed rather than skipped, so a mismatch between the
		// creating and the dropping side can never leave a table behind unnoticed.
		$result = TempTableDropper::drop( $tables, TempTables::owner_prefix( $token ) );
		$what   = 0 === $job_id ? 'orphaned temporary tables' : 'temporary tables of job ' . $job_id;
		$this->report_reclaim(
			$what,
			array(
				'deleted'   => count( $result['dropped'] ),
				'failed'    => $result['failed'],
				'remaining' => array() !== $result['remaining'],
			)
		);
		if ( '' !== $result['stopped'] ) {
			$this->directories->log_event( sprintf( 'Reclaiming the %1$s stopped: %2$s.', $what, $result['stopped'] ) );
		}
		foreach ( $result['kept'] as $table => $referrers ) {
			// Dropping it would leave another table's key pointing at nothing: it stays until that key is gone.
			$this->directories->log_event( sprintf( 'Reclaiming the %1$s: %2$s is kept; a foreign key of %3$s, which stays, references it.', $what, $table, implode( ', ', $referrers ) ) );
		}
		return array() === $result['failed'] && array() === $result['kept'] && array() === $result['remaining'];
	}

	/**
	 * Note failures and unfinished passes in the storage log (counts only,
	 * no paths); the entries stay where they are and the next pass tries
	 * again.
	 *
	 * @param string                                                 $what   Description.
	 * @param array{deleted: int, failed: string[], remaining: bool} $result Deleter result.
	 * @return void
	 */
	private function report_reclaim( string $what, array $result ): void {
		if ( array() !== $result['failed'] ) {
			$this->directories->log_event( sprintf( 'Reclaiming the %s: %d entries could not be deleted; they will be tried again.', $what, count( $result['failed'] ) ) );
		} elseif ( $result['remaining'] ) {
			$this->directories->log_event( sprintf( 'Reclaiming the %s: %d entries deleted, more remain for the next pass.', $what, $result['deleted'] ) );
		}
	}

	/**
	 * This installation's temporary tables (optionally one job's), as name => job id.
	 *
	 * @param string $token  Storage token.
	 * @param int    $job_id Job id, or 0 for all.
	 * @return array<string, int>
	 */
	private function temp_tables( string $token, int $job_id = 0 ): array {
		global $wpdb;
		try {
			$prefix = $job_id > 0 ? TempTables::job_prefix( $token, $job_id ) : TempTables::owner_prefix( $token );
		} catch ( \InvalidArgumentException $e ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table listing.
		$names = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
		$out   = array();
		foreach ( is_array( $names ) ? $names : array() as $name ) {
			$id = TempTables::job_id_of( $token, (string) $name );
			if ( $id > 0 && ( 0 === $job_id || $id === $job_id ) ) {
				$out[ (string) $name ] = $id;
			}
		}
		return $out;
	}

	/**
	 * Delete old finished jobs and their log files; keep the table bounded.
	 * Running, queued and paused jobs and their logs are never touched.
	 * Before that, failed jobs whose work files passed WORK_RETENTION_SECONDS
	 * have those files reclaimed: the row is marked first (the job can no
	 * longer be retried), then the files go, so a crash in between never
	 * leaves a retryable job with half its files.
	 *
	 * @return int Rows deleted.
	 */
	public function purge(): int {
		$this->expire_work();
		global $wpdb;
		$now     = $this->now();
		$table   = $wpdb->base_prefix . Schema::JOBS_TABLE;
		$victims = array();

		foreach ( self::RETENTION_SECONDS as $status => $seconds ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table name from the prefix.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s AND finished_at > 0 AND finished_at < %d", $status, $now - $seconds ), ARRAY_A );
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$victims[ (int) $row['id'] ] = self::hydrate( $row );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table name from the prefix.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$over  = $total - count( $victims ) - self::MAX_ROWS;
		if ( $over > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table name from the prefix.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status IN (%s, %s, %s) ORDER BY created_at ASC, id ASC LIMIT %d", Job::COMPLETED, Job::CANCELLED, Job::FAILED, $over + count( $victims ) ), ARRAY_A );
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				if ( count( $victims ) >= $total - self::MAX_ROWS ) {
					break;
				}
				$victims[ (int) $row['id'] ] = self::hydrate( $row );
			}
		}

		foreach ( $victims as $job ) {
			$this->delete_files_of( $job );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
			$wpdb->delete( $table, array( 'id' => $job->id ), array( '%d' ) );
		}
		return count( $victims );
	}

	/**
	 * Reclaim the work files of failed jobs older than WORK_RETENTION_SECONDS.
	 *
	 * @return int Jobs whose work was expired in this pass.
	 */
	public function expire_work(): int {
		global $wpdb;
		$now   = $this->now();
		$table = $wpdb->base_prefix . Schema::JOBS_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table name from the prefix.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s AND work_expired_at = 0 AND finished_at > 0 AND finished_at < %d LIMIT 100", Job::FAILED, $now - self::WORK_RETENTION_SECONDS ), ARRAY_A );
		$count = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$job = self::hydrate( $row );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table; the WHERE keeps a concurrent retry from being undone.
			$affected = $wpdb->update(
				$table,
				array( 'work_expired_at' => $now ),
				array(
					'id'              => $job->id,
					'status'          => Job::FAILED,
					'work_expired_at' => 0,
				),
				array( '%d' ),
				array( '%d', '%s', '%d' )
			);
			if ( 1 !== (int) $affected ) {
				continue;
			}
			$job->work_expired_at = $now;
			$this->reclaim_work( $job );
			++$count;
		}
		return $count;
	}

	/**
	 * Throttled reap() and purge(), safe to call on every plugin page load or tick.
	 *
	 * @return void
	 */
	public function maintenance(): void {
		if ( false === get_site_transient( 'wpcheckpoint_jobs_reaped' ) ) {
			set_site_transient( 'wpcheckpoint_jobs_reaped', 1, self::REAP_THROTTLE );
			$this->reap();
		}
		if ( false === get_site_transient( 'wpcheckpoint_jobs_purged' ) ) {
			set_site_transient( 'wpcheckpoint_jobs_purged', 1, self::PURGE_THROTTLE );
			$this->purge();
		}
	}

	/**
	 * Refuse cursors that carry credentials: cursors hold identifiers and
	 * positions only; credentials are read from the encrypted options. The
	 * same check guards the job options, the questions and the answers,
	 * which is why none of them may hold row values (a primary key or an
	 * option name can contain a piece of a secret; a rule such as a length
	 * threshold cannot).
	 *
	 * Every escaped form of a secret is checked (Redactor::forms()), because
	 * the cursor is compared in its JSON encoding where slashes, quotes and
	 * non-ASCII characters are escaped. DB_USER is left out: it is not a
	 * credential and is often a plain word that legitimately occurs in a
	 * cursor (a site or table name); Redactor still masks it in logs.
	 *
	 * @param array<mixed>  $cursor  Cursor, options, questions or answers.
	 * @param string[]|null $secrets Secrets to check (tests); the installation's when null.
	 * @return void
	 * @throws \InvalidArgumentException When a known secret appears in it.
	 */
	public static function assert_cursor_has_no_secrets( array $cursor, $secrets = null ): void {
		$json = wp_json_encode( $cursor );
		if ( ! is_string( $json ) ) {
			throw new \InvalidArgumentException( 'Cursor cannot be encoded.' );
		}
		$user = defined( 'DB_USER' ) && is_string( DB_USER ) ? DB_USER : '';
		foreach ( is_array( $secrets ) ? $secrets : Redactor::installation_secrets() as $secret ) {
			if ( ! is_string( $secret ) || strlen( $secret ) < self::SECRET_MIN_LENGTH || ( '' !== $user && $secret === $user ) ) {
				continue;
			}
			foreach ( Redactor::forms( $secret ) as $form ) {
				if ( strlen( $form ) >= self::SECRET_MIN_LENGTH && false !== strpos( $json, $form ) ) {
					throw new \InvalidArgumentException( 'Cursor must not contain credentials.' );
				}
			}
		}
	}

	/**
	 * Status change without a token, for the reaper (which only touches jobs
	 * whose lock expired) and the storage settlement (whose jobs are bound to
	 * another directory: a holder in another installation cannot pass the
	 * gate here, and a holder sharing this database is meant to be stopped).
	 *
	 * @param Job    $job   Job.
	 * @param string $to    Target status.
	 * @param string $error Error message for failed.
	 * @param string $failure Kind of failure for a failed job (Job::FAILURE_*).
	 * @return Job
	 */
	private function force_transition( Job $job, string $to, string $error, string $failure = '' ): Job {
		return $this->write_transition( $job, $to, $error, '', array(), array(), $failure );
	}

	/**
	 * The guarded UPDATE behind transition() and force_transition().
	 *
	 * @param Job                  $job           Job (updated in place on success).
	 * @param string               $to            Target status.
	 * @param string               $error         Error message for failed (redacted before storing).
	 * @param string               $token         Lock token to add to the guard, or empty.
	 * @param array<string, mixed> $extra         Columns to set in the same statement (a state change that spans
	 *                                            several fields is one write, never two).
	 * @param string[]             $extra_formats Their formats.
	 * @param string               $failure Kind of failure for a failed job (Job::stamp_failure(), or '').
	 * @return Job
	 * @throws StaleJob When the guarded UPDATE changed no row.
	 */
	private function write_transition( Job $job, string $to, string $error, string $token, array $extra = array(), array $extra_formats = array(), string $failure = '' ): Job {
		global $wpdb;
		$from = $job->status;
		$copy = clone $job;
		$copy->transition( $to ); // Validates.

		$now  = $this->now();
		$data = array(
			'status'     => $to,
			'updated_at' => $now,
		);
		if ( in_array( $to, array( Job::COMPLETED, Job::FAILED, Job::CANCELLED ), true ) ) {
			$data['finished_at'] = $now;
		}
		if ( in_array( $to, array( Job::COMPLETED, Job::FAILED, Job::CANCELLED, Job::PAUSED ), true ) ) {
			// Nobody works on the job any more; a paused job is acquired again after it resumes.
			$data['lock_token']   = '';
			$data['locked_until'] = 0;
		}
		if ( Job::FAILED === $to ) {
			$data['last_error'] = $this->redactor->redact( $error );
			// Stamped with the failure it belongs to (Job::read_failure_kind()): code that does not know the column
			// retries and fails the job again without touching it, and the old kind must not speak for the new failure.
			$data['failure_kind'] = Job::stamp_failure( $failure, $now );
		}
		if ( Job::QUEUED === $to ) {
			$data['failure_kind']   = '';
			$data['finished_at']    = 0;
			$data['takeovers']      = 0; // A retry starts its counts over.
			$data['takeover_mark']  = '';
			$data['cron_deferrals'] = 0;
		}
		if ( Job::RUNNING === $to && 0 === $job->started_at ) {
			$data['started_at'] = $now;
		}
		$formats = array_fill( 0, count( $data ), '%s' );
		foreach ( array_keys( $data ) as $i => $key ) {
			if ( in_array( $key, array( 'updated_at', 'finished_at', 'locked_until', 'started_at', 'takeovers', 'cron_deferrals' ), true ) ) {
				$formats[ $i ] = '%d';
			}
		}
		foreach ( array_values( $extra ) as $i => $value ) {
			$formats[] = isset( $extra_formats[ $i ] ) ? $extra_formats[ $i ] : '%s';
		}
		$data          = array_merge( $data, $extra );
		$where         = array(
			'id'     => $job->id,
			'status' => $from,
		);
		$where_formats = array( '%d', '%s' );
		if ( Job::QUEUED === $to && Job::FAILED === $from ) {
			// The in-memory can_retry() check races with expire_work(): the row is the authority.
			$where['work_expired_at'] = 0;
			$where_formats[]          = '%d';
		}
		if ( '' !== $token ) {
			$where['lock_token'] = $token;
			$where_formats[]     = '%s';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table; the WHERE on status (and token) is the guard.
		$affected = $wpdb->update( self::table(), $data, $where, $formats, $where_formats );
		if ( 1 !== (int) $affected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
			throw new StaleJob( sprintf( 'Job %d is no longer %s.', $job->id, $from ) );
		}
		foreach ( $data as $key => $value ) {
			if ( property_exists( $job, $key ) ) {
				$job->$key = $value;
			}
		}
		// The object answers like a row read back: the kind, not the stamped column value.
		$stored              = (string) $job->failure_kind;
		$job->failure_kind   = Job::read_failure_kind( $stored, (int) $job->finished_at );
		$job->failure_reason = Job::read_failure_reason( $stored, (int) $job->finished_at );
		if ( in_array( $to, array( Job::COMPLETED, Job::FAILED, Job::CANCELLED ), true ) ) {
			$this->remove_lock_file( $job );
		}
		return $job;
	}

	/**
	 * Whether the row is currently locked with this token. Used after an
	 * UPDATE that changed no row, because MySQL reports changed rows, not
	 * matched rows: two heartbeats within the same second are still ours.
	 *
	 * @param int    $id    Job id.
	 * @param string $token Lock token.
	 * @return bool
	 */
	private function holds_lock( int $id, string $token ): bool {
		global $wpdb;
		if ( '' === $token ) {
			return false;
		}
		$table = $wpdb->base_prefix . Schema::JOBS_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table name from the prefix.
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d AND lock_token = %s", $id, $token ) );
	}

	/**
	 * Whether the job's files live in the current storage directory. Files in
	 * any other directory are never touched: that directory belongs to
	 * another installation (copied database) or was abandoned.
	 *
	 * @param Job $job Job.
	 * @return bool
	 */
	public function owns_files_of( Job $job ): bool {
		return '' !== $this->files_base( $job );
	}

	/**
	 * Whether a path is positively gone: its parent directory can be listed and holds no entry of its name.
	 * A parent that cannot be listed (permissions, open_basedir, a storage error) is no evidence.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function positively_gone( string $path ): bool {
		$path    = rtrim( $path, '/\\' );
		$entries = @scandir( dirname( $path ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would put the path into the error log.
		return is_array( $entries ) && ! in_array( basename( $path ), $entries, true );
	}

	/**
	 * The directory to work in for a job's files: the current storage
	 * directory when the job's row names the same location, '' otherwise.
	 * Its files are then reached under the current directory, not under the
	 * row's spelling of it (which the check resolved, but a link could be
	 * changed after).
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	public function files_base( Job $job ): string {
		$base = $this->directories->base();
		return '' !== $base && '' !== $job->storage_path && Paths::same_location( $job->storage_path, $base ) ? $base : '';
	}

	/**
	 * Write or refresh the lock file, inside the current directory only.
	 *
	 * @param Job    $job   Job.
	 * @param string $token Lock token.
	 * @param int    $until Lock expiry.
	 * @return void
	 */
	private function write_lock_file( Job $job, string $token, int $until ): void {
		$base = $this->files_base( $job );
		if ( '' === $base ) {
			$this->directories->log_event( sprintf( 'Job %d is bound to another storage directory; no lock file was written.', $job->id ) );
			return;
		}
		if ( ! LockFile::write( $base, $job->id, $token, $until ) ) {
			$this->directories->log_event( sprintf( 'The lock file of job %d could not be written; directory take-over checks cannot see this job.', $job->id ) );
		}
	}

	/**
	 * Remove the lock file, inside the current directory only.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	private function remove_lock_file( Job $job ): void {
		$base = $this->files_base( $job );
		if ( '' === $base ) {
			$this->directories->log_event( sprintf( 'Job %d is bound to another storage directory; its files were left alone.', $job->id ) );
			return;
		}
		LockFile::remove( $base, $job->id );
	}

	/**
	 * Delete the log, lock file, work directory and temporary tables of a
	 * job, confined to the current storage directory.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	private function delete_files_of( Job $job ): void {
		$base = $this->files_base( $job );
		if ( '' === $base ) {
			$this->directories->log_event( sprintf( 'Job %d is bound to another storage directory; its files were left alone.', $job->id ) );
			return;
		}
		if ( ! is_dir( $base ) ) {
			return;
		}
		$this->reclaim_work( $job );
		LockFile::remove( $base, $job->id );
		if ( '' !== $job->log_path && 0 === strpos( $job->log_path, 'logs/' ) ) {
			$file = $base . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $job->log_path );
			if ( is_file( $file ) ) {
				Deleter::delete_tree( $base . DIRECTORY_SEPARATOR . 'logs', $file );
			}
		}
	}

	/**
	 * Shape a gate verdict.
	 *
	 * @param bool   $allowed     Allowed.
	 * @param string $reason      Reason key.
	 * @param string $message     User message.
	 * @param int    $retry_after Seconds to wait.
	 * @return array{allowed: bool, reason: string, message: string, retry_after: int}
	 */
	private static function verdict( bool $allowed, string $reason, string $message, int $retry_after ): array {
		return array(
			'allowed'     => $allowed,
			'reason'      => $reason,
			'message'     => $message,
			'retry_after' => $retry_after,
		);
	}

	/**
	 * Row to object.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return Job
	 */
	private static function hydrate( array $row ): Job {
		$job                  = new Job();
		$job->missing_columns = array_values( array_diff( array_keys( Schema::COLUMNS ), array_keys( $row ) ) );
		foreach ( $row as $key => $value ) {
			if ( 'cursor_json' === $key || 'options_json' === $key || 'questions_json' === $key ) {
				$decoded          = is_string( $value ) ? json_decode( $value, true ) : null;
				$property         = substr( $key, 0, -5 );
				$job->{$property} = is_array( $decoded ) ? ( 'questions' === $property ? array_values( $decoded ) : $decoded ) : array();
				continue;
			}
			if ( ! property_exists( $job, $key ) ) {
				continue;
			}
			if ( is_int( $job->$key ) ) {
				$job->$key = (int) $value;
			} else {
				$job->$key = (string) $value;
			}
		}
		$stored              = $job->failure_kind;
		$job->failure_kind   = Job::read_failure_kind( $stored, $job->finished_at );
		$job->failure_reason = Job::read_failure_reason( $stored, $job->finished_at );
		return $job;
	}
}
