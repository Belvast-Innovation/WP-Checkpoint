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
	 * Notice flag values: a site-level "on" was found, or the network was too
	 * large to scan and the super admin should confirm the setting anyway.
	 */
	const NOTICE_LEFTOVER  = 'leftover';
	const NOTICE_UNSCANNED = 'unscanned';

	/**
	 * Networks with more sites than this are not scanned site by site.
	 */
	const SCAN_LIMIT = 50;

	/**
	 * Sites fetched per page when walking the whole network.
	 */
	const BATCH_SIZE = 500;

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
	 * Small networks are scanned site by site. Larger ones (more than
	 * SCAN_LIMIT sites) are not: that would mean one query per site, and a
	 * capped scan could miss exactly the site whose administrator needs the
	 * notice; the notice is shown regardless, with neutral wording.
	 *
	 * @param int|null $site_count Number of sites; null queries the network (tests inject it).
	 * @return array{ran: bool, scanned: bool, leftover: bool} "ran" is false when nothing had to be done.
	 */
	public static function migrate_multisite( $site_count = null ): array {
		$result = array(
			'ran'      => false,
			'scanned'  => false,
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

		if ( null === $site_count ) {
			$site_count = (int) get_sites( array( 'count' => true ) );
		}
		if ( $site_count > self::SCAN_LIMIT ) {
			update_site_option( self::NOTICE_FLAG, self::NOTICE_UNSCANNED );
			return $result;
		}

		$result['scanned'] = true;
		foreach ( self::site_ids( self::SCAN_LIMIT ) as $site_id ) {
			if ( (bool) get_blog_option( $site_id, self::OPTION, false ) ) {
				$result['leftover'] = true;
				break;
			}
		}
		if ( $result['leftover'] ) {
			update_site_option( self::NOTICE_FLAG, self::NOTICE_LEFTOVER );
		}
		return $result;
	}

	/**
	 * Why the confirmation notice is shown: NOTICE_LEFTOVER, NOTICE_UNSCANNED or ''.
	 *
	 * @return string
	 */
	public static function notice_reason(): string {
		if ( ! is_multisite() ) {
			return '';
		}
		$flag = get_site_option( self::NOTICE_FLAG, '' );
		if ( self::NOTICE_UNSCANNED === $flag ) {
			return self::NOTICE_UNSCANNED;
		}
		return empty( $flag ) ? '' : self::NOTICE_LEFTOVER;
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
	 * @param int $batch_size Sites per page while walking the network (tests use a small value).
	 * @return void
	 */
	public static function delete_everywhere( int $batch_size = self::BATCH_SIZE ): void {
		Options::delete( self::OPTION );
		if ( ! is_multisite() ) {
			return;
		}
		delete_site_option( self::NOTICE_FLAG );
		$batch_size = max( 1, $batch_size );
		$offset     = 0;
		do {
			$ids     = self::site_ids( $batch_size, $offset );
			$fetched = count( $ids );
			foreach ( $ids as $site_id ) {
				delete_blog_option( $site_id, self::OPTION );
			}
			$offset += $batch_size;
		} while ( $fetched === $batch_size );
	}

	/**
	 * A page of site IDs.
	 *
	 * @param int $number Page size.
	 * @param int $offset Offset.
	 * @return int[]
	 */
	private static function site_ids( int $number, int $offset = 0 ): array {
		$ids = get_sites(
			array(
				'number'  => $number,
				'offset'  => $offset,
				'fields'  => 'ids',
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}
}
