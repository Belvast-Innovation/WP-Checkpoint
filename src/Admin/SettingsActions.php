<?php
/**
 * Saves the plugin settings.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Admin;

use WPCheckpoint\Support\Guard;
use WPCheckpoint\Support\UninstallSetting;

defined( 'ABSPATH' ) || exit;

/**
 * One admin-post entry point for the Settings tab, on single sites and
 * multisite alike (options.php cannot write network options). Guarded by
 * Guard::require_admin_post(), which on multisite means a super admin.
 */
final class SettingsActions {

	const ACTION       = 'wpcheckpoint_save_settings';
	const NONCE_ACTION = 'save-settings';
	const RESULT       = 'saved';

	/**
	 * Hook the admin-post action.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
	}

	/**
	 * POST handler.
	 *
	 * @return void
	 */
	public function save(): void {
		Guard::require_admin_post( self::NONCE_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by require_admin_post() above.
		$raw = isset( $_POST[ UninstallSetting::OPTION ] ) ? sanitize_text_field( wp_unslash( $_POST[ UninstallSetting::OPTION ] ) ) : '';
		$this->run_save( Settings::to_bool( $raw ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                           => Page::SLUG,
					'tab'                            => 'settings',
					EnvironmentActions::RESULT_PARAM => self::RESULT,
				),
				Page::base_url()
			)
		);
		exit;
	}

	/**
	 * Store the settings. Separated for tests.
	 *
	 * @param bool $delete_on_uninstall New value of the uninstall setting.
	 * @return void
	 */
	public function run_save( bool $delete_on_uninstall ): void {
		UninstallSetting::save( $delete_on_uninstall );
	}
}
