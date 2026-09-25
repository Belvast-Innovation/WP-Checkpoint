<?php
/**
 * Streams one table into SQL chunk files, one batch of rows per unit of work.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Database;

use WPCheckpoint\Archive\ChunkHasher;
use WPCheckpoint\Archive\IndexLine;
use WPCheckpoint\Archive\Limits;
use WPCheckpoint\Jobs\TableChanged;
use WPCheckpoint\Jobs\TransientFailure;
use WPCheckpoint\Jobs\WorkLost;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages carry table names, numbers and the driver's error text; the runner stores them through the redactor and the presenter cleans them before display.

/**
 * Pure PHP over a Connection. A table becomes database/{t}.{c}.sql files
 * of at most CHUNK_BYTES each; every chunk starts with a header comment
 * and a session preamble (SET NAMES, SQL_MODE, FOREIGN_KEY_CHECKS), chunk
 * 1 continues with DROP TABLE and the CREATE statement, every chunk ends
 * with an end comment, and every batch of rows is followed by a marker
 * comment carrying the last primary key of the batch. That marker is
 * the only resume point: the cursor never holds a key value (keys are
 * user data, and a key that happens to contain a site secret would trip
 * the cursor check), only the table, the chunk number and the number of
 * bytes of the current chunk that were committed at the last checkpoint.
 *
 * Resuming truncates the chunk to the committed length (a batch written
 * after the last checkpoint is simply redone) and reads the marker the
 * committed part ends with; a chunk with no marker yet starts after the
 * key in its header. The committed length only ever advances after a
 * whole batch and its marker are on disk, so the committed part of a
 * chunk always ends right after a marker line and a torn write lies
 * beyond it. Anything else (a last line that is not a marker while the
 * chunk holds markers, a marker that does not parse) is damage to the
 * work directory and fails the export rather than re-exporting rows.
 *
 * A batch is chosen by size before it is fetched: a first query reads
 * the keys and the byte lengths of the rows ahead, estimate_row_bytes()
 * turns them into an upper bound of what each row costs, and only as
 * many rows as fit TARGET_BATCH_BYTES (at least one) are fetched, so a
 * run of large rows after a run of small ones cannot be pulled into
 * memory whole. A row whose estimate exceeds MAX_ROW_BYTES is refused
 * before it is fetched, with the table, the position and the size; the
 * exact size is checked again after formatting. INSERT statements are
 * cut at about STATEMENT_BYTES (one row per statement when a row alone
 * is larger), and a chunk closes when the next batch would push it past
 * CHUNK_BYTES. Columns are listed explicitly (SELECT * would leave out
 * INVISIBLE columns and shift every value one column over); generated
 * columns are left out of both the SELECT and the INSERT. Tables with a
 * primary key are read in key order with an expanded comparison that
 * uses the index on every MySQL version; tables without one use
 * LIMIT/OFFSET, which is unstable while the table changes, and say so
 * in a warning.
 *
 * Each table has an upper bound, fixed when its export starts: the largest
 * primary key then (a keyless table: its row count then), written on the
 * second line of every chunk of the table ("-- wpcheckpoint bound
 * pk_max=..." or "rows_max=..."), never in the cursor (a key is user
 * data). Rows past it are not read, so a table that grows while it is
 * exported still ends: without the bound a table that gains a batch per
 * tick (the options table gains a row with each loopback token) was read
 * forever on a host that runs one unit per tick. The bound is decided
 * before any byte of the table is written and is committed with the first
 * chunk's header, so a resumed or replayed export reads it back instead
 * of querying again; a header that was not committed is cut off with
 * everything after it, and querying again is safe because nothing depends
 * on it yet. A new chunk copies it from the chunk before. A chunk without
 * the line (written before the bound existed, resumed after an update) is
 * exported without a bound, as it was begun.
 *
 * The export is not a snapshot: batches run across ticks and requests
 * while the site keeps writing. Each table holds the rows that were there
 * when its export started, as each batch read them (rows changed or
 * deleted during the export may appear either way); rows whose key sorts
 * after the bound are left out, which for an auto-increment key means rows
 * added later, but a new row whose (string) key sorts before the bound and
 * after the rows already read is still read. A table emptied during its
 * export ends early; one emptied and refilled (a cache rebuild) ends up
 * with part of the old rows and part of the new. Tables start at different
 * times and are not consistent with each other. The manifest records the
 * period; a consistent snapshot needs writes locked (a restore point).
 */
final class TableExporter {

	const CHUNK_BYTES        = Limits::CONTENT_CHUNK_BYTES; // A chunk is at most the manifest's chunk_bytes (IndexLine::database()), and the export's is this.
	const TARGET_BATCH_BYTES = 1048576;
	const STATEMENT_BYTES    = 1048576;
	const MIN_ROWS           = 50;
	const MAX_ROWS           = 5000;
	const INITIAL_ROWS       = 500;
	const SCAN_WINDOW        = 65536;
	const MAX_MARKER_BYTES   = 65536;

	/**
	 * The largest row, as SQL text, the exporter takes. Over $wpdb a row is
	 * held about five times over while it is fetched (the driver's result
	 * buffer, the packet, the field, the PHP string) and escaped, and one
	 * unit may add at most 32 MB; both test levels measure the largest
	 * legal row against that budget. Rows are not sliced (T033).
	 */
	const MAX_ROW_BYTES = 4194304;

	/**
	 * Small pieces of a batch are coalesced into writes of about this size; a value larger than this is written on its own.
	 */
	const WRITE_BYTES = 65536;

	/**
	 * The row size estimate, in one place for its PHP form
	 * (estimate_row_bytes()) and its SQL form (oversize_predicate()): a
	 * NULL costs ESTIMATE_NULL_BYTES, every other value its length times
	 * the ratio of its kind (11/10 for quoted text, 22/10 for hex output,
	 * which doubles the bytes), rounded up, plus ESTIMATE_COLUMN_BYTES for
	 * quotes and comma, and a row ESTIMATE_ROW_BYTES for its parentheses.
	 * The ratios are integers on both sides on purpose: MySQL multiplies
	 * by a decimal literal exactly while PHP would use a double, and
	 * CEIL(234560 * 1.1) is 258016 in one and 258017 in the other. The two
	 * forms must agree row for row; a test compares the set of rows each
	 * one calls oversized.
	 */
	const ESTIMATE_ROW_BYTES    = 2;
	const ESTIMATE_NULL_BYTES   = 5;
	const ESTIMATE_COLUMN_BYTES = 4;
	const ESTIMATE_TEXT_RATIO   = array( 11, 10 );
	const ESTIMATE_BINARY_RATIO = array( 22, 10 );

	const HEADER = '-- wpcheckpoint table=';
	const BOUND  = '-- wpcheckpoint bound ';
	const MARKER = '-- wpcheckpoint batch ';
	const END    = '-- wpcheckpoint end ';

	/**
	 * MySQL error numbers worth a retry: the server went away, a lock timed out, a deadlock.
	 */
	const TRANSIENT_ERRNOS = array( 1040, 1053, 1205, 1213, 2002, 2003, 2006, 2013 );

