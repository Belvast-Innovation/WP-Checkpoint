<?php
/**
 * A byte count as text for the screens.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The size_format() of WordPress, for counts that may not fit this platform's integer: free
 * disk space and the sizes of large sites are floats on 32-bit PHP, and an
 * (int) cast there turns them into a wrong number, or a negative one that
 * size_format() does not show at all. WordPress's own advice for sizes beyond an integer
 * is to pass them as a string.
 */
final class Bytes {

	/**
	 * The count as "1.2 GB".
	 *
	 * @param int|float|string $bytes    Count of bytes.
	 * @param int              $decimals Decimals.
	 * @return string '' when it cannot be shown (negative, not a number).
	 */
	public static function text( $bytes, int $decimals = 1 ): string {
		if ( ! is_numeric( $bytes ) || (float) $bytes < 0 ) {
			return '';
		}
		// Below PHP_INT_MAX as a float (2^63 on 64-bit, rounded up) every value converts exactly.
		$value = (float) $bytes < PHP_INT_MAX ? (int) $bytes : sprintf( '%.0f', (float) $bytes );
		$text  = size_format( $value, $decimals );
		return is_string( $text ) ? $text : '';
	}
}
