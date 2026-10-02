<?php
/**
 * The swap: the restored directories and tables take the site's place, or the site is put back as it was.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Database\SqlWriter;
use WPCheckpoint\Files\ScanRoots;
use WPCheckpoint\Restore\ImportSession;
use WPCheckpoint\Restore\Ledger;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Restore\Queries;
use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Restore\StagingLayout;
use WPCheckpoint\Restore\StateCarry;
use WPCheckpoint\Restore\SwapPlan;
use WPCheckpoint\Restore\SwapRules;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Standalone\Failure;
use WPCheckpoint\Support\HostFunctions;
use WPCheckpoint\Support\Paths;
use WPCheckpoint\Support\StoredNames;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- job errors with paths and table names; the presenter cleans them.
// phpcs:disable WordPress.WP.AlternativeFunctions -- renames of the site's directories and the staging roots.
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would put a path into the error log; failures are reported.

/**
 * Runs after the final check (SwapCheckStep), in WP-CLI only (CliOnly):
 * the swap must not be cut off by a web server's time limit, and while it
 * runs the site is changing under the requests that would drive it. It
 * reads the plan the check wrote (SwapPlan, the attempt in the work file
 * RestoreFiles::SWAP_PLAN) and nothing it decides comes from anywhere else.
 *
 * Before the site is changed (Job::SITE_UNTOUCHED): the temporary tables
 * without transactions are counted once more (TempTableCount; a mismatch
 * is FINAL), then the swap gets ready: no time limit for this process (or
 * it does not start), the site's directories still where the staging
 * layout has them (or a retry starts over at the final check), no
 * maintenance file of anyone else (or it waits).
 *
 * The swap (Job::SITE_CHANGING), in one tick and never stopped for the
 * budget: "enter" is recorded, the maintenance file (Restore\Maintenance)
 * put up; each directory unit in plan order, the live one moved to its old
 * path and the staged one into its place, each rename right after a lease
 * check and recorded; this plugin's own state carried into the restored
 * options (StateCarry, when the backup replaces them); then the tables, in
 * batches (SwapRules::batches()), each recorded before it is sent, the first
 * one only when this plugin stays active (StateCarry::guard()), each
 * retried after a lock wait of LOCK_WAIT seconds at most RETRIES times. The
 * maintenance file is refreshed between the steps. The swap is made when a
 * listing shows every table entry swapped (SwapRules::committed()): that is
 * recorded (Job::SITE_SWAPPED) and from then on the swap only goes forward.
 *
 * Anything that stops the swap before that, in the same tick (an exception)
 * or seen by the next one (a run that died: "enter", "dirs", "carry", or
 * "rename" without every table swapped), rolls it back: the tables, then the
 * directories, in reverse plan order, each by what is there and not by where
 * the cursor stopped (SwapRules::table_back(), dir_back()); what is found in
 * a place the old site's table or directory must go back to (someone made it
 * meanwhile) is moved out of the way and logged, never deleted; the
 * maintenance file is taken down; then the site is recorded as untouched
 * and the job fails with the reason, a retry starting over at the final
 * check, or is cancelled when that was asked for. What cannot be told (a
 * listing or an lstat that fails) is waited out (TransientFailure), never
 * guessed.
 *
 * After the swap is made: caches flushed, the restored rewrite rules
 * removed (WordPress builds them again), a mark left in the restored
 * options for the plugin to set its fallback events again on its next
 * request (Plugin), the maintenance file taken down: each a step of its
 * own, with plain SQL on the restored tables and no WordPress API but the
 * cache flush (the process still holds the old site's plugins).
 */
final class SwapStep implements Step, HoldsSite, CliOnly {

	const ID = 'restore_swap';

	/**
	 * Seconds a rename waits for a lock (the session's lock_wait_timeout), and the waits between tries.
	 */
	const LOCK_WAIT = 5;
	const RETRIES   = array( 1, 3, 6 );

	/**
	 * Names per listing statement, plan entries per read.
	 */
	const PAGE = 500;

	/**
	 * Why a swap was rolled back, as its cursor keeps it (a code: the cursor holds identifiers and positions, not
	 * texts with paths), and what the job's error says of it in a later tick.
	 */
	const INTERRUPTED = 'interrupted';
	const INCOMPLETE  = 'incomplete';
	const STOPPED     = 'stopped';
	const REASONS     = array(
		self::INTERRUPTED => 'The swap was interrupted before it was complete.',
		self::INCOMPLETE  => 'After the renames, not every table of the plan was in its place.',
		self::STOPPED     => 'The swap stopped (the job log says why).',
	);

	/**
	 * Phases in which the site is being changed (or put back), and in which it is swapped.
	 */
	const CHANGING = array( 'enter', 'dirs', 'carry', 'rename', 'rollback' );
	const SWAPPED  = array( 'committed', 'done' );

	/**
	 * Returns the connection: function(): Queries.
	 *
	 * @var callable
	 */
	private $connect;

	/**
	 * Injected parts (tests): "at" function( string $point ): void (a crash seam), "abspath" (the directory of the
	 * maintenance file), "sleep" function( int $seconds ): void, "packet" (max_allowed_packet in place of the
	 * server's), "now" function(): int, "flush" function(): void (in place of the cache flush), "plugin" (this
	 * plugin's file relative to the plugins directory), "rows" (rows counted per unit), "site_dirs" function():
	 * array (group => directory), "random" function(): string (hex for the names of what is moved out of the
	 * way), "limit" function(): string (in place of reading max_execution_time back), "batch" (bytes of a batch
	 * of renames, in place of SwapRules::limit()).
	 *
	 * @var array<string, mixed>
	 */
	private $parts;

	/**
	 * Constructor.
	 *
	 * @param callable|null        $connect function(): Queries (tests); the site's own settings by default.
	 * @param array<string, mixed> $parts   See $parts.
	 */
	public function __construct( $connect = null, array $parts = array() ) {
		$this->parts   = $parts;
		$this->connect = is_callable( $connect ) ? $connect : static function (): Queries {
			try {
				$credentials = Credentials::from_wordpress();
			} catch ( Failure $e ) {
				throw new \RuntimeException( 'The database settings of this site cannot be read: ' . $e->getMessage() );
			}
			return ImportSession::open( $credentials );
		};
	}

	/**
	 * Step id.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * The site state a cursor stands for.
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @return int|null
	 */
	public static function site_state( array $cursor ) {
		if ( ! isset( $cursor['phase'] ) ) {
			return null; // The empty cursor of a step that is done: the stored state stays.
		}
		if ( in_array( $cursor['phase'], self::CHANGING, true ) ) {
			return Job::SITE_CHANGING;
		}
		return in_array( $cursor['phase'], self::SWAPPED, true ) ? Job::SITE_SWAPPED : Job::SITE_UNTOUCHED;
	}

