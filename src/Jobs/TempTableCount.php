<?php
/**
 * Counting the rows of a restore's temporary tables against what the import recorded.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Database\SqlWriter;
use WPCheckpoint\Restore\Ledger;
use WPCheckpoint\Restore\JobFence;
use WPCheckpoint\Restore\StateCarry;
use WPCheckpoint\Restore\Queries;
use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Restore\TablePlan;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- job errors with table names; the presenter cleans them.

/**
 * The final check before the swap (SwapCheckStep) counts the temporary
 * tables, and the swap (SwapStep) counts those without transactions once
 * more before it changes anything: a unit at a time, each bounded ("rows"
 * rows of a table's key per unit; a table without such a key in one
 * statement, which no budget can cut), against the ledger's row count less
 * what the table prefix rewrite removed. A count that differs is evidence
 * that the table was changed after the import (WorkLost: FINAL).
 */
final class TempTableCount {

	/**
	 * Connection.
	 *
	 * @var Queries
	 */
	private $db;

	/**
	 * The restore's table plan.
	 *
	 * @var TablePlan
	 */
	private $plan;

	/**
	 * The restore's ledger.
	 *
	 * @var Ledger
	 */
	private $ledger;

	/**
	 * Work directory.
	 *
	 * @var string
	 */
	private $work;

	/**
	 * Rows of a key counted per unit.
	 *
	 * @var int
	 */
	private $rows;

	/**
	 * Crash seam (tests): function( string $point ): void, or null.
	 *
	 * @var callable|null
	 */
	private $seam;

	/**
	 * The tables whose counts leave the plugin's own rows out (StateCarry::counted()): final name => how
	 * StateCarry::own_names() reads the table.
	 *
	 * @var array<string, array{sitemeta: bool, site: string[]}>
	 */
	private $own;

	/**
	 * The plugin's own names found in a temporary table, by table, for this instance (a tick): the same names serve
	 * every range of the table's count.
	 *
	 * @var array<string, string[]>
	 */
	private $names = array();

	/**
	 * The job row the count's one write is conditioned on: table, id, token (JobFence); empty for none (then the own
	 * rows cannot be recorded, and a table that needs them is a transient failure).
	 *
	 * @var array{table: string, id: int, token: string}|array{}
	 */
	private $fence;

	/**
	 * Constructor.
	 *
	 * @param Queries                                              $db     Connection.
	 * @param TablePlan                                            $plan   The table plan.
	 * @param Ledger                                               $ledger The ledger.
	 * @param string                                               $work   Work directory.
	 * @param int                                                  $rows   Rows of a key counted per unit.
	 * @param callable|null                                        $seam   Crash seam (tests): called at "counted_whole" and "counted".
	 * @param array<string, array{sitemeta: bool, site: string[]}> $own    The tables counted without the plugin's own rows (StateCarry::counted()).
	 * @param array{table: string, id: int, token: string}|array{} $fence  The job row the record of the own rows is conditioned on.
	 */
	public function __construct( Queries $db, TablePlan $plan, Ledger $ledger, string $work, int $rows, $seam = null, array $own = array(), array $fence = array() ) {
		$this->db     = $db;
		$this->plan   = $plan;
		$this->ledger = $ledger;
		$this->work   = $work;
		$this->rows   = max( 1, $rows );
		$this->seam   = is_callable( $seam ) ? $seam : null;
		$this->own    = $own;
		$this->fence  = $fence;
	}

