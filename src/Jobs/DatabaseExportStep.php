<?php
/**
 * The step that exports the database tables into SQL chunk files.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Archive\ChunkHasher;
use WPCheckpoint\Archive\EntryPath;
use WPCheckpoint\Archive\IndexLine;
use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Database\Connection;
use WPCheckpoint\Database\TableExporter;
use WPCheckpoint\Support\Utf8;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages carry table names and numbers; the runner stores them through the redactor and the presenter cleans them before display.

/**
 * Drives TableExporter over a frozen list of tables. Files under the
 * job's work directory: database/{t}.{c}.sql (the chunks),
 * database.index.jsonl (one line per finished chunk, appended when the
 * chunk closes, idempotently), tables.json (the list frozen on the first
 * tick, so a table created mid-export does not shift the order), and
 * database.summary.json when the export is done (per-table rows, bytes,
 * chunks and list hash, the export period, warnings; table names and
 * counts only, never a CREATE statement or a row).
 *
 * The cursor holds the table position and the exporter state (chunk
 * number and committed bytes), never a key value. Every unit is one
 * batch; a checkpoint follows every closed chunk (after its index line)
 * and otherwise the usual rhythm.
 */
final class DatabaseExportStep implements Step {

	const ID      = 'database';
	const TABLES  = 'tables.json';
	const DONE    = 'tables.done.jsonl';
	const SUMMARY = 'database.summary.json';
	const DIR     = 'database';

	/**
	 * Database.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Tables to export, in order.
	 *
	 * @var string[]
	 */
	private $tables;

	/**
	 * Warnings from choosing the tables (views left out, and the like).
	 *
	 * @var string[]
	 */
	private $notes;

	/**
	 * Chunk size (tests use a smaller one).
	 *
	 * @var int
	 */
	private $chunk_bytes;

