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
 * the backup's blogs table (the main site, blog 1, without an id). Phases,
 * a site group (the main site and up to GROUP site ids) at a time where
 * sites matter:
 *
 * 1. options_report (P not empty): per site's options table, the options
 *    whose names start with P, other than the roles, are reported (how
 *    many, the first names): only reported, never renamed.
 * 2. copy (P not empty): usermeta keys that start with P and are not known
 *    ones (PrefixKeys::known()) are copied under Q (PrefixKeys::copy_of()),
 *    the P row kept: a plugin's per-user option (update_user_option())
 *    then keeps working. A copy whose name a row of that user already has
 *    is skipped and reported, not overwritten (that row may be a plugin's
 *    own, by its literal name). Rows are taken by primary key range, up to
 *    the highest key at the start (copies get higher keys and are never
 *    taken again); a row's existing copy is how a replay knows it is done.
 * 3. a: every old name becomes its intermediate name (PrefixKeys).
 * 4. count, clear: rows already under a new name (in the backup, never read
 *    on its site, whose prefix was P) are counted per name, then removed:
 *    WordPress here would read them (a leftover "{Q}capabilities" would
 *    give a user a role they did not have). Only the temporary tables are
 *    changed; the backup keeps them.
 * 5. b: every intermediate name becomes its new name.
 *
 * With P empty, only the exact names are renamed, and the report says that
 * other rows named after the prefix cannot be told from the rest.
 *
 * Each statement changes at most LIMIT rows, in primary key order, and
 * carries its run's claim in itself, as the import's statements do: each
 * tick claims the rewrite's row of the restore's ledger (Ledger, row CLAIM,
 * the job's lease confirmed before and after), and every statement requires
 * that row to still name this run, so a run that lost the job changes
 * nothing once a newer run has claimed it.
 * Each moves rows out of what it matches, so running it again changes
 * nothing; a statement cut short on a table without transactions goes on
 * at the next run. Each phase is only ever replayed on its own: the move to
 * the next phase is checkpointed before that phase's first statement (the
 * clearing replayed after the last phase began would remove renamed rows).
 * A group counts as done only after the lease and the claim are confirmed
 * (its statements changed fewer rows because none are left, not because
 * another run holds the rewrite). The first statement of a tick always
 * runs; the tick ends when the time left is under 1.5 times the slowest so
 * far.
 *
 * None of these names is one StateCarry writes before the swap (its names
 * start with "wpcheckpoint_" and its transients').
 */
final class PrefixRewriteStep implements Step {

	const ID     = 'restore_prefix';
	const MARGIN = 1.5;

	/**
	 * The rewrite's row in the restore's ledger (the imported tables are numbered from 0).
	 */
	const CLAIM = 4294967295;

	/**
	 * Rows one statement changes at most.
	 */
	const LIMIT = 1000;

	/**
	 * Primary key range the copy phase reads per unit.
	 */
	const RANGE = 10000;

	/**
	 * Site ids per group (and so per statement's list of names: GROUP x the keys).
	 */
	const GROUP = 100;

	/**
	 * Names listed per table or per report.
	 */
	const MAX_LISTED = 20;

	/**
	 * Distinct unknown usermeta keys counted one by one; more are summed.
	 */
	const MAX_KEYS = 1000;

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
	 * The restore's ledger table (this tick).
	 *
	 * @var string
	 */
	private $ledger_table = '';

	/**
	 * Constructor.
	 *
	 * @param callable|null $connect function(): Queries (tests); the site's own settings by default.
	 * @param callable|null $at      Crash seam (tests).
	 */
	public function __construct( $connect = null, $at = null ) {
		$this->at      = is_callable( $at ) ? $at : null;
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
			$holder = array( (string) self::CLAIM, $token );
			if ( 'plan' === $cursor['phase'] ) {
				$cursor = $this->plan( $context, $db, $tables, $from );
				$context->checkpoint( $cursor, 68, __( 'Renaming for this site\'s table prefix', 'wp-checkpoint' ) );
				$first = false;
			}
			$slowest = 0.0;
			while ( 'done' !== $cursor['phase'] ) {
				if ( ! $first && ( $context->should_stop() || $context->remaining_seconds() < $slowest * self::MARGIN ) ) {
					return StepResult::progress( $cursor, 69, __( 'Renaming for this site\'s table prefix', 'wp-checkpoint' ) );
				}
				$first   = false;
				$started = $context->elapsed();
				$was     = $cursor['phase'];
				$cursor  = $this->unit( $context, $db, $cursor, $tables, $keys, $plan, $holder, $ledger );
				$slowest = max( $slowest, $context->elapsed() - $started );
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
	 * @param JobContext            $context Context.
	 * @param Queries               $db      Connection.
	 * @param array<string, string> $tables  tables().
	 * @param string                $from    The backup's prefix.
	 * @return array<string, mixed>
	 */
	private function plan( JobContext $context, Queries $db, array $tables, string $from ): array {
		$max = 0;
		if ( isset( $tables['usermeta'] ) ) {
			$rows = $db->rows( 'SELECT COALESCE(MAX(umeta_id), 0) FROM `' . $tables['usermeta'] . '`' );
			$max  = (int) ( $rows[0][0] ?? 0 );
		}
		ExportPlan::write(
			$context->work_path(),
			RestoreFiles::PREFIX_REPORT,
			array(
				'identified' => '' !== $from,
				'options'    => array(),
				'copy'       => array(
					'upto' => 0,
					'keys' => array(),
					'more' => array(
						'keys' => 0,
						'rows' => 0,
					),
				),
				'cleared'    => array(),
				'missing'    => array(),
			)
		);
		return array(
			'phase' => '' === $from ? 'a' : 'options_report',
			'after' => 0,
			'max'   => $max,
			'part'  => 'users',
		);
	}

	/**
	 * One unit of the current phase: the next cursor.
	 *
	 * @param JobContext            $context Context.
	 * @param Queries               $db      Connection.
	 * @param array<string, mixed>  $cursor  Cursor.
	 * @param array<string, string> $tables  tables().
	 * @param PrefixKeys            $keys    Names.
	 * @param TablePlan             $plan    Plan.
	 * @param string[]              $holder  The claim's row and this run's token.
	 * @param Ledger                $ledger  The restore's ledger.
	 * @return array<string, mixed>
	 */
	private function unit( JobContext $context, Queries $db, array $cursor, array $tables, PrefixKeys $keys, TablePlan $plan, array $holder, Ledger $ledger ): array {
		$phase = (string) $cursor['phase'];
		if ( 'copy' === $phase ) {
			return $this->copy( $context, $db, $cursor, $tables, $keys, $plan, $holder );
		}
		$sites = $this->group( $db, $tables, (int) $cursor['after'] );
		if ( array() === $sites ) {
			return $this->next_phase( $cursor );
		}
		$last = (int) max( $sites );
		switch ( $phase ) {
			case 'options_report':
				$this->report_options( $context, $db, $sites, $keys, $plan );
				$cursor['after'] = $last;
				return $cursor;
			case 'count':
				$this->count( $context, $db, $sites, $tables, $keys, $plan );
				$cursor['after'] = $last;
				return $cursor;
		}
		// a, clear, b: the users' rows of the group (a statement at a time), then its options tables.
		if ( 'users' === $cursor['part'] && isset( $tables['usermeta'] ) ) {
			$changed = $this->users( $db, $phase, $sites, $tables['usermeta'], $keys, $holder );
			$this->at( $phase );
			if ( self::LIMIT === $changed ) {
				return $cursor; // Perhaps more: the same group again.
			}
		}
		foreach ( $sites as $site ) {
			$table = self::options_table( $plan, $site );
			if ( null === $table ) {
				continue;
			}
			list( $old, $middle, $new ) = $keys->roles( $site );
			$holding                    = ' AND EXISTS (SELECT 1 FROM `' . $this->ledger_table . '` WHERE n = ? AND holder = ?)';
			if ( 'a' === $phase ) {
				$db->write( 'UPDATE `' . $table . '` SET option_name = ? WHERE option_name = ?' . $holding, array( $middle, $old, $holder[0], $holder[1] ) );
			} elseif ( 'clear' === $phase ) {
				$db->write( 'DELETE FROM `' . $table . '` WHERE option_name = ?' . $holding, array( $new, $holder[0], $holder[1] ) );
			} else {
				$db->write( 'UPDATE `' . $table . '` SET option_name = ? WHERE option_name = ?' . $holding, array( $new, $middle, $holder[0], $holder[1] ) );
			}
		}
		// Before the group counts as done: fewer rows changed because none are left, not because another run holds the rewrite.
		$this->confirm_holder( $context, $ledger, $holder[1] );
		$this->at( $phase . '_options' );
		$cursor['after'] = $last;
		$cursor['part']  = 'users';
		return $cursor;
	}

	/**
	 * One statement on usermeta for a group of sites: rows changed.
	 *
	 * @param Queries    $db       Connection.
	 * @param string     $phase    a, clear or b.
	 * @param int[]      $sites    Site ids.
	 * @param string     $usermeta Temporary usermeta table.
	 * @param PrefixKeys $keys     Names.
	 * @param string[]   $holder   The claim's row and this run's token.
	 * @return int
	 */
	private function users( Queries $db, string $phase, array $sites, string $usermeta, PrefixKeys $keys, array $holder ): int {
		$renames = $keys->user_keys( $sites );
		$holding = ' AND EXISTS (SELECT 1 FROM `' . $this->ledger_table . '` WHERE n = ? AND holder = ?) ORDER BY umeta_id LIMIT ' . self::LIMIT;
		if ( 'clear' === $phase ) {
			$targets = array_column( $renames, 1 );
			return $db->write( 'DELETE FROM `' . $usermeta . '` WHERE meta_key IN (' . self::marks( $targets ) . ')' . $holding, array_merge( $targets, $holder ) );
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
		$params = array_merge( $params, array_map( 'strval', array_keys( $map ) ), $holder );
		return $db->write( 'UPDATE `' . $usermeta . '` SET meta_key = CASE meta_key' . $case . ' END WHERE meta_key IN (' . self::marks( array_keys( $map ) ) . ')' . $holding, $params );
	}

	/**
	 * One unit of the copy phase: a primary key range of usermeta; the next cursor.
	 *
	 * @param JobContext            $context Context.
	 * @param Queries               $db      Connection.
	 * @param array<string, mixed>  $cursor  Cursor.
	 * @param array<string, string> $tables  tables().
	 * @param PrefixKeys            $keys    Names.
	 * @param TablePlan             $plan    Plan.
	 * @param string[]              $holder  The claim's row and this run's token.
	 * @return array<string, mixed>
	 */
	private function copy( JobContext $context, Queries $db, array $cursor, array $tables, PrefixKeys $keys, TablePlan $plan, array $holder ): array {
		$after = (int) $cursor['after'];
		$max   = (int) $cursor['max'];
		if ( ! isset( $tables['usermeta'] ) || $after >= $max ) {
			return $this->next_phase( $cursor );
		}
		$upto  = (int) min( $max, $after + self::RANGE );
		$table = $tables['usermeta'];
		$rows  = $db->rows( 'SELECT umeta_id, user_id, meta_key FROM `' . $table . '` WHERE umeta_id > ? AND umeta_id <= ? AND meta_key LIKE ? ESCAPE \'!\' ORDER BY umeta_id', array( (string) $after, (string) $upto, self::like( $plan->backup_prefix() ) ) );
		$sites = $this->sites_of( $db, $tables, $rows, $plan->backup_prefix() );
		$seen  = array();
		foreach ( $rows as $row ) {
			$key = (string) $row[2];
			if ( $keys->known( $key, $sites ) ) {
				continue;
			}
			$copy     = $keys->copy_of( $key );
			$existing = $db->rows( 'SELECT umeta_id FROM `' . $table . '` WHERE user_id = ? AND meta_key = ? ORDER BY umeta_id LIMIT 1', array( (string) $row[1], $copy ) );
			$outcome  = 'copied';
			if ( array() !== $existing ) {
				// A row under that name from the backup is kept, and reported; one above the start's highest key is this copy, from before a replay.
				$outcome = (int) $existing[0][0] <= $max ? 'existing' : 'copied';
			} else {
				$db->write( 'INSERT INTO `' . $table . '` (user_id, meta_key, meta_value) SELECT s.user_id, ?, s.meta_value FROM `' . $table . '` s WHERE s.umeta_id = ? AND EXISTS (SELECT 1 FROM `' . $this->ledger_table . '` WHERE n = ? AND holder = ?)', array( $copy, (string) $row[0], $holder[0], $holder[1] ) );
			}
			$seen[ $key ][ $outcome ] = ( $seen[ $key ][ $outcome ] ?? 0 ) + 1;
		}
		$this->at( 'copied' );
		$this->record_copies( $context, $seen, $upto );
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
	 * @return void
	 */
	private function record_copies( JobContext $context, array $seen, int $upto ): void {
		$report = ExportPlan::read( $context->work_path(), RestoreFiles::PREFIX_REPORT );
		if ( (int) $report['copy']['upto'] >= $upto ) {
			return; // A replayed unit: counted already.
		}
		foreach ( $seen as $key => $outcomes ) {
			$rows = array_sum( $outcomes );
			if ( ! isset( $report['copy']['keys'][ $key ] ) && count( $report['copy']['keys'] ) >= self::MAX_KEYS ) {
				++$report['copy']['more']['keys'];
				$report['copy']['more']['rows'] += $rows;
				continue;
			}
			$entry                          = $report['copy']['keys'][ $key ] ?? array(
				'rows'     => 0,
				'copied'   => 0,
				'existing' => 0,
			);
			$entry['rows']                 += $rows;
			$entry['copied']               += $outcomes['copied'] ?? 0;
			$entry['existing']             += $outcomes['existing'] ?? 0;
			$report['copy']['keys'][ $key ] = $entry;
		}
		$report['copy']['upto'] = $upto;
		ExportPlan::write( $context->work_path(), RestoreFiles::PREFIX_REPORT, $report );
	}

	/**
	 * Report a group's options tables: how many names start with the backup's prefix (the roles aside), the first.
	 *
	 * @param JobContext $context Context.
	 * @param Queries    $db      Connection.
	 * @param int[]      $sites   Site ids.
	 * @param PrefixKeys $keys    Names.
	 * @param TablePlan  $plan    Plan.
	 * @return void
	 */
	private function report_options( JobContext $context, Queries $db, array $sites, PrefixKeys $keys, TablePlan $plan ): void {
		$report = ExportPlan::read( $context->work_path(), RestoreFiles::PREFIX_REPORT );
		foreach ( $sites as $site ) {
			$table = self::options_table( $plan, $site );
			$name  = PrefixKeys::site( $plan->backup_prefix(), $site ) . 'options';
			if ( null === $table ) {
				$report['missing'][ (string) $site ] = $name;
				continue;
			}
			$like  = self::like( $plan->backup_prefix() );
			$roles = $keys->roles( $site )[0];
			$count = $db->rows( 'SELECT COUNT(*) FROM `' . $table . '` WHERE option_name LIKE ? ESCAPE \'!\' AND option_name <> ?', array( $like, $roles ) );
			$first = $db->rows( 'SELECT option_name FROM `' . $table . '` WHERE option_name LIKE ? ESCAPE \'!\' AND option_name <> ? ORDER BY option_name LIMIT ' . self::MAX_LISTED, array( $like, $roles ) );
			if ( (int) ( $count[0][0] ?? 0 ) > 0 ) {
				$report['options'][ $plan->final_name( $name ) ] = array(
					'count' => (int) $count[0][0],
					'first' => array_map( 'strval', array_column( $first, 0 ) ),
				);
			}
		}
		ExportPlan::write( $context->work_path(), RestoreFiles::PREFIX_REPORT, $report );
		$this->at( 'options_report' );
	}

	/**
	 * Count a group's rows already under a new name (before they are cleared), into the report.
	 *
	 * @param JobContext            $context Context.
	 * @param Queries               $db      Connection.
	 * @param int[]                 $sites   Site ids.
	 * @param array<string, string> $tables  tables().
	 * @param PrefixKeys            $keys    Names.
	 * @param TablePlan             $plan    Plan.
	 * @return void
	 */
	private function count( JobContext $context, Queries $db, array $sites, array $tables, PrefixKeys $keys, TablePlan $plan ): void {
		$report = ExportPlan::read( $context->work_path(), RestoreFiles::PREFIX_REPORT );
		if ( isset( $tables['usermeta'] ) ) {
			$targets = array_column( $keys->user_keys( $sites ), 1 );
			foreach ( $db->rows( 'SELECT meta_key, COUNT(*) FROM `' . $tables['usermeta'] . '` WHERE meta_key IN (' . self::marks( $targets ) . ') GROUP BY meta_key', $targets ) as $row ) {
				$report['cleared'][ (string) $row[0] ] = (int) $row[1];
			}
		}
		foreach ( $sites as $site ) {
			$table = self::options_table( $plan, $site );
			if ( null === $table ) {
				continue;
			}
			$new = $keys->roles( $site )[2];
			$has = $db->rows( 'SELECT COUNT(*) FROM `' . $table . '` WHERE option_name = ?', array( $new ) );
			if ( (int) ( $has[0][0] ?? 0 ) > 0 ) {
				$report['cleared'][ $new ] = (int) $has[0][0];
			}
		}
		ExportPlan::write( $context->work_path(), RestoreFiles::PREFIX_REPORT, $report );
		$this->at( 'count' );
	}

	/**
	 * This run still holds the job and the rewrite's claim, or it stops.
	 *
	 * @param JobContext $context Context.
	 * @param Ledger     $ledger  Ledger.
	 * @param string     $token   This run's token.
	 * @return void
	 * @throws ClaimLost When another run claimed the rewrite.
	 */
	private function confirm_holder( JobContext $context, Ledger $ledger, string $token ): void {
		$context->confirm_lease();
		$claim = $ledger->get( self::CLAIM );
		if ( null === $claim || $claim['holder'] !== $token ) {
			throw new ClaimLost( 'Another run of this restore took over the table prefix rewrite.' );
		}
	}

	/**
	 * The phase after the cursor's, from its start.
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @return array<string, mixed>
	 */
	private function next_phase( array $cursor ): array {
		$order = array( 'options_report', 'copy', 'a', 'count', 'clear', 'b', 'done' );
		$at    = array_search( $cursor['phase'], $order, true );
		return array(
			'phase' => $order[ false === $at ? count( $order ) - 1 : $at + 1 ],
			'after' => 0,
			'max'   => $cursor['max'],
			'part'  => 'users',
		);
	}

	/**
	 * The next group of sites after an id: the main site (1) first, then the network's other sites from the
	 * backup's blogs table, GROUP at a time, in id order; empty when none are left.
	 *
	 * @param Queries               $db     Connection.
	 * @param array<string, string> $tables tables().
	 * @param int                   $after  Last id of the previous group (0 before the first).
	 * @return int[]
	 */
	private function group( Queries $db, array $tables, int $after ): array {
		$ids = 0 === $after ? array( 1 ) : array();
		if ( isset( $tables['blogs'] ) ) {
			foreach ( $db->rows( 'SELECT blog_id FROM `' . $tables['blogs'] . '` WHERE blog_id > ? ORDER BY blog_id LIMIT ' . self::GROUP, array( (string) max( 1, $after ) ) ) as $row ) {
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
		if ( array() !== $report['options'] ) {
			$context->logger()->warning( 'Options whose names start with the backup\'s table prefix were left as they are (reported, not renamed)', array( 'tables' => $report['options'] ) );
		}
		if ( array() !== $report['copy']['keys'] ) {
			$keys = array_slice( $report['copy']['keys'], 0, self::MAX_LISTED, true );
			$context->logger()->info(
				'User settings named after the backup\'s table prefix were copied under this site\'s (kept under the old name too; a name a user already had was left alone)',
				array(
					'keys'  => $keys,
					'more'  => $report['copy']['more'],
					'total' => count( $report['copy']['keys'] ),
				)
			);
		}
		if ( array() !== $report['cleared'] ) {
			$context->logger()->warning( 'Rows of the backup already named after this site\'s table prefix (never read on the backed-up site) were removed from the restored tables', array( 'rows' => $report['cleared'] ) );
		}
		if ( array() !== $report['missing'] ) {
			$context->logger()->warning( 'Sites of the network whose options table is not restored: their roles were not renamed', array( 'tables' => $report['missing'] ) );
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
