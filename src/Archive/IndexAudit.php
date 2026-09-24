<?php
/**
 * Audits of the side indexes before the archive is sealed.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Archive;

use WPCheckpoint\Jobs\WorkLost;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages carry line numbers, table names and counts; the runner stores them through the redactor.

/**
 * The writer's own reading of its indexes before they are sealed into the
 * archive: every files line carries a content hash (an archive without
 * them would pass verification on sizes alone), and the database index
 * is in lockstep with the export summary (tables in order, chunks
 * numbered 1..n, list hashes matching).
 *
 * Both audits run in bounded units resumable from a small state (byte
 * offset, line number, counts): a unit reads at most LINES_PER_UNIT lines
 * or BYTES_PER_UNIT bytes, whichever comes first, so a files index of a
 * million lines is a hundred units, not one. The whole-file functions
 * files() and database() loop the units for callers without a budget.
 */
final class IndexAudit {

	const LINES_PER_UNIT = 5000;
	const BYTES_PER_UNIT = 16777216;

	/**
	 * The state a files audit starts from.
	 *
	 * @return array{offset: int, n: int, count: int, bytes: int, done: bool}
	 */
	public static function files_start(): array {
		return array(
			'offset' => 0,
			'n'      => 0,
			'count'  => 0,
			'bytes'  => 0,
			'done'   => false,
		);
	}

