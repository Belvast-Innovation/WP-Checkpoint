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
 *
 * The redo: an undone restore swapped back in by the plan its swap was made
 * by (redo_cursor(): "redo", with the time of the undo, kept on every cursor
 * of it). Before it changes anything it checks again what can change while
 * it waits (Redo::again()) and gets ready as a swap does (ready()), skipping
 * what only holds for a copy just imported (the counts, the judgement);
 * then "enter" (recorded only with no cancel requested), the maintenance
 * file, this plugin's state carried into the restored copy before anything
 * is renamed (carry_in(): when that fails, the file comes down and it asks,
 * "redo_ask"), the directories and the tables as a swap, and from
 * "committed" on a swap's end; a check that fails swaps it back out. A redo
 * that does not stand ends undone again as of the undo it set out from
 * (Undone::back()): given up before "enter", reversed after its check, or
 * rolled back after it stopped. An undo whose site as put back fails its
 * check, or cannot be checked, asks with the site open whether to keep it
 * or swap the restored copy back in ("undone_ask", ask_after_undo()).
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
	const INTERRUPTED     = 'interrupted';
	const INCOMPLETE      = 'incomplete';
	const STOPPED         = 'stopped';
	const REVERSED        = 'reversed';
	const UNDONE          = 'undone';
	const ASKED           = 'asked';
	const PUT_BACK_REASON = 'putback';
	const REASONS         = array(
		self::INTERRUPTED     => 'The swap was interrupted before it was complete.',
		self::INCOMPLETE      => 'After the renames, not every table of the plan was in its place.',
		self::STOPPED         => 'The swap stopped (the job log says why).',
		self::REVERSED        => 'A check of the restored site found it broken: the site was put back as it was before the restore.',
		self::UNDONE          => 'The restored site could not be checked, and the swap was undone as chosen: the site was put back as it was before the restore.',
		self::ASKED           => 'The restore was undone as asked (wp wpcheckpoint job undo): the site was put back as it was before the restore.',
		self::PUT_BACK_REASON => 'The site was put back as it was before the restore, as asked (wp wpcheckpoint job put-back).',
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
	 * The question asked when this plugin's own state could not be carried back on an undo after the restored site was
	 * let out (carry_back()), before anything was put back: put the site back without it (PUT_BACK: the rows listed in
	 * RestoreFiles::CARRY_BACK are lost), or keep the restored site and give the undo up (RestoreJob::KEEP). No
	 * default: nothing answers it but the administrator.
	 */
	const CARRY_QUESTION = 'carry_back';
	const CARRY_KIND     = 'carry_back_failed';
	const PUT_BACK       = 'put_back';

	/**
	 * The question asked when this plugin's own state could not be carried into the restored copy on a redo (wp
	 * wpcheckpoint job redo), before anything was renamed: swap the restored copy back in without it (REDO: the rows
	 * listed in RestoreFiles::REDO_CARRY are lost), or give the redo up (RestoreJob::KEEP: the site stays as the undo
	 * left it). No default. The answer to swap it back in counts only while the rows that would be lost are the ones
	 * listed: the list is read again right before it is acted on (carry_in()).
	 */
	const REDO_CARRY_QUESTION = 'redo_carry';
	const REDO_CARRY_KIND     = 'redo_carry_failed';
	const REDO                = 'redo';

	/**
	 * The question asked when the site an undo put back failed its check or could not be checked (not after a
	 * put-back): keep it (RestoreJob::KEEP), or swap the restored copy back in (REDO, the redo's own way, every check of
	 * it made). Asked with the site open and the maintenance file down (RestoreFiles::AFTER_UNDO says what was found).
	 */
	const AFTER_UNDO_QUESTION = 'after_undo';
	const AFTER_UNDO_KIND     = 'undone_site_failed';

	/**
	 * The answers a redo asks anew: what the swap, its undo and an earlier redo were answered.
	 */
	const REDO_FORGETS = array( self::UNVERIFIED_QUESTION, self::CARRY_QUESTION, self::REDO_CARRY_QUESTION, self::AFTER_UNDO_QUESTION );

	/**
	 * How a redo that did not stand ended (redo_outcome()): given up before it changed the site, swapped back out after
	 * its check, or rolled back after it stopped.
	 */
	const REDO_GIVEN_UP    = 'given_up';
	const REDO_REVERSED    = 'reversed';
	const REDO_ROLLED_BACK = 'rolled_back';

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
	const SWAPPED  = array( 'committed', 'done', 'carry_ask', 'undo' );

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
	 * The cursor an undone restore is swapped back in from (wp wpcheckpoint job redo, JobActions::redo(); or an answer
	 * to swap it back in, undone_asked()): "redo", a position of the untouched site, with the time of the undo it sets
	 * out from. That time stays on every cursor of the redo until it ends (redo_at()): a redo that does not stand ends
	 * undone as of that time (Undone::back()). Its JSON begins with the phase (JobRepository::cancel_redo() finds a redo
	 * that has not started by it).
	 *
	 * @param int    $attempt   The plan's attempt the swap was made by.
	 * @param string $mark      The job's maintenance file mark (Job::$site_mark).
	 * @param int    $undone_at The time of the undo.
	 * @return array<string, mixed>
	 */
	public static function redo_cursor( int $attempt, string $mark, int $undone_at ): array {
		return array(
			'phase'     => 'redo',
			'attempt'   => $attempt,
			'mark'      => $mark,
			'undone_at' => $undone_at,
		);
	}

	/**
	 * The time of the undo a redo's cursor sets out from, or 0 when the cursor is not a redo's (an undo's question
	 * about the site it put back holds the time of that undo, and is not a redo yet).
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @return int
	 */
	public static function redo_at( array $cursor ): int {
		if ( 'undone_ask' === ( $cursor['phase'] ?? '' ) ) {
			return 0;
		}
		return isset( $cursor['undone_at'] ) && is_int( $cursor['undone_at'] ) ? max( 0, $cursor['undone_at'] ) : 0;
	}

	/**
	 * Whether a cursor is a redo before it changed the site ("redo", "redo_ask") or an undo's question about the site it
	 * put back ("undone_ask"): the site is untouched there, and the job ends undone, never failed, once the time a redo
	 * can be made in runs out (JobRepository::reap()).
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @return bool
	 */
	public static function before_redo( array $cursor ): bool {
		return in_array( (string) ( $cursor['phase'] ?? '' ), array( 'redo', 'redo_ask', 'undone_ask' ), true ) && (int) ( $cursor['undone_at'] ?? 0 ) > 0;
	}

	/**
	 * How a restore that is undone again after a redo ended, by its cursor at the end: REDO_GIVEN_UP (before it changed
	 * the site), REDO_REVERSED (swapped back out after its check found the site broken, or could not tell and the
	 * restore's policy said undo), REDO_ROLLED_BACK (stopped or interrupted, put back); '' for any other job.
	 *
	 * @param Job $job Job, as it ended.
	 * @return string
	 */
	public static function redo_outcome( Job $job ): string {
		if ( self::ID !== $job->step || Job::UNDONE !== $job->status || self::redo_at( $job->cursor ) <= 0 ) {
			return '';
		}
		if ( in_array( (string) ( $job->cursor['phase'] ?? '' ), array( 'redo', 'redo_ask' ), true ) ) {
			return self::REDO_GIVEN_UP;
		}
		return in_array( (string) ( $job->cursor['reason'] ?? '' ), array( self::REVERSED, self::UNDONE ), true ) ? self::REDO_REVERSED : self::REDO_ROLLED_BACK;
	}

	/**
	 * The redo's keys of a cursor, to keep on the next one: the time of the undo it sets out from.
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @return array<string, int>
	 */
	private static function redo_keys( array $cursor ): array {
		$at = self::redo_at( $cursor );
		return $at > 0 ? array( 'undone_at' => $at ) : array();
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
			if ( 'carry' === $phase && self::redo_at( $cursor ) > 0 && ! empty( $cursor['again'] ) ) {
				// A redo's carry that failed for a moment (carry_in()), nothing renamed yet: tried again, the file held.
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
			if ( 'undo' === $phase ) {
				return $this->start_undo( $context, $db, $cursor );
			}
			if ( 'redo' === $phase ) {
				return $this->redo( $context, $db, $cursor );
			}
			if ( 'redo_ask' === $phase ) {
				return $this->redo_asked( $context, $db, $cursor );
			}
			if ( 'undone_ask' === $phase ) {
				return $this->undone_asked( $context, $db, $cursor );
			}
			if ( 'carry_ask' === $phase ) {
				return $this->carry_asked( $context, $db, $cursor );
			}
			if ( 'restored' === $phase ) {
				// Recorded as put back while the file was held: only the end is left, nothing is renamed any more.
				throw $this->end_back( $context, $db, $cursor, '' );
			}
			if ( 'reverted' === $phase ) {
				// Recorded as untouched; the maintenance file comes down last (again, if a run died before).
				throw $this->end_back( $context, $db, $cursor, '' );
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
		} catch ( SwapPause $question ) {
			return $question->result();
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
	 * A redo (redo_at(): its cursor holds the time of the undo it sets out from) goes the same way with three
	 * differences: the write that records "enter" requires no cancel requested (JobRepository::save_progress(): a cancel
	 * gives it up there, RedoCancelled); the backup's tables are not judged again (the copy was the live site); and this
	 * plugin's state is carried before anything is renamed (carry_in(): a carry that fails leaves nothing to put back).
	 * What stops a redo before "enter" gives it up (give_up_redo()), the site as the undo left it.
	 *
	 * @param JobContext           $context Context.
	 * @param Queries              $db      Connection.
	 * @param array<string, mixed> $cursor  Cursor ("ready", "redo"; or a redo's "carry" to try again).
	 * @return StepResult
	 * @throws \RuntimeException When the old directories' places cannot be made (nothing changed yet).
	 */
	private function swap( JobContext $context, Queries $db, array $cursor ): StepResult {
		$redo   = self::redo_at( $cursor );
		$resume = 'carry' === ( $cursor['phase'] ?? '' );
		try {
			$entries = $this->entries( $context, $db, $cursor );
			foreach ( $entries['dirs'] as $entry ) {
				$parent = dirname( $entry['old'] );
				if ( $entry['had_live'] && ! is_dir( $parent ) && ! @mkdir( $parent, 0755, true ) && ! is_dir( $parent ) ) {
					throw new \RuntimeException( sprintf( 'The place for the site\'s own %s in the staging root could not be made; the swap was not started, and the site is as it was.', basename( $entry['live'] ) ) );
				}
			}
			$carry = $this->carry_tables( $entries['tables'] );
		} catch ( LockLost $e ) {
			throw $e;
		} catch ( StaleJob $e ) {
			throw $e;
		} catch ( TransientFailure $e ) {
			throw $e;
		} catch ( \RuntimeException $e ) {
			if ( $redo <= 0 || $resume ) {
				throw $e;
			}
			throw $this->give_up_redo( $context, $cursor, $e->getMessage() );
		}
		$file = $this->maintenance( $cursor );
		$base = array(
			'attempt' => $cursor['attempt'],
			'mark'    => $cursor['mark'],
		) + self::redo_keys( $cursor ) + array_intersect_key( $cursor, array( 'carry' => true ) );
		if ( ! $resume ) {
			$cursor = array( 'phase' => 'enter' ) + $base;
			try {
				$context->checkpoint( $cursor, 20, __( 'Swapping in the restored site', 'wp-checkpoint' ) );
			} catch ( RedoCancelled $e ) {
				throw $this->give_up_redo( $context, $cursor, __( 'It was cancelled.', 'wp-checkpoint' ) );
			}
		}
		$mark = $cursor['mark'];
		try {
			$guard = null;
			if ( $resume ) {
				$file->hold( array( $context, 'confirm_lease' ) );
			} else {
				$this->at( 'entered' );
				$file->put( $this->now(), array( $context, 'confirm_lease' ) );
				$this->at( 'maintenance' );
			}
			if ( $redo > 0 ) {
				$guard = $this->carry_in( $context, $db, $base, $carry, $file );
			} else {
				// Once more before the first rename, the live tables only (the usermeta table was walked in this attempt's
				// judge phase, and that judgement was the preflight's): the site's only change so far is the maintenance file.
				$recorded = RestorePreflightStep::judged( $context->work_path() );
				$loaded   = RestorePreflightStep::load_plan( $context->work_path() );
				if ( null === $recorded || $this->judgement( $loaded, $recorded['finals'], $recorded['judgement']['evidence'], $recorded['judgement']['over'] ) !== $recorded['judgement'] ) {
					throw new IncomingChanged( self::REJUDGED );
				}
			}
			foreach ( $entries['dirs'] as $i => $entry ) {
				$cursor = array( 'phase' => 'dirs' ) + $base + array(
					'i'    => $i,
					'step' => 'a',
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
			if ( $redo <= 0 ) {
				$cursor = array( 'phase' => 'carry' ) + $base;
				$context->checkpoint( $cursor, 50, __( 'Carrying this plugin\'s own settings into the restored site', 'wp-checkpoint' ) );
				$file->hold( array( $context, 'confirm_lease' ) );
				if ( null !== $carry ) {
					$guard = $this->state_carry( $db );
					$guard->carry( $carry['live_options'], $carry['temp_options'], $carry['live_meta'], $carry['temp_meta'], $context->fence() );
				}
				$this->at( 'carried' );
			}
			$cursor = array( 'phase' => 'carry' ) + $base; // Where rename_tables() takes its keys from.
			$this->rename_tables( $context, $db, $cursor, $entries['tables'], $guard, $carry );
		} catch ( LockLost $e ) {
			throw $e; // The run that takes the job over rolls back.
		} catch ( StaleJob $e ) {
			throw $e;
		} catch ( SwapPause $e ) {
			throw $e; // A redo asks how to go on after its carry failed (carry_in()): nothing was renamed.
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
			if ( $e instanceof TransientFailure && $redo > 0 && ! empty( $context->cursor()['again'] ) && 'carry' === ( $context->cursor()['phase'] ?? '' ) ) {
				throw $e; // A redo's carry that failed for a moment: tried again by the next run, the file held (carry_in()).
			}
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
	 * A redo's carry of this plugin's own state, the maintenance file held and nothing renamed yet ("carry"): the live
	 * options (and a network's sitemeta) carried into the restored copy (StateCarry::carry()), so that the swap back in
	 * keeps what the plugin wrote since the undo. A failure of the moment (TransientFailure) is recorded ("again") and
	 * thrown: the Runner waits and the next run tries the carry again, the file held (a run that died here instead is
	 * rolled back). Any other failure asks how to go on (ask_redo_carry()).
	 *
	 * Answered to go on without it ("carry" is "skip"): nothing is carried, after the rows that would be lost are read
	 * again and found to be the ones the question listed (the file held, they no longer change); other rows, and the
	 * answer is void: asked again with them.
	 *
	 * @param JobContext                                                                                             $context Context.
	 * @param Queries                                                                                                $db      Connection.
	 * @param array<string, mixed>                                                                                   $base    The cursor's attempt, mark, undone_at (and carry).
	 * @param array{live_options: string, temp_options: string, live_meta: string|null, temp_meta: string|null}|null $carry   The tables carried.
	 * @param Maintenance                                                                                            $file    The maintenance file.
	 * @return StateCarry|null The carry, for the first rename (StateCarry::guard()), when the options are replaced.
	 * @throws SwapPause When it asks.
	 * @throws TransientFailure When the carry failed for a moment.
	 */
	private function carry_in( JobContext $context, Queries $db, array $base, $carry, Maintenance $file ) {
		$cursor = array( 'phase' => 'carry' ) + $base + array_intersect_key( $context->cursor(), array( 'again' => true ) );
		$context->checkpoint( $cursor, 30, __( 'Carrying this plugin\'s own settings into the restored site', 'wp-checkpoint' ) );
		$file->hold( array( $context, 'confirm_lease' ) );
		if ( null === $carry ) {
			return null;
		}
		$guard = $this->state_carry( $db );
		if ( 'skip' === ( $base['carry'] ?? '' ) ) {
			$names = $this->carried_names( $db, $carry['live_options'], $carry['live_meta'] );
			$asked = $this->redo_carry_names( $context );
			if ( $names !== $asked ) {
				$context->logger()->warning(
					'The rows of WP Checkpoint that the redo would not carry are not the ones it asked about any more: the answer is void, and it asks again',
					array(
						'asked' => $asked,
						'now'   => $names,
					)
				);
				throw new SwapPause( $this->ask_redo_carry( $context, $cursor, __( 'The rows that would be lost changed since the question was asked.', 'wp-checkpoint' ), $names ) );
			}
			$context->logger()->warning( 'This plugin\'s own settings written since the undo were not carried into the restored copy, as chosen', array( 'names' => $names ) );
			return $guard;
		}
		try {
			$guard->carry( $carry['live_options'], $carry['temp_options'], $carry['live_meta'], $carry['temp_meta'], $context->fence() );
		} catch ( LockLost $e ) {
			throw $e;
		} catch ( StaleJob $e ) {
			throw $e;
		} catch ( TransientFailure $e ) {
			$cursor['again'] = 1;
			$context->checkpoint( $cursor, 30, __( 'Carrying this plugin\'s own settings into the restored site', 'wp-checkpoint' ) );
			throw $e;
		} catch ( \Throwable $e ) {
			throw new SwapPause( $this->ask_redo_carry( $context, $cursor, get_class( $e ) . ': ' . $e->getMessage(), $this->carried_names( $db, $carry['live_options'], $carry['live_meta'] ) ) );
		}
		$this->at( 'redo_carried' );
		$context->logger()->info( 'This plugin\'s own settings were carried into the restored copy' );
		return $guard;
	}

	/**
	 * Ask how a redo goes on whose carry failed (or whose list of rows that would be lost changed), before anything was
	 * renamed: why and the rows written to the work directory (RestoreFiles::REDO_CARRY) first, then the maintenance
	 * file taken down, then the position recorded ("redo_ask": the site untouched). The file comes down first: the other
	 * way round, a run that died between the two would leave a held file of a job recorded as untouched; this way it
	 * finds "carry" and puts back what is there (nothing), as for a run that died in it.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor ("carry").
	 * @param string               $why     Why it asks.
	 * @param string[]|null        $names   carried_names().
	 * @return StepResult
	 */
	private function ask_redo_carry( JobContext $context, array $cursor, string $why, $names ): StepResult {
		ExportPlan::write(
			$context->work_path(),
			RestoreFiles::REDO_CARRY,
			array(
				'reason' => $why,
				'names'  => $names,
			)
		);
		$this->take_down( $context, $cursor );
		$this->at( 'redo_carry_down' );
		$cursor = array(
			'phase'     => 'redo_ask',
			'attempt'   => $cursor['attempt'],
			'mark'      => $cursor['mark'],
			'undone_at' => self::redo_at( $cursor ),
			'ask'       => $this->random(), // This asking: an answer counts only when it was given to it (answered()).
		);
		$context->checkpoint( $cursor, 20, __( 'Waiting for your decision on 1 question', 'wp-checkpoint' ) );
		$this->at( 'redo_ask_recorded' );
		$context->logger()->warning(
			'This plugin\'s own settings could not be carried into the restored copy; the redo asks how to go on, the site open as the undo left it',
			array(
				'reason' => $why,
				'names'  => $names,
			)
		);
		return $this->redo_question( $context, $cursor, $names );
	}

	/**
	 * The question of "redo_ask" (the maintenance file taken down first, again if a run died before).
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor ("redo_ask").
	 * @param string[]|null        $names   The rows that would be lost, or null (cannot be read).
	 * @return StepResult
	 */
	private function redo_question( JobContext $context, array $cursor, $names ): StepResult {
		$this->take_down( $context, $cursor );
		$this->at( 'redo_asked' );
		return StepResult::ask(
			$cursor,
			array(
				array(
					'id'      => self::REDO_CARRY_QUESTION,
					'kind'    => self::REDO_CARRY_KIND,
					'count'   => null === $names ? 0 : count( $names ),
					'file'    => RestoreFiles::REDO_CARRY,
					'choices' => array( self::REDO, RestoreJob::KEEP ),
				),
			),
			__( 'Waiting for your decision on 1 question', 'wp-checkpoint' )
		);
	}

	/**
	 * The rows the redo's question listed (RestoreFiles::REDO_CARRY, as carried_names() read them: sorted), or null when
	 * it said they could not be read.
	 *
	 * @param JobContext $context Context.
	 * @return string[]|null
	 * @throws TransientFailure When the file cannot be read now: the answer is acted on only as the question was asked.
	 */
	private function redo_carry_names( JobContext $context ) {
		try {
			$read = ExportPlan::read( $context->work_path(), RestoreFiles::REDO_CARRY );
		} catch ( \RuntimeException $e ) {
			throw new TransientFailure( 'What the redo\'s question listed cannot be read now: ' . $e->getMessage() );
		}
		return is_array( $read['names'] ?? null ) ? array_map( 'strval', $read['names'] ) : null;
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
		) + self::redo_keys( $cursor );
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
			) + self::redo_keys( $cursor );
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
			) + self::redo_keys( $cursor );
			$context->checkpoint( $cursor, 90, __( 'Putting the site back as it was', 'wp-checkpoint' ) );
			$context->logger()->warning( 'The swap is rolled back', array( 'reason' => self::REASONS[ $reason ] ) );
		}
		$this->at( 'rollback' );
		$entries = $this->entries( $context, $db, $cursor );
		$file    = $this->maintenance( $cursor );
		$this->hold_for_renames( $context, $file, ! empty( $cursor['out'] ) );
		$db->run( 'SET SESSION lock_wait_timeout = ' . self::LOCK_WAIT );
		$there = $this->there( $db, $entries['tables'] );
		$this->carry_back( $context, $db, $entries['tables'], $there, $cursor );
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
		) + self::redo_keys( $cursor );
		if ( $out ) {
			$cursor['out'] = true; // Kept to the end: an undo after the site was let out ends the job as undone (ended()).
		}
		$context->checkpoint( $cursor, 98, __( 'The site is as it was before the restore', 'wp-checkpoint' ) );
		$this->at( 'restored' );
		return $this->end_back( $context, $db, $cursor, $detail );
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
	 * maintenance file held, and tried again by the next run. One that fails for any other reason: on an undo after
	 * the restored site was let out ($cursor "out"), with nothing put back yet, the undo asks how to go on (SwapPause,
	 * carry_asked(): put the site back without it, or keep the restored site); otherwise (a swap reversed before it was
	 * let out, or a run that finds the site partly put back already) it is said in the job's log with the names of the
	 * rows not carried back, and the site is put back without it. On an old table without transactions the statements
	 * sent before the failure stay, and they never take away a row the old table had under one of this plugin's names
	 * the carry listed in the live table and the live table still has: it stays, or the live one is there, or both (a
	 * sitemeta table) (StateCarry::carry_back()). With the answer to put the site back without it ($cursor "carry" is
	 * "skip"), nothing is carried and that is said.
	 *
	 * @param JobContext                                                                                          $context Context.
	 * @param Queries                                                                                             $db      Connection.
	 * @param array<int, array{seq: int, kind: string, live: string, stage: string, old: string, had_live: bool}> $tables  Table entries.
	 * @param array<string, bool>                                                                                 $there   Which of their names are there.
	 * @param array<string, mixed>                                                                                $cursor  The rollback's cursor.
	 * @return void
	 * @throws SwapPause When the undo asks how to go on.
	 * @throws TransientFailure When the carry fails for a moment (the put-back goes on later).
	 * @throws LockLost When the job is no longer this run's.
	 * @throws StaleJob When the job changed meanwhile.
	 */
	private function carry_back( JobContext $context, Queries $db, array $tables, array $there, array $cursor ): void {
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
		if ( 'skip' === ( $cursor['carry'] ?? '' ) ) {
			// Put back without it, as answered (carry_asked()).
			$context->logger()->warning( 'This plugin\'s own settings written since the swap were not carried back, as chosen; the site is put back without them', array( 'names' => $this->carried_names( $db, $pairs['options']['live'], null === $meta ? null : $meta['live'] ) ) );
			return;
		}
		try {
			$this->state_carry( $db )->carry_back( $pairs['options']['live'], $pairs['options']['old'], null === $meta ? null : $meta['live'], null === $meta ? null : $meta['old'], $context->fence() );
		} catch ( LockLost $e ) {
			throw $e; // The run that takes the job over puts the site back.
		} catch ( StaleJob $e ) {
			throw $e;
		} catch ( TransientFailure $e ) {
			throw $e; // A problem of the moment: the put-back waits, its file held, and goes on.
		} catch ( \Throwable $e ) {
			$names = $this->carried_names( $db, $pairs['options']['live'], null === $meta ? null : $meta['live'] );
			if ( ! empty( $cursor['out'] ) && SwapRules::committed( $tables, $there ) ) {
				// An undo after the site was let out, nothing put back yet: someone is there to decide (2026-10-24), and
				// the site can be let out as it is while they do.
				throw new SwapPause( $this->ask_carry( $context, $cursor, $e, $names ) );
			}
			// Swapped back out before it was let out (a failed check): nothing of the restored site was served, and
			// the old site's own state is whole up to the swap; it is put back without the carry, and that is said.
			$context->logger()->warning(
				'This plugin\'s own settings written since the swap were not carried back (or only some of them); the site is put back all the same',
				array(
					'error'  => get_class( $e ),
					'reason' => $e->getMessage(),
					'names'  => $names,
				)
			);
			return;
		}
		$context->logger()->info( 'This plugin\'s own settings were carried back into the site as it was' );
		$this->at( 'carried_back' );
	}

	/**
	 * The names of this plugin's own rows in the live options table (and a network's sitemeta table) a carry back
	 * would bring into the old site, but the row that lists the active plugins and the self-check row (neither is
	 * carried back); null when they cannot be read.
	 *
	 * @param Queries     $db      Connection.
	 * @param string      $options The live options table.
	 * @param string|null $meta    The live sitemeta table, or null.
	 * @return string[]|null
	 */
	private function carried_names( Queries $db, string $options, $meta ) {
		try {
			$names = array_diff( StateCarry::own_names( $db, $options, false, array() ), array( StateCarry::ACTIVE, StoredNames::SELF_CHECK ) );
			if ( null !== $meta ) {
				$names = array_merge( $names, array_diff( StateCarry::own_names( $db, $meta, true, array( (string) ( is_multisite() ? get_current_network_id() : 0 ) ) ), array( StateCarry::ACTIVE_NETWORK ) ) );
			}
		} catch ( \RuntimeException $e ) {
			return null;
		}
		$names = array_values( array_unique( array_map( 'strval', $names ) ) );
		sort( $names );
		return $names;
	}

	/**
	 * Ask how an undo goes on whose carry back failed, before anything was put back: why and the rows that would be lost
	 * written to the work directory (RestoreFiles::CARRY_BACK) first, then the position recorded ("carry_ask": the
	 * restored site is in place, as it was), then the maintenance file taken down (nothing was renamed, so the site
	 * is let out as it was while the question waits).
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor (its attempt, mark, reason).
	 * @param \Throwable           $failure Why the carry failed.
	 * @param string[]|null        $names   carried_names().
	 * @return StepResult
	 */
	private function ask_carry( JobContext $context, array $cursor, \Throwable $failure, $names ): StepResult {
		ExportPlan::write(
			$context->work_path(),
			RestoreFiles::CARRY_BACK,
			array(
				'reason' => get_class( $failure ) . ': ' . $failure->getMessage(),
				'names'  => $names,
			)
		);
		$cursor = array(
			'phase'   => 'carry_ask',
			'attempt' => $cursor['attempt'],
			'mark'    => $cursor['mark'] ?? '',
			'reason'  => (string) ( $cursor['reason'] ?? self::ASKED ),
			'check'   => (string) ( $cursor['check'] ?? '' ),
			'out'     => true,
		) + array_intersect_key( $cursor, array_flip( array( 'evidence', 'evidence_sum' ) ) ); // A put-back's: keep puts the job back from it.

		$context->checkpoint( $cursor, 88, __( 'Waiting for your decision on 1 question', 'wp-checkpoint' ) );
		$this->at( 'carry_ask_recorded' );
		$context->logger()->warning(
			'This plugin\'s own settings written since the swap could not be carried back; the undo asks how to go on, the site open to visitors as it is',
			array(
				'error'  => get_class( $failure ),
				'reason' => $failure->getMessage(),
				'names'  => $names,
			)
		);
		return $this->carry_question( $context, $cursor, $names );
	}

	/**
	 * The question of carry_ask (the maintenance file taken down first, again if a run died before).
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor ("carry_ask").
	 * @param string[]|null        $names   The rows that would be lost, or null (cannot be read).
	 * @return StepResult
	 */
	private function carry_question( JobContext $context, array $cursor, $names ): StepResult {
		$this->take_down( $context, $cursor );
		$this->at( 'carry_asked' );
		return StepResult::ask(
			$cursor,
			array(
				array(
					'id'      => self::CARRY_QUESTION,
					'kind'    => self::CARRY_KIND,
					'count'   => null === $names ? 0 : count( $names ),
					'file'    => RestoreFiles::CARRY_BACK,
					'choices' => array( self::PUT_BACK, RestoreJob::KEEP ),
				),
			),
			__( 'Waiting for your decision on 1 question', 'wp-checkpoint' )
		);
	}

	/**
	 * An undo that asked how to go on after its carry back failed: asked again until it is answered; put the site back
	 * without the carry (PUT_BACK), or give the undo up and keep the restored site (KEEP: it ends as a restore that
	 * completed, its maintenance file down and its caches flushed, the time it was committed unchanged).
	 *
	 * @param JobContext           $context Context.
	 * @param Queries              $db      Connection.
	 * @param array<string, mixed> $cursor  Cursor ("carry_ask").
	 * @return StepResult
	 * @throws \RuntimeException What ends the job after the site is put back (ended()).
	 */
	private function carry_asked( JobContext $context, Queries $db, array $cursor ): StepResult {
		$answer = $context->options()['answers'][ self::CARRY_QUESTION ] ?? null;
		$at     = $context->job()->committed_at;
		$why    = '';
		if ( self::putting_back( $cursor ) ) {
			// A put-back's question has no deadline: it waits for an answer, the job holding the site meanwhile. Keep is
			// never this restore's completion (nothing after its commit was done for real): the answer itself puts the
			// job back as it was before the put-back (JobRepository::answer()); one that reached the step another way is
			// not acted on, and the question is asked again.
			if ( RestoreJob::KEEP === $answer ) {
				$context->logger()->warning( 'An answer to keep the restored site reached the put-back without putting the job back as it was; the question is asked again' );
				$answer = null;
			}
		} elseif ( ! self::within_window( $at, $this->now() ) ) {
			// The deadline (the end of the time the restore can be undone in) passed: what the undo said it drops was
			// counted before, and it is no longer an undo the plugin makes. The restored site stays, whatever was answered.
			$deadline = array( 'deadline' => gmdate( 'Y-m-d H:i', $at + Undo::WINDOW_SECONDS ) . ' UTC' );
			if ( self::PUT_BACK === $answer ) {
				$context->logger()->warning( 'The answer to put the site back came after the deadline; the undo is given up and the restored site kept, as the deadline passed', $deadline );
			} else {
				$context->logger()->warning( 'The deadline to answer passed; the undo is given up and the restored site kept, as the question said', $deadline );
			}
			$answer = RestoreJob::KEEP;
			$why    = 'deadline';
		}
		if ( self::PUT_BACK === $answer ) {
			$cursor = array(
				'phase'   => 'reverse',
				'attempt' => $cursor['attempt'],
				'mark'    => $cursor['mark'] ?? '',
				'reason'  => (string) ( $cursor['reason'] ?? self::ASKED ),
				'check'   => (string) ( $cursor['check'] ?? '' ),
				'out'     => true,
				'carry'   => 'skip',
			);
			$context->checkpoint( $cursor, 88, __( 'Putting the site back as it was', 'wp-checkpoint' ) );
			throw $this->roll_back( $context, $db, $cursor, (string) $cursor['reason'] );
		}
		if ( RestoreJob::KEEP === $answer ) {
			if ( '' === $why ) {
				$context->logger()->warning( 'The undo was given up as the administrator chose: the restored site stays' );
			}
			$cursor = array(
				'phase'   => 'committed',
				'attempt' => $cursor['attempt'],
				'mark'    => $cursor['mark'] ?? '',
				'post'    => 'exit',
			);
			$context->checkpoint( $cursor, 90, __( 'The restored site is in place', 'wp-checkpoint' ) );
			$this->at( 'undo_given_up' );
			return $this->after( $context, $db, $cursor );
		}
		$names = null;
		try {
			$read  = ExportPlan::read( $context->work_path(), RestoreFiles::CARRY_BACK );
			$names = is_array( $read['names'] ?? null ) ? array_map( 'strval', $read['names'] ) : null;
		} catch ( \RuntimeException $e ) {
			$names = null; // The question stands without its list.
		}
		return $this->carry_question( $context, $cursor, $names );
	}

	/**
	 * The end of a rollback recorded as put back ("restored"): after an undo the site was let out from (undone()), the
	 * site as it is now checked first, behind the held file (check_undone()); then the maintenance file let go, the
	 * site recorded as untouched, the file taken down as the last step; then what ends the job.
	 *
	 * @param JobContext           $context Context.
	 * @param Queries              $db      Connection.
	 * @param array<string, mixed> $cursor  Cursor ("restored" or "reverted").
	 * @param string               $detail  Why in words, for this tick's error ('' for the reason's text).
	 * @return \RuntimeException What ends the job, for the caller to throw (ended()).
	 * @throws TransientFailure When the file cannot be let go or taken down yet (it is tried again).
	 * @throws SwapPause When the site is checked again after a wait.
	 */
	private function end_back( JobContext $context, Queries $db, array $cursor, string $detail ): \RuntimeException {
		$reason = (string) ( $cursor['reason'] ?? self::INTERRUPTED );
		$undone = self::undone( $cursor );
		if ( 'restored' === ( $cursor['phase'] ?? '' ) ) {
			if ( self::checked_back( $cursor ) ) {
				$cursor = $this->check_undone( $context, $db, $cursor );
			}
			$this->release( $context, $this->maintenance( $cursor ) );
			$this->at( 'unheld' );
			$cursor = array(
				'phase'  => 'reverted',
				'mark'   => $cursor['mark'] ?? '',
				'reason' => $reason,
				'check'  => (string) ( $cursor['check'] ?? '' ),
			) + array_intersect_key( $cursor, array_flip( array( 'out', 'after_undo', 'after_undo_found', 'undone_at' ) ) );
			$context->checkpoint( $cursor, 100, __( 'The site is as it was before the restore', 'wp-checkpoint' ) );
			$this->at( 'reverted' );
		}
		$this->take_down( $context, $cursor );
		$this->at( 'maintenance_down' );
		if ( self::asks_after_undo( $cursor ) ) {
			// Open to visitors first, then asked (2026-11-01): keep the site as put back, or swap the restored copy back in.
			throw new SwapPause( $this->ask_after_undo( $context, $cursor ) );
		}
		return $this->ended( $context, '' !== $detail ? $detail : $reason, (string) ( $cursor['check'] ?? '' ), $undone, $cursor );
	}

	/**
	 * Whether a rollback's cursor is an undo after the restored site was let out (an undo answered after the release,
	 * or wp wpcheckpoint job undo): such a job ends undone, and the site is checked after it was put back.
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @return bool
	 */
	private static function undone( array $cursor ): bool {
		return in_array( (string) ( $cursor['reason'] ?? '' ), array( self::UNDONE, self::ASKED, self::PUT_BACK_REASON ), true ) && ! empty( $cursor['out'] );
	}

	/**
	 * Whether a rollback's cursor is a put-back (wp wpcheckpoint job put-back, JobActions::put_back()): of a swap that was
	 * committed ("out": it ends undone, as an undo) or of one that was half made (it ends cancelled).
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @return bool
	 */
	public static function putting_back( array $cursor ): bool {
		return self::PUT_BACK_REASON === (string) ( $cursor['reason'] ?? '' );
	}

	/**
	 * Whether the site, once put back by this rollback, is checked before it is let out (check_undone()): after an undo
	 * the site was let out from, and after any put-back.
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @return bool
	 */
	private static function checked_back( array $cursor ): bool {
		return self::undone( $cursor ) || self::putting_back( $cursor );
	}

	/**
	 * Whether a job ended with the site put back (undone, or cancelled by a put-back) but the site as put back did
	 * not pass its check, or could not be checked: what its cursor at the end holds (check_undone(), end_back()). A
	 * cursor that should hold the verdict and does not counts as not checked.
	 *
	 * @param Job $job Job, as it ended.
	 * @return bool
	 */
	public static function back_unchecked( Job $job ): bool {
		if ( self::ID !== $job->step || ! in_array( $job->status, array( Job::UNDONE, Job::CANCELLED ), true ) ) {
			return false;
		}
		return self::checked_back( $job->cursor ) && SiteCheck::PASSED !== ( $job->cursor['after_undo'] ?? '' );
	}

	/**
	 * After an undo the site was let out from, the site as put back is checked, behind the held file, as a restored
	 * site is (the self-check row in the options table now in place, the maintenance file letting this run's requests
	 * through, Restore\SiteCheck; what could not be told checked again after the waits of CHECK_WAITS, at most
	 * CHECK_TRIES times, each try in a tick of its own). A failure or a check that could not tell does not hold the site
	 * in maintenance mode: the site is let out all the same (it is the site that ran before the restore), and then an
	 * undo asks whether to keep it or swap the restored copy back in (ask_after_undo(), 2026-11-01); a put-back only
	 * says it (2026-10-31). The verdict is recorded before the row goes and the file is let go; a run that dies after it
	 * does not check again. The self-check row goes before the file is let go, again by a run taken up after the
	 * verdict.
	 *
	 * @param JobContext           $context Context.
	 * @param Queries              $db      Connection.
	 * @param array<string, mixed> $cursor  Cursor ("restored").
	 * @return array<string, mixed> The cursor with the verdict ("after_undo": a SiteCheck verdict; "after_undo_found":
	 *                              the failed check's id of CHECKS, or what a check that could not tell found).
	 * @throws SwapPause When the site is checked again after a wait.
	 */
	private function check_undone( JobContext $context, Queries $db, array $cursor ): array {
		$file = $this->maintenance( $cursor );
		if ( ! isset( $cursor['after_undo'] ) ) {
			$key   = SelfCheck::random();
			$nonce = SelfCheck::random();
			$fence = $context->fence();
			SelfCheck::put( $db, $fence, SelfCheck::table( self::base_prefix() ), SelfCheck::value( $fence['id'], $nonce, hash( 'sha256', $key ) ) );
			$this->let_through( $context, $file, hash( 'sha256', $key ) );
			$this->at( 'undo_check_written' );
			$found = $this->check_site( $context, $db, $key, $nonce );
			$this->at( 'undo_checked' );
			$tries = (int) ( $cursor['tries'] ?? 0 ) + 1;
			if ( SiteCheck::UNKNOWN === $found['verdict'] && $tries < self::CHECK_TRIES ) {
				$cursor['tries'] = $tries;
				$context->checkpoint( $cursor, 99, __( 'Checking the site after the undo', 'wp-checkpoint' ) );
				$wait = self::CHECK_WAITS[ $tries - 1 ];
				$text = self::unchecked_text( self::finding( $found ) );
				$context->logger()->info(
					'The site could not be checked yet after the undo; it is checked again',
					array(
						'try'   => $tries,
						'found' => $text,
						'wait'  => $wait,
					)
				);
				/* translators: 1: what was found, 2: seconds, 3: this try, 4: the most tries */
				throw new SwapPause( StepResult::wait( $wait, $cursor, sprintf( __( 'The site could not be checked yet after the undo (%1$s); it is checked again in %2$d seconds (try %3$d of %4$d).', 'wp-checkpoint' ), $text, $wait, $tries, self::CHECK_TRIES ) ) );
			}
			unset( $cursor['tries'] );
			$cursor['after_undo']       = $found['verdict'];
			$cursor['after_undo_found'] = SiteCheck::FAILED === $found['verdict'] ? self::check_id( $found ) : ( SiteCheck::UNKNOWN === $found['verdict'] ? self::finding( $found ) : '' );
			$context->checkpoint( $cursor, 99, __( 'Checking the site after the undo', 'wp-checkpoint' ) );
			$this->at( 'undo_verdict_recorded' );
			if ( SiteCheck::PASSED !== $found['verdict'] ) {
				$context->logger()->warning( 'The site as put back did not pass its check; it is let out all the same', array( 'found' => self::after_undo_text( $cursor ) ) );
			}
		}
		$this->refresh( $context, $file, true );
		SelfCheck::remove( $db, $context->fence(), SelfCheck::table( self::base_prefix() ) );
		$this->at( 'undo_self_check_removed' );
		return $cursor;
	}

	/**
	 * Whether a cursor is where the site put back by an undo is checked (the self-check route answers for it,
	 * Rest\SelfCheckController): "restored", undone, no verdict yet.
	 *
	 * @param array<string, mixed> $cursor The job's cursor.
	 * @return bool
	 */
	public static function checks_undone( array $cursor ): bool {
		return 'restored' === ( $cursor['phase'] ?? '' ) && self::checked_back( $cursor ) && ! isset( $cursor['after_undo'] );
	}

	/**
	 * What the check after an undo found, in words ('' when it passed or was not made).
	 *
	 * @param array<string, mixed> $cursor Cursor (its "after_undo" verdict).
	 * @return string
	 */
	private static function after_undo_text( array $cursor ): string {
		$found = $cursor['after_undo_found'] ?? '';
		if ( SiteCheck::FAILED === ( $cursor['after_undo'] ?? '' ) ) {
			return self::CHECKS[ is_string( $found ) && isset( self::CHECKS[ $found ] ) ? $found : 'unknown' ];
		}
		if ( SiteCheck::UNKNOWN === ( $cursor['after_undo'] ?? '' ) ) {
			return self::unchecked_text( is_array( $found ) ? $found : array() );
		}
		return '';
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
	 * The cursor a completed restore is undone from (wp wpcheckpoint job undo, JobActions::undo()): "undo", a position
	 * of the swapped site (nothing changed yet), with what its reverse needs (as reverse() records it after the site
	 * was let out, with the reason ASKED). The next run checks once more before it changes anything (start_undo()),
	 * then holds the maintenance file (waiting out anyone else's), carries this plugin's state back, and puts the site
	 * back by the plan; the job ends undone (ended()). Its JSON begins with the phase (JobRepository::cancel_undo()
	 * finds an undo that has not started by it).
	 *
	 * @param int    $attempt The plan's attempt the swap was made by.
	 * @param string $mark    The job's maintenance file mark (Job::$site_mark).
	 * @return array<string, mixed>
	 */
	public static function undo_cursor( int $attempt, string $mark ): array {
		return array(
			'phase'   => 'undo',
			'attempt' => $attempt,
			'mark'    => $mark,
			'reason'  => self::ASKED,
			'check'   => '',
			'out'     => true,
		);
	}

	/**
	 * The cursor a put-back claims a stuck restore with (wp wpcheckpoint job put-back, JobActions::put_back()), made from
	 * the plan and the job row only: a swap that was committed ($committed) is reversed as an undo after the site was let
	 * out ("reverse", "out": its maintenance file waits for anyone else's, its own state is carried back, it ends
	 * undone); one that was half made is rolled back ("rollback": it ends cancelled, the claim recording the cancel). The
	 * evidence directory (PutBackEvidence) holds what the claim wrote over: an answer to keep the restored site puts the
	 * job back from it.
	 *
	 * @param bool   $committed Whether the swap was committed (the row's site state is swapped).
	 * @param int    $attempt   The plan's attempt.
	 * @param string $mark      The job's maintenance file mark (Job::$site_mark).
	 * @param string $evidence  The evidence directory, relative to the storage directory.
	 * @param string $sum       SHA-256 of its manifest.
	 * @return array<string, mixed>
	 */
	public static function put_back_cursor( bool $committed, int $attempt, string $mark, string $evidence, string $sum ): array {
		$cursor = array(
			'phase'        => $committed ? 'reverse' : 'rollback',
			'attempt'      => $attempt,
			'mark'         => $mark,
			'reason'       => self::PUT_BACK_REASON,
			'check'        => '',
			'evidence'     => $evidence,
			'evidence_sum' => $sum,
		);
		if ( $committed ) {
			$cursor['out'] = true;
		}
		return $cursor;
	}

	/**
	 * Right before an undo first changes the site (its maintenance file not held yet): the time a restore can be undone
	 * in run out (Undo::WINDOW_SECONDS from its commit) gives it up (give_up_undo()); otherwise the reverse is recorded:
	 * the site state becomes changing in a write that requires no cancel requested (JobRepository::save_progress(), the
	 * one place a cancel is weighed against the undo: it and the request meet on the job's row), and a cancel requested
	 * since the claim gives the undo up there (UndoCancelled); then the site is put back (roll_back()).
	 *
	 * @param JobContext           $context Context.
	 * @param Queries              $db      Connection.
	 * @param array<string, mixed> $cursor  Cursor ("undo").
	 * @return StepResult
	 * @throws \RuntimeException What ends the job once the site is put back (ended()).
	 */
	private function start_undo( JobContext $context, Queries $db, array $cursor ): StepResult {
		$job = $context->job();
		if ( ! self::within_window( $job->committed_at, $this->now() ) ) {
			return $this->give_up_undo( $context, $cursor, 'The time a restore can be undone in ran out before the undo changed the site: the restored site stays', __( 'The time this restore could be undone in ran out before the undo changed the site: the restored site stays.', 'wp-checkpoint' ) );
		}
		$this->at( 'undo_starting' );
		$cursor['phase'] = 'reverse';
		try {
			$context->checkpoint( $cursor, 88, __( 'Putting the site back as it was', 'wp-checkpoint' ) );
		} catch ( UndoCancelled $e ) {
			$cursor['phase'] = 'undo';
			return $this->give_up_undo( $context, $cursor, 'The undo was cancelled before it changed the site: the restored site stays', __( 'The undo was cancelled before it changed the site: the restored site stays.', 'wp-checkpoint' ) );
		}
		$this->at( 'undo_started' );
		throw $this->roll_back( $context, $db, $cursor, (string) ( $cursor['reason'] ?? self::ASKED ) );
	}

	/**
	 * Give an undo up before it changed the site: recorded as done (the site swapped, as it has been; a cancel request
	 * cleared with it), its maintenance file down if a run left one, and the job completed again.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor ("undo").
	 * @param string               $log     What the log says.
	 * @param string               $text    What the job says at its end.
	 * @return StepResult
	 */
	private function give_up_undo( JobContext $context, array $cursor, string $log, string $text ): StepResult {
		$context->logger()->warning( $log );
		$cursor = array(
			'phase'   => 'done',
			'attempt' => $cursor['attempt'],
			'mark'    => $cursor['mark'] ?? '',
		);
		$context->checkpoint( $cursor, 100, $text );
		$this->at( 'undo_not_started' );
		$this->take_down( $context, $cursor );
		return StepResult::done( $text );
	}

	/**
	 * Whether a swap committed at $committed_at may still be undone at $now.
	 *
	 * @param int $committed_at Job::$committed_at.
	 * @param int $now          Unix time.
	 * @return bool
	 */
	public static function within_window( int $committed_at, int $now ): bool {
		return $committed_at > 0 && $now <= $committed_at + Undo::WINDOW_SECONDS;
	}

	/**
	 * Whether a cursor is an undo after the restored site was let out (wp wpcheckpoint job undo, or an undo answered
	 * after the release), at any position: "undo" before it changed the site, or a reverse, a question about its
	 * carry back, or its end. A cancel gives up the first (JobRepository::cancel_undo()) and is refused for the rest.
	 *
	 * @param array<string, mixed> $cursor The job's cursor.
	 * @return bool
	 */
	public static function undoing( array $cursor ): bool {
		return 'undo' === ( $cursor['phase'] ?? '' ) || self::undone( $cursor );
	}

	/**
	 * What ends a job whose swap was rolled back: cancelled when that was asked for; undone (Undone) when it was undone
	 * after the restored site was let out; otherwise failed with a retry starting at the final check.
	 *
	 * @param JobContext           $context Context.
	 * @param string               $reason  Why it was rolled back.
	 * @param string               $check   The check that failed (an id of CHECKS), when it was reversed for one.
	 * @param bool                 $undone  Whether it was undone after the restored site was let out.
	 * @param array<string, mixed> $cursor  The rollback's last cursor (what the check after an undo found).
	 * @return \RuntimeException Cancelled, Undone or RetryFrom.
	 */
	private function ended( JobContext $context, string $reason, string $check = '', bool $undone = false, array $cursor = array() ): \RuntimeException {
		$redo = self::redo_at( $cursor );
		if ( $redo > 0 ) {
			// A redo that did not stand: undone again, as of the undo it set out from (never cancelled: a cancel is refused
			// once a redo changes the site; never failed: a retry would start from the final check, and the restored copy
			// is no longer what the import left).
			return $this->redo_ended( $context, $reason, $check, $redo, $cursor );
		}
		$found = self::after_undo_text( $cursor );
		if ( self::putting_back( $cursor ) && ! $undone ) {
			// A put-back of a swap that was half made ends cancelled (its claim recorded the cancel); what the check of
			// the site as put back found is said with it.
			$context->logger()->info( 'The site was put back as asked (wp wpcheckpoint job put-back); the restore is cancelled' );
			if ( '' !== $found ) {
				return Cancelled::said(
					sprintf(
						/* translators: %s: what the check found */
						SiteCheck::FAILED === ( $cursor['after_undo'] ?? '' ) ? __( 'The site was put back and the restore cancelled, but the site\'s check then failed: %s.', 'wp-checkpoint' ) : __( 'The site was put back and the restore cancelled, but the site could not be checked: %s.', 'wp-checkpoint' ),
						$found
					)
				);
			}
			return Cancelled::said( __( 'The site was put back as it was before the restore, and the restore cancelled.', 'wp-checkpoint' ) );
		}
		if ( 0 !== $context->job()->cancel_requested && ! $undone ) {
			// An undo's put-back is not stopped by a cancel requested while it runs: it ends as the undo it is.
			return new Cancelled( 'The restore was cancelled; the site is as it was before it.' );
		}
		$text = self::REASONS[ $reason ] ?? $reason;
		if ( $undone ) {
			// What was written on the restored site since it was let out went with it, so nothing offers it as a retry:
			// a retry from the final check would also fail (the copy is no longer what the import left).
			// The message stays short (the job's progress message holds it); the reason is in the log (reverse()).
			$context->logger()->info( 'The restore was undone', array( 'reason' => $text ) );
			$days = (int) ( JobRepository::WORK_RETENTION_SECONDS / 86400 );
			if ( self::putting_back( $cursor ) ) {
				if ( '' !== $found ) {
					return new Undone(
						sprintf(
							/* translators: 1: what the check found, 2: days the restored copy is kept */
							SiteCheck::FAILED === ( $cursor['after_undo'] ?? '' ) ? __( 'The site was put back, but its check then failed: %1$s. Restored copy kept %2$d days.', 'wp-checkpoint' ) : __( 'The site was put back, but it could not be checked: %1$s. Restored copy kept %2$d days.', 'wp-checkpoint' ),
							$found,
							$days
						)
					);
				}
				/* translators: %d: days the restored copy is kept */
				return new Undone( sprintf( __( 'The site was put back as it was before the restore. The restored copy is kept for %d days.', 'wp-checkpoint' ), $days ) );
			}
			return new Undone( self::undone_text( $cursor ) );
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
	 * What an undo after the restored site was let out says at its end (not a put-back's), with what the check of the
	 * site as put back found when it did not pass (Undone's message; JobRepository::settle_undone() writes it when the
	 * site is kept at the question that follows: AFTER_UNDO_QUESTION).
	 *
	 * @param array<string, mixed> $cursor The undo's last cursor.
	 * @return string
	 */
	public static function undone_text( array $cursor ): string {
		$days  = (int) ( JobRepository::WORK_RETENTION_SECONDS / 86400 );
		$found = self::after_undo_text( $cursor );
		if ( '' !== $found ) {
			// Said where the job's end is read (the progress message holds 191 characters: the log has the rest).
			return sprintf(
				/* translators: 1: what the check found, 2: days the restored copy is kept */
				SiteCheck::FAILED === ( $cursor['after_undo'] ?? '' ) ? __( 'The restore was undone, but the site\'s check then failed: %1$s. Restored copy kept %2$d days.', 'wp-checkpoint' ) : __( 'The restore was undone, but the site could not be checked: %1$s. Restored copy kept %2$d days.', 'wp-checkpoint' ),
				$found,
				$days
			);
		}
		/* translators: %d: days the restored copy is kept */
		return sprintf( __( 'The restore was undone: the site is as it was before it. The restored copy is kept for %d days after the undo.', 'wp-checkpoint' ), $days );
	}

	/**
	 * What ends a redo that did not stand, put back as the undo left it: undone as of that undo (Undone::back()).
	 *
	 * @param JobContext           $context Context.
	 * @param string               $reason  Why it was rolled back: one of REASONS, or a text of this tick.
	 * @param string               $check   The check that failed (an id of CHECKS), when it was reversed for one.
	 * @param int                  $at      The time of the undo.
	 * @param array<string, mixed> $cursor  The rollback's last cursor.
	 * @return Undone
	 */
	private function redo_ended( JobContext $context, string $reason, string $check, int $at, array $cursor ): Undone {
		$why = (string) ( $cursor['reason'] ?? '' );
		if ( self::REVERSED === $why ) {
			$what = isset( self::CHECKS[ $check ] ) ? self::CHECKS[ $check ] : self::CHECKS['unknown'];
			$context->logger()->warning( 'The redo was swapped back out: a check of the site after it found it broken', array( 'check' => $what ) );
			/* translators: %s: what the check found */
			return Undone::back( sprintf( __( 'The redo was reversed: the site\'s check after it failed (%s). The site is as the undo left it.', 'wp-checkpoint' ), $what ), $at );
		}
		if ( self::UNDONE === $why ) {
			$context->logger()->warning( 'The redo was swapped back out: the site could not be checked after it, and the restore\'s policy is to undo' );
			return Undone::back( __( 'The redo was reversed: the site could not be checked after it, and the restore\'s policy is to undo. The site is as the undo left it.', 'wp-checkpoint' ), $at );
		}
		$text = self::REASONS[ $reason ] ?? $reason;
		$context->logger()->warning( 'The redo stopped and was rolled back', array( 'reason' => $text ) );
		/* translators: %s: why it stopped */
		return Undone::back( sprintf( __( 'The redo stopped and was rolled back: %s The site is as the undo left it.', 'wp-checkpoint' ), $text ), $at );
	}

	/**
	 * Whether an undo after the restored site was let out (not a put-back) asks, at its end, whether to keep the site it
	 * put back: its check failed or could not tell (check_undone()).
	 *
	 * @param array<string, mixed> $cursor Cursor ("reverted").
	 * @return bool
	 */
	private static function asks_after_undo( array $cursor ): bool {
		return 'reverted' === ( $cursor['phase'] ?? '' ) && self::undone( $cursor ) && ! self::putting_back( $cursor ) && isset( $cursor['after_undo'] ) && SiteCheck::PASSED !== $cursor['after_undo'];
	}

	/**
	 * Ask whether to keep the site an undo put back, which failed its check or could not be checked: the site is let go
	 * and recorded as untouched, and the maintenance file is down (end_back()), so the site is open while the question
	 * waits: in maintenance mode its back end and every WP-CLI command (Cli\HeldGuard) would be closed, and nobody could
	 * look at the site before they answer. What was found is written to the work directory (RestoreFiles::AFTER_UNDO),
	 * then the position recorded ("undone_ask", with the time of the undo: a redo from here, and the deadline, count
	 * from it).
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor ("reverted").
	 * @return StepResult
	 */
	private function ask_after_undo( JobContext $context, array $cursor ): StepResult {
		$found = self::after_undo_text( $cursor );
		ExportPlan::write(
			$context->work_path(),
			RestoreFiles::AFTER_UNDO,
			array(
				'verdict' => (string) $cursor['after_undo'],
				'found'   => $found,
			)
		);
		$cursor = array(
			'phase'     => 'undone_ask',
			'mark'      => $cursor['mark'] ?? '',
			'reason'    => (string) ( $cursor['reason'] ?? self::ASKED ),
			'check'     => (string) ( $cursor['check'] ?? '' ),
			'undone_at' => $this->now(),
			'ask'       => $this->random(), // This asking: an answer counts only when it was given to it (answered()).
		) + array_intersect_key( $cursor, array_flip( array( 'out', 'after_undo', 'after_undo_found' ) ) );
		$context->checkpoint( $cursor, 100, __( 'Waiting for your decision on 1 question', 'wp-checkpoint' ) );
		$this->at( 'undone_ask_recorded' );
		$context->logger()->warning( 'The site as put back by the undo did not pass its check; it is open, and the undo asks whether to keep it or swap the restored copy back in', array( 'found' => $found ) );
		return $this->after_undo_question( $cursor );
	}

	/**
	 * The question of "undone_ask".
	 *
	 * @param array<string, mixed> $cursor Cursor ("undone_ask").
	 * @return StepResult
	 */
	private function after_undo_question( array $cursor ): StepResult {
		return StepResult::ask(
			$cursor,
			array(
				array(
					'id'      => self::AFTER_UNDO_QUESTION,
					'kind'    => self::AFTER_UNDO_KIND,
					'file'    => RestoreFiles::AFTER_UNDO,
					'choices' => array( RestoreJob::KEEP, self::REDO ),
				),
			),
			__( 'Waiting for your decision on 1 question', 'wp-checkpoint' )
		);
	}

	/**
	 * An undo that asks whether to keep the site it put back: asked again until it is answered; swapped back in by the
	 * redo's own way (REDO: the answer is its confirmation, every check of the redo is made; "redo" recorded first, so
	 * that a redo given up ends as one); kept (KEEP, ended at the answer itself, JobRepository::answer(); one that
	 * reached the step another way ends here the same).
	 *
	 * @param JobContext           $context Context.
	 * @param Queries              $db      Connection.
	 * @param array<string, mixed> $cursor  Cursor ("undone_ask").
	 * @return StepResult
	 * @throws Undone When the site is kept.
	 */
	private function undone_asked( JobContext $context, Queries $db, array $cursor ): StepResult {
		$answer = self::answered( $context, $cursor, self::AFTER_UNDO_QUESTION );
		$at     = (int) ( $cursor['undone_at'] ?? 0 );
		if ( RestoreJob::KEEP === $answer ) {
			$context->logger()->warning( 'The site as put back by the undo was kept, as chosen' );
			throw Undone::back( self::undone_text( $cursor ), $at );
		}
		if ( self::REDO !== $answer ) {
			$this->take_down( $context, $cursor );
			return $this->after_undo_question( $cursor );
		}
		$context->logger()->warning( 'The restored copy is swapped back in, as answered after the undo' );
		try {
			$attempt = (int) ( ExportPlan::read( $context->work_path(), RestoreFiles::SWAP_PLAN )['attempt'] ?? 0 );
		} catch ( \RuntimeException $e ) {
			$attempt = 0; // The redo's own check says that the plan cannot be read.
		}
		$cursor = self::redo_cursor( $attempt, (string) ( $cursor['mark'] ?? '' ), $at );
		$context->checkpoint( $cursor, 5, __( 'Getting ready to swap the restored copy back in', 'wp-checkpoint' ) );
		return $this->redo( $context, $db, $cursor );
	}

	/**
	 * A redo before it changed the site ("redo"): the time a redo can be made in (Undo::WINDOW_SECONDS from the undo),
	 * then the checks that can change while it waited (Redo::again(): what is in the way, the plan, where its entries
	 * are, the tables made since, this plugin's copy; the plan's attempt the one the redo was asked for), then the swap
	 * getting ready as a swap does (ready(): no time limit, the site's directories where the staging layout has them,
	 * the plan as the final check writes it, someone else's maintenance file waited out). What does not hold gives the
	 * redo up (give_up_redo()); then the swap (swap()).
	 *
	 * @param JobContext           $context Context.
	 * @param Queries              $db      Connection.
	 * @param array<string, mixed> $cursor  Cursor ("redo").
	 * @return StepResult
	 * @throws Undone When it is given up.
	 */
	private function redo( JobContext $context, Queries $db, array $cursor ): StepResult {
		if ( 0 !== $context->job()->cancel_requested ) {
			// Asked for while a run held it (JobRepository::cancel_redo()): seen by every run before it changes the site,
			// not only by the write that records "enter" (a run that waits for someone else's maintenance file would
			// otherwise wait on).
			throw $this->give_up_redo( $context, $cursor, __( 'It was cancelled.', 'wp-checkpoint' ) );
		}
		$why = $this->redo_check( $context, $db, $cursor );
		if ( '' !== $why ) {
			throw $this->give_up_redo( $context, $cursor, $why );
		}
		try {
			$waiting = $this->ready( $context, $db, $cursor );
		} catch ( LockLost $e ) {
			throw $e;
		} catch ( StaleJob $e ) {
			throw $e;
		} catch ( TransientFailure $e ) {
			throw $e;
		} catch ( \RuntimeException $e ) {
			throw $this->give_up_redo( $context, $cursor, $e->getMessage() );
		}
		if ( null !== $waiting ) {
			return $waiting;
		}
		return $this->swap( $context, $db, $cursor );
	}

	/**
	 * Why a redo does not go on now ('' when it does): the time it can be made in ran out, or Redo::again() says why.
	 *
	 * @param JobContext           $context Context.
	 * @param Queries              $db      Connection.
	 * @param array<string, mixed> $cursor  Cursor ("redo", "redo_ask").
	 * @return string
	 */
	private function redo_check( JobContext $context, Queries $db, array $cursor ): string {
		$at = self::redo_at( $cursor );
		if ( ! Redo::within_window( $at, $this->now() ) ) {
			/* translators: 1: days, 2: date and time of the undo (UTC) */
			return sprintf( __( 'a redo can be made for %1$d days after the undo (%2$s UTC), and that time ran out.', 'wp-checkpoint' ), (int) ( Undo::WINDOW_SECONDS / 86400 ), gmdate( 'Y-m-d H:i', $at ) );
		}
		$this->at( 'redo_checking' );
		$seen = $this->redo_gate()->again( $context->job(), $at, $db );
		if ( '' !== $seen['why'] ) {
			return $seen['why'];
		}
		if ( (int) ( $cursor['attempt'] ?? 0 ) !== $seen['attempt'] ) {
			return __( 'The restore\'s plan is not the one the redo was asked for.', 'wp-checkpoint' );
		}
		return '';
	}

	/**
	 * Give a redo up before it changed the site: undone again as of the undo it set out from (Undone::back()), the site
	 * as the undo left it (no maintenance file was put up, or it was taken down before the position said so).
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor (its undone_at).
	 * @param string               $why     Why.
	 * @return Undone
	 */
	private function give_up_redo( JobContext $context, array $cursor, string $why ): Undone {
		$context->logger()->warning( 'The redo was given up before it changed the site', array( 'reason' => $why ) );
		/* translators: %s: why */
		return Undone::back( sprintf( __( 'The redo did not go on: %s The site stays as the undo left it.', 'wp-checkpoint' ), $why ), self::redo_at( $cursor ) );
	}

	/**
	 * A redo that asked how to go on after its carry failed ("redo_ask"): asked again until it is answered; given up
	 * (KEEP: ended at the answer itself, JobRepository::answer(); one that reached the step another way ends here the
	 * same); or swapped back in without the carry (REDO), its checks made again, then "redo" recorded with "carry"
	 * "skip", where the rows that would be lost are compared with the ones listed once the file is held (carry_in()).
	 *
	 * @param JobContext           $context Context.
	 * @param Queries              $db      Connection.
	 * @param array<string, mixed> $cursor  Cursor ("redo_ask").
	 * @return StepResult
	 * @throws Undone When it is given up.
	 */
	private function redo_asked( JobContext $context, Queries $db, array $cursor ): StepResult {
		$answer = self::answered( $context, $cursor, self::REDO_CARRY_QUESTION );
		if ( RestoreJob::KEEP === $answer ) {
			throw $this->give_up_redo( $context, $cursor, __( 'It was given up as chosen.', 'wp-checkpoint' ) );
		}
		if ( self::REDO !== $answer ) {
			return $this->redo_question( $context, $cursor, $this->redo_carry_names( $context ) );
		}
		$cursor          = self::redo_cursor( (int) $cursor['attempt'], (string) $cursor['mark'], self::redo_at( $cursor ) );
		$cursor['carry'] = 'skip';
		$context->checkpoint( $cursor, 5, __( 'Getting ready to swap the restored copy back in', 'wp-checkpoint' ) );
		return $this->redo( $context, $db, $cursor );
	}

	/**
	 * The answer to a redo's question or an undo's question about the site it put back, when it was given to the asking
	 * the cursor records ("ask"; JobRepository::answer() records which asking an answer was given to, in its write): an
	 * answer left from an earlier asking (an earlier undo of the same job, or an answer voided because the rows it was
	 * about changed) is not taken for a new one, even by a run that finds the position recorded and the question not
	 * written yet (the two are separate writes). Null when there is none for this asking.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor ("undone_ask", "redo_ask").
	 * @param string               $id      The question.
	 * @return mixed
	 */
	private static function answered( JobContext $context, array $cursor, string $id ) {
		$options = $context->options();
		$ask     = (string) ( $cursor['ask'] ?? '' );
		$given   = (string) ( $options[ JobRepository::ANSWERED ][ $id ] ?? '' );
		if ( '' === $ask || $given !== $ask ) {
			return null;
		}
		return $options['answers'][ $id ] ?? null;
	}

	/**
	 * The checks of a redo (Redo), as JobActions has them (tests give the step its own: the "redo" part).
	 *
	 * @return Redo
	 */
	private function redo_gate(): Redo {
		if ( isset( $this->parts['redo'] ) && $this->parts['redo'] instanceof Redo ) {
			return $this->parts['redo'];
		}
		return \WPCheckpoint\Plugin::instance()->job_actions()->redo_gate();
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
		) + ( $out ? array() : self::redo_keys( $cursor ) ); // Undone after a redo was let out: an undo of its own.
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
