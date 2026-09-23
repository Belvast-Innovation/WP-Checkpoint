<?php
/**
 * An in-memory database for the exporter tests.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Tests\Fixtures\Database;

use WPCheckpoint\Database\Connection;

/**
 * Understands exactly the statements TableExporter, RowSizeCheck and
 * PreflightStep issue: SHOW CREATE TABLE, SHOW COLUMNS, SHOW INDEX,
 * SHOW FULL TABLES LIKE, the information_schema statistics query,
 * SELECT MIN/MAX of a key, SELECT COUNT(*) ... WHERE <oversize predicate>
 * (directly or over a window subquery), and SELECT <list> ... [WHERE [NOT
 * <predicate>] [AND] [after-key]] [ORDER BY pk] LIMIT n [OFFSET o], where
 * the list is column names and LENGTH(`col`) terms (or * for the
 * invisible-column test). The after-key condition is not parsed: the last
 * k arguments of the query are the key (the expanded form ends with the
 * full key), and rows are compared as tuples of strings, the way MySQL
 * orders them for string keys and numerically for numeric keys (the test
 * declares which). The oversize predicate is evaluated from its CASE
 * terms with PHP arithmetic (the real check against MySQL's arithmetic
 * is the integration test).
 */
final class FakeConnection implements Connection {

	/**
	 * Tables: name => array{create, columns: [[name, type, extra]], pk: string[], rows: array<array<string|null>>, numeric_pk: bool, invisible: string[]}.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $tables = array();

	/**
	 * Views (names) for SHOW FULL TABLES.
	 *
	 * @var string[]
	 */
	public $views = array();

	/**
	 * Queries issued.
	 *
	 * @var string[]
	 */
	public $log = array();

	/**
	 * Error to raise on the next query: [errno, message], or null.
	 *
	 * @var array{0: int, 1: string}|null
	 */
	public $fail_next = null;

	/** @var string */
	private $last_error = '';

	/** @var int */
	private $last_errno = 0;

	/** @var string */
	private $charset;

	public function __construct( string $charset = 'utf8mb4' ) {
		$this->charset = $charset;
	}

	/**
	 * @param array<int, array{0: string, 1: string, 2?: string}> $columns   [name, type, extra] triples (extra optional).
	 * @param string[]                                            $pk        Primary key columns in order.
	 * @param array<int, array<int, string|null>>                  $rows      Rows in column order (a generated column's value is what the server would compute).
	 * @param string[]                                            $invisible Columns SELECT * leaves out.
	 */
	public function add_table( string $name, array $columns, array $pk, array $rows, bool $numeric_pk = true, array $invisible = array() ): void {
		$this->tables[ $name ] = array(
			'create'     => "CREATE TABLE `{$name}` (\n  ...\n) ENGINE=InnoDB",
			'columns'    => $columns,
			'pk'         => $pk,
			'rows'       => $rows,
			'numeric_pk' => $numeric_pk,
			'invisible'  => $invisible,
		);
	}

	/**
	 * The site writes while the export runs: add rows.
	 *
	 * @param array<int, array<int, string|null>> $rows Rows in column order.
	 */
	public function insert_rows( string $name, array $rows ): void {
		$this->tables[ $name ]['rows'] = array_merge( $this->tables[ $name ]['rows'], $rows );
	}

	/**
	 * The site empties a table (and maybe refills it) while the export runs.
	 *
	 * @param array<int, array<int, string|null>> $rows New rows in column order.
	 */
	public function replace_rows( string $name, array $rows ): void {
		$this->tables[ $name ]['rows'] = $rows;
	}

