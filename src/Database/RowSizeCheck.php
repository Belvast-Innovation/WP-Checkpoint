<?php
/**
 * Finds tables with rows the exporter would refuse, before the export starts.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Database;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages carry table names and numbers; the runner stores them through the redactor.

/**
 * One table per call, bounded. "Oversized" is TableExporter's own
 * predicate (oversize_predicate(), the SQL form of estimate_row_bytes()),
 * so the count here, the rows the export leaves out when asked to, and
 * the check after formatting agree row for row.
 *
 * Small tables (at most EXACT_MAX_BYTES of data and EXACT_MAX_ROWS rows by
 * the server's statistics) are counted exactly with one query. Larger
 * tables are sampled: with a numeric single-column primary key, SAMPLES
 * windows of SAMPLE_ROWS rows at evenly spaced keys (each window an index
 * range, bounded); otherwise only the first window, because a deep
 * OFFSET reads and discards every row before it. A sampled table's
 * verdict is "may have" (likely = true, count = null), never "has none":
 * with the average row length as a second signal, the user is asked and
 * can choose to leave such rows out, which costs nothing when there are
 * none.
 */
final class RowSizeCheck {

	const EXACT_MAX_BYTES = 268435456;
	const EXACT_MAX_ROWS  = 200000;
	const SAMPLES         = 8;
	const SAMPLE_ROWS     = 5000;

	/**
	 * Exporter (for describe(), the predicate and the limit).
	 *
	 * @var TableExporter
	 */
	private $exporter;

	/**
	 * Database.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Constructor.
	 *
	 * @param Connection    $connection Database.
	 * @param TableExporter $exporter   The exporter the export will use (same limit, same charset).
	 */
	public function __construct( Connection $connection, TableExporter $exporter ) {
		$this->connection = $connection;
		$this->exporter   = $exporter;
	}

	/**
	 * Check one table.
	 *
	 * @param string                                                $table Table name.
	 * @param array{rows: int, data_bytes: int, avg_row_bytes: int} $stats Server statistics (information_schema; estimates).
	 * @return array{table: string, exact: bool, likely: bool, count: int|null, limit: int}
	 * @throws \RuntimeException On query failure (via the exporter's classification).
	 */
	public function check( string $table, array $stats ): array {
		$desc      = $this->exporter->describe( $table );
		$predicate = $this->exporter->oversize_predicate( $desc );
		$id        = SqlWriter::identifier( $table );
		$limit     = $this->exporter->row_limit();
		$result    = array(
			'table'  => $table,
			'exact'  => false,
			'likely' => false,
			'count'  => null,
			'limit'  => $limit,
		);

		if ( $stats['data_bytes'] <= self::EXACT_MAX_BYTES && $stats['rows'] <= self::EXACT_MAX_ROWS ) {
			$count            = $this->count( 'SELECT COUNT(*) FROM ' . $id . ' WHERE ' . $predicate );
			$result['exact']  = true;
			$result['count']  = $count;
			$result['likely'] = $count > 0;
			return $result;
		}

		// A row twice the average would be over the limit: the statistics alone make it likely.
		if ( $stats['avg_row_bytes'] * 2 > $limit ) {
			$result['likely'] = true;
		}
		$columns = implode( ', ', array_map( array( SqlWriter::class, 'identifier' ), $desc['columns'] ) );
		$windows = array();
		if ( 1 === count( $desc['pk'] ) && 'numeric' === $desc['kinds'][ $desc['pk_index'][0] ] ) {
			$pk    = SqlWriter::identifier( $desc['pk'][0] );
			$range = $this->connection->rows( 'SELECT MIN(' . $pk . '), MAX(' . $pk . ') FROM ' . $id );
			if ( is_array( $range ) && isset( $range[0][0], $range[0][1] ) && is_numeric( $range[0][0] ) && is_numeric( $range[0][1] ) ) {
				$min = (float) $range[0][0];
				$max = (float) $range[0][1];
				for ( $i = 0; $i < self::SAMPLES; $i++ ) {
					$start     = $min + ( $max - $min ) * $i / max( 1, self::SAMPLES - 1 );
					$windows[] = array(
						'sql'  => 'SELECT COUNT(*) FROM (SELECT ' . $columns . ' FROM ' . $id . ' WHERE ' . $pk . ' >= ? ORDER BY ' . $pk . ' LIMIT ' . self::SAMPLE_ROWS . ') AS w WHERE ' . $predicate,
						'args' => array( (string) ( (int) floor( $start ) ) ),
					);
				}
			}
		}
		if ( array() === $windows ) {
			// String keys or no key: the first window only (a deep OFFSET is a scan).
			$order     = array() === $desc['pk'] ? '' : ' ORDER BY ' . implode( ', ', array_map( array( SqlWriter::class, 'identifier' ), $desc['pk'] ) );
			$windows[] = array(
				'sql'  => 'SELECT COUNT(*) FROM (SELECT ' . $columns . ' FROM ' . $id . $order . ' LIMIT ' . self::SAMPLE_ROWS . ') AS w WHERE ' . $predicate,
				'args' => array(),
			);
		}
		foreach ( $windows as $window ) {
			if ( $this->count( $window['sql'], $window['args'] ) > 0 ) {
				$result['likely'] = true;
				break;
			}
		}
		return $result;
	}

	/**
	 * A COUNT(*) query.
	 *
	 * @param string   $sql  SQL.
	 * @param string[] $args Placeholder values.
	 * @return int
	 * @throws \RuntimeException When the query fails.
	 */
	private function count( string $sql, array $args = array() ): int {
		$rows = $this->connection->rows( $sql, $args );
		if ( null === $rows ) {
			throw new \RuntimeException( sprintf( 'Database query failed (%d): %s', $this->connection->last_errno(), $this->connection->last_error() ) );
		}
		return isset( $rows[0][0] ) ? (int) $rows[0][0] : 0;
	}
}
