<?php
/**
 * One line of a sidecar index, validated by hand.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Archive;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- IndexLineError messages carry a key name and fixed text, never HTML; the verifier turns them into findings whose texts go through JobPresenter::clean() and the caller's escaping.

/**
 * Line shapes:
 * database.index.jsonl: {"t":table,"c":chunk,"p":"database/<t>.<c>.sql","b":bytes,"h":sha256}
 * files.index.jsonl:    {"p":"wp-content/...","b":bytes,"m":mtime,"h"?:sha256,"hc"?:[sha256...]}
 *
 * Same discipline as the manifest: decoded once as an object, every known
 * key type-checked, unknown keys ignored, paths through EntryPath, sizes
 * bounded, the chunk list present exactly when the size exceeds the chunk
 * size, and the list hash checked against "h". A line that fails means the
 * index is damaged.
 */
final class IndexLine {

	/**
	 * Longest index line. It bounds the chunk list of one file and so the
	 * largest file the format can index (max_indexable_bytes()); the
	 * writer and the verifier read lines with the same constant. One line
	 * of this size decodes well within a unit's memory budget (tested).
	 */
	const MAX_LINE_BYTES = 1048576;
	const MAX_DEPTH      = 3;

	/**
	 * Bytes one chunk hash takes in a line: the quoted hex digest and a comma.
	 */
	const HASH_ITEM_BYTES = 67;

	/**
	 * Bytes of a files line without its chunk hashes: the longest path
	 * (EntryPath::MAX_BYTES) plus exactly 128 bytes of keys, punctuation,
	 * two 16-digit numbers and the list hash (the last hash has no comma,
	 * which pays for the closing brackets).
	 */
	const LINE_OVERHEAD_BYTES = EntryPath::MAX_BYTES + 128;

	/**
	 * Chunk hashes one line can carry with the longest path.
	 *
	 * @return int
	 */
	public static function max_chunks(): int {
		return intdiv( self::MAX_LINE_BYTES - self::LINE_OVERHEAD_BYTES, self::HASH_ITEM_BYTES );
	}

	/**
	 * The largest file whose chunk list fits one line: max_chunks() chunks
	 * of chunk_bytes. Larger files cannot be described by the format and
	 * the scan lists them as too large before anything is packed.
	 *
	 * @param int $chunk_bytes Content chunk size.
	 * @return int PHP_INT_MAX when the product does not fit the platform integer (32-bit PHP; the entry limit is lower there anyway).
	 */
	public static function max_indexable_bytes( int $chunk_bytes ): int {
		if ( $chunk_bytes > intdiv( PHP_INT_MAX, self::max_chunks() ) ) {
			return PHP_INT_MAX;
		}
		return self::max_chunks() * $chunk_bytes;
	}

	/**
	 * Parse a database index line.
	 *
	 * @param string $line        Line without its newline.
	 * @param int    $chunk_bytes Content chunk size from the manifest.
	 * @return array{t: string, c: int, p: string, b: int, h: string}
	 * @throws IndexLineError When the line is not acceptable.
	 */
	public static function database( string $line, int $chunk_bytes ): array {
		$data = self::decode( $line );
		$t    = self::string( $data, 't', Manifest::MAX_TABLE_NAME );
		if ( '' === $t || 1 === preg_match( '/[\x00-\x1F\x7F]/', $t ) ) {
			throw new IndexLineError( 't', 'Invalid table name.' );
		}
		$c = self::int( $data, 'c', 1, Manifest::MAX_TABLE_CHUNKS );
		$p = self::path( $data, 'p' );
		if ( self::database_path( $t, $c ) !== $p ) {
			throw new IndexLineError( 'p', 'A database chunk is named database/<table>.<chunk>.sql.' );
		}
		$b = self::int( $data, 'b', 0, $chunk_bytes );
		return array(
			't' => $t,
			'c' => $c,
			'p' => $p,
			'b' => $b,
			'h' => self::hash( $data, 'h' ),
		);
	}

	/**
	 * The entry path of a table chunk: database/<table>.<chunk>.sql with the
	 * chunk number zero-padded to four digits (wider when it needs more).
	 *
	 * @param string $table Table name.
	 * @param int    $chunk Chunk number, 1-based.
	 * @return string
	 */
	public static function database_path( string $table, int $chunk ): string {
		return 'database/' . $table . '.' . sprintf( '%04d', $chunk ) . '.sql';
	}

