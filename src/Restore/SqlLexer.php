<?php
/**
 * The pieces of MySQL's lexer that decide where things end in a backup's SQL.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are fixed text with offsets; the importer adds the table and chunk.

/**
 * Pure functions over a buffer and a byte position. Each one either returns
 * the position after what it read, or throws NeedMoreBytes when the buffer
 * ends first (the caller reads more of the file and starts the statement
 * again), or Refused when the bytes cannot be what it reads.
 *
 * The rules are MySQL's with the session the importer sets up (the chunk
 * preamble replaces SQL_MODE, so NO_BACKSLASH_ESCAPES and ANSI_QUOTES are
 * off):
 * - a string is quoted with ' or "; inside, a backslash escapes the next
 *   byte, whatever it is (a quote, another backslash, a semicolon), and a
 *   doubled quote is one quote character, so 'it''s; fine' and 'a\';b'
 *   are each one string and neither semicolon ends anything;
 * - an identifier is quoted with backticks, a doubled backtick is one
 *   backtick character, and a backslash is an ordinary character;
 * - "-- " (two dashes and a space or control character) and "#" start a
 *   comment to the end of the line; "--" followed by anything else is two
 *   minus signs;
 * - "/*" starts a comment to the next "*" "/"; "/*!" followed by a version
 *   (and MariaDB's "/*M!") is a versioned comment whose content the server
 *   executes, so its content is read as SQL here too.
 *
 * Every byte is ASCII-significant or not: the backup is written in UTF-8,
 * latin1 or as hex (SqlWriter), where no byte of a multibyte character is
 * a quote, a backslash or a semicolon.
 */
final class SqlLexer {

	/**
	 * Characters of an unquoted word (letters, digits, _ and $; bytes from 0x80 are letters to MySQL).
	 */
	const WORD_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$';

	/**
	 * Skip what MySQL's lexer counts as space: space, tab, line breaks, vertical tab, form feed.
	 *
	 * @param string $buffer Buffer.
	 * @param int    $pos    Position.
	 * @return int The first position that is not one of them (the buffer's length when none is left).
	 */
	public static function spaces( string $buffer, int $pos ): int {
		return $pos + strspn( $buffer, " \t\r\n\x0B\x0C", $pos );
	}

	/**
	 * Skip what may stand between two statements of a chunk: spaces and
	 * whole "-- " comment lines. Nothing else: a statement starts at the
	 * position returned.
	 *
	 * @param string $buffer Buffer.
	 * @param int    $pos    Position.
	 * @param bool   $eof    Whether the buffer holds the rest of the file (a last comment line needs no line break).
	 * @return int The buffer's length when only spaces and comments are left.
	 * @throws NeedMoreBytes When the buffer ends inside a comment line.
	 */
	public static function between( string $buffer, int $pos, bool $eof ): int {
		$length = strlen( $buffer );
		while ( true ) {
			$pos = self::spaces( $buffer, $pos );
			if ( $pos >= $length ) {
				return $length;
			}
			if ( ! self::is_dash_comment( $buffer, $pos, $eof ) ) {
				return $pos;
			}
			$pos = self::line_end( $buffer, $pos, $eof );
		}
	}

	/**
	 * Read a quoted string: ' or " at $pos.
	 *
	 * @param string $buffer Buffer.
	 * @param int    $pos    Position of the opening quote.
	 * @return int Position after the closing quote.
	 * @throws NeedMoreBytes When the buffer ends inside the string, or right after a quote that might be doubled.
	 */
	public static function quoted( string $buffer, int $pos ): int {
		$length = strlen( $buffer );
		$quote  = $buffer[ $pos ];
		$stops  = '\\' . $quote;
		$at     = $pos + 1;
		while ( true ) {
			$at += strcspn( $buffer, $stops, $at );
			if ( $at >= $length ) {
				throw new NeedMoreBytes();
			}
			if ( '\\' === $buffer[ $at ] ) {
				$at += 2; // The escaped byte, whatever it is.
				continue;
			}
			if ( $at + 1 >= $length ) {
				throw new NeedMoreBytes(); // A doubled quote or the end: the next byte decides.
			}
			if ( $quote === $buffer[ $at + 1 ] ) {
				$at += 2;
				continue;
			}
			return $at + 1;
		}
	}

