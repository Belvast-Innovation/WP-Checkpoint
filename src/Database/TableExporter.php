<?php
/**
 * Streams one table into SQL chunk files, one batch of rows per unit of work.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Database;

use WPCheckpoint\Archive\ChunkHasher;
use WPCheckpoint\Archive\IndexLine;
use WPCheckpoint\Jobs\TransientFailure;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages carry table names, numbers and the driver's error text; the runner stores them through the redactor and the presenter cleans them before display.

/**
 * Pure PHP over a Connection. A table becomes database/{t}.{c}.sql files
 * of at most CHUNK_BYTES each; chunk 1 starts with DROP TABLE and the
 * CREATE statement, every chunk starts with a header comment and ends
 * with an end comment, and every batch of rows is followed by a marker
 * comment carrying the last primary key of the batch. That marker is
 * the only resume point: the cursor never holds a key value (keys are
 * user data, and a key that happens to contain a site secret would trip
 * the cursor check), only the table, the chunk number and the number of
 * bytes of the current chunk that were committed at the last checkpoint.
 *
 * Resuming truncates the chunk to the committed length (a batch written
 * after the last checkpoint is simply redone) and finds the last marker
 * before that length by scanning backwards, falling back to the chunk
 * header's pk_from. Both invariants the scan relies on hold by
 * construction: the committed length only ever advances after a whole
 * batch and its marker are on disk, so the committed part of a chunk
 * always ends right after a marker line and a torn write lies beyond
 * it; and the key is JSON on one line found only at a line start. A
 * marker that does not parse is skipped, not trusted.
 *
 * Batches adapt to the row size (target TARGET_BATCH_BYTES, at most a
 * quarter of the chunk), INSERT statements are cut at about
 * STATEMENT_BYTES (one row per statement when a row alone is larger),
 * and a chunk closes when the next batch would push it past
 * CHUNK_BYTES. A single row larger than MAX_ROW_BYTES as SQL cannot be
 * exported and fails with the table, the position and the size.
 * Tables with a primary key are read in key order with an
 * expanded comparison that uses the index on every MySQL version; tables
 * without one use LIMIT/OFFSET, which is unstable while the table
 * changes, and say so in a warning.
 *
 * The export is not a snapshot: batches run across ticks and requests
 * while the site keeps writing, so the files reflect the state of each
 * table over the export period. The manifest records the period.
 */
final class TableExporter {

	const CHUNK_BYTES        = 16777216;
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
	 * legal row against that budget. Rows are not sliced (no T0xx yet).
	 */
	const MAX_ROW_BYTES = 4194304;

	/**
	 * Small pieces of a batch are coalesced into writes of about this size; a value larger than this is written on its own.
	 */
	const WRITE_BYTES = 65536;

	const HEADER = '-- wpcheckpoint table=';
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
	 * Table descriptions cached for this instance.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $described = array();

