<?php
/**
 * What replacing in one stored value did.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Replace;

defined( 'ABSPATH' ) || exit;

/**
 * The new bytes, the kind of the value, and counts: replacements made,
 * damaged serializations left unchanged (WordPress cannot read them either:
 * the data was broken before, whatever it once held is already lost to the
 * plugin that wrote it), and array keys or opaque parts (C: payloads, E:
 * names, class names) that hold a search text and were left unchanged.
 */
final class Result {

	/**
	 * Kinds of value.
	 */
	const TEXT       = 'text';
	const SERIALIZED = 'serialized';
	const DAMAGED    = 'damaged';

	/**
	 * New bytes.
	 *
	 * @var string
	 */
	private $value;

	/**
	 * Kind.
	 *
	 * @var string
	 */
	private $kind;

	/**
	 * Counts.
	 *
	 * @var array{replaced: int, damaged: int, keys: int, opaque: int}
	 */
	private $counts;

	/**
	 * Constructor.
	 *
	 * @param string                                                     $value  New bytes.
	 * @param string                                                     $kind   Kind (the stored value's).
	 * @param array{replaced: int, damaged: int, keys: int, opaque: int} $counts Counts.
	 */
	public function __construct( string $value, string $kind, array $counts ) {
		$this->value  = $value;
		$this->kind   = $kind;
		$this->counts = $counts;
	}

	/**
	 * New bytes.
	 *
	 * @return string
	 */
	public function value(): string {
		return $this->value;
	}

	/**
	 * Kind of the stored value: TEXT, SERIALIZED or DAMAGED.
	 *
	 * @return string
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Replacements made.
	 *
	 * @return int
	 */
	public function replaced(): int {
		return $this->counts['replaced'];
	}

	/**
	 * Damaged serializations holding a search text, left unchanged (the value itself or strings inside it).
	 *
	 * @return int
	 */
	public function damaged(): int {
		return $this->counts['damaged'];
	}

	/**
	 * Array keys and property names holding a search text, left unchanged.
	 *
	 * @return int
	 */
	public function keys(): int {
		return $this->counts['keys'];
	}

	/**
	 * Class names, C: payloads and E: names holding a search text, left unchanged.
	 *
	 * @return int
	 */
	public function opaque(): int {
		return $this->counts['opaque'];
	}

	/**
	 * Whether any byte changed.
	 *
	 * @return bool
	 */
	public function changed(): bool {
		return $this->replaced() > 0;
	}
}