	/**
	 * Run.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws WorkLost When the temporary tables are not what the import left.
	 * @throws RetryFrom When the plan is not complete, or the site's directories moved (a retry checks again).
	 * @throws Cancelled When the swap was rolled back after a cancel was asked for.
	 */
	public function run( JobContext $context ): StepResult {
		$cursor = $context->cursor();
		$db     = call_user_func( $this->connect );
		try {
			$phase = (string) ( $cursor['phase'] ?? 'start' );
			if ( 'start' === $phase ) {
				$cursor = $this->start( $context, $db );
				$context->checkpoint( $cursor, 5, __( 'Counting the restored tables once more before the swap', 'wp-checkpoint' ) );
				$phase = 'recount';
			}
			if ( 'recount' === $phase ) {
				$cursor = $this->recount( $context, $db, $cursor );
				if ( 'recount' === $cursor['phase'] ) {
					return StepResult::progress( $cursor, 10, __( 'Counting the restored tables once more before the swap', 'wp-checkpoint' ) );
				}
				$context->checkpoint( $cursor, 15, __( 'Getting ready for the swap', 'wp-checkpoint' ) );
				$phase = 'ready';
			}
			if ( 'ready' === $phase ) {
				$waiting = $this->ready( $context, $db, $cursor );
				if ( null !== $waiting ) {
					return $waiting;
				}
				return $this->swap( $context, $db, $cursor );
			}
			if ( in_array( $phase, array( 'enter', 'dirs', 'carry' ), true ) ) {
				throw $this->roll_back( $context, $db, $cursor, self::INTERRUPTED );
			}
			if ( 'rename' === $phase ) {
				$entries = $this->entries( $context, $db, $cursor );
				if ( SwapRules::committed( $entries['tables'], $this->there( $db, $entries['tables'] ) ) ) {
					$this->release( $context, $this->maintenance( $cursor ) );
					$this->at( 'unheld_commit' );
					$cursor = array(
						'phase'   => 'committed',
						'attempt' => $cursor['attempt'],
						'mark'    => $cursor['mark'] ?? '',
						'post'    => 'cache',
					);
					$context->checkpoint( $cursor, 85, __( 'The restored site is in place', 'wp-checkpoint' ) );
					$this->at( 'committed' );
					return $this->after( $context, $db, $cursor );
				}
				throw $this->roll_back( $context, $db, $cursor, self::INTERRUPTED );
			}
			if ( 'rollback' === $phase ) {
				throw $this->roll_back( $context, $db, $cursor, (string) ( $cursor['reason'] ?? '' ) );
			}
			if ( 'reverted' === $phase ) {
				// Recorded as put back; the maintenance file comes down last (again, if a run died before).
				$this->take_down( $context, $cursor );
				throw $this->ended( $context, (string) ( $cursor['reason'] ?? '' ) );
			}
			if ( 'committed' === $phase ) {
				return $this->after( $context, $db, $cursor );
			}
			if ( 'done' === $phase ) {
				// Recorded as swapped; the maintenance file comes down last (again, if a run died before).
				$this->take_down( $context, $cursor );
				return StepResult::done( __( 'The restored site is in place', 'wp-checkpoint' ) );
			}
			throw new WorkLost( 'The position of the swap is not one this version wrote; the job row was changed.' );
		} finally {
			if ( $db instanceof ImportSession ) {
				$db->close();
			}
		}
	}

