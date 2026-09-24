<?php
/**
 * PHP's serialization format, read and rewritten byte by byte.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Replace;

defined( 'ABSPATH' ) || exit;

/**
 * A strict reader of PHP's serialization format that never deserializes:
 * nothing is instantiated, and every byte it does not change is copied as
 * it was (floats, integers, references, class names, untouched strings and
 * the length written before each of them, leading zeros included). A
 * rewrite passes each string value (never an array key or a property name)
 * to a callback; a string that comes back changed is written with its length
 * recomputed in bytes. The number of members of an array or object never
 * changes, so the slot numbers that references (R: and r:) point to stay
 * right.
 *
 * Strict, following what unserialize() accepts: every length must be the
 * exact byte count and fit in what remains of the input, every member count
 * the actual count, floats follow PHP's grammar, a reference must point to
 * an earlier value (r: to an object), and nesting is limited to MAX_DEPTH
 * (deeper values raise TooDeep, which the caller can tell from damage).
 * rewrite() also wants nothing after the value; rewrite_leading() reports
 * where it ended. Custom-serialized objects (C:) and enums (E:) are copied
 * without looking inside. The S: form (escaped strings), which serialize()
 * never writes, is not read.
 */
final class Serialized {

	/**
	 * Deepest nesting of arrays and objects read in one serialization.
	 */
	const MAX_DEPTH = 256;

	/**
	 * Parts copied without change, as passed to the $opaque callback.
	 */
	const PART_KEY    = 'key';
	const PART_OPAQUE = 'opaque';

	/**
	 * Input.
	 *
	 * @var string
	 */
	private $data;

	/**
	 * Read position.
	 *
	 * @var int
	 */
	private $pos = 0;

	/**
	 * Output.
	 *
	 * @var string
	 */
	private $out = '';

	/**
	 * Callback for each string value: function( string $value ): string.
	 *
	 * @var callable
	 */
	private $value;

	/**
	 * Callback for each part copied without change: function( string $part, string $bytes ): void, with
	 * PART_KEY for an array key or property name, PART_OPAQUE for a class name, a C: payload or an E: name.
	 *
	 * @var callable
	 */
	private $opaque;

	/**
	 * Whether each value slot read so far holds an object (what r: may point to), in order from slot 1.
	 *
	 * @var bool[]
	 */
	private $slots = array();

	/**
	 * Constructor.
	 *
	 * @param string   $data   Input.
	 * @param callable $value  function( string $value ): string.
	 * @param callable $opaque function( string $part, string $bytes ): void.
	 */
	private function __construct( string $data, callable $value, callable $opaque ) {
		$this->data   = $data;
		$this->value  = $value;
		$this->opaque = $opaque;
	}

	/**
	 * The serialization with each string value passed through $value, or
	 * null when the input is not one complete, valid serialized value.
	 *
	 * @param string   $data   Input, without surrounding whitespace.
	 * @param callable $value  function( string $value ): string, for each string value.
	 * @param callable $opaque function( string $part, string $bytes ): void, for each part copied unchanged (PART_KEY: an array key or property name; PART_OPAQUE: a class name, a C: payload or an E: name).
	 * @return string|null
	 * @throws TooDeep When arrays and objects nest deeper than MAX_DEPTH.
	 */
	public static function rewrite( string $data, callable $value, callable $opaque ) {
		$read = self::rewrite_leading( $data, $value, $opaque );
		return null !== $read && strlen( $data ) === $read[1] ? $read[0] : null;
	}

	/**
	 * The first complete, valid serialized value at the start of the input,
	 * rewritten, and how many bytes it took; null when there is none.
	 * unserialize() reads such a value and ignores what follows it (PHP 8.3
	 * warns, WordPress silences the warning), so a stored value with bytes
	 * after one is read by WordPress all the same.
	 *
	 * @param string   $data   Input.
	 * @param callable $value  As for rewrite().
	 * @param callable $opaque As for rewrite().
	 * @return array{0: string, 1: int}|null
	 * @throws TooDeep When arrays and objects nest deeper than MAX_DEPTH.
	 */
	public static function rewrite_leading( string $data, callable $value, callable $opaque ) {
		$reader = new self( $data, $value, $opaque );
		try {
			$reader->item( 0, false );
		} catch ( TooDeep $e ) {
			throw $e;
		} catch ( NotSerialized $e ) {
			return null;
		}
		return array( $reader->out, $reader->pos );
	}