	public function rows( string $sql, array $args = array() ) {
		$this->log[]      = $sql;
		$this->last_error = '';
		$this->last_errno = 0;
		if ( null !== $this->fail_next ) {
			list( $this->last_errno, $this->last_error ) = $this->fail_next;
			$this->fail_next                             = null;
			return null;
		}
		if ( 1 === preg_match( '/\ASHOW CREATE TABLE `(.+)`\z/', $sql, $m ) ) {
			$t = $this->table( $m[1] );
			return array( array( $m[1], $t['create'] ) );
		}
		if ( 1 === preg_match( '/\ASHOW COLUMNS FROM `(.+)`\z/', $sql, $m ) ) {
			$out = array();
			foreach ( $this->table( $m[1] )['columns'] as $c ) {
				$out[] = array( $c[0], $c[1], 'YES', '', null, isset( $c[2] ) ? $c[2] : '' );
			}
			return $out;
		}
		if ( 1 === preg_match( '/\ASHOW INDEX FROM `(.+)`\z/', $sql, $m ) ) {
			$out = array();
			foreach ( $this->table( $m[1] )['pk'] as $i => $col ) {
				$out[] = array( $m[1], '0', 'PRIMARY', (string) ( $i + 1 ), $col );
			}
			return $out;
		}
		if ( 'SHOW FULL TABLES LIKE ?' === $sql ) {
			$prefix = rtrim( (string) ( $args[0] ?? '' ), '%' );
			$out    = array();
			foreach ( array_keys( $this->tables ) as $name ) {
				if ( 0 === strpos( $name, $prefix ) ) {
					$out[] = array( $name, 'BASE TABLE' );
				}
			}
			foreach ( $this->views as $name ) {
				if ( 0 === strpos( $name, $prefix ) ) {
					$out[] = array( $name, 'VIEW' );
				}
			}
			return $out;
		}
		if ( 0 === strpos( $sql, 'SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, AVG_ROW_LENGTH FROM information_schema.TABLES' ) ) {
			$out = array();
			foreach ( $args as $name ) {
				if ( ! isset( $this->tables[ $name ] ) ) {
					continue;
				}
				$bytes = 0;
				foreach ( $this->tables[ $name ]['rows'] as $row ) {
					foreach ( $row as $value ) {
						$bytes += strlen( (string) $value );
					}
				}
				$count = count( $this->tables[ $name ]['rows'] );
				$out[] = array( $name, (string) $count, (string) $bytes, '0', (string) ( $count > 0 ? (int) ( $bytes / $count ) : 0 ) );
			}
			return $out;
		}
		if ( 1 === preg_match( '/\ASELECT MIN\(`(.+?)`\), MAX\(`\1`\) FROM `(.+?)`\z/', $sql, $m ) ) {
			$t   = $this->table( $m[2] );
			$at  = array_search( $m[1], array_column( $t['columns'], 0 ), true );
			$all = array_map( static function ( array $row ) use ( $at ) {
				return $row[ $at ];
			}, $t['rows'] );
			return array() === $all ? array( array( null, null ) ) : array( array( (string) min( $all ), (string) max( $all ) ) );
		}
		if ( 1 === preg_match( '/\ASELECT COUNT\(\*\) FROM \(SELECT (.+?) FROM `(.+?)`(?: WHERE `(.+?)` >= \?)?(?: ORDER BY .+?)? LIMIT (\d+)\) AS w WHERE (\(\(.+\) > \d+\))\z/', $sql, $m ) ) {
			$t     = $this->table( $m[2] );
			$names = array_column( $t['columns'], 0 );
			$rows  = $this->ordered( $t, $names );
			if ( '' !== $m[3] ) {
				$at   = array_search( $m[3], $names, true );
				$from = (float) $args[0];
				$rows = array_values( array_filter( $rows, static function ( array $row ) use ( $at, $from ): bool {
					return (float) $row[ $at ] >= $from;
				} ) );
			}
			$rows  = array_slice( $rows, 0, (int) $m[4] );
			$count = 0;
			foreach ( $rows as $row ) {
				if ( $this->oversized( $m[5], $names, $row ) ) {
					++$count;
				}
			}
			return array( array( (string) $count ) );
		}
		if ( 1 === preg_match( '/\ASELECT COUNT\(\*\) FROM `(.+?)` WHERE (\(\(.+\) > \d+\))\z/', $sql, $m ) ) {
			$t     = $this->table( $m[1] );
			$names = array_column( $t['columns'], 0 );
			$count = 0;
			foreach ( $t['rows'] as $row ) {
				if ( $this->oversized( $m[2], $names, $row ) ) {
					++$count;
				}
			}
			return array( array( (string) $count ) );
		}
		if ( 1 === preg_match( '/\ASELECT COUNT\(\*\) FROM `(.+?)`\z/', $sql, $m ) ) {
			return array( array( (string) count( $this->table( $m[1] )['rows'] ) ) );
		}
		if ( 1 === preg_match( '/\ASELECT (.+?) FROM `(.+?)` ORDER BY (.+ DESC) LIMIT 1\z/', $sql, $m ) ) {
			$t     = $this->table( $m[2] );
			$names = array_column( $t['columns'], 0 );
			$rows  = array_reverse( $this->ordered( $t, $names ) );
			return $this->project( $m[1], $names, $t['invisible'], array_slice( $rows, 0, 1 ) );
		}
		if ( 1 === preg_match( '/\ASELECT (.+?) FROM `(.+?)`(?: WHERE (.+?))?(?: ORDER BY .+?)? LIMIT (\d+)(?: OFFSET (\d+))?\z/', $sql, $m ) ) {
			$t     = $this->table( $m[2] );
			$where = isset( $m[3] ) ? $m[3] : '';
			$limit = (int) $m[4];
			$names = array_column( $t['columns'], 0 );
			$rows  = array() !== $t['pk'] ? $this->ordered( $t, $names ) : $t['rows'];
			if ( 1 === preg_match( '/NOT (\(\(.+?\) > \d+\))/', $where, $pm ) ) {
				$rows = array_values( array_filter( $rows, function ( array $row ) use ( $pm, $names ): bool {
					return ! $this->oversized( $pm[1], $names, $row );
				} ) );
			}
			if ( array() !== $t['pk'] ) {
				$idx = array();
				foreach ( $t['pk'] as $col ) {
					$idx[] = (int) array_search( $col, $names, true );
				}
				$n      = count( $idx );
				$key_of = static function ( array $row ) use ( $idx ): array {
					$k = array();
					foreach ( $idx as $i ) {
						$k[] = (string) $row[ $i ];
					}
					return $k;
				};
				$cmp = $this->comparator( $t['numeric_pk'] );
				// Arguments: the lower bound's comparison (n(n+1)/2 values, the key last), then the upper bound's (its key last).
				$lower = false !== strpos( $where, '> ?' ) ? array_slice( $args, intdiv( $n * ( $n + 1 ), 2 ) - $n, $n ) : null;
				$upper = false !== strpos( $where, '< ?' ) ? array_slice( $args, -$n ) : null;
				$rows  = array_values( array_filter( $rows, static function ( array $row ) use ( $key_of, $cmp, $lower, $upper ): bool {
					return ( null === $lower || $cmp( $key_of( $row ), $lower ) > 0 ) && ( null === $upper || $cmp( $key_of( $row ), $upper ) <= 0 );
				} ) );
			}
			$rows = array() !== $t['pk'] ? array_slice( $rows, 0, $limit ) : array_slice( $rows, isset( $m[5] ) ? (int) $m[5] : 0, $limit );
			return $this->project( $m[1], $names, $t['invisible'], $rows );
		}
		throw new \RuntimeException( 'FakeConnection does not understand: ' . $sql );
	}

