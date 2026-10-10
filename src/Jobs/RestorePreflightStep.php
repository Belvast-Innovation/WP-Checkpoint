<?php
/**
 * A restore's preflight: what it will create, under which names, and whether that can work.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Archive\ChunkHasher;
use WPCheckpoint\Archive\EnvironmentFailure;
use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Archive\ManifestError;
use WPCheckpoint\Archive\ZipFormat;
use WPCheckpoint\Archive\ZipReader;
use WPCheckpoint\Database\WpdbConnection;
use WPCheckpoint\Restore\ChunkReader;
use WPCheckpoint\Restore\ChunkWalk;
use WPCheckpoint\Restore\CollationRules;
use WPCheckpoint\Restore\ConstraintNames;
use WPCheckpoint\Restore\ImportTarget;
use WPCheckpoint\Restore\IncomingQuestions;
use WPCheckpoint\Restore\IncomingTables;
use WPCheckpoint\Restore\Refused;
use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Restore\SiteTables;
use WPCheckpoint\Restore\Statement;
use WPCheckpoint\Restore\TableMoves;
use WPCheckpoint\Restore\TablePlan;
use WPCheckpoint\Restore\TargetIncompatible;
use WPCheckpoint\Database\OwnTables;
use WPCheckpoint\Support\Utf8;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- job errors with table names; the presenter cleans them.
// phpcs:disable WordPress.WP.AlternativeFunctions -- files in the job's work directory.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- reads of the schema.

/**
 * Nothing is created here; the restore stops before anything is touched
 * when a check fails. Three phases:
 *
 * 1. plan: the manifest (the verify step's copy) must describe a database
 *    and a site of the same kind as this one (a network's backup is not
 *    restored onto a single site, or the reverse); the table plan
 *    (TablePlan: temporary and final names, which are fixed here with the
 *    restore's random part and never change); database.index.jsonl
 *    extracted from the last volume and checked against the manifest's
 *    hash. Before that: a server that compares table names without case
 *    is refused unless every name involved is lowercase
 *    (IncomingTables::fold_refusal()); the usermeta table this site uses
 *    is walked for other installations' capabilities keys, a window of
 *    ids per unit (the progress shown), on from where a tick stopped; then
 *    a table of the backup whose live table another installation in the
 *    same database uses (IncomingTables) is judged: one of that
 *    installation's own is left out; one that may be either's, and one
 *    this site shares with it, are left to the user's choice, by kind
 *    (RestoreJob::POLICIES). When a choice is missing, both questions are
 *    asked at once (IncomingQuestions: each named after its tables, so an
 *    answer holds for those only; the tables shown from
 *    RestoreFiles::INCOMING), and the phase runs again with the answers.
 * 2. heads: every chunk of every planned table, in lockstep (ChunkWalk),
 *    one per unit: the head of the chunk (at most HEAD_BYTES, or FIRST_BYTES
 *    for a table's first chunk) is extracted and read by the same reader
 *    the import runs (ChunkReader, heads only): the first chunk must hold
 *    the table's CREATE TABLE, whose definition (stored columns, primary
 *    key, foreign keys, engine) is appended to RestoreFiles::DEFINITIONS;
 *    the first INSERT of every chunk must list exactly those columns. A
 *    table the manifest lists without a chunk has no definition and is
 *    refused.
 * 3. collations: every collation the definitions name (the table option, a
 *    column, an expression, a CHECK), against the ones this server knows
 *    (SHOW COLLATION: information_schema.COLLATIONS leaves out names
 *    MariaDB 10.10 and later know, such as utf8mb4_uca1400_ai_ci). A name
 *    the server knows is kept; one it does not know is written under the
 *    first name CollationRules offers that the server knows, each such
 *    mapping logged and written to RestoreFiles::COLLATIONS for the import;
 *    one with no such name stops the restore for good
 *    (TargetIncompatible), before any table is created, naming every
 *    missing collation and every table that uses one. The definitions are
 *    read COLLATION_LINES a unit. How many were mapped is recorded on the
 *    job (JobContext::record()) for the swap's last word.
 * 4. references: the foreign keys that would cross the swap. The swap
 *    moves aside the live tables TableMoves shows to be this site's and
 *    puts the restored ones in their place; another installation's
 *    tables, tables of other prefixes, this plugin's run tables and the
 *    tables left out of the restore stay. A restored table's
 *    key to a table that is moved aside and not restored would point at
 *    the old table after the swap; a staying table's key to a moved table
 *    would too (InnoDB follows the rename), holding the new data to the
 *    old rows and keeping the old tables from being deleted. Either
 *    refuses the restore with both ways out. A restored key to a table
 *    that exists nowhere is logged as a warning (inserts into that table
 *    will fail after the restore, as they would have on the backed-up
 *    site). The live keys are read from
 *    information_schema.REFERENTIAL_CONSTRAINTS a page per unit.
 */
final class RestorePreflightStep implements Step {

	const ID = 'restore_preflight';

	/**
	 * Head of a table's first chunk read: its CREATE TABLE and the head of its first INSERT.
	 */
	const FIRST_BYTES = ChunkReader::MAX_STATEMENT_BYTES + 1048576;

	/**
	 * Head of a later chunk read: the preamble and the head of its first INSERT.
	 */
	const HEAD_BYTES = 1048576;

	/**
	 * Live foreign keys read per unit.
	 */
	const PAGE = 1000;

	/**
	 * Table definitions read in one unit of the collations phase.
	 */
	const COLLATION_LINES = 200;

	/**
	 * Ids of the usermeta table read per unit of the walk for other installations' capabilities keys.
	 */
	const META_WINDOW = 5000;

	/**
	 * Other installations' prefixes found in the usermeta table beyond which the search stops as one that could not
	 * finish (the restore asks all the same).
	 */
	const MAX_EVIDENCE = 100;

	/**
	 * Returns the backups directory: function(): string.
	 *
	 * @var callable
	 */
	private $backups;

	/**
	 * Head of a later chunk read (tests make it small).
	 *
	 * @var int
	 */
	private $head_bytes;

	/**
	 * Test seams: "deleting" function(): void, before the lease is confirmed for each deletion of what an earlier
	 * attempt left (a test stops a run there).
	 *
	 * @var array<string, mixed>
	 */
	private $parts;

