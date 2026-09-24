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
 * it was (floats, integers, references, class names, untouched strings). A
 * rewrite passes each string value (never an array key or a property name)
 * to a callback and writes the result back with its length recomputed in
 * bytes; the number of members of an array or object never changes, so the
 * slot numbers that references (R: and r:) point to stay right.
 *
 * Strict: every length must be the exact byte count, every member count the
 * actual count, and nesting is limited to MAX_DEPTH; rewrite() also wants
 * nothing after the value, rewrite_leading() reports where it ended. Anything else is not a serialization
 * here, whatever it looks like: looks_serialized() tells the two apart.
 * Custom-serialized objects (C:) and enums (E:) are copied without looking
 * inside. The S: form (escaped strings), which serialize() never writes, is
 * not read.
 */
final class Serialized {

	/**
	 * Deepest nesting read, counting nested serializations inside strings.
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
	 * Callback for each string value: function( string $value, int $depth ): string.
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
	 * Constructor.
	 *
	 * @param string   $data   Input.
	 * @param callable $value  function( string $value, int $depth ): string.
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
	 * @param callable $value  function( string $value, int $depth ): string, for each string value; $depth is the nesting of the value.
	 * @param callable $opaque function( string $part, string $bytes ): void, for each part copied unchanged (PART_KEY: an array key or property name; PART_OPAQUE: a class name, a C: payload or an E: name).
	 * @param int      $depth  Nesting of $data itself (a serialization inside a string of another).
	 * @return string|null
	 */
	public static function rewrite( string $data, callable $value, callable $opaque, int $depth = 0 ) {
		$read = self::rewrite_leading( $data, $value, $opaque, $depth );
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
	 * @param int      $depth  As for rewrite().
	 * @return array{0: string, 1: int}|null
	 */
	public static function rewrite_leading( string $data, callable $value, callable $opaque, int $depth = 0 ) {
		$reader = new self( $data, $value, $opaque );
		try {
			$reader->item( $depth, false );
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
	 */
	private function item( int $depth, bool $key ): void {
		if ( $depth > self::MAX_DEPTH ) {
			throw new NotSerialized();
		}
		$type = $this->byte( $this->pos );
		if ( $key && 'i' !== $type && 's' !== $type ) {
			throw new NotSerialized();
		}
		if ( 'N' === $type ) {
			$this->copy_literal( 'N;' );
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
				return;
			case 'i':
			case 'R':
			case 'r':
				$this->copy_number( 'i' === $type );
				$this->copy_literal( ';' );
				return;
			case 'd':
				$end = strpos( $this->data, ';', $this->pos );
				if ( false === $end || $end === $this->pos || strspn( $this->data, '0123456789.eE+-INFA', $this->pos, $end - $this->pos ) !== $end - $this->pos ) {
					throw new NotSerialized();
				}
				$this->copy( $end - $this->pos + 1 );
				return;
			case 's':
				$this->string( $depth, $key );
				return;
			case 'a':
				$this->members( $this->copy_number( false ), $depth );
				return;
			case 'O':
				$this->quoted_name();
				$this->copy_literal( ':' );
				$this->members( $this->copy_number( false ), $depth );
				return;
			case 'C':
				$this->quoted_name();
				$this->copy_literal( ':' );
				$length = $this->copy_number( false );
				$this->copy_literal( ':{' );
				$this->ensure( $length + 1 );
				call_user_func( $this->opaque, self::PART_OPAQUE, substr( $this->data, $this->pos, $length ) );
				$this->copy( $length );
				$this->copy_literal( '}' );
				return;
			case 'E':
				$this->quoted_name();
				$this->copy_literal( ';' );
				return;
		}
		throw new NotSerialized();
	}

	/**
	 * A string's length, quotes and bytes; a value goes through the callback.
	 *
	 * @param int  $depth Nesting.
	 * @param bool $key   Whether it is a key.
	 * @return void
	 * @throws NotSerialized When the length does not match.
	 */
	private function string( int $depth, bool $key ): void {
		$start  = $this->pos;
		$length = $this->read_number();
		$this->expect( ':"' );
		$this->ensure( $length + 2 );
		if ( '";' !== substr( $this->data, $this->pos + $length, 2 ) ) {
			throw new NotSerialized();
		}
		$bytes = substr( $this->data, $this->pos, $length );
		if ( $key ) {
			call_user_func( $this->opaque, self::PART_KEY, $bytes );
			$this->out .= substr( $this->data, $start, $this->pos + $length + 2 - $start );
		} else {
			$new        = (string) call_user_func( $this->value, $bytes, $depth + 1 );
			$this->out .= strlen( $new ) . ':"' . $new . '";';
		}
		$this->pos += $length + 2;
	}

	/**
	 * The members of an array or object: "{", count pairs of key and value, "}".
	 *
	 * @param int $count Declared count.
	 * @param int $depth Nesting of the container.
	 * @return void
	 * @throws NotSerialized When the count or the braces do not match.
	 */
	private function members( int $count, int $depth ): void {
		$this->copy_literal( ':{' );
		for ( $i = 0; $i < $count; $i++ ) {
			$this->item( $depth + 1, true );
			$this->item( $depth + 1, false );
		}
		$this->copy_literal( '}' );
	}

	/**
	 * A length, a colon and a double-quoted name of that many bytes (class
	 * names, and E:'s class and case), copied unchanged.
	 *
	 * @return void
	 * @throws NotSerialized When the length does not match.
	 */
	private function quoted_name(): void {
		$length = $this->copy_number( false );
		$this->copy_literal( ':"' );
		$this->ensure( $length + 1 );
		call_user_func( $this->opaque, self::PART_OPAQUE, substr( $this->data, $this->pos, $length ) );
		$this->copy( $length );
		$this->copy_literal( '"' );
	}

	/**
	 * Read a non-negative decimal (or, with $signed, an optionally signed one) and copy it.
	 *
	 * @param bool $signed Whether a sign may lead.
	 * @return int
	 * @throws NotSerialized When there is no number.
	 */
	private function copy_number( bool $signed ): int {
		$start = $this->pos;
		if ( $signed && ( '-' === $this->byte( $this->pos ) || '+' === $this->byte( $this->pos ) ) ) {
			++$this->pos;
		}
		$digits = strspn( $this->data, '0123456789', $this->pos );
		if ( 0 === $digits || $digits > 19 ) {
			throw new NotSerialized();
		}
		$this->pos += $digits;
		$text       = substr( $this->data, $start, $this->pos - $start );
		$this->out .= $text;
		return (int) $text;
	}

	/**
	 * Read a non-negative decimal without copying it (a string's length, written anew).
	 *
	 * @return int
	 * @throws NotSerialized When there is no number or it cannot be a length.
	 */
	private function read_number(): int {
		$digits = strspn( $this->data, '0123456789', $this->pos );
		if ( 0 === $digits || $digits > 10 ) {
			throw new NotSerialized();
		}
		$number     = (int) substr( $this->data, $this->pos, $digits );
		$this->pos += $digits;
		return $number;
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
	 * At least $length more bytes must follow.
	 *
	 * @param int $length Bytes.
	 * @return void
	 * @throws NotSerialized When there are fewer.
	 */
	private function ensure( int $length ): void {
		if ( $length < 0 || $this->pos + $length > strlen( $this->data ) ) {
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
