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
		// Integer arithmetic: a float division loses precision above 2^53 and undercounts.
		return intdiv( $bytes - 1, $chunk_bytes ) + 1;
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
			$size = self::size( $handle );
			self::after_size( $path );
			$hashes = array();
			for ( $start = 0; $start < $size; $start += $chunk_bytes ) {
				$hashes[] = self::hash_range( $handle, $start, min( $chunk_bytes, $size - $start ) );
			}
			self::assert_unchanged( $handle, $size );
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
			throw self::read_failure( $path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message, no user data.
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
		if ( $chunk_bytes <= 0 ) {
			throw new \RuntimeException( 'Invalid chunk size.' );
		}
		// One handle for the size and the bytes, and a second look at the size afterwards: a file that
		// is still being written (a process whose lease expired but which is still alive) must not
		// yield a manifest whose bytes and hash do not belong together.
		$handle = self::open( $path );
		try {
			$size = self::size( $handle );
			self::after_size( $path );
			if ( $size <= $chunk_bytes ) {
				$result = array(
					'bytes'  => $size,
					'sha256' => self::hash_range( $handle, 0, $size ),
					'chunks' => null,
				);
			} else {
				$chunks = array();
				for ( $start = 0; $start < $size; $start += $chunk_bytes ) {
					$chunks[] = self::hash_range( $handle, $start, min( $chunk_bytes, $size - $start ) );
				}
				$result = array(
					'bytes'  => $size,
					'sha256' => self::list_hash( $chunks ),
					'chunks' => $chunks,
				);
			}
			self::assert_unchanged( $handle, $size );
			return $result;
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream read.
		}
	}

	/**
	 * Test seam: called right after a size was read, before the bytes are
	 * hashed (a test appends to the file here to simulate a concurrent writer).
	 *
	 * @internal
	 * @var callable|null
	 */
	private static $after_size_hook = null;

	/**
	 * Install or clear the test seam.
	 *
	 * @internal
	 * @param callable|null $hook function( string $path ): void.
	 * @return void
	 */
	public static function set_after_size_hook( $hook ): void {
		self::$after_size_hook = is_callable( $hook ) ? $hook : null;
	}

	/**
	 * Run the test seam.
	 *
	 * @param string $path File.
	 * @return void
	 */
	private static function after_size( string $path ): void {
		if ( null !== self::$after_size_hook ) {
			call_user_func( self::$after_size_hook, $path );
		}
	}

	/**
	 * The file must still have the size that was hashed.
	 *
	 * @param resource $handle Handle.
	 * @param int      $size   Size at the start.
	 * @return void
	 * @throws \RuntimeException When the file changed size meanwhile.
	 */
	private static function assert_unchanged( $handle, int $size ): void {
		clearstatcache();
		if ( self::size( $handle ) !== $size ) {
			throw new \RuntimeException( 'The file changed size while it was being hashed.' );
		}
	}

	/**
	 * Whether one chunk of a file still has the expected hash.
	 *
	 * @param string $path        File.
	 * @param int    $index       Chunk index.
	 * @param int    $chunk_bytes Chunk size.
	 * @param string $expected    Expected lowercase hex.
	 * @return bool False on mismatch or when the chunk cannot be read as the content it claims to be.
	 * @throws EnvironmentFailure When this server cannot read the file at all.
	 */
	public static function verify_chunk( string $path, int $index, int $chunk_bytes, string $expected ): bool {
		try {
			return hash_equals( strtolower( $expected ), self::hash_chunk( $path, $index, $chunk_bytes ) );
		} catch ( EnvironmentFailure $e ) {
			throw $e; // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- rethrown unchanged: this server could not read the file, which says nothing about its content.
		} catch ( \RuntimeException $e ) {
			return false;
		}
	}

	/**
	 * Why a file could not be read: a file that is there but cannot be read
	 * is this server's problem (permissions, storage), not a content mismatch.
	 *
	 * @param string $path File.
	 * @return \RuntimeException
	 */
	private static function read_failure( string $path ): \RuntimeException {
		clearstatcache( true, $path );
		if ( '' !== $path && is_file( $path ) ) {
			return new EnvironmentFailure( 'The file is there, but this server cannot read it (file permissions or storage). The archive itself is not in question.', EnvironmentFailure::ACCESS );
		}
		return new \RuntimeException( 'The file could not be opened for reading.' );
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
			throw self::read_failure( $path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message, no user data.
		}
		// Regular files on the local file system only: no stream wrappers (data:, php:, phar:), no
		// directories, no FIFOs. Callers always join a directory to a validated relative path, so a
		// wrapper prefix cannot reach here in practice; this is the second line of defence.
		$meta  = stream_get_meta_data( $handle );
		$stats = fstat( $handle );
		if ( 'plainfile' !== $meta['wrapper_type'] || ! is_array( $stats ) || ! isset( $stats['mode'] ) || 0100000 !== ( $stats['mode'] & 0170000 ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
			throw new \RuntimeException( 'Not a regular file.' );
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