	/**
	 * Whether WordPress would take the value for a serialization and pass it
	 * to unserialize() (the test of its is_serialized(), strict mode): such a
	 * value that rewrite() refuses is damaged, and WordPress cannot read it
	 * either.
	 *
	 * @param string $data Value.
	 * @return bool
	 */
	public static function looks_serialized( string $data ): bool {
		$data = trim( $data );
		if ( 'N;' === $data ) {
			return true;
		}
		$length = strlen( $data );
		if ( $length < 4 || ':' !== $data[1] ) {
			return false;
		}
		$last = $data[ $length - 1 ];
		if ( ';' !== $last && '}' !== $last ) {
			return false;
		}
		switch ( $data[0] ) {
			case 's':
				if ( '"' !== $data[ $length - 2 ] ) {
					return false;
				}
				// A string passes the same test as the containers.
			case 'a':
			case 'O':
			case 'E':
				$colon = strpos( $data, ':', 2 );
				return false !== $colon && $colon > 2 && ctype_digit( substr( $data, 2, $colon - 2 ) );
			case 'b':
			case 'i':
			case 'd':
				$body = substr( $data, 2, -1 );
				return ';' === $last && '' !== $body && strspn( $body, '0123456789.E+-' ) === strlen( $body );
		}
		return false;
	}

	/**
	 * Read one value (or, with $key, one array key or property name) and write it out.
	 *
	 * @param int  $depth Nesting.
	 * @param bool $key   Whether it is a key.
	 * @return void
	 * @throws NotSerialized When the input does not follow the format.
	 * @throws TooDeep When it nests deeper than MAX_DEPTH.
	 */
	private function item( int $depth, bool $key ): void {
		if ( $depth > self::MAX_DEPTH ) {
			throw new TooDeep();
		}
		$type = $this->byte( $this->pos );
		if ( $key && 'i' !== $type && 's' !== $type ) {
			throw new NotSerialized();
		}
		if ( 'N' === $type ) {
			$this->copy_literal( 'N;' );
			$this->slots[] = false;
			return;
		}
		$this->copy_literal( $type . ':' );
		switch ( $type ) {
			case 'b':
				$flag = $this->byte( $this->pos );
				if ( '0' !== $flag && '1' !== $flag ) {
					throw new NotSerialized();
				}
				$this->copy( 1 );
				$this->copy_literal( ';' );
				break;
			case 'i':
				$this->copy_integer();
				$this->copy_literal( ';' );
				break;
			case 'd':
				$this->copy_float();
				$this->copy_literal( ';' );
				break;
			case 's':
				$this->string( $key );
				break;
			case 'R':
			case 'r':
				$this->reference( 'r' === $type );
				return;
			case 'a':
				$this->slots[] = false;
				$this->members( $depth );
				return;
			case 'O':
				$this->slots[] = true;
				$this->quoted_name();
				$this->copy_literal( ':' );
				$this->members( $depth );
				return;
			case 'C':
				$this->slots[] = true;
				$this->quoted_name();
				$this->copy_literal( ':' );
				$length = $this->copy_length();
				$this->copy_literal( ':{' );
				call_user_func( $this->opaque, self::PART_OPAQUE, substr( $this->data, $this->pos, $length ) );
				$this->copy( $length );
				$this->copy_literal( '}' );
				return;
			case 'E':
				$this->slots[] = true;
				$this->quoted_name();
				$this->copy_literal( ';' );
				return;
			default:
				throw new NotSerialized();
		}
		if ( ! $key ) {
			$this->slots[] = false;
		}
	}

