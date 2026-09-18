<?php
/**
 * UTF-8 scrubbing without any dependency that can fail.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

/**
 * Replaces every invalid byte with U+FFFD using a byte-level state machine
 * (RFC 3629: no overlong forms, no surrogates, nothing above U+10FFFF, no
 * truncated sequences). Deliberately not mb_scrub(): its substitute
 * character is a global setting ("?" by default) and mbstring may be
 * missing; not preg-based either, because a failing regex is exactly the
 * problem this class exists to avoid.
 */
final class Utf8 {

	/**
	 * U+FFFD REPLACEMENT CHARACTER, UTF-8 encoded.
	 */
	const REPLACEMENT = "\xEF\xBF\xBD";

	/**
	 * Return valid UTF-8 with every terminal control and bidirectional
	 * override replaced by U+FFFD: C0 controls except tab and newline,
	 * DEL, C1 controls (U+0080-U+009F, which include the 8-bit CSI U+009B
	 * that terminals in UTF-8 mode still honour), the bidi embeddings and
	 * overrides U+202A-U+202E and the isolates U+2066-U+2069, which can
	 * flip the displayed order of a whole line in a ticket or log. Byte
	 * level like scrub(), so it cannot fail; the input must already be
	 * valid UTF-8 (run scrub() first).
	 *
	 * @param string $text Valid UTF-8.
	 * @return string
	 */
	public static function neutralize_controls( string $text ): string {
		$length = strlen( $text );
		$out    = '';
		$i      = 0;
		while ( $i < $length ) {
			$byte = ord( $text[ $i ] );
			if ( $byte < 0x80 ) {
				$out .= ( $byte < 0x20 && 0x09 !== $byte && 0x0A !== $byte ) || 0x7F === $byte ? self::REPLACEMENT : $text[ $i ];
				++$i;
				continue;
			}
			if ( 0xC2 === $byte && $i + 1 < $length && ord( $text[ $i + 1 ] ) <= 0x9F ) {
				// U+0080-U+009F (U+00A0-U+00BF share the lead byte and are ordinary characters).
				$out .= self::REPLACEMENT;
				$i   += 2;
				continue;
			}
			if ( 0xE2 === $byte && $i + 2 < $length ) {
				$second = ord( $text[ $i + 1 ] );
				$third  = ord( $text[ $i + 2 ] );
				if ( ( 0x80 === $second && $third >= 0xAA && $third <= 0xAE ) || ( 0x81 === $second && $third >= 0xA6 && $third <= 0xA9 ) ) {
					$out .= self::REPLACEMENT;
					$i   += 3;
					continue;
				}
			}
			$out .= $text[ $i ];
			++$i;
		}
		return $out;
	}

	/**
	 * Return $text with every invalid byte replaced by U+FFFD.
	 *
	 * @param string $text Bytes that should be UTF-8.
	 * @return string Valid UTF-8.
	 */
	public static function scrub( string $text ): string {
		$length = strlen( $text );
		if ( 0 === $length ) {
			return $text;
		}

		$out = '';
		$i   = 0;
		while ( $i < $length ) {
			$byte = ord( $text[ $i ] );

			if ( $byte < 0x80 ) {
				$out .= $text[ $i ];
				++$i;
				continue;
			}

			// Lead byte: how many continuation bytes follow and the allowed range of the first one.
			if ( $byte >= 0xC2 && $byte <= 0xDF ) {
				$need = 1;
				$low  = 0x80;
				$high = 0xBF;
			} elseif ( 0xE0 === $byte ) {
				$need = 2;
				$low  = 0xA0;
				$high = 0xBF;
			} elseif ( $byte >= 0xE1 && $byte <= 0xEC ) {
				$need = 2;
				$low  = 0x80;
				$high = 0xBF;
			} elseif ( 0xED === $byte ) {
				$need = 2;
				$low  = 0x80;
				$high = 0x9F; // Excludes UTF-16 surrogates U+D800..U+DFFF.
			} elseif ( $byte >= 0xEE && $byte <= 0xEF ) {
				$need = 2;
				$low  = 0x80;
				$high = 0xBF;
			} elseif ( 0xF0 === $byte ) {
				$need = 3;
				$low  = 0x90;
				$high = 0xBF;
			} elseif ( $byte >= 0xF1 && $byte <= 0xF3 ) {
				$need = 3;
				$low  = 0x80;
				$high = 0xBF;
			} elseif ( 0xF4 === $byte ) {
				$need = 3;
				$low  = 0x80;
				$high = 0x8F; // Nothing above U+10FFFF.
			} else {
				// Stray continuation byte, overlong lead (C0, C1) or out-of-range lead (F5..FF).
				$out .= self::REPLACEMENT;
				++$i;
				continue;
			}

			$valid = $i + $need < $length;
			if ( $valid ) {
				$second = ord( $text[ $i + 1 ] );
				$valid  = $second >= $low && $second <= $high;
				for ( $k = 2; $valid && $k <= $need; $k++ ) {
					$next  = ord( $text[ $i + $k ] );
					$valid = $next >= 0x80 && $next <= 0xBF;
				}
			}

			if ( $valid ) {
				$out .= substr( $text, $i, $need + 1 );
				$i   += $need + 1;
			} else {
				// Replace the lead byte only; following bytes are judged on their own.
				$out .= self::REPLACEMENT;
				++$i;
			}
		}

		return $out;
	}

	/**
	 * Scrub every string inside a nested array (keys included).
	 *
	 * @param mixed $value Array, string or scalar.
	 * @return mixed
	 */
	public static function scrub_deep( $value ) {
		if ( is_string( $value ) ) {
			return self::scrub( $value );
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ is_string( $key ) ? self::scrub( $key ) : $key ] = self::scrub_deep( $item );
			}
			return $out;
		}
		return $value;
	}
}
