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
 * - a serialization (Serialized reads a whole value from its start, and
 *   either nothing follows or WordPress would unserialize it all the same,
 *   which ignores what follows): its string values are replaced and their
 *   lengths written anew, everything else is copied as it was;
 * - a value WordPress would take for a serialization but that is not a
 *   valid one (Serialized::looks_serialized()): left unchanged and counted.
 *   It is damaged already, WordPress cannot read it either, and replacing
 *   bytes inside it would only turn broken data into wrong data;
 * - anything else: replaced as text, in every encoding (Needles).
 *
 * A string inside a serialization is again one of the three: a
 * serialization inside a string is rewritten first, and the outer length
 * follows from the rewritten bytes. Each value is read once. Two limits keep
 * the work bounded, and a value beyond either is left unchanged and counted
 * as too deep (not as damaged: WordPress may read it): arrays and objects
 * nested deeper than Serialized::MAX_DEPTH in one serialization, and
 * serializations inside strings nested deeper than MAX_NESTED (each level
 * holds its own copy of the bytes, so the memory grows with it). Surrounding
 * whitespace, which WordPress trims before unserializing, is kept. Array
 * keys, property names and the insides of C: and E: are never changed;
 * those holding a search text are counted. A value in which no search text
 * occurs at all is returned as it is without being read.
 *
 * Pure PHP: no WordPress, and no call to unserialize() anywhere.
 */
final class Engine {

	/**
	 * What WordPress trims before unserializing (trim()'s defaults).
	 */
	const WHITESPACE = " \t\n\r\0\x0B";

	/**
	 * Serializations inside strings followed below a stored value: a stored
	 * serialization, one inside one of its strings, and so on, this many
	 * levels down. A 4 MiB value nested this deep stays within the per-step
	 * memory budget (EngineTest measures it).
	 */
	const MAX_NESTED = 3;

	/**
	 * Needles.
	 *
	 * @var Needles
	 */
	private $needles;

	/**
	 * Counts for the value being processed.
	 *
	 * @var array{replaced: int, damaged: int, too_deep: int, keys: int, opaque: int}
	 */
	private $counts;

	/**
	 * Constructor.
	 *
	 * @param Needles $needles What to replace.
	 */
	public function __construct( Needles $needles ) {
		$this->needles = $needles;
		$this->counts  = self::zero();
	}

	/**
	 * One stored value, replaced.
	 *
	 * @param string $value Stored bytes.
	 * @return Result
	 */
	public function value( string $value ): Result {
		$this->counts = self::zero();
		$kind         = Result::TEXT;
		$new          = $this->transform( $value, 0, $kind );
		return new Result( $new, $kind, $this->counts );
	}

	/**
	 * Counts at zero.
	 *
	 * @return array{replaced: int, damaged: int, too_deep: int, keys: int, opaque: int}
	 */
	private static function zero(): array {
		return array(
			'replaced' => 0,
			'damaged'  => 0,
			'too_deep' => 0,
			'keys'     => 0,
			'opaque'   => 0,
		);
	}

	/**
	 * A value or a string inside one, replaced according to its kind.
	 *
	 * @param string $value   Bytes.
	 * @param int    $nesting Serializations inside strings above it (0 for a stored value).
	 * @param string $kind    Set to the kind found (Result::*).
	 * @return string
	 */
	private function transform( string $value, int $nesting, string &$kind ): string {
		$kind = Result::TEXT;
		if ( ! $this->needles->occurs_in( $value ) ) {
			return $value;
		}
		$looks = Serialized::looks_serialized( $value );
		if ( $nesting > self::MAX_NESTED && $looks ) {
			$kind = Result::TOO_DEEP;
			++$this->counts['too_deep'];
			return $value;
		}
		$lead  = strspn( $value, self::WHITESPACE );
		$trail = strlen( $value ) - $lead - strlen( rtrim( substr( $value, $lead ), self::WHITESPACE ) );
		$core  = 0 === $lead && 0 === $trail ? $value : substr( $value, $lead, strlen( $value ) - $lead - $trail );
		if ( '' !== $core ) {
			// A read that fails half way has already counted what it saw inside: those counts are taken back.
			$saved = $this->counts;
			try {
				$read = Serialized::rewrite_leading(
					$core,
					function ( string $inner ) use ( $nesting ): string {
						$ignored = '';
						return $this->transform( $inner, $nesting + 1, $ignored );
					},
					function ( string $part, string $bytes ): void {
						if ( $this->needles->occurs_in( $bytes ) ) {
							++$this->counts[ Serialized::PART_KEY === $part ? 'keys' : 'opaque' ];
						}
					}
				);
			} catch ( TooDeep $e ) {
				$this->counts = $saved;
				if ( $looks ) {
					$kind = Result::TOO_DEEP;
					++$this->counts['too_deep'];
					return $value;
				}
				$read = null;
			}
			// Bytes after a complete value count when WordPress would unserialize the whole: it ignores them.
			if ( null !== $read && ( strlen( $core ) === $read[1] || $looks ) ) {
				$kind = Result::SERIALIZED;
				if ( 0 === $lead && 0 === $trail && strlen( $core ) === $read[1] ) {
					return $read[0];
				}
				return substr( $value, 0, $lead ) . $read[0] . substr( $core, $read[1] ) . substr( $value, strlen( $value ) - $trail );
			}
			$this->counts = $saved;
		}
		if ( $looks ) {
			$kind = Result::DAMAGED;
			++$this->counts['damaged'];
			return $value;
		}
		list( $new, $count )       = $this->needles->replace( $value );
		$this->counts['replaced'] += $count;
		return $new;
	}
}