	/**
	 * Constructor.
	 *
	 * @param Connection $connection   Database.
	 * @param string     $dir          Existing directory for the chunk files.
	 * @param int        $chunk_bytes  Chunk size (tests use smaller values).
	 * @param int        $target_batch Batch target size; kept at or below a quarter of the chunk size so a batch always fits.
	 */
	public function __construct( Connection $connection, string $dir, int $chunk_bytes = self::CHUNK_BYTES, int $target_batch = self::TARGET_BATCH_BYTES ) {
		$this->connection   = $connection;
		$this->dir          = rtrim( $dir, '/\\' );
		$this->chunk_bytes  = $chunk_bytes;
		$this->target_batch = max( 1, min( $target_batch, intdiv( $chunk_bytes, 4 ) ) );
		$this->writer       = new SqlWriter( $connection->charset() );
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
	 * @throws \RuntimeException When the table cannot be exported (a row larger than a chunk, a broken query, a lost file).
	 */
	public function step( array $state ): array {
		$state['closed'] = null;
		if ( ! empty( $state['done'] ) ) {
			return $state;
		}
		$table = (string) $state['table'];
		$desc  = $this->describe( $table );
		$path  = $this->chunk_path( $table, (int) $state['chunk'] );

		if ( 0 === (int) $state['bytes'] ) {
			$key = 1 === (int) $state['chunk'] ? null : $this->key_from_header( $this->previous_end_key( $table, (int) $state['chunk'] ) );
			$this->write_new( $path, $this->header( $state, $desc, $key ) );
			$state['bytes'] = (int) filesize( $path );
			$this->note_mode( $state, $desc );
			$handle = $this->open_at( $path, (int) $state['bytes'] );
		} else {
			$handle = $this->open_at( $path, (int) $state['bytes'] );
			$key    = $this->resume_key( $handle, (int) $state['bytes'] );
		}

		try {
			$rows = $this->fetch( $table, $desc, $key, (int) $state['rows'], (int) $state['batch_rows'] );
			if ( array() === $rows ) {
				$this->close_chunk( $handle, $path, $state, $desc, $key );
				$state['done'] = true;
				return $state;
			}
			list( $pieces, $length, $emitted, $last_key, $largest ) = $this->format_batch( $table, $desc, $rows, $key, (int) $state['rows'] );
			$rows      = array();
			$end       = strlen( $this->end_line( $state, $desc, $last_key ) );
			$row_limit = min( self::MAX_ROW_BYTES, $this->chunk_bytes - 4096 );
			if ( $largest['bytes'] > $row_limit ) {
				throw new \RuntimeException( sprintf( 'Table %s has a row of %d bytes (as SQL) at position %s, larger than the %d bytes a single row may take in a backup; this row must be reduced or excluded before the table can be backed up.', $table, $largest['bytes'], $this->position_text( $desc, $largest['after'], $largest['index'] ), $row_limit ) );
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
				$this->write_new( $path, $this->header( $state, $desc, $key ) );
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
	 * Describe a table: CREATE statement, columns with kinds, primary key columns.
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
		foreach ( $columns as $column ) {
			$names[] = (string) $column[0];
			$kinds[] = SqlWriter::kind( (string) $column[1] );
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
				$pk = array(); // A key on a column the listing lacks: treat as keyless rather than guess.
				break;
			}
			$pk_index[] = (int) $at;
		}
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
	 * Fetch the next batch after a key (or offset).
	 *
	 * @param string                                                                                   $table Table.
	 * @param array{create: string, columns: string[], kinds: string[], pk: string[], pk_index: int[]} $desc  Description.
	 * @param string[]|null                                                                            $key   Last key, null from the start.
	 * @param int                                                                                      $rows  Rows exported so far (offset mode).
	 * @param int                                                                                      $limit Batch size.
	 * @return array<int, array<int, string|null>>
	 */
	private function fetch( string $table, array $desc, $key, int $rows, int $limit ): array {
		$sql  = 'SELECT * FROM ' . SqlWriter::identifier( $table );
		$args = array();
		if ( array() === $desc['pk'] ) {
			$sql .= ' LIMIT ' . $limit . ' OFFSET ' . $rows;
			return $this->query( $sql );
		}
		if ( null !== $key ) {
			list( $where, $args ) = self::after_key( $desc['pk'], $key );
			$sql                 .= ' WHERE ' . $where;
		}
		$sql .= ' ORDER BY ' . implode( ', ', array_map( array( SqlWriter::class, 'identifier' ), $desc['pk'] ) ) . ' LIMIT ' . $limit;
		return $this->query( $sql, $args );
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
	 * Rows to statements plus the batch marker, as a list of pieces to be
	 * written in order (one piece per row, plus statement heads and ends),
	 * so a large row is held once, not concatenated into a copy. Emits
	 * rows until the target batch size is reached; rows beyond that are
	 * left for the next batch (they are fetched again). Rows are released
	 * as they are formatted.
	 *
	 * @param string                                                                                   $table Table.
	 * @param array{create: string, columns: string[], kinds: string[], pk: string[], pk_index: int[]} $desc  Description.
	 * @param array<int, array<int, string|null>>                                                      $rows  Fetched rows.
	 * @param string[]|null                                                                            $key   Key before the batch.
	 * @param int                                                                                      $done  Rows exported before the batch.
	 * @return array{0: string[], 1: int, 2: int, 3: string[]|null, 4: array{bytes: int, after: string[]|null, index: int}} Pieces, their total length, rows emitted, last key, largest row (its size, the key before it, its position).
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
			'after' => $key,
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
					'after' => $last,
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
	 * Next batch size from the last one: aim at the target bytes, within bounds.
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
	 * The header of a chunk: the comment line and, for chunk 1, DROP and CREATE.
	 *
	 * @param array<string, mixed>                                                                     $state State.
	 * @param array{create: string, columns: string[], kinds: string[], pk: string[], pk_index: int[]} $desc  Description.
	 * @param string[]|null                                                                            $key   Key the chunk starts after.
	 * @return string
	 */
	private function header( array $state, array $desc, $key ): string {
		$table = (string) $state['table'];
		$from  = array() === $desc['pk'] ? 'offset=' . (int) $state['rows'] : 'pk_from=' . self::encode_key( $key );
		$text  = self::HEADER . $table . ' chunk=' . (int) $state['chunk'] . ' ' . $from . "\n";
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
			throw new \RuntimeException( 'A chunk file is shorter than the last checkpoint recorded; the work directory was lost or changed.' );
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
	 * The key to continue after, from the last marker before $length, or
	 * from the chunk header when the chunk has no marker yet. The scan
	 * runs backwards in windows; a line that does not parse is skipped.
	 *
	 * @param resource $handle Open chunk.
	 * @param int      $length Committed length.
	 * @return string[]|null Null at the start of the table.
	 * @throws \RuntimeException When neither a marker nor a header can be read.
	 */
	private function resume_key( $handle, int $length ) {
		$end  = $length;
		$tail = '';
		while ( $end > 0 ) {
			$start = max( 0, $end - self::SCAN_WINDOW );
			if ( 0 !== fseek( $handle, $start ) ) {
				break;
			}
			$piece = fread( $handle, $end - $start ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- chunk file in the job's work directory.
			if ( ! is_string( $piece ) ) {
				break;
			}
			$tail = $piece . $tail;
			if ( strlen( $tail ) > self::MAX_MARKER_BYTES + self::SCAN_WINDOW ) {
				$tail = substr( $tail, -( self::MAX_MARKER_BYTES + self::SCAN_WINDOW ) );
			}
			$found = self::last_marker( $tail );
			if ( null !== $found ) {
				fseek( $handle, $length );
				return $found;
			}
			$end = $start;
		}
		// No marker: the header of this chunk names the key the chunk starts after.
		fseek( $handle, 0 );
		$first = fgets( $handle, self::MAX_MARKER_BYTES );
		fseek( $handle, $length );
		if ( ! is_string( $first ) ) {
			throw new \RuntimeException( 'A chunk file has no header; the work directory was lost or changed.' );
		}
		return $this->key_from_header( $first );
	}

	/**
	 * The last well-formed marker line in a buffer, anchored at a line start.
	 *
	 * @param string $buffer Buffer ending at the committed length.
	 * @return string[]|null
	 */
	private static function last_marker( string $buffer ) {
		$pos = strlen( $buffer );
		while ( true ) {
			$pos = strrpos( $buffer, "\n" . self::MARKER, $pos - strlen( $buffer ) - 1 );
			if ( false === $pos ) {
				return null;
			}
			$line_end = strpos( $buffer, "\n", $pos + 1 );
			$line     = substr( $buffer, $pos + 1, false === $line_end ? null : $line_end - $pos - 1 );
			$key      = self::parse_marker( $line );
			if ( null !== $key ) {
				return $key;
			}
			if ( 0 === $pos ) {
				return null;
			}
		}
	}

	/**
	 * Parse a marker line; null when it is not a complete, valid marker.
	 *
	 * @param string $line Line without its newline.
	 * @return string[]|null
	 */
	public static function parse_marker( string $line ) {
		if ( 1 !== preg_match( '/\A' . preg_quote( self::MARKER, '/' ) . 'rows=(\d+) (pk|offset)=(.+)\z/', $line, $m ) ) {
			return null;
		}
		if ( 'offset' === $m[2] ) {
			return array(); // Offset mode keeps its position in the state; the marker is for readers.
		}
		return self::decode_key( $m[3] );
	}

	/**
	 * Key from a chunk header line ("pk_from=..." or "offset=...").
	 *
	 * @param string $line Header line.
	 * @return string[]|null
	 * @throws \RuntimeException When the line is not a header.
	 */
	private function key_from_header( string $line ) {
		if ( 1 !== preg_match( '/\A' . preg_quote( self::HEADER, '/' ) . '\S+ chunk=\d+ (pk_from|offset)=(.+?)\s*\z/', $line, $m ) ) {
			throw new \RuntimeException( 'A chunk file has a malformed header; the work directory was lost or changed.' );
		}
		if ( 'offset' === $m[1] ) {
			return array();
		}
		return self::decode_key( $m[2] );
	}

	/**
	 * The end line of the previous chunk gives the key the next chunk starts after.
	 *
	 * @param string $table Table.
	 * @param int    $chunk Chunk about to start (> 1).
	 * @return string The previous chunk's end line rewritten as a header-shaped line.
	 * @throws \RuntimeException When the previous chunk cannot be read.
	 */
	private function previous_end_key( string $table, int $chunk ): string {
		$path   = $this->chunk_path( $table, $chunk - 1 );
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- see write_new().
		if ( false === $handle ) {
			throw new \RuntimeException( 'The previous chunk file is missing; the work directory was lost or changed.' );
		}
		try {
			$stat = fstat( $handle );
			$size = is_array( $stat ) ? (int) $stat['size'] : 0;
			fseek( $handle, max( 0, $size - self::MAX_MARKER_BYTES ) );
			$tail = (string) stream_get_contents( $handle );
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
		$lines = explode( "\n", rtrim( $tail, "\n" ) );
		$last  = (string) end( $lines );
		if ( 1 !== preg_match( '/\A' . preg_quote( self::END, '/' ) . 'table=\S+ chunk=\d+ rows=\d+ (pk_to|offset)=(.+)\z/', $last, $m ) ) {
			throw new \RuntimeException( 'The previous chunk file does not end with its end line; the work directory was lost or changed.' );
		}
		return self::HEADER . $table . ' chunk=' . $chunk . ' ' . ( 'offset' === $m[1] ? 'offset=' : 'pk_from=' ) . $m[2];
	}

	/**
	 * Key values as one-line JSON.
	 *
	 * @param string[]|null $key Key.
	 * @return string
	 */
	public static function encode_key( $key ): string {
		if ( null === $key ) {
			return 'null';
		}
		$json = json_encode( array_map( 'strval', $key ), JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure PHP class, also unit-tested without WordPress.
		return is_string( $json ) ? $json : 'null';
	}

	/**
	 * Key values from one-line JSON; null for "null" or anything malformed.
	 *
	 * @param string $json JSON.
	 * @return string[]|null
	 */
	public static function decode_key( string $json ) {
		if ( 'null' === $json ) {
			return null;
		}
		$decoded = json_decode( $json, true, 2 );
		if ( ! is_array( $decoded ) || array() === $decoded || array_keys( $decoded ) !== range( 0, count( $decoded ) - 1 ) ) {
			return null;
		}
		foreach ( $decoded as $value ) {
			if ( ! is_string( $value ) && ! is_int( $value ) ) {
				return null;
			}
		}
		return array_map( 'strval', array_values( $decoded ) );
	}

	/**
	 * Record how the table is read, once.
	 *
	 * @param array<string, mixed>                                                                     $state State (updated).
	 * @param array{create: string, columns: string[], kinds: string[], pk: string[], pk_index: int[]} $desc  Description.
	 * @return void
	 */
	private function note_mode( array &$state, array $desc ): void {
		if ( array() === $desc['pk'] && 1 === (int) $state['chunk'] ) {
			$state['warnings'][] = sprintf( 'Table %s has no primary key and was read with LIMIT/OFFSET; rows added or removed while it was being exported may be missing or duplicated.', (string) $state['table'] );
		}
	}

	/**
	 * Human-readable position for an error message.
	 *
	 * @param array{create: string, columns: string[], kinds: string[], pk: string[], pk_index: int[]} $desc Description.
	 * @param string[]|null                                                                            $key  Key before the row.
	 * @param int                                                                                      $rows Rows exported so far.
	 * @return string
	 */
	private function position_text( array $desc, $key, int $rows ): string {
		if ( array() === $desc['pk'] ) {
			return 'row ' . ( $rows + 1 );
		}
		return 'after primary key ' . self::encode_key( $key );
	}
}
