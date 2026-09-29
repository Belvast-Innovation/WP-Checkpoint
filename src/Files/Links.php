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
	const INTO_SKIPPED    = 'into_skipped';
	const HOLDS_GROUP     = 'holds_group';
	const UNDECIDED       = 'undecided';

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
		// "/", "C:\", and the root of a network share ("\\server\share").
		if ( '' === $trimmed || dirname( $target ) === $target || 1 === preg_match( '#\A(?:[A-Za-z]:|[\\\\/]{2}[^\\\\/]+[\\\\/][^\\\\/]+)\z#', $trimmed ) ) {
			return self::FILESYSTEM_ROOT;
		}
		// Unknown or unresolvable: nothing can say the link does not lead onto the site itself.
		if ( '' === rtrim( $abspath, '/\\' ) || false === realpath( $abspath ) ) {
			return self::SITE_UNKNOWN;
		}
		return Paths::is_same_or_inside( $target, $abspath ) ? self::HOLDS_SITE : '';
	}

	/**
	 * The one judgement of a content root, for the scan and the pack step alike: whether it is a link, and why it
	 * is not scanned ('' when it is). Beyond root_refusal(): a root that cannot be told apart from a link is not
	 * scanned (UNDECIDED), a link that leads into one of the directories in $skip (the plugin's storage
	 * directory, ScanRoots) is refused (INTO_SKIPPED), and so is one that leads to or above a
	 * directory in $hold (HOLDS_GROUP), compared by real path.
	 *
	 * @param string        $path    The root.
	 * @param string        $abspath The WordPress directory (ABSPATH); '' when unknown.
	 * @param string[]      $skip    Directories the root skips.
	 * @param callable|null $state   Link test (state()); tests inject.
	 * @param string[]      $hold    Directories the link must not lead to or above (HOLDS_GROUP): the content
	 *                               directory (ScanRoots).
	 * @return array{link: bool, refusal: string}
	 */
	public static function root_verdict( string $path, string $abspath, array $skip = array(), $state = null, array $hold = array() ): array {
		$link = (string) call_user_func( null === $state ? array( self::class, 'state' ) : $state, $path );
		if ( self::UNKNOWN === $link ) {
			return array(
				'link'    => false,
				'refusal' => self::UNDECIDED,
			);
		}
		if ( self::LINK !== $link ) {
			return array(
				'link'    => false,
				'refusal' => '',
			);
		}
		$refusal = self::root_refusal( $path, $abspath );
		if ( '' === $refusal ) {
			$target = (string) realpath( $path );
			foreach ( $skip as $dir ) {
				if ( Paths::is_same_or_inside( (string) $dir, $target ) ) {
					$refusal = self::INTO_SKIPPED;
					break;
				}
			}
		}
		if ( '' === $refusal ) {
			foreach ( $hold as $dir ) {
				if ( Paths::is_same_or_inside( (string) realpath( $path ), (string) $dir ) ) {
					$refusal = self::HOLDS_GROUP;
					break;
				}
			}
		}
		return array(
			'link'    => true,
			'refusal' => $refusal,
		);
	}

	/**
	 * A path as a comparison key: its real path when it resolves (as written otherwise), with "/" separators and
	 * no trailing one, case-folded on Windows.
	 *
	 * @param string $path Path.
	 * @param bool   $real Resolve it first.
	 * @return string
	 */
	public static function key( string $path, bool $real = true ): string {
		$resolved = $real ? realpath( $path ) : false;
		$key      = rtrim( Paths::normalize( false === $resolved ? $path : $resolved ), '/' );
		return Paths::is_windows() ? strtolower( $key ) : $key;
	}

	/**
	 * Where a root leads, as a hash (never a path: it is kept in the cursor and the scan summary): the scan
	 * records it and the pack step refuses a root that leads somewhere else by then. '' when it does not resolve.
	 *
	 * @param string $path The root.
	 * @return string
	 */
	public static function fingerprint( string $path ): string {
		return false === realpath( $path ) ? '' : hash( 'sha256', self::key( $path ) );
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
			case self::INTO_SKIPPED:
				return 'it leads into the plugin\'s storage directory';
			case self::HOLDS_GROUP:
				return 'it leads to the content directory or a directory that holds it';
			case self::UNDECIDED:
				return 'it could not be determined whether it is a link';
			default:
				return 'the WordPress directory could not be located to check where it leads';
		}
	}
}
