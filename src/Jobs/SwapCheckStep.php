<?php
/**
 * The final check before the swap, and the swap's plan.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Archive\Limits;
use WPCheckpoint\Database\SqlWriter;
use WPCheckpoint\Files\ScanRoots;
use WPCheckpoint\Restore\ChunkHashes;
use WPCheckpoint\Restore\ChunkWalk;
use WPCheckpoint\Restore\ClaimLost;
use WPCheckpoint\Restore\ImportSession;
use WPCheckpoint\Restore\Ledger;
use WPCheckpoint\Restore\Queries;
use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Restore\StagingLayout;
use WPCheckpoint\Restore\SwapPlan;
use WPCheckpoint\Restore\TablePlan;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Standalone\Failure;
use WPCheckpoint\Support\Paths;
use WPCheckpoint\Support\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- job errors with paths and table names; the presenter cleans them.
// phpcs:disable WordPress.WP.AlternativeFunctions -- files in the job's work directory, the staging roots and this plugin's own directory.
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would put a path into the error log; failures are reported.

/**
 * Runs after the files are staged and before anything of the site is
 * changed; it changes nothing of the site either. It writes the holder of
 * the restore's ledger, the swap's plan (Restore\SwapPlan) and files in the
 * job's work directory. Phases, each unit bounded:
 *
 * 1. claim: this run takes every record of the ledger (one statement, the
 *    lease confirmed before and after), so an import run that outlived its
 *    lease can commit no more rows; and the plan's attempt number is fixed
 *    here, in the cursor, never computed again.
 * 2. ledger: every planned table's record is at its last chunk, not being
 *    imported again, and holds the rows the manifest says.
 * 3. count: the temporary tables with transactions are counted, a range of
 *    their primary (or non-null unique) key at a time ("rows" rows per
 *    unit; a table without such a key in one statement). A table whose
 *    count is not its record's was changed after the import (FINAL).
 * 4. files: the files index in lockstep again, each staged file stat'ed:
 *    a regular file (not a link), its size and modification time the
 *    index's (FileStagingStep::target() says which are staged, and where).
 * 5. extras: the staged tree is walked, a directory per unit (the queue in
 *    a work file under a committed length); every entry that is not a
 *    directory is counted, and the counts must be the files staged (4.) and
 *    this plugin's files. A directory with nothing in it is not counted.
 * 6. rehash: where staging continued after a takeover (FileStagingStep
 *    recorded the position), the files from there on are hashed again, a
 *    chunk per unit, over SUSPECT_WINDOW_BYTES: what a run that lost the
 *    job can write before its next checkpoint fails (the checkpoint pace's
 *    bytes, and the unit in flight). A file whose bytes changed while its
 *    size and modification time stayed is found here, not in 4.
 * 7. self: this plugin's staged copy against the running one: the version
 *    (WPCHECKPOINT_VERSION against the staged main file's header), then
 *    file by file (hashes); then the running directory listed again. The
 *    plugin updated since it was staged, or its staged copy changed, is
 *    FINAL. Skipped when the plugins are not staged.
 * 8. live: the site's directories are still where the layout has them
 *    (compared by location); the live tables are listed into a work file (a
 *    page per unit, under a committed length), the plan's view of them.
 * 9. fk: a table outside the swap that references a table the swap moves
 *    away would reference the old site's table afterwards: the swap is
 *    refused and they are named (not FINAL: remove the reference, retry).
 * 10. plan: the rows of other attempts removed (bounded), the entries
 *    written in batches by their sequence number (DIR: each staged group,
 *    then each top-level entry of other-content but the drop-ins; TABLE:
 *    each table of the plan; MOVE: each live table the backup does not
 *    have that is this site's), then the COMPLETE row; the attempt and the
 *    count in a work file for the swap (RestoreFiles::SWAP_PLAN).
 * 11. recount: the temporary tables without transactions are counted once
 *    more, last (the swap counts them again before it changes anything).
 *
 * A mismatch backed by what was read (a count, a size, a hash) ends the
 * job for good (WorkLost: start the restore again); what cannot be read is
 * retried (TransientFailure); the site's settings having changed (a
 * directory, a reference) is an ordinary failure a retry can get past.
 * A unit slower than the whole budget ends the job with the reason, but
 * for a table without a key or a directory counted by WP-CLI, which no web
 * server's time limit ends.
 */
final class SwapCheckStep implements Step {

	const ID     = 'restore_swap_check';
	const MARGIN = 1.5;

	/**
	 * What a run that lost the job can write past its last checkpoint: the checkpoint pace's bytes, and the one
	 * unit (a content chunk) in flight, which the pace does not cut. Time (the pace's other trigger) only makes it
	 * less.
	 */
	const SUSPECT_WINDOW_BYTES = JobContext::CHECKPOINT_BYTES + Limits::CONTENT_CHUNK_BYTES;

	/**
	 * The drop-ins the swap leaves in place: the live one stays, the backup's stays in the staging root.
	 */
	const DROP_INS = array( 'advanced-cache.php', 'db.php', 'object-cache.php', 'fatal-error-handler.php', 'sunrise.php', 'maintenance.php', 'db-error.php' );

	/**
	 * Sizes (tests may make them small): "rows" rows counted per unit, "lines" index lines per unit, "bytes"
	 * bytes hashed per unit, "tables" live tables listed per unit, "batch" plan entries per statement,
	 * "batch_bytes" bytes of names per statement.
	 */
	const SIZES = array(
		'rows'        => 50000,
		'lines'       => 2000,
		'bytes'       => 16777216,
		'tables'      => 500,
		'batch'       => 200,
		'batch_bytes' => 262144,
	);

	/**
	 * Returns the connection: function(): Queries.
	 *
	 * @var callable
	 */
	private $connect;

	/**
	 * Injected parts (tests): "at" function( string $point ): void (a crash seam), "plugin_dir", "plugin_main",
	 * "version" (the running version), "site_dirs" function(): array (group => directory), "sizes", "window" (bytes
	 * hashed again after a takeover position, in place of SUSPECT_WINDOW_BYTES; or a function returning them).
	 *
	 * @var array<string, mixed>
	 */
	private $parts;

	/**
	 * Sizes.
	 *
	 * @var array<string, int>
	 */
	private $sizes;

