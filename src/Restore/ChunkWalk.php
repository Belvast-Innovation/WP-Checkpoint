<?php
/**
 * Walks a backup's database chunks, or its files, in the order of their index, entry by entry.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Archive\ArchiveVerifier;
use WPCheckpoint\Archive\EnvironmentFailure;
use WPCheckpoint\Archive\IndexLine;
use WPCheckpoint\Archive\IndexLineError;
use WPCheckpoint\Archive\ZipReader;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- job errors with table and entry names; the presenter cleans them.
// phpcs:disable WordPress.WP.AlternativeFunctions -- the index file in the job's work directory, read line by line.

/**
 * Lockstep, the only way a restore reads the database of a backup: the
 * volumes hold the chunks in the order of database.index.jsonl, first
 * entry of the first volume onwards, so the n-th line of the index is the
 * n-th entry, and each step reads one line and the next entry of the
 * central directory and requires the entry's name to be the line's path.
 * The format thus has one way to be read (a lookup by name could read the
 * same archive two ways); an archive whose order differs is refused, which
 * the verifier reports as UNSUPPORTED_LAYOUT before a restore starts.
 *
 * The position is plain numbers, kept in the job's cursor: the byte offset
 * of the next index line and its number, the volume and the entry index in
 * it, and the entry's central directory offset (so no step re-reads the
 * directory before it).
 *
 * The files are walked the same way (FILES): files.index.jsonl line by
 * line, the entries after the database's ("files/" + the line's path),
 * from files_start().
 */
final class ChunkWalk {

	/**
	 * The index file (extracted to the work directory).
	 *
	 * @var string
	 */
	private $index;

	/**
	 * Volume paths in order.
	 *
	 * @var string[]
	 */
	private $volumes;

	/**
	 * The manifest's chunk size.
	 *
	 * @var int
	 */
	private $chunk_bytes;

	/**
	 * DATABASE or FILES.
	 *
	 * @var string
	 */
	private $kind;

	const DATABASE = 'database';
	const FILES    = 'files';

	/**
	 * Constructor.
	 *
	 * @param string   $index       Index file.
	 * @param string[] $volumes     Volume paths in order.
	 * @param int      $chunk_bytes The manifest's chunk size.
	 * @param string   $kind        DATABASE (chunks) or FILES.
	 */
	public function __construct( string $index, array $volumes, int $chunk_bytes, string $kind = self::DATABASE ) {
		$this->index       = $index;
		$this->volumes     = array_values( $volumes );
		$this->chunk_bytes = $chunk_bytes;
		$this->kind        = self::FILES === $kind ? self::FILES : self::DATABASE;
	}

	/**
	 * The position before the first chunk.
	 *
	 * @return array{offset: int, line: int, volume: int, entry: int, cd: int}
	 */
	public static function start(): array {
		return array(
			'offset' => 0,
			'line'   => 0,
			'volume' => 0,
			'entry'  => 0,
			'cd'     => -1,
		);
	}

	/**
	 * The position of the first file: the entry after the database's chunks ($chunks of them, the lines of the
	 * database index), counted across the volumes.
	 *
	 * @param int $chunks Database chunks.
	 * @return array{offset: int, line: int, volume: int, entry: int, cd: int}
	 * @throws EnvironmentFailure When a volume cannot be read.
	 * @throws Refused When a volume is not a zip archive.
	 */
	public function files_start( int $chunks ): array {
		$at = self::start();
		foreach ( $this->volumes as $volume => $path ) {
			try {
				$reader = ZipReader::open( $path );
				$count  = $reader->count();
				if ( $chunks < $count ) {
					$at['volume'] = $volume;
					$at['entry']  = $chunks;
					if ( $chunks > 0 ) {
						// The central directory position of that entry, found once (the walk goes on from it).
						$reader->each(
							static function ( array $entry ) use ( $chunks, &$at ): bool {
								if ( (int) $entry['index'] < $chunks ) {
									return true;
								}
								$at['cd'] = (int) $entry['cd_offset'];
								return false;
							}
						);
					}
					return $at;
				}
			} catch ( EnvironmentFailure $e ) {
				throw $e;
			} catch ( \RuntimeException $e ) {
				throw new Refused( sprintf( 'Volume %d of the backup cannot be read as a zip archive.', $volume + 1 ) );
			}
			$chunks -= $count;
		}
		$at['volume'] = count( $this->volumes );
		return $at;
	}

