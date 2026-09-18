<?php
/**
 * Thrown when a manifest is not acceptable.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Archive;

/**
 * Carries the path of the offending field (for example
 * "database.tables[3].chunks[0].sha256") so a verifier can report it.
 */
final class ManifestError extends \InvalidArgumentException {

	/**
	 * Field path, empty for document-level problems.
	 *
	 * @var string
	 */
	private $field;

	/**
	 * Constructor.
	 *
	 * @param string $field   Field path.
	 * @param string $message What is wrong with it.
	 */
	public function __construct( string $field, string $message ) {
		$this->field = $field;
		parent::__construct( '' === $field ? $message : $field . ': ' . $message );
	}

	/**
	 * Field path.
	 *
	 * @return string
	 */
	public function field(): string {
		return $this->field;
	}
}
