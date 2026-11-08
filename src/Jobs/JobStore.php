<?php
/**
 * The writes and the read of a job's row that a run makes, written once.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Support\Schema;
use WPCheckpoint\Support\Utf8;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal messages: the runner stores them through the redactor and the presenter cleans them before display.

/**
 * What a run does to its job's row: read it (find(), through the one hydration), take it (claim()), keep it
 * (heartbeat(), holds_lock()), write its position (save_progress(), save_record()), let it go (release()) and end or
 * move it (write_transition()). Each statement is written here once, as a template in wpdb::prepare()'s form or an
 * update in wpdb::update()'s; StoreExecutor sends it, through wpdb (JobRepository, in WordPress) or through a
 * connection made without WordPress (SessionExecutor). Nothing here calls WordPress: what depends on it is given to
 * the constructor (the encoder, the redactor, how a message is fitted to its column, the check that a cursor holds no
 * secret, whether the uninstall fence's table is there). What a run does around these writes (the lock file in the
 * storage directory, which tokens this installation holds) is the caller's.
 */
final class JobStore {

	/**
	 * Executor.
	 *
	 * @var StoreExecutor
	 */
	private $db;

	/**
	 * The jobs table.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * The uninstall fence's table.
	 *
	 * @var string
	 */
	private $fence;

	/**
	 * Returns the current time on the database's clock: function(): int.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Encodes a cursor or the options: function( mixed $value ): string|false.
	 *
	 * @var callable
	 */
	private $encode;

	/**
	 * Redacts an error: function( string $text ): string.
	 *
	 * @var callable
	 */
	private $redact;

	/**
	 * Fits a progress message to its column: function( string $text ): string.
	 *
	 * @var callable
	 */
	private $fit;

	/**
	 * Refuses a cursor or options that hold a secret: function( array $value ): void, throws.
	 *
	 * @var callable
	 */
	private $no_secrets;

	/**
	 * Whether the uninstall fence's table is positively not there: function(): bool.
	 *
	 * @var callable
	 */
	private $fence_absent;

	/**
	 * The time a write of a run under its lock stamps as wp_run_at: function(): int; 0 for a run without WordPress,
	 * whose writes leave the column as it is (Job::$wp_run_at).
	 *
	 * @var callable
	 */
	private $run_at;

	/**
	 * Constructor.
	 *
	 * @param StoreExecutor $db           Executor.
	 * @param string        $table        The jobs table.
	 * @param string        $fence        The uninstall fence's table (UninstallFence::name()).
	 * @param callable      $now          function(): int, the database's clock.
	 * @param callable      $encode       function( mixed ): string|false, the encoder of cursors and options.
	 * @param callable      $redact       function( string ): string.
	 * @param callable      $fit          function( string ): string, a progress message as its column takes it.
	 * @param callable      $no_secrets   function( array ): void, throws when a cursor holds a secret.
	 * @param callable      $fence_absent function(): bool.
	 * @param callable      $run_at       function(): int, the time a run's write stamps as wp_run_at (the database's
	 *                                    clock in WordPress), 0 to leave it (a run without WordPress).
	 */
	public function __construct( StoreExecutor $db, string $table, string $fence, callable $now, callable $encode, callable $redact, callable $fit, callable $no_secrets, callable $fence_absent, callable $run_at ) {
		$this->db           = $db;
		$this->table        = $table;
		$this->fence        = $fence;
		$this->now          = $now;
		$this->encode       = $encode;
		$this->redact       = $redact;
		$this->fit          = $fit;
		$this->no_secrets   = $no_secrets;
		$this->fence_absent = $fence_absent;
		$this->run_at       = $run_at;
	}

	/**
	 * The time this store's runs stamp as wp_run_at (0: they leave it).
	 *
	 * @return int
	 */
	private function run_at(): int {
		return max( 0, (int) call_user_func( $this->run_at ) );
	}