	/**
	 * One unit of the files audit.
	 *
	 * @param string               $path        Index file.
	 * @param array<string, mixed> $state       State from files_start() or a previous unit.
	 * @param int                  $chunk_bytes Content chunk size the lines were made with.
	 * @return array{offset: int, n: int, count: int, bytes: int, done: bool}
	 * @throws \RuntimeException When a line is malformed or has no hash, with its number.
	 */
	public static function files_step( string $path, array $state, int $chunk_bytes ): array {
		if ( ! empty( $state['done'] ) ) {
			return $state;
		}
		$handle = self::open( $path, 'The files index is missing; the work directory was lost or changed.', (int) $state['offset'] );
		$read   = 0;
		$lines  = 0;
		try {
			$line = fgets( $handle, IndexLine::MAX_LINE_BYTES + 2 );
			while ( false !== $line ) {
				++$state['n'];
				++$lines;
				$read            += strlen( $line );
				$state['offset'] += strlen( $line );
				$text             = rtrim( $line, "\r\n" );
				if ( '' !== $text ) {
					try {
						$data = IndexLine::files( $text, $chunk_bytes );
					} catch ( IndexLineError $e ) {
						throw new \RuntimeException( sprintf( 'Files index line %d is malformed (%s); the archive cannot be described.', $state['n'], $e->getMessage() ) );
					}
					if ( null === $data['h'] ) {
						throw new \RuntimeException( sprintf( 'Files index line %d has no content hash; an archive without content hashes cannot be verified and is not written.', $state['n'] ) );
					}
					++$state['count'];
					$state['bytes'] += (int) $data['b'];
				}
				if ( $lines >= self::LINES_PER_UNIT || $read >= self::BYTES_PER_UNIT ) {
					return $state;
				}
				$line = fgets( $handle, IndexLine::MAX_LINE_BYTES + 2 );
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream read.
		}
		$state['done'] = true;
		return $state;
	}

	/**
	 * Read the whole files index: every line must carry a content hash.
	 *
	 * @param string $path        Index file.
	 * @param int    $chunk_bytes Content chunk size the lines were made with.
	 * @return array{count: int, bytes: int}
	 * @throws \RuntimeException When a line is malformed or has no hash, with its number.
	 */
	public static function files( string $path, int $chunk_bytes ): array {
		$state = self::files_start();
		while ( ! $state['done'] ) {
			$state = self::files_step( $path, $state, $chunk_bytes );
		}
		return array(
			'count' => (int) $state['count'],
			'bytes' => (int) $state['bytes'],
		);
	}

	/**
	 * The state a database audit starts from.
	 *
	 * @return array{offset: int, n: int, table: int, chunk: int, table_offset: int, done: bool}
	 */
	public static function database_start(): array {
		return array(
			'offset'       => 0,
			'n'            => 0,
			'table'        => 0,
			'chunk'        => 0,
			'table_offset' => 0,
			'done'         => false,
		);
	}

	/**
	 * One unit of the database audit, in lockstep with the summary's
	 * tables: the lines must name the tables in the summary's order, each
	 * table's chunks numbered 1..n consecutively with n the summary's
	 * chunk count, and the chunk hashes adding up to its list hash. A
	 * table's hashes are re-read from its first line when it closes
	 * (bounded by Manifest::MAX_TABLE_CHUNKS lines), so the state holds
	 * positions only.
	 *
	 * @param string                                                       $path        Index file.
	 * @param array<int, array{name: string, chunks: int, sha256: string}> $tables      Tables from database.summary.json, in order.
	 * @param array<string, mixed>                                         $state       State from database_start() or a previous unit.
	 * @param int                                                          $chunk_bytes Chunk size.
	 * @return array{offset: int, n: int, table: int, chunk: int, table_offset: int, done: bool}
	 * @throws \RuntimeException When the index and the summary disagree.
	 */
	public static function database_step( string $path, array $tables, array $state, int $chunk_bytes ): array {
		if ( ! empty( $state['done'] ) ) {
			return $state;
		}
		$handle = self::open( $path, 'The database index is missing; the work directory was lost or changed.', (int) $state['offset'] );
		$read   = 0;
		$lines  = 0;
		try {
			$line = fgets( $handle, IndexLine::MAX_LINE_BYTES + 2 );
			while ( false !== $line ) {
				$start = (int) $state['offset'];
				++$state['n'];
				++$lines;
				$read            += strlen( $line );
				$state['offset'] += strlen( $line );
				$text             = rtrim( $line, "\r\n" );
				if ( '' === $text ) {
					$line = fgets( $handle, IndexLine::MAX_LINE_BYTES + 2 );
					continue;
				}
				try {
					$data = IndexLine::database( $text, $chunk_bytes );
				} catch ( IndexLineError $e ) {
					throw new \RuntimeException( sprintf( 'Database index line %d is malformed (%s).', $state['n'], $e->getMessage() ) );
				}
				$state = self::skip_empty_tables( $tables, $state, $start );
				if ( $state['table'] < count( $tables ) && $state['chunk'] >= (int) $tables[ $state['table'] ]['chunks'] ) {
					self::close_table( $path, $tables[ $state['table'] ], (int) $state['table_offset'], (int) $state['n'], $chunk_bytes );
					++$state['table'];
					$state['chunk']        = 0;
					$state['table_offset'] = $start;
					$state                 = self::skip_empty_tables( $tables, $state, $start );
				}
				if ( $state['table'] >= count( $tables ) ) {
					throw new \RuntimeException( sprintf( 'Database index line %d names table %s, which the export summary does not list.', $state['n'], $data['t'] ) );
				}
				if ( $data['t'] !== (string) $tables[ $state['table'] ]['name'] ) {
					throw new \RuntimeException( sprintf( 'Database index line %d names table %s where the export summary expects %s.', $state['n'], $data['t'], (string) $tables[ $state['table'] ]['name'] ) );
				}
				if ( $data['c'] !== (int) $state['chunk'] + 1 ) {
					throw new \RuntimeException( sprintf( 'Database index line %d is chunk %d of table %s where chunk %d was expected.', $state['n'], $data['c'], $data['t'], (int) $state['chunk'] + 1 ) );
				}
				if ( 0 === (int) $state['chunk'] ) {
					$state['table_offset'] = $start;
				}
				++$state['chunk'];
				if ( $lines >= self::LINES_PER_UNIT || $read >= self::BYTES_PER_UNIT ) {
					return $state;
				}
				$line = fgets( $handle, IndexLine::MAX_LINE_BYTES + 2 );
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
		$state = self::skip_empty_tables( $tables, $state, (int) $state['offset'] );
		if ( $state['table'] < count( $tables ) && ( (int) $state['chunk'] > 0 || ! Manifest::is_empty_table( $tables[ $state['table'] ] ) ) ) {
			self::close_table( $path, $tables[ $state['table'] ], (int) $state['table_offset'], (int) $state['n'], $chunk_bytes, (int) $state['chunk'] );
			++$state['table'];
			$state['chunk'] = 0;
			$state          = self::skip_empty_tables( $tables, $state, (int) $state['offset'] );
		}
		if ( count( $tables ) !== (int) $state['table'] ) {
			throw new \RuntimeException( sprintf( 'The database index ends after %d of %d tables of the export summary.', (int) $state['table'], count( $tables ) ) );
		}
		$state['done'] = true;
		return $state;
	}

	/**
	 * Read the whole database index in lockstep with the summary.
	 *
	 * @param string                                                       $path        Index file.
	 * @param array<int, array{name: string, chunks: int, sha256: string}> $tables      Tables from database.summary.json, in order.
	 * @param int                                                          $chunk_bytes Chunk size.
	 * @return int Lines read.
	 * @throws \RuntimeException When the index and the summary disagree.
	 */
	public static function database( string $path, array $tables, int $chunk_bytes ): int {
		$state = self::database_start();
		while ( ! $state['done'] ) {
			$state = self::database_step( $path, $tables, $state, $chunk_bytes );
		}
		return (int) $state['n'];
	}

	/**
	 * Tables with no chunks have no lines: step over them, as the reader
	 * does (Manifest::is_empty_table(), the one definition). Only when no
	 * chunk of the current table has been read yet.
	 *
	 * @param array<int, array{name: string, chunks: int, sha256: string}> $tables Tables.
	 * @param array<string, mixed>                                         $state  State.
	 * @param int                                                          $offset Offset the next table would start at.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When a table declares no chunks but bytes or a hash.
	 */
	private static function skip_empty_tables( array $tables, array $state, int $offset ): array {
		$total = count( $tables );
		while ( 0 === (int) $state['chunk'] && $state['table'] < $total && 0 === (int) $tables[ $state['table'] ]['chunks'] ) {
			if ( ! Manifest::is_empty_table( $tables[ $state['table'] ] ) ) {
				throw new \RuntimeException( sprintf( 'Table %s declares no chunks but bytes or a hash in the export summary.', (string) $tables[ $state['table'] ]['name'] ) );
			}
			++$state['table'];
			$state['table_offset'] = $offset;
		}
		return $state;
	}

	/**
	 * A table's chunks are complete and their list hash is the summary's:
	 * its lines are read again from the table's first line.
	 *
	 * @param string                                           $path        Index file.
	 * @param array{name: string, chunks: int, sha256: string} $table       Summary entry.
	 * @param int                                              $offset      Byte offset of the table's first line.
	 * @param int                                              $line        Current line (for the message).
	 * @param int                                              $chunk_bytes Chunk size.
	 * @param int|null                                         $seen        Chunks seen when the index ended inside the table (null: the summary's count was reached).
	 * @return void
	 * @throws \RuntimeException When they disagree.
	 */
	private static function close_table( string $path, array $table, int $offset, int $line, int $chunk_bytes, $seen = null ): void {
		$expected = (int) $table['chunks'];
		if ( null !== $seen && $seen !== $expected ) {
			throw new \RuntimeException( sprintf( 'Table %s has %d chunks in the database index but %d in the export summary (at line %d).', (string) $table['name'], $seen, $expected, $line ) );
		}
		$hashes = array();
		$got    = 0;
		$handle = self::open( $path, 'The database index is missing; the work directory was lost or changed.', $offset );
		try {
			while ( $got < $expected ) {
				$text = fgets( $handle, IndexLine::MAX_LINE_BYTES + 2 );
				if ( false === $text ) {
					break;
				}
				$text = rtrim( $text, "\r\n" );
				if ( '' === $text ) {
					continue;
				}
				$hashes[] = IndexLine::database( $text, $chunk_bytes )['h'];
				++$got;
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
		if ( count( $hashes ) !== $expected ) {
			throw new \RuntimeException( sprintf( 'Table %s has %d chunks in the database index but %d in the export summary (at line %d).', (string) $table['name'], count( $hashes ), $expected, $line ) );
		}
		if ( ChunkHasher::list_hash( $hashes ) !== (string) $table['sha256'] ) {
			throw new \RuntimeException( sprintf( 'The chunk hashes of table %s in the database index do not add up to the export summary\'s list hash.', (string) $table['name'] ) );
		}
	}

	/**
	 * Open an index at an offset.
	 *
	 * @param string $path    Index file.
	 * @param string $missing Message when it is not there.
	 * @param int    $offset  Byte offset.
	 * @return resource
	 * @throws \RuntimeException When the file is missing or cannot be positioned.
	 * @throws WorkLost When the work directory was lost, changed or damaged.
	 */
	private static function open( string $path, string $missing, int $offset ) {
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- the job's own index; a missing file is thrown below.
		if ( false === $handle ) {
			throw new WorkLost( $missing );
		}
		if ( 0 !== fseek( $handle, $offset ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
			throw new WorkLost( 'An index could not be positioned; the work directory was changed.' );
		}
		return $handle;
	}
}