	/**
	 * Parse a files index line.
	 *
	 * @param string $line        Line without its newline.
	 * @param int    $chunk_bytes Content chunk size from the manifest.
	 * @return array{p: string, b: int, m: int, h: string|null, hc: string[]|null}
	 * @throws IndexLineError When the line is not acceptable.
	 */
	public static function files( string $line, int $chunk_bytes ): array {
		$data = self::decode( $line );
		$p    = self::path( $data, 'p' );
		$b    = self::int( $data, 'b', 0, Manifest::MAX_BYTES );
		$m    = self::int( $data, 'm', 0, Manifest::MAX_BYTES );
		$h    = array_key_exists( 'h', $data ) ? self::hash( $data, 'h' ) : null;
		$hc   = null;
		if ( array_key_exists( 'hc', $data ) ) {
			if ( null === $h ) {
				throw new IndexLineError( 'hc', 'A chunk list needs a list hash in "h".' );
			}
			if ( $b <= $chunk_bytes ) {
				throw new IndexLineError( 'hc', 'Content of at most chunk_bytes has no chunk list.' );
			}
			$list = $data['hc'];
			if ( ! is_array( $list ) || count( $list ) !== ChunkHasher::chunk_count( $b, $chunk_bytes ) ) {
				throw new IndexLineError( 'hc', 'The chunk list does not match the size.' );
			}
			$hc = array();
			foreach ( $list as $i => $value ) {
				if ( ! is_string( $value ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $value ) ) {
					throw new IndexLineError( "hc[{$i}]", 'Not a lowercase hex SHA-256.' );
				}
				$hc[] = $value;
			}
			if ( ChunkHasher::list_hash( $hc ) !== $h ) {
				throw new IndexLineError( 'h', 'The list hash does not match the chunk list.' );
			}
		} elseif ( null !== $h && $b > $chunk_bytes ) {
			throw new IndexLineError( 'hc', 'Content larger than chunk_bytes must carry its chunk hashes.' );
		}
		return array(
			'p'  => $p,
			'b'  => $b,
			'm'  => $m,
			'h'  => $h,
			'hc' => $hc,
		);
	}

	/**
	 * Decode one line into an array (from a stdClass).
	 *
	 * @param string $line Line.
	 * @return array<string, mixed>
	 * @throws IndexLineError When the line is not a JSON object.
	 */
	private static function decode( string $line ): array {
		if ( '' === $line || strlen( $line ) > self::MAX_LINE_BYTES ) {
			throw new IndexLineError( '', 'Empty or over-long line.' );
		}
		$object = json_decode( $line, false, self::MAX_DEPTH );
		if ( ! $object instanceof \stdClass || JSON_ERROR_NONE !== json_last_error() ) {
			throw new IndexLineError( '', 'Not a JSON object.' );
		}
		return (array) $object;
	}

	/**
	 * A required bounded string.
	 *
	 * @param array<string, mixed> $data Line data.
	 * @param string               $key  Key.
	 * @param int                  $max  Maximum bytes.
	 * @return string
	 * @throws IndexLineError When missing or not a string.
	 */
	private static function string( array $data, string $key, int $max ): string {
		if ( ! array_key_exists( $key, $data ) ) {
			throw new IndexLineError( $key, 'Missing.' );
		}
		if ( ! is_string( $data[ $key ] ) || strlen( $data[ $key ] ) > $max || false !== strpos( $data[ $key ], "\0" ) ) {
			throw new IndexLineError( $key, 'Not a string of the allowed length.' );
		}
		return $data[ $key ];
	}

	/**
	 * A required integer in range.
	 *
	 * @param array<string, mixed> $data Line data.
	 * @param string               $key  Key.
	 * @param int                  $min  Minimum.
	 * @param int                  $max  Maximum.
	 * @return int
	 * @throws IndexLineError When missing, not an integer or out of range.
	 */
	private static function int( array $data, string $key, int $min, int $max ): int {
		if ( ! array_key_exists( $key, $data ) ) {
			throw new IndexLineError( $key, 'Missing.' );
		}
		$kind = Manifest::classify_integer( $data[ $key ] );
		if ( Manifest::INTEGER_TOO_LARGE_FOR_PLATFORM === $kind ) {
			throw new IndexLineError( $key, 'This value is larger than the 32-bit PHP on this server can handle.' );
		}
		if ( Manifest::INTEGER !== $kind || $data[ $key ] < $min || $data[ $key ] > $max ) {
			throw new IndexLineError( $key, 'Not an integer in range.' );
		}
		return (int) $data[ $key ];
	}

	/**
	 * A required relative path.
	 *
	 * @param array<string, mixed> $data Line data.
	 * @param string               $key  Key.
	 * @return string
	 * @throws IndexLineError When the path could escape.
	 */
	private static function path( array $data, string $key ): string {
		$value   = self::string( $data, $key, EntryPath::MAX_BYTES );
		$problem = EntryPath::problem( $value );
		if ( null !== $problem ) {
			throw new IndexLineError( $key, $problem );
		}
		return $value;
	}

	/**
	 * A required lowercase hex SHA-256.
	 *
	 * @param array<string, mixed> $data Line data.
	 * @param string               $key  Key.
	 * @return string
	 * @throws IndexLineError When not a hash.
	 */
	private static function hash( array $data, string $key ): string {
		if ( ! array_key_exists( $key, $data ) || ! is_string( $data[ $key ] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $data[ $key ] ) ) {
			throw new IndexLineError( $key, 'Not a lowercase hex SHA-256.' );
		}
		return $data[ $key ];
	}
}