	/**
	 * A progress message cut to the 191 characters of its column (varchar(191)), at a word's end: a path, a host or a
	 * secret cut in two would no longer be recognised by the masks the text passes through on its way out, and its
	 * first part would show. What the column's character set cannot store is the caller's (wpdb strips it in
	 * WordPress: JobRepository::fit_message()).
	 *
	 * @param string $message Message.
	 * @return string
	 */
	public static function cut_message( string $message ): string {
		$message = Utf8::scrub( $message );
		if ( mb_strlen( $message, 'UTF-8' ) > 191 ) {
			$cut     = preg_replace( '/\s+\S*\z/u', '', mb_substr( $message, 0, 192, 'UTF-8' ) );
			$message = is_string( $cut ) && mb_strlen( $cut, 'UTF-8' ) <= 191 ? rtrim( $cut ) : '';
		}
		return $message;
	}

	/**
	 * The jobs table.
	 *
	 * @return string
	 */
	public function table(): string {
		return $this->table;
	}

	/**
	 * Current time on the database's clock.
	 *
	 * @return int
	 */
	private function now(): int {
		return (int) call_user_func( $this->now );
	}

	/**
	 * Load a job.
	 *
	 * @param int $id Job id.
	 * @return Job|null Null when there is no such row, or it could not be read.
	 */
	public function find( int $id ) {
		$rows = $this->db->select( "SELECT * FROM {$this->table} WHERE id = %d", array( $id ) );
		return is_array( $rows ) && isset( $rows[0] ) && is_array( $rows[0] ) ? self::hydrate( $rows[0] ) : null;
	}

