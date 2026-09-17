<?php
/**
 * The "delete data on uninstall" setting.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Installation-wide: a site option on single sites, a network option on
 * multisite (Options helper). On multisite, a missing network option is
 * initialised to "off"; an "on" left in any site's options is never
 * inherited, because that would delete every site's backups on uninstall.
 * Instead a notice asks the super admin to confirm the setting again.
 */
final class UninstallSetting {

	const OPTION      = Uninstaller::OPTION_DELETE_DATA;
	const NOTICE_FLAG = 'wpcheckpoint_uninstall_setting_notice';

	/**
	 * Whether deleting data on uninstall is enabled.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return (bool) Options::get( self::OPTION, false );
	}

	/**
	 * Store the setting; confirming it clears the migration notice.
	 *
	 * @param bool $enabled New value.
	 * @return void
	 */
	public static function save( bool $enabled ): void {
		// Stored as 1/0: update_network_option() treats a new value of false as "unchanged" and writes nothing.
		Options::set( self::OPTION, $enabled ? 1 : 0 );
		if ( is_multisite() ) {
			delete_site_option( self::NOTICE_FLAG );
		}
	}

	/**
	 * On multisite, initialise the network option once and detect leftover
	 * per-site values.
	 *
	 * @return array{ran: bool, leftover: bool} "ran" is false when nothing had to be done.
	 */
	public static function migrate_multisite(): array {
		$result = array(
			'ran'      => false,
			'leftover' => false,
		);
		if ( ! is_multisite() ) {
			return $result;
		}
		$missing = new \stdClass();
		if ( get_site_option( self::OPTION, $missing ) !== $missing ) {
			return $result;
		}

		// Never inherit "on" from a site option: the network value starts as "off"
		// (stored as 0, see save()).
		update_site_option( self::OPTION, 0 );
		$result['ran'] = true;

		foreach ( self::site_ids() as $site_id ) {
			if ( (bool) get_blog_option( $site_id, self::OPTION, false ) ) {
				$result['leftover'] = true;
				break;
			}
		}
		if ( $result['leftover'] ) {
			update_site_option( self::NOTICE_FLAG, 1 );
		}
		return $result;
	}

	/**
	 * Whether the super admin still has to confirm the setting after migration.
	 *
	 * @return bool
	 */
	public static function needs_confirmation(): bool {
		return is_multisite() && (bool) get_site_option( self::NOTICE_FLAG, false );
	}

	/**
	 * Remove the setting and the notice flag everywhere (uninstall).
	 *
	 * @return void
	 */
	public static function delete_everywhere(): void {
		Options::delete( self::OPTION );
		if ( ! is_multisite() ) {
			return;
		}
		delete_site_option( self::NOTICE_FLAG );
		foreach ( self::site_ids() as $site_id ) {
			delete_blog_option( $site_id, self::OPTION );
		}
	}

	/**
	 * Site IDs of the network (capped).
	 *
	 * @return int[]
	 */
	private static function site_ids(): array {
		$ids = get_sites(
			array(
				'number' => 1000,
				'fields' => 'ids',
			)
		);
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}
}
