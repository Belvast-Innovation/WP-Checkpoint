<?php
/**
 * The search and replacement pairs, in every encoding a value may hold them in.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Replace;

defined( 'ABSPATH' ) || exit;

/**
 * A pair is looked for as written and in the encodings a stored value may
 * carry it in, each replaced by the replacement in the same encoding:
 * JSON with escaped slashes (`https:\/\/…`, as the block editor and page
 * builders store it), JSON inside JSON (`https:\\\/\\\/…`) and URL encoding
 * (upper- and lowercase hexadecimal). JSON is never decoded and encoded
 * again: that would change the formatting and escaping of everything else in
 * the value. A pair therefore may not contain a double quote, a backslash or
 * a control character, which would need escaping differently in each form.
 *
 * A match counts only on a boundary: what follows it (a letter or digit, or
 * dots, dashes, underscores or tildes followed by one) and, when the needle
 * starts with a letter or digit, the byte before it must not continue a
 * host name or a path segment, so `old.example` is not found inside
 * `old.example.au`, `old.example-staging.net` or `cold.example`, but is at
 * the end of a sentence (`old.example.`). Replacement
 * runs once, left to right, the longest needle first where several start at
 * the same byte; replaced text is never searched again.
 *
 * Pure PHP: no WordPress, and no call to unserialize() anywhere.
 */
final class Needles {

	/**
	 * Bytes that continue a host name or a path segment.
	 */
	const CONTINUES = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.-_~';