	/**
	 * Database.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Directory the chunk files go to (the "database" directory).
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Chunk size.
	 *
	 * @var int
	 */
	private $chunk_bytes;

	/**
	 * Batch target size.
	 *
	 * @var int
	 */
	private $target_batch;

	/**
	 * SQL formatting for this connection.
	 *
	 * @var SqlWriter
	 */
	private $writer;

	/**
	 * Connection charset for the SET NAMES preamble ('' when unknown or not a plain name).
	 *
	 * @var string
	 */
	private $charset;

	/**
	 * Table descriptions cached for this instance.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $described = array();

	/**
	 * A short fingerprint per described table of what its rows are written
	 * against: each exported column's name, full type and whether it takes
	 * NULL, in order, and the primary key. Not the CREATE TABLE text, whose
	 * AUTO_INCREMENT moves with every insert. An identifier, safe for the
	 * cursor.
	 *
	 * @var array<string, string>
	 */
	private $shapes = array();

	/**
	 * Tables whose oversized rows are left out (the user's decision at the
	 * pre-flight): rows that oversize_predicate() selects are skipped by
	 * both queries of a batch, so the export never meets them.
	 *
	 * @var string[]
	 */
	private $exclude_oversize;

	/**
	 * Constructor.
	 *
	 * @param Connection $connection       Database.
	 * @param string     $dir              Existing directory for the chunk files.
	 * @param int        $chunk_bytes      Chunk size (tests use smaller values).
	 * @param int        $target_batch     Batch target size; kept at or below a quarter of the chunk size so a batch always fits.
	 * @param string[]   $exclude_oversize Tables whose oversized rows are left out.
	 */
	public function __construct( Connection $connection, string $dir, int $chunk_bytes = self::CHUNK_BYTES, int $target_batch = self::TARGET_BATCH_BYTES, array $exclude_oversize = array() ) {
		$this->connection       = $connection;
		$this->dir              = rtrim( $dir, '/\\' );
		$this->chunk_bytes      = $chunk_bytes;
		$this->target_batch     = max( 1, min( $target_batch, intdiv( $chunk_bytes, 4 ) ) );
		$this->exclude_oversize = array_values( array_map( 'strval', $exclude_oversize ) );
		$this->writer           = new SqlWriter( $connection->charset() );
		$charset                = strtolower( $connection->charset() );
		$this->charset          = 1 === preg_match( '/\A[a-z0-9_]{1,32}\z/', $charset ) ? $charset : '';
	}

	/**
	 * Whether strings are written as hex on this connection.
	 *
	 * @return bool
	 */
	public function hex_all(): bool {
		return $this->writer->hex_all();
	}

	/**
	 * The largest row (as SQL) this exporter accepts: MAX_ROW_BYTES, or less with a small chunk size.
	 *
	 * @return int
	 */
	public function row_limit(): int {
		return min( self::MAX_ROW_BYTES, $this->chunk_bytes - 4096 );
	}

	/**
	 * The state a table export starts from.
	 *
	 * @param string $table Table name.
	 * @return array<string, mixed>
	 */
	public static function initial_state( string $table ): array {
		return array(
			'table'      => $table,
			'chunk'      => 1,
			'bytes'      => 0,
			'rows'       => 0,
			'chunks'     => 0,
			'total'      => 0,
			'batch_rows' => self::INITIAL_ROWS,
			'done'       => false,
			'closed'     => null,
			'warnings'   => array(),
			'shape'      => '', // The table's fingerprint, set with its first chunk ('' until then, and for an export begun before it existed).
		);
	}

