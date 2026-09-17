<?php
/**
 * One environment check result.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

/**
 * Immutable value object shared by the admin page and the text report.
 */
final class Check {

	const OK      = 'ok';
	const WARNING = 'warning';
	const ERROR   = 'error';
	const INFO    = 'info';

	/**
	 * Stable identifier, e.g. "php.zip".
	 *
	 * @var string
	 */
	public $id;

	/**
	 * Group identifier, e.g. "php".
	 *
	 * @var string
	 */
	public $group;

	/**
	 * Translated label.
	 *
	 * @var string
	 */
	public $label;

	/**
	 * Display value.
	 *
	 * @var string
	 */
	public $value;

	/**
	 * One of the status constants.
	 *
	 * @var string
	 */
	public $status;

	/**
	 * Explanation of the status.
	 *
	 * @var string
	 */
	public $message;

	/**
	 * What the finding means for the plugin's features.
	 *
	 * @var string
	 */
	public $impact;

	/**
	 * Constructor.
	 *
	 * @param string $id      Identifier.
	 * @param string $group   Group.
	 * @param string $label   Label.
	 * @param string $value   Display value.
	 * @param string $status  Status constant.
	 * @param string $message Explanation.
	 * @param string $impact  Impact on features.
	 */
	public function __construct( string $id, string $group, string $label, string $value, string $status = self::INFO, string $message = '', string $impact = '' ) {
		$this->id      = $id;
		$this->group   = $group;
		$this->label   = $label;
		$this->value   = $value;
		$this->status  = in_array( $status, array( self::OK, self::WARNING, self::ERROR, self::INFO ), true ) ? $status : self::INFO;
		$this->message = $message;
		$this->impact  = $impact;
	}

	/**
	 * Count checks per status.
	 *
	 * @param Check[] $checks Checks.
	 * @return array<string, int>
	 */
	public static function summarize( array $checks ): array {
		$summary = array(
			self::OK      => 0,
			self::WARNING => 0,
			self::ERROR   => 0,
			self::INFO    => 0,
		);
		foreach ( $checks as $check ) {
			++$summary[ $check->status ];
		}
		return $summary;
	}
}
