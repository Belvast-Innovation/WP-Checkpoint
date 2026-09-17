<?php
/**
 * Settings API registration.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Admin;

use WPCheckpoint\Support\Guard;
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
		// options.php checks manage_options by default; on multisite the plugin requires more.
		add_filter( 'option_page_capability_' . self::GROUP, array( Guard::class, 'capability' ) );
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
				'sanitize_callback' => array( $this, 'sanitize_delete_data' ),
			)
		);
	}

	/**
	 * Normalise the uninstall checkbox and refuse changes from users who may
	 * not manage the plugin.
	 *
	 * The capability check only applies when a user is logged in: WP-CLI,
	 * cron and tests update options without a session and are not requests
	 * that could be forged.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public function sanitize_delete_data( $value ): bool {
		if ( is_user_logged_in() && ! Guard::current_user_can() ) {
			add_settings_error(
				Uninstaller::OPTION_DELETE_DATA,
				'wpcheckpoint_forbidden',
				__( 'Sorry, you are not allowed to change WP Checkpoint settings.', 'wp-checkpoint' )
			);
			return Uninstaller::should_delete_data();
		}
		return self::to_bool( $value );
	}

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
