<?php
/**
 * Thrown when a sidecar index line is not acceptable.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Archive;

/**
 * Carries the offending key; the message is a fixed text.
 */
final class IndexLineError extends \InvalidArgumentException {

	/**
	 * Key, empty for line-level problems.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Constructor.
	 *
	 * @param string $key     Key.
	 * @param string $message What is wrong.
	 */
	public function __construct( string $key, string $message ) {
		$this->key = $key;
		parent::__construct( '' === $key ? $message : $key . ': ' . $message );
	}

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->key;
	}
}
