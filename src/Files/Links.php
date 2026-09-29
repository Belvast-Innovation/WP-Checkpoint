<?php
/**
 * Whether a directory is a link, and whether a content root that is one may be followed.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Files;

use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Paths;

/**
 * The scan and the pack step judge links the same way, through this class.
 *
 * A content root (uploads, plugins, themes...) that is a link is followed:
 * leaving it out would leave a site's media or plugins out of its backup
 * without a trace, and a link there is common (deployment tools keep
 * uploads in a shared directory, hosts move it to another disk). Only the
 * root itself is followed; links below it are never entered. The directory
 * it leads to must be a directory, must not be the root of the file system,
 * and must not be or hold the WordPress directory: a backup of that would
 * take the whole site, or the whole server, a second time under one group.
 *
 * Whether a directory is a link: on POSIX, is_link() answers. On Windows a
 * directory junction is not reported by is_link(), and the Deleter's
 * reparse check answers, which can be "unknown" (no readlink() on this
 * host and an empty directory, or one holding only links, to probe
 * through). A directory whose answer is unknown is not entered.
 *
 * Pure PHP.
 */
final class Links {

	const LINK    = Deleter::REPARSE_LINK;
	const PLAIN   = Deleter::REPARSE_PLAIN;
	const UNKNOWN = Deleter::REPARSE_UNKNOWN;

	const NOT_A_DIRECTORY = 'not_a_directory';
	const FILESYSTEM_ROOT = 'filesystem_root';
	const HOLDS_SITE      = 'holds_site';
	const SITE_UNKNOWN    = 'site_unknown';

	/**
	 * Whether a path is a link: LINK, PLAIN or UNKNOWN.
	 *
	 * @param string $path Path (no trailing separator).
	 * @return string
	 */
	public static function state( string $path ): string {
		if ( is_link( $path ) ) {
			return self::LINK;
		}
		if ( ! Paths::is_windows() ) {
			return self::PLAIN;
		}
		// Right after is_link(), is_dir() and is_file() read false for a junction on Windows (observed on the CI
		// runner): the answers below must not come from what is_link() left in the stat cache.
		clearstatcache( true, $path );
		if ( is_file( $path ) ) {
			return self::PLAIN;
		}
		clearstatcache( true, $path );
		return Deleter::reparse_state( $path );
	}

	/**
	 * Why a content root that is a link is not followed, or '' when it may be.
	 *
	 * @param string $path    The root (the link).
	 * @param string $abspath The WordPress directory (ABSPATH); '' when unknown.
	 * @return string '' or one of NOT_A_DIRECTORY, FILESYSTEM_ROOT, HOLDS_SITE, SITE_UNKNOWN.
	 */
	public static function root_refusal( string $path, string $abspath ): string {
		$target = realpath( $path );
		if ( false === $target || ! is_dir( $target ) ) {
			return self::NOT_A_DIRECTORY;
		}
		$trimmed = rtrim( $target, '/\\' );
		if ( '' === $trimmed || dirname( $target ) === $target || 1 === preg_match( '/\A[A-Za-z]:\z/', $trimmed ) ) {
			return self::FILESYSTEM_ROOT;
		}
		// Unknown or unresolvable: nothing can say the link does not lead onto the site itself.
		if ( '' === rtrim( $abspath, '/\\' ) || false === realpath( $abspath ) ) {
			return self::SITE_UNKNOWN;
		}
		return Paths::is_same_or_inside( $target, $abspath ) ? self::HOLDS_SITE : '';
	}

	/**
	 * What a refusal means, for the scan's warning.
	 *
	 * @param string $refusal One of the refusal constants.
	 * @return string
	 */
	public static function refusal_text( string $refusal ): string {
		switch ( $refusal ) {
			case self::NOT_A_DIRECTORY:
				return 'it does not lead to a directory';
			case self::FILESYSTEM_ROOT:
				return 'it leads to the root of the file system';
			case self::HOLDS_SITE:
				return 'it leads to the WordPress directory or a directory that holds it';
			default:
				return 'the WordPress directory could not be located to check where it leads';
		}
	}
}
