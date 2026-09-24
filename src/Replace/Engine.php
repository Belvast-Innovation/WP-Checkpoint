<?php
/**
 * Search and replace in stored values, safe for serialized data.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Replace;

defined( 'ABSPATH' ) || exit;

/**
 * Each value is one of three kinds, each handled one way:
 *
 * - a serialization (Serialized::rewrite() reads it whole, or reads a whole
 *   value at its start and WordPress would unserialize it, which ignores
 *   what follows): its string values are replaced and their lengths written
 *   anew, everything else is copied as it was;
 * - a value WordPress would take for a serialization but that is not a
 *   valid one (Serialized::looks_serialized()): left unchanged and counted.
 *   It is damaged already, WordPress cannot read it either, and replacing
 *   bytes inside it would only turn broken data into wrong data;
 * - anything else: replaced as text, in every encoding (Needles).
 *
 * A string inside a serialization is again one of the three, to any depth:
 * a serialization inside a string is rewritten first, and the outer length
 * follows from the rewritten bytes. Surrounding whitespace, which WordPress
 * trims before unserializing, is kept. Array keys, property names and the
 * insides of C: and E: are never changed; those holding a search text are
 * counted. A value in which no search text occurs at all is returned as it
 * is without being read.
 *
 * Pure PHP: no WordPress, and no call to unserialize() anywhere.
 */
final class Engine {

	/**
	 * What WordPress trims before unserializing (trim()'s defaults).
	 */
	const WHITESPACE = " \t\n\r\0\x0B";

	/**
	 * Needles.
	 *
	 * @var Needles
	 */
	private $needles;

	/**
	 * Counts for the value being processed.
	 *
	 * @var array{replaced: int, damaged: int, keys: int, opaque: int}
	 */
	private $counts = array(
		'replaced' => 0,
		'damaged'  => 0,
		'keys'     => 0,
		'opaque'   => 0,
	);

	/**
	 * Constructor.
	 *
	 * @param Needles $needles What to replace.
	 */
	public function __construct( Needles $needles ) {
		$this->needles = $needles;
	}

	/**
	 * One stored value, replaced.
	 *
	 * @param string $value Stored bytes.
	 * @return Result
	 */
	public function value( string $value ): Result {
		$this->counts = array(
			'replaced' => 0,
			'damaged'  => 0,
			'keys'     => 0,
			'opaque'   => 0,
		);
		if ( ! $this->needles->occurs_in( $value ) ) {
			return new Result( $value, Result::TEXT, $this->counts );
		}
		$kind = Result::TEXT;
		$new  = $this->transform( $value, 0, $kind );
		return new Result( $new, $kind, $this->counts );
	}

	/**
	 * A value or a string inside one, replaced according to its kind.
	 *
	 * @param string $value Bytes.
	 * @param int    $depth Nesting (0 for a stored value).
	 * @param string $kind  Set to the kind found (Result::*).
	 * @return string
	 */
	private function transform( string $value, int $depth, string &$kind ): string {
		if ( ! $this->needles->occurs_in( $value ) ) {
			$kind = Result::TEXT;
			return $value;
		}
		$lead  = strspn( $value, self::WHITESPACE );
		$core  = substr( $value, $lead );
		$trail = strlen( $core ) - strlen( rtrim( $core, self::WHITESPACE ) );
		$core  = substr( $core, 0, strlen( $core ) - $trail );
		if ( '' !== $core ) {
			// A read that fails half way has already counted what it saw inside: those counts are taken back.
			$saved     = $this->counts;
			$rewritten = Serialized::rewrite(
				$core,
				function ( string $inner, int $inner_depth ): string {
					$ignored = '';
					return $this->transform( $inner, $inner_depth, $ignored );
				},
				function ( string $part, string $bytes ): void {
					if ( $this->needles->occurs_in( $bytes ) ) {
						++$this->counts[ Serialized::PART_KEY === $part ? 'keys' : 'opaque' ];
					}
				},
				$depth
			);
			if ( null !== $rewritten ) {
				$kind = Result::SERIALIZED;
				return substr( $value, 0, $lead ) . $rewritten . substr( $value, strlen( $value ) - $trail );
			}
			$this->counts = $saved;
			// Bytes after a complete value: unserialize() reads the value and ignores them, so WordPress reads it
			// when it takes the whole for a serialization. The value is rewritten, what follows is copied as it is.
			if ( Serialized::looks_serialized( $value ) ) {
				$leading = Serialized::rewrite_leading(
					$core,
					function ( string $inner, int $inner_depth ): string {
						$ignored = '';
						return $this->transform( $inner, $inner_depth, $ignored );
					},
					function ( string $part, string $bytes ): void {
						if ( $this->needles->occurs_in( $bytes ) ) {
							++$this->counts[ Serialized::PART_KEY === $part ? 'keys' : 'opaque' ];
						}
					},
					$depth
				);
				if ( null !== $leading ) {
					$kind = Result::SERIALIZED;
					return substr( $value, 0, $lead ) . $leading[0] . substr( $core, $leading[1] ) . substr( $value, strlen( $value ) - $trail );
				}
				$this->counts = $saved;
			}
		}
		if ( Serialized::looks_serialized( $value ) ) {
			$kind = Result::DAMAGED;
			++$this->counts['damaged'];
			return $value;
		}
		$kind                      = Result::TEXT;
		list( $new, $count )       = $this->needles->replace( $value );
		$this->counts['replaced'] += $count;
		return $new;
	}
}
