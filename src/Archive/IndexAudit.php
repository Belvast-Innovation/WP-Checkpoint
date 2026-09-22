<?php
/**
 * The checks a manifest must pass before it is written.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Archive;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages carry line numbers, table names and counts; the runner stores them through the redactor.

/**
 * Two streaming audits of the side indexes, run by the manifest step
 * before it writes anything: every line of the files index must carry a
 * content hash (an archive without them would pass verification on sizes
 * alone, a silent downgrade), and the database index must be in lockstep
 * with the export summary (the same tables in the same order, chunks
 * numbered from 1 with nothing missing). Either failing fails the job.
 */
final class IndexAudit {

	/**
	 * Read the files index line by line through IndexLine::files() and
	 * require a hash on every line.
	 *
	 * @param string $path        Index file.
	 * @param int    $chunk_bytes Content chunk size the lines were made with.
	 * @return array{count: int, bytes: int}
	 * @throws \RuntimeException When a line is malformed or has no hash, with its number.
	 */
	public static function files( string $path, int $chunk_bytes ): array {
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- the job's own index; a missing file is thrown below.
		if ( false === $handle ) {
			throw new \RuntimeException( 'The files index is missing; the work directory was lost or changed.' );
		}
		$count = 0;
		$bytes = 0;
		$n     = 0;
		try {
			$line = fgets( $handle );
			while ( false !== $line ) {
				++$n;
				$text = rtrim( $line, "\r\n" );
				if ( '' !== $text ) {
					try {
						$data = IndexLine::files( $text, $chunk_bytes );
					} catch ( IndexLineError $e ) {
						throw new \RuntimeException( sprintf( 'Files index line %d is malformed (%s); the archive cannot be described.', $n, $e->getMessage() ) );
					}
					if ( null === $data['h'] ) {
						throw new \RuntimeException( sprintf( 'Files index line %d has no content hash; an archive without content hashes cannot be verified and is not written.', $n ) );
					}
					++$count;
					$bytes += (int) $data['b'];
				}
				$line = fgets( $handle );
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
		return array(
			'count' => $count,
			'bytes' => $bytes,
		);
	}

	/**
	 * Read the database index in lockstep with the summary's tables: the
	 * lines must name the tables in the summary's order, each table's
	 * chunks numbered 1..n consecutively with n the summary's chunk count.
	 *
	 * @param string                                                       $path        Index file.
	 * @param array<int, array{name: string, chunks: int, sha256: string}> $tables      Tables from database.summary.json, in order.
	 * @param int                                                          $chunk_bytes Chunk size.
	 * @return int Lines read.
	 * @throws \RuntimeException When the index and the summary disagree.
	 */
	public static function database( string $path, array $tables, int $chunk_bytes ): int {
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- see above.
		if ( false === $handle ) {
			throw new \RuntimeException( 'The database index is missing; the work directory was lost or changed.' );
		}
		$table  = 0;
		$chunk  = 0;
		$hashes = array();
		$n      = 0;
		try {
			$line = fgets( $handle );
			while ( false !== $line ) {
				++$n;
				$text = rtrim( $line, "\r\n" );
				if ( '' === $text ) {
					$line = fgets( $handle );
					continue;
				}
				try {
					$data = IndexLine::database( $text, $chunk_bytes );
				} catch ( IndexLineError $e ) {
					throw new \RuntimeException( sprintf( 'Database index line %d is malformed (%s).', $n, $e->getMessage() ) );
				}
				if ( $table < count( $tables ) && $chunk >= (int) $tables[ $table ]['chunks'] ) {
					self::close_table( $tables[ $table ], $hashes, $n );
					++$table;
					$chunk  = 0;
					$hashes = array();
				}
				if ( $table >= count( $tables ) ) {
					throw new \RuntimeException( sprintf( 'Database index line %d names table %s, which the export summary does not list.', $n, $data['t'] ) );
				}
				if ( $data['t'] !== (string) $tables[ $table ]['name'] ) {
					throw new \RuntimeException( sprintf( 'Database index line %d names table %s where the export summary expects %s.', $n, $data['t'], (string) $tables[ $table ]['name'] ) );
				}
				if ( $data['c'] !== $chunk + 1 ) {
					throw new \RuntimeException( sprintf( 'Database index line %d is chunk %d of table %s where chunk %d was expected.', $n, $data['c'], $data['t'], $chunk + 1 ) );
				}
				++$chunk;
				$hashes[] = $data['h'];
				$line     = fgets( $handle );
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
		if ( $table < count( $tables ) ) {
			self::close_table( $tables[ $table ], $hashes, $n );
			++$table;
		}
		if ( count( $tables ) !== $table ) {
			throw new \RuntimeException( sprintf( 'The database index ends after %d of %d tables of the export summary.', $table, count( $tables ) ) );
		}
		return $n;
	}

	/**
	 * A table's chunks are complete and their list hash is the summary's.
	 *
	 * @param array{name: string, chunks: int, sha256: string} $table  Summary entry.
	 * @param string[]                                         $hashes Chunk hashes read.
	 * @param int                                              $line   Current line (for the message).
	 * @return void
	 * @throws \RuntimeException When they disagree.
	 */
	private static function close_table( array $table, array $hashes, int $line ): void {
		if ( count( $hashes ) !== (int) $table['chunks'] ) {
			throw new \RuntimeException( sprintf( 'Table %s has %d chunks in the database index but %d in the export summary (at line %d).', (string) $table['name'], count( $hashes ), (int) $table['chunks'], $line ) );
		}
		if ( ChunkHasher::list_hash( $hashes ) !== (string) $table['sha256'] ) {
			throw new \RuntimeException( sprintf( 'The chunk hashes of table %s in the database index do not add up to the export summary\'s list hash.', (string) $table['name'] ) );
		}
	}
}