	/**
	 * Constructor.
	 *
	 * @param callable             $backups    function(): string.
	 * @param int                  $head_bytes Head of a later chunk read.
	 * @param array<string, mixed> $parts      Test seams.
	 */
	public function __construct( callable $backups, int $head_bytes = self::HEAD_BYTES, array $parts = array() ) {
		$this->backups    = $backups;
		$this->head_bytes = max( 1, $head_bytes );
		$this->parts      = $parts;
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
	 * Run the phases.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws Refused When the restore cannot work.
	 * @throws TransientFailure When a work file cannot be written.
	 */
	public function run( JobContext $context ): StepResult {
		$cursor = $context->cursor();
		if ( array() === $cursor ) {
			// The step's start, in a first attempt or in one that starts over here: what an earlier attempt recorded
			// is listed before anything else (PreviousAttempt).
			$cursor = array( 'phase' => 'plan' );
			if ( PreviousAttempt::record( $context ) ) {
				$cursor = array_merge( array( 'phase' => 'reclaim' ), PreviousAttempt::start() );
				$context->checkpoint( $cursor, 0, __( 'Removing what an earlier attempt of the restore left', 'wp-checkpoint' ) );
			}
		}
		$cursor = array_merge(
			array(
				'phase' => 'plan',
			),
			$cursor
		);
		if ( 'reclaim' === $cursor['phase'] ) {
			$reclaimed = $this->reclaim( $context, $cursor );
			if ( null !== $reclaimed ) {
				return $reclaimed;
			}
			$cursor = array( 'phase' => 'plan' );
			$context->checkpoint( $cursor, 0, __( 'Reading the backup\'s tables', 'wp-checkpoint' ) );
		}
		if ( 'plan' === $cursor['phase'] ) {
			$planned = $this->plan( $context, $cursor );
			if ( $planned instanceof StepResult ) {
				return $planned;
			}
			$cursor = $planned;
			$context->checkpoint( $cursor, 5, __( 'Reading the backup\'s tables', 'wp-checkpoint' ) );
		}
		$work = $context->work_path();
		$plan = self::load_plan( $work );
		if ( 'heads' === $cursor['phase'] ) {
			$result = $this->heads( $context, $cursor, $plan );
			if ( null !== $result ) {
				return $result;
			}
		}
		if ( 'collations' === $cursor['phase'] ) {
			$result = $this->collations( $context, $cursor );
			if ( null !== $result ) {
				return $result;
			}
		}
		return $this->references( $context, $cursor, $plan );
	}

	/**
	 * The reclaim phase: what an earlier attempt left, a unit at a time within the budget (the first unit of a tick
	 * always runs), each deletion right after the lease is confirmed. Null once it is all done.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor ("reclaim" and the position in the list).
	 * @return StepResult|null
	 */
	private function reclaim( JobContext $context, array $cursor ): ?StepResult {
		$position = array(
			'part'  => 'roots' === ( $cursor['part'] ?? '' ) ? 'roots' : 'tables',
			'r'     => (int) ( $cursor['r'] ?? 0 ),
			'left'  => array_map( 'strval', (array) ( $cursor['left'] ?? array() ) ),
			'units' => (int) ( $cursor['units'] ?? 0 ),
		);
		$seam     = $this->parts['deleting'] ?? null;
		$confirm  = static function () use ( $context, $seam ): void {
			if ( is_callable( $seam ) ) {
				call_user_func( $seam );
			}
			$context->confirm_lease();
		};
		$message  = __( 'Removing what an earlier attempt of the restore left', 'wp-checkpoint' );
		$first    = true;
		while ( true ) {
			if ( ! $first && $context->should_stop() ) {
				return StepResult::progress( array_merge( array( 'phase' => 'reclaim' ), $position ), 0, $message );
			}
			$first = false;
			if ( PreviousAttempt::unit( $context, $position, $confirm ) ) {
				return null;
			}
			if ( $context->should_checkpoint( 0 ) ) {
				$context->checkpoint( array_merge( array( 'phase' => 'reclaim' ), $position ), 0, $message );
			}
		}
	}

	/**
	 * The plan phase: returns the cursor of the heads phase, or the questions about the tables another installation
	 * may use.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor (the walk over the usermeta table, when it went on in a tick before).
	 * @return array<string, mixed>|StepResult
	 * @throws Refused When the backup does not fit this site.
	 */
	private function plan( JobContext $context, array $cursor ) {
		global $wpdb;
		$work     = $context->work_path();
		$options  = RestoreJob::options( $context->options() );
		$manifest = self::manifest( $work );
		$site     = $manifest->site();
		$contents = $manifest->to_array()['contents'];
		// A chunk is at most chunk_bytes long, and chunk_bytes at most Limits::CONTENT_CHUNK_BYTES: the check
		// before this step refuses a backup with larger hash chunks as unsupported. So each chunk is extracted and
		// hashed in one unit.
		if ( empty( $contents['database'] ) ) {
			throw new Refused( 'This backup holds no database; restoring files alone is not available yet.' );
		}
		$multisite = ! empty( $site['multisite'] );
		if ( is_multisite() !== $multisite ) {
			throw new Refused( $multisite ? 'This backup is of a multisite network and this site is a single site; it can only be restored onto a network.' : 'This backup is of a single site and this site is a multisite network; it can only be restored onto a single site.' );
		}
		$fold    = SiteTables::fold_case();
		$refusal = IncomingTables::fold_refusal( $fold, (string) $wpdb->base_prefix, defined( 'CUSTOM_USER_TABLE' ) ? (string) CUSTOM_USER_TABLE : null, defined( 'CUSTOM_USER_META_TABLE' ) ? (string) CUSTOM_USER_META_TABLE : null, array_column( $manifest->tables(), 'name' ), array(), $options['exclude_tables'] );
		if ( '' !== $refusal ) {
			throw new Refused( $refusal );
		}
		$walked = empty( $cursor['meta']['done'] );
		$meta   = $this->usermeta( $context, $cursor, $multisite );
		if ( $meta instanceof StepResult ) {
			return $meta;
		}
		if ( $fold ) {
			// The live tables, once the walk is over (listing a whole network's tables in every tick of it would not be).
			$refusal = IncomingTables::fold_refusal( true, (string) $wpdb->base_prefix, null, null, array(), ( new WpdbConnection() )->tables_with_prefix( (string) $wpdb->base_prefix )['tables'] );
			if ( '' !== $refusal ) {
				throw new Refused( $refusal );
			}
		}
		if ( $walked && $context->should_stop() ) {
			// The walk ended in this tick: the rest of the plan waits for the next one.
			return StepResult::progress(
				array(
					'phase' => 'plan',
					'meta'  => $meta,
				),
				self::meta_percent( $meta ),
				__( 'Looking for other installations\' users in this site\'s user table', 'wp-checkpoint' )
			);
		}
		$random = bin2hex( random_bytes( 2 ) );
		$plan   = TablePlan::make( $manifest->tables(), (string) ( $site['table_prefix'] ?? '' ), (string) $wpdb->base_prefix, $multisite, $options['exclude_tables'], $context->job()->storage_token, $context->job()->id, $random, $fold );
		$judged = array();
		$skip   = $this->incoming( $context, $plan, $multisite, $options['policy'], $meta, $judged );
		if ( $skip instanceof StepResult ) {
			return $skip;
		}
		if ( array() !== $skip ) {
			$plan = TablePlan::make( $manifest->tables(), (string) ( $site['table_prefix'] ?? '' ), (string) $wpdb->base_prefix, $multisite, $options['exclude_tables'], $context->job()->storage_token, $context->job()->id, $random, $fold, $skip );
		}
		foreach ( $plan->tables() as $table ) {
			if ( $table['chunks'] < 1 ) {
				throw new Refused( sprintf( 'The backup holds no definition of the table %s (no chunk); leave it out of the restore.', $table['table'] ) );
			}
		}
		$volumes = array();
		$backups = (string) call_user_func( $this->backups );
		foreach ( $manifest->volumes() as $volume ) {
			$volumes[] = $backups . DIRECTORY_SEPARATOR . (string) $volume['path'];
		}
		self::extract_index( $manifest, (string) end( $volumes ), $work );
		ExportPlan::write(
			$work,
			RestoreFiles::PLAN,
			array(
				'plan'        => $plan->to_array(),
				'random'      => $random,
				'chunk_bytes' => $manifest->chunk_bytes(),
				'volumes'     => $volumes,
				'multisite'   => $multisite,
				'incoming'    => $judged,
			)
		);
		foreach ( $plan->skipped() as $name => $reason ) {
			$context->logger()->info(
				'A table of the backup is not restored',
				array(
					'table'  => $name,
					'reason' => $reason,
				)
			);
		}
		$definitions = RestoreFiles::path( $work, RestoreFiles::DEFINITIONS );
		if ( false === @file_put_contents( $definitions, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would put the path into the error log.
			throw new TransientFailure( 'A work file of the restore could not be written.' );
		}
		return array(
			'phase'   => 'heads',
			'walk'    => ChunkWalk::start(),
			'defined' => 0,
			'table'   => -1,
			'chunk'   => 0,
			'line'    => 0,
		);
	}

	/**
	 * The walk over the usermeta table this site uses for other installations' capabilities keys
	 * (IncomingTables::evidence()), on from where a tick before stopped. Returns the walk's state once it is over, or
	 * the progress to go on from.
	 *
	 * @param JobContext           $context   Context.
	 * @param array<string, mixed> $cursor    Cursor.
	 * @param bool                 $multisite Whether this site is a network.
	 * @return array{after: int, last: int, found: string[], over: bool, done: bool}|StepResult The prefixes found in hex.
	 */
	private function usermeta( JobContext $context, array $cursor, bool $multisite ) {
		$message = __( 'Looking for other installations\' users in this site\'s user table', 'wp-checkpoint' );
		$walk    = self::search_usermeta(
			$context,
			isset( $cursor['meta'] ) && is_array( $cursor['meta'] ) ? $cursor['meta'] : null,
			$multisite,
			static function ( array $meta ) use ( $context, $message ): void {
				$context->checkpoint(
					array(
						'phase' => 'plan',
						'meta'  => $meta,
					),
					self::meta_percent( $meta ),
					$message
				);
			}
		);
		if ( ! $walk['done'] ) {
			return StepResult::progress(
				array(
					'phase' => 'plan',
					'meta'  => $walk['meta'],
				),
				self::meta_percent( $walk['meta'] ),
				$message
			);
		}
		return $walk['meta'];
	}

	/**
	 * Walk the usermeta table this site uses for other installations' capabilities keys (IncomingTables::evidence()),
	 * a window of ids per unit, on from $meta: the first unit always runs, then it goes on while the budget lasts. The
	 * preflight walks it, and the swap walks it again before it changes anything (SwapStep).
	 *
	 * @param JobContext                $context   Context.
	 * @param array<string, mixed>|null $meta      The walk's state (null: from the start).
	 * @param bool                      $multisite Whether this site is a network.
	 * @param callable                  $save      function( array $meta ): void, a checkpoint of the caller's cursor with it.
	 * @return array{meta: array{after: int, last: int, found: string[], over: bool, done: bool}, done: bool} The prefixes
	 *                                                                                                      found in hex.
	 */
	public static function search_usermeta( JobContext $context, $meta, bool $multisite, callable $save ): array {
		global $wpdb;
		$meta    = is_array( $meta ) ? array(
			'after' => (int) ( $meta['after'] ?? 0 ),
			'last'  => (int) ( $meta['last'] ?? 0 ),
			'found' => array_map( 'strval', (array) ( $meta['found'] ?? array() ) ),
			'over'  => ! empty( $meta['over'] ),
			'done'  => ! empty( $meta['done'] ),
		) : array(
			'after' => 0,
			'last'  => SiteTables::last_meta_id(),
			'found' => array(),
			'over'  => false,
			'done'  => false,
		);
		$prefix  = (string) $wpdb->base_prefix;
		$sites   = array();
		$is_blog = static function ( int $blog_id ) use ( &$sites ): bool {
			if ( ! isset( $sites[ $blog_id ] ) ) {
				$sites[ $blog_id ] = SiteTables::blog_exists( $blog_id ); // Once per site in a tick, whatever the rows.
			}
			return $sites[ $blog_id ];
		};
		$first   = true;
		$read    = 0;
		while ( ! $meta['done'] ) {
			if ( ! $first && $context->should_stop() ) {
				return array(
					'meta' => $meta,
					'done' => false,
				);
			}
			$first  = false;
			$window = SiteTables::capability_keys( $meta['after'], self::META_WINDOW );
			$found  = array_fill_keys( $meta['found'], true );
			foreach ( IncomingTables::evidence( $prefix, $multisite, $window['keys'], $is_blog ) as $owner ) {
				$found[ bin2hex( $owner ) ] = true;
			}
			$meta['found'] = array_map( 'strval', array_keys( $found ) );
			if ( count( $meta['found'] ) > self::MAX_EVIDENCE ) {
				// Too many to tell: a search that could not finish, asked about as one that found something.
				$meta['found'] = array_slice( $meta['found'], 0, self::MAX_EVIDENCE );
				$meta['over']  = true;
				$meta['done']  = true;
			} elseif ( null === $window['after'] ) {
				$meta['done'] = true;
			} else {
				$meta['after'] = (int) $window['after'];
			}
			$read += self::META_WINDOW * 255; // At most this much of meta keys per window.
			if ( ! $meta['done'] && $context->should_checkpoint( $read ) ) {
				call_user_func( $save, $meta );
				$read = 0;
			}
		}
		return array(
			'meta' => $meta,
			'done' => true,
		);
	}

	/**
	 * The step's progress while the usermeta table is walked (the plan phase is its first 5 %).
	 *
	 * @param array<string, mixed> $meta The walk's state.
	 * @return int
	 */
	private static function meta_percent( array $meta ): int {
		$last = max( 1, (int) ( $meta['last'] ?? 0 ) );
		return 1 + (int) floor( 3 * min( 1.0, (int) ( $meta['after'] ?? 0 ) / $last ) );
	}

	/**
	 * The backup's tables whose live table another installation may use (IncomingTables), by their final names: one of
	 * a neighbour's own is left out; the two kinds the user decides (RestoreJob::POLICIES) are left out or restored
	 * as answered or as the policy says (IncomingQuestions: an answer holds for the very tables its question listed).
	 * Asks both questions at once when a decision is missing.
	 *
	 * @param JobContext                                                            $context   Context.
	 * @param TablePlan                                                             $plan      The plan with every table of the backup.
	 * @param bool                                                                  $multisite Whether this site is a network.
	 * @param array<string, string>                                                 $policy    RestoreJob::options()'s policy.
	 * @param array{after: int, last: int, found: string[], over: bool, done: bool} $meta      The walk over the usermeta table.
	 * @param array<string, mixed>                                                  $judged    Set to the judgement (IncomingTables::judgement(), the
	 *                                                                                         evidence in hex) and the final names it judged.
	 * @return array<string, string>|StepResult Tables to leave out (names in the backup) => why, or the questions.
	 */
	private function incoming( JobContext $context, TablePlan $plan, bool $multisite, array $policy, array $meta, array &$judged ) {
		$finals = array();
		foreach ( $plan->tables() as $table ) {
			$finals[ $table['final'] ] = $table['table'];
		}
		$prefix   = $plan->site_prefix();
		$live     = ( new WpdbConnection() )->tables_with_prefix( $prefix )['tables'];
		$evidence = array_map( 'hex2bin', array_map( 'strval', (array) $meta['found'] ) );
		$over     = ! empty( $meta['over'] );
		$kinds    = IncomingTables::classify( $prefix, $multisite, $live, array_keys( $finals ), SiteTables::core(), SiteTables::users(), SiteTables::usermeta(), array() !== $evidence ); // A search that could not finish found MAX_EVIDENCE already.
		$judged   = array(
			'finals'    => array_map( 'strval', array_keys( $finals ) ),
			'judgement' => IncomingTables::judgement( $prefix, $multisite, $live, array_keys( $finals ), SiteTables::core(), SiteTables::users(), SiteTables::usermeta(), array_map( 'strval', (array) $meta['found'] ), $over ),
		);
		$listed   = array(
			IncomingTables::NEIGHBOUR => array(),
			IncomingTables::UNCERTAIN => array(),
			IncomingTables::SHARED    => array(),
		);
		foreach ( $kinds as $final => $kind ) {
			$listed[ $kind ][] = (string) $final;
		}
		$answers  = isset( $context->options()['answers'] ) && is_array( $context->options()['answers'] ) ? $context->options()['answers'] : array();
		$decision = IncomingQuestions::decide( $listed, $evidence, $over, $answers, $policy );
		$current  = array_column( $decision['questions'], 'id' );
		foreach ( IncomingQuestions::KINDS as $key => $kind ) {
			if ( array() !== $listed[ $kind ] ) {
				$current[] = IncomingQuestions::id( $key, $listed[ $kind ], $evidence, $over );
			}
		}
		$stale = array();
		foreach ( array_keys( $answers ) as $id ) {
			foreach ( array_keys( IncomingQuestions::KINDS ) as $key ) {
				if ( 0 === strpos( (string) $id, $key . '_' ) && ! in_array( (string) $id, $current, true ) ) {
					$stale[] = (string) $id; // Answered for other tables than these: it holds for none of them.
				}
			}
		}
		if ( array() !== $stale ) {
			$context->logger()->warning( 'An answer given for other tables than the restore would now ask about is not used', array( 'questions' => $stale ) );
		}
		if ( array() !== $decision['questions'] ) {
			$shown = array(
				'evidence' => array_map( array( Utf8::class, 'scrub' ), $evidence ),
				'over'     => $over,
			);
			foreach ( $listed as $kind => $tables ) {
				$shown[ $kind ] = array_map( array( Utf8::class, 'scrub' ), $tables );
			}
			ExportPlan::write( $context->work_path(), RestoreFiles::INCOMING, $shown ); // Only shown: the ids tie answers to tables.
			// Every table the questions are about, in the log (the questions list a few of them).
			$context->logger()->info(
				'Tables of the backup whose live tables another installation in the same database may use; asked what to do with them',
				array(
					'may_be_either'      => $listed[ IncomingTables::UNCERTAIN ],
					'shared'             => $listed[ IncomingTables::SHARED ],
					'its_own_skipped'    => $listed[ IncomingTables::NEIGHBOUR ],
					'other_installation' => $shown['evidence'],
					'search_unfinished'  => $over,
				)
			);
			$questions = array();
			foreach ( $decision['questions'] as $question ) {
				$question['file'] = RestoreFiles::INCOMING;
				$questions[]      = $question;
			}
			// Without the walk: after the answers the usermeta table is walked again, as the live tables are read again,
			// so the questions' ids (the evidence among them) are those of what is there then.
			return StepResult::ask(
				array( 'phase' => 'plan' ),
				$questions,
				sprintf(
					/* translators: %d: number of questions */
					_n( 'Waiting for your decision on %d question', 'Waiting for your decision on %d questions', count( $questions ), 'wp-checkpoint' ),
					count( $questions )
				)
			);
		}
		$skip = array();
		foreach ( $listed[ IncomingTables::NEIGHBOUR ] as $final ) {
			$skip[ $finals[ $final ] ] = IncomingTables::NEIGHBOUR;
		}
		foreach ( $decision['decided'] as $kind => $choice ) {
			$context->logger()->info(
				IncomingTables::SHARED === $kind ? 'Tables this site shares with another installation in the same database' : 'Tables that may belong to this site or to another installation in the same database',
				array(
					'tables' => $listed[ $kind ],
					'choice' => $choice,
					'from'   => $decision['from'][ $kind ],
				)
			);
			if ( 'exclude' === $choice ) {
				foreach ( $listed[ $kind ] as $final ) {
					$skip[ $finals[ $final ] ] = $kind;
				}
			}
		}
		return $skip;
	}

	/**
	 * The heads phase; null when it is over (the cursor then holds the references phase).
	 *
	 * @param JobContext                                                                                   $context Context.
	 * @param array<string, mixed>                                                                         $cursor  Cursor (updated).
	 * @param array{plan: TablePlan, random: string, chunk_bytes: int, volumes: string[], multisite: bool} $plan    Plan.
	 * @return StepResult|null
	 * @throws Refused When a chunk is not what the restore runs.
	 */
	private function heads( JobContext $context, array &$cursor, array $plan ) {
		$work        = $context->work_path();
		$walk        = new ChunkWalk( RestoreFiles::path( $work, RestoreFiles::INDEX ), $plan['volumes'], $plan['chunk_bytes'] );
		$definitions = RestoreFiles::path( $work, RestoreFiles::DEFINITIONS );
		self::truncate_to( $definitions, (int) $cursor['defined'] );
		$heads = RestoreFiles::path( $work, RestoreFiles::HEADS );
		if ( ! is_dir( $heads ) && ! @mkdir( $heads, 0700 ) && ! is_dir( $heads ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would put the path into the error log.
			throw new TransientFailure( 'A work directory of the restore could not be created.' );
		}
		$tables = $plan['plan']->tables();
		$names  = new ConstraintNames( $plan['random'] );
		while ( true ) {
			$chunk = $walk->at( $cursor['walk'] );
			if ( null === $chunk ) {
				if ( count( $tables ) - 1 !== (int) $cursor['table'] || $tables[ count( $tables ) - 1 ]['chunks'] !== (int) $cursor['chunk'] ) {
					throw new Refused( 'The database index ends before every chunk of the restored tables.' );
				}
				$cursor = array(
					'phase'   => 'collations',
					'line'    => 0,
					'written' => 0,
					'mapped'  => 0,
					'missing' => array(),
				);
				$context->checkpoint( $cursor, 56, __( 'Checking the collations', 'wp-checkpoint' ) );
				return null;
			}
			$line  = $chunk['line'];
			$table = $plan['plan']->find( $line['t'] );
			if ( null !== $table ) {
				$this->expect_order( $cursor, $table, $line['c'], $tables );
				$this->head( $chunk, $table, $heads, $definitions, $names, $plan['plan'] );
			} elseif ( ! array_key_exists( $line['t'], $plan['plan']->skipped() ) ) {
				throw new Refused( sprintf( 'The database index names the table %s, which the manifest does not list.', $line['t'] ) );
			}
			$cursor['walk'] = $chunk['next'];
			clearstatcache( true, $definitions );
			$cursor['defined'] = (int) filesize( $definitions );
			$percent           = 5 + (int) floor( 50 * $cursor['walk']['line'] / max( 1, array_sum( array_column( $tables, 'chunks' ) ) + count( $plan['plan']->skipped() ) ) );
			if ( $context->should_stop() ) {
				return StepResult::progress( $cursor, min( 55, $percent ), __( 'Reading the backup\'s tables', 'wp-checkpoint' ) );
			}
			if ( $context->should_checkpoint( 0 ) ) {
				$context->checkpoint( $cursor, min( 55, $percent ), __( 'Reading the backup\'s tables', 'wp-checkpoint' ) );
			}
		}
	}

	/**
	 * The collations phase: the definitions a page at a time (COLLATION_LINES; the cursor's "line" is the next),
	 * each table's collations against the server's (known_collations()), the outcome of a table that needs one
	 * written as a JSON line to RestoreFiles::COLLATIONS (the cursor's "written" is its committed length); "mapped"
	 * counts the mappings, "missing" the distinct names without a replacement. At the end a missing name stops the
	 * restore (TargetIncompatible, the tables read back from the file), else the count is recorded on the job and the
	 * references phase follows. Null when this phase is done in this tick.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor (updated).
	 * @return StepResult|null
	 * @throws TargetIncompatible When a collation has no replacement this server knows.
	 * @throws TransientFailure When the server's collations cannot be read, or a work file not written.
	 */
	private function collations( JobContext $context, array &$cursor ) {
		$work     = $context->work_path();
		$outcomes = RestoreFiles::path( $work, RestoreFiles::COLLATIONS );
		if ( ! is_file( $outcomes ) && false === @file_put_contents( $outcomes, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.
			throw new TransientFailure( 'A work file of the restore could not be written.' );
		}
		self::truncate_to( $outcomes, (int) $cursor['written'] );
		$known  = self::known_collations();
		$handle = @fopen( RestoreFiles::path( $work, RestoreFiles::DEFINITIONS ), 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.
		if ( false === $handle ) {
			throw new WorkLost( 'The table definitions of the restore are gone from the work directory.' );
		}
		try {
			$index = 0;
			$read  = 0;
			for ( $text = fgets( $handle ); false !== $text; $text = fgets( $handle ) ) {
				if ( $index++ < (int) $cursor['line'] ) {
					continue; // Done in an earlier unit.
				}
				$definition = json_decode( $text, true );
				if ( ! is_array( $definition ) || ! isset( $definition['table'] ) || ! is_array( $definition['collations'] ?? null ) ) {
					throw new WorkLost( 'The table definitions of the restore in the work directory are damaged.' );
				}
				$map     = array();
				$missing = array();
				foreach ( $definition['collations'] as $found ) {
					$name = (string) $found['name'];
					$to   = CollationRules::resolve( $name, $known );
					if ( null === $to ) {
						$missing[ strtolower( $name ) ] = $name;
						$context->logger()->warning(
							'A collation this server does not know has no name to write instead',
							array(
								'table' => (string) $definition['table'],
								'at'    => (string) $found['at'],
								'name'  => $name,
							)
						);
					} elseif ( $to !== $name ) {
						$map[ $name ] = $to;
						++$cursor['mapped'];
						$context->logger()->info(
							'A collation this server does not know is written under another name',
							array(
								'table' => (string) $definition['table'],
								'at'    => (string) $found['at'],
								'from'  => $name,
								'to'    => $to,
							)
						);
					}
				}
				if ( array() !== $map || array() !== $missing ) {
					$line = wp_json_encode(
						array(
							'n'       => (int) $definition['n'],
							'table'   => (string) $definition['table'],
							'map'     => $map,
							'missing' => array_values( $missing ),
						)
					);
					if ( false === $line ) {
						throw new Refused( sprintf( 'The collations of the table %s cannot be written down (names that are not UTF-8).', (string) $definition['table'] ) );
					}
					if ( false === @file_put_contents( $outcomes, $line . "\n", FILE_APPEND ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.
						throw new TransientFailure( 'A work file of the restore could not be written.' );
					}
					$cursor['missing'] = array_values( array_unique( array_merge( (array) $cursor['missing'], array_values( $missing ) ) ) );
				}
				$cursor['line'] = $index;
				clearstatcache( true, $outcomes );
				$cursor['written'] = (int) filesize( $outcomes );
				if ( ++$read >= self::COLLATION_LINES ) {
					$context->checkpoint( $cursor, 58, __( 'Checking the collations', 'wp-checkpoint' ) );
					if ( $context->should_stop() ) {
						return StepResult::progress( $cursor, 58, __( 'Checking the collations', 'wp-checkpoint' ) );
					}
					$read = 0;
				}
			}
		} finally {
			fclose( $handle );
		}
		if ( array() !== $cursor['missing'] ) {
			// Read whole: one line per table that needs a collation, read once, on the way to a failure for good.
			$tables = array();
			$lines  = file( $outcomes, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			foreach ( is_array( $lines ) ? $lines : array() as $text ) {
				$outcome = json_decode( $text, true );
				if ( is_array( $outcome ) && array() !== ( $outcome['missing'] ?? array() ) ) {
					$tables[] = (string) $outcome['table'];
				}
			}
			throw new TargetIncompatible( TargetIncompatible::COLLATION, (array) $cursor['missing'], $tables );
		}
		$context->record( 'collations_mapped', (int) $cursor['mapped'] );
		$cursor = array(
			'phase' => 'references',
			'page'  => 0,
		);
		$context->checkpoint( $cursor, 60, __( 'Checking the foreign keys', 'wp-checkpoint' ) );
		return null;
	}

	/**
	 * The collations this server knows, as CollationRules::resolve() takes them: SHOW COLLATION, which lists every
	 * one; information_schema.COLLATIONS does not (MariaDB 10.10 and later leave out utf8mb4_uca1400_ai_ci and its
	 * kind there), so a check that read it would replace names the server knows.
	 *
	 * @return array<string, bool>
	 * @throws TransientFailure When the server does not answer.
	 */
	public static function known_collations(): array {
		global $wpdb;
		$names = $wpdb->get_col( 'SHOW COLLATION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- the server's own list, read once per unit.
		if ( '' !== (string) $wpdb->last_error || ! is_array( $names ) || array() === $names ) {
			throw new TransientFailure( 'The collations of this database server could not be read.' );
		}
		return CollationRules::set( $names );
	}

	/**
	 * The collations of table $number the import writes under another name (RestoreFiles::COLLATIONS): the backup's
	 * name => the name to write; empty when it keeps every one.
	 *
	 * @param string $outcomes The file.
	 * @param int    $number   Table number.
	 * @return array<string, string>
	 * @throws WorkLost When the file is gone or damaged.
	 */
	public static function collations_of( string $outcomes, int $number ): array {
		$handle = @fopen( $outcomes, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.
		if ( false === $handle ) {
			throw new WorkLost( 'The collation check of the restore is gone from the work directory.' );
		}
		try {
			for ( $text = fgets( $handle ); false !== $text; $text = fgets( $handle ) ) {
				$outcome = json_decode( $text, true );
				if ( ! is_array( $outcome ) || ! isset( $outcome['n'] ) || ! is_array( $outcome['map'] ?? null ) ) {
					throw new WorkLost( 'The collation check of the restore in the work directory is damaged.' );
				}
				if ( $number === (int) $outcome['n'] ) {
					return array_map( 'strval', $outcome['map'] );
				}
			}
		} finally {
			fclose( $handle );
		}
		return array();
	}

	/**
	 * Require the chunks in the plan's order: each table's chunks 1..n, the tables one after another.
	 *
	 * @param array<string, mixed>                                                                         $cursor Cursor (updated: table, chunk).
	 * @param array{table: string, temporary: string, final: string, number: int, chunks: int}             $table  The line's table.
	 * @param int                                                                                          $chunk  The line's chunk number.
	 * @param array<int, array{table: string, temporary: string, final: string, number: int, chunks: int}> $tables Planned tables.
	 * @return void
	 * @throws Refused When the order is another.
	 */
	private function expect_order( array &$cursor, array $table, int $chunk, array $tables ): void {
		$current = (int) $cursor['table'];
		if ( $table['number'] === $current && $chunk === (int) $cursor['chunk'] + 1 && $chunk <= $table['chunks'] ) {
			$cursor['chunk'] = $chunk;
			return;
		}
		$done = $current < 0 || (int) $cursor['chunk'] === $tables[ $current ]['chunks'];
		if ( $done && $table['number'] === $current + 1 && 1 === $chunk ) {
			$cursor['table'] = $table['number'];
			$cursor['chunk'] = 1;
			return;
		}
		throw new Refused( sprintf( 'The database index lists chunk %d of the table %s out of order.', $chunk, $table['table'] ) );
	}

	/**
	 * Read one chunk's head; for a first chunk, append the table's definition.
	 *
	 * @param array<string, mixed>                                                             $chunk       ChunkWalk::at().
	 * @param array{table: string, temporary: string, final: string, number: int, chunks: int} $table       The table.
	 * @param string                                                                           $heads       Directory to extract into.
	 * @param string                                                                           $definitions Definitions file.
	 * @param ConstraintNames                                                                  $names       Constraint names.
	 * @param TablePlan                                                                        $plan        Plan.
	 * @return void
	 * @throws Refused When the head is not what the restore runs.
	 */
	private function head( array $chunk, array $table, string $heads, string $definitions, ConstraintNames $names, TablePlan $plan ): void {
		$line   = $chunk['line'];
		$first  = 1 === $line['c'];
		$known  = $first ? null : self::definition( $definitions, $table['number'] );
		$reader = $chunk['reader'];
		$entry  = $chunk['entry'];
		$stored = ZipFormat::METHOD_STORE === (int) $entry['method'];
		try {
			// A deflated entry has no addressable ranges and is read whole: at most Limits::INFLATE_BYTES, the check before
			// this step refuses a backup with a larger one as unsupported.
			$length = $stored ? ( $first ? self::FIRST_BYTES : $this->head_bytes ) : (int) $entry['usize'];
			$piece  = $reader->extract_piece( $entry, $heads, 0, $length, 0 );
		} catch ( EnvironmentFailure $e ) {
			throw $e;
		} catch ( \RuntimeException $e ) {
			throw new Refused( sprintf( 'The database chunk %s cannot be read from the backup: %s', $line['p'], $e->getMessage() ) );
		}
		$target = new ImportTarget(
			$table['table'],
			$table['temporary'],
			$table['final'],
			$table['number'],
			$names,
			array( $plan, 'reference' ),
			null !== $known ? $known['columns'] : null
		);
		$text   = new ChunkReader( $piece['path'], 0, $target, $line['c'], ChunkReader::READ_BYTES, true );
		$create = null;
		try {
			for ( $statement = $text->next(); null !== $statement; $statement = $text->next() ) {
				if ( Statement::CREATE === $statement->kind ) {
					if ( ! $first || null !== $create ) {
						throw new Refused( sprintf( 'The table %s is created again in its chunk %d.', $table['table'], $line['c'] ) );
					}
					$create          = $statement->create;
					$target->columns = $statement->columns;
				} elseif ( Statement::INSERT === $statement->kind ) {
					if ( $first && null === $create ) {
						throw new Refused( sprintf( 'The first chunk of the table %s inserts rows before it creates the table.', $table['table'] ) );
					}
					break;
				}
			}
		} finally {
			$text->close();
			@unlink( $piece['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the next head overwrites it anyway.
		}
		if ( $first ) {
			if ( null === $create ) {
				throw new Refused( sprintf( 'The first chunk of the table %s does not create it.', $table['table'] ) );
			}
			$definition = array(
				'n'              => $table['number'],
				'table'          => $table['table'],
				'columns'        => $create->stored_columns(),
				'primary'        => $create->primary_key(),
				'foreign'        => $create->foreign_keys(),
				'engine'         => $create->engine(),
				// TRUNCATE sets the counter back; a table started over gets this value again.
				'auto_increment' => $create->auto_increment(),
				'collations'     => $create->collations(),
			);
			$json       = json_encode( $definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- read back; a failure is thrown.
			if ( ! is_string( $json ) ) {
				throw new Refused( sprintf( 'The definition of the table %s cannot be written down (names that are not UTF-8).', $table['table'] ) );
			}
			if ( false === @file_put_contents( $definitions, $json . "\n", FILE_APPEND ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
				throw new TransientFailure( 'A work file of the restore could not be written.' );
			}
		}
	}

	/**
	 * The references phase.
	 *
	 * @param JobContext                                                                                   $context Context.
	 * @param array<string, mixed>                                                                         $cursor  Cursor.
	 * @param array{plan: TablePlan, random: string, chunk_bytes: int, volumes: string[], multisite: bool} $plan    Plan.
	 * @return StepResult
	 * @throws Refused When a foreign key would cross the swap.
	 */
	private function references( JobContext $context, array $cursor, array $plan ): StepResult {
		global $wpdb;
		$work    = $context->work_path();
		$table   = $plan['plan'];
		$live    = ( new WpdbConnection() )->tables_with_prefix( (string) $wpdb->base_prefix )['tables'];
		$staying = array_fill_keys( OwnTables::names( (string) $wpdb->base_prefix ), true );
		$left    = array();
		foreach ( array_keys( $table->skipped() ) as $name ) {
			$left[] = $table->final_name( (string) $name );
			$staying[ $table->final_name( (string) $name ) ] = true;
		}
		// What the swap moves aside, by its rule (TableMoves): this site's live tables, never another installation's.
		$finals = array_column( $table->tables(), 'final' );
		$moved  = array_fill_keys( array_merge( TableMoves::select( $table->site_prefix(), is_multisite(), $live, $finals, $left, SiteTables::core() )['move'], $finals ), true );
		if ( 0 === (int) $cursor['page'] ) {
			$handle = @fopen( RestoreFiles::path( $work, RestoreFiles::DEFINITIONS ), 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.
			if ( false === $handle ) {
				throw new WorkLost( 'The table definitions of the restore are gone from the work directory.' );
			}
			try {
				for ( $text = fgets( $handle ); false !== $text; $text = fgets( $handle ) ) {
					$definition = json_decode( $text, true );
					if ( ! is_array( $definition ) || ! isset( $definition['foreign'] ) || ! is_array( $definition['foreign'] ) ) {
						throw new WorkLost( 'The table definitions of the restore in the work directory are damaged.' );
					}
					foreach ( $definition['foreign'] as $key ) {
						$target = (string) $key['references'];
						if ( null !== $table->find( $target ) ) {
							continue;
						}
						$here = $table->final_name( $target );
						if ( isset( $moved[ $here ] ) ) {
							throw new Refused( sprintf( 'The table %1$s of the backup has a foreign key (%2$s) to %3$s, which the backup does not hold (or which is left out) and which the restore moves aside with the rest of this site\'s tables. After the restore the key would point at the old table. Restore %3$s\'s table as well, or leave %1$s out of the restore.', (string) $definition['table'], (string) $key['name'], $here ) );
						}
						if ( ! in_array( $here, $live, true ) && ! isset( $staying[ $here ] ) ) {
							$context->logger()->warning(
								'A restored table has a foreign key to a table that is neither in the backup nor on this site; inserts into it will fail until that table exists',
								array(
									'table'      => (string) $definition['table'],
									'key'        => (string) $key['name'],
									'references' => $here,
								)
							);
						}
					}
				}
			} finally {
				fclose( $handle );
			}
		}
		while ( true ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() ORDER BY TABLE_NAME, CONSTRAINT_NAME LIMIT %d OFFSET %d', self::PAGE, (int) $cursor['page'] * self::PAGE ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- the live schema, read once per restore.
			if ( '' !== (string) $wpdb->last_error ) {
				throw new TransientFailure( 'The foreign keys of this site could not be read from the database.' );
			}
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				list( $owner, $name, $referenced ) = array_map( 'strval', $row );
				if ( isset( $moved[ $owner ] ) || OwnTables::is_own( $owner ) || ! isset( $moved[ $referenced ] ) ) {
					continue;
				}
				throw new Refused( sprintf( 'The table %1$s stays as it is, but its foreign key %2$s references %3$s, which the restore replaces. InnoDB would keep the key on the old %3$s: the new data would be held to the old rows, and the old tables could not be deleted after the restore. Either restore %1$s too (it must be in the backup and not left out), or remove that foreign key first; then start the restore again.', $owner, $name, $referenced ) );
			}
			if ( ! is_array( $rows ) || count( $rows ) < self::PAGE ) {
				break;
			}
			$cursor['page'] = (int) $cursor['page'] + 1;
			if ( $context->should_stop() ) {
				return StepResult::progress( $cursor, 60, __( 'Checking the foreign keys', 'wp-checkpoint' ) );
			}
		}
		return StepResult::done( __( 'The backup\'s tables can be restored here', 'wp-checkpoint' ) );
	}

	/**
	 * The manifest copy.
	 *
	 * @param string $work Work directory.
	 * @return Manifest
	 * @throws WorkLost When the copy is gone or not a manifest.
	 */
	public static function manifest( string $work ): Manifest {
		$path = RestoreFiles::path( $work, RestoreFiles::MANIFEST );
		clearstatcache( true, $path );
		$json = is_file( $path ) && filesize( $path ) <= Manifest::MAX_JSON_BYTES ? @file_get_contents( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- bounded; reported below.
		if ( ! is_string( $json ) ) {
			throw new WorkLost( 'The copy of the manifest is gone from the work directory.' );
		}
		try {
			return Manifest::from_json( $json );
		} catch ( ManifestError $e ) {
			throw new WorkLost( 'The copy of the manifest in the work directory is not a manifest.' );
		}
	}

	/**
	 * The plan file.
	 *
	 * @param string $work Work directory.
	 * @return array{plan: TablePlan, random: string, chunk_bytes: int, volumes: string[], multisite: bool}
	 * @throws WorkLost When it is gone or damaged.
	 */
	public static function load_plan( string $work ): array {
		$data = ExportPlan::read( $work, RestoreFiles::PLAN );
		if ( ! isset( $data['plan'], $data['random'], $data['chunk_bytes'], $data['volumes'] ) || ! is_array( $data['plan'] ) || ! is_array( $data['volumes'] ) ) {
			throw new WorkLost( 'The plan of the restore in the work directory is damaged.' );
		}
		return array(
			'plan'        => TablePlan::from_array( $data['plan'] ),
			'random'      => (string) $data['random'],
			'chunk_bytes' => (int) $data['chunk_bytes'],
			'volumes'     => array_map( 'strval', $data['volumes'] ),
			'multisite'   => ! empty( $data['multisite'] ),
		);
	}

	/**
	 * The judgement of the backup's tables the preflight recorded in its plan file (incoming()): the final names it
	 * judged and IncomingTables::judgement(), the evidence in hex. Null when the plan has none in that form (a plan
	 * of an earlier version, or one changed): the swap then cannot compare, and starts the restore over at the
	 * preflight.
	 *
	 * @param string $work Work directory.
	 * @return array{finals: string[], judgement: array{neighbour: string[], uncertain: string[], shared: string[], evidence: string[], over: bool}}|null
	 * @throws WorkLost When the plan file is gone or damaged.
	 */
	public static function judged( string $work ): ?array {
		$data = ExportPlan::read( $work, RestoreFiles::PLAN );
		$in   = $data['incoming'] ?? null;
		if ( ! is_array( $in ) || ! isset( $in['finals'], $in['judgement'] ) || ! is_array( $in['finals'] ) || ! is_array( $in['judgement'] ) ) {
			return null;
		}
		$judgement = array();
		foreach ( array( 'neighbour', 'uncertain', 'shared', 'evidence' ) as $key ) {
			if ( ! isset( $in['judgement'][ $key ] ) || ! is_array( $in['judgement'][ $key ] ) ) {
				return null;
			}
			$judgement[ $key ] = array_map( 'strval', $in['judgement'][ $key ] );
		}
		if ( ! isset( $in['judgement']['over'] ) || ! is_bool( $in['judgement']['over'] ) ) {
			return null;
		}
		$judgement['over'] = $in['judgement']['over'];
		return array(
			'finals'    => array_map( 'strval', $in['finals'] ),
			'judgement' => $judgement,
		);
	}

	/**
	 * A table's definition from the definitions file.
	 *
	 * @param string $definitions File.
	 * @param int    $number      The table's number.
	 * @return array{n: int, table: string, columns: string[], primary: string[], foreign: array<int, array<string, mixed>>, engine: string, auto_increment?: string}
	 * @throws WorkLost When it is not there.
	 */
	public static function definition( string $definitions, int $number ): array {
		$handle = @fopen( $definitions, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.
		if ( false === $handle ) {
			throw new WorkLost( 'The table definitions of the restore are gone from the work directory.' );
		}
		try {
			for ( $text = fgets( $handle ); false !== $text; $text = fgets( $handle ) ) {
				$definition = json_decode( $text, true );
				if ( is_array( $definition ) && isset( $definition['n'], $definition['columns'] ) && $number === (int) $definition['n'] ) {
					return $definition;
				}
			}
		} finally {
			fclose( $handle );
		}
		throw new WorkLost( 'A table definition of the restore is missing from the work directory.' );
	}

	/**
	 * Extract database.index.jsonl from the last volume and check it against the manifest.
	 *
	 * @param Manifest $manifest Manifest.
	 * @param string   $volume   Last volume.
	 * @param string   $work     Work directory.
	 * @return void
	 * @throws Refused When it is missing or not the one the manifest describes.
	 */
	private static function extract_index( Manifest $manifest, string $volume, string $work ): void {
		$want = $manifest->database_index();
		try {
			$reader = ZipReader::open( $volume );
			$entry  = $reader->find( (string) $want['path'] );
			if ( null === $entry ) {
				throw new Refused( 'The backup\'s last volume holds no database index.' );
			}
			$dir = RestoreFiles::path( $work, RestoreFiles::HEADS );
			if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700 ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would put the path into the error log.
				throw new TransientFailure( 'A work directory of the restore could not be created.' );
			}
			$path = $reader->extract( $entry, $dir );
		} catch ( EnvironmentFailure $e ) {
			throw $e;
		} catch ( TransientFailure $e ) {
			throw $e;
		} catch ( Refused $e ) {
			throw $e;
		} catch ( \RuntimeException $e ) {
			throw new Refused( 'The backup\'s database index cannot be read: ' . $e->getMessage() );
		}
		$hash = ChunkHasher::content_hash( $path, $manifest->chunk_bytes() );
		if ( $hash['bytes'] !== (int) $want['bytes'] || ! hash_equals( (string) $want['sha256'], $hash['sha256'] ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort.
			throw new Refused( 'The backup\'s database index does not match its manifest; the backup is damaged.' );
		}
		if ( ! @rename( $path, RestoreFiles::path( $work, RestoreFiles::INDEX ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			throw new TransientFailure( 'A work file of the restore could not be written.' );
		}
	}

	/**
	 * Cut an appended file back to its committed length; fail when it is shorter.
	 *
	 * @param string $path   File.
	 * @param int    $length Committed length.
	 * @return void
	 * @throws WorkLost When the file is shorter than committed.
	 * @throws TransientFailure When it cannot be cut.
	 */
	private static function truncate_to( string $path, int $length ): void {
		clearstatcache( true, $path );
		$size = is_file( $path ) ? (int) filesize( $path ) : -1;
		if ( $size < $length ) {
			throw new WorkLost( 'A work file of the restore is shorter than recorded; the work directory was changed.' );
		}
		if ( $size > $length ) {
			$handle = @fopen( $path, 'r+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.
			if ( false === $handle || ! ftruncate( $handle, $length ) ) {
				throw new TransientFailure( 'A work file of the restore could not be cut back to its committed length.' );
			}
			fclose( $handle );
		}
	}

	/**
	 * Nothing to do: everything it wrote is in the work directory, which the engine removes.
	 *
	 * @param JobContext $context Context.
	 * @return void
	 */
	public function cleanup( JobContext $context ): void {
		unset( $context );
	}
}
