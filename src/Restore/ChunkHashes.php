<?php
/**
 * The content chunks' hashes of bytes as they are read.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * Pure PHP. The bytes of a file, from $start on, are hashed chunk by chunk
 * (the manifest's chunk size, chunks counted from the file's start) as
 * they pass; a chunk is complete at its end or at the end of the file.
 * mismatch() compares the complete chunks with the index line: each with
 * its chunk hash when the line has a list, the whole file with the line's
 * hash when the file is one chunk, nothing when the line has no hash.
 */
final class ChunkHashes {

	/**
	 * Position in the file of the next byte.
	 *
	 * @var int
	 */
	private $position;

	/**
	 * Chunk size.
	 *
	 * @var int
	 */
	private $chunk_bytes;

	/**
	 * The file's size.
	 *
	 * @var int
	 */
	private $size;

	/**
	 * The running hash of the current chunk.
	 *
	 * @var \HashContext|null
	 */
	private $context;

	/**
	 * Complete chunks: number => hex hash.
	 *
	 * @var array<int, string>
	 */
	private $done = array();

	/**
	 * Constructor.
	 *
	 * @param int $start       Position of the first byte (a chunk boundary).
	 * @param int $chunk_bytes Chunk size.
	 * @param int $size        The file's size.
	 * @throws \InvalidArgumentException When $start is not a chunk boundary.
	 */
	public function __construct( int $start, int $chunk_bytes, int $size ) {
		if ( $chunk_bytes < 1 || $start < 0 || 0 !== $start % $chunk_bytes ) {
			throw new \InvalidArgumentException( 'Hashing starts at a chunk boundary.' );
		}
		$this->position    = $start;
		$this->chunk_bytes = $chunk_bytes;
		$this->size        = $size;
		if ( 0 === $size ) {
			$this->done[0] = hash( 'sha256', '' );
		}
	}

	/**
	 * Hash the next bytes.
	 *
	 * @param string $bytes Bytes.
	 * @return void
	 */
	public function update( string $bytes ): void {
		while ( '' !== $bytes ) {
			if ( null === $this->context ) {
				$this->context = hash_init( 'sha256' );
			}
			$room  = $this->chunk_bytes - $this->position % $this->chunk_bytes;
			$piece = substr( $bytes, 0, $room );
			$bytes = (string) substr( $bytes, strlen( $piece ) );
			hash_update( $this->context, $piece );
			$this->position += strlen( $piece );
			if ( 0 === $this->position % $this->chunk_bytes || $this->position >= $this->size ) {
				$this->done[ intdiv( $this->position - 1, $this->chunk_bytes ) ] = hash_final( $this->context );
				$this->context = null;
			}
		}
	}

	/**
	 * What does not match the index line, or null.
	 *
	 * @param array{h?: string|null, hc?: string[]|null, b: int} $line Files index line.
	 * @return string|null
	 */
	public function mismatch( array $line ) {
		$list = $line['hc'] ?? null;
		$hash = $line['h'] ?? null;
		foreach ( $this->done as $number => $actual ) {
			if ( is_array( $list ) ) {
				$expected = $list[ $number ] ?? '';
			} elseif ( null !== $hash && 0 === $number ) {
				$expected = $hash;
			} else {
				continue;
			}
			if ( ! hash_equals( (string) $expected, $actual ) ) {
				return sprintf( 'content chunk %d does not match its hash', $number + 1 );
			}
		}
		return null;
	}
}