	/**
	 * Rows in primary-key order.
	 *
	 * @param array<string, mixed> $t     Table.
	 * @param string[]             $names Column names.
	 * @return array<int, array<int, string|null>>
	 */
	private function ordered( array $t, array $names ): array {
		$rows = $t['rows'];
		if ( array() === $t['pk'] ) {
			return $rows;
		}
		$idx = array();
		foreach ( $t['pk'] as $col ) {
			$idx[] = (int) array_search( $col, $names, true );
		}
		$cmp = $this->comparator( $t['numeric_pk'] );
		usort( $rows, static function ( array $a, array $b ) use ( $idx, $cmp ): int {
			$ka = array();
			$kb = array();
			foreach ( $idx as $i ) {
				$ka[] = (string) $a[ $i ];
				$kb[] = (string) $b[ $i ];
			}
			return $cmp( $ka, $kb );
		} );
		return $rows;
	}

	/**
	 * Tuple comparison, numeric or byte-wise.
	 *
	 * @param bool $numeric Numeric keys.
	 * @return callable
	 */
	private function comparator( bool $numeric ): callable {
		return static function ( array $a, array $b ) use ( $numeric ): int {
			foreach ( $a as $i => $v ) {
				$c = $numeric ? ( (float) $v <=> (float) $b[ $i ] ) : strcmp( $v, $b[ $i ] );
				if ( 0 !== $c ) {
					return $c;
				}
			}
			return 0;
		};
	}