	/**
	 * One unit: a batch of rows (opening or closing a chunk as needed).
	 * "closed" carries the chunk that was completed in this unit, if any,
	 * for the caller to record; it is cleared at the start of the next.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 * @throws TransientFailure When the database is temporarily unavailable.
	 * @throws \RuntimeException When the table cannot be exported (a row larger than the limit, a broken query, a damaged work directory).
	 * @throws TableChanged When the table's columns or key changed since its first chunk was written.
	 */
	public function step( array $state ): array {
		$state['closed'] = null;
		if ( ! empty( $state['done'] ) ) {
			return $state;
		}
		$table = (string) $state['table'];
		$desc  = $this->describe( $table );
		$path  = $this->chunk_path( $table, (int) $state['chunk'] );
		$shape = $this->shapes[ $table ];
		if ( 1 === (int) $state['chunk'] && 0 === (int) $state['bytes'] ) {
			// Fixed with the definition this unit writes into the first chunk; a replay of this unit writes both again.
			$state['shape'] = $shape;
		} elseif ( ! empty( $state['shape'] ) && $state['shape'] !== $shape ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
			throw new TableChanged( sprintf( 'The structure of table %s changed while it was being exported: its columns or its primary key are not the ones its first chunk was written with.', $table ) );
		}

		if ( 0 === (int) $state['bytes'] ) {
			if ( 1 === (int) $state['chunk'] ) {
				$key   = null;
				$bound = $this->bound_now( $table, $desc ); // Before any byte of the table: nothing depends on an earlier query.
			} else {
				$key   = $this->previous_end_key( $table, (int) $state['chunk'] );
				$bound = $this->bound_in( $this->chunk_path( $table, (int) $state['chunk'] - 1 ), $table, (int) $state['chunk'] - 1, $desc );
			}
			$this->write_new( $path, $this->header( $state, $desc, $key, $bound ) );
			$state['bytes'] = (int) filesize( $path );
			$this->note_mode( $state, $desc );
			$handle = $this->open_at( $path, (int) $state['bytes'] );
		} else {
			$bound  = $this->bound_in( $path, $table, (int) $state['chunk'], $desc );
			$handle = $this->open_at( $path, (int) $state['bytes'] );
			$key    = $this->resume_key( $handle, $table, (int) $state['chunk'], (int) $state['bytes'] );
		}

		try {
			$rows = $this->fetch( $table, $desc, $key, (int) $state['rows'], (int) $state['batch_rows'], $bound );
			if ( array() === $rows ) {
				$this->close_chunk( $handle, $path, $state, $desc, $key );
				$state['done'] = true;
				return $state;
			}
			list( $pieces, $length, $emitted, $last_key, $largest ) = $this->format_batch( $table, $desc, $rows, $key, (int) $state['rows'] );
			$rows = array();
			$end  = strlen( $this->end_line( $state, $desc, $last_key ) );
			if ( $largest['bytes'] > $this->row_limit() ) {
				// The estimate before the fetch let it through (text full of quotes or backslashes doubles when escaped): the exact size decides.
				throw new \RuntimeException( $this->too_large( $table, $largest['bytes'], $desc, $largest['key'], $largest['index'] ) );
			}
			if ( $length + $end > $this->chunk_bytes - 4096 ) {
				// Only reachable when the batch target is set close to the chunk size (tests): a batch of small rows does not fit either.
				throw new \RuntimeException( sprintf( 'Table %s produced a batch of %d bytes, larger than the archive chunk size of %d bytes.', $table, $length, $this->chunk_bytes ) );
			}
			if ( (int) $state['bytes'] + $length + $end > $this->chunk_bytes ) {
				// The chunk cannot take this batch: end it at the previous key and continue in a new one.
				$this->close_chunk( $handle, $path, $state, $desc, $key );
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- chunk file in the job's work directory.
				$path = $this->chunk_path( $table, (int) $state['chunk'] );
				$this->write_new( $path, $this->header( $state, $desc, $key, $bound ) );
				$state['bytes'] = (int) filesize( $path );
				$handle         = $this->open_at( $path, (int) $state['bytes'] );
			}
			foreach ( $pieces as $i => $piece ) {
				$this->append( $handle, $piece );
				unset( $pieces[ $i ] );
			}
			$state['bytes']     += $length;
			$state['rows']      += $emitted;
			$state['batch_rows'] = self::adapt( (int) $state['batch_rows'], $length, $emitted, $this->target_batch );
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
		return $state;
	}

	/**
	 * Path of a chunk file.
	 *
	 * @param string $table Table.
	 * @param int    $chunk Chunk number.
	 * @return string
	 */
	public function chunk_path( string $table, int $chunk ): string {
		return $this->dir . DIRECTORY_SEPARATOR . basename( IndexLine::database_path( $table, $chunk ) );
	}

	/**
	 * Describe a table: CREATE statement, the exportable columns with
	 * their kinds (generated columns are left out: they are computed on
	 * import and cannot be inserted), primary key columns.
	 *
	 * @param string $table Table.
	 * @return array{create: string, columns: string[], kinds: string[], pk: string[], pk_index: int[]}
	 * @throws TransientFailure|\RuntimeException On query failure.
	 */
	public function describe( string $table ): array {
		if ( isset( $this->described[ $table ] ) ) {
			return $this->described[ $table ];
		}
		$id      = SqlWriter::identifier( $table );
		$create  = $this->query( 'SHOW CREATE TABLE ' . $id );
		$columns = $this->query( 'SHOW COLUMNS FROM ' . $id );
		$indexes = $this->query( 'SHOW INDEX FROM ' . $id );
		if ( array() === $create || ! isset( $create[0][1] ) || array() === $columns ) {
			throw new \RuntimeException( sprintf( 'Table %s could not be described.', $table ) );
		}
		$names = array();
		$kinds = array();
		$shape = array();
		foreach ( $columns as $column ) {
			$extra = isset( $column[5] ) ? strtoupper( (string) $column[5] ) : '';
			if ( false !== strpos( $extra, 'GENERATED' ) ) {
				continue;
			}
			$names[] = (string) $column[0];
			$kinds[] = SqlWriter::kind( (string) $column[1] );
			$shape[] = array( (string) $column[0], (string) $column[1], isset( $column[2] ) ? (string) $column[2] : '' ); // Name, full type, NULL allowed.
		}
		if ( array() === $names ) {
			throw new \RuntimeException( sprintf( 'Table %s has no column that can be exported.', $table ) );
		}
		$primary = array();
		foreach ( $indexes as $index ) {
			if ( isset( $index[2], $index[3], $index[4] ) && 'PRIMARY' === (string) $index[2] ) {
				$primary[ (int) $index[3] ] = (string) $index[4];
			}
		}
		ksort( $primary );
		$pk       = array_values( $primary );
		$pk_index = array();
		foreach ( $pk as $column ) {
			$at = array_search( $column, $names, true );
			if ( false === $at ) {
				$pk       = array(); // A key on a column the listing lacks (or a generated one): treat as keyless rather than guess.
				$pk_index = array();
				break;
			}
			$pk_index[] = (int) $at;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- a hash input, never unserialized.
		$this->shapes[ $table ]    = substr( hash( 'sha256', serialize( array( $shape, $pk ) ) ), 0, 16 );
		$this->described[ $table ] = array(
			'create'   => (string) $create[0][1],
			'columns'  => $names,
			'kinds'    => $kinds,
			'pk'       => $pk,
			'pk_index' => $pk_index,
		);
		return $this->described[ $table ];
	}

	/**
	 * Run a query and classify a failure.
	 *
	 * @param string   $sql  SQL.
	 * @param string[] $args Placeholder values.
	 * @return array<int, array<int, string|null>>
	 * @throws TransientFailure When the error is worth a retry.
	 * @throws \RuntimeException Otherwise.
	 */
	private function query( string $sql, array $args = array() ): array {
		$rows = $this->connection->rows( $sql, $args );
		if ( null === $rows ) {
			$errno = $this->connection->last_errno();
			$text  = sprintf( 'Database query failed (%d): %s', $errno, $this->connection->last_error() );
			if ( in_array( $errno, self::TRANSIENT_ERRNOS, true ) ) {
				throw new TransientFailure( $text );
			}
			throw new \RuntimeException( $text );
		}
		return $rows;
	}

	/**
	 * Fetch the next batch after a key (or offset), sized before it is
	 * read: a first query returns the keys and the byte lengths of up to
	 * $limit rows ahead, and only the rows whose estimates fit the batch
	 * target (at least one) are fetched.
	 *
	 * @param string                                                                                   $table Table.
	 * @param array{create: string, columns: string[], kinds: string[], pk: string[], pk_index: int[]} $desc  Description.
	 * @param string[]|null                                                                            $key   Last key, null from the start.
	 * @param int                                                                                      $rows  Rows exported so far (offset mode).
	 * @param int                                                                                      $limit Rows to look ahead.
	 * @param array{mode: string, key?: string[]|null, rows?: int}                                     $bound The table's upper bound (bound_now(), or bound_in(): mode "none" for a chunk begun without one).
	 * @return array<int, array<int, string|null>>
	 * @throws \RuntimeException When a row ahead is larger than the limit.
	 */
	private function fetch( string $table, array $desc, $key, int $rows, int $limit, array $bound ): array {
		if ( 'pk' === $bound['mode'] && null === ( $bound['key'] ?? null ) ) {
			return array(); // Empty when its export started.
		}
		if ( 'rows' === $bound['mode'] ) {
			$limit = min( $limit, (int) $bound['rows'] - $rows );
			if ( $limit <= 0 ) {
				return array();
			}
		}
		$from       = ' FROM ' . SqlWriter::identifier( $table );
		$args       = array();
		$conditions = array();
		$offset     = '';
		if ( in_array( $table, $this->exclude_oversize, true ) ) {
			// Built from the columns as they are now, so a table changed since the pre-flight still gets the right rows left out.
			$conditions[] = 'NOT ' . $this->oversize_predicate( $desc );
		}
		if ( array() === $desc['pk'] ) {
			$offset = ' OFFSET ' . $rows;
		} elseif ( null !== $key ) {
			list( $condition, $args ) = self::after_key( $desc['pk'], $key );
			$conditions[]             = '(' . $condition . ')';
		}
		if ( 'pk' === $bound['mode'] && array() !== $desc['pk'] ) {
			list( $condition, $upper ) = self::up_to_key( $desc['pk'], (array) $bound['key'] );
			$conditions[]              = '(' . $condition . ')';
			$args                      = array_merge( $args, $upper );
		}
		$where = array() === $conditions ? '' : ' WHERE ' . implode( ' AND ', $conditions );
		if ( array() !== $desc['pk'] ) {
			$where .= ' ORDER BY ' . implode( ', ', array_map( array( SqlWriter::class, 'identifier' ), $desc['pk'] ) );
		}
		$lengths = array_map( array( SqlWriter::class, 'identifier' ), $desc['pk'] );
		foreach ( $desc['columns'] as $column ) {
			$lengths[] = 'LENGTH(' . SqlWriter::identifier( $column ) . ')';
		}
		$sizes = $this->query( 'SELECT ' . implode( ', ', $lengths ) . $from . $where . ' LIMIT ' . $limit . $offset, $args );
		if ( array() === $sizes ) {
			return array();
		}
		$count = 0;
		$total = 0;
		$keys  = count( $desc['pk'] );
		foreach ( $sizes as $i => $size ) {
			$estimate = self::estimate_row_bytes( array_slice( $size, $keys ), $desc['kinds'], $this->hex_all() );
			if ( $estimate > $this->row_limit() ) {
				$row_key = array() === $desc['pk'] ? null : array_map( 'strval', array_slice( $size, 0, $keys ) );
				throw new \RuntimeException( $this->too_large( $table, $estimate, $desc, $row_key, $rows + $i ) );
			}
			++$count;
			$total += $estimate;
			if ( $total >= $this->target_batch ) {
				break;
			}
		}
		$columns = implode( ', ', array_map( array( SqlWriter::class, 'identifier' ), $desc['columns'] ) );
		return $this->query( 'SELECT ' . $columns . $from . $where . ' LIMIT ' . $count . $offset, $args );
	}

	/**
	 * An upper bound of the memory a row costs and of its size as SQL for
	 * ordinary values, from the byte lengths LENGTH() reports. Binary
	 * columns (and every string on a connection that writes hex) become
	 * X'..', twice the bytes plus three; other values are quoted, with
	 * room for escaping. Text made entirely of quotes or backslashes
	 * doubles when escaped and can exceed this bound as SQL; the exact
	 * size is checked after formatting, and memory stays bounded because
	 * the raw bytes never exceed the estimate. The pre-flight (T032) must
	 * use this same function so its verdict and the export's agree.
	 *
	 * @param array<int, string|int|null> $lengths LENGTH() per exportable column, null for NULL.
	 * @param string[]                    $kinds   Column kinds in the same order.
	 * @param bool                        $hex_all Whether every string is written as hex.
	 * @return int
	 */
	public static function estimate_row_bytes( array $lengths, array $kinds, bool $hex_all ): int {
		$bytes = self::ESTIMATE_ROW_BYTES;
		foreach ( array_values( $lengths ) as $i => $length ) {
			if ( null === $length ) {
				$bytes += self::ESTIMATE_NULL_BYTES;
				continue;
			}
			$kind   = isset( $kinds[ $i ] ) ? $kinds[ $i ] : 'text';
			$ratio  = self::estimate_ratio( $kind, $hex_all );
			$bytes += intdiv( (int) $length * $ratio[0] + $ratio[1] - 1, $ratio[1] ) + self::ESTIMATE_COLUMN_BYTES;
		}
		return $bytes;
	}

	/**
	 * The ratio (numerator, denominator) a value's bytes are multiplied by
	 * in the estimate, rounded up.
	 *
	 * @param string $kind    Column kind.
	 * @param bool   $hex_all Whether every string is written as hex.
	 * @return array{0: int, 1: int}
	 */
	public static function estimate_ratio( string $kind, bool $hex_all ): array {
		return 'binary' === $kind || ( $hex_all && 'numeric' !== $kind ) ? self::ESTIMATE_BINARY_RATIO : self::ESTIMATE_TEXT_RATIO;
	}

	/**
	 * The estimate (estimate_row_bytes()) as a SQL condition, true for the rows the
	 * exporter would refuse: the same sum, term by term (CEIL(LENGTH * f) +
	 * column bytes, NULL bytes for NULL, row bytes once), over the columns
	 * the table has now, compared with row_limit(). Used with NOT to leave
	 * oversized rows out, and by the pre-flight to count them: one
	 * definition of "oversized" for the count, the exclusion and the check
	 * after formatting.
	 *
	 * @param array{create: string, columns: string[], kinds: string[], pk: string[], pk_index: int[]} $desc Description from describe().
	 * @return string A parenthesised condition without placeholders.
	 */
	public function oversize_predicate( array $desc ): string {
		$terms = array( (string) self::ESTIMATE_ROW_BYTES );
		foreach ( $desc['columns'] as $i => $column ) {
			$id      = SqlWriter::identifier( $column );
			$ratio   = self::estimate_ratio( isset( $desc['kinds'][ $i ] ) ? $desc['kinds'][ $i ] : 'text', $this->hex_all() );
			$terms[] = sprintf( 'CASE WHEN %1$s IS NULL THEN %2$d ELSE CEIL(LENGTH(%1$s) * %3$d / %4$d) + %5$d END', $id, self::ESTIMATE_NULL_BYTES, $ratio[0], $ratio[1], self::ESTIMATE_COLUMN_BYTES );
		}
		return '((' . implode( ' + ', $terms ) . ') > ' . $this->row_limit() . ')';
	}

	/**
	 * The expanded "greater than this tuple" condition: (a > ?) OR (a = ?
	 * AND b > ?) OR (a = ? AND b = ? AND c > ?). A row-value comparison
	 * (a, b) > (?, ?) is not range-optimised before MySQL 8.0.14 and
	 * would scan the table on every batch.
	 *
	 * @param string[] $pk  Key columns.
	 * @param string[] $key Values.
	 * @return array{0: string, 1: string[]}
	 */
	public static function after_key( array $pk, array $key ): array {
		$parts = array();
		$args  = array();
		$count = count( $pk );
		for ( $i = 0; $i < $count; $i++ ) {
			$terms = array();
			for ( $j = 0; $j < $i; $j++ ) {
				$terms[] = SqlWriter::identifier( $pk[ $j ] ) . ' = ?';
				$args[]  = (string) $key[ $j ];
			}
			$terms[] = SqlWriter::identifier( $pk[ $i ] ) . ' > ?';
			$args[]  = (string) $key[ $i ];
			$parts[] = '(' . implode( ' AND ', $terms ) . ')';
		}
		return array( implode( ' OR ', $parts ), $args );
	}

	/**
	 * The expanded "at or before" comparison for the upper bound, in the
	 * same range-friendly form as after_key(): (a < ?) OR (a = ? AND b < ?)
	 * OR (a = ? AND b = ?).
	 *
	 * @param string[] $pk  Key columns.
	 * @param string[] $key Values.
	 * @return array{0: string, 1: string[]}
	 */
	public static function up_to_key( array $pk, array $key ): array {
		$parts = array();
		$args  = array();
		$count = count( $pk );
		for ( $i = 0; $i <= $count; $i++ ) {
			$terms = array();
			for ( $j = 0; $j < $i; $j++ ) {
				$terms[] = SqlWriter::identifier( $pk[ $j ] ) . ' = ?';
				$args[]  = (string) $key[ $j ];
			}
			if ( $i < $count ) {
				$terms[] = SqlWriter::identifier( $pk[ $i ] ) . ' < ?';
				$args[]  = (string) $key[ $i ];
			}
			$parts[] = '(' . implode( ' AND ', $terms ) . ')';
		}
		return array( implode( ' OR ', $parts ), $args );
	}

	/**
	 * The table's upper bound as it is now: its largest primary key (null
	 * when it is empty), or its row count when it has no key.
	 *
	 * @param string                                                                                   $table Table.
	 * @param array{create: string, columns: string[], kinds: string[], pk: string[], pk_index: int[]} $desc  Description.
	 * @return array{mode: string, key?: string[]|null, rows?: int}
	 * @throws TransientFailure|\RuntimeException On query failure.
	 */
	private function bound_now( string $table, array $desc ): array {
		if ( array() === $desc['pk'] ) {
			// Counted as the rows are read: without the rows left out as oversized. A full scan on a keyless
			// table, like the table's last OFFSET batches; WordPress's own tables all have a key.
			$where = in_array( $table, $this->exclude_oversize, true ) ? ' WHERE NOT ' . $this->oversize_predicate( $desc ) : '';
			$count = $this->query( 'SELECT COUNT(*) FROM ' . SqlWriter::identifier( $table ) . $where );
			return array(
				'mode' => 'rows',
				'rows' => (int) ( $count[0][0] ?? 0 ),
			);
		}
		$columns = array_map( array( SqlWriter::class, 'identifier' ), $desc['pk'] );
		$last    = $this->query(
			'SELECT ' . implode( ', ', $columns ) . ' FROM ' . SqlWriter::identifier( $table ) . ' ORDER BY ' . implode(
				', ',
				array_map(
					static function ( string $column ): string {
						return $column . ' DESC';
					},
					$columns
				)
			) . ' LIMIT 1'
		);
		return array(
			'mode' => 'pk',
			'key'  => array() === $last ? null : array_map( 'strval', $last[0] ),
		);
	}

	/**
	 * The bound a chunk carries on its second line; mode "none" for a chunk
	 * written before bounds existed. A second line that starts like a bound
	 * but does not parse is damage.
	 *
	 * @param string                                                                                   $path  Chunk path.
	 * @param string                                                                                   $table Table.
	 * @param int                                                                                      $chunk Chunk number.
	 * @param array{create: string, columns: string[], kinds: string[], pk: string[], pk_index: int[]} $desc  Description.
	 * @return array{mode: string, key?: string[]|null, rows?: int}
	 * @throws \RuntimeException When the chunk cannot be read or its bound line is malformed.
	 * @throws WorkLost When the work directory was lost, changed or damaged.
	 * @throws TableChanged When the table's key is not the one the chunk was written for, or it gained or lost one.
	 */
	private function bound_in( string $path, string $table, int $chunk, array $desc ): array {
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- see write_new().
		if ( false === $handle ) {
			throw WorkLost::or_unreadable( $path, 'A chunk file is missing; the work directory was lost or changed.', 'A chunk file could not be opened.' );
		}
		try {
			$stat   = fstat( $handle );
			$first  = fgets( $handle, self::MAX_MARKER_BYTES );
			$second = fgets( $handle, self::MAX_MARKER_BYTES );
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
		if ( false === $first && ( ! is_array( $stat ) || $stat['size'] > 0 ) ) {
			throw new \RuntimeException( 'A chunk file could not be read.' ); // Bytes there, none read: a storage error, not damage.
		}
		if ( ! is_string( $first ) || "\n" !== substr( $first, -1 ) || 0 !== strpos( $first, self::HEADER . $table . ' chunk=' . $chunk . ' ' ) ) {
			throw new WorkLost( sprintf( 'Chunk %d of table %s has a malformed header; the work directory was lost or changed.', $chunk, $table ) );
		}
		if ( ! is_string( $second ) || 0 !== strpos( $second, self::BOUND ) ) {
			return array( 'mode' => 'none' );
		}
		$line = rtrim( $second, "\n" );
		if ( array() === $desc['pk'] && 1 === preg_match( '/\A' . preg_quote( self::BOUND, '/' ) . 'rows_max=(\d+)\z/', $line, $m ) ) {
			return array(
				'mode' => 'rows',
				'rows' => (int) $m[1],
			);
		}
		if ( array() !== $desc['pk'] && 1 === preg_match( '/\A' . preg_quote( self::BOUND, '/' ) . 'pk_max=(.+)\z/', $line, $m ) ) {
			$key = self::decode_key( $m[1] );
			if ( null !== $key && count( $key ) !== count( $desc['pk'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
				throw new TableChanged( sprintf( 'The structure of table %s changed while it was being exported: its key is not the one its chunk %d was written for.', $table, $chunk ) );
			}
			return array(
				'mode' => 'pk',
				'key'  => $key,
			);
		}
		if ( 1 === preg_match( '/\A' . preg_quote( self::BOUND, '/' ) . '(?:rows_max=\d+|pk_max=.+)\z/', $line ) ) {
			// A well-formed bound of the other kind: the table gained or lost its primary key since the chunk was written.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
			throw new TableChanged( sprintf( 'The structure of table %s changed while it was being exported: its primary key was added or removed after its chunk %d was written.', $table, $chunk ) );
		}
		throw new WorkLost( sprintf( 'Chunk %d of table %s has a malformed bound line; the work directory was changed or damaged.', $chunk, $table ) );
	}

	/**
	 * Rows to statements plus the batch marker, as a list of pieces to be
	 * written in order (one piece per large value, small texts gathered
	 * into WRITE_BYTES pieces), so a large row is held once, not
	 * concatenated into a copy. Emits rows until the target batch size is
	 * reached; rows beyond that are left for the next batch (they are
	 * fetched again). Rows are released as they are formatted.
	 *
	 * @param string                                                                                   $table Table.
	 * @param array{create: string, columns: string[], kinds: string[], pk: string[], pk_index: int[]} $desc  Description.
	 * @param array<int, array<int, string|null>>                                                      $rows  Fetched rows.
	 * @param string[]|null                                                                            $key   Key before the batch.
	 * @param int                                                                                      $done  Rows exported before the batch.
	 * @return array{0: string[], 1: int, 2: int, 3: string[]|null, 4: array{bytes: int, key: string[]|null, index: int}} Pieces, their total length, rows emitted, last key, largest row (its size, its key, its position).
	 */
	private function format_batch( string $table, array $desc, array &$rows, $key, int $done ): array {
		$head      = SqlWriter::insert_head( $table, $desc['columns'] );
		$pieces    = array();
		$buffer    = '';
		$length    = 0;
		$statement = 0; // Bytes of the open statement, 0 when none is open.
		$emitted   = 0;
		$last      = $key;
		$largest   = array(
			'bytes' => 0,
			'key'   => null,
			'index' => $done,
		);
		foreach ( $rows as $i => $row ) {
			$row_key = null;
			if ( array() !== $desc['pk_index'] ) {
				$row_key = array();
				foreach ( $desc['pk_index'] as $at ) {
					$row_key[] = (string) $row[ $at ];
				}
			}
			$values = $this->writer->values( $row, $desc['kinds'] );
			unset( $rows[ $i ], $row );
			$size = count( $values ) + 1; // Parentheses and commas.
			foreach ( $values as $value ) {
				$size += strlen( $value );
			}
			if ( $size > $largest['bytes'] ) {
				$largest = array(
					'bytes' => $size,
					'key'   => $row_key,
					'index' => $done + $emitted,
				);
			}
			if ( $statement > 0 && $statement + $size + 3 > self::STATEMENT_BYTES ) {
				self::add( $pieces, $buffer, ";\n" );
				$length   += 2;
				$statement = 0;
			}
			if ( 0 === $statement ) {
				self::add( $pieces, $buffer, $head );
				$length   += strlen( $head );
				$statement = strlen( $head );
			} else {
				self::add( $pieces, $buffer, ',' );
				++$length;
				++$statement;
			}
			self::add( $pieces, $buffer, '(' );
			foreach ( $values as $n => $value ) {
				self::add( $pieces, $buffer, 0 === $n ? $value : ',' . $value );
				unset( $values[ $n ], $value );
			}
			self::add( $pieces, $buffer, ')' );
			$length    += $size;
			$statement += $size;
			++$emitted;
			if ( null !== $row_key ) {
				$last = $row_key;
			}
			if ( $length >= $this->target_batch ) {
				break;
			}
		}
		if ( $statement > 0 ) {
			self::add( $pieces, $buffer, ";\n" );
			$length += 2;
		}
		$marker = self::MARKER . 'rows=' . $emitted . ' ' . ( array() === $desc['pk'] ? 'offset=' . ( $done + $emitted ) : 'pk=' . self::encode_key( $last ) ) . "\n";
		self::add( $pieces, $buffer, $marker );
		$length += strlen( $marker );
		if ( '' !== $buffer ) {
			$pieces[] = $buffer;
		}
		return array( $pieces, $length, $emitted, $last, $largest );
	}

	/**
	 * Queue text for writing: small texts are gathered into WRITE_BYTES
	 * pieces, a large one becomes a piece of its own (never copied).
	 *
	 * @param string[] $pieces Pieces (updated).
	 * @param string   $buffer Gathered small texts (updated).
	 * @param string   $text   Text.
	 * @return void
	 */
	private static function add( array &$pieces, string &$buffer, string $text ): void {
		if ( strlen( $text ) >= self::WRITE_BYTES ) {
			if ( '' !== $buffer ) {
				$pieces[] = $buffer;
				$buffer   = '';
			}
			$pieces[] = $text;
			return;
		}
		$buffer .= $text;
		if ( strlen( $buffer ) >= self::WRITE_BYTES ) {
			$pieces[] = $buffer;
			$buffer   = '';
		}
	}

	/**
	 * How many rows to look ahead next, from the last batch: aim at the target bytes, within bounds.
	 *
	 * @param int $current Current batch size.
	 * @param int $bytes   Bytes of the last batch.
	 * @param int $rows    Rows of the last batch.
	 * @param int $target  Target bytes.
	 * @return int
	 */
	public static function adapt( int $current, int $bytes, int $rows, int $target ): int {
		if ( $rows <= 0 || $bytes <= 0 ) {
			return $current;
		}
		$per_row = max( 1, (int) ( $bytes / $rows ) );
		return max( self::MIN_ROWS, min( self::MAX_ROWS, (int) ( $target / $per_row ) ) );
	}

	/**
	 * The header of a chunk: the comment line, the session preamble and,
	 * for chunk 1, DROP and CREATE. The preamble is in every chunk because
	 * a chunk can be imported on its own; the settings are session-wide
	 * on the importing connection, like a mysqldump file's.
	 *
	 * @param array<string, mixed>                                                                     $state State.
	 * @param array{create: string, columns: string[], kinds: string[], pk: string[], pk_index: int[]} $desc  Description.
	 * @param string[]|null                                                                            $key   Key the chunk starts after.
	 * @param array{mode: string, key?: string[]|null, rows?: int}                                     $bound The table's upper bound.
	 * @return string
	 */
	private function header( array $state, array $desc, $key, array $bound ): string {
		$table = (string) $state['table'];
		$from  = array() === $desc['pk'] ? 'offset=' . (int) $state['rows'] : 'pk_from=' . self::encode_key( $key );
		$text  = self::HEADER . $table . ' chunk=' . (int) $state['chunk'] . ' ' . $from . "\n";
		if ( 'rows' === $bound['mode'] ) {
			$text .= self::BOUND . 'rows_max=' . (int) $bound['rows'] . "\n";
		} elseif ( 'pk' === $bound['mode'] ) {
			$text .= self::BOUND . 'pk_max=' . self::encode_key( $bound['key'] ?? null ) . "\n";
		}
		if ( '' !== $this->charset ) {
			$text .= '/*!40101 SET NAMES ' . $this->charset . " */;\n";
		}
		$text .= "/*!40101 SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n/*!40014 SET FOREIGN_KEY_CHECKS=0 */;\n";
		if ( 1 === (int) $state['chunk'] ) {
			$text .= 'DROP TABLE IF EXISTS ' . SqlWriter::identifier( $table ) . ";\n" . rtrim( $desc['create'], "; \n" ) . ";\n";
		}
		return $text;
	}

	/**
	 * The last line of a chunk.
	 *
	 * @param array<string, mixed>                                                                     $state State.
	 * @param array{create: string, columns: string[], kinds: string[], pk: string[], pk_index: int[]} $desc  Description.
	 * @param string[]|null                                                                            $key   Last key in the chunk.
	 * @return string
	 */
	private function end_line( array $state, array $desc, $key ): string {
		$to = array() === $desc['pk'] ? 'offset=' . (int) $state['rows'] : 'pk_to=' . self::encode_key( $key );
		return self::END . 'table=' . (string) $state['table'] . ' chunk=' . (int) $state['chunk'] . ' rows=' . (int) $state['rows'] . ' ' . $to . "\n";
	}

	/**
	 * Write the end line, hash the chunk, and move the state to the next chunk.
	 *
	 * @param resource                                                                                 $handle Open chunk.
	 * @param string                                                                                   $path   Chunk path.
	 * @param array<string, mixed>                                                                     $state  State (updated).
	 * @param array{create: string, columns: string[], kinds: string[], pk: string[], pk_index: int[]} $desc   Description.
	 * @param string[]|null                                                                            $key    Last key.
	 * @return void
	 * @throws TransientFailure When the end line cannot be written.
	 */
	private function close_chunk( $handle, string $path, array &$state, array $desc, $key ): void {
		$this->append( $handle, $this->end_line( $state, $desc, $key ) );
		if ( ! fflush( $handle ) ) {
			throw new TransientFailure( 'The chunk file could not be flushed.' );
		}
		clearstatcache( true, $path );
		$size            = (int) filesize( $path );
		$state['closed'] = array(
			'chunk' => (int) $state['chunk'],
			'bytes' => $size,
			'hash'  => ChunkHasher::hash_file( $path ),
		);
		$state['total'] += $size;
		++$state['chunks'];
		++$state['chunk'];
		$state['bytes'] = 0;
	}

	/**
	 * Create (or recreate) a chunk file with its header.
	 *
	 * @param string $path Path.
	 * @param string $text Header text.
	 * @return void
	 * @throws TransientFailure When the file cannot be written.
	 */
	private function write_new( string $path, string $text ): void {
		// Silenced: a warning would put the full path into the error log, bypassing the path masking.
		$handle = @fopen( $path, 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- see above.
		if ( false === $handle ) {
			throw new TransientFailure( 'A chunk file could not be created.' );
		}
		try {
			$this->append( $handle, $text );
			if ( ! fflush( $handle ) ) {
				throw new TransientFailure( 'The chunk file could not be flushed.' );
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
		clearstatcache( true, $path );
	}

	/**
	 * Open a chunk positioned at exactly $length bytes, cutting a longer file
	 * back (a batch written after the last checkpoint is redone).
	 *
	 * @param string $path   Path.
	 * @param int    $length Committed length.
	 * @return resource
	 * @throws TransientFailure When the file cannot be opened or truncated.
	 * @throws \RuntimeException When the file is shorter than the committed length.
	 * @throws WorkLost When the work directory was lost, changed or damaged.
	 */
	private function open_at( string $path, int $length ) {
		$handle = @fopen( $path, 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- see write_new().
		if ( false === $handle ) {
			throw new TransientFailure( 'A chunk file could not be opened.' );
		}
		$stat = fstat( $handle );
		$size = is_array( $stat ) ? (int) $stat['size'] : 0;
		if ( $size < $length ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
			throw new WorkLost( 'A chunk file is shorter than the last checkpoint recorded; the work directory was lost or changed.' );
		}
		if ( $size > $length && ! ftruncate( $handle, $length ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
			throw new TransientFailure( 'A chunk file could not be truncated.' );
		}
		if ( 0 !== fseek( $handle, $length ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
			throw new TransientFailure( 'A chunk file could not be positioned.' );
		}
		return $handle;
	}

	/**
	 * Append text in one write.
	 *
	 * @param resource $handle Open file.
	 * @param string   $text   Text.
	 * @return void
	 * @throws TransientFailure When the write is short.
	 */
	private function append( $handle, string $text ): void {
		$written = fwrite( $handle, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- chunk file in the job's work directory.
		if ( false === $written || strlen( $text ) !== $written ) {
			throw new TransientFailure( 'A chunk file could not be written.' );
		}
	}

	/**
	 * The key to continue after. The committed part of a chunk ends right
	 * after a marker line (the committed length only advances once a batch
	 * and its marker are on disk), so the last line is the marker; a chunk
	 * with no marker at all has not had a batch yet and starts after the
	 * key in its header. A last line that is not a marker while the chunk
	 * holds one, or a marker that does not parse, is damage: the export
	 * fails instead of re-exporting rows from an earlier point.
	 *
	 * @param resource $handle Open chunk.
	 * @param string   $table  Table.
	 * @param int      $chunk  Chunk number.
	 * @param int      $length Committed length.
	 * @return string[]|null Null at the start of the table.
	 * @throws \RuntimeException When the committed part is not shaped as the writer left it.
	 * @throws WorkLost When the work directory was lost, changed or damaged.
	 */
	private function resume_key( $handle, string $table, int $chunk, int $length ) {
		$tail_length = min( $length, self::MAX_MARKER_BYTES + 1 );
		$tail        = false;
		if ( $tail_length > 0 && 0 === fseek( $handle, $length - $tail_length ) ) {
			$tail = fread( $handle, $tail_length ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- chunk file in the job's work directory.
		}
		if ( ! is_string( $tail ) || strlen( $tail ) !== $tail_length ) {
			throw new \RuntimeException( 'A chunk file could not be read back.' ); // open_at() checked its length: a storage error.
		}
		if ( "\n" === substr( $tail, -1 ) ) {
			$body  = substr( $tail, 0, -1 );
			$start = strrpos( $body, "\n" );
			$line  = false === $start ? $body : substr( $body, $start + 1 );
			// A line cut by the window start (no newline in the window and more file before it) is not a marker of legal length.
			if ( ( false !== $start || $length === $tail_length ) && 0 === strpos( $line, self::MARKER ) ) {
				fseek( $handle, $length );
				return self::parse_marker( $line );
			}
		}
		if ( $this->contains_marker( $handle, $length ) ) {
			throw new WorkLost( sprintf( 'Chunk %d of table %s does not end with a batch marker at the recorded length; the work directory was changed or damaged.', $chunk, $table ) );
		}
		fseek( $handle, 0 );
		$first = fgets( $handle, self::MAX_MARKER_BYTES );
		fseek( $handle, $length );
		if ( ! is_string( $first ) ) {
			throw new \RuntimeException( 'The header of a chunk file could not be read.' ); // open_at() checked its length: a storage error.
		}
		return $this->key_from_header( $table, $chunk, $first );
	}

	/**
	 * Whether a marker line starts anywhere in the first $length bytes (a
	 * windowed scan; the windows overlap by the marker prefix so a line
	 * start on a boundary is not missed).
	 *
	 * @param resource $handle Open chunk.
	 * @param int      $length Committed length.
	 * @return bool
	 */
	private function contains_marker( $handle, int $length ): bool {
		$needle  = "\n" . self::MARKER;
		$overlap = '';
		$offset  = 0;
		while ( $offset < $length ) {
			$size = min( self::SCAN_WINDOW, $length - $offset );
			if ( 0 !== fseek( $handle, $offset ) ) {
				return false;
			}
			$piece = fread( $handle, $size ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see above.
			if ( ! is_string( $piece ) || '' === $piece ) {
				return false;
			}
			if ( false !== strpos( $overlap . $piece, $needle ) ) {
				return true;
			}
			$overlap = substr( $piece, -strlen( $needle ) );
			$offset += strlen( $piece );
		}
		return false;
	}

	/**
	 * Parse a marker line. Null when the line is not a marker at all; a
	 * line that starts like a marker but does not parse is damage.
	 *
	 * @param string $line Line without its newline.
	 * @return string[]|null Key values; an empty array in offset mode.
	 * @throws \RuntimeException When the marker is malformed.
	 * @throws WorkLost When the work directory was lost, changed or damaged.
	 */
	public static function parse_marker( string $line ) {
		if ( 0 !== strpos( $line, self::MARKER ) ) {
			return null;
		}
		if ( 1 !== preg_match( '/\A' . preg_quote( self::MARKER, '/' ) . 'rows=(\d+) (pk|offset)=(.+)\z/', $line, $m ) ) {
			throw new WorkLost( 'A batch marker in a chunk file is malformed; the work directory was changed or damaged.' );
		}
		if ( 'offset' === $m[2] ) {
			return array(); // Offset mode keeps its position in the state; the marker is for readers.
		}
		return self::decode_key( $m[3] );
	}

	/**
	 * Key from a chunk header line.
	 *
	 * @param string $table Table.
	 * @param int    $chunk Chunk number.
	 * @param string $line  Header line.
	 * @return string[]|null
	 * @throws \RuntimeException When the line is not this chunk's header.
	 * @throws WorkLost When the work directory was lost, changed or damaged.
	 */
	private function key_from_header( string $table, int $chunk, string $line ) {
		$prefix = preg_quote( self::HEADER . $table . ' chunk=' . $chunk . ' ', '/' );
		if ( 1 !== preg_match( '/\A' . $prefix . '(pk_from|offset)=(.+?)\s*\z/', $line, $m ) ) {
			throw new WorkLost( sprintf( 'Chunk %d of table %s has a malformed header; the work directory was lost or changed.', $chunk, $table ) );
		}
		return 'offset' === $m[1] ? array() : self::decode_key( $m[2] );
	}

	/**
	 * The end line of the previous chunk gives the key the next chunk starts after.
	 *
	 * @param string $table Table.
	 * @param int    $chunk Chunk about to start (> 1).
	 * @return string[]|null
	 * @throws \RuntimeException When the previous chunk cannot be read or does not end as written.
	 * @throws WorkLost When the work directory was lost, changed or damaged.
	 */
	private function previous_end_key( string $table, int $chunk ) {
		$path   = $this->chunk_path( $table, $chunk - 1 );
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- see write_new().
		if ( false === $handle ) {
			throw WorkLost::or_unreadable( $path, 'The previous chunk file is missing; the work directory was lost or changed.', 'The previous chunk file could not be opened.' );
		}
		try {
			$stat = fstat( $handle );
			$size = is_array( $stat ) ? (int) $stat['size'] : 0;
			fseek( $handle, max( 0, $size - self::MAX_MARKER_BYTES ) );
			$tail = stream_get_contents( $handle );
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
		if ( false === $tail ) {
			throw new \RuntimeException( 'The previous chunk file could not be read.' );
		}
		$lines  = explode( "\n", rtrim( $tail, "\n" ) );
		$last   = (string) end( $lines );
		$prefix = preg_quote( self::END . 'table=' . $table . ' chunk=' . ( $chunk - 1 ) . ' ', '/' );
		if ( 1 !== preg_match( '/\A' . $prefix . 'rows=\d+ (pk_to|offset)=(.+)\z/', $last, $m ) ) {
			throw new WorkLost( sprintf( 'Chunk %d of table %s does not end with its end line; the work directory was lost or changed.', $chunk - 1, $table ) );
		}
		return 'offset' === $m[1] ? array() : self::decode_key( $m[2] );
	}

	/**
	 * Key values as one-line JSON: a JSON string for valid UTF-8, otherwise
	 * {"h": hex} so binary keys survive losslessly. Encoding never drops a
	 * value silently: a key that cannot be written fails the export.
	 *
	 * @param string[]|null $key Key.
	 * @return string
	 * @throws \RuntimeException When the key cannot be encoded.
	 */
	public static function encode_key( $key ): string {
		if ( null === $key ) {
			return 'null';
		}
		$elements = array();
		foreach ( $key as $value ) {
			$value      = (string) $value;
			$elements[] = 1 === preg_match( '//u', $value ) ? $value : array( 'h' => bin2hex( $value ) );
		}
		$json = json_encode( $elements, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure PHP class, also unit-tested without WordPress.
		if ( ! is_string( $json ) || array() === $elements ) {
			throw new \RuntimeException( 'A primary key value could not be recorded in the chunk file.' );
		}
		return $json;
	}

	/**
	 * Key values from one-line JSON; null for "null". Anything malformed
	 * is damage to the chunk file and fails the export.
	 *
	 * @param string $json JSON.
	 * @return string[]|null
	 * @throws \RuntimeException When the JSON is not a key as encode_key() writes it.
	 * @throws WorkLost When the work directory was lost, changed or damaged.
	 */
	public static function decode_key( string $json ) {
		if ( 'null' === $json ) {
			return null;
		}
		$decoded = json_decode( $json, true, 3 );
		if ( ! is_array( $decoded ) || array() === $decoded || array_keys( $decoded ) !== range( 0, count( $decoded ) - 1 ) ) {
			throw new WorkLost( 'A primary key in a chunk file is malformed; the work directory was changed or damaged.' );
		}
		$key = array();
		foreach ( $decoded as $value ) {
			if ( is_string( $value ) || is_int( $value ) ) {
				$key[] = (string) $value;
				continue;
			}
			if ( is_array( $value ) && array( 'h' ) === array_keys( $value ) && is_string( $value['h'] ) && 1 === preg_match( '/\A(?:[0-9a-f]{2})*\z/', $value['h'] ) ) {
				$key[] = (string) hex2bin( $value['h'] );
				continue;
			}
			throw new WorkLost( 'A primary key in a chunk file is malformed; the work directory was changed or damaged.' );
		}
		return $key;
	}

	/**
	 * Record how the table is read, once.
	 *
	 * @param array<string, mixed>                                                                     $state State (updated).
	 * @param array{create: string, columns: string[], kinds: string[], pk: string[], pk_index: int[]} $desc  Description.
	 * @return void
	 */
	private function note_mode( array &$state, array $desc ): void {
		if ( 1 !== (int) $state['chunk'] ) {
			return;
		}
		if ( array() === $desc['pk'] ) {
			$state['warnings'][] = sprintf( 'Table %s has no primary key and was read with LIMIT/OFFSET; rows added or removed while it was being exported may be missing or duplicated.', (string) $state['table'] );
		}
		if ( in_array( (string) $state['table'], $this->exclude_oversize, true ) ) {
			$state['warnings'][] = sprintf( 'Table %s: rows larger than the single-row limit of %d bytes (as SQL) were left out, as chosen at the pre-flight.', (string) $state['table'], $this->row_limit() );
		}
	}

	/**
	 * The "row too large" message: table, position, size, what to do.
	 *
	 * @param string                                                                                   $table Table.
	 * @param int                                                                                      $bytes Size as SQL (estimated or exact).
	 * @param array{create: string, columns: string[], kinds: string[], pk: string[], pk_index: int[]} $desc  Description.
	 * @param string[]|null                                                                            $key   The row's key, if known.
	 * @param int                                                                                      $index The row's position from the start of the table (0-based).
	 * @return string
	 */
	private function too_large( string $table, int $bytes, array $desc, $key, int $index ): string {
		return sprintf( 'Table %s has a row of about %d bytes (as SQL) at %s, larger than the %d bytes a single row may take in a backup; this row must be reduced or excluded before the table can be backed up.', $table, $bytes, $this->position_text( $desc, $key, $index ), $this->row_limit() );
	}

	/**
	 * Human-readable position for an error message: the key when it is
	 * numeric, else the row number (string keys are user data: session
	 * keys, addresses, names).
	 *
	 * @param array{create: string, columns: string[], kinds: string[], pk: string[], pk_index: int[]} $desc  Description.
	 * @param string[]|null                                                                            $key   The row's key.
	 * @param int                                                                                      $index The row's position (0-based).
	 * @return string
	 */
	private function position_text( array $desc, $key, int $index ): string {
		if ( null !== $key && array() !== $desc['pk_index'] ) {
			$numeric = true;
			foreach ( $desc['pk_index'] as $at ) {
				if ( 'numeric' !== $desc['kinds'][ $at ] ) {
					$numeric = false;
				}
			}
			if ( $numeric ) {
				return 'primary key ' . self::encode_key( $key );
			}
		}
		return 'row ' . ( $index + 1 );
	}
}
