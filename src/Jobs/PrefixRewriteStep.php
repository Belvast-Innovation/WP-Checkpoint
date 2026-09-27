<?php
/**
 * A restore's rows named after the backup's table prefix, renamed for this site's.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Restore\ClaimLost;
use WPCheckpoint\Restore\ImportSession;
use WPCheckpoint\Restore\Ledger;
use WPCheckpoint\Restore\PrefixKeys;
use WPCheckpoint\Restore\Queries;
use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Restore\TablePlan;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Standalone\Failure;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- job errors with table names; the presenter cleans them.

/**
 * Runs after the database import, before the files are staged, on the
 * temporary tables only, and only when the backup's prefix P is not this
 * site's Q. PrefixKeys names the rows; each site of a network by the ids in
 * the backup's blogs table (the main site, blog 1, without an id). usermeta
 * keys are matched byte for byte (BINARY), not by the column's collation:
 * WordPress finds a user's meta by the exact key (its meta cache), so a
 * "WPX_capabilities" or a "wpx_capabilities " was never read on the
 * backed-up site and is not renamed into one that would be read here.
 * Option names are matched by the column's collation, as WordPress reads
 * them (get_option() ends in an SQL equality) and as the unique index on
 * the name holds them: one row per name under the collation. Phases, a site
 * group (the main site and up to "group" ids) at a time where sites matter:
 *
 * 1. options_report (P not empty): per site's options table, the options
 *    whose names start with P, the roles aside, are counted and the first
 *    named: only reported, never renamed.
 * 2. copy (P not empty): usermeta keys that start with P and are not known
 *    ones (PrefixKeys::known()) are copied under Q (PrefixKeys::copy_of()),
 *    the P row kept: a plugin's per-user option (update_user_option()) then
 *    keeps working. A copy whose name a row of that user already has is
 *    skipped and reported, not overwritten (that row may be a plugin's own,
 *    by its literal name); one whose name would not fit the column is
 *    skipped and reported; when Q starts with P, a copy's name is itself a
 *    name of the backup's own site, so nothing is copied and the keys are
 *    reported only, as is a copy whose name itself starts with P (when P
 *    starts with Q). Rows are taken in primary key order, a range and a
 *    number of rows at most per unit, up to the highest key at the plan
 *    (copies get higher keys and are never taken again); a row's existing
 *    copy above that key is how a replay knows it is done (so two rows of
 *    one user under one key make one copy, and both count as copied).
 * 3. a: every old name becomes its intermediate name (PrefixKeys).
 * 4. clear: rows already under a new name (in the backup, never read on its
 *    site, whose prefix was P) are counted per key and removed: WordPress
 *    here would read them (a leftover "{Q}capabilities" would give a user a
 *    role they did not have). Only the temporary tables are changed.
 * 5. b: every intermediate name becomes its new name.
 *
 * With P empty, only the exact names are renamed, and the report says that
 * other rows named after the prefix cannot be told from the rest.
 *
 * Each usermeta statement covers a primary key range ("range" ids) of the
 * table as it was when the phase began, the first from below 0 (a table
 * that lost its AUTO_INCREMENT holds rows at 0), so the rows it can touch are
 * bounded and the same whatever the order of a replay; each moves rows out
 * of what it matches, so running it again changes nothing, and a statement
 * cut short on a table without transactions goes on at the next run. Each
 * phase is only ever replayed on its own: the move to the next phase is
 * checkpointed before that phase's first statement (the clearing replayed
 * after the last phase began would remove renamed rows). Report counts are
 * added once per unit, under the unit's mark.
 *
 * Every statement carries its run's claim in itself, as the import's do:
 * each tick claims the rewrite's row of the restore's ledger (Ledger, row
 * CLAIM, the job's lease confirmed before and after), every statement
 * requires that row to still name this run, and the claim is confirmed
 * again before a report is written and before a group counts as done, so
 * a run that lost the job changes nothing once a newer run has claimed it.
 * The first unit of a tick always runs; the tick ends when the time left is
 * under 1.5 times the slowest unit so far, and a unit slower than the whole
 * budget ends the job with the reason.
 *
 * None of these names is one StateCarry writes before the swap (its names
 * start with "wpcheckpoint_" and its transients'), unless a table prefix
 * itself starts with "wpcheckpoint_": the swap's part of T042 handles that.
 */