	/**
	 * A job from its row as read: the one hydration of a row, whichever executor read it.
	 *
	 * @param array<string, mixed> $row Column => value.
	 * @return Job
	 */
	public static function hydrate( array $row ): Job {
		$job                  = new Job();
		$job->missing_columns = array_values( array_diff( array_keys( Schema::COLUMNS ), array_keys( $row ) ) );
		foreach ( $row as $key => $value ) {
			if ( 'cursor_json' === $key || 'options_json' === $key || 'questions_json' === $key ) {
				$decoded  = is_string( $value ) && '' !== $value ? json_decode( $value, true ) : null;
				$property = substr( $key, 0, -5 );
				// Options and questions may be NULL (none given, none asked; a row of a version before them); a cursor is
				// always written ("[]" when empty). Anything else that is not an array is not what this code wrote.
				if ( ! is_array( $decoded ) && ( 'cursor_json' === $key || ( null !== $value && '' !== $value ) ) ) {
					$job->unreadable_columns[] = $key;
				}
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

	/**
	 * The statement by which a run takes a job: the compare-and-set of JobRepository::acquire() (see there), with the
	 * condition on who manages the job given by the caller (CLI: a token this installation holds; without WordPress:
	 * the token as read, and more). Everything else is the same statement for every caller.
	 *
	 * @param string $managing The condition on who manages the job, a template piece in wpdb::prepare()'s form.
	 * @return string
	 */
	public function claim_template( string $managing ): string {
		return "UPDATE {$this->table} SET takeovers = IF(lock_token <> '', IF(takeover_mark = MD5(CONCAT(step, '|', COALESCE(cursor_json, ''))), takeovers + 1, 1), takeovers), takeover_mark = IF(lock_token <> '', MD5(CONCAT(step, '|', COALESCE(cursor_json, ''))), takeover_mark), attempts = IF(status = %s, attempts + 1, attempts), started_at = IF(started_at = 0, %d, started_at), status = %s, lock_token = %s, locked_until = %d, updated_at = %d, wp_run_at = GREATEST(wp_run_at, %d), questions_json = NULL WHERE id = %d AND status IN (%s, %s, %s) AND (status <> %s OR questions_json IS NULL OR questions_json = '' OR questions_json = '[]') AND {$managing} AND (lock_token = '' OR locked_until < %d)";
	}

	/**
	 * Take a job: one compare-and-set (claim_template()).
	 *
	 * @param int               $id              Job id.
	 * @param string            $token           The run's lock token.
	 * @param int               $lease           Lock duration in seconds.
	 * @param string            $managing        The condition on who manages the job (claim_template()).
	 * @param array<int, mixed> $managing_params Its values.
	 * @return bool Whether this run took it.
	 */
	public function claim( int $id, string $token, int $lease, string $managing, array $managing_params ): bool {
		$now      = $this->now();
		$params   = array_merge(
			array( Job::QUEUED, $now, Job::RUNNING, $token, $now + $lease, $now, $this->run_at(), $id, Job::QUEUED, Job::RUNNING, Job::PAUSED, Job::PAUSED ),
			array_values( $managing_params ),
			array( $now )
		);
		$affected = $this->db->execute( $this->claim_template( $managing ), $params );
		return 1 === (int) $affected;
	}

	/**
	 * Whether a run's lock token is still the job's. Used after an UPDATE that changed no row, because MySQL reports
	 * changed rows, not matched rows: two heartbeats within the same second are still the run's.
	 *
	 * @param int    $id    Job id.
	 * @param string $token Lock token.
	 * @return bool
	 */
	public function holds_lock( int $id, string $token ): bool {
		if ( '' === $token ) {
			return false;
		}
		$rows = $this->db->select( "SELECT COUNT(*) AS held FROM {$this->table} WHERE id = %d AND lock_token = %s", array( $id, $token ) );
		return is_array( $rows ) && isset( $rows[0]['held'] ) && 1 === (int) $rows[0]['held'];
	}

	/**
	 * Extend the lease (JobRepository::heartbeat(), without the lock file).
	 *
	 * @param Job    $job   Job (its locked_until updated).
	 * @param string $token Lock token.
	 * @param int    $lease Lock duration in seconds.
	 * @return int|null The new end of the lease; null when the lock is no longer this run's.
	 */
	public function heartbeat( Job $job, string $token, int $lease ) {
		$until    = $this->now() + $lease;
		$affected = $this->db->execute( "UPDATE {$this->table} SET locked_until = %d, updated_at = %d, wp_run_at = GREATEST(wp_run_at, %d) WHERE id = %d AND lock_token = %s", array( $until, $this->now(), $this->run_at(), $job->id, $token ) );
		if ( 1 !== (int) $affected && ! $this->holds_lock( $job->id, $token ) ) {
			return null;
		}
		$job->locked_until = $until;
		$job->wp_run_at    = max( $job->wp_run_at, $this->run_at() );
		return $until;
	}

	/**
	 * Let the job go (JobRepository::release()).
	 *
	 * @param Job    $job   Job (updated).
	 * @param string $token Lock token.
	 * @return bool Whether this run still held it.
	 */
	public function release( Job $job, string $token ): bool {
		$affected = $this->db->execute( "UPDATE {$this->table} SET lock_token = '', locked_until = 0, updated_at = %d, wp_run_at = GREATEST(wp_run_at, %d) WHERE id = %d AND lock_token = %s", array( $this->now(), $this->run_at(), $job->id, $token ) );
		if ( 1 !== (int) $affected ) {
			return false;
		}
		$job->lock_token   = '';
		$job->locked_until = 0;
		$job->wp_run_at    = max( $job->wp_run_at, $this->run_at() );
		return true;
	}

	/**
	 * Record a fact of the run on the job's row (JobRepository::save_record()).
	 *
	 * @param Job    $job   Job (its options updated).
	 * @param string $token Lock token.
	 * @param string $key   The fact's name.
	 * @param mixed  $value A scalar or null.
	 * @return void
	 * @throws \InvalidArgumentException When the name or the value is not one a record takes.
	 * @throws \RuntimeException When the options cannot be encoded.
	 * @throws StaleJob When the lock is no longer held.
	 */
	public function save_record( Job $job, string $token, string $key, $value ): void {
		if ( 1 !== preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/', $key ) ) {
			throw new \InvalidArgumentException( 'A record is named with a-z, digits and "_".' );
		}
		if ( ! is_scalar( $value ) && null !== $value ) {
			throw new \InvalidArgumentException( 'A record holds a scalar.' );
		}
		$options = $job->options;
		if ( ! isset( $options['recorded'] ) || ! is_array( $options['recorded'] ) ) {
			$options['recorded'] = array();
		}
		$options['recorded'][ $key ] = $value;
		call_user_func( $this->no_secrets, $options );
		$json = call_user_func( $this->encode, $options );
		if ( false === $json ) {
			throw new \RuntimeException( sprintf( 'Job %d: its options cannot be written.', $job->id ) );
		}
		$affected = $this->db->execute( "UPDATE {$this->table} SET options_json = %s, updated_at = %d, wp_run_at = GREATEST(wp_run_at, %d) WHERE id = %d AND lock_token = %s", array( $json, $this->now(), $this->run_at(), $job->id, $token ) );
		if ( 1 !== (int) $affected && ! $this->holds_lock( $job->id, $token ) ) {
			throw new StaleJob( sprintf( 'Job %d: the lock is no longer held; the record was not written.', $job->id ) );
		}
		$job->options   = $options;
		$job->wp_run_at = max( $job->wp_run_at, $this->run_at() );
	}

	/**
	 * Persist a step's position (JobRepository::save_progress(), see there).
	 *
	 * @param Job                  $job        Job (updated).
	 * @param string               $token      Lock token.
	 * @param string               $step       Current step id.
	 * @param array<string, mixed> $cursor     Cursor.
	 * @param int                  $progress   0-100.
	 * @param string               $message    Progress text.
	 * @param bool                 $advanced   Whether the position moved.
	 * @param int|null             $site_state Job::SITE_*, or null to keep the stored one.
	 * @param string|null          $site_mark  The mark of the job's maintenance file, or null to keep the stored one.
	 * @return int The cancel request the write cleared when it recorded the swap as made (0 otherwise).
	 * @throws StaleJob When the lock is no longer held.
	 * @throws WriteRefused When the write was refused.
	 * @throws FenceClosed When the write would enter the site with the uninstall fence closed or missing.
	 * @throws RedoCancelled When a cancel was requested before a redo changed the site.
	 * @throws UndoCancelled When a cancel was requested before an undo changed the site.
	 */
	public function save_progress( Job $job, string $token, string $step, array $cursor, int $progress, string $message = '', bool $advanced = true, $site_state = null, $site_mark = null ): int {
		call_user_func( $this->no_secrets, $cursor );
		$now      = $this->now();
		$progress = max( 0, min( 100, $progress ) );
		$message  = (string) call_user_func( $this->fit, $message );
		$data     = array(
			'step'             => $step,
			'cursor_json'      => call_user_func( $this->encode, $cursor ),
			'progress'         => $progress,
			'progress_message' => $message,
			'updated_at'       => $now,
		);
		$formats  = array( '%s', '%s', '%d', '%s', '%d' );
		$run_at   = $this->run_at();
		if ( $run_at > 0 ) {
			// A run in WordPress: when it last wrote the job (a run without WordPress leaves the column as it is).
			$data['wp_run_at'] = max( $job->wp_run_at, $run_at ); // GREATEST(), as the other statements: never back.
			$formats[]         = '%d';
		}
		if ( $advanced ) {
			$data['progress_at']   = $now;
			$data['blocked_count'] = 0;
			$formats[]             = '%d';
			$formats[]             = '%d';
		}
		$pending = 0;
		if ( null !== $site_mark ) {
			// In this statement, with the cursor: a held file with this mark is never there before the row says it.
			$data['site_mark'] = (string) $site_mark;
			$formats[]         = '%s';
		}
		if ( null !== $site_state ) {
			$data['site_state'] = (int) $site_state;
			$formats[]          = '%d';
			if ( Job::SITE_SWAPPED === (int) $site_state && 0 === $job->committed_at ) {
				// When the swap was committed, in the statement that records it (an undo is measured from it); only
				// while none is recorded (the row as read under this run's lock is what $job says): a run that writes
				// the state again, or an undo given up before anything was put back (the site swapped all along), does
				// not move it.
				$data['committed_at'] = $now;
				$formats[]            = '%d';
			} elseif ( Job::SITE_UNTOUCHED === (int) $site_state && 0 !== $job->committed_at ) {
				// The swap is undone or rolled back: no swap of this job stands any more, and the next one is new.
				$data['committed_at'] = 0;
				$formats[]            = '%d';
			}
			if ( Job::SITE_SWAPPED === (int) $site_state ) {
				// The swap is complete: a cancel requested while it was under way can no longer act, and must not act
				// on whatever changes the site later (an undo). When the swap was just made, what is cleared is read
				// just before, for the caller to say so (a request that lands between this read and the write is
				// cleared without that note); written again while swapped (an undo given up before it changed the site,
				// as a cancel asked) it is cleared and nothing is said.
				if ( Job::SITE_SWAPPED !== $job->site_state ) {
					$pending = max( (int) $job->cancel_requested, $this->cancel_requested( $job->id ) );
				}
				$data['cancel_requested'] = 0;
				$formats[]                = '%d';
			}
		}
		$entering = null !== $site_state && Job::SITE_UNTOUCHED !== (int) $site_state && Job::SITE_UNTOUCHED === $job->site_state;
		$undoing  = null !== $site_state && Job::SITE_CHANGING === (int) $site_state && Job::SITE_SWAPPED === $job->site_state;
		// A redo starts changing the site only with no cancel requested (JobActions::cancel() gives it up until then):
		// the two meet on this row, and only one wins.
		$redoing = $entering && SwapStep::ID === $step && SwapStep::redo_at( $cursor ) > 0;
		if ( $entering ) {
			// The site is about to be changed: the job's row and the uninstall fence's row in one statement, on the
			// condition that the fence is open (UninstallFence: both sides write that row, so they cannot interleave).
			$affected = $this->db->execute( UninstallFence::entering_template( $this->table, $this->fence, array_keys( $data ), $formats, $redoing ), self::entering_params( $job->id, $token, $data, $now ) );
		} else {
			$where         = array(
				'id'         => $job->id,
				'lock_token' => $token,
			);
			$where_formats = array( '%d', '%s' );
			if ( $undoing ) {
				// An undo starts changing the site only with no cancel requested (JobActions::cancel() gives it up until
				// then): the two meet on this row, and only one wins.
				$where['cancel_requested'] = 0;
				$where_formats[]           = '%d';
			}
			$affected = $this->db->update( $this->table, $data, $formats, $where, $where_formats );
		}
		if ( null === $affected && $entering && (bool) call_user_func( $this->fence_absent ) ) {
			// The fence table is positively not there (dropped by an uninstall sharing this database, a schema not yet
			// upgraded): no swap enters without it.
			throw new FenceClosed( 'The uninstall fence is not there (WP Checkpoint was uninstalled on a site that shares this database, or its tables are not up to date): the restore does not start changing the site, and tries again later. Nothing was changed.' );
		}
		if ( null === $affected ) {
			// Refused (a lock wait, the server gone): nothing of this cursor is stored, and a step that goes on would
			// change what the row does not say (a site state above all). Stopped as a lost lock: no further writes.
			throw new WriteRefused( sprintf( 'Job %d: its progress could not be written.', $job->id ) );
		}
		if ( $entering && 0 === $affected ) {
			if ( ! $this->holds_lock( $job->id, $token ) ) {
				throw new StaleJob( sprintf( 'Job %d is no longer locked by this driver.', $job->id ) );
			}
			if ( $redoing && 0 !== $this->cancel_requested( $job->id ) ) {
				// Still this run's: the row held a cancel request, and nothing was written.
				throw new RedoCancelled( sprintf( 'Job %d: a cancel was requested before the redo changed the site.', $job->id ) );
			}
			throw new FenceClosed( 'WP Checkpoint is being uninstalled on this site or on one that shares its database (or such an uninstall did not finish): the restore does not start changing the site while it is, and tries again later. Nothing was changed.' );
		}
		if ( 1 !== $affected && ! $entering && ! $this->holds_lock( $job->id, $token ) ) {
			throw new StaleJob( sprintf( 'Job %d is no longer locked by this driver.', $job->id ) );
		}
		if ( $undoing && 0 === $affected ) {
			// Still this run's: the row held a cancel request, and nothing was written.
			throw new UndoCancelled( sprintf( 'Job %d: a cancel was requested before the undo changed the site.', $job->id ) );
		}
		$job->step             = $step;
		$job->cursor           = $cursor;
		$job->progress         = $progress;
		$job->progress_message = $message;
		$job->updated_at       = $now;
		if ( $run_at > 0 ) {
			$job->wp_run_at = (int) $data['wp_run_at'];
		}
		if ( $advanced ) {
			$job->progress_at   = $now;
			$job->blocked_count = 0;
		}
		if ( null !== $site_mark ) {
			$job->site_mark = (string) $site_mark;
		}
		if ( null !== $site_state ) {
			if ( isset( $data['committed_at'] ) ) {
				$job->committed_at = (int) $data['committed_at'];
			}
			$job->site_state = (int) $site_state;
			if ( Job::SITE_SWAPPED === $job->site_state ) {
				$job->cancel_requested = 0;
			}
		}
		return $pending;
	}

	/**
	 * The values of the statement by which a job enters the site (UninstallFence::entering_template()), in its order.
	 *
	 * @param int                  $job_id Job id.
	 * @param string               $token  Lock token.
	 * @param array<string, mixed> $data   Column => value, as save_progress() writes them.
	 * @param int                  $now    Unix time.
	 * @return array<int, mixed>
	 */
	public static function entering_params( int $job_id, string $token, array $data, int $now ): array {
		return array_merge( array( UninstallFence::ROW, UninstallFence::OPEN ), array_values( $data ), array( $now, $job_id, $token ) );
	}

	/**
	 * The row's cancel request as it is now.
	 *
	 * @param int $id Job id.
	 * @return int
	 */
	private function cancel_requested( int $id ): int {
		$rows = $this->db->select( "SELECT cancel_requested FROM {$this->table} WHERE id = %d", array( $id ) );
		return is_array( $rows ) && isset( $rows[0]['cancel_requested'] ) ? (int) $rows[0]['cancel_requested'] : 0;
	}

	/**
	 * Move a job to another status (JobRepository::transition(), see there): the checks, the step a retry starts at and
	 * the answers to forget, then the write (write_transition()); without the lock file, which the caller removes when
	 * the job ended.
	 *
	 * @param Job           $job         Job (updated in place on success).
	 * @param string        $to          Target status.
	 * @param string        $error       Error message for failed.
	 * @param string        $token       Lock token.
	 * @param string        $failure     Kind of failure for failed.
	 * @param string        $retry_from  For failed: the step a retry starts at.
	 * @param string[]      $forget      For failed: answers a retry asks again.
	 * @param int           $at          For undone: the time it was undone at.
	 * @param string        $retry_key   The cursor key that names the step a retry starts from.
	 * @param callable|null $retry_check function( Job ): void, before a retry (to queued from failed); throws when it may not.
	 * @return Job
	 * @throws InvalidTransition When the state machine forbids the move or the token is missing.
	 * @throws StaleJob When the row is no longer the job as read.
	 * @throws \RuntimeException When the cursor or the options cannot be encoded.
	 */
	public function transition( Job $job, string $to, string $error, string $token, string $failure, string $retry_from, array $forget, int $at, string $retry_key, $retry_check = null ): Job {
		if ( '' === $token && Job::RUNNING === $job->status && Job::CANCELLED !== $to ) {
			throw new InvalidTransition( sprintf( 'Job %d: leaving running for %s requires the lock token.', $job->id, $to ) );
		}
		if ( Job::UNDONE === $job->status ) {
			throw new InvalidTransition( sprintf( 'Job %d was undone: an undone restore is not run again.', $job->id ) );
		}
		if ( Job::QUEUED === $to && Job::FAILED === $job->status ) {
			if ( is_callable( $retry_check ) ) {
				call_user_func( $retry_check, $job );
			}
		}
		$answers = isset( $job->options['answers'] ) && is_array( $job->options['answers'] ) ? $job->options['answers'] : array();
		$forget  = array_values( array_intersect( array_map( 'strval', $forget ), array_map( 'strval', array_keys( $answers ) ) ) );
		if ( '' === $retry_from && array() === $forget ) {
			return $this->write_transition( $job, $to, $error, $token, array(), array(), $failure, $at, $retry_key );
		}
		if ( Job::FAILED !== $to ) {
			throw new InvalidTransition( sprintf( 'Job %d: only a failure names a step to retry from or an answer to forget.', $job->id ) );
		}
		$extra   = array();
		$cursor  = null;
		$options = null;
		if ( '' !== $retry_from ) {
			$cursor               = $job->cursor;
			$cursor[ $retry_key ] = $retry_from;
			call_user_func( $this->no_secrets, $cursor );
			$json = call_user_func( $this->encode, $cursor );
			if ( false === $json ) {
				throw new \RuntimeException( sprintf( 'Job %d: its cursor cannot be written.', $job->id ) );
			}
			$extra['cursor_json'] = $json;
		}
		if ( array() !== $forget ) {
			$options = $job->options;
			foreach ( $forget as $question ) {
				unset( $options['answers'][ $question ] );
			}
			if ( array() === $options['answers'] ) {
				unset( $options['answers'] );
			}
			$json = call_user_func( $this->encode, $options );
			if ( false === $json ) {
				throw new \RuntimeException( sprintf( 'Job %d: its options cannot be written.', $job->id ) );
			}
			$extra['options_json'] = $json;
		}
		$job = $this->write_transition( $job, $to, $error, $token, $extra, array_fill( 0, count( $extra ), '%s' ), $failure, 0, $retry_key );
		if ( null !== $cursor ) {
			$job->cursor = $cursor;
		}
		if ( null !== $options ) {
			$job->options = $options;
		}
		return $job;
	}

	/**
	 * Write a transition (JobRepository::write_transition(), see there), without the lock file: the caller removes it
	 * when the job ended.
	 *
	 * @param Job                  $job           Job (updated in place).
	 * @param string               $to            Target status.
	 * @param string               $error         Error message for failed (redacted here).
	 * @param string               $token         Lock token ('' for a forced write).
	 * @param array<string, mixed> $extra         Additional columns.
	 * @param string[]             $extra_formats Their formats.
	 * @param string               $failure       Job::FAILURE_* for failed.
	 * @param int                  $at            For undone: the time it was undone at, when it goes back to undone.
	 * @param string               $retry_key     The cursor key that names the step a retry starts from.
	 * @return Job
	 * @throws InvalidTransition When the transition is not allowed.
	 * @throws StaleJob When the row is no longer the job as read.
	 * @throws \RuntimeException When the options cannot be encoded.
	 */
	public function write_transition( Job $job, string $to, string $error, string $token, array $extra, array $extra_formats, string $failure, int $at, string $retry_key ): Job {
		$from = $job->status;
		$copy = clone $job;
		$copy->transition( $to ); // Validates.

		$now  = $this->now();
		$data = array(
			'status'     => $to,
			'updated_at' => $now,
		);
		if ( in_array( $to, array( Job::COMPLETED, Job::FAILED, Job::CANCELLED, Job::UNDONE ), true ) ) {
			$data['finished_at'] = $now;
		}
		if ( in_array( $to, array( Job::COMPLETED, Job::FAILED, Job::CANCELLED, Job::PAUSED, Job::UNDONE ), true ) ) {
			// Nobody works on the job any more; a paused job is acquired again after it resumes.
			$data['lock_token']   = '';
			$data['locked_until'] = 0;
		}
		if ( Job::FAILED === $to ) {
			$data['last_error'] = (string) call_user_func( $this->redact, $error );
			// Stamped with the failure it belongs to (Job::read_failure_kind()): code that does not know the column
			// retries and fails the job again without touching it, and the old kind must not speak for the new failure.
			$data['failure_kind'] = Job::stamp_failure( $failure, $now );
		}
		if ( Job::CANCELLED === $to && '' !== $error ) {
			// A cancel that says how it ended (a put-back's, Cancelled::said()): its progress message, not an error.
			$data['progress_message'] = (string) call_user_func( $this->fit, (string) call_user_func( $this->redact, $error ) );
		}
		if ( Job::UNDONE === $to ) {
			// Not a failure: what happened is said as the job's progress message, and no error or kind of failure stays.
			$data['progress_message'] = (string) call_user_func( $this->fit, (string) call_user_func( $this->redact, $error ) );
			$data['last_error']       = '';
			$data['failure_kind']     = '';
			if ( $at > 0 ) {
				// Back to undone as of its undo: the time its restored copy is kept, and a redo can be made in, stays.
				$data['finished_at'] = $at;
			}
		}
		$restart = null;
		if ( Job::QUEUED === $to ) {
			$data['failure_kind']   = '';
			$data['finished_at']    = 0;
			$data['takeovers']      = 0; // A retry starts its counts over.
			$data['takeover_mark']  = '';
			$data['cron_deferrals'] = 0;
			if ( Job::SITE_UNTOUCHED === $job->site_state ) {
				// A cancel requested of a failure that holds the site changed is kept: the retry puts it back, then
				// cancels. Of any other, it was for a run that is over.
				$data['cancel_requested'] = 0;
			}
			$named = Job::FAILED === $from && isset( $job->cursor[ $retry_key ] ) ? $job->cursor[ $retry_key ] : null;
			if ( is_string( $named ) && '' !== $named ) {
				// The failed step named where a retry starts (RetryFrom): that step, from its start, in this same write;
				// without the answers given so far, which were about what the steps found then (a step never asks
				// again once it has an answer).
				$options = $job->options;
				unset( $options['answers'] );
				$json = call_user_func( $this->encode, $options );
				if ( false === $json ) {
					throw new \RuntimeException( sprintf( 'Job %d: its options cannot be written.', $job->id ) );
				}
				$restart              = $options;
				$data['step']         = $named;
				$data['cursor_json']  = '[]';
				$data['options_json'] = $json;
			}
		}
		if ( Job::RUNNING === $to && 0 === $job->started_at ) {
			$data['started_at'] = $now;
		}
		$run_at = '' !== $token ? $this->run_at() : 0;
		if ( $run_at > 0 ) {
			// The write of a run in WordPress under its lock (a transition made outside a run, without the token, is not
			// one: Job::$wp_run_at).
			$data['wp_run_at'] = max( $job->wp_run_at, $run_at ); // GREATEST(), as the other statements: never back.
		}
		$formats = array_fill( 0, count( $data ), '%s' );
		foreach ( array_keys( $data ) as $i => $key ) {
			if ( in_array( $key, array( 'updated_at', 'finished_at', 'locked_until', 'started_at', 'takeovers', 'cron_deferrals', 'cancel_requested', 'wp_run_at' ), true ) ) {
				$formats[ $i ] = '%d';
			}
		}
		foreach ( array_values( $extra ) as $i => $value ) {
			$formats[] = isset( $extra_formats[ $i ] ) ? $extra_formats[ $i ] : '%s';
		}
		$data          = array_merge( $data, $extra );
		$guard         = array(
			'id'     => $job->id,
			'status' => $from,
		);
		$guard_formats = array( '%d', '%s' );
		if ( Job::QUEUED === $to && Job::FAILED === $from ) {
			// The in-memory can_retry() check races with expire_work(): the row is the authority. And the step a retry
			// starts at comes from the job as read: the row must still be that failure (not another retry's, run and
			// failed again meanwhile; a failure within the same second is not told apart).
			$guard['work_expired_at'] = 0;
			$guard['finished_at']     = (int) $job->finished_at;
			$guard_formats[]          = '%d';
			$guard_formats[]          = '%d';
		}
		if ( '' !== $token ) {
			$guard['lock_token'] = $token;
			$guard_formats[]     = '%s';
		}
		if ( Job::CANCELLED === $to || ( '' === $token && Job::FAILED === $to ) ) {
			// A job that holds the site changed is cancelled only by its own step once the site is back
			// (Cancelled), and failed only by the run that holds it: never from outside, and never by a cancel
			// that took an expired lock. The row is the authority: the run may have written the state just now.
			$guard['site_state'] = Job::SITE_UNTOUCHED;
			$guard_formats[]     = '%d';
		}
		$affected = $this->db->update( $this->table, $data, $formats, $guard, $guard_formats );
		if ( 1 !== (int) $affected ) {
			throw new StaleJob( sprintf( 'Job %d is no longer %s.', $job->id, $from ) );
		}
		foreach ( $data as $key => $value ) {
			if ( property_exists( $job, $key ) ) {
				$job->$key = $value;
			}
		}
		if ( null !== $restart ) {
			$job->cursor  = array();
			$job->options = $restart;
		}
		// The object answers like a row read back: the kind, not the stamped column value.
		$stored              = (string) $job->failure_kind;
		$job->failure_kind   = Job::read_failure_kind( $stored, (int) $job->finished_at );
		$job->failure_reason = Job::read_failure_reason( $stored, (int) $job->finished_at );
		return $job;
	}
}