	/**
	 * The chunk (or file) at a position, or null after the last line of the index.
	 *
	 * @param array{offset: int, line: int, volume: int, entry: int, cd: int} $at Position.
	 * @return array{line: array<string, mixed>, entry: array<string, mixed>, reader: ZipReader, next: array{offset: int, line: int, volume: int, entry: int, cd: int}}|null
	 * @throws EnvironmentFailure When the index or a volume cannot be read.
	 * @throws Refused When a line is not acceptable or an entry is not the one the line names.
	 */
	public function at( array $at ) {
		list( $text, $after ) = $this->line( $at['offset'] );
		if ( null === $text ) {
			return null;
		}
		try {
			$line = self::FILES === $this->kind ? IndexLine::files( $text, $this->chunk_bytes ) : IndexLine::database( $text, $this->chunk_bytes );
		} catch ( IndexLineError $e ) {
			throw new Refused( sprintf( 'Line %1$d of the %2$s index is not acceptable: %3$s', $at['line'] + 1, $this->kind, $e->getMessage() ) );
		}
		$name   = self::FILES === $this->kind ? ArchiveVerifier::FILES_PREFIX . $line['p'] : $line['p'];
		$what   = self::FILES === $this->kind ? 'file' : 'database chunk';
		$volume = $at['volume'];
		$index  = $at['entry'];
		$cd     = $at['cd'];
		while ( true ) {
			if ( ! isset( $this->volumes[ $volume ] ) ) {
				throw new Refused( sprintf( 'The volumes end before the %1$s %2$s of the index.', $what, $line['p'] ) );
			}
			try {
				$reader = ZipReader::open( $this->volumes[ $volume ] );
			} catch ( EnvironmentFailure $e ) {
				throw $e;
			} catch ( \RuntimeException $e ) {
				throw new Refused( sprintf( 'Volume %d of the backup cannot be read as a zip archive.', $volume + 1 ) );
			}
			if ( $index < $reader->count() ) {
				break;
			}
			++$volume;
			$index = 0;
			$cd    = -1;
		}
		$entry = null;
		try {
			$reader->each(
				static function ( array $found ) use ( &$entry ): bool {
					$entry = $found;
					return false;
				},
				$index,
				$index > 0 ? $cd : -1
			);
		} catch ( EnvironmentFailure $e ) {
			throw $e;
		} catch ( \RuntimeException $e ) {
			throw new Refused( sprintf( 'The central directory of volume %d cannot be read.', $volume + 1 ) );
		}
		if ( null === $entry || $entry['name'] !== $name ) {
			throw new Refused( sprintf( 'The backup does not hold the %1$s %2$s where its index puts it; its entries are not in the order this plugin writes them.', $what, $line['p'] ) );
		}
		if ( (int) $entry['usize'] !== $line['b'] ) {
			throw new Refused( sprintf( 'The %1$s %2$s is not the size its index says.', $what, $line['p'] ) );
		}
		return array(
			'line'   => $line,
			'entry'  => $entry,
			'reader' => $reader,
			'next'   => array(
				'offset' => $after,
				'line'   => $at['line'] + 1,
				'volume' => $volume,
				'entry'  => $index + 1,
				'cd'     => (int) $entry['cd_next'],
			),
		);
	}

	/**
	 * The index line at a byte offset (without its line break), and the offset after it; null at the end.
	 *
	 * @param int $offset Offset.
	 * @return array{0: string|null, 1: int}
	 * @throws EnvironmentFailure When the index cannot be read.
	 * @throws Refused When a line is longer than IndexLine::MAX_LINE_BYTES or the file ends inside one.
	 */
	private function line( int $offset ): array {
		$handle = @fopen( $this->index, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.
		if ( false === $handle ) {
			throw new EnvironmentFailure( sprintf( 'The %s index extracted for the restore cannot be opened.', $this->kind ) );
		}
		try {
			if ( 0 !== fseek( $handle, $offset ) ) {
				throw new EnvironmentFailure( sprintf( 'The %s index extracted for the restore cannot be positioned for reading.', $this->kind ) );
			}
			$text = fgets( $handle, IndexLine::MAX_LINE_BYTES + 2 );
			if ( false === $text ) {
				if ( ! feof( $handle ) ) {
					throw new EnvironmentFailure( sprintf( 'The %s index extracted for the restore cannot be read.', $this->kind ) );
				}
				return array( null, $offset );
			}
			if ( "\n" !== substr( $text, -1 ) ) {
				throw new Refused( sprintf( 'The %s index has a line that is too long or does not end.', $this->kind ) );
			}
			return array( substr( $text, 0, -1 ), $offset + strlen( $text ) );
		} finally {
			fclose( $handle );
		}
	}
}
