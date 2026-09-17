<?php
/**
 * Persistence, locking and housekeeping for jobs.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * All database access for the jobs table.
 *
 * Locking: one UPDATE with a "not locked or expired" condition is the
 * compare-and-set between drivers (browser polling, loopback, WP-CLI);
 * heartbeat and release are guarded by the token. The lock file in the
 * storage tmp/ directory mirrors the database lock for observers that
 * cannot read this database.
 *
 * Storage gate: a job is bound to the storage directory it was created for
 * (token + path). Ticks are refused while the directory is unusable or has
 * changed (clone detected); consecutive refusals back off 5 s, 15 s, 60 s,
 * 5 min. Once the storage situation is settled, jobs bound to another
 * directory are failed.
 */
final class JobRepository {

	const LOCK_SECONDS      = 120;
	const BACKOFF_SECONDS   = array( 5, 15, 60, 300 );
	const STALL_SECONDS     = 86400;
	const LOCK_FILE_GRACE   = 600;
	const RETENTION_SECONDS = array(
		Job::COMPLETED => 2592000, // 30 days
		Job::CANCELLED => 2592000,
		Job::FAILED    => 7776000, // 90 days
	);
	const MAX_ROWS          = 1000;
	const REAP_THROTTLE     = 600;
	const PURGE_THROTTLE    = 86400;
	const SECRET_MIN_LENGTH = 8;

	/**
	 * Storage directories.
	 *
	 * @var Directories
	 */
	private $directories;

