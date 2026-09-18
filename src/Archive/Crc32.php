<?php
/**
 * CRC-32 that can be continued across ticks.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Archive;

/**
 * A zip entry needs the CRC-32 of its whole data, but the data is written
 * in pieces across ticks and PHP cannot persist a hash context. The CRC of
 * each piece comes from hash('crc32b') at C speed; combine() folds it into
 * the running value with zlib's GF(2) matrix method, a few hundred
 * operations per piece, and the running value (one integer) goes into the
 * cursor.
 *
 * Every intermediate value is a 32-bit quantity kept in a PHP int. On 64-bit
 * PHP it is the unsigned value; on 32-bit PHP (PHP_INT_SIZE 4) it is the
 * signed two's-complement view, because 0xFFFFFFFF and hexdec('ffffffff')
 * are floats there and a sign-extending right shift would never reach
 * zero. The $int_size parameter lets the tests run the 32-bit arithmetic
 * on a 64-bit machine.
 */
final class Crc32 {

	/**
	 * Reversed polynomial 0xEDB88320 as a signed 32-bit value (an int on every platform).
	 */
	const POLY_SIGNED = -306674912;

	/**
	 * CRC-32 of a string.
	 *
	 * @param string $data     Data.
	 * @param int    $int_size PHP_INT_SIZE to compute for (tests inject 4).
	 * @return int
	 */
	public static function of( string $data, int $int_size = PHP_INT_SIZE ): int {
		$unpacked = unpack( 'N', hash( 'crc32b', $data, true ) );
		return self::norm( (int) $unpacked[1], $int_size );
	}

	/**
	 * CRC-32 of the concatenation of two blocks from their CRCs and the
	 * length of the second block (zlib's crc32_combine).
	 *
	 * @param int $crc1     CRC of the first block.
	 * @param int $crc2     CRC of the second block.
	 * @param int $len2     Length of the second block in bytes.
	 * @param int $int_size PHP_INT_SIZE to compute for (tests inject 4).
	 * @return int
	 */
	public static function combine( int $crc1, int $crc2, int $len2, int $int_size = PHP_INT_SIZE ): int {
		$crc1 = self::norm( $crc1, $int_size );
		$crc2 = self::norm( $crc2, $int_size );
		if ( $len2 <= 0 ) {
			return $crc1;
		}
		// Operator matrix for one zero bit.
		$odd    = array_fill( 0, 32, 0 );
		$odd[0] = self::norm( self::POLY_SIGNED, $int_size );
		$row    = 1;
		for ( $n = 1; $n < 32; $n++ ) {
			$odd[ $n ] = $row;
			$row       = self::shl1( $row, $int_size );
		}
		$even = self::square( $odd, $int_size );   // Two zero bits.
		$odd  = self::square( $even, $int_size );  // Four zero bits.
		do {
			$even = self::square( $odd, $int_size );
			if ( 1 === ( $len2 & 1 ) ) {
				$crc1 = self::times( $even, $crc1, $int_size );
			}
			$len2 >>= 1;
			if ( 0 === $len2 ) {
				break;
			}
			$odd = self::square( $even, $int_size );
			if ( 1 === ( $len2 & 1 ) ) {
				$crc1 = self::times( $odd, $crc1, $int_size );
			}
			$len2 >>= 1;
		} while ( 0 !== $len2 );
		return self::norm( $crc1 ^ $crc2, $int_size );
	}

	/**
	 * Lowercase hex of a CRC value (8 digits on every platform).
	 *
	 * @param int $crc CRC.
	 * @return string
	 */
	public static function hex( int $crc ): string {
		if ( PHP_INT_SIZE >= 8 ) {
			return sprintf( '%08x', $crc & 0xFFFFFFFF );
		}
		return sprintf( '%08x', $crc );
	}

	/**
	 * Four little-endian bytes of a CRC value, as stored in a zip header.
	 *
	 * @param int $crc CRC.
	 * @return string
	 */
	public static function pack( int $crc ): string {
		return pack( 'V', $crc );
	}

	/**
	 * Matrix times vector over GF(2).
	 *
	 * @param int[] $mat      32 rows.
	 * @param int   $vec      Vector.
	 * @param int   $int_size Platform.
	 * @return int
	 */
	private static function times( array $mat, int $vec, int $int_size ): int {
		$sum = 0;
		$i   = 0;
		while ( 0 !== $vec ) {
			if ( 1 === ( $vec & 1 ) ) {
				$sum ^= $mat[ $i ];
			}
			$vec = self::shr1( $vec );
			++$i;
		}
		return self::norm( $sum, $int_size );
	}

	/**
	 * Matrix squared over GF(2).
	 *
	 * @param int[] $mat      32 rows.
	 * @param int   $int_size Platform.
	 * @return int[]
	 */
	private static function square( array $mat, int $int_size ): array {
		$square = array();
		for ( $n = 0; $n < 32; $n++ ) {
			$square[ $n ] = self::times( $mat, $mat[ $n ], $int_size );
		}
		return $square;
	}

	/**
	 * Logical shift right by one (a plain >> would sign-extend a negative 32-bit view forever).
	 *
	 * @param int $v Value.
	 * @return int
	 */
	private static function shr1( int $v ): int {
		return ( $v >> 1 ) & 0x7FFFFFFF;
	}

	/**
	 * Shift left by one within 32 bits.
	 *
	 * @param int $v        Value.
	 * @param int $int_size Platform.
	 * @return int
	 */
	private static function shl1( int $v, int $int_size ): int {
		return self::norm( $v << 1, $int_size );
	}

	/**
	 * Reduce to the platform's 32-bit representation: unsigned on 64-bit
	 * PHP, signed two's complement when computing for 32-bit PHP. On a real
	 * 32-bit PHP every int already is that representation and this is a
	 * no-op; no literal above PHP_INT_MAX is ever evaluated there.
	 *
	 * @param int $v        Value.
	 * @param int $int_size Platform.
	 * @return int
	 */
	private static function norm( int $v, int $int_size ): int {
		if ( PHP_INT_SIZE >= 8 ) {
			$v &= 0xFFFFFFFF;
			if ( $int_size < 8 && $v > 0x7FFFFFFF ) {
				$v -= 0x100000000;
			}
		}
		return $v;
	}
}
