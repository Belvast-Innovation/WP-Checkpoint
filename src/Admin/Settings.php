<?php
/**
 * Settings API registration.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Admin;

use WPCheckpoint\Support\Uninstaller;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's options so options.php accepts and sanitizes them.
 */
final class Settings {

	/**
	 * Settings group used by settings_fields() and register_setting().
	 */
	const GROUP = 'wpcheckpoint';

	/**
	 * Hook into admin_init.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register every option with its sanitizer.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			Uninstaller::OPTION_DELETE_DATA,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'show_in_rest'      => false,
				'sanitize_callback' => array( $this, 'sanitize_bool' ),
			)
		);
	}

	/**
	 * Normalise a checkbox value.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public function sanitize_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		return in_array( $value, array( 1, '1', 'true', 'on', 'yes' ), true );
	}
}
