<?php
/**
 * Content groups whose directory is not positively this site's: what the restore asks about them.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Support\Paths;
use WPCheckpoint\Support\StorageReclaim;

defined( 'ABSPATH' ) || exit;

/**
 * The restore replaces a group's directory where it finally is. When that is another installation's (a staging site
 * whose uploads are the production site's, through a link or through WordPress's own settings, UPLOADS or
 * upload_path), the restore would change that installation's files. So it is judged by where the directory finally
 * lands (one realpath() of the whole path), never by how the path gets there, and only positive evidence makes it
 * this site's (judge()):
 *
 * - it is in this site's WordPress directory, in the trusted deployment root, or in the directory of the
 *   wp-config.php this site loaded (Bedrock; a deployment's current link), that one only when it is neither the
 *   root of a file system nor a home directory (config_zone());
 * - and no directory between it and that zone (the zone itself not included) holds wp-load.php or wp-config.php:
 *   such a directory is another installation's root (a staging site inside the production site's directory).
 *
 * Anything else is asked about in the files preflight, with no default: swap it as usual, or leave it out of the
 * restore (its live directory stays as it is). A directory that cannot be resolved, or a directory on the way up
 * that cannot be listed, is asked about too, saying which path could not be told. The answer holds for the very
 * groups and directories its question named (id()). A restore nobody attends says it up front (the policy
 * POLICY_KEY, RestoreJob).
 */
final class LinkedTargets {

	/**
	 * The restore policy and the prefix of the question's id.
	 */
	const POLICY_KEY = 'linked_targets';

	/**
	 * The question's kind.
	 */
	const KIND = 'link_targets_outside_site';

	/**
	 * The answers.
	 */
	const SWAP    = 'swap';
	const EXCLUDE = 'exclude';
	const CHOICES = array( self::SWAP, self::EXCLUDE );

	/**
	 * Verdicts: this site's; outside every zone of this site; inside another installation's root; cannot be told.
	 */
	const SITE         = 'site';
	const OUTSIDE      = 'outside';
	const INSTALLATION = 'installation';
	const UNKNOWN      = 'unknown';

	/**
	 * Home directories, as the masks of paths know them (Report::mask_paths()): the directory under one of these is a
	 * user's.
	 */
	const HOMES = '#\A((?:[A-Za-z]:)?/(?:home\d*|users|var/www/vhosts|srv/users|usr/home|export/home)/[^/]+)(?:/|\z)#i';

	/**
	 * The directories that hold home directories (each of them broader than one home).
	 */
	const HOME_ROOTS = '#\A(?:[A-Za-z]:)?/(?:home\d*|users|var/www/vhosts|srv/users|usr/home|export/home)\z#i';

	/**
	 * The files whose presence in a directory makes it a WordPress installation's root.
	 */
	const ROOT_FILES = array( 'wp-load.php', 'wp-config.php' );

	/**
	 * A directory where it finally is, with one realpath() of the whole path (normalised, no trailing slash); a
	 * directory that is not there, by its parent's realpath() and its name when it is positively not there; '' when
	 * neither can be told.
	 *
	 * @param string $given The directory as WordPress names it.
	 * @return string
	 */
	public static function resolve( string $given ): string {
		$given = rtrim( Paths::normalize( $given ), '/' );
		if ( 1 !== preg_match( '#\A(?:/|[A-Za-z]:/)#', $given ) ) {
			return ''; // Not absolute: it would be resolved against whatever directory this request works in.
		}
		$real = Paths::real( $given );
		if ( false !== $real ) {
			return rtrim( Paths::normalize( (string) $real ), '/' );
		}
		// Not there, or not to be told: its parent where it is (realpath() follows ".." as the system does), then
		// whether it is positively not there, asked of that place.
		$name   = basename( $given );
		$parent = Paths::real( dirname( $given ) );
		if ( false === $parent || '.' === $name || '..' === $name ) {
			return '';
		}
		$where = rtrim( Paths::normalize( (string) $parent ), '/' ) . '/' . $name;
		return Paths::positively_gone( $where ) ? $where : '';
	}