	/**
	 * Needle and replacement pairs in every form, longest needle first.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private $forms = array();

	/**
	 * Constructor.
	 *
	 * @param array<int, array{0: string, 1: string}> $pairs Search and replacement, as written.
	 * @throws \InvalidArgumentException When a pair is empty, cannot be written in every form, or two pairs claim the same needle.
	 */
	public function __construct( array $pairs ) {
		$seen = array();
		foreach ( $pairs as $index => $pair ) {
			if ( ! is_array( $pair ) || ! isset( $pair[0], $pair[1] ) || ! is_string( $pair[0] ) || ! is_string( $pair[1] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Pair %d is not a search and a replacement.', (int) $index ) );
			}
			if ( '' === $pair[0] ) {
				throw new \InvalidArgumentException( sprintf( 'Pair %d searches for nothing.', (int) $index ) );
			}
			foreach ( array( $pair[0], $pair[1] ) as $text ) {
				if ( ! self::writable( $text ) ) {
					throw new \InvalidArgumentException( sprintf( 'Pair %d holds a double quote, a backslash or a control character, which cannot be replaced the same way in every encoding.', (int) $index ) );
				}
			}
			foreach ( self::encodings( $pair[0], $pair[1] ) as $form ) {
				if ( isset( $seen[ 'k' . $form[0] ] ) ) {
					if ( $seen[ 'k' . $form[0] ] !== $form[1] ) {
						throw new \InvalidArgumentException( sprintf( 'Pair %d searches for the same text as an earlier pair, with another replacement.', (int) $index ) );
					}
					continue;
				}
				$seen[ 'k' . $form[0] ] = $form[1];
				$this->forms[]          = $form;
			}
		}
		usort(
			$this->forms,
			static function ( array $a, array $b ): int {
				$longer = strlen( $b[0] ) <=> strlen( $a[0] );
				return 0 !== $longer ? $longer : strcmp( $a[0], $b[0] );
			}
		);
	}

	/**
	 * The pairs that move a site: its address in each scheme and without
	 * one, and its directory, each to the new site's.
	 *
	 * @param string $from_url  Old address, with its scheme (https://old.example or https://old.example/sub).
	 * @param string $to_url    New address, with its scheme.
	 * @param string $from_path Old directory, or '' to leave paths alone.
	 * @param string $to_path   New directory.
	 * @return self
	 * @throws \InvalidArgumentException When an address has no http or https scheme.
	 */
	public static function for_move( string $from_url, string $to_url, string $from_path = '', string $to_path = '' ): self {
		$from      = self::without_scheme( $from_url );
		$to        = self::without_scheme( $to_url );
		$to_scheme = substr( $to_url, 0, (int) strpos( $to_url, '//' ) );
		$pairs     = array(
			array( 'https://' . $from, $to_scheme . '//' . $to ),
			array( 'http://' . $from, $to_scheme . '//' . $to ),
			array( '//' . $from, '//' . $to ),
		);
		if ( '' !== $from_path && $from_path !== $to_path ) {
			$pairs[] = array( rtrim( $from_path, '/' ), rtrim( $to_path, '/' ) );
		}
		return new self( $pairs );
	}

	/**
	 * Whether any needle occurs in the bytes (boundaries not checked: a
	 * cheap filter before a value is looked at closely).
	 *
	 * @param string $bytes Bytes.
	 * @return bool
	 */
	public function occurs_in( string $bytes ): bool {
		foreach ( $this->forms as $form ) {
			if ( false !== strpos( $bytes, $form[0] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every needle in every form, longest first (for a pre-filter).
	 *
	 * @return string[]
	 */
	public function needles(): array {
		$out = array();
		foreach ( $this->forms as $form ) {
			$out[] = $form[0];
		}
		return $out;
	}

	/**
	 * The text with every bounded match replaced.
	 *
	 * @param string $text Text (a whole value or a string inside a serialized one).
	 * @return array{0: string, 1: int} New text and the number of replacements.
	 */
	public function replace( string $text ): array {
		$next = array();
		foreach ( $this->forms as $i => $form ) {
			$next[ $i ] = strpos( $text, $form[0] );
		}
		$out   = '';
		$pos   = 0;
		$count = 0;
		while ( true ) {
			$best = -1;
			$at   = PHP_INT_MAX;
			foreach ( $next as $i => $found ) {
				// Forms are sorted longest first: on a tie the earlier index is the longer needle.
				if ( false !== $found && $found < $at ) {
					$at   = $found;
					$best = $i;
				}
			}
			if ( -1 === $best ) {
				break;
			}
			$needle = $this->forms[ $best ][0];
			$end    = $at + strlen( $needle );
			if ( ! self::bounded( $text, $needle, $at, $end ) ) {
				$next[ $best ] = strpos( $text, $needle, $at + 1 );
				continue;
			}
			$out .= substr( $text, $pos, $at - $pos ) . $this->forms[ $best ][1];
			$pos  = $end;
			++$count;
			foreach ( $next as $i => $found ) {
				if ( false !== $found && $found < $pos ) {
					$next[ $i ] = strpos( $text, $this->forms[ $i ][0], $pos );
				}
			}
		}
		return array( $out . substr( $text, $pos ), $count );
	}

	/**
	 * A pair in each encoding it may be stored in; forms equal to an earlier
	 * one are left out.
	 *
	 * @param string $search  Search, as written.
	 * @param string $replace Replacement, as written.
	 * @return array<int, array{0: string, 1: string}>
	 */
	private static function encodings( string $search, string $replace ): array {
		$slash  = '\\/';
		$slash2 = '\\\\\\/';
		$forms  = array(
			array( $search, $replace ),
			array( str_replace( '/', $slash, $search ), str_replace( '/', $slash, $replace ) ),
			array( str_replace( '/', $slash2, $search ), str_replace( '/', $slash2, $replace ) ),
			array( rawurlencode( $search ), rawurlencode( $replace ) ),
			array( self::lower_hex( rawurlencode( $search ) ), self::lower_hex( rawurlencode( $replace ) ) ),
		);
		$out    = array();
		$seen   = array();
		foreach ( $forms as $form ) {
			if ( isset( $seen[ 'k' . $form[0] ] ) ) {
				continue;
			}
			$seen[ 'k' . $form[0] ] = true;
			$out[]                  = $form;
		}
		return $out;
	}

	/**
	 * Percent-escapes with lowercase hexadecimal digits.
	 *
	 * @param string $encoded rawurlencode() output.
	 * @return string
	 */
	private static function lower_hex( string $encoded ): string {
		$out    = '';
		$length = strlen( $encoded );
		for ( $i = 0; $i < $length; $i++ ) {
			if ( '%' === $encoded[ $i ] && $i + 2 < $length ) {
				$out .= '%' . strtolower( $encoded[ $i + 1 ] . $encoded[ $i + 2 ] );
				$i   += 2;
				continue;
			}
			$out .= $encoded[ $i ];
		}
		return $out;
	}

	/**
	 * Whether a match stands on its own: not the middle of a longer host
	 * name or path segment.
	 *
	 * @param string $text   Text.
	 * @param string $needle Needle found.
	 * @param int    $start  Its first byte.
	 * @param int    $end    The byte after it.
	 * @return bool
	 */
	private static function bounded( string $text, string $needle, int $start, int $end ): bool {
		if ( false !== strpos( self::CONTINUES, $needle[ strlen( $needle ) - 1 ] ) ) {
			// Punctuation after the match continues the name only when a letter or digit follows it:
			// "old.example.au" and "old.example-staging" do, a sentence ending in "old.example." does not.
			$after = $end + strspn( $text, '.-_~', $end );
			if ( $after < strlen( $text ) && ctype_alnum( $text[ $after ] ) ) {
				return false;
			}
		}
		if ( $start > 0 && false !== strpos( self::CONTINUES, $needle[0] ) && false !== strpos( self::CONTINUES, $text[ $start - 1 ] ) ) {
			// A percent-escape right before ("%2F" of an encoded slash) ends a segment too.
			return $start >= 3 && '%' === $text[ $start - 3 ] && ctype_xdigit( $text[ $start - 2 ] . $text[ $start - 1 ] );
		}
		return true;
	}

	/**
	 * Whether a text can be written the same way in every form.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	private static function writable( string $text ): bool {
		$length = strlen( $text );
		for ( $i = 0; $i < $length; $i++ ) {
			$byte = ord( $text[ $i ] );
			if ( $byte < 0x20 || 0x7f === $byte || 0x22 === $byte || 0x5c === $byte ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * An address without its scheme and trailing slash.
	 *
	 * @param string $url Address.
	 * @return string
	 * @throws \InvalidArgumentException When it has no http or https scheme.
	 */
	private static function without_scheme( string $url ): string {
		foreach ( array( 'https://', 'http://' ) as $scheme ) {
			if ( 0 === strpos( $url, $scheme ) && strlen( $url ) > strlen( $scheme ) ) {
				return rtrim( substr( $url, strlen( $scheme ) ), '/' );
			}
		}
		throw new \InvalidArgumentException( 'An address to move from or to needs an http or https scheme.' );
	}
}