	/**
	 * Read a backtick-quoted identifier at $pos.
	 *
	 * @param string $buffer Buffer.
	 * @param int    $pos    Position of the opening backtick.
	 * @return array{0: string, 1: int} The name (doubled backticks made single) and the position after the closing backtick.
	 * @throws NeedMoreBytes When the buffer ends inside the identifier or right after a backtick that might be doubled.
	 * @throws Refused When the identifier is empty.
	 */
	public static function identifier( string $buffer, int $pos ): array {
		$length = strlen( $buffer );
		$at     = $pos + 1;
		$name   = '';
		while ( true ) {
			$next = strpos( $buffer, '`', $at );
			if ( false === $next || $next + 1 >= $length ) {
				throw new NeedMoreBytes();
			}
			$name .= substr( $buffer, $at, $next - $at );
			if ( '`' === $buffer[ $next + 1 ] ) {
				$name .= '`';
				$at    = $next + 2;
				continue;
			}
			if ( '' === $name ) {
				throw new Refused( 'An empty quoted name.' );
			}
			return array( $name, $next + 1 );
		}
	}

	/**
	 * Tokens of one statement, up to and including the semicolon that ends
	 * it. Comments are dropped, except that a versioned comment's markers
	 * become tokens of their own ("vopen", "vclose") and its content is
	 * tokenized as SQL. A semicolon inside a versioned comment is refused:
	 * the server would read it as the end of the statement, and a statement
	 * cannot contain another one.
	 *
	 * Token: array{0: string type, 1: int start, 2: int end, 3: string value}; types:
	 * "id" (backtick-quoted identifier; value: the name), "word" (unquoted
	 * letters, digits, _, $ and bytes from 0x80; value as written), "str"
	 * (quoted string; value: as written, quotes included), "p" (any other
	 * single byte; value: the byte), "vopen", "vclose", "end" (the semicolon).
	 *
	 * @param string $buffer Buffer.
	 * @param int    $pos    Position of the statement's first byte.
	 * @param bool   $eof    Whether the buffer holds the rest of the file.
	 * @return array<int, array{0: string, 1: int, 2: int, 3: string}> The last token is "end".
	 * @throws NeedMoreBytes When the buffer ends before the semicolon.
	 * @throws Refused When a comment is not closed or a versioned comment holds a semicolon.
	 */
	public static function tokens( string $buffer, int $pos, bool $eof ): array {
		$length    = strlen( $buffer );
		$tokens    = array();
		$versioned = false;
		while ( true ) {
			$pos = self::spaces( $buffer, $pos );
			if ( $pos >= $length ) {
				throw new NeedMoreBytes();
			}
			$byte = $buffer[ $pos ];
			if ( '\'' === $byte || '"' === $byte ) {
				$end      = self::quoted( $buffer, $pos );
				$tokens[] = array( 'str', $pos, $end, substr( $buffer, $pos, $end - $pos ) );
				$pos      = $end;
				continue;
			}
			if ( '`' === $byte ) {
				list( $name, $end ) = self::identifier( $buffer, $pos );
				$tokens[]           = array( 'id', $pos, $end, $name );
				$pos                = $end;
				continue;
			}
			if ( '#' === $byte || self::is_dash_comment( $buffer, $pos, $eof ) ) {
				$pos = self::line_end( $buffer, $pos, $eof );
				continue;
			}
			if ( '/' === $byte ) {
				if ( $pos + 1 >= $length ) {
					throw new NeedMoreBytes();
				}
				if ( '*' === $buffer[ $pos + 1 ] ) {
					$open = self::versioned_open( $buffer, $pos );
					if ( null !== $open ) {
						if ( $versioned ) {
							throw new Refused( 'A versioned comment inside another.' );
						}
						$tokens[]  = array( 'vopen', $pos, $open, substr( $buffer, $pos, $open - $pos ) );
						$versioned = true;
						$pos       = $open;
						continue;
					}
					$close = strpos( $buffer, '*/', $pos + 2 );
					if ( false === $close ) {
						if ( $eof ) {
							throw new Refused( 'A comment that is never closed.' );
						}
						throw new NeedMoreBytes();
					}
					$pos = $close + 2;
					continue;
				}
			}
			if ( $versioned && '*' === $byte ) {
				if ( $pos + 1 >= $length ) {
					throw new NeedMoreBytes();
				}
				if ( '/' === $buffer[ $pos + 1 ] ) {
					$tokens[]  = array( 'vclose', $pos, $pos + 2, '*/' );
					$versioned = false;
					$pos      += 2;
					continue;
				}
			}
			if ( ';' === $byte ) {
				if ( $versioned ) {
					throw new Refused( 'A semicolon inside a versioned comment.' );
				}
				$tokens[] = array( 'end', $pos, $pos + 1, ';' );
				return $tokens;
			}
			$word = strspn( $buffer, self::WORD_CHARS, $pos );
			while ( $pos + $word < $length && ord( $buffer[ $pos + $word ] ) >= 0x80 ) {
				++$word;
				$word += strspn( $buffer, self::WORD_CHARS, $pos + $word );
			}
			if ( $word > 0 ) {
				if ( $pos + $word >= $length ) {
					throw new NeedMoreBytes(); // The word may go on.
				}
				$tokens[] = array( 'word', $pos, $pos + $word, substr( $buffer, $pos, $word ) );
				$pos     += $word;
				continue;
			}
			$tokens[] = array( 'p', $pos, $pos + 1, $byte );
			++$pos;
		}
	}

