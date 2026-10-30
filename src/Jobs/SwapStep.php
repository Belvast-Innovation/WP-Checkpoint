<?php
/**
 * The swap: the restored directories and tables take the site's place, or the site is put back as it was.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Database\SqlWriter;
use WPCheckpoint\Database\WpdbConnection;
use WPCheckpoint\Files\ScanRoots;
use WPCheckpoint\Restore\ImportSession;
use WPCheckpoint\Restore\IncomingTables;
use WPCheckpoint\Restore\Ledger;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Restore\SelfCheck;
use WPCheckpoint\Restore\Queries;
use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Restore\SiteChanged;
use WPCheckpoint\Restore\SiteCheck;
use WPCheckpoint\Restore\SiteTables;
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
 * retried after a lock wait of LOCK_WAIT seconds at most RETRIES times. From
 * the first change of the site the maintenance file is held (it never lapses).
 * The swap is made when a listing shows every table entry swapped
 * (SwapRules::committed()): that is recorded ("committed", Job::SITE_SWAPPED)
 * while the file is still held, and from then on the swap only goes forward
 * and nothing is renamed again.
 *
 * Anything that stops the swap before that, in the same tick (an exception)
 * or seen by the next one (a run that died: "enter", "dirs", "carry", or
 * "rename" without every table swapped), rolls it back: the tables, then the
 * directories, in reverse plan order, each by what is there and not by where
 * the cursor stopped (SwapRules::table_back(), dir_back()); what is found in
 * a place the old site's table or directory must go back to (someone made it
 * meanwhile) is moved out of the way and logged, never deleted (the
 * maintenance file held before the first rename); the site is recorded as
 * put back ("restored") while the file is still held, and from then on
 * nothing is renamed again; the file is let go, the site recorded as
 * untouched ("reverted"), the file taken down; the job fails with the
 * reason, a retry starting over at the final check, or is cancelled when
 * that was asked for. What cannot be told (a
 * listing or an lstat that fails) is waited out (TransientFailure), never
 * guessed.
 *
 * After the swap is made: caches flushed (behind the held file), the
 * self-check row written and the restored site checked through the web
 * server (Restore\SiteCheck, behind the held file that lets the checks'
 * requests through; checked again after waits when it could not be told),
 * the self-check row deleted; a site that failed is swapped back out with
 * nothing of it served, one that could not be checked is kept or undone as
 * the policy or the answer to a question says (asked once it is let out);
 * then the file let go, the restored rewrite rules removed (WordPress builds
 * them again), a mark left in the restored options for the plugin to set its
 * fallback events again on its next request (Plugin), the cache flushed
 * again, the end recorded ("done"), the file taken down: each a step of its
 * own, with plain SQL on the restored tables (the process still holds the
 * old site's plugins); of WordPress's API only the cache flush, the HTTP
 * requests of the checks, and the options WP_HOME and WP_SITEURL stand for
 * when wp-config.php defines them. The empty cursor of the
 * ended step, on a job still recorded as holding the site (a run that died
 * between the Runner's two last writes), ends the step without touching
 * anything.
 *
 * The reverse: a swap that was made can be swapped back out (reverse():
 * the restored site failed its checks; there is no undo yet, 4c-C adds
 * it). The direction is
 * recorded first ("reverse", with the reason), the maintenance file held
 * before the first rename, then the site is put back exactly as the
 * rollback does it, from what is there (every table entry is in its swapped
 * state, which table_back() and dir_back() put back), recorded as put back
 * ("restored") while the file is held, let go, recorded "reverted", the
 * file taken down; the job ends failed with the reason, a retry starting
 * over at the final check. A run that dies anywhere in it reads "reverse"
 * and goes on putting the site back: once the direction is recorded, no run
 * goes forward again. Nothing but reverse() renames after "committed".
 */
final class SwapStep implements Step, HoldsSite, MarksSite, CliOnly {

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
	const REVERSED    = 'reversed';
	const UNDONE      = 'undone';
	const ASKED       = 'asked';
	const REASONS     = array(
		self::INTERRUPTED => 'The swap was interrupted before it was complete.',
		self::INCOMPLETE  => 'After the renames, not every table of the plan was in its place.',
		self::STOPPED     => 'The swap stopped (the job log says why).',
		self::REVERSED    => 'A check of the restored site found it broken: the site was put back as it was before the restore.',
		self::UNDONE      => 'The restored site could not be checked, and the swap was undone as chosen: the site was put back as it was before the restore.',
		self::ASKED       => 'The restore was undone as asked (wp wpcheckpoint job undo): the site was put back as it was before the restore.',
	);

	/**
	 * Checks of the restored site that could not tell made at most, and the waits between them (each try in a tick
	 * of its own, with a new pass key and one-time value).
	 */
	const CHECK_TRIES = 4;
	const CHECK_WAITS = array( 15, 60, 120 );

	/**
	 * The question asked when the restored site could not be checked: keep it, or undo the swap (RestoreJob::UNVERIFIED
	 * says it up front).
	 */
	const UNVERIFIED_QUESTION = 'unverified';
	const UNVERIFIED_KIND     = 'restored_site_unchecked';

	/**
	 * The checks of the restored site a failed verdict names (the cursor keeps the id): what 4c-B checks, and what
	 * the job's error says of each. "unknown" is what a verdict without a known id is kept as.
	 */
	const CHECKS = array(
		'home'        => 'the home page did not answer as a WordPress site',
		'login'       => 'the login page did not answer as a WordPress site',
		'self'        => 'the plugin\'s self-check did not answer from the restored tables',
		'home_fatal'  => 'the home page answered with WordPress\'s fatal error page (HTTP 500), twice, 15 seconds apart',
		'login_fatal' => 'wp-login.php answered with WordPress\'s fatal error page (HTTP 500), twice, 15 seconds apart',
		'self_wrong'  => 'the plugin\'s self-check answered from another options table than the one the restore swapped in',
		'unknown'     => 'the check is not named',
	);

	/**
	 * Why the swap did not go on when the backup's tables are judged otherwise than at the preflight (judge(), and
	 * once more before the first rename), and what it did then in the second case.
	 */
	const REJUDGED   = 'The backup\'s tables are judged otherwise than at the preflight of this restore: another installation\'s tables or users appeared in this database, or went, since then (the job log says what changed). A retry starts the restore over at its preflight, which asks about them again.';
	const STOOD_DOWN = 'The swap stopped before it renamed anything, and took its maintenance file down: the site is as it was.';