	/**
	 * A string's length, quotes and bytes. A value goes through the callback
	 * and keeps the length as written when it comes back unchanged.
	 *
	 * @param bool $key Whether it is a key.
	 * @return void
	 * @throws NotSerialized When the length does not match.
	 */
	private function string( bool $key ): void {
		$start  = $this->pos;
		$length = $this->read_length();
		$this->expect( ':"' );
		$this->ensure( $length + 2 );
		if ( '";' !== substr( $this->data, $this->pos + $length, 2 ) ) {
			throw new NotSerialized();
		}
		$bytes = substr( $this->data, $this->pos, $length );
		$new   = $bytes;
		if ( $key ) {
			call_user_func( $this->opaque, self::PART_KEY, $bytes );
		} else {
			$new = (string) call_user_func( $this->value, $bytes );
		}
		$this->pos += $length + 2;
		if ( $new === $bytes ) {
			$this->out .= substr( $this->data, $start, $this->pos - $start );
			return;
		}
		$this->out .= strlen( $new ) . ':"' . $new . '";';
	}

	/**
	 * The members of an array or object: count, "{", count pairs of key and value, "}".
	 *
	 * @param int $depth Nesting of the container.
	 * @return void
	 * @throws NotSerialized When the count or the braces do not match.
	 * @throws TooDeep When it nests deeper than MAX_DEPTH.
	 */
	private function members( int $depth ): void {
		// Each member takes at least six bytes ("i:0;N;"): a count that cannot fit is refused before looping.
		$count = $this->copy_length( 6 );
		$this->copy_literal( ':{' );
		for ( $i = 0; $i < $count; $i++ ) {
			$this->item( $depth + 1, true );
			$this->item( $depth + 1, false );
		}
		$this->copy_literal( '}' );
	}

	/**
	 * A reference: R: to any earlier value, r: to an earlier object. R: takes
	 * no slot of its own, r: takes one (holding the object), as unserialize()
	 * counts them.
	 *
	 * @param bool $to_object Whether it is r:.
	 * @return void
	 * @throws NotSerialized When it points nowhere.
	 */
	private function reference( bool $to_object ): void {
		$digits = strspn( $this->data, '0123456789', $this->pos );
		if ( 0 === $digits || $digits > strlen( (string) count( $this->slots ) ) ) {
			throw new NotSerialized();
		}
		$id = (int) substr( $this->data, $this->pos, $digits );
		if ( $id < 1 || $id > count( $this->slots ) || ( $to_object && ! $this->slots[ $id - 1 ] ) ) {
			throw new NotSerialized();
		}
		$this->copy( $digits );
		$this->copy_literal( ';' );
		if ( $to_object ) {
			$this->slots[] = true;
		}
	}

	/**
	 * A length, a colon and a double-quoted name of that many bytes (class
	 * names, and E:'s class and case), copied unchanged.
	 *
	 * @return void
	 * @throws NotSerialized When the length does not match.
	 */
	private function quoted_name(): void {
		$length = $this->copy_length();
		$this->copy_literal( ':"' );
		$this->ensure( $length + 1 );
		call_user_func( $this->opaque, self::PART_OPAQUE, substr( $this->data, $this->pos, $length ) );
		$this->copy( $length );
		$this->copy_literal( '"' );
	}

	/**
	 * An integer: an optional sign and digits, copied as written.
	 *
	 * @return void
	 * @throws NotSerialized When there are no digits.
	 */
	private function copy_integer(): void {
		$sign   = strspn( $this->data, '+-', $this->pos, 1 );
		$digits = strspn( $this->data, '0123456789', $this->pos + $sign );
		if ( 0 === $digits ) {
			throw new NotSerialized();
		}
		$this->copy( $sign + $digits );
	}

