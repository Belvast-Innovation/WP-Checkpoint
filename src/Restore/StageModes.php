<?php
/**
 * The permissions of staged files and directories.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * This site's own: FS_CHMOD_DIR and FS_CHMOD_FILE when WordPress defined
 * them (wp-config.php, or WP_Filesystem()), otherwise what WordPress
 * derives them from (ABSPATH's and index.php's permissions, with at least
 * 0755 and 0644). A backup's modes are never used: they come from another
 * server, and a staged file must be what this site writes itself.
 */
final class StageModes {

	/**
	 * Mode of a staged directory.
	 *
	 * @return int
	 */
	public static function dir(): int {
		if ( defined( 'FS_CHMOD_DIR' ) ) {
			return (int) FS_CHMOD_DIR;
		}
		$perms = @fileperms( ABSPATH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unknown falls back below.
		return ( false === $perms ? 0 : $perms & 0777 ) | 0755;
	}

	/**
	 * Mode of a staged file.
	 *
	 * @return int
	 */
	public static function file(): int {
		if ( defined( 'FS_CHMOD_FILE' ) ) {
			return (int) FS_CHMOD_FILE;
		}
		$perms = @fileperms( ABSPATH . 'index.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unknown falls back below.
		return ( false === $perms ? 0 : $perms & 0777 ) | 0644;
	}
}
