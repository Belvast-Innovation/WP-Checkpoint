<?php
/**
 * Removes plugin data when the plugin is deleted.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\LockFile;

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
		Directories::OPTION,
		\WPCheckpoint\Backups\Estimate::OPTION,
		\WPCheckpoint\Backups\Estimate::RATE_OPTION,
	);

	/**
	 * User meta keys the plugin owns.
	 *
	 * @var string[]
	 */
	const USER_META = array( 'wpcheckpoint_dismissed_notices' );

	/**
	 * Whether the user asked for a full clean-up.
	 *
	 * @return bool
	 */
	public static function should_delete_data(): bool {
		return UninstallSetting::enabled();
	}

	/**
	 * Run the uninstall routine.
	 *
	 * @return void
	 */
	public static function run(): void {
		self::clear_transient_state();
		self::cancel_jobs();

		if ( ! self::should_delete_data() ) {
			return;
		}

		self::delete_storage();
		Schema::drop();
		self::delete_options();
		self::delete_user_meta();
	}

	/**
	 * Cancel every queued, running or paused job and remove its lock file:
	 * nothing can continue once the plugin is gone, and a driver still
	 * holding a lock must stop before the directory or the table disappears.
	 * Runs whether or not data is deleted, so a reinstall does not find jobs
	 * that look alive.
	 *
	 * @return int Jobs cancelled.
	 */
	public static function cancel_jobs(): int {
		global $wpdb;
		if ( ! Schema::table_exists() ) {
			return 0;
		}
		$table = $wpdb->base_prefix . Schema::JOBS_TABLE;
		$state = Directories::load_state();
		$path  = is_string( $state['path'] ) ? rtrim( $state['path'], '/\\' ) : '';
		$live  = array( Job::QUEUED, Job::RUNNING, Job::PAUSED );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table name from the prefix.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, storage_path FROM {$table} WHERE status IN (%s, %s, %s)", $live[0], $live[1], $live[2] ), ARRAY_A );
		$now  = time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table name from the prefix.
		$affected = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, finished_at = %d, updated_at = %d, lock_token = '', locked_until = 0 WHERE status IN (%s, %s, %s)", Job::CANCELLED, $now, $now, $live[0], $live[1], $live[2] ) );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			// Only the current directory's files: another directory is another installation's.
			if ( '' !== $path && Paths::same( (string) $row['storage_path'], $path, Paths::is_windows() ) ) {
				LockFile::remove( $path, (int) $row['id'] );
			}
		}
		return max( 0, (int) $affected );
	}

	/**
	 * Delete the storage directory, but only when it demonstrably belongs to
	 * this installation.
	 *
	 * A custom directory (WPCHECKPOINT_STORAGE_DIR) is emptied of the plugin's
	 * own sub-directories and files; the directory itself is left alone.
	 *
	 * @return array{deleted: int, failed: string[]}
	 */
	public static function delete_storage(): array {
		$none  = array(
			'deleted' => 0,
			'failed'  => array(),
		);
		$state = Directories::load_state();
		$path  = is_string( $state['path'] ) ? rtrim( $state['path'], '/\\' ) : '';
		if ( '' === $path || ! is_dir( $path ) || Deleter::is_reparse( $path ) ) {
			return $none;
		}

		$marker = $path . DIRECTORY_SEPARATOR . OwnerMarker::FILENAME;
		if ( ! is_file( $marker ) ) {
			return $none;
		}
		$contents = file_get_contents( $marker ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- tiny local file.
		if ( ! is_string( $contents ) || ! OwnerMarker::matches( $contents, (string) $state['install_id'], ABSPATH ) ) {
			return $none;
		}

		if ( Directories::SOURCE_CUSTOM === $state['source'] ) {
			$result = $none;
			foreach ( Directories::SUBDIRS as $sub ) {
				if ( is_dir( $path . DIRECTORY_SEPARATOR . $sub ) ) {
					$part               = Deleter::delete_tree( $path, $path . DIRECTORY_SEPARATOR . $sub );
					$result['deleted'] += $part['deleted'];
					$result['failed']   = array_merge( $result['failed'], $part['failed'] );
				}
			}
			foreach ( array( 'index.php', '.htaccess', OwnerMarker::FILENAME ) as $file ) {
				if ( is_file( $path . DIRECTORY_SEPARATOR . $file ) ) {
					$part               = Deleter::delete_tree( $path, $path . DIRECTORY_SEPARATOR . $file );
					$result['deleted'] += $part['deleted'];
					$result['failed']   = array_merge( $result['failed'], $part['failed'] );
				}
			}
			return $result;
		}

		if ( basename( $path ) !== Directories::DIR_PREFIX . $state['token'] ) {
			return $none;
		}
		$result = Deleter::empty_directory( $path );
		if ( array() === $result['failed'] ) {
			if ( @rmdir( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- failure is reported below.
				++$result['deleted'];
			} else {
				$result['failed'][] = $path;
			}
		}
		return $result;
	}

	/**
	 * Delete the plugin's user meta for every user.
	 *
	 * @return void
	 */
	public static function delete_user_meta(): void {
		foreach ( self::USER_META as $key ) {
			delete_metadata( 'user', 0, $key, '', true );
		}
	}

	/**
	 * Remove state that has no value after deletion, whatever the user chose.
	 *
	 * @return void
	 */
	public static function clear_transient_state(): void {
		\WPCheckpoint\Jobs\Loopback::unschedule_all();
		delete_site_transient( 'wpcheckpoint_jobs_reaped' );
		delete_site_transient( 'wpcheckpoint_jobs_purged' );
		delete_site_transient( 'wpcheckpoint_jobs_swept' );
		delete_site_transient( 'wpcheckpoint_foreign_tables' );
		delete_site_transient( Environment::CACHE );
	}

	/**
	 * Delete every option the plugin owns.
	 *
	 * @return void
	 */
	public static function delete_options(): void {
		foreach ( self::OPTIONS as $option ) {
			Options::delete( $option );
		}
		UninstallSetting::delete_everywhere();
	}
}