	/**
	 * Constructor.
	 *
	 * @param callable|null        $connect function(): Queries (tests); the site's own settings by default.
	 * @param array<string, mixed> $parts   See $parts.
	 */
	public function __construct( $connect = null, array $parts = array() ) {
		$this->parts   = $parts;
		$this->sizes   = array_map( 'intval', (array) ( $parts['sizes'] ?? array() ) ) + self::SIZES;
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
	 * @throws WorkLost When the temporary tables or the staged files are not what the restore wrote.
	 * @throws ClaimLost When another run took the ledger (retried).
	 * @throws \RuntimeException When the site changed so that the swap cannot be made as planned.
	 */
	public function run( JobContext $context ): StepResult {
		$work   = $context->work_path();
		$loaded = RestorePreflightStep::load_plan( $work );
		$cursor = array_merge( array( 'phase' => 'claim' ), $context->cursor() );
		$db     = call_user_func( $this->connect );
		$first  = true;
		try {
			// TIMESTAMP keys in UTC: in a time zone with daylight saving time, an hour repeats in local time and a key
			// read as text would convert back to an earlier instant.
			$db->run( "SET SESSION time_zone = '+00:00'" );
			$run           = array(
				'db'      => $db,
				'plan'    => $loaded['plan'],
				'random'  => (string) $loaded['random'],
				'ledger'  => new Ledger( $db, TempTables::ledger( $context->job()->storage_token, $context->job()->id, (string) $loaded['random'] ), bin2hex( random_bytes( 16 ) ) ),
				'loaded'  => $loaded,
				'staging' => RestoreFilesPreflightStep::staging( $work ),
				'work'    => $work,
			);
			$run['layout'] = RestoreFilesPreflightStep::layout_of( $run['staging'], $context->job() );
			$run['swap']   = new SwapPlan( $db, self::base_prefix() . SwapPlan::TABLE );
			$slowest       = 0.0;
			$budget        = (float) $context->budget()->seconds;
			while ( 'done' !== $cursor['phase'] ) {
				if ( ! $first && ( $context->should_stop() || $context->remaining_seconds() < $slowest * self::MARGIN ) ) {
					return StepResult::progress( $cursor, 92, __( 'Checking the restore before the swap', 'wp-checkpoint' ) );
				}
				$first   = false;
				$was     = $cursor['phase'];
				$started = $context->elapsed();
				$unit    = $this->unit( $context, $cursor, $run );
				$cursor  = $unit['cursor'];
				$cost    = $context->elapsed() - $started;
				$slowest = max( $slowest, $cost );
				if ( $cost > $budget && ! ( $unit['unbounded'] && $context->is_cli() ) ) {
					throw new \RuntimeException(
						$unit['unbounded']
							? sprintf( 'Checking the restore before the swap is too slow here: one step of it (%1$s) took %2$d seconds, more than the %3$d-second time budget of a single web request, and it cannot be split. Run the restore with WP-CLI, where no such limit applies: wp wpcheckpoint job run %4$d', $unit['what'], (int) ceil( $cost ), (int) $budget, $context->job()->id )
							: sprintf( 'Checking the restore before the swap is too slow on this server: one unit of work took %1$d seconds, more than the %2$d-second time budget of a single run.', (int) ceil( $cost ), (int) $budget )
					);
				}
				if ( $was !== $cursor['phase'] || $context->should_checkpoint( 0 ) ) {
					$context->checkpoint( $cursor, 92, __( 'Checking the restore before the swap', 'wp-checkpoint' ) );
				}
			}
		} catch ( ClaimLost $e ) {
			$context->confirm_lease();
			throw $e;
		} finally {
			if ( $db instanceof ImportSession ) {
				$db->close();
			}
		}
		return StepResult::done( __( 'The restore is ready for the swap', 'wp-checkpoint' ) );
	}

	/**
	 * One unit of the current phase.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor.
	 * @param array<string, mixed> $run     This run.
	 * @return array{cursor: array<string, mixed>, unbounded: bool, what: string}
	 * @throws WorkLost When the position is not one this version wrote.
	 */
	private function unit( JobContext $context, array $cursor, array $run ): array {
		$unbounded = false;
		$what      = '';
		switch ( $cursor['phase'] ) {
			case 'claim':
				$context->confirm_lease();
				$run['ledger']->take_all();
				$context->confirm_lease(); // A run whose lease ran out between the check and the take stops here.
				$cursor = array(
					'phase'   => 'ledger',
					'attempt' => $run['swap']->next_attempt( $context->job()->id ),
					'i'       => 0,
				);
				$this->at( 'claim' );
				break;
			case 'ledger':
				$cursor = $this->ledger( $cursor, $run );
				break;
			case 'count':
			case 'recount':
				$count     = $this->count( $cursor, $run );
				$cursor    = $count['cursor'];
				$unbounded = $count['unbounded'];
				$what      = $count['what'];
				break;
			case 'files':
				$cursor = $this->files( $context, $cursor, $run );
				break;
			case 'extras':
				$extras    = $this->extras( $context, $cursor, $run );
				$cursor    = $extras['cursor'];
				$unbounded = true;
				$what      = 'counting a directory of staged files';
				break;
			case 'rehash':
				$cursor = $this->rehash( $context, $cursor, $run );
				break;
			case 'self':
				$cursor = $this->self( $context, $cursor, $run );
				break;
			case 'live':
				$cursor = $this->live( $context, $cursor, $run );
				break;
			case 'fk':
				$cursor = $this->fk( $context, $cursor, $run );
				break;
			case 'plan':
				$cursor = $this->plan( $context, $cursor, $run );
				break;
			default:
				throw new WorkLost( 'The position of the final check is not one this version wrote; the work directory was changed.' );
		}
		return array(
			'cursor'    => $cursor,
			'unbounded' => $unbounded,
			'what'      => $what,
		);
	}

	/**
	 * A page of the ledger check: each table's record against the manifest.
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @param array<string, mixed> $run    This run.
	 * @return array<string, mixed>
	 * @throws WorkLost When a record is not that of a table imported whole.
	 */
	private function ledger( array $cursor, array $run ): array {
		$tables  = $run['plan']->tables();
		$rows_of = array_column( RestorePreflightStep::manifest( $run['work'] )->tables(), 'rows', 'name' );
		$total   = count( $tables );
		for ( $n = 0; $n < 100 && (int) $cursor['i'] < $total; $n++ ) {
			$table = $tables[ (int) $cursor['i'] ];
			$state = $run['ledger']->get( (int) $table['number'] );
			if ( null === $state || $state['chunk'] !== (int) $table['chunks'] || $state['restarting'] || (int) ( $rows_of[ $table['table'] ] ?? -1 ) !== $state['rows'] ) {
				throw new WorkLost( sprintf( 'The import of the table %s is not complete as recorded: its record was changed after the import. Start the restore again.', $table['table'] ) );
			}
			++$cursor['i'];
		}
		if ( (int) $cursor['i'] >= count( $tables ) ) {
			$cursor = array(
				'phase'   => 'count',
				'attempt' => $cursor['attempt'],
				'i'       => 0,
				'key'     => null,
				'sum'     => 0,
			);
		}
		return $cursor;
	}

	/**
	 * A unit of counting: the next range of a table's key, or a table without a key in one statement. The count
	 * phase counts the tables with transactions, the recount phase those without.
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @param array<string, mixed> $run    This run.
	 * @return array{cursor: array<string, mixed>, unbounded: bool, what: string}
	 * @throws WorkLost When a table holds other rows than the import recorded.
	 */
	private function count( array $cursor, array $run ): array {
		$db     = $run['db'];
		$tables = $run['plan']->tables();
		$want   = 'count' === $cursor['phase'];
		$total  = count( $tables );
		while ( (int) $cursor['i'] < $total ) {
			$table = $tables[ (int) $cursor['i'] ];
			$state = $run['ledger']->get( (int) $table['number'] );
			if ( null !== $state && $state['transactional'] === $want ) {
				break;
			}
			++$cursor['i'];
		}
		if ( (int) $cursor['i'] >= count( $tables ) ) {
			return array(
				'cursor'    => $want ? array(
					'phase'   => 'files',
					'attempt' => $cursor['attempt'],
					'at'      => null,
					'counts'  => array(),
				) : array(
					'phase'   => 'done',
					'attempt' => $cursor['attempt'],
				),
				'unbounded' => false,
				'what'      => '',
			);
		}
		$table = $tables[ (int) $cursor['i'] ];
		$name  = SqlWriter::identifier( $table['temporary'] );
		$key   = self::key_columns( $db, $table['temporary'] );
		// Rows the table prefix rewrite copied are above the highest key at its plan: counted are the imported ones.
		$above = self::rewrite( $run['work'] )['above'][ $table['temporary'] ] ?? null;
		$only  = null === $above ? '' : ' AND ' . SqlWriter::identifier( 'umeta_id' ) . ' <= ' . (int) $above;
		if ( array() === $key ) {
			$held = (int) ( $db->rows( 'SELECT COUNT(*) FROM ' . $name . ( '' === $only ? '' : ' WHERE 1 = 1' . $only ) )[0][0] ?? -1 );
			$this->at( 'counted_whole' );
			$this->counted( $table, $held, $run );
			++$cursor['i'];
			return array(
				'cursor'    => $cursor,
				'unbounded' => true,
				'what'      => 'counting the table ' . $table['table'] . ', which has no key to count it by in parts',
			);
		}
		// Key values travel as text the cursor keeps exactly (hex for strings and bytes, digits for numbers) and are
		// compared back in the column's own terms: a key in bytes or a character set the connection cannot hold
		// would otherwise come back changed from the cursor, and a range would be counted twice or never end.
		$columns = implode( ', ', array_column( $key, 'name' ) );
		$tuple   = '(' . $columns . ')';
		$marks   = '(' . implode( ', ', array_column( $key, 'compare' ) ) . ')';
		$after   = is_array( $cursor['key'] ) ? array_map( 'strval', $cursor['key'] ) : null;
		$where   = null === $after ? '' : ' WHERE ' . $tuple . ' > ' . $marks;
		$bound   = $db->rows( 'SELECT ' . implode( ', ', array_column( $key, 'select' ) ) . ' FROM ' . $name . $where . ' ORDER BY ' . $columns . ' LIMIT 1 OFFSET ' . ( $this->sizes['rows'] - 1 ), null === $after ? array() : $after );
		if ( array() === $bound ) {
			$last = (int) ( $db->rows( 'SELECT COUNT(*) FROM ' . $name . ( '' === $where ? ' WHERE 1 = 1' : $where ) . $only, null === $after ? array() : $after )[0][0] ?? -1 );
			$this->counted( $table, (int) $cursor['sum'] + $last, $run );
			$cursor['i']   = (int) $cursor['i'] + 1;
			$cursor['key'] = null;
			$cursor['sum'] = 0;
		} else {
			$upper         = array_map( 'strval', $bound[0] );
			$range         = (int) ( $db->rows( 'SELECT COUNT(*) FROM ' . $name . ( null === $after ? ' WHERE ' : $where . ' AND ' ) . $tuple . ' <= ' . $marks . $only, array_merge( null === $after ? array() : $after, $upper ) )[0][0] ?? -1 );
			$cursor['key'] = $upper;
			$cursor['sum'] = (int) $cursor['sum'] + $range;
		}
		return array(
			'cursor'    => $cursor,
			'unbounded' => false,
			'what'      => '',
		);
	}

	/**
	 * A table's count against its record.
	 *
	 * @param array<string, mixed> $table The table.
	 * @param int                  $held  Rows counted.
	 * @param array<string, mixed> $run   This run.
	 * @return void
	 * @throws WorkLost When they differ.
	 */
	private function counted( array $table, int $held, array $run ): void {
		$state = $run['ledger']->get( (int) $table['number'] );
		$want  = null === $state ? -1 : $state['rows'] - (int) ( self::rewrite( $run['work'] )['removed'][ $table['temporary'] ] ?? 0 );
		if ( $held !== $want ) {
			throw new WorkLost( sprintf( 'The temporary table of %1$s holds %2$d rows where the restore left %3$d: it was changed after the import. Start the restore again.', $table['table'], $held, $want ) );
		}
		$this->at( 'counted' );
	}

	/**
	 * What the table prefix rewrite changed in the row counts (PrefixRewriteStep's report): "removed" (temporary
	 * table => rows) and "above" (temporary usermeta => the highest key before its copies). Nothing when it had
	 * nothing to do (the backup has this site's prefix).
	 *
	 * @param string $work Work directory.
	 * @return array{removed: array<string, int>, above: array<string, int>}
	 * @throws WorkLost When the report is one an older version wrote, without the counts.
	 */
	private static function rewrite( string $work ): array {
		$path = RestoreFiles::path( $work, RestoreFiles::PREFIX_REPORT );
		clearstatcache( true, $path );
		if ( ! is_file( $path ) ) {
			return array(
				'removed' => array(),
				'above'   => array(),
			);
		}
		$report = ExportPlan::read( $work, RestoreFiles::PREFIX_REPORT );
		if ( ! isset( $report['rows'] ) || ! is_array( $report['rows'] ) ) {
			// Written by an older version, which did not record what it removed: the counts cannot be told right, and
			// running the rewrite again would remove the rows it renamed.
			throw new WorkLost( 'The table prefix of this restore was rewritten by an older version of WP Checkpoint, which did not record what it changed, so the tables cannot be checked. Start the restore again.' );
		}
		$rows = $report['rows'];
		return array(
			'removed' => array_map( 'intval', (array) ( $rows['removed'] ?? array() ) ),
			'above'   => array_map( 'intval', (array) ( $rows['above'] ?? array() ) ),
		);
	}

	/**
	 * The key a table is counted by in ranges: its primary key, or else its first unique key whose columns are
	 * all NOT NULL (whole columns, not prefixes), each column with how its values are read ("select") and a
	 * value read so is compared back ("compare", with one "?"): integers and decimals as digits; dates and times
	 * as their text; character strings as the hex of their bytes, converted back in the column's character set
	 * and collation; binary strings as hex. None when there is no such key, or it has a column of another type
	 * (floating point, which does not come back exactly as text; ENUM and SET, which sort in another order than
	 * they compare; anything else), or a name with "?" (the connection's placeholder): the table is then counted
	 * in one statement.
	 *
	 * @param Queries $db    Connection.
	 * @param string  $table Table.
	 * @return array<int, array{name: string, select: string, compare: string}>
	 */
	private static function key_columns( Queries $db, string $table ): array {
		$keys  = array();
		$nulls = array();
		foreach ( $db->rows( 'SHOW INDEX FROM ' . SqlWriter::identifier( $table ) ) as $row ) {
			// Table, Non_unique, Key_name, Seq_in_index, Column_name, Collation, Cardinality, Sub_part, Packed, Null.
			if ( '0' !== (string) $row[1] || null !== $row[7] ) {
				continue; // Not unique, or on a prefix of the column.
			}
			$keys[ (string) $row[2] ][ (int) $row[3] ] = (string) $row[4];
			if ( 'YES' === strtoupper( (string) $row[9] ) ) {
				$nulls[ (string) $row[2] ] = true;
			}
		}
		$chosen = array();
		if ( isset( $keys['PRIMARY'] ) ) {
			$chosen = $keys['PRIMARY'];
		} else {
			foreach ( $keys as $name => $columns ) {
				if ( ! isset( $nulls[ $name ] ) ) {
					$chosen = $columns;
					break;
				}
			}
		}
		if ( array() === $chosen ) {
			return array();
		}
		ksort( $chosen );
		$types = array();
		foreach ( $db->rows( 'SHOW FULL COLUMNS FROM ' . SqlWriter::identifier( $table ) ) as $row ) {
			// Field, Type, Collation, ...
			$types[ (string) $row[0] ] = array( strtolower( (string) $row[1] ), null === $row[2] ? '' : (string) $row[2] );
		}
		$out = array();
		foreach ( $chosen as $column ) {
			if ( false !== strpos( $column, '?' ) || ! isset( $types[ $column ] ) ) {
				return array();
			}
			list( $type, $collation ) = $types[ $column ];
			$name                     = SqlWriter::identifier( $column );
			if ( 1 === preg_match( '/\A(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric)\b/', $type ) ) {
				$out[] = array(
					'name'    => $name,
					'select'  => $name,
					'compare' => '?',
				);
			} elseif ( 1 === preg_match( '/\A(date|datetime|timestamp|time)\b/', $type ) ) {
				$out[] = array(
					'name'    => $name,
					'select'  => $name,
					'compare' => '?',
				);
			} elseif ( 1 === preg_match( '/\A(char|varchar)\b/', $type ) && 1 === preg_match( '/\A([a-z0-9]+)_[a-z0-9_]+\z/', strtolower( $collation ), $charset ) ) {
				$out[] = array(
					'name'    => $name,
					'select'  => 'HEX(' . $name . ')',
					'compare' => 'CONVERT(UNHEX(?) USING ' . $charset[1] . ') COLLATE ' . strtolower( $collation ),
				);
			} elseif ( 1 === preg_match( '/\A(binary|varbinary)\b/', $type ) ) {
				$out[] = array(
					'name'    => $name,
					'select'  => 'HEX(' . $name . ')',
					'compare' => 'UNHEX(?)',
				);
			} else {
				return array();
			}
		}
		return $out;
	}

	/**
	 * A page of the files index: each staged file stat'ed against its line.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor.
	 * @param array<string, mixed> $run     This run.
	 * @return array<string, mixed>
	 * @throws WorkLost When a staged file is missing, of another type, or differs in size or modification time.
	 * @throws TransientFailure When a staged file's directory cannot be read.
	 */
	private function files( JobContext $context, array $cursor, array $run ): array {
		$work   = $context->work_path();
		$walk   = $this->walk( $work, $run );
		$at     = null === $cursor['at'] ? $walk->files_start( FileStagingStep::database_chunks( $work ) ) : $cursor['at'];
		$staged = (array) $run['staging']['staged'];
		$plan   = ExportPlan::read( $work, RestoreFiles::STAGE_PLAN );
		$skip   = array_keys( (array) ( $plan['self'] ?? array() ) );
		$fold   = FileStagingStep::fold( (string) ( $plan['running'] ?? '' ) );
		$layout = $run['layout'];
		for ( $n = 0; $n < $this->sizes['lines']; $n++ ) {
			$chunk = $walk->at( $at );
			if ( null === $chunk ) {
				return array(
					'phase'   => 'extras',
					'attempt' => $cursor['attempt'],
					'counts'  => $cursor['counts'],
					'queue'   => 0,
					'read'    => 0,
					'found'   => array(),
				);
			}
			$at     = $chunk['next'];
			$target = FileStagingStep::target( (string) $chunk['line']['p'], $chunk['entry'], $layout, $staged, $skip, $fold );
			if ( null === $target || '' !== $target['special'] ) {
				continue;
			}
			$path = $layout->root( $target['group'] ) . '/' . $target['relative'];
			$stat = self::lstat( $path );
			$line = $chunk['line'];
			if ( null === $stat && ! Paths::positively_gone( $path ) ) {
				throw new TransientFailure( sprintf( 'Whether the staged file %s is there cannot be told.', $target['relative'] ) );
			}
			if ( null === $stat || 0100000 !== ( $stat['mode'] & 0170000 ) || (int) $stat['size'] !== (int) $line['b'] || (int) $stat['mtime'] !== (int) $line['m'] ) {
				throw new WorkLost( sprintf( 'The staged file %s is not the one the restore wrote (%s): the staged files were changed. Start the restore again.', $target['relative'], null === $stat ? 'it is missing' : ( 0100000 !== ( $stat['mode'] & 0170000 ) ? 'it is not a regular file' : 'its size or modification time differs' ) ) );
			}
			$cursor['counts'][ $target['group'] ] = (int) ( $cursor['counts'][ $target['group'] ] ?? 0 ) + 1;
		}
		$cursor['at'] = $at;
		return $cursor;
	}

	/**
	 * One directory of the staged tree: its entries counted, its directories queued.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor.
	 * @param array<string, mixed> $run     This run.
	 * @return array{cursor: array<string, mixed>}
	 * @throws WorkLost When the counts are not the files staged.
	 * @throws TransientFailure When a directory cannot be read.
	 */
	private function extras( JobContext $context, array $cursor, array $run ): array {
		$work   = $context->work_path();
		$queue  = RestoreFiles::path( $work, RestoreFiles::SWAP_CHECK_TREE );
		$layout = $run['layout'];
		if ( 0 === (int) $cursor['queue'] ) {
			$seed = '';
			foreach ( (array) $run['staging']['staged'] as $group ) {
				$seed .= wp_json_encode(
					array(
						'g' => (string) $group,
						'd' => '',
					)
				) . "\n";
			}
			self::append( $queue, 0, $seed );
			$cursor['queue'] = strlen( $seed );
		}
		self::truncate_to( $queue, (int) $cursor['queue'] );
		$item = self::line_at( $queue, (int) $cursor['read'] );
		if ( null === $item ) {
			$expected = (array) $cursor['counts'];
			if ( in_array( 'plugins', (array) $run['staging']['staged'], true ) ) {
				$expected['plugins'] = (int) ( $expected['plugins'] ?? 0 ) + count( $this->plugin_lines( $work ) );
			}
			foreach ( (array) $run['staging']['staged'] as $group ) {
				$have = (int) ( $cursor['found'][ $group ] ?? 0 );
				$want = (int) ( $expected[ $group ] ?? 0 );
				if ( $have !== $want ) {
					throw new WorkLost( sprintf( 'The staged %1$s hold %2$d entries where the restore staged %3$d: the staged files were changed. Start the restore again.', $group, $have, $want ) );
				}
			}
			return array(
				'cursor' => array(
					'phase'   => 'rehash',
					'attempt' => $cursor['attempt'],
					's'       => 0,
					'at'      => null,
					'done'    => 0,
					'bytes'   => 0,
				),
			);
		}
		$group = (string) $item['value']['g'];
		$dir   = (string) $item['value']['d'];
		$path  = $layout->stage_dir( $group ) . ( '' === $dir ? '' : '/' . $dir );
		$list  = @opendir( $path );
		if ( false === $list ) {
			throw new TransientFailure( sprintf( 'The staged directory %s cannot be read.', $group . ( '' === $dir ? '' : '/' . $dir ) ) );
		}
		$count = 0;
		$more  = '';
		try {
			while ( false !== ( $entry = readdir( $list ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- the idiom of readdir().
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$stat = @lstat( $path . '/' . $entry );
				if ( false !== $stat && 0100000 !== ( $stat['mode'] & 0170000 ) && 0040000 !== ( $stat['mode'] & 0170000 ) ) {
					// Staging writes regular files and directories only: a link or anything else was put there later.
					throw new WorkLost( sprintf( 'The staged files hold %s, which the restore did not write (a link or a special file): the staged files were changed. Start the restore again.', $group . '/' . ( '' === $dir ? '' : $dir . '/' ) . $entry ) );
				}
				if ( false !== $stat && 0040000 === ( $stat['mode'] & 0170000 ) ) {
					$more .= wp_json_encode(
						array(
							'g' => $group,
							'd' => '' === $dir ? $entry : $dir . '/' . $entry,
						)
					) . "\n";
				} else {
					++$count;
				}
			}
		} finally {
			closedir( $list );
		}
		self::append( $queue, (int) $cursor['queue'], $more );
		$cursor['queue']           = (int) $cursor['queue'] + strlen( $more );
		$cursor['read']            = $item['next'];
		$cursor['found'][ $group ] = (int) ( $cursor['found'][ $group ] ?? 0 ) + $count;
		$this->at( 'extras' );
		return array( 'cursor' => $cursor );
	}

	/**
	 * One chunk of a file after a recorded takeover position, hashed again.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor.
	 * @param array<string, mixed> $run     This run.
	 * @return array<string, mixed>
	 * @throws WorkLost When a staged file's bytes are not the backup's.
	 */
	private function rehash( JobContext $context, array $cursor, array $run ): array {
		$work     = $context->work_path();
		$suspects = FileStagingStep::suspects( $work );
		$next     = array(
			'phase'   => 'self',
			'attempt' => $cursor['attempt'],
			'line'    => 0,
			'version' => false,
		);
		$total    = count( $suspects );
		while ( (int) $cursor['s'] < $total ) {
			if ( null === $cursor['at'] ) {
				$cursor['at']    = $suspects[ (int) $cursor['s'] ]['at'];
				$cursor['done']  = (int) $suspects[ (int) $cursor['s'] ]['done'];
				$cursor['bytes'] = 0;
			}
			$window = $this->parts['window'] ?? self::SUSPECT_WINDOW_BYTES;
			if ( (int) $cursor['bytes'] >= (int) ( is_callable( $window ) ? call_user_func( $window ) : $window ) ) {
				$cursor['s']  = (int) $cursor['s'] + 1;
				$cursor['at'] = null;
				continue;
			}
			$walk    = $this->walk( $work, $run );
			$plan    = ExportPlan::read( $work, RestoreFiles::STAGE_PLAN );
			$skip    = array_keys( (array) ( $plan['self'] ?? array() ) );
			$fold    = FileStagingStep::fold( (string) ( $plan['running'] ?? '' ) );
			$chunk   = null;
			$target  = null;
			$skipped = 0;
			while ( true ) {
				$chunk = $walk->at( $cursor['at'] );
				if ( null === $chunk ) {
					break;
				}
				$target = FileStagingStep::target( (string) $chunk['line']['p'], $chunk['entry'], $run['layout'], (array) $run['staging']['staged'], $skip, $fold );
				if ( null !== $target && '' === $target['special'] ) {
					break;
				}
				// Not written by staging, so not counted by its pace either: not in the window.
				$cursor['at']   = $chunk['next'];
				$cursor['done'] = 0;
				if ( ++$skipped >= $this->sizes['lines'] ) {
					return $cursor;
				}
			}
			if ( null === $chunk || null === $target ) {
				$cursor['s']  = (int) $cursor['s'] + 1;
				$cursor['at'] = null;
				continue;
			}
			$line = $chunk['line'];
			$size = (int) $line['b'];
			if ( null === $line['h'] || (int) $cursor['done'] >= $size ) {
				// Written, but nothing to hash it against (no hash in the index, or empty): its bytes count as the pace counted them.
				$cursor['bytes'] = (int) $cursor['bytes'] + max( 0, $size - (int) $cursor['done'] );
				$cursor['at']    = $chunk['next'];
				$cursor['done']  = 0;
				return $cursor;
			}
			$chunk_bytes = (int) $run['loaded']['chunk_bytes'];
			$length      = (int) min( $chunk_bytes, $size - (int) $cursor['done'] );
			$hashes      = new ChunkHashes( (int) $cursor['done'], $chunk_bytes, $size );
			$handle      = @fopen( $run['layout']->root( $target['group'] ) . '/' . $target['relative'], 'rb' );
			if ( false === $handle || 0 !== fseek( $handle, (int) $cursor['done'] ) ) {
				throw new TransientFailure( sprintf( 'The staged file %s cannot be read.', $target['relative'] ) );
			}
			$left = $length;
			while ( $left > 0 ) {
				$piece = fread( $handle, (int) min( 1048576, $left ) );
				if ( false === $piece || '' === $piece ) {
					break;
				}
				$hashes->update( $piece );
				$left -= strlen( $piece );
			}
			fclose( $handle );
			$wrong = $left > 0 ? 'it is shorter than recorded' : $hashes->mismatch( $line );
			if ( null !== $wrong ) {
				throw new WorkLost( sprintf( 'The staged file %1$s is not the one the restore wrote (%2$s), although its size and modification time are: it was written again by a run that had lost the restore. Start the restore again.', $target['relative'], $wrong ) );
			}
			$cursor['bytes'] = (int) $cursor['bytes'] + $length;
			$cursor['done']  = (int) $cursor['done'] + $length;
			if ( (int) $cursor['done'] >= $size ) {
				$cursor['at']   = $chunk['next'];
				$cursor['done'] = 0;
			}
			$this->at( 'rehashed' );
			return $cursor;
		}
		return $next;
	}

	/**
	 * A unit of the self check: the version, then a file of the staged copy against the running one, then the
	 * running directory listed again.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor.
	 * @param array<string, mixed> $run     This run.
	 * @return array<string, mixed>
	 * @throws WorkLost When the running plugin was updated since it was staged, or its staged copy changed.
	 */
	private function self( JobContext $context, array $cursor, array $run ): array {
		$next = array(
			'phase'   => 'live',
			'attempt' => $cursor['attempt'],
			'part'    => 'dirs',
			'written' => 0,
			'after'   => '',
		);
		if ( ! in_array( 'plugins', (array) $run['staging']['staged'], true ) ) {
			return $next; // The live plugins, this one included, stay.
		}
		$work    = $context->work_path();
		$source  = $this->plugin_dir();
		$staged  = $run['layout']->stage_dir( 'plugins' ) . '/' . basename( $source );
		$running = (string) ( $this->parts['version'] ?? WPCHECKPOINT_VERSION );
		if ( empty( $cursor['version'] ) ) {
			$main = $staged . '/' . basename( (string) ( $this->parts['plugin_main'] ?? WPCHECKPOINT_FILE ) );
			if ( ! self::readable( $main, 'The staged copy of WP Checkpoint was changed (its main file is gone): start the restore again.' ) ) {
				throw new TransientFailure( 'The staged copy of WP Checkpoint cannot be read.' );
			}
			$header  = function_exists( 'get_file_data' ) ? get_file_data( $main, array( 'Version' => 'Version' ) ) : array();
			$version = (string) ( $header['Version'] ?? '' );
			if ( $version !== $running ) {
				throw new WorkLost( sprintf( 'WP Checkpoint was updated while the restore ran (%1$s was staged, %2$s is running): the swap would put the older copy in place. Start the restore again.', '' === $version ? '?' : $version, $running ) );
			}
			$cursor['version'] = true;
			return $cursor;
		}
		$lines = $this->plugin_lines( $work );
		$bytes = 0;
		$total = count( $lines );
		while ( (int) $cursor['line'] < $total && $bytes < $this->sizes['bytes'] ) {
			$item   = $lines[ (int) $cursor['line'] ];
			$live   = $source . '/' . $item['r'];
			$copy   = $staged . '/' . $item['r'];
			$have   = @hash_file( 'sha256', $copy );
			$want   = @hash_file( 'sha256', $live );
			$bytes += (int) $item['b'];
			// Only what was read decides: a file that is gone (its directory lists without it) is evidence, one that
			// cannot be read is not.
			if ( false === $want && ! self::readable( $live, sprintf( 'WP Checkpoint was updated while the restore ran (its file %s is gone): the swap would put an older copy in place. Start the restore again.', $item['r'] ) ) ) {
				throw new TransientFailure( sprintf( 'A file of WP Checkpoint (%s) cannot be read.', $item['r'] ) );
			}
			if ( false === $have && ! self::readable( $copy, sprintf( 'The staged copy of WP Checkpoint was changed (its file %s is gone): start the restore again.', $item['r'] ) ) ) {
				throw new TransientFailure( sprintf( 'A file of the staged copy of WP Checkpoint (%s) cannot be read.', $item['r'] ) );
			}
			if ( false === $want || false === $have ) {
				throw new TransientFailure( sprintf( 'A file of WP Checkpoint (%s) cannot be read.', $item['r'] ) );
			}
			if ( $have !== $want ) {
				$updated = (int) @filesize( $live ) !== (int) $item['b'] || (int) @filemtime( $live ) !== (int) @filemtime( $copy );
				throw new WorkLost(
					$updated
						? sprintf( 'WP Checkpoint was updated while the restore ran (its file %s changed): the swap would put the older copy in place. Start the restore again.', $item['r'] )
						: sprintf( 'The staged copy of WP Checkpoint was changed (its file %s): start the restore again.', $item['r'] )
				);
			}
			$cursor['line'] = (int) $cursor['line'] + 1;
		}
		if ( (int) $cursor['line'] < $total ) {
			return $cursor;
		}
		$listed = array_column( $lines, 'r' );
		$now    = self::files_under( $source, RestoreFilesPreflightStep::MAX_PLUGIN_ENTRIES );
		sort( $listed, SORT_STRING );
		if ( null === $now || $now !== $listed ) {
			throw new WorkLost( 'WP Checkpoint was updated while the restore ran (its files are not the ones staged): the swap would put the older copy in place. Start the restore again.' );
		}
		$this->at( 'self' );
		return $next;
	}

	/**
	 * A unit of the live phase: the site's directories against the layout, then a page of live tables into the
	 * work file the plan reads.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor.
	 * @param array<string, mixed> $run     This run.
	 * @return array<string, mixed>
	 * @throws RetryFrom When a directory of the site is no longer where the layout has it (a retry checks all again).
	 */
	private function live( JobContext $context, array $cursor, array $run ): array {
		$file = RestoreFiles::path( $context->work_path(), RestoreFiles::SWAP_LIVE );
		if ( 'dirs' === $cursor['part'] ) {
			$now = isset( $this->parts['site_dirs'] ) ? (array) call_user_func( $this->parts['site_dirs'] ) : ScanRoots::site_directories();
			foreach ( (array) $run['staging']['staged'] as $group ) {
				$was = (string) ( $run['staging']['groups'][ $group ] ?? '' );
				if ( ! isset( $now[ $group ] ) || ! Paths::same_location( (string) $now[ $group ], $was ) ) {
					// A retry checks everything again from the start: the site may have changed more than this.
					throw new RetryFrom( sprintf( 'The %1$s directory of the site moved since the restore staged its files (it was %2$s, it is %3$s now): the swap would put the files in the wrong place. Put it back and retry, or start the restore again.', $group, $was, (string) ( $now[ $group ] ?? '?' ) ), self::ID );
				}
			}
			self::append( $file, 0, '' );
			$cursor['part'] = 'tables';
			return $cursor;
		}
		self::truncate_to( $file, (int) $cursor['written'] );
		$page = $run['db']->rows( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND BINARY TABLE_NAME > BINARY ? ORDER BY BINARY TABLE_NAME LIMIT ' . $this->sizes['tables'], array( (string) $cursor['after'] ) );
		$text = '';
		foreach ( $page as $row ) {
			$text .= wp_json_encode( (string) $row[0] ) . "\n";
		}
		self::append( $file, (int) $cursor['written'], $text );
		$cursor['written'] = (int) $cursor['written'] + strlen( $text );
		if ( count( $page ) < $this->sizes['tables'] ) {
			return array(
				'phase'   => 'fk',
				'attempt' => $cursor['attempt'],
				'offset'  => 0,
				'found'   => array(),
			);
		}
		$cursor['after'] = (string) $page[ count( $page ) - 1 ][0];
		return $cursor;
	}

	/**
	 * A page of the foreign keys of this database: one from a table the swap leaves to a table it moves away
	 * refuses the swap.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor.
	 * @param array<string, mixed> $run     This run.
	 * @return array<string, mixed>
	 * @throws RetryFrom When there is one (a retry checks all again).
	 */
	private function fk( JobContext $context, array $cursor, array $run ): array {
		$entries  = $this->table_entries( $context, $run );
		$moved    = array();
		$swapped  = array();
		$incoming = array();
		foreach ( $entries as $entry ) {
			$swapped[ $entry['live'] ] = true;
			if ( $entry['had_live'] ) {
				$moved[ $entry['live'] ] = true;
			}
			if ( SwapPlan::TABLE_OF === $entry['kind'] ) {
				$incoming[ $entry['stage'] ] = true;
			}
		}
		// Every constraint that references a table of this database, from any database, in a fixed order.
		$page = $run['db']->rows( 'SELECT CONSTRAINT_SCHEMA = DATABASE(), CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE UNIQUE_CONSTRAINT_SCHEMA = DATABASE() ORDER BY BINARY CONSTRAINT_SCHEMA, BINARY TABLE_NAME, BINARY CONSTRAINT_NAME LIMIT ' . $this->sizes['tables'] . ' OFFSET ' . (int) $cursor['offset'] );
		foreach ( $page as $row ) {
			$here = '1' === (string) $row[0];
			$from = (string) $row[2];
			$to   = (string) $row[3];
			if ( ! isset( $moved[ $to ] ) || count( $cursor['found'] ) >= 10 ) {
				continue;
			}
			// A table of another database, or one this restore brings in (its references to what it brings too were
			// renamed on import), stays pointing at the name the old table goes to; a table moved away as well goes
			// with it; the plugin's own and other temporary tables are never swapped.
			$left = ! $here || isset( $incoming[ $from ] ) || ( ! isset( $swapped[ $from ] ) && ! self::ours( $from ) );
			if ( $left ) {
				$cursor['found'][] = sprintf( '%1$s (%2$s → %3$s)', (string) $row[1], $here ? $from : 'a table in another database', $to );
			}
		}
		if ( count( $page ) === $this->sizes['tables'] ) {
			$cursor['offset'] = (int) $cursor['offset'] + count( $page );
			return $cursor;
		}
		if ( array() !== $cursor['found'] ) {
			throw new RetryFrom( sprintf( 'Tables the restore does not replace have foreign keys to tables it replaces: after the swap they would point at the old site\'s tables, keep them from being removed, and hold the restored data to the old. Either include those tables in the restore, or remove their foreign keys, then retry. %s', implode( '; ', $cursor['found'] ) ), self::ID );
		}
		return array(
			'phase'   => 'plan',
			'attempt' => $cursor['attempt'],
			'part'    => 'old',
			'seq'     => 0,
		);
	}

	/**
	 * A unit of writing the plan: rows of other attempts removed, a batch of entries, or the COMPLETE row.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor.
	 * @param array<string, mixed> $run     This run.
	 * @return array<string, mixed>
	 * @throws TransientFailure When the plan's record cannot be written.
	 */
	private function plan( JobContext $context, array $cursor, array $run ): array {
		$job     = $context->job()->id;
		$attempt = (int) $cursor['attempt'];
		$swap    = $run['swap'];
		$context->confirm_lease(); // A run that lost the job writes no more of the plan.
		if ( 'old' === $cursor['part'] ) {
			if ( $swap->delete_others( $job, $attempt ) < SwapPlan::DELETE_ROWS ) {
				$cursor['part'] = 'entries';
			}
			$this->at( 'plan_old' );
			return $cursor;
		}
		$entries = array_merge( $this->dir_entries( $run ), $this->table_entries( $context, $run ) );
		if ( 'entries' === $cursor['part'] ) {
			$from  = (int) $cursor['seq'];
			$batch = array();
			$bytes = 0;
			$total = count( $entries );
			$most  = $this->sizes['batch'];
			for ( $i = $from, $n = 0; $i < $total && $n < $most; $i++, $n++ ) {
				$size = strlen( $entries[ $i ]['live'] ) + strlen( $entries[ $i ]['stage'] ) + strlen( $entries[ $i ]['old'] );
				if ( array() !== $batch && $bytes + $size > $this->sizes['batch_bytes'] ) {
					break;
				}
				$batch[] = $entries[ $i ];
				$bytes  += $size;
			}
			$swap->write( $job, $attempt, $from, $batch );
			$this->at( 'plan_batch' );
			$cursor['seq'] = $from + count( $batch );
			if ( (int) $cursor['seq'] >= count( $entries ) ) {
				$cursor['part'] = 'complete';
			}
			return $cursor;
		}
		$swap->complete( $job, $attempt, count( $entries ) );
		$this->at( 'plan_complete' );
		ExportPlan::write(
			$context->work_path(),
			RestoreFiles::SWAP_PLAN,
			array(
				'attempt' => $attempt,
				'entries' => count( $entries ),
			)
		);
		return array(
			'phase'   => 'recount',
			'attempt' => $attempt,
			'i'       => 0,
			'key'     => null,
			'sum'     => 0,
		);
	}

	/**
	 * The plan's directory units: each staged group but other-content, then each top-level entry of the staged
	 * other-content but the drop-ins, in name order.
	 *
	 * @param array<string, mixed> $run This run.
	 * @return array<int, array{kind: string, live: string, stage: string, old: string, had_live: bool}>
	 * @throws TransientFailure When whether a live directory is there cannot be told.
	 */
	private function dir_entries( array $run ): array {
		$layout = $run['layout'];
		$out    = array();
		foreach ( StagingLayout::GROUPS as $group ) {
			if ( StagingLayout::OTHER === $group || ! in_array( $group, (array) $run['staging']['staged'], true ) ) {
				continue;
			}
			$out[] = self::dir_entry( $layout->live_dir( $group ), $layout->stage_dir( $group ), $layout->root( $group ) . '/old/' . $group );
		}
		if ( in_array( StagingLayout::OTHER, (array) $run['staging']['staged'], true ) ) {
			$stage = $layout->stage_dir( StagingLayout::OTHER );
			$names = @scandir( $stage );
			if ( ! is_array( $names ) ) {
				throw new TransientFailure( 'The staged other content cannot be listed.' );
			}
			sort( $names, SORT_STRING );
			foreach ( $names as $name ) {
				if ( '.' === $name || '..' === $name || in_array( $name, self::DROP_INS, true ) ) {
					continue;
				}
				$out[] = self::dir_entry( $layout->live_dir( StagingLayout::OTHER ) . '/' . $name, $stage . '/' . $name, $layout->root( StagingLayout::OTHER ) . '/old/' . StagingLayout::OTHER . '/' . $name );
			}
		}
		return $out;
	}

	/**
	 * A directory unit, with whether the live one is there (by lstat; not there only when its parent lists
	 * without it).
	 *
	 * @param string $live  Live path.
	 * @param string $stage Staged path.
	 * @param string $old   Where the live one goes.
	 * @return array{kind: string, live: string, stage: string, old: string, had_live: bool}
	 * @throws TransientFailure When whether it is there cannot be told.
	 */
	private static function dir_entry( string $live, string $stage, string $old ): array {
		$there = null !== self::lstat( $live );
		if ( ! $there && ! Paths::positively_gone( $live ) ) {
			throw new TransientFailure( sprintf( 'Whether %s is there cannot be told.', $live ) );
		}
		return array(
			'kind'     => SwapPlan::DIR,
			'live'     => $live,
			'stage'    => $stage,
			'old'      => $old,
			'had_live' => $there,
		);
	}

	/**
	 * The plan's table entries: each table of the plan (TABLE), then each live table the backup does not have
	 * that is this site's (MOVE), from the live tables listed into the work file.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $run     This run.
	 * @return array<int, array{kind: string, live: string, stage: string, old: string, had_live: bool}>
	 */
	private function table_entries( JobContext $context, array $run ): array {
		$job   = $context->job();
		$plan  = $run['plan'];
		$live  = $this->live_tables( $context->work_path() );
		$site  = $plan->site_prefix();
		$out   = array();
		$final = array();
		foreach ( $plan->tables() as $table ) {
			$final[ $table['final'] ] = true;
			$out[]                    = array(
				'kind'     => SwapPlan::TABLE_OF,
				'live'     => (string) $table['final'],
				'stage'    => (string) $table['temporary'],
				'old'      => TempTables::old( $job->storage_token, $job->id, $run['random'], self::without( (string) $table['final'], $site ) ),
				'had_live' => isset( $live[ $table['final'] ] ),
			);
		}
		$kept = array();
		foreach ( array_keys( $plan->skipped() ) as $name ) {
			$kept[ $plan->final_name( (string) $name ) ] = true; // Left out of the restore: the live one stays.
		}
		$core = '' === $site ? self::core_tables() : array();
		foreach ( array_keys( $live ) as $name ) {
			$name = (string) $name;
			if ( isset( $final[ $name ] ) || isset( $kept[ $name ] ) || self::ours( $name ) ) {
				continue;
			}
			if ( '' === $site ? ! isset( $core[ $name ] ) : 0 !== strncmp( $name, $site, strlen( $site ) ) ) {
				continue; // Not known to be this site's: left where it is.
			}
			$out[] = array(
				'kind'     => SwapPlan::MOVE,
				'live'     => $name,
				'stage'    => '',
				'old'      => TempTables::old( $job->storage_token, $job->id, $run['random'], self::without( $name, $site ) ),
				'had_live' => true,
			);
		}
		return $out;
	}

	/**
	 * The live tables the live phase listed: name => true.
	 *
	 * @param string $work Work directory.
	 * @return array<string, bool>
	 * @throws WorkLost When the list is gone.
	 */
	private function live_tables( string $work ): array {
		$path = RestoreFiles::path( $work, RestoreFiles::SWAP_LIVE );
		$text = @file_get_contents( $path ); // A table name per line: small next to the tables themselves.
		if ( ! is_string( $text ) ) {
			throw new WorkLost( 'The list of the site\'s tables is gone from the work directory.' );
		}
		$out = array();
		foreach ( explode( "\n", $text ) as $line ) {
			if ( '' === $line ) {
				continue;
			}
			$name = json_decode( $line, true );
			if ( ! is_string( $name ) ) {
				throw new WorkLost( 'The list of the site\'s tables in the work directory is damaged.' );
			}
			$out[ $name ] = true;
		}
		return $out;
	}

	/**
	 * Whether a table is one the swap never moves: the plugin's own (jobs, swap plan) and its temporary and old
	 * tables.
	 *
	 * @param string $name Table name.
	 * @return bool
	 */
	private static function ours( string $name ): bool {
		$base = self::base_prefix();
		return $base . Schema::JOBS_TABLE === $name || $base . SwapPlan::TABLE === $name || 0 === strncmp( $name, TempTables::PREFIX, strlen( TempTables::PREFIX ) ) || 0 === strncmp( $name, TempTables::OLD_PREFIX, strlen( TempTables::OLD_PREFIX ) );
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
	 * WordPress's own table names for this site with an empty prefix: name => true.
	 *
	 * @return array<string, bool>
	 */
	private static function core_tables(): array {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->tables( 'all', false ) as $name ) {
			$out[ (string) $name ] = true;
		}
		return $out;
	}

	/**
	 * A name without a prefix it starts with.
	 *
	 * @param string $name   Name.
	 * @param string $prefix Prefix.
	 * @return string
	 */
	private static function without( string $name, string $prefix ): string {
		return '' !== $prefix && 0 === strncmp( $name, $prefix, strlen( $prefix ) ) && strlen( $name ) > strlen( $prefix ) ? (string) substr( $name, strlen( $prefix ) ) : $name;
	}

	/**
	 * The running plugin's files as the staging step listed them.
	 *
	 * @param string $work Work directory.
	 * @return array<int, array{r: string, b: int}>
	 * @throws WorkLost When the list is gone.
	 */
	private function plugin_lines( string $work ): array {
		$text = @file_get_contents( RestoreFiles::path( $work, RestoreFiles::PLUGIN_LIST ) ); // Bounded by MAX_PLUGIN_ENTRIES lines.
		if ( ! is_string( $text ) ) {
			throw new WorkLost( 'The list of this plugin\'s files is gone from the work directory.' );
		}
		$out = array();
		foreach ( explode( "\n", $text ) as $line ) {
			if ( '' === $line ) {
				continue;
			}
			$item = json_decode( $line, true );
			if ( ! is_array( $item ) || ! isset( $item['r'], $item['b'] ) || ! is_string( $item['r'] ) ) {
				throw new WorkLost( 'The list of this plugin\'s files in the work directory is damaged.' );
			}
			$out[] = array(
				'r' => $item['r'],
				'b' => (int) $item['b'],
			);
		}
		return $out;
	}

	/**
	 * The files under a directory (not links), as relative paths in sorted order; null when there are more than
	 * $limit entries (not the plugin as staged).
	 *
	 * @param string $root  Directory.
	 * @param int    $limit Most entries.
	 * @return string[]|null
	 * @throws TransientFailure When a directory cannot be read.
	 */
	private static function files_under( string $root, int $limit ): ?array {
		$out  = array();
		$seen = 0;
		$dirs = array( '' );
		while ( array() !== $dirs ) {
			$dir     = (string) array_shift( $dirs );
			$entries = @scandir( '' === $dir ? $root : $root . '/' . $dir );
			if ( ! is_array( $entries ) ) {
				throw new TransientFailure( 'A directory of WP Checkpoint cannot be listed.' );
			}
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				if ( ++$seen > $limit ) {
					return null;
				}
				$relative = '' === $dir ? $entry : $dir . '/' . $entry;
				$path     = $root . '/' . $relative;
				if ( is_link( $path ) ) {
					continue;
				}
				if ( is_dir( $path ) ) {
					$dirs[] = $relative;
				} elseif ( is_file( $path ) ) {
					$out[] = $relative;
				}
			}
		}
		sort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * The files walk of the restore.
	 *
	 * @param string               $work Work directory.
	 * @param array<string, mixed> $run  This run.
	 * @return ChunkWalk
	 */
	private function walk( string $work, array $run ): ChunkWalk {
		return new ChunkWalk( RestoreVerifyStep::index_path( $work, RestorePreflightStep::manifest( $work )->files_index() ), $run['loaded']['volumes'], $run['loaded']['chunk_bytes'], ChunkWalk::FILES );
	}

	/**
	 * The running plugin's directory.
	 *
	 * @return string
	 */
	private function plugin_dir(): string {
		return rtrim( (string) ( $this->parts['plugin_dir'] ?? WPCHECKPOINT_DIR ), '/\\' );
	}

	/**
	 * Whether a file is there to read: true when it is; false when it is there but could not be read (or whether
	 * it is there cannot be told), for the caller to retry; and when its directory lists without it, the job
	 * ends with $gone (that is evidence).
	 *
	 * @param string $path Path.
	 * @param string $gone Why the job ends when it is gone.
	 * @return bool
	 * @throws WorkLost When it is gone.
	 */
	private static function readable( string $path, string $gone ): bool {
		$stat = self::lstat( $path );
		if ( null === $stat ) {
			if ( Paths::positively_gone( $path ) ) {
				throw new WorkLost( $gone );
			}
			return false;
		}
		if ( 0100000 !== ( $stat['mode'] & 0170000 ) ) {
			throw new WorkLost( $gone ); // Not a file any more (a directory, a link): that is evidence too.
		}
		return is_readable( $path );
	}

	/**
	 * A path's lstat(), or null when it is not there.
	 *
	 * @param string $path Path.
	 * @return array<int|string, int>|null
	 */
	private static function lstat( string $path ) {
		clearstatcache( true, $path );
		$stat = @lstat( $path );
		return false === $stat ? null : $stat;
	}

	/**
	 * Write bytes at a committed length of a work file (created when missing).
	 *
	 * @param string $path   Path.
	 * @param int    $length Committed length.
	 * @param string $bytes  Bytes.
	 * @return void
	 * @throws WorkLost When the file is shorter than its committed length.
	 * @throws TransientFailure When it cannot be written.
	 */
	private static function append( string $path, int $length, string $bytes ): void {
		$handle = @fopen( $path, 'cb' );
		if ( false === $handle ) {
			throw new TransientFailure( 'A work file of the restore could not be written.' );
		}
		try {
			$stat = fstat( $handle );
			if ( false === $stat || (int) $stat['size'] < $length ) {
				throw new WorkLost( 'A work file of the restore is shorter than recorded; the work directory was changed.' );
			}
			if ( ! ftruncate( $handle, $length ) || 0 !== fseek( $handle, $length ) || ( '' !== $bytes && strlen( $bytes ) !== fwrite( $handle, $bytes ) ) || ! fflush( $handle ) ) {
				throw new TransientFailure( 'A work file of the restore could not be written.' );
			}
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Cut a work file back to its committed length (nothing when it is missing and the length is 0).
	 *
	 * @param string $path   Path.
	 * @param int    $length Committed length.
	 * @return void
	 * @throws WorkLost When it is shorter.
	 */
	private static function truncate_to( string $path, int $length ): void {
		clearstatcache( true, $path );
		if ( 0 === $length && ! is_file( $path ) ) {
			return;
		}
		self::append( $path, $length, '' );
	}

	/**
	 * The JSON line at an offset of a work file, and the offset after it; null at its end.
	 *
	 * @param string $path   Path.
	 * @param int    $offset Offset.
	 * @return array{value: array<string, mixed>, next: int}|null
	 * @throws WorkLost When the line is not one this code wrote.
	 */
	private static function line_at( string $path, int $offset ): ?array {
		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			throw new WorkLost( 'A work file of the restore is gone; the work directory was changed.' );
		}
		try {
			if ( 0 !== fseek( $handle, $offset ) ) {
				throw new WorkLost( 'A work file of the restore is shorter than recorded; the work directory was changed.' );
			}
			$line = fgets( $handle );
		} finally {
			fclose( $handle );
		}
		if ( false === $line || '' === $line ) {
			return null;
		}
		$value = json_decode( rtrim( $line, "\n" ), true );
		if ( ! is_array( $value ) || ! isset( $value['g'], $value['d'] ) || "\n" !== substr( $line, -1 ) ) {
			throw new WorkLost( 'A work file of the restore is damaged; the work directory was changed.' );
		}
		return array(
			'value' => $value,
			'next'  => $offset + strlen( $line ),
		);
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

	/**
	 * Nothing to release: the ledger's holder and the plan's rows are the job's, removed with it.
	 *
	 * @param JobContext $context Context.
	 * @return void
	 */
	public function cleanup( JobContext $context ): void {
		unset( $context );
	}
}