	/**
	 * Phases in which the site is being changed (or put back), and in which it is swapped.
	 */
	const CHANGING = array( 'enter', 'dirs', 'carry', 'rename', 'rollback', 'reverse', 'restored' );
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
	 * of renames, in place of SwapRules::limit()), "remove" function( Maintenance $file, callable $confirm ): bool
	 * (in place of taking the maintenance file down), "verify" function( JobContext, array{key: string, nonce:
	 * string} ): true|string|array (in place of the checks, given the run's pass key and the one-time value of the
	 * self-check row: true passed; a string, the id of the check that failed (CHECKS), reverses the swap; an array
	 * is SiteCheck's result), "http" function( string $url, array $headers ): array (in place of http(), each request
	 * of the checks), "urls" function(): array (in place of check_urls(), where the checks go), "left" function(
	 * string $dir, callable $lease ): array (in place of JobActions::release_left()).
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
	 * The maintenance file's mark a cursor carries (MarksSite): from the cursor start() returns, checkpointed before
	 * the file is first put up, on every cursor after it.
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @return string|null
	 */
	public static function site_mark( array $cursor ) {
		$mark = (string) ( $cursor['mark'] ?? '' );
		return '' === $mark ? null : $mark;
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
		if ( array() === $cursor && Job::SITE_UNTOUCHED !== $context->job()->site_state ) {
			// The empty cursor of a step that ended (the Runner writes it, then completes the job in a second write) on a
			// job that holds the site: the swap is over. Starting it again would find the site swapped and put it back.
			if ( Job::SITE_SWAPPED === $context->job()->site_state ) {
				return $this->completed( $context );
			}
			throw new \RuntimeException( 'The swap has no position recorded while the site is recorded as being changed; nothing was changed. The job row was changed: look at the site before you go on.' );
		}
		$db = call_user_func( $this->connect );
		try {
			$phase = (string) ( $cursor['phase'] ?? 'start' );
			if ( 'start' === $phase ) {
				$cursor = $this->start( $context, $db );
				$context->checkpoint( $cursor, 3, __( 'Judging the backup\'s tables once more before the swap', 'wp-checkpoint' ) );
				$phase = 'judge';
			}
			if ( 'judge' === $phase ) {
				$cursor = $this->judge( $context, $cursor );
				if ( 'judge' === $cursor['phase'] ) {
					return StepResult::progress( $cursor, 4, __( 'Judging the backup\'s tables once more before the swap', 'wp-checkpoint' ) );
				}
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
					return $this->commit( $context, $db, $cursor );
				}
				throw $this->roll_back( $context, $db, $cursor, self::INTERRUPTED );
			}
			if ( 'rollback' === $phase ) {
				throw $this->roll_back( $context, $db, $cursor, (string) ( $cursor['reason'] ?? '' ) );
			}
			if ( 'reverse' === $phase ) {
				// Recorded as being swapped back out: the direction holds, whatever this run finds.
				throw $this->roll_back( $context, $db, $cursor, (string) ( $cursor['reason'] ?? self::REVERSED ) );
			}
			if ( 'rejudged' === $phase ) {
				// Recorded as untouched after the second judgement differed: the file comes down (again, if a run died
				// before), and the restore starts over at its preflight.
				$this->take_down( $context, $cursor );
				throw new RetryFrom( self::REJUDGED . ' ' . self::STOOD_DOWN, RestorePreflightStep::ID );
			}
			if ( 'restored' === $phase ) {
				// Recorded as put back while the file was held: only the end is left, nothing is renamed any more.
				throw $this->end_back( $context, $cursor, '' );
			}
			if ( 'reverted' === $phase ) {
				// Recorded as untouched; the maintenance file comes down last (again, if a run died before).
				throw $this->end_back( $context, $cursor, '' );
			}
			if ( 'committed' === $phase ) {
				return $this->after( $context, $db, $cursor );
			}
			if ( 'done' === $phase ) {
				// Recorded as swapped; the maintenance file comes down last (again, if a run died before).
				$this->take_down( $context, $cursor );
				return $this->completed( $context );
			}
			throw new \RuntimeException( 'The position of the swap is not one this version wrote; the job row was changed. Nothing was changed by this run: look at the site before you go on.' ); // Not FINAL: the job may hold the site.
		} finally {
			if ( $db instanceof ImportSession ) {
				$db->close();
			}
		}
	}

	/**
	 * This restore's maintenance file, when a cancel finds it: a job is cancelled only while the site is untouched
	 * (what the swap changed is put back by its own rollback, never by a cleanup), but a run that died between
	 * recording the site as untouched and taking the file down leaves it (let go: it lapses, but it is still there).
	 * Only the file with this job's mark goes; nothing else is released here (the plan's rows go with the job).
	 * Never throws.
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
			// This restore's file only: a bare one (no data file names its restore) is the holder's to take down, and
			// this job's once it was recorded as untouched after it held the site (its file put up, then let go).
			$own = in_array( (string) ( $cursor['phase'] ?? '' ), array( 'reverted', 'rejudged' ), true );
			if ( ( $own || Maintenance::BARE !== $file->state() ) && ! $file->remove() ) {
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
		// One mark for the job, whatever the attempt: a held file of an earlier attempt somewhere else (a copy of the
		// site) carries it, and the row must keep saying it (Job::$site_mark) or nothing could tell that file again.
		$mark = (string) $context->job()->site_mark;
		return array(
			'phase'   => 'judge',
			'attempt' => $attempt,
			'mark'    => '' !== $mark ? $mark : Maintenance::new_mark(),
		);
	}

	/**
	 * Judge the backup's tables once more before anything is changed (the site is untouched): the usermeta table is
	 * walked again within the budget, the live tables listed, and the judgement compared with the one the preflight
	 * recorded. Another installation's tables or users that appeared or went since then would make the plan act on
	 * tables nobody was asked about.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor ("judge", with the walk's state when a tick before stopped in it).
	 * @return array<string, mixed> The cursor: still "judge" when the budget ran out, "recount" when it is the same.
	 * @throws RetryFrom When the judgement differs, or the preflight recorded none (the restore starts over there).
	 */
	private function judge( JobContext $context, array $cursor ): array {
		$work     = $context->work_path();
		$recorded = RestorePreflightStep::judged( $work );
		if ( null === $recorded ) {
			throw new RetryFrom( 'The preflight of this restore recorded no judgement of the backup\'s tables to compare with (its plan was written by another version); the swap was not started, and the site is as it was. A retry starts the restore over at its preflight.', RestorePreflightStep::ID );
		}
		$loaded  = RestorePreflightStep::load_plan( $work );
		$message = __( 'Judging the backup\'s tables once more before the swap', 'wp-checkpoint' );
		$walk    = RestorePreflightStep::search_usermeta(
			$context,
			isset( $cursor['meta'] ) && is_array( $cursor['meta'] ) ? $cursor['meta'] : null,
			$loaded['multisite'],
			static function ( array $meta ) use ( $context, $cursor, $message ): void {
				$cursor['meta'] = $meta;
				$context->checkpoint( $cursor, 4, $message );
			}
		);
		if ( ! $walk['done'] ) {
			$cursor['meta'] = $walk['meta'];
			return $cursor;
		}
		$now = $this->judgement( $loaded, $recorded['finals'], $walk['meta']['found'], $walk['meta']['over'] );
		if ( $now !== $recorded['judgement'] ) {
			$context->logger()->warning(
				'The backup\'s tables are judged otherwise than at the preflight',
				array(
					'then' => $recorded['judgement'],
					'now'  => $now,
				)
			);
			throw new RetryFrom( self::REJUDGED . ' The swap was not started, and the site is as it was.', RestorePreflightStep::ID );
		}
		return array(
			'phase'   => 'recount',
			'attempt' => $cursor['attempt'],
			'mark'    => $cursor['mark'],
			'i'       => 0,
			'key'     => null,
			'sum'     => 0,
		);
	}

	/**
	 * The judgement of the backup's tables now (IncomingTables::judgement()), with the live tables listed now and
	 * the given search of the usermeta table.
	 *
	 * @param array{plan: \WPCheckpoint\Restore\TablePlan, multisite: bool} $loaded   The plan file.
	 * @param string[]                                                      $finals   The final names the preflight judged.
	 * @param string[]                                                      $evidence What the search found, in hex.
	 * @param bool                                                          $over     Whether the search stopped at its limit.
	 * @return array{neighbour: string[], uncertain: string[], shared: string[], evidence: string[], over: bool}
	 */
	private function judgement( array $loaded, array $finals, array $evidence, bool $over ): array {
		$prefix = $loaded['plan']->site_prefix();
		$live   = ( new WpdbConnection() )->tables_with_prefix( $prefix )['tables'];
		return IncomingTables::judgement( $prefix, $loaded['multisite'], $live, $finals, SiteTables::core(), SiteTables::users(), SiteTables::usermeta(), $evidence, $over );
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
			},
			StateCarry::counted( self::base_prefix(), is_multisite() ? get_current_network_id() : 0 ),
			$context->fence()
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
		$file  = $this->maintenance( $cursor );
		$state = $file->state();
		$left  = null;
		if ( Maintenance::OTHER === $state ) {
			$left = $this->release_left( $context );
			if ( JobActions::LEFT_GONE === $left['outcome'] ) {
				$context->logger()->info( sprintf( 'The maintenance file left by restore job %d, which has ended, was taken down', $left['id'] ) );
				$state = $file->state();
			}
		}
		if ( Maintenance::UNKNOWN === $state ) {
			throw new TransientFailure( 'Whether a maintenance file is in place cannot be told; the swap waits.' );
		}
		if ( Maintenance::OTHER === $state ) {
			$context->logger()->info( 'Another maintenance file is in place; the swap waits for it to go' );
			return StepResult::wait( 60, $cursor, self::other_file( $left ) );
		}
		if ( Maintenance::BARE === $state ) {
			// This plugin's code with no data file of its form: it names no restore (this one's would, from its first
			// write on), so it is not this restore's to take over or to take down.
			$context->logger()->info( 'A maintenance file of this plugin\'s without its data file is in place; the swap waits for it to go' );
			return StepResult::wait( 60, $cursor, __( 'A maintenance file of WP Checkpoint is in the WordPress directory (.maintenance) without the data file that names its restore (.wpcheckpoint-maintenance.php is missing or was changed); the swap waits until it is gone. If wp wpcheckpoint job list shows no restore that holds this site, remove .maintenance.', 'wp-checkpoint' ) );
		}
		return null;
	}

	/**
	 * Take down the maintenance file an earlier restore left, when its job has ended (JobActions::release_left()).
	 *
	 * @param JobContext $context Context.
	 * @return array{outcome: string, id: int}
	 */
	private function release_left( JobContext $context ): array {
		$dir   = (string) ( $this->parts['abspath'] ?? ABSPATH );
		$lease = array( $context, 'confirm_lease' );
		return isset( $this->parts['left'] )
			? (array) call_user_func( $this->parts['left'], $dir, $lease )
			: \WPCheckpoint\Plugin::instance()->job_actions()->release_left( $dir, $lease );
	}

	/**
	 * What the swap waits for while another maintenance file is in place.
	 *
	 * @param array{outcome: string, id: int}|null $left What release_left() found (null: not asked).
	 * @return string
	 */
	private static function other_file( ?array $left ): string {
		$outcome = null === $left ? '' : $left['outcome'];
		if ( JobActions::LEFT_NO_JOB === $outcome ) {
			return __( 'A maintenance file of a WP Checkpoint restore that no job here recorded is in the WordPress directory (.maintenance; it may have been copied from another site); the swap waits until it is gone. If wp wpcheckpoint job list shows no restore that holds this site, remove that file.', 'wp-checkpoint' );
		}
		if ( JobActions::LEFT_NOT_ENDED === $outcome ) {
			/* translators: %d: job id. */
			return sprintf( __( 'The maintenance file of restore job %1$d is in the WordPress directory (.maintenance), and that job has not ended, still holds the site, or is being run; the swap waits until it is gone. wp wpcheckpoint job status %1$d says what to do.', 'wp-checkpoint' ), $left['id'] );
		}
		if ( JobActions::LEFT_STAYS === $outcome ) {
			/* translators: %d: job id. */
			return sprintf( __( 'The maintenance file left by restore job %d, which has ended, could not be taken down from the WordPress directory (.maintenance); the swap tries again.', 'wp-checkpoint' ), $left['id'] );
		}
		return __( 'Another maintenance file is in the WordPress directory (.maintenance: an update in progress, or one left by an update that stopped); the swap waits until it is gone. If nothing is being updated, remove that file.', 'wp-checkpoint' );
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
			// Once more before the first rename, the live tables only (the usermeta table was walked in this attempt's
			// judge phase, and that judgement was the preflight's): the site's only change so far is the maintenance file.
			$recorded = RestorePreflightStep::judged( $context->work_path() );
			$loaded   = RestorePreflightStep::load_plan( $context->work_path() );
			if ( null === $recorded || $this->judgement( $loaded, $recorded['finals'], $recorded['judgement']['evidence'], $recorded['judgement']['over'] ) !== $recorded['judgement'] ) {
				throw new IncomingChanged( self::REJUDGED );
			}
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
				$guard->carry( $carry['live_options'], $carry['temp_options'], $carry['live_meta'], $carry['temp_meta'], $context->fence() );
			}
			$this->at( 'carried' );
			$this->rename_tables( $context, $db, $cursor, $entries['tables'], $guard, $carry );
		} catch ( LockLost $e ) {
			throw $e; // The run that takes the job over rolls back.
		} catch ( StaleJob $e ) {
			throw $e;
		} catch ( IncomingChanged $e ) {
			// Nothing renamed, the maintenance file the only change: it comes down first, then the site is recorded as
			// untouched (a run that dies between the two finds the site entering and puts it back; one that dies after
			// takes the file down again), then the restore starts over at its preflight. Never the rollback.
			$context->logger()->warning( 'The backup\'s tables are judged otherwise than at the preflight; the swap stops before it renames anything' );
			$this->take_down( $context, $cursor );
			$this->at( 'judge_file_down' );
			$cursor = array(
				'phase' => 'rejudged',
				'mark'  => $mark,
			);
			$context->checkpoint( $cursor, 20, __( 'The swap stopped before it changed the site', 'wp-checkpoint' ) );
			$this->at( 'judge_recorded' );
			throw new RetryFrom( self::REJUDGED . ' ' . self::STOOD_DOWN, RestorePreflightStep::ID );
		} catch ( \Throwable $e ) {
			$context->logger()->warning( 'The swap stopped', array( 'error' => $e->getMessage() ) );
			throw $this->roll_back( $context, $db, $cursor, self::STOPPED, self::reason( $e ) );
		}
		// Made only by the evidence: a listing that cannot be read is waited out, and the next tick decides from
		// what is there then (the cursor says the renames were under way).
		if ( ! SwapRules::committed( $entries['tables'], $this->there( $db, $entries['tables'] ) ) ) {
			throw $this->roll_back( $context, $db, $cursor, self::INCOMPLETE );
		}
		$context->logger()->info( 'The restored site is in place' );
		return $this->commit( $context, $db, $cursor );
	}

	/**
	 * The swap is made: recorded first, while the maintenance file is still held (from here on nothing is renamed,
	 * whatever is found later), then what follows it: the cache flushed behind the held file, then the file let go.
	 *
	 * @param JobContext           $context Context.
	 * @param Queries              $db      Connection.
	 * @param array<string, mixed> $cursor  Cursor (its attempt and mark).
	 * @return StepResult
	 */
	private function commit( JobContext $context, Queries $db, array $cursor ): StepResult {
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
		if ( ! in_array( $cursor['phase'] ?? '', array( 'rollback', 'reverse' ), true ) ) {
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
		$this->hold_for_renames( $context, $file, ! empty( $cursor['out'] ) );
		$db->run( 'SET SESSION lock_wait_timeout = ' . self::LOCK_WAIT );
		$there = $this->there( $db, $entries['tables'] );
		$this->carry_back( $context, $db, $entries['tables'], $there );
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
				$this->refresh( $context, $file, true, ! empty( $cursor['out'] ) );
				$this->rename_back( $context, $pair[0], $pair[1] );
				$this->at( 'dir_back' );
			}
		}
		$this->at( 'dirs_back' );
		// What requests cached of the half-swapped site (a persistent cache outlives this process). A cache that
		// cannot be flushed now is waited out: the site is back, and the maintenance file stays held until then.
		$this->flush( $context );
		// Recorded as put back while the file is still held: from here on nothing is renamed, whatever is found later.
		$out    = ! empty( $cursor['out'] );
		$cursor = array(
			'phase'  => 'restored',
			'mark'   => $cursor['mark'] ?? '',
			'reason' => (string) ( $cursor['reason'] ?? $reason ),
			'check'  => (string) ( $cursor['check'] ?? '' ),
		);
		if ( $out ) {
			$cursor['out'] = true; // Kept to the end: an undo after the site was let out ends the job as undone (ended()).
		}
		$context->checkpoint( $cursor, 98, __( 'The site is as it was before the restore', 'wp-checkpoint' ) );
		$this->at( 'restored' );
		return $this->end_back( $context, $cursor, $detail );
	}

	/**
	 * Before anything is renamed back: this plugin's own state, written since the swap into the options table (and a
	 * network's sitemeta) under its live name, carried into the table the swap moved aside, which is about to come back
	 * (StateCarry::carry_back(), in its fenced transaction). Only while the options table is where the swap left it,
	 * the restored one under the live name, the old one under its moved-aside name and nothing under the staging name
	 * (positive evidence, read just before; with a table under the staging name too, the one under the live name is
	 * not the restored one and is moved out of the way): a run taken up after it was renamed back finds no such pair
	 * and carries nothing, never the other way;
	 * the run that renamed it had carried both before its first rename. The sitemeta pair goes with it when it is
	 * where the swap left it too.
	 *
	 * Putting the site back comes first: a carry that fails for a moment (TransientFailure, as the connection raises
	 * for a lock wait, a deadlock, a row changed since its snapshot or a lost connection) is waited out, the
	 * maintenance file held, and tried again by the next run; one that fails for any other reason is said in the
	 * job's log (with what was thrown), and the site is put back without it: on an old table without transactions the
	 * statements sent before the failure stay, and they never take away a row the old table had under one of this
	 * plugin's names the carry listed in the live table and the live table still has: it stays, or the live one is
	 * there, or both (a sitemeta table) (StateCarry::carry_back()).
	 *
	 * @param JobContext                                                                                          $context Context.
	 * @param Queries                                                                                             $db      Connection.
	 * @param array<int, array{seq: int, kind: string, live: string, stage: string, old: string, had_live: bool}> $tables  Table entries.
	 * @param array<string, bool>                                                                                 $there   Which of their names are there.
	 * @return void
	 * @throws TransientFailure When the carry fails for a moment (the put-back goes on later).
	 * @throws LockLost When the job is no longer this run's.
	 * @throws StaleJob When the job changed meanwhile.
	 */
	private function carry_back( JobContext $context, Queries $db, array $tables, array $there ): void {
		$pairs = array();
		foreach ( $tables as $entry ) {
			if ( SwapPlan::TABLE_OF !== $entry['kind'] || ! $entry['had_live'] || ! isset( $there[ $entry['live'] ], $there[ $entry['old'] ] ) || isset( $there[ $entry['stage'] ] ) ) {
				continue;
			}
			if ( self::base_prefix() . 'options' === $entry['live'] ) {
				$pairs['options'] = $entry;
			}
			if ( is_multisite() && self::base_prefix() . 'sitemeta' === $entry['live'] ) {
				$pairs['meta'] = $entry;
			}
		}
		if ( ! isset( $pairs['options'] ) ) {
			return;
		}
		$meta = $pairs['meta'] ?? null;
		try {
			$this->state_carry( $db )->carry_back( $pairs['options']['live'], $pairs['options']['old'], null === $meta ? null : $meta['live'], null === $meta ? null : $meta['old'], $context->fence() );
		} catch ( LockLost $e ) {
			throw $e; // The run that takes the job over puts the site back.
		} catch ( StaleJob $e ) {
			throw $e;
		} catch ( TransientFailure $e ) {
			throw $e; // A problem of the moment: the put-back waits, its file held, and goes on.
		} catch ( \Throwable $e ) {
			// Nothing a retry would change: the site is put back without the carry, and that is said.
			$context->logger()->warning(
				'This plugin\'s own settings written since the swap were not carried back (or only some of them); the site is put back all the same',
				array(
					'error'  => get_class( $e ),
					'reason' => $e->getMessage(),
				)
			);
			return;
		}
		$context->logger()->info( 'This plugin\'s own settings were carried back into the site as it was' );
		$this->at( 'carried_back' );
	}

	/**
	 * The end of a rollback recorded as put back ("restored"): the maintenance file let go, the site recorded as
	 * untouched, the file taken down as the last step; then what ends the job.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor ("restored" or "reverted").
	 * @param string               $detail  Why in words, for this tick's error ('' for the reason's text).
	 * @return \RuntimeException What ends the job, for the caller to throw (ended()).
	 * @throws TransientFailure When the file cannot be let go or taken down yet (it is tried again).
	 */
	private function end_back( JobContext $context, array $cursor, string $detail ): \RuntimeException {
		$reason = (string) ( $cursor['reason'] ?? self::INTERRUPTED );
		if ( 'restored' === ( $cursor['phase'] ?? '' ) ) {
			$this->release( $context, $this->maintenance( $cursor ) );
			$this->at( 'unheld' );
			$cursor = array(
				'phase'  => 'reverted',
				'mark'   => $cursor['mark'] ?? '',
				'reason' => $reason,
				'check'  => (string) ( $cursor['check'] ?? '' ),
			) + ( empty( $cursor['out'] ) ? array() : array( 'out' => true ) );
			$context->checkpoint( $cursor, 100, __( 'The site is as it was before the restore', 'wp-checkpoint' ) );
			$this->at( 'reverted' );
		}
		$this->take_down( $context, $cursor );
		$this->at( 'maintenance_down' );
		return $this->ended( $context, '' !== $detail ? $detail : $reason, (string) ( $cursor['check'] ?? '' ), in_array( $reason, array( self::UNDONE, self::ASKED ), true ) && ! empty( $cursor['out'] ) );
	}

	/**
	 * Hold this restore's maintenance file before the rollback renames anything: the run that is rolled back held it,
	 * but it may be gone meanwhile (a WordPress update takes a maintenance file down whoever wrote it), or the
	 * rollback may start before the file was first put up (from "enter"). Someone else's file in its place is left
	 * (the site answers with a maintenance page all the same). What cannot be told or written is waited out: nothing
	 * is renamed without the file held.
	 *
	 * A reverse of a site that was let out (an undo answered after the release: $out) waits for someone else's file
	 * instead: the site has been live, and that file may be an update writing into the directories the reverse moves.
	 *
	 * @param JobContext  $context Context.
	 * @param Maintenance $file    The file.
	 * @param bool        $out     Whether the site was let out before this reverse.
	 * @return void
	 * @throws TransientFailure When it cannot be held now.
	 */
	private function hold_for_renames( JobContext $context, Maintenance $file, bool $out = false ): void {
		if ( Maintenance::OTHER === $file->state() ) {
			if ( $out ) {
				throw new TransientFailure( 'Another maintenance file is in place (an update in progress, for example); the undo waits for it to go before it moves anything.' );
			}
			return;
		}
		try {
			$file->hold( array( $context, 'confirm_lease' ) );
		} catch ( LockLost $e ) {
			throw $e;
		} catch ( StaleJob $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			throw new TransientFailure( 'The maintenance file could not be held before the rollback: ' . $e->getMessage() );
		}
	}

	/**
	 * The cursor a completed restore is undone from (wp wpcheckpoint job undo, JobActions::undo()): its reverse, as
	 * reverse() records it after the site was let out, with the reason ASKED. The next run holds the maintenance file
	 * again before the first rename back (waiting out anyone else's), carries this plugin's state back, and puts the
	 * site back by the plan; the job ends undone (ended()).
	 *
	 * @param int    $attempt The plan's attempt the swap was made by.
	 * @param string $mark    The job's maintenance file mark (Job::$site_mark).
	 * @return array<string, mixed>
	 */
	public static function undo_cursor( int $attempt, string $mark ): array {
		return array(
			'phase'   => 'reverse',
			'attempt' => $attempt,
			'mark'    => $mark,
			'reason'  => self::ASKED,
			'check'   => '',
			'out'     => true,
		);
	}

	/**
	 * What ends a job whose swap was rolled back: cancelled when that was asked for; undone (Undone) when it was undone
	 * after the restored site was let out; otherwise failed with a retry starting at the final check.
	 *
	 * @param JobContext $context Context.
	 * @param string     $reason  Why it was rolled back.
	 * @param string     $check   The check that failed (an id of CHECKS), when it was reversed for one.
	 * @param bool       $undone  Whether it was undone after the restored site was let out.
	 * @return \RuntimeException Cancelled, Undone or RetryFrom.
	 */
	private function ended( JobContext $context, string $reason, string $check = '', bool $undone = false ): \RuntimeException {
		if ( 0 !== $context->job()->cancel_requested ) {
			return new Cancelled( 'The restore was cancelled; the site is as it was before it.' );
		}
		$text = self::REASONS[ $reason ] ?? $reason;
		if ( $undone ) {
			// What was written on the restored site since it was let out went with it, so nothing offers it as a retry:
			// a retry from the final check would also fail (the copy is no longer what the import left).
			// The message stays short (the job's progress message holds it); the reason is in the log (reverse()).
			$context->logger()->info( 'The restore was undone', array( 'reason' => $text ) );
			/* translators: %d: days the restored copy is kept */
			return new Undone( sprintf( __( 'The restore was undone: the site is as it was before it. The restored copy is kept for %d days after the undo.', 'wp-checkpoint' ), (int) ( JobRepository::WORK_RETENTION_SECONDS / 86400 ) ) );
		}
		if ( self::REVERSED === $reason || self::UNDONE === $reason ) {
			// The restored copy is kept under its temporary names, next to the site, for a retry (the engine reclaims
			// it with the failed job's work after WORK_RETENTION_SECONDS); the retry checks and swaps again.
			$what = '' !== $check && isset( self::CHECKS[ $check ] ) ? ' What failed: ' . self::CHECKS[ $check ] . '.' : '';
			return new RetryFrom( trim( $text . $what . ' The swap was reversed; the restored copy is kept for a retry, which checks the site again.' ), SwapCheckStep::ID );
		}
		return new RetryFrom( trim( $text . ' The swap was rolled back: the site is as it was before the restore.' ), SwapCheckStep::ID );
	}

	/**
	 * Swap a swap that was made back out: the direction recorded first ("reverse", with the reason; from here on no
	 * run goes forward again), then the site put back from what is there exactly as the rollback does it (the
	 * maintenance file held before the first rename, the directories and the tables in reverse plan order, "restored"
	 * recorded while the file is held, "reverted" once it is let go, the file taken down), and the job ended with
	 * the reason. Called from the "forget" post-swap step while the file is still held (nothing of the restored site
	 * was served): after a failed verdict, and after one that could not tell when the policy says undo; and from
	 * the "ask" step when the answer is undo (the site was let out: $out). There is no undo of a job that ended yet
	 * (4c-C).
	 *
	 * @param JobContext           $context Context.
	 * @param Queries              $db      Connection.
	 * @param array<string, mixed> $cursor  Cursor (its attempt and mark).
	 * @param string               $reason  Why: one of REASONS.
	 * @param string               $check   The check that failed (an id of CHECKS), or ''.
	 * @param bool                 $out     Whether the site was let out before (an undo answered after the release):
	 *                                      recorded, so that a run that takes over waits for another maintenance file
	 *                                      too (hold_for_renames()).
	 * @return \RuntimeException What ends the job, for the caller to throw.
	 * @throws TransientFailure When what is there cannot be told (the reverse goes on later).
	 */
	private function reverse( JobContext $context, Queries $db, array $cursor, string $reason, string $check = '', bool $out = false ): \RuntimeException {
		if ( ! isset( self::REASONS[ $reason ] ) ) {
			$reason = self::REVERSED;
		}
		$cursor = array(
			'phase'   => 'reverse',
			'attempt' => $cursor['attempt'],
			'mark'    => $cursor['mark'] ?? '',
			'reason'  => $reason,
			'check'   => isset( self::CHECKS[ $check ] ) ? $check : '',
		);
		if ( $out ) {
			$cursor['out'] = true;
		}
		$context->checkpoint( $cursor, 88, __( 'Putting the site back as it was', 'wp-checkpoint' ) );
		$context->logger()->warning(
			'The swap is reversed after it was made',
			array(
				'reason' => self::REASONS[ $reason ],
				'check'  => '' === $cursor['check'] ? '' : self::CHECKS[ $cursor['check'] ],
			)
		);
		$this->at( 'reverse_recorded' );
		return $this->roll_back( $context, $db, $cursor, $reason );
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
	 * @throws \RuntimeException When the position is not one this version wrote (not FINAL: the job holds the site).
	 */
	private function after( JobContext $context, Queries $db, array $cursor ): StepResult {
		$entries = null;
		while ( true ) {
			switch ( (string) ( $cursor['post'] ?? '' ) ) {
				case 'cache':
					// Before anything reads the site through WordPress (this process holds the old site's options), and
					// before visitors can: the maintenance file is still held, so a flush that has to wait does so behind it.
					$this->flush( $context );
					$this->at( 'flushed' );
					$cursor['post'] = 'verify';
					break;
				case 'verify':
					// Where the restored site is checked before it is let out, behind the held file. Each run that checks
					// writes the self-check row first (Restore\SelfCheck: a new one-time value and the hash of a new pass
					// key, in the options table swapped in, in its fenced transaction), so that the self-check route
					// answers this run and no earlier one, then the hash of the same key into the maintenance file's data
					// file (held as before), so that the file lets this run's requests through and no earlier run's. Then
					// the checks (Restore\SiteCheck, through the web server; a "verify" part in tests). What could not be
					// told is checked again, at most CHECK_TRIES times, after the waits of CHECK_WAITS, each try in a
					// tick of its own. The verdict is made durable before anything acts on it: a run that dies after it
					// is continued by one that reads the verdict, not by one that checks again (the second check may be
					// unable to decide, and a site found broken would stay).
					if ( ! isset( $cursor['verdict'] ) ) {
						$key   = SelfCheck::random();
						$nonce = SelfCheck::random();
						$fence = $context->fence();
						SelfCheck::put( $db, $fence, SelfCheck::table( self::base_prefix() ), SelfCheck::value( $fence['id'], $nonce, hash( 'sha256', $key ) ) );
						$this->let_through( $context, $this->maintenance( $cursor ), hash( 'sha256', $key ) );
						$this->at( 'self_check_written' );
						$found = $this->check_site( $context, $db, $key, $nonce );
						$this->at( 'verified' );
						$tries = (int) ( $cursor['tries'] ?? 0 ) + 1;
						if ( SiteCheck::UNKNOWN === $found['verdict'] && $tries < self::CHECK_TRIES ) {
							$cursor['tries']  = $tries;
							$cursor['unsure'] = self::finding( $found );
							$context->checkpoint( $cursor, 86, __( 'Checking the restored site', 'wp-checkpoint' ) );
							$wait = self::CHECK_WAITS[ $tries - 1 ];
							$text = self::unchecked_text( $cursor['unsure'] );
							$context->logger()->info(
								'The restored site could not be checked yet; it is checked again',
								array(
									'try'   => $tries,
									'found' => $text,
									'wait'  => $wait,
								)
							);
							/* translators: 1: what was found, 2: seconds, 3: this try, 4: the most tries */
							return StepResult::wait( $wait, $cursor, sprintf( __( 'The restored site could not be checked yet (%1$s); it is checked again in %2$d seconds (try %3$d of %4$d).', 'wp-checkpoint' ), $text, $wait, $tries, self::CHECK_TRIES ) );
						}
						unset( $cursor['tries'], $cursor['unsure'] );
						$cursor['verdict'] = $found['verdict'];
						$cursor['check']   = SiteCheck::PASSED === $found['verdict'] ? '' : self::check_id( $found );
						if ( SiteCheck::UNKNOWN === $found['verdict'] ) {
							$cursor['unverified'] = self::finding( $found );
						}
						$context->checkpoint( $cursor, 86, __( 'Checking the restored site', 'wp-checkpoint' ) );
						$this->at( SiteCheck::FAILED === $found['verdict'] ? 'verify_failed' : 'verdict_recorded' );
					}
					$cursor['post'] = 'forget';
					break;
				case 'forget':
					// The self-check row goes once the verdict is recorded and before anything acts on it (the reverse,
					// the release): a step of its own, done again by a run that takes over after the verdict (none there
					// is done), so that no outcome leaves it in the site's options table. The maintenance file stops
					// letting the checks through first (held, with no pass).
					$this->refresh( $context, $this->maintenance( $cursor ), true );
					SelfCheck::remove( $db, $context->fence(), SelfCheck::table( self::base_prefix() ) );
					$this->at( 'self_check_removed' );
					if ( SiteCheck::FAILED === ( $cursor['verdict'] ?? '' ) ) {
						throw $this->reverse( $context, $db, $cursor, self::REVERSED, (string) ( $cursor['check'] ?? '' ) );
					}
					if ( SiteCheck::UNKNOWN === ( $cursor['verdict'] ?? '' ) ) {
						$context->logger()->warning( 'The restored site could not be checked', array( 'found' => self::unchecked_text( (array) ( $cursor['unverified'] ?? array() ) ) ) );
						if ( RestoreJob::UNDO === $this->unverified_choice( $context, false ) ) {
							// Said up front (the restore policy): undone behind the held file, nothing of the restored site served.
							throw $this->reverse( $context, $db, $cursor, self::UNDONE );
						}
					}
					unset( $cursor['verdict'], $cursor['check'] );
					$cursor['post'] = 'release';
					break;
				case 'release':
					// The swap is recorded as made and the cache is flushed: the maintenance file lapses again, refreshed
					// step by step from here on.
					$this->release( $context, $this->maintenance( $cursor ) );
					$this->at( 'unheld_commit' );
					$cursor['post'] = 'rewrite';
					break;
				case 'rewrite':
					$entries = $entries ?? $this->entries( $context, $db, $cursor );
					$this->refresh( $context, $this->maintenance( $cursor ), false );
					foreach ( $entries['tables'] as $entry ) {
						if ( SwapPlan::TABLE_OF === $entry['kind'] && self::is_options( $entry['live'] ) ) {
							$context->confirm_lease();
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
					$context->confirm_lease();
					$db->write(
						'INSERT INTO ' . SqlWriter::identifier( self::base_prefix() . 'options' ) . ' (option_name, option_value, autoload) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)',
						array( StoredNames::AFTER_SWAP, (string) $this->now(), 'yes' )
					);
					$this->at( 'cron' );
					$cursor['post'] = isset( $cursor['unverified'] ) ? 'ask' : 'exit';
					break;
				case 'ask':
					// The restored site could not be checked: it is let out (the file let go, the rewrite rules and the
					// cron mark written, the file taken down), and whether to keep it is asked before the end, unless the
					// policy or an answer says it. Undone after it was let out: what was written on it since is gone (the question says so),
					// and the file is held again before the first rename (reverse()).
					$choice = $this->unverified_choice( $context, true );
					if ( RestoreJob::UNDO === $choice && Maintenance::OTHER === $this->maintenance( $cursor )->state() ) {
						// The site is live: someone else's file (an update in progress, or one left by an update that
						// stopped, or by an earlier restore) is waited out before the undo is recorded, so nothing is
						// counted against it and the answer is read again on the next tick.
						$left = $this->release_left( $context );
						if ( JobActions::LEFT_GONE !== $left['outcome'] ) {
							return StepResult::wait( 60, $cursor, self::other_file( $left ) );
						}
					}
					if ( RestoreJob::UNDO === $choice ) {
						$context->logger()->warning( 'The restored site could not be checked; the swap is undone as chosen' );
						throw $this->reverse( $context, $db, $cursor, self::UNDONE, '', true );
					}
					if ( RestoreJob::KEEP !== $choice ) {
						// Open to visitors while it waits for the answer: the file let go would still answer with the
						// maintenance page for up to ten minutes, so it comes down now (again on the way out).
						$this->flush( $context );
						$this->take_down( $context, $cursor );
						$this->at( 'asked' );
						return StepResult::ask(
							$cursor,
							array(
								array(
									'id'      => self::UNVERIFIED_QUESTION,
									'kind'    => self::UNVERIFIED_KIND,
									'choices' => array( RestoreJob::KEEP, RestoreJob::UNDO ),
								),
							),
							__( 'Waiting for your decision on 1 question', 'wp-checkpoint' )
						);
					}
					$context->logger()->warning( 'The restored site could not be checked; it is kept as chosen' );
					unset( $cursor['unverified'] );
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
					return $this->completed( $context );
				default:
					throw new \RuntimeException( 'The position of the swap is not one this version wrote; the job row was changed. Nothing was changed by this run: look at the site before you go on.' ); // Not FINAL: the job may hold the site.
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
		// The site the plan was written for is this one: the WordPress directory and the table prefix as they are now.
		$real = Paths::real( (string) ( $this->parts['abspath'] ?? ABSPATH ) );
		$site = $entries['site'][0] ?? null;
		if ( array() === $entries['site'] ) {
			throw new SiteChanged( 'The swap\'s plan was written by an earlier version of WP Checkpoint, which did not record the site it was written for. The swap was refused before it changed anything, and its plan is kept as it was written. Start the restore again.' );
		}
		if ( 1 !== count( $entries['site'] ) || 0 !== ( $site['seq'] ?? -1 ) || false === $real || rtrim( Paths::normalize( (string) $real ), '/' ) !== $site['live'] || self::base_prefix() !== $site['stage'] ) {
			throw new SiteChanged( 'The swap\'s plan was written for a WordPress directory or table prefix other than this site\'s now. The swap was refused before it changed anything, and its plan is kept as it was written. Start the restore again.' );
		}
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
	 * The plan's entries of the attempt, in order: the site entry (SwapPlan::SITE), the directory units and the table
	 * entries.
	 *
	 * @param JobContext           $context Context.
	 * @param Queries              $db      Connection.
	 * @param array<string, mixed> $cursor  Cursor (its attempt).
	 * @return array{site: array<int, array{seq: int, kind: string, live: string, stage: string, old: string, had_live: bool}>, dirs: array<int, array{seq: int, kind: string, live: string, stage: string, old: string, had_live: bool}>, tables: array<int, array{seq: int, kind: string, live: string, stage: string, old: string, had_live: bool}>}
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
			'site'   => array(),
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
				$out[ SwapPlan::SITE === $entry['kind'] ? 'site' : ( SwapPlan::DIR === $entry['kind'] ? 'dirs' : 'tables' ) ][] = $entry;
				$after = $entry['seq'];
			}
			if ( count( $page ) < self::PAGE ) {
				break;
			}
		}
		if ( count( $out['site'] ) + count( $out['dirs'] ) + count( $out['tables'] ) !== $count ) {
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
	 * Rename a directory of the swap going forward, right after a lease check. A failure stops the swap: the caller
	 * rolls it back in the same tick.
	 *
	 * @param JobContext $context Context.
	 * @param string     $from    From.
	 * @param string     $to      To.
	 * @return void
	 * @throws \RuntimeException When the rename failed.
	 */
	private function rename( JobContext $context, string $from, string $to ): void {
		if ( null !== $this->move( $context, $from, $to ) ) {
			throw new \RuntimeException( sprintf( 'Moving %1$s to %2$s failed.', $from, $to ) );
		}
	}

	/**
	 * Rename a directory of the rollback, right after a lease check. rename() gives no reliable error code (only
	 * PHP's warning, in the words of the system), so every failure here is taken as one of the moment: the Runner
	 * tries again after its back-off, a bounded number of times, then fails the job as one to retry (it still holds
	 * the site, the maintenance file stays held, nothing is recorded as put back). Each try decides again from what
	 * is there (SwapRules::dir_back()), so a rename that was made but reported as failed (as on NFS) is not made a
	 * second time.
	 *
	 * @param JobContext $context Context.
	 * @param string     $from    From.
	 * @param string     $to      To.
	 * @return void
	 * @throws TransientFailure When the rename failed, with PHP's warning.
	 */
	private function rename_back( JobContext $context, string $from, string $to ): void {
		$warning = $this->move( $context, $from, $to );
		if ( null !== $warning ) {
			throw new TransientFailure( sprintf( 'Moving %1$s back to %2$s failed: %3$s', $from, $to, $warning ) );
		}
	}

	/**
	 * Rename right after a lease check, with PHP's warning kept instead of shown.
	 *
	 * @param JobContext $context Context.
	 * @param string     $from    From.
	 * @param string     $to      To.
	 * @return string|null Null when it was renamed; otherwise PHP's warning (or that there was none).
	 */
	private function move( JobContext $context, string $from, string $to ): ?string {
		$move    = $this->parts['rename'] ?? null;
		$warning = '';
		$keep    = static function ( int $number, string $text ) use ( &$warning ): bool {
			$warning = $text;
			return true;
		};
		$context->confirm_lease();
		set_error_handler( $keep ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- the warning is kept for the job's error.
		try {
			$moved = is_callable( $move ) ? (bool) call_user_func( $move, $from, $to ) : rename( $from, $to );
		} finally {
			restore_error_handler();
		}
		if ( $moved ) {
			return null;
		}
		return '' !== $warning ? $warning : 'PHP gave no reason';
	}

	/**
	 * Refresh this restore's maintenance file as far as it can be, held (during the rollback, before each directory
	 * rename) or with the time (after the swap is made, between the steps that follow it): someone else's in its
	 * place, or a failed write, does not stop the step (it is logged); with the time, none is made where there is
	 * none.
	 *
	 * @param JobContext  $context Context.
	 * @param Maintenance $file    The file.
	 * @param bool        $held    Whether it is held (the site half swapped) or refreshed with the time.
	 * @param bool        $out     Whether the rollback is a reverse of a site that was let out: someone else's file
	 *                             in its place is waited out then (TransientFailure), not only logged.
	 * @return void
	 * @throws LockLost When the lease is gone (not caught).
	 * @throws StaleJob When the job is no longer this run's (not caught).
	 * @throws TransientFailure When the site was let out and someone else's file is in place.
	 */
	private function refresh( JobContext $context, Maintenance $file, bool $held, bool $out = false ): void {
		if ( ! $held && Maintenance::NONE === $file->state() ) {
			return; // None of ours to refresh: none is made.
		}
		if ( $out && Maintenance::OTHER === $file->state() ) {
			// A reverse of a site that was let out: someone else's file put up meanwhile (an update started) is waited
			// out before the next rename, as before the first one (hold_for_renames()).
			throw new TransientFailure( 'Another maintenance file is in place (an update in progress, for example); the undo waits for it to go before it moves anything more.' );
		}
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
	 * Check the restored site (Restore\SiteCheck), or ask the "verify" part (tests: true passed, a check id failed,
	 * or SiteCheck's own result).
	 *
	 * @param JobContext $context Context.
	 * @param Queries    $db      Connection.
	 * @param string     $key     The pass key.
	 * @param string     $nonce   The one-time value of the self-check row.
	 * @return array{verdict: string, check: string, why: string, status: int}
	 */
	private function check_site( JobContext $context, Queries $db, string $key, string $nonce ): array {
		if ( isset( $this->parts['verify'] ) ) {
			$said = call_user_func(
				$this->parts['verify'],
				$context,
				array(
					'key'   => $key,
					'nonce' => $nonce,
				)
			);
			if ( is_array( $said ) ) {
				return array(
					'verdict' => (string) ( $said['verdict'] ?? SiteCheck::UNKNOWN ),
					'check'   => (string) ( $said['check'] ?? '' ),
					'why'     => (string) ( $said['why'] ?? '' ),
					'status'  => (int) ( $said['status'] ?? 0 ),
				);
			}
			return array(
				'verdict' => true === $said ? SiteCheck::PASSED : SiteCheck::FAILED,
				'check'   => true === $said ? '' : ( is_string( $said ) && isset( self::CHECKS[ $said ] ) ? $said : 'unknown' ),
				'why'     => '',
				'status'  => 0,
			);
		}
		$urls = $this->check_urls( $db );
		if ( null === $urls ) {
			return array(
				'verdict' => SiteCheck::UNKNOWN,
				'check'   => 'home',
				'why'     => 'no_address',
				'status'  => 0,
			);
		}
		// The lease is confirmed (and extended) before every request and every wait: a request may take its timeout,
		// and the checks make a few dozen at most.
		$http  = isset( $this->parts['http'] ) ? $this->parts['http'] : array( $this, 'http' );
		$check = new SiteCheck(
			static function ( string $url, array $headers ) use ( $context, $http ): array {
				$context->confirm_lease();
				return (array) call_user_func( $http, $url, $headers );
			},
			function ( int $seconds ) use ( $context ): void {
				$context->confirm_lease();
				$this->sleep( $seconds );
			}
		);
		return $check->check( $urls, $key, $nonce, SelfCheck::random() );
	}

	/**
	 * Where the checks go (addresses()), from the options table swapped in unless wp-config.php defines WP_HOME or
	 * WP_SITEURL (WordPress then answers those options with the constants: _config_wp_home()). Null when the options
	 * cannot be read.
	 *
	 * @param Queries $db Connection.
	 * @return array{home: string, login: string, self: string}|null
	 */
	private function check_urls( Queries $db ) {
		if ( isset( $this->parts['urls'] ) ) {
			return (array) call_user_func( $this->parts['urls'] );
		}
		$table = SqlWriter::identifier( self::base_prefix() . 'options' );
		$read  = static function ( string $name ) use ( $db, $table ): string {
			$rows = $db->rows( 'SELECT option_value FROM ' . $table . ' WHERE option_name = ?', array( $name ) );
			return (string) ( $rows[0][0] ?? '' );
		};
		try {
			return self::addresses( defined( 'WP_HOME' ) ? (string) get_option( 'home' ) : null, defined( 'WP_SITEURL' ) ? (string) get_option( 'siteurl' ) : null, $read, substr( SelfCheck::random(), 0, 16 ) );
		} catch ( \RuntimeException $e ) {
			return null;
		}
	}

	/**
	 * The home page and wp-login.php of the restored site, and the self-check route (?rest_route=, whatever the
	 * permalinks), each with a parameter no cache has seen; the constants' values when they are given (WP_HOME,
	 * WP_SITEURL), else the options read. Null when either is not an http(s) address.
	 *
	 * @param string|null $home  WP_HOME's value, or null when it is not defined.
	 * @param string|null $site  WP_SITEURL's value, or null when it is not defined.
	 * @param callable    $read  function( string $option ): string, from the options table swapped in.
	 * @param string      $fresh The cache-busting value.
	 * @return array{home: string, login: string, self: string}|null
	 */
	public static function addresses( $home, $site, callable $read, string $fresh ) {
		$home = null === $home ? (string) call_user_func( $read, 'home' ) : $home;
		$site = null === $site ? (string) call_user_func( $read, 'siteurl' ) : $site;
		if ( 1 !== preg_match( '#\Ahttps?://#i', $home ) || 1 !== preg_match( '#\Ahttps?://#i', $site ) ) {
			return null;
		}
		$fresh = 'wpcheckpoint_check=' . $fresh;
		return array(
			'home'  => rtrim( $home, '/' ) . '/?' . $fresh,
			'login' => rtrim( $site, '/' ) . '/wp-login.php?' . $fresh,
			'self'  => rtrim( $home, '/' ) . '/?rest_route=' . SelfCheck::PATH . '&' . $fresh,
		);
	}

	/**
	 * One request of the checks, redirects not followed (SiteCheck follows those to the same host).
	 *
	 * @param string                $url     URL.
	 * @param array<string, string> $headers Headers.
	 * @return array{status: int, body: string, location: string} status 0 when no answer came.
	 */
	public function http( string $url, array $headers ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'redirection'         => 0,
				'headers'             => $headers,
				'sslverify'           => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as its loopback requests use it.
				'limit_response_size' => 262144,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'status'   => 0,
				'body'     => '',
				'location' => '',
			);
		}
		return array(
			'status'   => (int) wp_remote_retrieve_response_code( $response ),
			'body'     => (string) wp_remote_retrieve_body( $response ),
			'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
		);
	}

	/**
	 * What a check found, as the cursor keeps it (ids and a status, nothing of the site's).
	 *
	 * @param array{check: string, why: string, status: int} $found SiteCheck's result.
	 * @return array{check: string, why: string, status: int}
	 */
	private static function finding( array $found ): array {
		return array(
			'check'  => (string) $found['check'],
			'why'    => (string) $found['why'],
			'status' => (int) $found['status'],
		);
	}

	/**
	 * The CHECKS id of a failed check.
	 *
	 * @param array{check: string, why: string} $found SiteCheck's result.
	 * @return string
	 */
	private static function check_id( array $found ): string {
		$id = $found['check'] . '_' . ( 'wrong_answer' === $found['why'] ? 'wrong' : $found['why'] );
		if ( isset( self::CHECKS[ $id ] ) ) {
			return $id;
		}
		return isset( self::CHECKS[ $found['check'] ] ) ? $found['check'] : 'unknown';
	}

	/**
	 * What a check that could not tell found, in words.
	 *
	 * @param array<string, mixed> $found finding().
	 * @return string
	 */
	public static function unchecked_text( array $found ): string {
		$what   = 'self' === ( $found['check'] ?? '' ) ? __( 'the plugin\'s self-check', 'wp-checkpoint' ) : ( 'login' === ( $found['check'] ?? '' ) ? __( 'wp-login.php', 'wp-checkpoint' ) : __( 'the home page', 'wp-checkpoint' ) );
		$status = (int) ( $found['status'] ?? 0 );
		switch ( (string) ( $found['why'] ?? '' ) ) {
			case 'none':
				/* translators: %s: the page checked */
				return sprintf( __( '%s did not answer (no connection, or no answer in time)', 'wp-checkpoint' ), $what );
			case 'other_host':
				/* translators: %s: the page checked */
				return sprintf( __( '%s redirected to another address (another host, or https), where the maintenance page cannot be passed', 'wp-checkpoint' ), $what );
			case 'redirects':
				/* translators: %s: the page checked */
				return sprintf( __( '%s redirected too many times', 'wp-checkpoint' ), $what );
			case 'fatal_once':
				/* translators: %s: the page checked */
				return sprintf( __( '%s answered with WordPress\'s fatal error page once, and otherwise 15 seconds later', 'wp-checkpoint' ), $what );
			case 'no_route':
				return __( 'the plugin\'s self-check was not found (WP Checkpoint not active on the restored site, or a version without it)', 'wp-checkpoint' );
			case 'old_answer':
				return __( 'the plugin\'s self-check gave an answer to an earlier question (a cache in front of the site)', 'wp-checkpoint' );
			case 'no_address':
				return __( 'the restored site\'s address could not be read (home and siteurl)', 'wp-checkpoint' );
			case 'status':
				/* translators: 1: the page checked, 2: HTTP status */
				return sprintf( __( '%1$s answered HTTP %2$d', 'wp-checkpoint' ), $what, $status );
			default:
				return __( 'the checks could not tell whether the restored site works', 'wp-checkpoint' );
		}
	}

	/**
	 * What to do with a restored site that could not be checked: the answer to the question (when $answers), else the
	 * restore policy ("keep", "undo", or "ask").
	 *
	 * @param JobContext $context Context.
	 * @param bool       $answers Whether an answer counts.
	 * @return string
	 */
	private function unverified_choice( JobContext $context, bool $answers ): string {
		$options = $context->options();
		if ( $answers && isset( $options['answers'][ self::UNVERIFIED_QUESTION ] ) && is_string( $options['answers'][ self::UNVERIFIED_QUESTION ] ) ) {
			return $options['answers'][ self::UNVERIFIED_QUESTION ];
		}
		try {
			return (string) ( RestoreJob::options( $options )['policy'][ RestoreJob::UNVERIFIED ] ?? 'ask' );
		} catch ( \InvalidArgumentException $e ) {
			return 'ask'; // Validated when the restore started; whatever it says now, the question decides.
		}
	}

	/**
	 * Hold this restore's maintenance file with the hash of this run's pass key, so that it lets the checks' requests
	 * through. A file that cannot be written now (someone else's in its place, or what is there cannot be told) is
	 * logged and left: the checks are then blocked like any visitor and can only say they could not check.
	 *
	 * @param JobContext  $context   Context.
	 * @param Maintenance $file      The file.
	 * @param string      $pass_hash SHA-256 of the pass key.
	 * @return void
	 * @throws LockLost When the lease is gone (and StaleJob).
	 */
	private function let_through( JobContext $context, Maintenance $file, string $pass_hash ): void {
		try {
			$file->hold( array( $context, 'confirm_lease' ), $pass_hash );
		} catch ( LockLost $e ) {
			throw $e;
		} catch ( StaleJob $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			$context->logger()->warning( 'The maintenance file could not be made to let the checks through', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Let this restore's maintenance file lapse again once the direction is recorded ("committed" or "restored",
	 * while it was held): it is rewritten with the time, so a run that dies before it takes the file down leaves a
	 * file that lapses after WordPress's ten minutes, not one held for good. Someone else's file in its place is
	 * left, and none is made where there is none. What cannot be told or written is waited out: until then the job
	 * is recorded as holding the site (changing it, or swapped), and every WP-CLI command of the plugin says so.
	 *
	 * @param JobContext  $context Context.
	 * @param Maintenance $file    The file.
	 * @return void
	 * @throws TransientFailure When it could not be rewritten.
	 */
	private function release( JobContext $context, Maintenance $file ): void {
		$state = $file->state();
		if ( Maintenance::NONE === $state ) {
			return; // None of ours: nothing to let go, and none is made.
		}
		if ( Maintenance::OTHER === $state ) {
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
	 * The carry of this plugin's state, on the swap's connection.
	 *
	 * @param Queries $db Connection.
	 * @return StateCarry
	 */
	private function state_carry( Queries $db ): StateCarry {
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
	 * @throws \RuntimeException When the cursor has no mark (not FINAL: the job may hold the site).
	 */
	private function maintenance( array $cursor ): Maintenance {
		if ( ! isset( $cursor['mark'] ) || ! is_string( $cursor['mark'] ) || '' === $cursor['mark'] ) {
			throw new \RuntimeException( 'The position of the swap is not one this version wrote; the job row was changed. Nothing was changed by this run: look at the site before you go on.' ); // Not FINAL: the job may hold the site.
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
	 * @throws TransientFailure When it fails (it is tried again).
	 */
	private function flush( JobContext $context ): void {
		try {
			$flushed = isset( $this->parts['flush'] ) ? call_user_func( $this->parts['flush'] ) : wp_cache_flush();
		} catch ( LockLost $e ) {
			throw $e;
		} catch ( StaleJob $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			// The site is whole at every flush; what it may serve from the cache is waited out, not given up on.
			throw new TransientFailure( 'The object cache could not be flushed (the site shows the maintenance page until it is): ' . $e->getMessage() . ' If this goes on, get the object cache (its drop-in or its server) working, then retry the job.' );
		}
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
		$file    = $this->maintenance( $cursor );
		$confirm = array( $context, 'confirm_lease' );
		$removed = isset( $this->parts['remove'] ) ? (bool) call_user_func( $this->parts['remove'], $file, $confirm ) : $file->remove( $confirm );
		if ( ! $removed ) {
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
	 * The swap's last word, on every way it ends with the restored site in place: what the restore's collation check
	 * recorded on the job (JobContext::record()), when it recorded a count above zero and it can be read; nothing is
	 * said otherwise. A swap that put the site back does not come here.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 */
	private function completed( JobContext $context ): StepResult {
		$text     = __( 'The restored site is in place', 'wp-checkpoint' );
		$recorded = $context->options()['recorded']['collations_mapped'] ?? null;
		if ( is_int( $recorded ) && $recorded > 0 ) {
			$text .= ' ' . sprintf(
				/* translators: %d: how many collations */
				_n( '%d collation the backup uses was written under a name this server knows; the job log says which.', '%d collations the backup uses were written under names this server knows; the job log says which.', $recorded, 'wp-checkpoint' ),
				$recorded
			);
		}
		return StepResult::done( $text );
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