	/**
	 * Time source (tests inject one).
	 *
	 * @var callable
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
		$this->clock       = is_callable( $clock ) ? $clock : 'time';
	}

	/**
	 * Current time.
	 *
	 * @return int
	 */
	public function now(): int {
		return (int) call_user_func( $this->clock );
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
	 * @return Job
	 * @throws JobsUnavailable When jobs cannot be created right now.
	 */
	public function create( string $type, int $owner_user = 0, array $cursor = array() ): Job {
		global $wpdb;

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

		$state = $this->directories->state();
		$now   = $this->now();
		$row   = array(
			'site_id'       => get_current_blog_id(),
			'type'          => $type,
			'status'        => Job::QUEUED,
			'cursor_json'   => wp_json_encode( $cursor ),
			'storage_token' => (string) $state['token'],
			'storage_path'  => $base,
			'owner_user'    => $owner_user,
			'created_at'    => $now,
			'updated_at'    => $now,
			'progress_at'   => $now,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$wpdb->insert( self::table(), $row, array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' ) );
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
	 * Load a job.
	 *
	 * @param int $id Job id.
	 * @return Job|null
	 */
	public function find( int $id ) {
		global $wpdb;
		$table = self::table();
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
		$table    = self::table();
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
		$table  = self::table();
		$counts = array_fill_keys( Job::statuses(), 0 );
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
		$retry = self::BACKOFF_SECONDS[ min( $job->blocked_count, count( self::BACKOFF_SECONDS ) - 1 ) ];
		if ( ! Schema::is_compatible() ) {
			return self::verdict( false, 'schema', __( 'The database structure was created by a newer version of WP Checkpoint. Please update the plugin.', 'wp-checkpoint' ), $retry );
		}
		$base = $this->directories->base();
		if ( '' === $base ) {
			return self::verdict( false, 'storage_unavailable', $this->directories->last_error(), $retry );
		}
		$state = $this->directories->state();
		if ( (string) $state['token'] !== $job->storage_token ) {
			$message = ! empty( $state['clone_detected'] )
				? __( 'The storage directory changed: resolve the clone notice (continue with the original directory or keep the new one) before this job can continue.', 'wp-checkpoint' )
				: __( 'The storage directory changed; this job cannot continue.', 'wp-checkpoint' );
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
	 * Try to take the lock. Only queued and running jobs can be acquired;
	 * a queued job becomes running.
	 *
	 * @param int $id    Job id.
	 * @param int $lease Lock duration in seconds.
	 * @return array{job: Job, token: string}|null Null when another driver holds the lock or the job is not runnable.
	 */
	public function acquire( int $id, int $lease = self::LOCK_SECONDS ) {
		global $wpdb;
		$job = $this->find( $id );
		if ( null === $job || ! in_array( $job->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
			return null;
		}
		$now   = $this->now();
		$token = bin2hex( random_bytes( 16 ) );
		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table name from the prefix; the WHERE clause is the compare-and-set.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET attempts = IF(status = %s, attempts + 1, attempts), started_at = IF(started_at = 0, %d, started_at), status = %s, lock_token = %s, locked_until = %d, updated_at = %d WHERE id = %d AND status IN (%s, %s) AND (lock_token = '' OR locked_until < %d)",
				Job::QUEUED,
				$now,
				Job::RUNNING,
				$token,
				$now + $lease,
				$now,
				$id,
				Job::QUEUED,
				Job::RUNNING,
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
		LockFile::write( $job->storage_path, $job->id, $token, $job->locked_until );
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
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table name from the prefix.
		$affected = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET locked_until = %d, updated_at = %d WHERE id = %d AND lock_token = %s", $until, $this->now(), $job->id, $token ) );
		if ( 1 !== (int) $affected ) {
			return false;
		}
		$job->locked_until = $until;
		LockFile::write( $job->storage_path, $job->id, $token, $until );
		return true;
	}

	/**
	 * Give the lock back.
	 *
	 * @param Job    $job   Job.
	 * @param string $token Lock token.
	 * @return bool False when the lock was not ours.
	 */
	public function release( Job $job, string $token ): bool {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table name from the prefix.
		$affected = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET lock_token = '', locked_until = 0, updated_at = %d WHERE id = %d AND lock_token = %s", $this->now(), $job->id, $token ) );
		if ( 1 !== (int) $affected ) {
			return false;
		}
		$job->lock_token   = '';
		$job->locked_until = 0;
		if ( LockFile::is_owned_by( LockFile::path( $job->storage_path, $job->id ), $token ) ) {
			LockFile::remove( $job->storage_path, $job->id );
		}
		return true;
	}

	/**
	 * Save the position of a running job. Resets the back-off counter.
	 *
	 * @param Job                  $job      Job.
	 * @param string               $step     Current step id.
	 * @param array<string, mixed> $cursor   Cursor (identifiers and offsets only).
	 * @param int                  $progress Percentage.
	 * @param string               $message  Progress text.
	 * @return void
	 */
	public function save_progress( Job $job, string $step, array $cursor, int $progress, string $message = '' ): void {
		global $wpdb;
		self::assert_cursor_has_no_secrets( $cursor );
		$now                   = $this->now();
		$job->step             = $step;
		$job->cursor           = $cursor;
		$job->progress         = max( 0, min( 100, $progress ) );
		$job->progress_message = $message;
		$job->progress_at      = $now;
		$job->updated_at       = $now;
		$job->blocked_count    = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$wpdb->update(
			self::table(),
			array(
				'step'             => $step,
				'cursor_json'      => wp_json_encode( $cursor ),
				'progress'         => $job->progress,
				'progress_message' => $message,
				'progress_at'      => $now,
				'updated_at'       => $now,
				'blocked_count'    => 0,
			),
			array( 'id' => $job->id ),
			array( '%s', '%s', '%d', '%s', '%d', '%d', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Change the status, guarded by the status the caller saw.
	 *
	 * @param Job    $job   Job (updated in place on success).
	 * @param string $to    Target status.
	 * @param string $error Error message for failed (redacted before storing).
	 * Job::transition() on a copy validates first and throws InvalidTransition
	 * when the state machine forbids the move.
	 *
	 * @return Job
	 * @throws StaleJob When the row no longer has the expected status.
	 */
	public function transition( Job $job, string $to, string $error = '' ): Job {
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
			$data['finished_at']  = $now;
			$data['lock_token']   = '';
			$data['locked_until'] = 0;
		}
		if ( Job::FAILED === $to ) {
			$data['last_error'] = $this->redactor->redact( $error );
		}
		if ( Job::QUEUED === $to ) {
			$data['finished_at'] = 0;
		}
		if ( Job::RUNNING === $to && 0 === $job->started_at ) {
			$data['started_at'] = $now;
		}
		$formats = array_fill( 0, count( $data ), '%s' );
		foreach ( array_keys( $data ) as $i => $key ) {
			if ( in_array( $key, array( 'updated_at', 'finished_at', 'locked_until', 'started_at' ), true ) ) {
				$formats[ $i ] = '%d';
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table; the WHERE on status is the guard.
		$affected = $wpdb->update(
			self::table(),
			$data,
			array(
				'id'     => $job->id,
				'status' => $from,
			),
			$formats,
			array( '%d', '%s' )
		);
		if ( 1 !== (int) $affected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
			throw new StaleJob( sprintf( 'Job %d is no longer %s.', $job->id, $from ) );
		}
		foreach ( $data as $key => $value ) {
			$job->$key = $value;
		}
		if ( in_array( $to, array( Job::COMPLETED, Job::FAILED, Job::CANCELLED ), true ) ) {
			LockFile::remove( $job->storage_path, $job->id );
		}
		return $job;
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
			if ( $job->storage_token === $token ) {
				continue;
			}
			try {
				$this->transition( $job, Job::FAILED, __( 'The storage directory changed; the job cannot continue.', 'wp-checkpoint' ) );
				++$failed;
			} catch ( StaleJob $e ) {
				continue;
			}
		}
		return $failed;
	}

	/**
	 * Housekeeping that must not wait for cron: stale lock files, stalled
	 * jobs, storage settlement.
	 *
	 * @return void
	 */
	public function reap(): void {
		$now  = $this->now();
		$base = $this->directories->base();

		if ( '' !== $base && is_dir( $base . DIRECTORY_SEPARATOR . 'tmp' ) ) {
			$files = glob( $base . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . LockFile::PREFIX . '*' . LockFile::SUFFIX );
			foreach ( is_array( $files ) ? $files : array() as $file ) {
				$id   = LockFile::job_id_from_path( $file );
				$job  = $id > 0 ? $this->find( $id ) : null;
				$dead = null === $job || ! in_array( $job->status, array( Job::QUEUED, Job::RUNNING, Job::PAUSED ), true );
				if ( $dead || ( ! $job->is_locked( $now ) && LockFile::is_stale( $file, $now, self::LOCK_FILE_GRACE ) ) ) {
					@unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- plugin-owned stale file.
				}
			}
		}

		foreach ( $this->list_jobs( array( Job::QUEUED, Job::RUNNING ), 500 ) as $job ) {
			$last = max( $job->progress_at, $job->created_at );
			if ( $job->is_locked( $now ) || $last + self::STALL_SECONDS > $now ) {
				continue;
			}
			try {
				$this->transition( $job, Job::FAILED, __( 'No progress for 24 hours; the job was given up.', 'wp-checkpoint' ) );
			} catch ( StaleJob $e ) {
				continue;
			}
		}

		$this->settle_storage();
	}

	/**
	 * Delete old finished jobs and their log files; keep the table bounded.
	 * Running, queued and paused jobs and their logs are never touched.
	 *
	 * @return int Rows deleted.
	 */
	public function purge(): int {
		global $wpdb;
		$now     = $this->now();
		$table   = self::table();
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
	 * positions only; credentials are read from the encrypted options.
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @return void
	 * @throws \InvalidArgumentException When a known secret appears in it.
	 */
	public static function assert_cursor_has_no_secrets( array $cursor ): void {
		$json = wp_json_encode( $cursor );
		if ( ! is_string( $json ) ) {
			throw new \InvalidArgumentException( 'Cursor cannot be encoded.' );
		}
		foreach ( Redactor::installation_secrets() as $secret ) {
			if ( strlen( $secret ) >= self::SECRET_MIN_LENGTH && false !== strpos( $json, $secret ) ) {
				throw new \InvalidArgumentException( 'Cursor must not contain credentials.' );
			}
		}
	}

	/**
	 * Delete the log and lock file of a job, confined to its storage directory.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	private function delete_files_of( Job $job ): void {
		if ( '' === $job->storage_path || ! is_dir( $job->storage_path ) ) {
			return;
		}
		LockFile::remove( $job->storage_path, $job->id );
		if ( '' !== $job->log_path && 0 === strpos( $job->log_path, 'logs/' ) ) {
			$file = $job->storage_path . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $job->log_path );
			if ( is_file( $file ) ) {
				Deleter::delete_tree( $job->storage_path . DIRECTORY_SEPARATOR . 'logs', $file );
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
		$job = new Job();
		foreach ( $row as $key => $value ) {
			if ( 'cursor_json' === $key ) {
				$decoded     = is_string( $value ) ? json_decode( $value, true ) : null;
				$job->cursor = is_array( $decoded ) ? $decoded : array();
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
		return $job;
	}
}