	/**
	 * This restore's maintenance file, when a cancel finds it: a job is cancelled only while the site is untouched
	 * (what the swap changed is put back by its own rollback, never by a cleanup), but a run that died between
	 * recording the site as put back and taking the file down leaves it, held. Only the file with this job's mark
	 * goes; nothing else is released here (the plan's rows go with the job). Never throws.
	 *
	 * @param JobContext $context Context.
	 * @return void
	 */
	public function cleanup( JobContext $context ): void {
		$cursor = $context->job()->cursor;
		if ( ! isset( $cursor['mark'] ) || ! is_string( $cursor['mark'] ) || '' === $cursor['mark'] ) {
			return;
		}
		try {
			$file = $this->maintenance( $cursor );
			if ( ! $file->remove() ) {
				$context->logger()->warning( 'The restore\'s maintenance file could not be taken down' );
			}
		} catch ( \Throwable $e ) {
			$context->logger()->warning( 'The restore\'s maintenance file could not be taken down', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * The first unit: the plan the check wrote, complete.
	 *
	 * @param JobContext $context Context.
	 * @param Queries    $db      Connection.
	 * @return array<string, mixed>
	 * @throws RetryFrom When the plan is not there whole (the check writes it again).
	 */
	private function start( JobContext $context, Queries $db ): array {
		$work = $context->work_path();
		try {
			$file = ExportPlan::read( $work, RestoreFiles::SWAP_PLAN );
		} catch ( \RuntimeException $e ) {
			throw new RetryFrom( 'The swap\'s plan is not in the work directory; the final check writes it again.', SwapCheckStep::ID );
		}
		$attempt = (int) ( $file['attempt'] ?? 0 );
		$count   = (int) ( $file['entries'] ?? -1 );
		$plan    = new SwapPlan( $db, self::base_prefix() . SwapPlan::TABLE );
		if ( $attempt < 1 || $plan->complete_count( $context->job()->id, $attempt ) !== $count ) {
			throw new RetryFrom( 'The swap\'s plan is not complete as the final check recorded it; the final check writes it again.', SwapCheckStep::ID );
		}
		return array(
			'phase'   => 'recount',
			'attempt' => $attempt,
			'mark'    => Maintenance::new_mark(),
			'i'       => 0,
			'key'     => null,
			'sum'     => 0,
		);
	}

	/**
	 * Count the temporary tables without transactions once more, a unit at a time within the budget (the first
	 * unit of a tick always runs).
	 *
	 * @param JobContext           $context Context.
	 * @param Queries              $db      Connection.
	 * @param array<string, mixed> $cursor  Cursor.
	 * @return array<string, mixed> The cursor: still "recount" when the budget ran out, "ready" when done.
	 * @throws WorkLost When a table holds other rows than the import recorded.
	 */
	private function recount( JobContext $context, Queries $db, array $cursor ): array {
		$work    = $context->work_path();
		$loaded  = RestorePreflightStep::load_plan( $work );
		$ledger  = new Ledger( $db, TempTables::ledger( $context->job()->storage_token, $context->job()->id, (string) $loaded['random'] ), bin2hex( random_bytes( 16 ) ) );
		$counter = new TempTableCount(
			$db,
			$loaded['plan'],
			$ledger,
			$work,
			(int) ( $this->parts['rows'] ?? SwapCheckStep::SIZES['rows'] ),
			function ( string $point ): void {
				$this->at( 'recount_' . $point );
			}
		);
		$first   = true;
		$slowest = 0.0;
		while ( true ) {
			if ( ! $first && ( $context->should_stop() || $context->remaining_seconds() < $slowest * SwapCheckStep::MARGIN ) ) {
				return $cursor;
			}
			$first   = false;
			$started = $context->elapsed();
			$unit    = $counter->unit(
				array(
					'i'   => (int) $cursor['i'],
					'key' => is_array( $cursor['key'] ) ? array_map( 'strval', $cursor['key'] ) : null,
					'sum' => (int) $cursor['sum'],
				),
				false
			);
			$slowest = max( $slowest, $context->elapsed() - $started );
			if ( $unit['done'] ) {
				return array(
					'phase'   => 'ready',
					'attempt' => $cursor['attempt'],
					'mark'    => $cursor['mark'],
				);
			}
			$cursor['i']   = $unit['position']['i'];
			$cursor['key'] = $unit['position']['key'];
			$cursor['sum'] = $unit['position']['sum'];
			if ( $context->should_checkpoint( 0 ) ) {
				$context->checkpoint( $cursor, 10, __( 'Counting the restored tables once more before the swap', 'wp-checkpoint' ) );
			}
		}
	}

	/**
	 * Ready for the swap: no time limit, the site's directories where they were, no one else's maintenance file.
	 *
	 * @param JobContext           $context Context.
	 * @param Queries              $db      Connection.
	 * @param array<string, mixed> $cursor  Cursor.
	 * @return StepResult|null A wait while someone else's maintenance file is in place; null when ready.
	 * @throws RetryFrom When a directory of the site moved since the files were staged.
	 * @throws TransientFailure When whether a maintenance file is there cannot be told.
	 * @throws \RuntimeException When this process keeps a time limit.
	 */
	private function ready( JobContext $context, Queries $db, array $cursor ): ?StepResult {
		HostFunctions::set_time_limit( 0 );
		$limit = isset( $this->parts['limit'] ) ? call_user_func( $this->parts['limit'] ) : ini_get( 'max_execution_time' );
		if ( '0' !== (string) $limit ) {
			throw new \RuntimeException( sprintf( 'This host keeps a time limit of %s seconds on WP-CLI that the restore cannot lift, and the swap must never be cut off halfway; it was not started, and the site is as it was. Run it where WP-CLI has no time limit (max_execution_time = 0).', (string) $limit ) );
		}
		$staging = RestoreFilesPreflightStep::staging( $context->work_path() );
		$now     = isset( $this->parts['site_dirs'] ) ? (array) call_user_func( $this->parts['site_dirs'] ) : ScanRoots::site_directories();
		clearstatcache( true );
		foreach ( (array) $staging['staged'] as $group ) {
			$was = (string) ( $staging['groups'][ $group ] ?? '' );
			if ( ! isset( $now[ $group ] ) || ! Paths::same_location( (string) $now[ $group ], $was ) ) {
				throw new RetryFrom( sprintf( 'The %1$s directory of the site moved since the restore staged its files (it was %2$s, it is %3$s now); the swap was not started, and the site is as it was. Put it back and retry, or start the restore again.', $group, $was, (string) ( $now[ $group ] ?? '?' ) ), SwapCheckStep::ID );
			}
		}
		$this->check_plan( $context, $db, $cursor, $staging );
		$state = $this->maintenance( $cursor )->state();
		if ( Maintenance::UNKNOWN === $state ) {
			throw new TransientFailure( 'Whether a maintenance file is in place cannot be told; the swap waits.' );
		}
		if ( Maintenance::OTHER === $state ) {
			$context->logger()->info( 'Another maintenance file is in place; the swap waits for it to go' );
			return StepResult::wait( 60, $cursor, __( 'Another maintenance file is in the WordPress directory (.maintenance: an update in progress, or one left by an update that stopped); the swap waits until it is gone. If nothing is being updated, remove that file.', 'wp-checkpoint' ) );
		}
		return null;
	}

	/**
	 * The swap, in this tick: the directories, this plugin's state, the tables; rolled back in this tick when
	 * anything stops it before it is made.
	 *
	 * @param JobContext           $context Context.
	 * @param Queries              $db      Connection.
	 * @param array<string, mixed> $cursor  Cursor ("ready").
	 * @return StepResult
	 * @throws \RuntimeException When the old directories' places cannot be made (nothing changed yet).
	 */
	private function swap( JobContext $context, Queries $db, array $cursor ): StepResult {
		$entries = $this->entries( $context, $db, $cursor );
		foreach ( $entries['dirs'] as $entry ) {
			$parent = dirname( $entry['old'] );
			if ( $entry['had_live'] && ! is_dir( $parent ) && ! @mkdir( $parent, 0755, true ) && ! is_dir( $parent ) ) {
				throw new \RuntimeException( sprintf( 'The place for the site\'s own %s in the staging root could not be made; the swap was not started, and the site is as it was.', basename( $entry['live'] ) ) );
			}
		}
		$carry   = $this->carry_tables( $entries['tables'] );
		$record  = null === $carry ? null : $this->carry_record( $context, $db );
		$file    = $this->maintenance( $cursor );
		$attempt = $cursor['attempt'];
		$mark    = $cursor['mark'];
		$cursor  = array(
			'phase'   => 'enter',
			'attempt' => $attempt,
			'mark'    => $mark,
		);
		$context->checkpoint( $cursor, 20, __( 'Swapping in the restored site', 'wp-checkpoint' ) );
		try {
			$this->at( 'entered' );
			$file->put( $this->now(), array( $context, 'confirm_lease' ) );
			$this->at( 'maintenance' );
			foreach ( $entries['dirs'] as $i => $entry ) {
				$cursor = array(
					'phase'   => 'dirs',
					'attempt' => $attempt,
					'mark'    => $mark,
					'i'       => $i,
					'step'    => 'a',
				);
				$context->checkpoint( $cursor, 25, __( 'Swapping in the restored files', 'wp-checkpoint' ) );
				$file->hold( array( $context, 'confirm_lease' ) ); // Held from the first change of the site on.
				if ( $entry['had_live'] ) {
					$this->rename( $context, $entry['live'], $entry['old'] );
					$this->at( 'dir_aside' );
					$cursor['step'] = 'b';
					$context->checkpoint( $cursor, 25, __( 'Swapping in the restored files', 'wp-checkpoint' ) );
					$this->at( 'dir_aside_recorded' );
					$file->hold( array( $context, 'confirm_lease' ) );
				}
				if ( $this->exists( $entry['live'] ) ) {
					// Made since the plan (or since its live one moved aside): a rename would replace a file or an
					// empty directory without a word.
					throw new \RuntimeException( sprintf( 'Something is at %s that was not there when the swap planned to put the restored copy there.', $entry['live'] ) );
				}
				$this->rename( $context, $entry['stage'], $entry['live'] );
				$this->at( 'dir_in' );
			}
			$cursor = array(
				'phase'   => 'carry',
				'attempt' => $attempt,
				'mark'    => $mark,
			);
			$context->checkpoint( $cursor, 50, __( 'Carrying this plugin\'s own settings into the restored site', 'wp-checkpoint' ) );
			$file->hold( array( $context, 'confirm_lease' ) );
			$guard = null;
			if ( null !== $carry ) {
				$guard = $this->state_carry( $db );
				$guard->carry( $carry['live_options'], $carry['temp_options'], $carry['live_meta'], $carry['temp_meta'], $record );
			}
			$this->at( 'carried' );
			$this->rename_tables( $context, $db, $cursor, $entries['tables'], $guard, $carry );
		} catch ( LockLost $e ) {
			throw $e; // The run that takes the job over rolls back.
		} catch ( StaleJob $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			$context->logger()->warning( 'The swap stopped', array( 'error' => $e->getMessage() ) );
			throw $this->roll_back( $context, $db, $cursor, self::STOPPED, self::reason( $e ) );
		}
		// Made only by the evidence: a listing that cannot be read is waited out, and the next tick decides from
		// what is there then (the cursor says the renames were under way).
		if ( ! SwapRules::committed( $entries['tables'], $this->there( $db, $entries['tables'] ) ) ) {
			throw $this->roll_back( $context, $db, $cursor, self::INCOMPLETE );
		}
		$this->release( $context, $this->maintenance( $cursor ) ); // The swap is made: the site is whole again.
		$this->at( 'unheld_commit' );
		$cursor = array(
			'phase'   => 'committed',
			'attempt' => $attempt,
			'mark'    => $mark,
			'post'    => 'cache',
		);
		$context->checkpoint( $cursor, 85, __( 'The restored site is in place', 'wp-checkpoint' ) );
		$this->at( 'committed' );
		$context->logger()->info( 'The restored site is in place' );
		return $this->after( $context, $db, $cursor );
	}

	/**
	 * The tables in batches, each recorded before it is sent; the first only when this plugin stays active.
	 *
	 * @param JobContext                                                                                             $context Context.
	 * @param Queries                                                                                                $db      Connection.
	 * @param array<string, mixed>                                                                                   $cursor  Cursor (updated for each batch).
	 * @param array<int, array{seq: int, kind: string, live: string, stage: string, old: string, had_live: bool}>    $tables  Table entries.
	 * @param StateCarry|null                                                                                        $guard   The carry, when the options are replaced.
	 * @param array{live_options: string, temp_options: string, live_meta: string|null, temp_meta: string|null}|null $carry The tables carried.
	 * @return void
	 */
	private function rename_tables( JobContext $context, Queries $db, array &$cursor, array $tables, $guard, $carry ): void {
		$db->run( 'SET SESSION lock_wait_timeout = ' . self::LOCK_WAIT );
		$batches = SwapRules::batches( $tables, isset( $this->parts['batch'] ) ? (int) $this->parts['batch'] : SwapRules::limit( $this->packet( $db ) ) );
		$file    = $this->maintenance( $cursor );
		foreach ( $batches as $k => $batch ) {
			$cursor = array(
				'phase'   => 'rename',
				'attempt' => $cursor['attempt'],
				'mark'    => $cursor['mark'],
				'batch'   => $k,
				'batches' => count( $batches ),
			);
			$context->checkpoint( $cursor, 60, __( 'Swapping in the restored tables', 'wp-checkpoint' ) );
			$file->hold( array( $context, 'confirm_lease' ) );
			$this->at( 'batch_recorded' );
			$send = function () use ( $context, $db, $batch ): void {
				$this->send( $context, $db, $batch['sql'] );
			};
			if ( 0 === $k && null !== $guard && null !== $carry ) {
				$guard->guard( $carry['temp_options'], $carry['temp_meta'], $send );
			} else {
				$send();
			}
			$this->at( 'batch_sent' );
		}
	}

	/**
	 * Send a RENAME, retried after a lock wait (each try right after a lease check).
	 *
	 * @param JobContext $context Context.
	 * @param Queries    $db      Connection.
	 * @param string     $sql     Statement.
	 * @return void
	 * @throws \RuntimeException When the tables stayed locked through every retry.
	 */
	private function send( JobContext $context, Queries $db, string $sql ): void {
		$tries = 0;
		while ( true ) {
			try {
				$context->confirm_lease();
				$db->run( $sql );
				return;
			} catch ( TransientFailure $e ) {
				if ( 1205 !== $e->getCode() ) {
					throw $e;
				}
				if ( $tries >= count( self::RETRIES ) ) {
					throw new \RuntimeException( sprintf( 'The database is busy: the tables stayed locked by other work through %d tries.', $tries + 1 ) );
				}
				$this->sleep( self::RETRIES[ $tries ] );
				++$tries;
			}
		}
	}

	/**
	 * Put the site back from what is there: the tables, then the directories, in reverse plan order; the
	 * maintenance file taken down; then the job ends (failed, a retry starting at the final check; or
	 * cancelled).
	 *
	 * @param JobContext           $context Context.
	 * @param Queries              $db      Connection.
	 * @param array<string, mixed> $cursor  Cursor.
	 * @param string               $reason  Why: one of REASONS (the cursor keeps only that).
	 * @param string               $detail  Why in words, for this tick's error (another tick says the reason's text).
	 * @return \RuntimeException What ends the job, for the caller to throw (ended()).
	 * @throws TransientFailure When what is there cannot be told (the rollback goes on later).
	 */
	private function roll_back( JobContext $context, Queries $db, array $cursor, string $reason, string $detail = '' ): \RuntimeException {
		if ( ! isset( self::REASONS[ $reason ] ) ) {
			$reason = self::INTERRUPTED;
		}
		HostFunctions::set_time_limit( 0 );
		if ( 'rollback' !== ( $cursor['phase'] ?? '' ) ) {
			$cursor = array(
				'phase'   => 'rollback',
				'attempt' => $cursor['attempt'],
				'mark'    => $cursor['mark'] ?? '',
				'reason'  => $reason,
			);
			$context->checkpoint( $cursor, 90, __( 'Putting the site back as it was', 'wp-checkpoint' ) );
			$context->logger()->warning( 'The swap is rolled back', array( 'reason' => self::REASONS[ $reason ] ) );
		}
		$this->at( 'rollback' );
		$entries = $this->entries( $context, $db, $cursor );
		$file    = $this->maintenance( $cursor );
		$db->run( 'SET SESSION lock_wait_timeout = ' . self::LOCK_WAIT );
		$there = $this->there( $db, $entries['tables'] );
		foreach ( array_reverse( $entries['tables'] ) as $entry ) {
			$pairs = SwapRules::table_back( $entry, $there, $this->stray_table( $context, $entry['live'] ) );
			if ( SwapPlan::TABLE_OF === $entry['kind'] && $entry['had_live'] && ! isset( $there[ $entry['stage'] ] ) && isset( $there[ $entry['live'] ] ) && ! isset( $there[ $entry['old'] ] ) ) {
				// Neither the restored table nor the old one where the swap would have them: the table under the final
				// name may be the live one (its temporary table went before the swap). Left alone, and said.
				$context->logger()->warning( 'A table of the plan was left as it is: neither its restored copy nor the moved-aside one is there', array( 'table' => $entry['live'] ) );
			}
			if ( array() === $pairs ) {
				continue;
			}
			$this->send( $context, $db, SwapRules::rename_sql( $pairs ) );
			foreach ( $pairs as $pair ) {
				unset( $there[ $pair[0] ] );
				$there[ $pair[1] ] = true;
				if ( 0 === strpos( $pair[1], TempTables::STRAY_PREFIX ) ) {
					$context->logger()->warning(
						'A table made while the swap was under way was moved out of the way and kept',
						array(
							'table' => $pair[0],
							'now'   => $pair[1],
						)
					);
				}
			}
			$this->at( 'table_back' );
		}
		foreach ( array_reverse( $entries['dirs'] ) as $entry ) {
			$pairs = SwapRules::dir_back( $entry, $this->exists( $entry['live'] ), $this->exists( $entry['stage'] ), $this->exists( $entry['old'] ), $this->stray_dir( $entry ) );
			foreach ( $pairs as $pair ) {
				if ( $pair[1] !== $entry['stage'] && $pair[1] !== $entry['live'] ) {
					$parent = dirname( $pair[1] );
					if ( ! is_dir( $parent ) && ! @mkdir( $parent, 0755, true ) && ! is_dir( $parent ) ) {
						throw new TransientFailure( sprintf( 'The place for what is in the way of %s could not be made.', $entry['live'] ) );
					}
					$context->logger()->warning(
						'Something made in the site\'s place while the swap was under way was moved out of the way and kept',
						array(
							'path' => $pair[0],
							'now'  => $pair[1],
						)
					);
				}
				$this->refresh( $context, $file, true );
				$this->rename( $context, $pair[0], $pair[1] );
				$this->at( 'dir_back' );
			}
		}
		$this->at( 'dirs_back' );
		// What requests cached of the half-swapped site (a persistent cache outlives this process). A cache that
		// cannot be flushed now is waited out: the site is back, and the maintenance file stays up until then.
		try {
			$this->flush( $context );
		} catch ( LockLost $e ) {
			throw $e;
		} catch ( StaleJob $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			throw new TransientFailure( 'The object cache could not be flushed after the site was put back; the rollback waits to finish: ' . $e->getMessage() );
		}
		$this->release( $context, $file );
		$this->at( 'unheld' );
		$reason = (string) ( $cursor['reason'] ?? $reason );
		$cursor = array(
			'phase'  => 'reverted',
			'mark'   => $cursor['mark'] ?? '',
			'reason' => $reason,
		);
		$context->checkpoint( $cursor, 100, __( 'The site is as it was before the restore', 'wp-checkpoint' ) );
		$this->at( 'reverted' );
		$this->take_down( $context, $cursor );
		$this->at( 'maintenance_down' );
		return $this->ended( $context, '' !== $detail ? $detail : $reason );
	}

	/**
	 * What ends a job whose swap was rolled back: cancelled when that was asked for, otherwise failed with a retry
	 * starting at the final check.
	 *
	 * @param JobContext $context Context.
	 * @param string     $reason  Why it was rolled back.
	 * @return \RuntimeException Cancelled or RetryFrom.
	 */
	private function ended( JobContext $context, string $reason ): \RuntimeException {
		if ( 0 !== $context->job()->cancel_requested ) {
			return new Cancelled( 'The restore was cancelled; the site is as it was before it.' );
		}
		$text = self::REASONS[ $reason ] ?? $reason;
		return new RetryFrom( trim( $text . ' The swap was rolled back: the site is as it was before the restore.' ), SwapCheckStep::ID );
	}

	/**
	 * What follows the swap, a step at a time: the caches, the rewrite rules, the mark for the fallback events,
	 * the maintenance file.
	 *
	 * @param JobContext           $context Context.
	 * @param Queries              $db      Connection.
	 * @param array<string, mixed> $cursor  Cursor ("committed").
	 * @return StepResult
	 * @throws TransientFailure When the maintenance file cannot be taken down yet.
	 * @throws WorkLost When the position is not one this version wrote.
	 */
	private function after( JobContext $context, Queries $db, array $cursor ): StepResult {
		$entries = null;
		while ( true ) {
			switch ( (string) ( $cursor['post'] ?? '' ) ) {
				case 'cache':
					// The swap is made: the maintenance file lapses again as before, refreshed step by step.
					$this->refresh( $context, $this->maintenance( $cursor ), false );
					// Before anything reads the site through WordPress: this process holds the old site's options.
					$this->flush( $context );
					$this->at( 'flushed' );
					$cursor['post'] = 'rewrite';
					break;
				case 'rewrite':
					$entries = $entries ?? $this->entries( $context, $db, $cursor );
					$this->refresh( $context, $this->maintenance( $cursor ), false );
					foreach ( $entries['tables'] as $entry ) {
						if ( SwapPlan::TABLE_OF === $entry['kind'] && self::is_options( $entry['live'] ) ) {
							$db->write( 'DELETE FROM ' . SqlWriter::identifier( $entry['live'] ) . ' WHERE option_name = ?', array( 'rewrite_rules' ) );
						}
					}
					$this->at( 'rewrite' );
					$cursor['post'] = 'cron';
					break;
				case 'cron':
					// The restored cron option has none of this installation's fallback events: the plugin sets them
					// again on its next request, with the restored site loaded (Plugin::after_swap()).
					$this->refresh( $context, $this->maintenance( $cursor ), false );
					$db->write(
						'INSERT INTO ' . SqlWriter::identifier( self::base_prefix() . 'options' ) . ' (option_name, option_value, autoload) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)',
						array( StoredNames::AFTER_SWAP, (string) $this->now(), 'yes' )
					);
					$this->at( 'cron' );
					$cursor['post'] = 'exit';
					break;
				case 'exit':
					// Again: requests that came while a stopped run waited may have cached the restored options before
					// the writes above.
					$this->flush( $context );
					$cursor = array(
						'phase'   => 'done',
						'attempt' => $cursor['attempt'],
						'mark'    => $cursor['mark'],
					);
					$context->checkpoint( $cursor, 100, __( 'The restored site is in place', 'wp-checkpoint' ) );
					$this->at( 'done_recorded' );
					$this->take_down( $context, $cursor );
					$this->at( 'exited' );
					return StepResult::done( __( 'The restored site is in place', 'wp-checkpoint' ) );
				default:
					throw new WorkLost( 'The position of the swap is not one this version wrote; the job row was changed.' );
			}
			$context->checkpoint( $cursor, 90, __( 'The restored site is in place', 'wp-checkpoint' ) );
		}
	}

	/**
	 * Before the swap (the work directory is read here, never later): every entry of the plan is exactly what the
	 * final check builds from the staging layout and the table plan. A directory unit: a staged group's live
	 * directory, staged copy and old path, or an entry of the staged other content (not a drop-in), each as
	 * StagingLayout names it. A table: its temporary name the plan's for its final name, its old name the one
	 * TempTables gives it; a live table moved aside: not a table of the backup, its old name the same way. The
	 * rollback, which must not read the work directory, holds the entries to their shape only (SwapRules::invalid()).
	 *
	 * @param JobContext           $context Context.
	 * @param Queries              $db      Connection.
	 * @param array<string, mixed> $cursor  Cursor (its attempt).
	 * @param array<string, mixed> $staging The staging layout's record (RestoreFilesPreflightStep::staging()).
	 * @return void
	 * @throws RetryFrom When an entry is not one the final check writes (the check writes the plan again).
	 */
	private function check_plan( JobContext $context, Queries $db, array $cursor, array $staging ): void {
		$job     = $context->job();
		$layout  = RestoreFilesPreflightStep::layout_of( $staging, $job );
		$loaded  = RestorePreflightStep::load_plan( $context->work_path() );
		$staged  = (array) $staging['staged'];
		$entries = $this->entries( $context, $db, $cursor );
		$other   = StagingLayout::OTHER;
		foreach ( $entries['dirs'] as $entry ) {
			$want = null;
			foreach ( StagingLayout::GROUPS as $group ) {
				if ( $other !== $group && in_array( $group, $staged, true ) && $layout->stage_dir( $group ) === $entry['stage'] ) {
					$want = array( $layout->live_dir( $group ), $layout->root( $group ) . '/old/' . $group );
				}
			}
			// The entry's name by prefix, not basename() / dirname() (locale-dependent): byte for byte what the check built.
			$prefix = $layout->stage_dir( $other ) . '/';
			$name   = 0 === strncmp( $entry['stage'], $prefix, strlen( $prefix ) ) ? (string) substr( $entry['stage'], strlen( $prefix ) ) : '';
			if ( null === $want && in_array( $other, $staged, true ) && '' !== $name && false === strpos( $name, '/' ) && ! in_array( $name, SwapCheckStep::DROP_INS, true ) ) {
				$want = array( $layout->live_dir( $other ) . '/' . $name, $layout->root( $other ) . '/old/' . $other . '/' . $name );
			}
			if ( null === $want || $want[0] !== $entry['live'] || $want[1] !== $entry['old'] ) {
				throw new RetryFrom( 'The swap\'s plan holds a directory entry the final check does not write; the swap was not started, and the final check writes the plan again.', SwapCheckStep::ID );
			}
		}
		$plan   = $loaded['plan'];
		$site   = $plan->site_prefix();
		$finals = array_column( $plan->tables(), 'final', 'temporary' );
		foreach ( $entries['tables'] as $entry ) {
			$old = TempTables::old( $job->storage_token, $job->id, (string) $loaded['random'], self::without( $entry['live'], $site ) );
			$ok  = $old === $entry['old'] && ( SwapPlan::TABLE_OF === $entry['kind'] ? ( $finals[ $entry['stage'] ] ?? null ) === $entry['live'] : ! in_array( $entry['live'], $finals, true ) );
			if ( ! $ok ) {
				throw new RetryFrom( 'The swap\'s plan holds a table entry the final check does not write; the swap was not started, and the final check writes the plan again.', SwapCheckStep::ID );
			}
		}
	}

	/**
	 * A name without a prefix it starts with (as the final check strips it).
	 *
	 * @param string $name   Name.
	 * @param string $prefix Prefix.
	 * @return string
	 */
	private static function without( string $name, string $prefix ): string {
		return '' !== $prefix && 0 === strncmp( $name, $prefix, strlen( $prefix ) ) && strlen( $name ) > strlen( $prefix ) ? (string) substr( $name, strlen( $prefix ) ) : $name;
	}

	/**
	 * The plan's entries of the attempt, in order: the directory units and the table entries.
	 *
	 * @param JobContext           $context Context.
	 * @param Queries              $db      Connection.
	 * @param array<string, mixed> $cursor  Cursor (its attempt).
	 * @return array{dirs: array<int, array{seq: int, kind: string, live: string, stage: string, old: string, had_live: bool}>, tables: array<int, array{seq: int, kind: string, live: string, stage: string, old: string, had_live: bool}>}
	 * @throws \RuntimeException When the plan's rows are gone or are not whole: an ordinary failure, never FINAL (a job
	 *                           that holds the site changed must keep its retry, which goes on putting it back).
	 */
	private function entries( JobContext $context, Queries $db, array $cursor ): array {
		$plan    = new SwapPlan( $db, self::base_prefix() . SwapPlan::TABLE );
		$job     = $context->job()->id;
		$attempt = (int) ( $cursor['attempt'] ?? 0 );
		$count   = $plan->complete_count( $job, $attempt );
		if ( null === $count ) {
			throw new \RuntimeException( 'The swap\'s plan is gone from the database or no longer whole; what the swap changed cannot be told from it. Retry once the plan\'s rows are back.' );
		}
		$out   = array(
			'dirs'   => array(),
			'tables' => array(),
		);
		$after = -1;
		while ( true ) {
			$page = $plan->read( $job, $attempt, $after, self::PAGE );
			foreach ( $page as $entry ) {
				$why = SwapRules::invalid( $entry, $context->job()->storage_token, $job );
				if ( '' !== $why ) {
					// Not FINAL: a job that holds the site changed keeps its retry.
					throw new \RuntimeException( sprintf( 'The swap\'s plan in the database holds an entry the final check does not write (%s); nothing more is renamed by it.', $why ) );
				}
				$out[ SwapPlan::DIR === $entry['kind'] ? 'dirs' : 'tables' ][] = $entry;
				$after = $entry['seq'];
			}
			if ( count( $page ) < self::PAGE ) {
				break;
			}
		}
		if ( count( $out['dirs'] ) + count( $out['tables'] ) !== $count ) {
			throw new \RuntimeException( 'The swap\'s plan in the database does not hold the entries it says it has. Retry once the plan\'s rows are back.' );
		}
		return $out;
	}

	/**
	 * Which of the table entries' names are there: one complete listing, a page of names per statement.
	 *
	 * @param Queries                                                                   $db      Connection.
	 * @param array<int, array{kind: string, live: string, stage: string, old: string}> $entries Table entries.
	 * @return array<string, bool> Name => true for each name there (compared byte for byte).
	 * @throws TransientFailure When the listing could not be read whole.
	 */
	private function there( Queries $db, array $entries ): array {
		$out = array();
		foreach ( array_chunk( SwapRules::names( $entries ), self::PAGE ) as $names ) {
			try {
				$rows = $db->rows( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode( ', ', array_fill( 0, count( $names ), '?' ) ) . ')', $names );
			} catch ( \RuntimeException $e ) {
				throw new TransientFailure( 'Which of the restore\'s tables are there cannot be read now: ' . $e->getMessage() );
			}
			$want = array_fill_keys( $names, true );
			foreach ( $rows as $row ) {
				$name = (string) $row[0];
				if ( isset( $want[ $name ] ) ) {
					$out[ $name ] = true;
				}
			}
		}
		return $out;
	}

	/**
	 * Whether a path is there (lstat), by positive evidence.
	 *
	 * @param string $path Path.
	 * @return bool
	 * @throws TransientFailure When it cannot be told.
	 */
	private function exists( string $path ): bool {
		clearstatcache( true, $path );
		if ( false !== @lstat( $path ) ) {
			return true;
		}
		if ( Paths::positively_gone( $path ) ) {
			return false;
		}
		throw new TransientFailure( sprintf( 'Whether %s is there cannot be told now.', $path ) );
	}

	/**
	 * Rename right after a lease check.
	 *
	 * @param JobContext $context Context.
	 * @param string     $from    From.
	 * @param string     $to      To.
	 * @return void
	 * @throws \RuntimeException When the rename failed.
	 */
	private function rename( JobContext $context, string $from, string $to ): void {
		$context->confirm_lease();
		if ( ! @rename( $from, $to ) ) {
			throw new \RuntimeException( sprintf( 'Moving %1$s to %2$s failed.', $from, $to ) );
		}
	}

	/**
	 * Refresh this restore's maintenance file during the rollback, as far as it can be: someone else's in its
	 * place, or a failed write, does not stop the rollback (it is logged).
	 *
	 * @param JobContext  $context Context.
	 * @param Maintenance $file    The file.
	 * @param bool        $held    Whether it is held (the site half swapped) or refreshed with the time.
	 * @return void
	 * @throws LockLost When the lease is gone (not caught).
	 * @throws StaleJob When the job is no longer this run's (not caught).
	 */
	private function refresh( JobContext $context, Maintenance $file, bool $held ): void {
		try {
			if ( $held ) {
				$file->hold( array( $context, 'confirm_lease' ) );
			} else {
				$file->put( $this->now(), array( $context, 'confirm_lease' ) );
			}
		} catch ( LockLost $e ) {
			throw $e;
		} catch ( StaleJob $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			$context->logger()->warning( $held ? 'The maintenance file could not be kept up during the rollback' : 'The maintenance file could not be refreshed after the swap was made', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Let this restore's maintenance file lapse again once the site is whole (put back, or swapped in): it is
	 * rewritten with the time before the state is recorded, so a run that dies before it takes the file down leaves
	 * a file that lapses after WordPress's ten minutes, not one held for good. Someone else's file in its place is
	 * left (there is none of ours to let go). What cannot be told or written is waited out: until then the site is
	 * recorded as being changed, and every WP-CLI command of the plugin says so.
	 *
	 * @param JobContext  $context Context.
	 * @param Maintenance $file    The file.
	 * @return void
	 * @throws TransientFailure When it could not be rewritten.
	 */
	private function release( JobContext $context, Maintenance $file ): void {
		if ( Maintenance::OTHER === $file->state() ) {
			$context->logger()->warning( 'Another maintenance file is in place of this restore\'s; it is left as it is' );
			return;
		}
		try {
			$file->put( $this->now(), array( $context, 'confirm_lease' ) );
		} catch ( LockLost $e ) {
			throw $e;
		} catch ( StaleJob $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			throw new TransientFailure( 'The maintenance file could not be let go yet: ' . $e->getMessage() );
		}
	}

	/**
	 * The options (and on a network the sitemeta) tables the swap replaces, when it replaces the options table.
	 *
	 * @param array<int, array{kind: string, live: string, stage: string}> $tables Table entries.
	 * @return array{live_options: string, temp_options: string, live_meta: string|null, temp_meta: string|null}|null
	 * @throws \RuntimeException When the backup replaces the sitemeta table but not the options table.
	 */
	private function carry_tables( array $tables ): ?array {
		$options = null;
		$meta    = null;
		foreach ( $tables as $entry ) {
			if ( SwapPlan::TABLE_OF !== $entry['kind'] ) {
				continue;
			}
			if ( self::base_prefix() . 'options' === $entry['live'] ) {
				$options = $entry;
			}
			if ( is_multisite() && self::base_prefix() . 'sitemeta' === $entry['live'] ) {
				$meta = $entry;
			}
		}
		if ( null === $options ) {
			if ( null !== $meta ) {
				throw new \RuntimeException( 'The backup replaces the network\'s settings (sitemeta) but not the main site\'s options; the swap does not carry this plugin\'s state into only one of them. The site is as it was.' );
			}
			return null;
		}
		return array(
			'live_options' => $options['live'],
			'temp_options' => $options['stage'],
			'live_meta'    => null === $meta ? null : $meta['live'],
			'temp_meta'    => null === $meta ? null : $meta['stage'],
		);
	}

	/**
	 * What records the carry's change of each table's rows in the restore's ledger (a retry's count expects it).
	 *
	 * @param JobContext $context Context.
	 * @param Queries    $db      Connection.
	 * @return callable function( string $temp_table, int $delta ): void
	 * @throws WorkLost When the plan in the work directory is gone (read before anything changes).
	 */
	private function carry_record( JobContext $context, Queries $db ): callable {
		$loaded  = RestorePreflightStep::load_plan( $context->work_path() );
		$numbers = array_column( $loaded['plan']->tables(), 'number', 'temporary' );
		$ledger  = new Ledger( $db, TempTables::ledger( $context->job()->storage_token, $context->job()->id, (string) $loaded['random'] ), bin2hex( random_bytes( 16 ) ) );
		return static function ( string $table, int $delta ) use ( $numbers, $ledger ): void {
			if ( ! isset( $numbers[ $table ] ) ) {
				throw new \RuntimeException( 'The carry changed a table the restore\'s plan does not have.' );
			}
			$ledger->add_carried( (int) $numbers[ $table ], $delta );
		};
	}

	/**
	 * The carry of this plugin's state, on the swap's connection.
	 *
	 * @param Queries $db Connection.
	 * @return StateCarry
	 * @throws \RuntimeException When the connection is not one the carry works on.
	 */
	private function state_carry( Queries $db ): StateCarry {
		if ( ! $db instanceof ImportSession ) {
			throw new \RuntimeException( 'The swap\'s connection cannot carry this plugin\'s state.' );
		}
		$plugin = isset( $this->parts['plugin'] ) ? (string) $this->parts['plugin'] : plugin_basename( WPCHECKPOINT_FILE );
		return new StateCarry(
			$db,
			$plugin,
			is_multisite() ? get_current_network_id() : 0,
			function ( string $point ): void {
				$this->at( $point );
			}
		);
	}

	/**
	 * This restore's maintenance file, by the mark the cursor holds (the work directory is not read: the rollback
	 * must not depend on the storage directory).
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @return Maintenance
	 * @throws WorkLost When the cursor has no mark.
	 */
	private function maintenance( array $cursor ): Maintenance {
		if ( ! isset( $cursor['mark'] ) || ! is_string( $cursor['mark'] ) || '' === $cursor['mark'] ) {
			throw new WorkLost( 'The position of the swap is not one this version wrote; the job row was changed.' );
		}
		return new Maintenance( (string) ( $this->parts['abspath'] ?? ABSPATH ), $cursor['mark'] );
	}

	/**
	 * The name a table in the way is moved to.
	 *
	 * @param JobContext $context Context.
	 * @param string     $name    The table's name.
	 * @return string
	 */
	private function stray_table( JobContext $context, string $name ): string {
		$job  = $context->job();
		$site = $GLOBALS['wpdb']->prefix ?? '';
		$bare = '' !== $site && 0 === strncmp( $name, $site, strlen( $site ) ) && strlen( $name ) > strlen( $site ) ? substr( $name, strlen( $site ) ) : $name;
		return TempTables::stray( $job->storage_token, $job->id, substr( $this->random(), 0, TempTables::RANDOM_LEN ), $bare );
	}

	/**
	 * Where an entry in the way of a directory unit is moved: the staging root's stray/ directory.
	 *
	 * @param array{live: string, old: string} $entry Entry.
	 * @return string
	 */
	private function stray_dir( array $entry ): string {
		$root = $entry['old'];
		while ( dirname( $root ) !== $root && 'stage' !== ( StagingLayout::parse( basename( $root ) )['kind'] ?? '' ) ) {
			$root = dirname( $root );
		}
		return $root . '/' . Residue::STRAY_DIR . '/' . basename( $entry['live'] ) . '-' . $this->random();
	}

	/**
	 * Whether a table is an options table of this installation (the main site's or a network site's).
	 *
	 * @param string $name Table name.
	 * @return bool
	 */
	private static function is_options( string $name ): bool {
		return 1 === preg_match( '/\A' . preg_quote( self::base_prefix(), '/' ) . '(?:[1-9][0-9]*_)?options\z/', $name );
	}

	/**
	 * The server's max_allowed_packet.
	 *
	 * @param Queries $db Connection.
	 * @return int
	 */
	private function packet( Queries $db ): int {
		if ( isset( $this->parts['packet'] ) ) {
			return (int) $this->parts['packet'];
		}
		$rows = $db->rows( 'SELECT @@max_allowed_packet' );
		return (int) ( $rows[0][0] ?? 0 );
	}

	/**
	 * Flush the object cache (the persistent one too, through its drop-in); a flush that says it failed is logged.
	 *
	 * @param JobContext $context Context.
	 * @return void
	 */
	private function flush( JobContext $context ): void {
		$flushed = isset( $this->parts['flush'] ) ? call_user_func( $this->parts['flush'] ) : wp_cache_flush();
		if ( false === $flushed ) {
			$context->logger()->warning( 'The object cache says it could not be flushed' );
		}
	}

	/**
	 * Take this restore's maintenance file down, the last step once the site is recorded as swapped or put back:
	 * the lease is checked right before the file is deleted.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor (its mark).
	 * @return void
	 * @throws TransientFailure When it cannot be taken down yet (it is tried again).
	 */
	private function take_down( JobContext $context, array $cursor ): void {
		if ( ! $this->maintenance( $cursor )->remove( array( $context, 'confirm_lease' ) ) ) {
			throw new TransientFailure( 'The maintenance file could not be taken down yet.' );
		}
	}

	/**
	 * Why the swap stopped, for the job's error.
	 *
	 * @param \Throwable $e What stopped it.
	 * @return string
	 */
	private static function reason( \Throwable $e ): string {
		return 'The swap stopped: ' . $e->getMessage();
	}

	/**
	 * Now (Unix time).
	 *
	 * @return int
	 */
	private function now(): int {
		return isset( $this->parts['now'] ) ? (int) call_user_func( $this->parts['now'] ) : time();
	}

	/**
	 * Eight hex characters.
	 *
	 * @return string
	 */
	private function random(): string {
		return isset( $this->parts['random'] ) ? (string) call_user_func( $this->parts['random'] ) : bin2hex( random_bytes( 4 ) );
	}

	/**
	 * Wait.
	 *
	 * @param int $seconds Seconds.
	 * @return void
	 */
	private function sleep( int $seconds ): void {
		if ( isset( $this->parts['sleep'] ) ) {
			call_user_func( $this->parts['sleep'], $seconds );
			return;
		}
		sleep( $seconds );
	}

	/**
	 * The site's base table prefix.
	 *
	 * @return string
	 */
	private static function base_prefix(): string {
		global $wpdb;
		return (string) $wpdb->base_prefix;
	}

	/**
	 * A crash seam (tests).
	 *
	 * @param string $point Point.
	 * @return void
	 */
	private function at( string $point ): void {
		if ( isset( $this->parts['at'] ) && is_callable( $this->parts['at'] ) ) {
			call_user_func( $this->parts['at'], $point );
		}
	}
}