	/**
	 * Whether "-- " (or "--" and a tab or line break) starts at $pos.
	 *
	 * @param string $buffer Buffer.
	 * @param int    $pos    Position.
	 * @param bool   $eof    Whether the buffer holds the rest of the file.
	 * @return bool
	 * @throws NeedMoreBytes When "--" is the end of the buffer.
	 */
	private static function is_dash_comment( string $buffer, int $pos, bool $eof ): bool {
		if ( '-' !== $buffer[ $pos ] ) {
			return false;
		}
		if ( ! isset( $buffer[ $pos + 1 ] ) ) {
			if ( $eof ) {
				return false;
			}
			throw new NeedMoreBytes();
		}
		if ( '-' !== $buffer[ $pos + 1 ] ) {
			return false;
		}
		if ( ! isset( $buffer[ $pos + 2 ] ) ) {
			if ( $eof ) {
				return true; // "--" at the very end: nothing follows either way.
			}
			throw new NeedMoreBytes();
		}
		return ord( $buffer[ $pos + 2 ] ) <= 0x20; // A space or a control character, as MySQL's lexer has it.
	}

	/**
	 * The position after the line break that ends a comment line starting at $pos.
	 *
	 * @param string $buffer Buffer.
	 * @param int    $pos    Position in the line.
	 * @param bool   $eof    Whether the buffer holds the rest of the file.
	 * @return int
	 * @throws NeedMoreBytes When the line does not end in the buffer and more may follow.
	 */
	private static function line_end( string $buffer, int $pos, bool $eof ): int {
		$break = strpos( $buffer, "\n", $pos );
		if ( false === $break ) {
			if ( $eof ) {
				return strlen( $buffer );
			}
			throw new NeedMoreBytes();
		}
		return $break + 1;
	}

	/**
	 * The position after "/*!" and its version digits (or "/*M!" and its digits), or null when $pos starts a plain comment.
	 *
	 * @param string $buffer Buffer.
	 * @param int    $pos    Position of "/*".
	 * @return int|null
	 * @throws NeedMoreBytes When the buffer ends before the marker is known.
	 */
	private static function versioned_open( string $buffer, int $pos ) {
		$at = $pos + 2;
		if ( ! isset( $buffer[ $at ] ) ) {
			throw new NeedMoreBytes();
		}
		if ( 'M' === $buffer[ $at ] ) {
			if ( ! isset( $buffer[ $at + 1 ] ) ) {
				throw new NeedMoreBytes();
			}
			if ( '!' !== $buffer[ $at + 1 ] ) {
				return null;
			}
			++$at;
		} elseif ( '!' !== $buffer[ $at ] ) {
			return null;
		}
		++$at;
		$digits = strspn( $buffer, '0123456789', $at );
		if ( $at + $digits >= strlen( $buffer ) ) {
			throw new NeedMoreBytes();
		}
		return $at + $digits;
	}
}
