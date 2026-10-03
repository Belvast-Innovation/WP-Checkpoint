<?php
/**
 * Content groups whose directory is not positively this site's: what the restore asks about them.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Support\CloneClassifier;
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
 *   wp-config.php this site loads (Bedrock; a deployment's current link; zones()), that one only when it is not the
 *   root of a file system, a home directory or a broad directory that holds sites (config_zone()), and when none of
 *   its direct subdirectories but this site's own is another installation's root (zone_stands());
 * - and no directory between it and that zone (the zone itself not included) is an installation's root
 *   (root_state(): it holds wp-load.php or wp-config.php, or WordPress's front controller with the core beside it;
 *   a staging site inside the production site's directory, WordPress in its own directory).
 *
 * Anything else is asked about in the files preflight, with no default: swap it as usual, or leave it out of the
 * restore (its live directory stays as it is). A directory that cannot be resolved, or one on the way up whose
 * listing cannot be read, is asked about too, saying which path could not be told. The answer holds for the very
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
	 * Home directories: the directory under one of these is a user's.
	 */
	const HOMES = '#\A((?:[A-Za-z]:)?/(?:home\d*|users|var/www/vhosts|srv/users|usr/home|export/home)/[^/]+)(?:/|\z)#i'; // Those Report::mask_paths() masks, in any case.

	/**
	 * The directories that hold home directories (each of them broader than one home).
	 */
	const HOME_ROOTS = '#\A(?:[A-Za-z]:)?/(?:home\d*|users|var/www/vhosts|srv/users|usr/home|export/home)\z#i';

	/**
	 * The files whose presence in a directory makes it a WordPress installation's root.
	 */
	const ROOT_FILES = array( 'wp-load.php', 'wp-config.php' );

	/**
	 * Broad directories that hold sites rather than being one, besides CloneClassifier::BROAD_ROOTS: never the zone of
	 * a wp-config.php.
	 */
	const BROAD = array( '/srv/www', '/srv/http', '/usr/local/www' );

	/**
	 * Most entries of a directory read to tell whether it is an installation's root by its subdirectories, or whether
	 * the zone of a wp-config.php holds another installation beside this site: more cannot be told.
	 */
	const CHILDREN_LIMIT = 1000;

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
	 * The wp-config.php this site loads, as named (not resolved): under WP-CLI the one WP-CLI loads (it evaluates the
	 * file rather than including it, so the included files never show it, and honours WP_CONFIG_PATH), otherwise the
	 * one wp-load.php loads (config_file()). '' when there is none.
	 *
	 * @param string $abspath The WordPress directory.
	 * @return string
	 */
	public static function config_location( string $abspath ): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			// WP-CLI's order (WP_CLI\Utils\locate_wp_config()) without resolving the file: a wp-config.php that is a
			// link (a deployment's shared one) is judged where it is named, as a web request finds it.
			$told = getenv( 'WP_CONFIG_PATH' );
			if ( is_string( $told ) && '' !== $told && @is_file( $told ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir: not there.
				return rtrim( Paths::normalize( $told ), '/' );
			}
		}
		return self::config_file( $abspath );
	}

	/**
	 * The directory of the wp-config.php this site loads, as a zone of this site: '' when it is the root of a file
	 * system, a home directory (by its name as given or where it is, or the home of the user PHP runs as), a
	 * directory that holds home directories, or a broad directory that holds sites (/var/www, /srv/www and the like):
	 * it would take in everything below it. Whether a zone stands is then judged by what is in it (zone_stands()).
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
			if ( 0 === strcasecmp( self::home( $name ), $name ) || 1 === preg_match( self::HOME_ROOTS, $name ) || self::broad( $name ) ) {
				return '';
			}
		}
		if ( false !== $user && self::within( $dir, rtrim( Paths::normalize( (string) $user ), '/' ) ) ) {
			return ''; // The user's home, or a directory holding it.
		}
		return $dir;
	}

	/**
	 * This site's zones, the way the restore takes them: its WordPress directory and the trusted deployment root,
	 * resolved, and the directory of its wp-config.php when it is a zone (config_zone()) and stands (zone_stands());
	 * '' for each that is none.
	 *
	 * @param string        $abspath    The WordPress directory.
	 * @param string        $trusted    The trusted deployment root ('' when none).
	 * @param string        $config_dir The directory of the wp-config.php, as found ('' when none).
	 * @param callable|null $is_home    See config_zone() (tests).
	 * @return string[] Three entries.
	 */
	public static function zones( string $abspath, string $trusted, string $config_dir, $is_home = null ): array {
		$out = array();
		foreach ( array( $abspath, $trusted, $config_dir ) as $dir ) {
			$trimmed = rtrim( $dir, '/\\' );
			// A file system's root is no zone; and realpath() of '' or of a bare drive is the working directory.
			$real  = '' === $trimmed || 1 === preg_match( '#\A[A-Za-z]:\z#', $trimmed ) ? false : Paths::real( $trimmed );
			$out[] = false === $real ? '' : rtrim( Paths::normalize( (string) $real ), '/' );
		}
		$zone   = '' === $out[2] ? '' : self::config_zone( $out[2], $is_home, $config_dir );
		$out[2] = '' !== $zone && self::zone_stands( $zone, $out[0] ) ? $zone : '';
		return $out;
	}

	/**
	 * Whether a directory is a broad one that holds sites rather than being one.
	 *
	 * @param string $dir Directory, normalised.
	 * @return bool
	 */
	private static function broad( string $dir ): bool {
		$lower = strtolower( $dir );
		return in_array( $lower, CloneClassifier::BROAD_ROOTS, true ) || in_array( $lower, self::BROAD, true ) || 1 === preg_match( '#\A[a-z]:/(?:users|inetpub|xampp|wamp)\z#', $lower );
	}

	/**
	 * Whether the zone of a wp-config.php stands: none of its direct subdirectories but the one this site's WordPress
	 * directory is in (its branch) is another installation's root (root_state()). Names would always miss some
	 * (/data/www, /opt/sites): a zone that holds another site beside this one is broader than this site, and a shared
	 * directory beside them (another site's UPLOADS) would be taken in. A subdirectory that is a link counts where it
	 * leads (a link to this site's branch is the branch). A zone whose entries, or where they lead, cannot all be told,
	 * or that has more than CHILDREN_LIMIT, does not stand.
	 *
	 * @param string $zone         The zone, resolved.
	 * @param string $abspath_real This site's WordPress directory, resolved.
	 * @return bool
	 */
	public static function zone_stands( string $zone, string $abspath_real ): bool {
		$branch = '';
		if ( '' !== $abspath_real && Paths::is_prefix( $zone, $abspath_real, Paths::is_windows() ) ) {
			$rest   = substr( $abspath_real, strlen( rtrim( $zone, '/' ) ) + 1 );
			$branch = (string) strtok( $rest, '/' );
		}
		$handle = @opendir( $zone ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- not listable: does not stand.
		if ( false === $handle ) {
			return false;
		}
		$count = 0;
		try {
			while ( false !== ( $name = readdir( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- the readdir() idiom.
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				if ( ++$count > self::CHILDREN_LIMIT ) {
					return false;
				}
				$child = $zone . '/' . $name;
				if ( $name === $branch ) {
					continue; // This site's branch.
				}
				$kind = self::kind( $child );
				if ( 'unknown' === $kind ) {
					return false; // What it is, or what it leads to, cannot be told.
				}
				if ( 'dir' !== $kind ) {
					continue; // A file, or a link to one.
				}
				// A link is judged by where it leads: another site linked into the zone sits beside this one all the same.
				$real = Paths::real( $child );
				if ( false === $real ) {
					return false;
				}
				$real = rtrim( Paths::normalize( (string) $real ), '/' );
				if ( '' !== $branch && self::within( $zone . '/' . $branch, $real ) ) {
					continue; // This site's branch by another name (a deployment's current).
				}
				if ( false !== self::root_state( $real ) ) {
					return false; // Another installation's root, or a directory that cannot be told.
				}
			}
		} finally {
			closedir( $handle );
		}
		return true;
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
	 * another installation's root (root_state()), or that cannot be told about.
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
				$holds = is_dir( $dir ) ? self::root_state( $dir ) : null;
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
	 * Whether a directory is a WordPress installation's root: it holds wp-load.php or wp-config.php, or (WordPress in
	 * its own directory) its index.php is WordPress's front controller (it loads wp-blog-header.php) and one of its
	 * direct subdirectories holds wp-load.php. Not merely the parent of an installation: wp-content holds an index.php
	 * of its own ("silence is golden"), and would be a root as soon as a staging site is put in it. Told by the
	 * directory's listing (lists()), never by looking names up one by one; beside a front controller, at most
	 * CHILDREN_LIMIT entries are looked into. Null when it cannot be listed, its index.php cannot be read, or there
	 * are more entries beside a front controller than are looked into (or one of them cannot be listed and none
	 * shows the core).
	 *
	 * @param string $dir Directory.
	 * @return bool|null
	 */
	public static function root_state( string $dir ) {
		$listed = self::lists( $dir, self::ROOT_FILES + array( 2 => 'index.php' ) );
		if ( null === $listed ) {
			return null; // What is in it cannot be read.
		}
		if ( array() !== array_intersect( self::ROOT_FILES, $listed ) ) {
			return true;
		}
		if ( ! in_array( 'index.php', $listed, true ) ) {
			return false;
		}
		$front = self::front_controller( $dir . '/index.php' );
		if ( true !== $front ) {
			return $front; // Not a front controller (false), or one that cannot be read (null).
		}
		// The core beside it: each subdirectory's listing names wp-load.php or not (by listing, as above).
		$handle = @opendir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- not listable: null.
		if ( false === $handle ) {
			return null;
		}
		$count   = 0;
		$unknown = false;
		try {
			while ( false !== ( $name = readdir( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- the readdir() idiom.
				if ( '.' === $name || '..' === $name || 'index.php' === $name ) {
					continue;
				}
				if ( ++$count > self::CHILDREN_LIMIT ) {
					return null; // Too many beside the front controller to look into.
				}
				$kind = self::kind( $dir . '/' . $name );
				if ( 'other' === $kind ) {
					continue; // Positively not a directory.
				}
				if ( 'unknown' === $kind ) {
					$unknown = true; // Read the others: one of them may tell.
					continue;
				}
				$inside = self::lists( $dir . '/' . $name, array( 'wp-load.php' ) );
				if ( null === $inside ) {
					$unknown = true; // Read the others: one of them may tell.
				} elseif ( array() !== $inside ) {
					return true;
				}
			}
		} finally {
			closedir( $handle );
		}
		return $unknown ? null : false;
	}

	/**
	 * What a directory entry is, told only from positive evidence: "dir", "other" (positively not a directory), or
	 * "unknown" (its status cannot be read: a link out of open_basedir, a target that cannot be searched, a thread-safe
	 * PHP's lookup in a directory that cannot be searched). A link counts as what it leads to.
	 *
	 * @param string $path The entry.
	 * @return string
	 */
	private static function kind( string $path ): string {
		$stat = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- not to be read: unknown.
		if ( false === $stat ) {
			return 'unknown';
		}
		$mode = (int) $stat['mode'] & 0170000;
		if ( 0120000 === $mode ) {
			$stat = @stat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
			if ( false === $stat ) {
				return 'unknown';
			}
			$mode = (int) $stat['mode'] & 0170000;
		}
		return 0040000 === $mode ? 'dir' : 'other';
	}

	/**
	 * Which of the names a directory's listing holds, read an entry at a time (memory bounded whatever it holds; time
	 * as many entries as it has); null when it cannot be listed. A listing is the evidence: a name looked up one by
	 * one may not be found for other reasons than not being there (a thread-safe PHP's lookups in a directory that
	 * cannot be searched, open_basedir for a link to a file outside it).
	 *
	 * @param string   $dir   Directory.
	 * @param string[] $names Names, lowercase (the listing is compared without case).
	 * @return string[]|null
	 */
	private static function lists( string $dir, array $names ) {
		$handle = @opendir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- not listable: null.
		if ( false === $handle ) {
			return null;
		}
		$found = array();
		try {
			while ( false !== ( $name = readdir( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- the readdir() idiom.
				$lower = strtolower( $name );
				foreach ( $names as $wanted ) {
					if ( $lower === $wanted ) {
						$found[] = $wanted; // Without case: a file system that ignores it loads it too (and a name in another case only asks).
					}
				}
			}
		} finally {
			closedir( $handle );
		}
		return $found;
	}

	/**
	 * Whether an index.php its directory lists is WordPress's front controller: it loads wp-blog-header.php (its
	 * first 8 KB read). False only for one positively a directory; null when it cannot be read.
	 *
	 * @param string $file The file.
	 * @return bool|null
	 */
	private static function front_controller( string $file ) {
		if ( @is_dir( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- not a directory: read below.
			return false; // A directory named index.php is no front controller.
		}
		$handle = @fopen( $file, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- not readable: null.
		if ( false === $handle ) {
			return null; // Listed, but it cannot be read (or its link's target cannot be: open_basedir).
		}
		$head = @fread( $handle, 8192 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fread -- a bounded read.
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- as above.
		if ( false === $head ) {
			return null;
		}
		return false !== strpos( $head, 'wp-blog-header.php' );
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
	 * The question's id: the policy key and a digest of the groups, their directories and why each is asked about
	 * (for another installation, its root too). An answer holds only for the question it was given to; another
	 * directory (a link pointed elsewhere since, a setting changed), or another reason (outside before, inside
	 * another installation now), is another question.
	 *
	 * @param array<string, array{target: string, verdict: string, at: string}> $why Group => why it is asked about.
	 * @return string
	 */
	public static function id( array $why ): string {
		ksort( $why, SORT_STRING );
		$canonical = array();
		foreach ( $why as $group => $entry ) {
			$canonical[] = implode( ':', array( bin2hex( (string) $group ), bin2hex( (string) $entry['target'] ), (string) $entry['verdict'], bin2hex( self::INSTALLATION === $entry['verdict'] ? (string) $entry['at'] : '' ) ) );
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
