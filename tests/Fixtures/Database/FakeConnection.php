<?php
/**
 * An in-memory database for the exporter tests.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Tests\Fixtures\Database;

use WPCheckpoint\Database\Connection;

/**
 * Understands exactly the statements TableExporter issues: SHOW CREATE
 * TABLE, SHOW COLUMNS, SHOW INDEX, and SELECT * ... [WHERE after-key]
 * ORDER BY pk LIMIT n [OFFSET o]. The after-key condition is not parsed:
 * the last k arguments of the query are the key (the expanded form ends
 * with the full key), and rows are compared as tuples of strings, the
 * way MySQL orders them for string keys and numerically for numeric
 * keys (the test declares which).
 */
final class FakeConnection implements Connection {

	/**
	 * Tables: name => array{create, columns: [[name, type]], pk: string[], rows: array<array<string|null>>, numeric_pk: bool}.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $tables = array();

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
	 * @param array<int, array{0: string, 1: string}> $columns [name, type] pairs.
	 * @param string[]                               $pk      Primary key columns in order.
	 * @param array<int, array<int, string|null>>     $rows    Rows in column order.
	 */
	public function add_table( string $name, array $columns, array $pk, array $rows, bool $numeric_pk = true ): void {
		$this->tables[ $name ] = array(
			'create'     => "CREATE TABLE `{$name}` (\n  ...\n) ENGINE=InnoDB",
			'columns'    => $columns,
			'pk'         => $pk,
			'rows'       => $rows,
			'numeric_pk' => $numeric_pk,
		);
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
				$out[] = array( $c[0], $c[1], 'YES', '', null, '' );
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
		if ( 1 === preg_match( '/\ASELECT \* FROM `(.+?)`(?: WHERE .+?)?(?: ORDER BY .+?)? LIMIT (\d+)(?: OFFSET (\d+))?\z/', $sql, $m ) ) {
			$t     = $this->table( $m[1] );
			$limit = (int) $m[2];
			$rows  = $t['rows'];
			if ( array() === $t['pk'] ) {
				return array_slice( $rows, isset( $m[3] ) ? (int) $m[3] : 0, $limit );
			}
			$idx = array();
			foreach ( $t['pk'] as $col ) {
				foreach ( $t['columns'] as $i => $c ) {
					if ( $c[0] === $col ) {
						$idx[] = $i;
					}
				}
			}
			$numeric = $t['numeric_pk'];
			$key_of  = static function ( array $row ) use ( $idx ): array {
				$k = array();
				foreach ( $idx as $i ) {
					$k[] = (string) $row[ $i ];
				}
				return $k;
			};
			$cmp     = static function ( array $a, array $b ) use ( $numeric ): int {
				foreach ( $a as $i => $v ) {
					$c = $numeric ? ( (float) $v <=> (float) $b[ $i ] ) : strcmp( $v, $b[ $i ] );
					if ( 0 !== $c ) {
						return $c;
					}
				}
				return 0;
			};
			usort( $rows, static function ( array $a, array $b ) use ( $key_of, $cmp ): int {
				return $cmp( $key_of( $a ), $key_of( $b ) );
			} );
			if ( array() !== $args ) {
				$after = array_slice( $args, -count( $idx ) );
				$rows  = array_values( array_filter( $rows, static function ( array $row ) use ( $key_of, $cmp, $after ): bool {
					return $cmp( $key_of( $row ), $after ) > 0;
				} ) );
			}
			return array_slice( $rows, 0, $limit );
		}
		throw new \RuntimeException( 'FakeConnection does not understand: ' . $sql );
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
