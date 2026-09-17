<?php
/**
 * Removes plugin data when the plugin is deleted.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Called from uninstall.php after the plugin has been deactivated.
 *
 * Backups are user data. They, the settings and the tables are only removed
 * when the user opted in beforehand on the Settings tab; otherwise only
 * transient state is cleared so a reinstall picks up where it left off.
 */
final class Uninstaller {

	/**
	 * Option holding the user's choice (boolean, default false).
	 */
	const OPTION_DELETE_DATA = 'wpcheckpoint_delete_data_on_uninstall';

	/**
	 * Option holding the installed plugin version.
	 */
	const OPTION_VERSION = 'wpcheckpoint_version';

	/**
	 * Every option the plugin owns. Keep in sync when adding options.
	 *
	 * @var string[]
	 */
	const OPTIONS = array(
		self::OPTION_VERSION,
		self::OPTION_DELETE_DATA,
	);

	/**
	 * Whether the user asked for a full clean-up.
	 *
	 * @return bool
	 */
	public static function should_delete_data(): bool {
		return (bool) get_option( self::OPTION_DELETE_DATA, false );
	}

	/**
	 * Run the uninstall routine.
	 *
	 * @return void
	 */
	public static function run(): void {
		self::clear_transient_state();

		if ( ! self::should_delete_data() ) {
			return;
		}

		self::delete_options();
		// T003: delete the backup directory, guarded by Paths::is_inside().
		// T010: drop the plugin tables.
	}

	/**
	 * Remove state that has no value after deletion, whatever the user chose.
	 *
	 * @return void
	 */
	public static function clear_transient_state(): void {
		// No scheduled events or transients exist yet; later tasks add them here.
	}

	/**
	 * Delete every option the plugin owns.
	 *
	 * @return void
	 */
	public static function delete_options(): void {
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
	}
}
