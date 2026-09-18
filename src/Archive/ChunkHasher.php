<?php
/**
 * Chunked SHA-256 for archive contents.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Archive;

/**
 * Hashes a file in fixed chunks so that a job can hash one chunk per unit
 * of work, keep the finished chunk hashes in its cursor and resume after a
 * dead tick; no hash state ever leaves the process. Pure PHP, every read is
 * streamed through a 1 MiB buffer, so memory does not depend on the file.
 *
 * Content hash rule of the archive format: content of at most chunk_bytes
 * is hashed as a whole (equal to sha256sum) and has no chunk list; larger
 * content has a chunk list and its hash is the SHA-256 of the lowercase hex
 * chunk hashes concatenated in order without a separator. The hex form
 * (not the raw digests) is deliberate: it can be reproduced by hand with
 * sha256sum. Changing either rule is a format change.
 */
final class ChunkHasher {

	const ALGORITHM   = 'sha256';
	const READ_BUFFER = 1048576;

	/**
	 * Number of chunks of a content size (0 for empty content).
	 *
	 * @param int $bytes       Content size.
	 * @param int $chunk_bytes Chunk size.
	 * @return int
	 */
	public static function chunk_count( int $bytes, int $chunk_bytes ): int {
		if ( $bytes <= 0 || $chunk_bytes <= 0 ) {
			return 0;
		}
		return (int) ceil( $bytes / $chunk_bytes );
	}

	/**
	 * SHA-256 of one chunk of a file.
	 *
	 * @param string $path        File.
	 * @param int    $index       Chunk index, 0-based.
	 * @param int    $chunk_bytes Chunk size.
	 * @return string Lowercase hex.
	 * @throws \RuntimeException When the file cannot be read or the chunk does not exist.
	 */
	public static function hash_chunk( string $path, int $index, int $chunk_bytes ): string {
		if ( $index < 0 || $chunk_bytes <= 0 ) {
			throw new \RuntimeException( 'Invalid chunk index or size.' );
		}
		$handle = self::open( $path );
		try {
			$size  = self::size( $handle );
			$start = $index * $chunk_bytes;
			if ( $start >= $size ) {
				throw new \RuntimeException( sprintf( 'Chunk %d is beyond the end of the file.', $index ) );
			}
			return self::hash_range( $handle, $start, min( $chunk_bytes, $size - $start ) );
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream read.
		}
	}

	/**
	 * SHA-256 of every chunk of a file, in order.
	 *
	 * @param string $path        File.
	 * @param int    $chunk_bytes Chunk size.
	 * @return string[] Lowercase hex per chunk; empty for an empty file.
	 * @throws \RuntimeException When the file cannot be read.
	 */
	public static function hash_chunks( string $path, int $chunk_bytes ): array {
		if ( $chunk_bytes <= 0 ) {
			throw new \RuntimeException( 'Invalid chunk size.' );
		}
		$handle = self::open( $path );
		try {
			$size   = self::size( $handle );
			$hashes = array();
			for ( $start = 0; $start < $size; $start += $chunk_bytes ) {
				$hashes[] = self::hash_range( $handle, $start, min( $chunk_bytes, $size - $start ) );
			}
			return $hashes;
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream read.
		}
	}

	/**
	 * The list hash: SHA-256 of the hex chunk hashes concatenated in order.
	 *
	 * @param string[] $hex_hashes Chunk hashes, lowercase hex.
	 * @return string
	 */
	public static function list_hash( array $hex_hashes ): string {
		return hash( self::ALGORITHM, implode( '', array_map( 'strtolower', $hex_hashes ) ) );
	}

	/**
	 * SHA-256 of a whole file, streamed.
	 *
	 * @param string $path File.
	 * @return string
	 * @throws \RuntimeException When the file cannot be read.
	 */
	public static function hash_file( string $path ): string {
		$hash = @hash_file( self::ALGORITHM, $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure is thrown below.
		if ( ! is_string( $hash ) ) {
			throw new \RuntimeException( 'The file could not be read.' );
		}
		return $hash;
	}

	/**
	 * The content hash of a file under the format's rule: whole-file hash
	 * without a chunk list up to chunk_bytes, chunk list plus list hash above.
	 *
	 * @param string $path        File.
	 * @param int    $chunk_bytes Chunk size.
	 * @return array{bytes: int, sha256: string, chunks: string[]|null}
	 * @throws \RuntimeException When the file cannot be read.
	 */
	public static function content_hash( string $path, int $chunk_bytes ): array {
		$handle = self::open( $path );
		try {
			$size = self::size( $handle );
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream read.
		}
		if ( $size <= $chunk_bytes ) {
			return array(
				'bytes'  => $size,
				'sha256' => self::hash_file( $path ),
				'chunks' => null,
			);
		}
		$chunks = self::hash_chunks( $path, $chunk_bytes );
		return array(
			'bytes'  => $size,
			'sha256' => self::list_hash( $chunks ),
			'chunks' => $chunks,
		);
	}

	/**
	 * Whether one chunk of a file still has the expected hash.
	 *
	 * @param string $path        File.
	 * @param int    $index       Chunk index.
	 * @param int    $chunk_bytes Chunk size.
	 * @param string $expected    Expected lowercase hex.
	 * @return bool False on mismatch or when the chunk cannot be read.
	 */
	public static function verify_chunk( string $path, int $index, int $chunk_bytes, string $expected ): bool {
		try {
			return hash_equals( strtolower( $expected ), self::hash_chunk( $path, $index, $chunk_bytes ) );
		} catch ( \RuntimeException $e ) {
			return false;
		}
	}

	/**
	 * Open for streamed reading.
	 *
	 * @param string $path File.
	 * @return resource
	 * @throws \RuntimeException When the file cannot be opened.
	 */
	private static function open( string $path ) {
		$handle = '' === $path ? false : @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- stream read; failure is thrown.
		if ( false === $handle ) {
			throw new \RuntimeException( 'The file could not be opened for reading.' );
		}
		return $handle;
	}

	/**
	 * Size of an open file.
	 *
	 * @param resource $handle Handle.
	 * @return int
	 * @throws \RuntimeException When the size is unknown.
	 */
	private static function size( $handle ): int {
		$stats = fstat( $handle );
		if ( ! is_array( $stats ) || ! isset( $stats['size'] ) ) {
			throw new \RuntimeException( 'The file size could not be determined.' );
		}
		return (int) $stats['size'];
	}

	/**
	 * SHA-256 of a byte range, read in READ_BUFFER pieces.
	 *
	 * @param resource $handle Handle.
	 * @param int      $start  Offset.
	 * @param int      $length Bytes.
	 * @return string
	 * @throws \RuntimeException When the range cannot be read completely.
	 */
	private static function hash_range( $handle, int $start, int $length ): string {
		if ( 0 !== fseek( $handle, $start ) ) {
			throw new \RuntimeException( 'The file could not be positioned.' );
		}
		$context   = hash_init( self::ALGORITHM );
		$remaining = $length;
		while ( $remaining > 0 ) {
			$piece = fread( $handle, min( self::READ_BUFFER, $remaining ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- stream read.
			if ( false === $piece || '' === $piece ) {
				throw new \RuntimeException( 'The file ended before the chunk was complete.' );
			}
			hash_update( $context, $piece );
			$remaining -= strlen( $piece );
		}
		return hash_final( $context );
	}
}