	/**
	 * A unit of counting: the next range of a table's key, or a table without a key in one statement, of the
	 * tables with transactions or of those without.
	 *
	 * @param array{i: int, key: array<int, string>|null, sum: int} $cursor Position: the table, the last key
	 *                                                                      counted to, the rows counted so far.
	 * @param bool                                                  $want   Whether the tables with transactions
	 *                                                                      are counted (else those without).
	 * @return array{position: array{i: int, key: array<int, string>|null, sum: int}, done: bool, unbounded: bool, what: string}
	 * @throws WorkLost When a table holds other rows than the import recorded.
	 * @throws TransientFailure When the ledger's record of the own rows, written by another run meanwhile, cannot be read back.
	 * @throws LockLost When the job is no longer this run's: the record was refused and nothing was written.
	 * @throws \LogicException When the counter was built without its fence and a table needs the own rows recorded.
	 */
	public function unit( array $cursor, bool $want ): array {
		$db     = $this->db;
		$tables = $this->plan->tables();
		$total  = count( $tables );
		$state  = null;
		while ( (int) $cursor['i'] < $total ) {
			$table = $tables[ (int) $cursor['i'] ];
			$state = $this->ledger->get( (int) $table['number'] );
			if ( null !== $state && $state['transactional'] === $want ) {
				break;
			}
			++$cursor['i'];
		}
		if ( (int) $cursor['i'] >= count( $tables ) ) {
			return array(
				'position'  => $cursor,
				'done'      => true,
				'unbounded' => false,
				'what'      => '',
			);
		}
		$table = $tables[ (int) $cursor['i'] ];
		$name  = SqlWriter::identifier( $table['temporary'] );
		$key   = self::key_columns( $db, $table['temporary'] );
		// Rows the table prefix rewrite copied are above the highest key at its plan: counted are the imported ones.
		$above  = self::rewrite( $this->work )['above'][ $table['temporary'] ] ?? null;
		$only   = null === $above ? '' : ' AND ' . SqlWriter::identifier( 'umeta_id' ) . ' <= ' . (int) $above;
		$params = array();
		// The plugin's own rows of the options and sitemeta tables are not counted (the swap's carry writes them,
		// whole or cut short): the same names the carry writes, from the same function. At the first count of such a
		// table (before any carry) how many of them the import wrote is recorded, and taken off what is expected.
		$owned = 0;
		if ( isset( $this->own[ $table['table'] ] ) && null !== $state ) {
			$how = $this->own[ $table['table'] ];
			if ( ! isset( $this->names[ $table['temporary'] ] ) ) {
				$this->names[ $table['temporary'] ] = StateCarry::own_names( $db, $table['temporary'], $how['sitemeta'], $how['site'] );
			}
			$names  = $this->names[ $table['temporary'] ];
			$column = $how['sitemeta'] ? 'meta_key' : 'option_name';
			$where  = array() === $how['site'] ? '' : 'site_id = ? AND ';
			$marks  = implode( ', ', array_fill( 0, count( $names ), '?' ) );
			$only  .= ' AND NOT (' . $where . $column . ' IN (' . $marks . '))';
			$params = array_merge( $how['site'], $names );
			$owned  = $state['owned'];
			if ( $owned < 0 ) {
				$owned = (int) ( $db->rows( 'SELECT COUNT(*) FROM ' . $name . ' WHERE ' . $where . $column . ' IN (' . $marks . ')', $params )[0][0] ?? 0 );
				$this->at( 'own_counted' );
				if ( array() === $this->fence ) {
					throw new \LogicException( 'The own rows of a table cannot be recorded outside a run: the counter was built without its fence.' );
				}
				if ( ! $this->ledger->set_owned( (int) $table['number'], $owned, $this->fence['table'], $this->fence['id'], $this->fence['token'] ) ) {
					// Not written: the job is no longer this run's (it stops here and writes nothing more), or the
					// record was made meanwhile by another run counting the same table while this one still held
					// the job (then what it recorded is what every count takes).
					if ( ! JobFence::holds( $db, $this->fence['table'], $this->fence['id'], $this->fence['token'] ) ) {
						throw new LockLost( sprintf( 'Job %d: the lock is no longer held; the count wrote nothing.', $this->fence['id'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
					}
					$state = $this->ledger->get( (int) $table['number'] );
					if ( null === $state || $state['owned'] < 0 ) {
						throw new TransientFailure( 'The restore ledger\'s record of the table being counted could not be read back; the count is tried again.' );
					}
					$owned = $state['owned'];
				}
			}
		}
		if ( array() === $key ) {
			$held = (int) ( $db->rows( 'SELECT COUNT(*) FROM ' . $name . ( '' === $only ? '' : ' WHERE 1 = 1' . $only ), $params )[0][0] ?? -1 );
			$this->at( 'counted_whole' );
			$this->counted( $table, $held, $owned );
			++$cursor['i'];
			return array(
				'position'  => $cursor,
				'done'      => false,
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
		$bound   = $db->rows( 'SELECT ' . implode( ', ', array_column( $key, 'select' ) ) . ' FROM ' . $name . $where . ' ORDER BY ' . $columns . ' LIMIT 1 OFFSET ' . ( $this->rows - 1 ), null === $after ? array() : $after );
		if ( array() === $bound ) {
			$last = (int) ( $db->rows( 'SELECT COUNT(*) FROM ' . $name . ( '' === $where ? ' WHERE 1 = 1' : $where ) . $only, array_merge( null === $after ? array() : $after, $params ) )[0][0] ?? -1 );
			$this->counted( $table, (int) $cursor['sum'] + $last, $owned );
			$cursor['i']   = (int) $cursor['i'] + 1;
			$cursor['key'] = null;
			$cursor['sum'] = 0;
		} else {
			$upper         = array_map( 'strval', $bound[0] );
			$range         = (int) ( $db->rows( 'SELECT COUNT(*) FROM ' . $name . ( null === $after ? ' WHERE ' : $where . ' AND ' ) . $tuple . ' <= ' . $marks . $only, array_merge( null === $after ? array() : $after, $upper, $params ) )[0][0] ?? -1 );
			$cursor['key'] = $upper;
			$cursor['sum'] = (int) $cursor['sum'] + $range;
		}
		return array(
			'position'  => $cursor,
			'done'      => false,
			'unbounded' => false,
			'what'      => '',
		);
	}

	/**
	 * A table's count against its record.
	 *
	 * @param array<string, mixed> $table The table.
	 * @param int                  $held  Rows counted (without the plugin's own rows, where they are left out).
	 * @param int                  $owned The plugin's own rows the import wrote (0 where they are counted).
	 * @return void
	 * @throws WorkLost When they differ.
	 */
	private function counted( array $table, int $held, int $owned = 0 ): void {
		$state = $this->ledger->get( (int) $table['number'] );
		// The import's rows, less what the prefix rewrite removed and the plugin's own rows left out of the count.
		$want = null === $state ? -1 : $state['rows'] - (int) ( self::rewrite( $this->work )['removed'][ $table['temporary'] ] ?? 0 ) - max( 0, $owned );
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
	public static function rewrite( string $work ): array {
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
	 * A crash seam (tests).
	 *
	 * @param string $point Point.
	 * @return void
	 */
	private function at( string $point ): void {
		if ( null !== $this->seam ) {
			call_user_func( $this->seam, $point );
		}
	}
}