final class PrefixRewriteStep implements Step {

	const ID     = 'restore_prefix';
	const MARGIN = 1.5;

	/**
	 * The rewrite's row in the restore's ledger (the imported tables are numbered from 0).
	 */
	const CLAIM = 4294967295;

	/**
	 * The longest usermeta key.
	 */
	const MAX_KEY_BYTES = 255;

	/**
	 * Rows of one user whose key equals a copy's name under the collation, looked at for the exact name.
	 */
	const VARIANTS = 100;

	/**
	 * Sizes: "range" usermeta ids per statement, "copy_range" ids and "copy_rows" rows per copy unit, "group"
	 * site ids per group, "listed" names per report section, "keys" distinct unknown keys counted one by one.
	 */
	const SIZES = array(
		'range'      => 10000,
		'copy_range' => 1000,
		'copy_rows'  => 200,
		'group'      => 100,
		'listed'     => 20,
		'keys'       => 200,
	);

	/**
	 * Returns the connection: function(): Queries.
	 *
	 * @var callable
	 */
	private $connect;

	/**
	 * Crash seam (tests): function( string $point ): void after a statement of each phase.
	 *
	 * @var callable|null
	 */
	private $at;

	/**
	 * Sizes (SIZES, tests may make them small).
	 *
	 * @var array<string, int>
	 */
	private $sizes;

	/**
	 * The restore's ledger table (this tick).
	 *
	 * @var string
	 */
	private $ledger_table = '';