	/**
	 * Constructor. The job type picks the tables from the job's options.
	 *
	 * @param Connection $connection  Database.
	 * @param string[]   $tables      Tables in export order.
	 * @param string[]   $notes       Warnings to carry into the summary.
	 * @param int        $chunk_bytes Chunk size.
	 */
	public function __construct( Connection $connection, array $tables, array $notes = array(), int $chunk_bytes = TableExporter::CHUNK_BYTES ) {
		$this->connection  = $connection;
		$this->tables      = array_values( $tables );
		$this->notes       = $notes;
		$this->chunk_bytes = $chunk_bytes;
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
	 * Export batches until the budget is spent or every table is done.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws TransientFailure When the database or the work directory is temporarily unavailable.
	 */
	public function run( JobContext $context ): StepResult {
		$work   = $context->work_path();
		$dir    = $work . DIRECTORY_SEPARATOR . self::DIR;
		$cursor = $context->cursor();
		if ( ! isset( $cursor['index'] ) ) {
			$cursor = $this->start( $work, $dir, $context );
		}
		$tables = $this->frozen_tables( $work );
		$total  = count( $tables );
		$since  = 0;
		while ( (int) $cursor['index'] < $total ) {
			$table    = $tables[ (int) $cursor['index'] ];
			$exporter = new TableExporter( $this->connection, $dir, $this->chunk_bytes );
			$state    = isset( $cursor['state'] ) && is_array( $cursor['state'] ) ? $cursor['state'] : TableExporter::initial_state( $table );
			$before   = (int) $state['bytes'];
			$state    = $exporter->step( $state );
			$since   += max( 0, (int) $state['bytes'] - $before );
			$closed   = is_array( $state['closed'] ) ? $state['closed'] : null;
			if ( null !== $closed ) {
				$this->append_index_line( $work, $table, $closed );
				$since += (int) $closed['bytes'];
			}
			if ( ! empty( $state['done'] ) ) {
				$this->finish_table( $work, $table, $state, $context );
				++$cursor['index'];
				$cursor['state'] = null;
				$cursor['done']  = (int) $cursor['done'] + 1;
			} else {
				$cursor['state'] = $state;
			}
			if ( null !== $closed || ! empty( $state['done'] ) || $context->should_checkpoint( $since ) ) {
				$context->checkpoint( $cursor, $this->percent( $cursor, $total ), $this->message( $cursor, $total ) );
				$since = 0;
			}
			if ( $context->should_stop() && (int) $cursor['index'] < $total ) {
				return StepResult::progress( $cursor, $this->percent( $cursor, $total ), $this->message( $cursor, $total ) );
			}
		}
		$this->write_summary( $work, $cursor, $context );
		return StepResult::done( $this->message( $cursor, $total ) );
	}

	/**
	 * Nothing to do: the chunk files and indexes live in the work
	 * directory, which the engine removes; no temporary tables are made.
	 *
	 * @param JobContext $context Context.
	 * @return void
	 */
	public function cleanup( JobContext $context ): void {
		unset( $context );
	}

	/**
	 * First tick: freeze the table list, create the chunk directory and the index.
	 *
	 * @param string     $work    Work directory.
	 * @param string     $dir     Chunk directory.
	 * @param JobContext $context Context.
	 * @return array<string, mixed> The initial cursor.
	 * @throws TransientFailure When the work directory cannot be prepared.
	 */
	private function start( string $work, string $dir, JobContext $context ): array {
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700 ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- a warning would put the path into the error log.
			throw new TransientFailure( 'The database chunk directory could not be created.' );
		}
		$tables  = array();
		$skipped = array();
		foreach ( $this->tables as $table ) {
			$table = (string) $table;
			if ( Utf8::scrub( $table ) !== $table || strlen( $table ) > Manifest::MAX_TABLE_NAME || ! EntryPath::is_valid( IndexLine::database_path( $table, 1 ) ) ) {
				$skipped[] = Utf8::scrub( $table );
				continue;
			}
			$tables[] = $table;
		}
		$notes = $this->notes;
		foreach ( $skipped as $name ) {
			$notes[] = sprintf( 'Table %s was skipped: its name cannot be stored in an archive path.', $name );
		}
		$this->put(
			$work . DIRECTORY_SEPARATOR . self::TABLES,
			wp_json_encode(
				array(
					'tables' => $tables,
					'notes'  => $notes,
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
		);
		$this->put( $work . DIRECTORY_SEPARATOR . Manifest::DATABASE_INDEX, '' );
		$this->put( $work . DIRECTORY_SEPARATOR . self::DONE, '' );
		foreach ( $skipped as $name ) {
			$context->logger()->warning( 'Table skipped: name not storable', array( 'table' => $name ) );
		}
		return array(
			'index'      => 0,
			'done'       => 0,
			'state'      => null,
			'started_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);
	}

	/**
	 * The frozen table list.
	 *
	 * @param string $work Work directory.
	 * @return string[]
	 * @throws \RuntimeException When the list is gone.
	 */
	private function frozen_tables( string $work ): array {
		$json = @file_get_contents( $work . DIRECTORY_SEPARATOR . self::TABLES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- small file in the job's own work directory.
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) || ! isset( $data['tables'] ) || ! is_array( $data['tables'] ) ) {
			throw new \RuntimeException( 'The frozen table list is missing; the work directory was lost or changed.' );
		}
		return array_map( 'strval', $data['tables'] );
	}

	/**
	 * Append the index line of a closed chunk. When the last line already
	 * names this chunk (a crash between the append and the checkpoint: the
	 * chunk was cut back to the committed length and closed again, possibly
	 * with different rows if the table changed meanwhile), that line is
	 * replaced so the hash on record is the hash of the file as it is.
	 *
	 * @param string                                      $work   Work directory.
	 * @param string                                      $table  Table.
	 * @param array{chunk: int, bytes: int, hash: string} $closed Closed chunk.
	 * @return void
	 * @throws TransientFailure When the index cannot be written.
	 */
	private function append_index_line( string $work, string $table, array $closed ): void {
		$path = $work . DIRECTORY_SEPARATOR . Manifest::DATABASE_INDEX;
		$line = array(
			't' => $table,
			'c' => (int) $closed['chunk'],
			'p' => IndexLine::database_path( $table, (int) $closed['chunk'] ),
			'b' => (int) $closed['bytes'],
			'h' => (string) $closed['hash'],
		);
		IndexLine::database( (string) wp_json_encode( $line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), $this->chunk_bytes ); // The line must satisfy the reader's rule.
		$last = self::last_line( $path );
		if ( '' !== $last ) {
			$previous = json_decode( $last, true );
			if ( is_array( $previous ) && ( $previous['t'] ?? null ) === $table && (int) ( $previous['c'] ?? 0 ) === (int) $closed['chunk'] ) {
				$this->drop_last_line( $path, $last );
			}
		}
		$this->put( $path, wp_json_encode( $line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n", true );
	}

	/**
	 * Cut the last line off a file (the line was read by last_line()).
	 *
	 * @param string $path Path.
	 * @param string $last The last line, without its newline.
	 * @return void
	 * @throws TransientFailure When the file cannot be shortened.
	 */
	private function drop_last_line( string $path, string $last ): void {
		clearstatcache( true, $path );
		$size   = (int) filesize( $path );
		$handle = @fopen( $path, 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- see above.
		if ( false === $handle ) {
			throw new TransientFailure( 'The database index could not be opened.' );
		}
		try {
			if ( ! ftruncate( $handle, max( 0, $size - strlen( $last ) - 1 ) ) ) {
				throw new TransientFailure( 'The database index could not be shortened.' );
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
	}

	/**
	 * Record a finished table: its summary line (idempotent) and a log line.
	 *
	 * @param string               $work    Work directory.
	 * @param string               $table   Table.
	 * @param array<string, mixed> $state   Final exporter state.
	 * @param JobContext           $context Context.
	 * @return void
	 * @throws TransientFailure When the index cannot be read.
	 * @throws \RuntimeException When the index disagrees with the chunks written.
	 */
	private function finish_table( string $work, string $table, array $state, JobContext $context ): void {
		$hashes = array();
		$index  = @fopen( $work . DIRECTORY_SEPARATOR . Manifest::DATABASE_INDEX, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- the job's own index.
		if ( false === $index ) {
			throw new TransientFailure( 'The database index could not be read.' );
		}
		try {
			$line = fgets( $index );
			while ( false !== $line ) {
				$data = json_decode( rtrim( $line, "\n" ), true );
				if ( is_array( $data ) && ( $data['t'] ?? null ) === $table && isset( $data['h'] ) ) {
					$hashes[] = (string) $data['h'];
				}
				$line = fgets( $index );
			}
		} finally {
			fclose( $index ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
		$summary = array(
			'name'   => $table,
			'rows'   => (int) $state['rows'],
			'bytes'  => (int) $state['total'],
			'chunks' => (int) $state['chunks'],
			'sha256' => ChunkHasher::list_hash( $hashes ),
		);
		if ( count( $hashes ) !== (int) $state['chunks'] ) {
			throw new \RuntimeException( sprintf( 'The index holds %d chunks of table %s but %d were written.', count( $hashes ), $table, (int) $state['chunks'] ) );
		}
		$done = $work . DIRECTORY_SEPARATOR . self::DONE;
		$last = json_decode( self::last_line( $done ), true );
		if ( ! is_array( $last ) || ( $last['name'] ?? null ) !== $table ) {
			$this->put( $done, wp_json_encode( array_merge( $summary, array( 'warnings' => array_values( (array) $state['warnings'] ) ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n", true );
		}
		$context->logger()->info(
			'Table exported',
			array(
				'table'  => $table,
				'rows'   => $summary['rows'],
				'chunks' => $summary['chunks'],
			)
		);
		foreach ( (array) $state['warnings'] as $warning ) {
			$context->logger()->warning( $warning );
		}
	}

	/**
	 * The summary the manifest step reads: tables in order, the period, warnings.
	 *
	 * @param string               $work    Work directory.
	 * @param array<string, mixed> $cursor  Cursor.
	 * @param JobContext           $context Context.
	 * @return void
	 * @throws TransientFailure When the summaries cannot be read or written.
	 */
	private function write_summary( string $work, array $cursor, JobContext $context ): void {
		$tables   = array();
		$warnings = array();
		$frozen   = json_decode( (string) @file_get_contents( $work . DIRECTORY_SEPARATOR . self::TABLES ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- small file in the job's own work directory.
		foreach ( is_array( $frozen ) && isset( $frozen['notes'] ) ? (array) $frozen['notes'] : array() as $note ) {
			$warnings[] = (string) $note;
		}
		$done = @fopen( $work . DIRECTORY_SEPARATOR . self::DONE, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- see above.
		if ( false === $done ) {
			throw new TransientFailure( 'The table summaries could not be read.' );
		}
		try {
			$line = fgets( $done );
			while ( false !== $line ) {
				$data = json_decode( rtrim( $line, "\n" ), true );
				$line = fgets( $done );
				if ( ! is_array( $data ) ) {
					continue;
				}
				foreach ( (array) ( $data['warnings'] ?? array() ) as $warning ) {
					$warnings[] = (string) $warning;
				}
				unset( $data['warnings'] );
				$tables[] = $data;
			}
		} finally {
			fclose( $done ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
		$exporter = new TableExporter( $this->connection, $work . DIRECTORY_SEPARATOR . self::DIR, $this->chunk_bytes );
		if ( $exporter->hex_all() ) {
			$warnings[] = sprintf( 'The database connection of this server uses the %s character set; text in the exported SQL is written as hexadecimal, which makes the files harder to read by hand but does not affect the restore.', $this->connection->charset() );
		}
		$summary = array(
			'exported' => array(
				'started_at'  => (string) $cursor['started_at'],
				'finished_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			),
			'tables'   => $tables,
			'warnings' => array_values( array_unique( $warnings ) ),
			'snapshot' => false,
		);
		$this->put( $work . DIRECTORY_SEPARATOR . self::SUMMARY, (string) wp_json_encode( $summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$context->logger()->info( 'Database export finished', array( 'tables' => count( $tables ) ) );
	}

	/**
	 * The last non-empty line of a file (small tail read).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function last_line( string $path ): string {
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- see above.
		if ( false === $handle ) {
			return '';
		}
		try {
			$stat = fstat( $handle );
			$size = is_array( $stat ) ? (int) $stat['size'] : 0;
			fseek( $handle, max( 0, $size - 8192 ) );
			$tail  = (string) stream_get_contents( $handle );
			$lines = explode( "\n", rtrim( $tail, "\n" ) );
			return (string) end( $lines );
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
	}

	/**
	 * Write (or append) a small file in the work directory.
	 *
	 * @param string $path   Path.
	 * @param string $text   Text.
	 * @param bool   $append Append instead of replace.
	 * @return void
	 * @throws TransientFailure When the write fails.
	 */
	private function put( string $path, string $text, bool $append = false ): void {
		$written = @file_put_contents( $path, $text, $append ? FILE_APPEND | LOCK_EX : LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- small file in the job's own work directory; failure is thrown.
		if ( false === $written || strlen( $text ) !== $written ) {
			throw new TransientFailure( 'A file in the work directory could not be written.' );
		}
	}

	/**
	 * Progress by tables finished.
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @param int                  $total  Tables.
	 * @return int
	 */
	private function percent( array $cursor, int $total ): int {
		return $total <= 0 ? 100 : (int) min( 100, floor( 100 * (int) $cursor['index'] / $total ) );
	}

	/**
	 * Progress text: counts only.
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @param int                  $total  Tables.
	 * @return string
	 */
	private function message( array $cursor, int $total ): string {
		return sprintf(
			/* translators: 1: tables exported, 2: tables in total */
			__( 'Exported %1$d of %2$d tables', 'wp-checkpoint' ),
			(int) $cursor['index'],
			$total
		);
	}
}