	/**
	 * A float as unserialize() reads it: NAN, INF or -INF, or an optionally
	 * signed decimal with digits on at least one side of an optional point
	 * and an optional exponent; copied as written.
	 *
	 * @return void
	 * @throws NotSerialized When it is none of these.
	 */
	private function copy_float(): void {
		foreach ( array( 'NAN', 'INF', '-INF' ) as $word ) {
			if ( substr( $this->data, $this->pos, strlen( $word ) ) === $word && ';' === $this->byte( $this->pos + strlen( $word ) ) ) {
				$this->copy( strlen( $word ) );
				return;
			}
		}
		$at     = $this->pos + strspn( $this->data, '+-', $this->pos, 1 );
		$before = strspn( $this->data, '0123456789', $at );
		$at    += $before;
		$after  = 0;
		if ( '.' === $this->byte( $at ) ) {
			$after = strspn( $this->data, '0123456789', $at + 1 );
			$at   += 1 + $after;
		}
		if ( 0 === $before + $after ) {
			throw new NotSerialized();
		}
		if ( 'e' === $this->byte( $at ) || 'E' === $this->byte( $at ) ) {
			$sign     = strspn( $this->data, '+-', $at + 1, 1 );
			$exponent = strspn( $this->data, '0123456789', $at + 1 + $sign );
			if ( 0 === $exponent ) {
				throw new NotSerialized();
			}
			$at += 1 + $sign + $exponent;
		}
		$this->copy( $at - $this->pos );
	}

	/**
	 * Read and copy a length or count that fits in what remains of the input
	 * ($unit bytes per item at least), refused before any arithmetic on it.
	 *
	 * @param int $unit Least bytes each counted item takes.
	 * @return int
	 * @throws NotSerialized When there is no number or it cannot fit.
	 */
	private function copy_length( int $unit = 1 ): int {
		$start      = $this->pos;
		$value      = $this->read_length( $unit );
		$this->out .= substr( $this->data, $start, $this->pos - $start );
		return $value;
	}

	/**
	 * Read a length or count that fits in what remains of the input, without
	 * copying it. The digits are compared as text first, so no number too
	 * large for the platform's integers is ever formed.
	 *
	 * @param int $unit Least bytes each counted item takes.
	 * @return int
	 * @throws NotSerialized When there is no number or it cannot fit.
	 */
	private function read_length( int $unit = 1 ): int {
		$digits = strspn( $this->data, '0123456789', $this->pos );
		if ( 0 === $digits ) {
			throw new NotSerialized();
		}
		$text      = ltrim( substr( $this->data, $this->pos, $digits ), '0' );
		$remaining = (string) intdiv( strlen( $this->data ) - $this->pos, $unit );
		if ( strlen( $text ) > strlen( $remaining ) || ( strlen( $text ) === strlen( $remaining ) && strcmp( $text, $remaining ) > 0 ) ) {
			throw new NotSerialized();
		}
		$this->pos += $digits;
		return (int) $text;
	}

	/**
	 * Copy the next bytes, which must be exactly $literal.
	 *
	 * @param string $literal Expected bytes.
	 * @return void
	 * @throws NotSerialized When they are not.
	 */
	private function copy_literal( string $literal ): void {
		$this->expect( $literal );
		$this->out .= $literal;
	}

	/**
	 * Skip the next bytes, which must be exactly $literal.
	 *
	 * @param string $literal Expected bytes.
	 * @return void
	 * @throws NotSerialized When they are not.
	 */
	private function expect( string $literal ): void {
		if ( substr( $this->data, $this->pos, strlen( $literal ) ) !== $literal ) {
			throw new NotSerialized();
		}
		$this->pos += strlen( $literal );
	}

	/**
	 * Copy the next $length bytes.
	 *
	 * @param int $length Bytes.
	 * @return void
	 * @throws NotSerialized When there are fewer.
	 */
	private function copy( int $length ): void {
		$this->ensure( $length );
		$this->out .= substr( $this->data, $this->pos, $length );
		$this->pos += $length;
	}

	/**
	 * At least $length more bytes must follow ($length comes from read_length(), so it cannot overflow).
	 *
	 * @param int $length Bytes.
	 * @return void
	 * @throws NotSerialized When there are fewer.
	 */
	private function ensure( int $length ): void {
		if ( $length < 0 || $length > strlen( $this->data ) - $this->pos ) {
			throw new NotSerialized();
		}
	}

	/**
	 * The byte at a position, or '' past the end.
	 *
	 * @param int $at Position.
	 * @return string
	 */
	private function byte( int $at ): string {
		return $at < strlen( $this->data ) ? $this->data[ $at ] : '';
	}
}