	/**
	 * Evaluate the exporter's oversize predicate for one row from its CASE terms.
	 *
	 * @param string                  $predicate Predicate SQL.
	 * @param string[]                $names     Column names.
	 * @param array<int, string|null> $row       Row.
	 * @return bool
	 */
	private function oversized( string $predicate, array $names, array $row ): bool {
		if ( 1 !== preg_match( '/\A\(\((\d+)(.*)\) > (\d+)\)\z/s', $predicate, $m ) ) {
			throw new \RuntimeException( 'FakeConnection does not understand the predicate: ' . $predicate );
		}
		$bytes = (int) $m[1];
		preg_match_all( '/CASE WHEN `(.+?)` IS NULL THEN (\d+) ELSE CEIL\(LENGTH\(`\1`\) \* (\d+) \/ (\d+)\) \+ (\d+) END/', $m[2], $terms, PREG_SET_ORDER );
		foreach ( $terms as $term ) {
			$at    = array_search( $term[1], $names, true );
			$value = false === $at ? null : $row[ $at ];
			$bytes += null === $value ? (int) $term[2] : intdiv( strlen( (string) $value ) * (int) $term[3] + (int) $term[4] - 1, (int) $term[4] ) + (int) $term[5];
		}
		return $bytes > (int) $m[3];
	}

	/**
	 * Apply a select list (* or `col` / LENGTH(`col`) terms) to rows.
	 *
	 * @param string                              $list      Select list.
	 * @param string[]                            $names     Column names.
	 * @param string[]                            $invisible Invisible columns.
	 * @param array<int, array<int, string|null>> $rows      Rows.
	 * @return array<int, array<int, string|null>>
	 */
	private function project( string $list, array $names, array $invisible, array $rows ): array {
		$terms = array();
		if ( '*' === $list ) {
			foreach ( $names as $name ) {
				if ( ! in_array( $name, $invisible, true ) ) {
					$terms[] = array( 'col', $name );
				}
			}
		} else {
			foreach ( explode( ', ', $list ) as $term ) {
				if ( 1 === preg_match( '/\ALENGTH\(`(.+)`\)\z/', $term, $mm ) ) {
					$terms[] = array( 'len', $mm[1] );
				} elseif ( 1 === preg_match( '/\A`(.+)`\z/', $term, $mm ) ) {
					$terms[] = array( 'col', $mm[1] );
				} else {
					throw new \RuntimeException( 'FakeConnection does not understand the select term: ' . $term );
				}
			}
		}
		$out = array();
		foreach ( $rows as $row ) {
			$projected = array();
			foreach ( $terms as $term ) {
				$at = array_search( $term[1], $names, true );
				if ( false === $at ) {
					throw new \RuntimeException( 'No such column in the fake table: ' . $term[1] );
				}
				$value       = $row[ $at ];
				$projected[] = 'len' === $term[0] ? ( null === $value ? null : (string) strlen( (string) $value ) ) : $value;
			}
			$out[] = $projected;
		}
		return $out;
	}

	private function table( string $name ): array {
		if ( ! isset( $this->tables[ $name ] ) ) {
			throw new \RuntimeException( 'No such fake table: ' . $name );
		}
		return $this->tables[ $name ];
	}

	public function last_error(): string {
		return $this->last_error;
	}

	public function last_errno(): int {
		return $this->last_errno;
	}

	public function charset(): string {
		return $this->charset;
	}

	public function server_info(): string {
		return '8.0.36-fake';
	}
}