	/**
	 * Constructor.
	 *
	 * @param callable|null      $connect function(): Queries (tests); the site's own settings by default.
	 * @param callable|null      $at      Crash seam (tests).
	 * @param array<string, int> $sizes   Sizes in place of SIZES' (tests).
	 */
	public function __construct( $connect = null, $at = null, array $sizes = array() ) {
		$this->at      = is_callable( $at ) ? $at : null;
		$this->sizes   = array_map( 'intval', $sizes ) + self::SIZES;
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
	 * Run the phases.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws WorkLost When the work directory changed.
	 * @throws ClaimLost When another run claimed the rewrite (retried after the back-off).
	 * @throws \RuntimeException When a unit takes longer than the whole budget.
	 */
	public function run( JobContext $context ): StepResult {
		$work   = $context->work_path();
		$loaded = RestorePreflightStep::load_plan( $work );
		$plan   = $loaded['plan'];
		$from   = $plan->backup_prefix();
		$to     = $plan->site_prefix();
		if ( $from === $to ) {
			return StepResult::done( __( 'The backup has this site\'s table prefix', 'wp-checkpoint' ) );
		}
		$cursor = array_merge( array( 'phase' => 'plan' ), $context->cursor() );
		$db     = call_user_func( $this->connect );
		$keys   = new PrefixKeys( $from, $to, 'wcp-' . $context->job()->id . '-' . $loaded['random'] . ':' );
		$tables = self::tables( $plan );
		$first  = true;
		try {
			$token              = bin2hex( random_bytes( 16 ) );
			$this->ledger_table = TempTables::ledger( $context->job()->storage_token, $context->job()->id, (string) $loaded['random'] );
			$ledger             = new Ledger( $db, $this->ledger_table, $token );
			$context->confirm_lease();
			$ledger->claim( self::CLAIM );
			$context->confirm_lease(); // A run whose lease ran out between the check and the claim stops here.
			$run = array(
				'db'     => $db,
				'ledger' => $ledger,
				'token'  => $token,
				'keys'   => $keys,
				'plan'   => $plan,
				'tables' => $tables,
			);
			if ( 'plan' === $cursor['phase'] ) {
				$cursor = $this->plan( $context, $run );
				$context->checkpoint( $cursor, 68, __( 'Renaming for this site\'s table prefix', 'wp-checkpoint' ) );
				$first = false;
			}
			$slowest = 0.0;
			$budget  = (float) $context->budget()->seconds;
			while ( 'done' !== $cursor['phase'] ) {
				if ( ! $first && ( $context->should_stop() || $context->remaining_seconds() < $slowest * self::MARGIN ) ) {
					return StepResult::progress( $cursor, 69, __( 'Renaming for this site\'s table prefix', 'wp-checkpoint' ) );
				}
				$first   = false;
				$started = $context->elapsed();
				$was     = $cursor['phase'];
				$cursor  = $this->unit( $context, $cursor, $run );
				$cost    = $context->elapsed() - $started;
				$slowest = max( $slowest, $cost );
				if ( $cost > $budget ) {
					throw new \RuntimeException( sprintf( 'Renaming the rows named after the table prefix is too slow on this database: one unit of work took %1$d seconds, more than the %2$d-second time budget of a single run.', (int) ceil( $cost ), (int) $budget ) );
				}
				// A phase ends in a checkpoint of its own: a replay never goes back into an earlier phase (the clearing
				// run again after the last phase had begun would remove renamed rows).
				if ( $was !== $cursor['phase'] || $context->should_checkpoint( 0 ) ) {
					$context->checkpoint( $cursor, 69, __( 'Renaming for this site\'s table prefix', 'wp-checkpoint' ) );
				}
			}
		} catch ( ClaimLost $e ) {
			// As the import: a run that no longer holds the job stops as such (LockLost), not as one that is retried.
			$context->confirm_lease();
			throw $e;
		} finally {
			if ( $db instanceof ImportSession ) {
				$db->close();
			}
		}
		$this->summarise( $context );
		return StepResult::done( __( 'The rows named after the table prefix are renamed', 'wp-checkpoint' ) );
	}

	/**
	 * The plan unit: the report file, the highest usermeta key; the cursor of the first phase.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $run     This run.
	 * @return array<string, mixed>
	 */
	private function plan( JobContext $context, array $run ): array {
		$from = $run['plan']->backup_prefix();
		$to   = $run['plan']->site_prefix();
		$max  = isset( $run['tables']['usermeta'] ) ? self::top( $run['db'], $run['tables']['usermeta'] ) : 0;
		// When this site's prefix starts with the backup's, a copy's name is a name of the backup's own site too.
		$copy = '' !== $from && 0 !== strncmp( $to, $from, strlen( $from ) );
		$this->write_report(
			$context,
			array(
				'identified' => '' !== $from,
				'copies'     => $copy,
				'options'    => array(
					'mark'   => 0,
					'tables' => 0,
					'names'  => 0,
					'first'  => array(),
				),
				'missing'    => array(
					'count' => 0,
					'first' => array(),
				),
				'copy'       => array(
					'upto' => -1,
					'keys' => array(),
					'more' => 0,
				),
				'cleared'    => array(
					'mark' => array( -1, -1, -1 ),
					'keys' => array(),
				),
			),
			$run
		);
		return array(
			'phase' => '' === $from ? 'a' : 'options_report',
			'after' => 0,
			'from'  => -1,
			'top'   => null,
			'max'   => $max,
			'copy'  => $copy,
		);
	}

	/**
	 * One unit of the current phase: the next cursor.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor.
	 * @param array<string, mixed> $run     This run: db, ledger, token, keys, plan, tables.
	 * @return array<string, mixed>
	 */
	private function unit( JobContext $context, array $cursor, array $run ): array {
		$phase = (string) $cursor['phase'];
		if ( 'copy' === $phase ) {
			return $this->copy( $context, $cursor, $run );
		}
		$db     = $run['db'];
		$tables = $run['tables'];
		$sites  = $this->group( $db, $tables, (int) $cursor['after'] );
		if ( array() === $sites ) {
			return $this->next_phase( $cursor );
		}
		$last = (int) max( $sites );
		if ( 'options_report' === $phase ) {
			$this->report_options( $context, $sites, $last, $run );
			$cursor['after'] = $last;
			return $cursor;
		}
		if ( null === $cursor['top'] ) {
			$cursor['top'] = isset( $tables['usermeta'] ) ? self::top( $db, $tables['usermeta'] ) : 0;
		}
		if ( (int) $cursor['from'] < (int) $cursor['top'] ) {
			$to = (int) min( (int) $cursor['top'], (int) $cursor['from'] + $this->sizes['range'] );
			$this->users( $context, $phase, $sites, (int) $cursor['after'], (int) $cursor['from'], $to, $run );
			$this->at( $phase );
			$cursor['from'] = $to;
			return $cursor;
		}
		$this->options( $context, $phase, $sites, (int) $cursor['after'], $run );
		// Before the group counts as done: no statement of it was refused for another run's claim.
		$this->confirm_holder( $context, $run );
		$this->at( $phase . '_options' );
		$cursor['after'] = $last;
		$cursor['from']  = -1;
		return $cursor;
	}

	/**
	 * One usermeta statement of phase a, clear or b for a group of sites, over a primary key range.
	 *
	 * @param JobContext           $context Context.
	 * @param string               $phase   a, clear or b.
	 * @param int[]                $sites   Site ids.
	 * @param int                  $after   The group's mark (the previous group's last id).
	 * @param int                  $from    Range start (excluded).
	 * @param int                  $to      Range end (included).
	 * @param array<string, mixed> $run     This run.
	 * @return void
	 */
	private function users( JobContext $context, string $phase, array $sites, int $after, int $from, int $to, array $run ): void {
		$db      = $run['db'];
		$table   = $run['tables']['usermeta'];
		$renames = $run['keys']->user_keys( $sites );
		$range   = array( (string) $from, (string) $to );
		$claim   = array( (string) self::CLAIM, $run['token'] );
		if ( 'clear' === $phase ) {
			$targets = array_values( array_column( $renames, 1 ) );
			$where   = ' WHERE umeta_id > ? AND umeta_id <= ? AND meta_key IN (' . self::marks( $targets ) . ') AND BINARY meta_key IN (' . self::marks( $targets ) . ')';
			$counts  = array();
			foreach ( $db->rows( 'SELECT meta_key, COUNT(*) FROM `' . $table . '`' . $where . ' GROUP BY BINARY meta_key', array_merge( $range, $targets, $targets ) ) as $row ) {
				$counts[ (string) $row[0] ] = (int) $row[1];
			}
			$this->record_cleared( $context, $counts, array( $after, 0, $to ), $run );
			$this->at( 'cleared' );
			$db->write( 'DELETE FROM `' . $table . '`' . $where . $this->holding(), array_merge( $range, $targets, $targets, $claim ) );
			return;
		}
		$map = array();
		foreach ( $renames as $old => $pair ) {
			if ( 'a' === $phase ) {
				$map[ (string) $old ] = $pair[0];
			} else {
				$map[ $pair[0] ] = $pair[1];
			}
		}
		$case   = '';
		$params = array();
		foreach ( $map as $match => $name ) {
			$case    .= ' WHEN ? THEN ?';
			$params[] = (string) $match;
			$params[] = $name;
		}
		$matches = array_map( 'strval', array_keys( $map ) );
		$db->write(
			'UPDATE `' . $table . '` SET meta_key = CASE BINARY meta_key' . $case . ' END WHERE umeta_id > ? AND umeta_id <= ? AND meta_key IN (' . self::marks( $matches ) . ') AND BINARY meta_key IN (' . self::marks( $matches ) . ')' . $this->holding(),
			array_merge( $params, $range, $matches, $matches, $claim )
		);
	}

	/**
	 * The options statements of phase a, clear or b for a group of sites (a row each, by exact name).
	 *
	 * @param JobContext           $context Context.
	 * @param string               $phase   a, clear or b.
	 * @param int[]                $sites   Site ids.
	 * @param int                  $after   The group's mark.
	 * @param array<string, mixed> $run     This run.
	 * @return void
	 */
	private function options( JobContext $context, string $phase, array $sites, int $after, array $run ): void {
		$db     = $run['db'];
		$claim  = array( (string) self::CLAIM, $run['token'] );
		$counts = array();
		foreach ( $sites as $site ) {
			$table = self::options_table( $run['plan'], $site );
			if ( null === $table ) {
				continue;
			}
			list( $old, $middle, $new ) = $run['keys']->roles( $site );
			if ( 'a' === $phase ) {
				$db->write( 'UPDATE `' . $table . '` SET option_name = ? WHERE option_name = ?' . $this->holding(), array_merge( array( $middle, $old ), $claim ) );
			} elseif ( 'b' === $phase ) {
				$db->write( 'UPDATE `' . $table . '` SET option_name = ? WHERE option_name = ?' . $this->holding(), array_merge( array( $new, $middle ), $claim ) );
			} else {
				$has = $db->rows( 'SELECT COUNT(*) FROM `' . $table . '` WHERE option_name = ?', array( $new ) );
				if ( (int) ( $has[0][0] ?? 0 ) > 0 ) {
					$counts[ $new ] = (int) $has[0][0];
				}
			}
		}
		if ( 'clear' !== $phase ) {
			return;
		}
		$this->record_cleared( $context, $counts, array( $after, 1, 0 ), $run );
		foreach ( $sites as $site ) {
			$table = self::options_table( $run['plan'], $site );
			if ( null !== $table ) {
				$new = $run['keys']->roles( $site )[2];
				$db->write( 'DELETE FROM `' . $table . '` WHERE option_name = ?' . $this->holding(), array_merge( array( $new ), $claim ) );
			}
		}
	}

	/**
	 * One unit of the copy phase: up to "copy_rows" rows of a primary key range of usermeta; the next cursor.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor.
	 * @param array<string, mixed> $run     This run.
	 * @return array<string, mixed>
	 */
	private function copy( JobContext $context, array $cursor, array $run ): array {
		$after  = (int) $cursor['after'];
		$max    = (int) $cursor['max'];
		$tables = $run['tables'];
		if ( ! isset( $tables['usermeta'] ) || $after >= $max ) {
			return $this->next_phase( $cursor );
		}
		$db    = $run['db'];
		$plan  = $run['plan'];
		$keys  = $run['keys'];
		$from  = $plan->backup_prefix();
		$upto  = (int) min( $max, $after + $this->sizes['copy_range'] );
		$table = $tables['usermeta'];
		$rows  = $db->rows( 'SELECT umeta_id, user_id, meta_key FROM `' . $table . '` WHERE umeta_id > ? AND umeta_id <= ? AND meta_key LIKE ? ESCAPE \'!\' ORDER BY umeta_id LIMIT ' . $this->sizes['copy_rows'], array( (string) $after, (string) $upto, self::like( $from ) ) );
		if ( count( $rows ) >= $this->sizes['copy_rows'] ) {
			$upto = (int) $rows[ count( $rows ) - 1 ][0]; // As many rows as a unit takes: the next unit goes on after the last.
		}
		$sites = $this->sites_of( $db, $tables, $rows, $from );
		$seen  = array();
		foreach ( $rows as $row ) {
			$key = (string) $row[2];
			// The collation matched it; WordPress would not ("WPX_…" is not "wpx_…"): not a row named after the prefix.
			if ( 0 !== strncmp( $key, $from, strlen( $from ) ) || $keys->known( $key, $sites ) ) {
				continue;
			}
			$copy = $keys->copy_of( $key );
			if ( empty( $cursor['copy'] ) || 0 === strncmp( $copy, $from, strlen( $from ) ) ) {
				// When P starts with Q, a copy's name may be a name of the backup's own site ("wp_aa2_x" → "wp_a2_x").
				$outcome = 'reported';
			} elseif ( strlen( $copy ) > self::MAX_KEY_BYTES ) {
				$outcome = 'too_long';
			} else {
				$existing = null;
				// Compared here, not by BINARY: the column's bytes are in its own charset, the name in the connection's.
				foreach ( $db->rows( 'SELECT umeta_id, meta_key FROM `' . $table . '` WHERE user_id = ? AND meta_key = ? ORDER BY umeta_id LIMIT ' . self::VARIANTS, array( (string) $row[1], $copy ) ) as $found ) {
					if ( (string) $found[1] === $copy ) {
						$existing = (int) $found[0];
						break;
					}
				}
				if ( null !== $existing ) {
					// A row under that name from the backup is kept, and reported; one above the plan's highest key is this copy, from before a replay.
					$outcome = $existing <= $max ? 'existing' : 'copied';
				} else {
					$db->write( 'INSERT INTO `' . $table . '` (user_id, meta_key, meta_value) SELECT s.user_id, ?, s.meta_value FROM `' . $table . '` s WHERE s.umeta_id = ?' . $this->holding(), array( $copy, (string) $row[0], (string) self::CLAIM, $run['token'] ) );
					$outcome = 'copied';
				}
			}
			$seen[ $key ][ $outcome ] = ( $seen[ $key ][ $outcome ] ?? 0 ) + 1;
		}
		$this->at( 'copied' );
		$this->record_copies( $context, $seen, $upto, $run );
		$this->at( 'copy' );
		$cursor['after'] = $upto;
		return $cursor;
	}

	/**
	 * Add a copy unit's counts to the report, once: the report says up to which key it counted.
	 *
	 * @param JobContext                        $context Context.
	 * @param array<string, array<string, int>> $seen    Key => outcome => rows.
	 * @param int                               $upto    The unit's last key.
	 * @param array<string, mixed>              $run     This run.
	 * @return void
	 */
	private function record_copies( JobContext $context, array $seen, int $upto, array $run ): void {
		$report = ExportPlan::read( $context->work_path(), RestoreFiles::PREFIX_REPORT );
		if ( (int) $report['copy']['upto'] >= $upto ) {
			return; // A replayed unit: counted already.
		}
		foreach ( $seen as $key => $outcomes ) {
			$rows = array_sum( $outcomes );
			if ( ! isset( $report['copy']['keys'][ $key ] ) && count( $report['copy']['keys'] ) >= $this->sizes['keys'] ) {
				$report['copy']['more'] += $rows; // Rows of keys not listed.
				continue;
			}
			$entry          = $report['copy']['keys'][ $key ] ?? array( 'rows' => 0 );
			$entry['rows'] += $rows;
			foreach ( $outcomes as $outcome => $count ) {
				$entry[ $outcome ] = ( $entry[ $outcome ] ?? 0 ) + $count;
			}
			$report['copy']['keys'][ $key ] = $entry;
		}
		$report['copy']['upto'] = $upto;
		$this->write_report( $context, $report, $run );
	}

	/**
	 * Add a clearing unit's counts to the report, once (under the unit's mark), by key without the site.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, int>   $counts  New name => rows about to be removed.
	 * @param int[]                $mark    The unit: [group mark, part (0 users, 1 options), range end].
	 * @param array<string, mixed> $run     This run.
	 * @return void
	 */
	private function record_cleared( JobContext $context, array $counts, array $mark, array $run ): void {
		$report = ExportPlan::read( $context->work_path(), RestoreFiles::PREFIX_REPORT );
		if ( array_map( 'intval', (array) $report['cleared']['mark'] ) >= $mark ) {
			return; // A replayed unit: counted already.
		}
		$to = $run['plan']->site_prefix();
		foreach ( $counts as $name => $rows ) {
			// "{Q}{id}_capabilities" and "{Q}capabilities" are one entry: the report stays the size of the key list.
			$key                               = preg_replace( '/\A' . preg_quote( $to, '/' ) . '(?:[1-9][0-9]*_)?/', '', $name );
			$key                               = null === $key ? $name : $key;
			$report['cleared']['keys'][ $key ] = ( $report['cleared']['keys'][ $key ] ?? 0 ) + $rows;
		}
		$report['cleared']['mark'] = $mark;
		$this->write_report( $context, $report, $run );
	}

	/**
	 * Report a group's options tables: how many names start with the backup's prefix (the roles aside), the
	 * first of the first tables; and the sites without an options table. Once per group (the report's mark).
	 *
	 * @param JobContext           $context Context.
	 * @param int[]                $sites   Site ids.
	 * @param int                  $last    The group's last id.
	 * @param array<string, mixed> $run     This run.
	 * @return void
	 */
	private function report_options( JobContext $context, array $sites, int $last, array $run ): void {
		$report = ExportPlan::read( $context->work_path(), RestoreFiles::PREFIX_REPORT );
		if ( (int) $report['options']['mark'] >= $last ) {
			return;
		}
		$db   = $run['db'];
		$plan = $run['plan'];
		$like = self::like( $plan->backup_prefix() );
		foreach ( $sites as $site ) {
			$table = self::options_table( $plan, $site );
			$name  = PrefixKeys::site( $plan->backup_prefix(), $site ) . 'options';
			if ( null === $table ) {
				++$report['missing']['count'];
				if ( count( $report['missing']['first'] ) < $this->sizes['listed'] ) {
					$report['missing']['first'][] = $name;
				}
				continue;
			}
			$roles = $run['keys']->roles( $site )[0];
			$where = ' WHERE option_name LIKE ? ESCAPE \'!\' AND option_name <> ?';
			$count = (int) ( $db->rows( 'SELECT COUNT(*) FROM `' . $table . '`' . $where, array( $like, $roles ) )[0][0] ?? 0 );
			if ( 0 === $count ) {
				continue;
			}
			++$report['options']['tables'];
			$report['options']['names'] += $count;
			if ( count( $report['options']['first'] ) < $this->sizes['listed'] ) {
				$first = $db->rows( 'SELECT option_name FROM `' . $table . '`' . $where . ' ORDER BY option_name LIMIT ' . $this->sizes['listed'], array( $like, $roles ) );
				$report['options']['first'][ $plan->final_name( $name ) ] = array_map( 'strval', array_column( $first, 0 ) );
			}
		}
		$report['options']['mark'] = $last;
		$this->write_report( $context, $report, $run );
		$this->at( 'options_report' );
	}

	/**
	 * Write the report while this run still holds the rewrite (a run that lost it writes nothing).
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $report  Report.
	 * @param array<string, mixed> $run     This run.
	 * @return void
	 */
	private function write_report( JobContext $context, array $report, array $run ): void {
		$this->confirm_holder( $context, $run );
		ExportPlan::write( $context->work_path(), RestoreFiles::PREFIX_REPORT, $report );
	}

	/**
	 * This run still holds the job and the rewrite's claim, or it stops.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $run     This run.
	 * @return void
	 * @throws ClaimLost When another run claimed the rewrite.
	 */
	private function confirm_holder( JobContext $context, array $run ): void {
		$context->confirm_lease();
		$claim = $run['ledger']->get( self::CLAIM );
		if ( null === $claim || $claim['holder'] !== $run['token'] ) {
			throw new ClaimLost( 'Another run of this restore took over the table prefix rewrite.' );
		}
	}

	/**
	 * The condition every statement carries: this run's claim in the ledger.
	 *
	 * @return string
	 */
	private function holding(): string {
		return ' AND EXISTS (SELECT 1 FROM `' . $this->ledger_table . '` WHERE n = ? AND holder = ?)';
	}

	/**
	 * The phase after the cursor's, from its start.
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @return array<string, mixed>
	 */
	private function next_phase( array $cursor ): array {
		$order = array( 'options_report', 'copy', 'a', 'clear', 'b', 'done' );
		$at    = array_search( $cursor['phase'], $order, true );
		$next  = $order[ false === $at ? count( $order ) - 1 : $at + 1 ];
		return array(
			'phase' => $next,
			'after' => 'copy' === $next ? -1 : 0, // The copy phase's position is a usermeta key, from before 0; the others' a site id.
			'from'  => -1,
			'top'   => null,
			'max'   => $cursor['max'],
			'copy'  => $cursor['copy'],
		);
	}

	/**
	 * The highest primary key of usermeta.
	 *
	 * @param Queries $db    Connection.
	 * @param string  $table Table.
	 * @return int
	 */
	private static function top( Queries $db, string $table ): int {
		return (int) ( $db->rows( 'SELECT COALESCE(MAX(umeta_id), 0) FROM `' . $table . '`' )[0][0] ?? 0 );
	}

	/**
	 * The next group of sites after an id: the main site (1) first, then the network's other sites from the
	 * backup's blogs table, "group" at a time, in id order; empty when none are left.
	 *
	 * @param Queries               $db     Connection.
	 * @param array<string, string> $tables tables().
	 * @param int                   $after  Last id of the previous group (0 before the first).
	 * @return int[]
	 */
	private function group( Queries $db, array $tables, int $after ): array {
		$ids = 0 === $after ? array( 1 ) : array();
		if ( isset( $tables['blogs'] ) ) {
			foreach ( $db->rows( 'SELECT blog_id FROM `' . $tables['blogs'] . '` WHERE blog_id > ? ORDER BY blog_id LIMIT ' . $this->sizes['group'], array( (string) max( 1, $after ) ) ) as $row ) {
				$id = (int) $row[0];
				if ( $id > 1 && (string) $id === (string) $row[0] ) {
					$ids[] = $id;
				}
			}
		}
		return $ids;
	}

	/**
	 * Which site ids the rows' keys name exist in the backup's blogs table: function( int $id ): bool.
	 *
	 * @param Queries                       $db     Connection.
	 * @param array<string, string>         $tables tables().
	 * @param array<int, array<int, mixed>> $rows   Rows (the key third).
	 * @param string                        $from   The backup's prefix.
	 * @return callable
	 */
	private function sites_of( Queries $db, array $tables, array $rows, string $from ): callable {
		$ids = array();
		foreach ( $rows as $row ) {
			if ( 1 === preg_match( '/\A([1-9][0-9]{0,18})_/', (string) substr( (string) $row[2], strlen( $from ) ), $m ) ) {
				$ids[ $m[1] ] = true;
			}
		}
		$found = array();
		if ( isset( $tables['blogs'] ) && array() !== $ids ) {
			$list = array_map( 'strval', array_keys( $ids ) );
			foreach ( $db->rows( 'SELECT blog_id FROM `' . $tables['blogs'] . '` WHERE blog_id IN (' . self::marks( $list ) . ')', $list ) as $row ) {
				$found[ (int) $row[0] ] = true;
			}
		}
		return static function ( int $id ) use ( $found ): bool {
			return isset( $found[ $id ] );
		};
	}

	/**
	 * The temporary tables the rewrite works on: usermeta and blogs (on a network), by role.
	 *
	 * @param TablePlan $plan Plan.
	 * @return array<string, string>
	 */
	private static function tables( TablePlan $plan ): array {
		$out = array();
		foreach ( array( 'usermeta', 'blogs' ) as $name ) {
			$table = $plan->find( $plan->backup_prefix() . $name );
			if ( null !== $table ) {
				$out[ $name ] = $table['temporary'];
			}
		}
		return $out;
	}

	/**
	 * A site's temporary options table, or null when the backup has none that is restored.
	 *
	 * @param TablePlan $plan Plan.
	 * @param int       $site Site id.
	 * @return string|null
	 */
	private static function options_table( TablePlan $plan, int $site ) {
		$table = $plan->find( PrefixKeys::site( $plan->backup_prefix(), $site ) . 'options' );
		return null === $table ? null : $table['temporary'];
	}

	/**
	 * A LIKE pattern for names starting with a prefix ("!" escapes).
	 *
	 * @param string $prefix Prefix.
	 * @return string
	 */
	private static function like( string $prefix ): string {
		return strtr(
			$prefix,
			array(
				'!' => '!!',
				'%' => '!%',
				'_' => '!_',
			)
		) . '%';
	}

	/**
	 * "?, ?, …" for a list.
	 *
	 * @param array<int|string, mixed> $values Values.
	 * @return string
	 */
	private static function marks( array $values ): string {
		return implode( ', ', array_fill( 0, count( $values ), '?' ) );
	}

	/**
	 * Log the report: options reported, copies, rows cleared, sites without an options table.
	 *
	 * @param JobContext $context Context.
	 * @return void
	 */
	private function summarise( JobContext $context ): void {
		$report = ExportPlan::read( $context->work_path(), RestoreFiles::PREFIX_REPORT );
		if ( empty( $report['identified'] ) ) {
			$context->logger()->warning( 'The backup has no table prefix: only the rows WordPress names after the prefix by exact name (roles, capabilities, user settings) were renamed; other rows named after a prefix cannot be told from the rest and were left as they are' );
		}
		if ( $report['options']['tables'] > 0 ) {
			$context->logger()->warning( 'Options whose names start with the backup\'s table prefix were left as they are (reported, not renamed)', $report['options'] );
		}
		if ( array() !== $report['copy']['keys'] ) {
			$context->logger()->info(
				empty( $report['copies'] ) ? 'User settings named after the backup\'s table prefix were left as they are: this site\'s prefix starts with the backup\'s, so a copy under it would be a name of the backed-up site too' : 'User settings named after the backup\'s table prefix were copied under this site\'s (kept under the old name too; a name a user already had, too long, or that would be a name of the backed-up site was left alone)',
				array(
					'keys'      => array_slice( $report['copy']['keys'], 0, $this->sizes['listed'], true ),
					'more_rows' => $report['copy']['more'],
				)
			);
		}
		if ( array() !== $report['cleared']['keys'] ) {
			$context->logger()->warning( 'Rows of the backup already named after this site\'s table prefix (never read on the backed-up site) were removed from the restored tables', array( 'rows' => $report['cleared']['keys'] ) );
		}
		if ( $report['missing']['count'] > 0 ) {
			$context->logger()->warning( 'Sites of the network whose options table is not restored: their roles were not renamed', $report['missing'] );
		}
	}

	/**
	 * A crash seam (tests).
	 *
	 * @param string $point Point.
	 * @return void
	 */
	private function at( string $point ): void {
		if ( null !== $this->at ) {
			call_user_func( $this->at, $point );
		}
	}

	/**
	 * Nothing to do: only temporary tables were changed, which the engine drops with the job's work.
	 *
	 * @param JobContext $context Context.
	 * @return void
	 */
	public function cleanup( JobContext $context ): void {
		unset( $context );
	}
}
