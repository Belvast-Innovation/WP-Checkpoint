<?php
/**
 * Settings helpers.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Value normalisation shared by the settings form handler.
 */
final class Settings {

	/**
	 * Normalise a checkbox value.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		return in_array( $value, array( 1, '1', 'true', 'on', 'yes' ), true );
	}
}