	/**
	 * The wp-config.php WordPress loads for a WordPress directory, by wp-load.php's own rule: the one in it, else the
	 * one in its parent when the parent is not itself a WordPress directory (no wp-settings.php there). '' when none.
	 *
	 * @param string $abspath The WordPress directory.
	 * @return string
	 */
	public static function config_file( string $abspath ): string {
		$abspath = rtrim( Paths::normalize( $abspath ), '/' );
		if ( '' === $abspath ) {
			return '';
		}
		if ( @is_file( $abspath . '/wp-config.php' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir: not there.
			return $abspath . '/wp-config.php';
		}
		$parent = dirname( $abspath );
		if ( @is_file( $parent . '/wp-config.php' ) && ! @is_file( $parent . '/wp-settings.php' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
			return $parent . '/wp-config.php';
		}
		return '';
	}

	/**
	 * The directory of the wp-config.php this site loads, as a zone of this site: '' when it is the root of a file
	 * system, a home directory (by its name as given or where it is, or the home of the user PHP runs as), or a
	 * directory that holds home directories: it would take in everything below it.
	 *
	 * @param string        $dir     The directory, resolved ('' when not known).
	 * @param callable|null $is_home function( string $dir ): bool, whether it is a home directory (tests); otherwise
	 *                               HOMES, HOME_ROOTS and the user's home.
	 * @param string        $given   The directory as found, before it was resolved ('' when the same).
	 * @return string
	 */
	public static function config_zone( string $dir, $is_home = null, string $given = '' ): string {
		$dir = rtrim( Paths::normalize( $dir ), '/' );
		if ( '' === $dir || 1 === preg_match( '#\A(?:[A-Za-z]:|//[^/]+/[^/]+)\z#', $dir ) ) {
			return ''; // A file system's root ("/" normalises to "").
		}
		if ( is_callable( $is_home ) ) {
			return call_user_func( $is_home, $dir ) ? '' : $dir;
		}
		$user = StorageReclaim::home_directory();
		$user = '' === $user ? false : Paths::real( $user );
		foreach ( array_filter( array( $dir, rtrim( Paths::normalize( $given ), '/' ) ) ) as $name ) {
			if ( 0 === strcasecmp( self::home( $name ), $name ) || 1 === preg_match( self::HOME_ROOTS, $name ) ) {
				return '';
			}
		}
		if ( false !== $user && self::within( $dir, rtrim( Paths::normalize( (string) $user ), '/' ) ) ) {
			return ''; // The user's home, or a directory holding it.
		}
		return $dir;
	}

	/**
	 * The innermost zone of this site the resolved directory is in ('' when none).
	 *
	 * @param string   $resolved The directory, resolved.
	 * @param string[] $zones    Zones, resolved ('' entries ignored).
	 * @return string
	 */
	public static function boundary( string $resolved, array $zones ): string {
		$boundary = '';
		foreach ( $zones as $zone ) {
			$zone = rtrim( (string) $zone, '/' );
			if ( '' !== $zone && self::within( $zone, $resolved ) && strlen( $zone ) > strlen( $boundary ) ) {
				$boundary = $zone;
			}
		}
		return $boundary;
	}

	/**
	 * The first directory from the resolved one up to the boundary (the boundary itself not included) that is
	 * another installation's root (it holds wp-load.php or wp-config.php), or that cannot be told about.
	 *
	 * @param string $resolved The directory, resolved.
	 * @param string $boundary The zone it is in.
	 * @return array{verdict: string, at: string} SITE when there is none.
	 */
	public static function installation_between( string $resolved, string $boundary ): array {
		$dir   = $resolved;
		$floor = strlen( $boundary );
		while ( true ) {
			$length = strlen( $dir );
			if ( $length <= $floor || ! self::within( $boundary, $dir ) ) {
				break;
			}
			clearstatcache( true, $dir );
			if ( false === @lstat( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- told apart below.
				if ( ! Paths::positively_gone( $dir ) ) {
					return array(
						'verdict' => self::UNKNOWN,
						'at'      => $dir,
					);
				}
			} else {
				$holds = is_dir( $dir ) ? self::holds_root_file( $dir ) : null;
				if ( null === $holds ) {
					return array(
						'verdict' => self::UNKNOWN,
						'at'      => $dir,
					);
				}
				if ( $holds ) {
					return array(
						'verdict' => self::INSTALLATION,
						'at'      => $dir,
					);
				}
			}
			$up = rtrim( Paths::normalize( dirname( $dir ) ), '/' );
			if ( $up === $dir ) {
				break;
			}
			$dir = $up;
		}
		return array(
			'verdict' => self::SITE,
			'at'      => '',
		);
	}

	/**
	 * Whether a directory's listing holds wp-load.php or wp-config.php, read an entry at a time and stopped at the
	 * first (memory bounded whatever the directory holds); null when it cannot be listed.
	 *
	 * @param string $dir Directory.
	 * @return bool|null
	 */
	private static function holds_root_file( string $dir ) {
		$handle = @opendir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- not listable: null.
		if ( false === $handle ) {
			return null;
		}
		try {
			while ( false !== ( $name = readdir( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- the readdir() idiom.
				if ( in_array( $name, self::ROOT_FILES, true ) ) {
					return true;
				}
			}
		} finally {
			closedir( $handle );
		}
		return false;
	}

	/**
	 * Whether a group's directory is positively this site's, and if not, why (SITE needs no question).
	 *
	 * @param string   $given The directory as WordPress names it.
	 * @param string[] $zones This site's zones, resolved: its WordPress directory, the trusted deployment root, the
	 *                        directory of its wp-config.php (config_zone()).
	 * @return array{verdict: string, target: string, at: string} The target is where the directory finally is (or the
	 *                                                            path as given when that cannot be told).
	 */
	public static function judge( string $given, array $zones ): array {
		$resolved = self::resolve( $given );
		if ( '' === $resolved ) {
			$path = rtrim( Paths::normalize( $given ), '/' );
			return array(
				'verdict' => self::UNKNOWN,
				'target'  => $path,
				'at'      => $path,
			);
		}
		$boundary = self::boundary( $resolved, $zones );
		if ( '' === $boundary ) {
			return array(
				'verdict' => self::OUTSIDE,
				'target'  => $resolved,
				'at'      => '',
			);
		}
		$between = self::installation_between( $resolved, $boundary );
		return array(
			'verdict' => $between['verdict'],
			'target'  => $resolved,
			'at'      => $between['at'],
		);
	}

	/**
	 * Whether a resolved path is a directory or in it (strings compared; case folded on Windows).
	 *
	 * @param string $dir  Directory, resolved.
	 * @param string $path Path, resolved.
	 * @return bool
	 */
	public static function within( string $dir, string $path ): bool {
		return Paths::same( $dir, $path, Paths::is_windows() ) || Paths::is_prefix( $dir, $path, Paths::is_windows() );
	}

	/**
	 * The question's id: the policy key and a digest of the groups and their directories. An answer holds only for the
	 * question it was given to; another directory (a link pointed elsewhere since, a setting changed) is another
	 * question.
	 *
	 * @param array<string, string> $targets Group => directory, resolved.
	 * @return string
	 */
	public static function id( array $targets ): string {
		ksort( $targets, SORT_STRING );
		$canonical = array();
		foreach ( $targets as $group => $target ) {
			$canonical[] = bin2hex( (string) $group ) . ':' . bin2hex( (string) $target );
		}
		return self::POLICY_KEY . '_' . substr( hash( 'sha256', implode( ';', $canonical ) ), 0, 16 );
	}

	/**
	 * The home directory a path is in ('' when it is in none).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function home( string $path ): string {
		return 1 === preg_match( self::HOMES, Paths::normalize( $path ), $m ) ? strtolower( $m[1] ) : '';
	}

	/**
	 * Whether a directory is in this site's home directory: "same", "other", or "unknown" (this site is in no home
	 * directory, so nothing can be said).
	 *
	 * @param string $target       Directory.
	 * @param string $abspath_real This site's WordPress directory, resolved.
	 * @return string
	 */
	public static function relation( string $target, string $abspath_real ): string {
		$site = self::home( $abspath_real );
		if ( '' === $site ) {
			return 'unknown';
		}
		return self::home( $target ) === $site ? 'same' : 'other';
	}

	/**
	 * The question's lines: each group with its directory as masked, why it is asked about (outside this site's
	 * directories; inside another installation, at which directory; or which path could not be told), whether it
	 * is in this site's home directory (said, not shown: the mask hides whose home it is), and a number where two
	 * directories read the same once masked.
	 *
	 * @param array<int, array{group: string, target: string, relation: string, verdict?: string, at?: string}> $entries Entries.
	 * @param callable                                                                                          $clean   Text cleaner (masks paths).
	 * @return string[]
	 */
	public static function lines( array $entries, callable $clean ): array {
		$shown = array();
		foreach ( $entries as $i => $entry ) {
			$shown[ $i ] = (string) call_user_func( $clean, (string) $entry['target'] );
		}
		$counts = array_count_values( $shown );
		$seen   = array();
		$out    = array();
		foreach ( $entries as $i => $entry ) {
			$line = sprintf( '%1$s: %2$s', (string) $entry['group'], $shown[ $i ] );
			if ( $counts[ $shown[ $i ] ] > 1 ) {
				$seen[ $shown[ $i ] ] = ( $seen[ $shown[ $i ] ] ?? 0 ) + 1;
				/* translators: %d: number of the directory among those that read the same */
				$line .= ' ' . sprintf( __( '(target %d)', 'wp-checkpoint' ), $seen[ $shown[ $i ] ] );
			}
			$verdict = (string) ( $entry['verdict'] ?? self::OUTSIDE );
			$at      = (string) call_user_func( $clean, (string) ( $entry['at'] ?? '' ) );
			if ( self::INSTALLATION === $verdict ) {
				/* translators: %s: a directory */
				$line .= ' ' . sprintf( __( '(inside another WordPress installation, at %s)', 'wp-checkpoint' ), $at );
			} elseif ( self::UNKNOWN === $verdict ) {
				/* translators: %s: a path */
				$line .= ' ' . sprintf( __( '(whether it is this site\'s could not be told: %s could not be read)', 'wp-checkpoint' ), $at );
			} else {
				$line .= ' ' . __( '(outside this site\'s directories)', 'wp-checkpoint' );
			}
			if ( 'same' === $entry['relation'] ) {
				$line .= ' ' . __( '(in this site\'s home directory)', 'wp-checkpoint' );
			} elseif ( 'other' === $entry['relation'] ) {
				$line .= ' ' . __( '(not in this site\'s home directory)', 'wp-checkpoint' );
			}
			$out[] = $line;
		}
		return $out;
	}
}
