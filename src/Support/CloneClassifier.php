<?php
/**
 * Decides whether an ABSPATH change looks like a deployment or a clone.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

/**
 * Pure classification of a storage owner mismatch.
 *
 * PHP resolves symlinks in __FILE__, so with release-directory deployments
 * (Capistrano, Deployer, Trellis: current -> releases/2026...) ABSPATH is
 * the real release path and changes on every deploy while the database and
 * the shared wp-content stay the same. That is the main case this class
 * recognises: the previous and the current ABSPATH are siblings under the
 * same parent directory AND the layout looks like releases (the parent is
 * named "releases", or both directory names are release identifiers such as
 * timestamps, Git hashes or version numbers). Plain siblings such as
 * /var/www/example.com and /var/www/staging.example.com are the most common
 * clone layout on a shared server and are never treated as a deployment.
 */
final class CloneClassifier {

	const DEPLOYMENT = 'deployment';
	const MOVED      = 'moved';
	const CLONE      = 'clone';

	const RECOMMEND_ORIGINAL = 'original';
	const RECOMMEND_NEW      = 'new';

	/**
	 * Parent directory names that hold releases by convention.
	 *
	 * @var string[]
	 */
	const RELEASE_PARENTS = array( 'releases' );

	/**
	 * Directories far too broad to be trusted as a deployment root.
	 *
	 * @var string[]
	 */
	const BROAD_ROOTS = array( '/', '/home', '/var/www', '/srv', '/users', '/var', '/usr', '/opt', '/tmp', '/mnt', '/data' );

	/**
	 * Directory names that denote a web root rather than a releases folder.
	 *
	 * @var string[]
	 */
	const WEB_ROOT_NAMES = array( 'public_html', 'htdocs', 'www', 'httpdocs', 'html', 'web', 'public', 'wwwroot' );

	/**
	 * Classify a mismatch.
	 *
	 * @param string        $previous_abspath ABSPATH recorded when the directory was adopted.
	 * @param string        $current_abspath  ABSPATH of this request.
	 * @param callable|null $is_dir           Directory existence probe (defaults to is_dir()).
	 * @param callable|null $realpath         Path resolver (defaults to realpath()).
	 * @return array{verdict: string, recommendation: string, previous_exists: bool, previous_is_wordpress: bool, siblings: bool, release_layout: bool, deploy_root: string}
	 */
	public static function classify( string $previous_abspath, string $current_abspath, $is_dir = null, $realpath = null ): array {
		$is_dir   = is_callable( $is_dir ) ? $is_dir : 'is_dir';
		$realpath = is_callable( $realpath ) ? $realpath : 'realpath';

		$previous = rtrim( Paths::normalize( $previous_abspath ), '/' );
		$current  = rtrim( Paths::normalize( $current_abspath ), '/' );

		$previous_exists = '' !== $previous && (bool) call_user_func( $is_dir, $previous );
		$previous_is_wp  = $previous_exists && (bool) call_user_func( $is_dir, $previous . '/wp-includes' );

		$siblings       = false;
		$release_layout = false;
		$deploy_root    = '';
		if ( '' !== $previous && '' !== $current && $previous !== $current ) {
			$previous_parent = self::parent( $previous, $realpath );
			$current_parent  = self::parent( $current, $realpath );
			if ( '' !== $current_parent && Paths::same( $previous_parent, $current_parent, Paths::is_windows() ) ) {
				$siblings       = true;
				$release_layout = self::is_release_layout( $current_parent, basename( $previous ), basename( $current ) );
				$deploy_root    = $release_layout ? $current_parent : '';
			}
		}

		if ( $siblings && $release_layout ) {
			$verdict        = self::DEPLOYMENT;
			$recommendation = self::RECOMMEND_ORIGINAL;
		} elseif ( ! $previous_is_wp ) {
			$verdict        = self::MOVED;
			$recommendation = self::RECOMMEND_ORIGINAL;
		} else {
			$verdict        = self::CLONE;
			$recommendation = self::RECOMMEND_NEW;
		}

		return array(
			'verdict'               => $verdict,
			'recommendation'        => $recommendation,
			'previous_exists'       => $previous_exists,
			'previous_is_wordpress' => $previous_is_wp,
			'siblings'              => $siblings,
			'release_layout'        => $release_layout,
			'deploy_root'           => $deploy_root,
		);
	}

	/**
	 * Whether sibling directories look like releases of one site.
	 *
	 * @param string $parent_dir    Real parent directory.
	 * @param string $previous_name Previous directory name.
	 * @param string $current_name  Current directory name.
	 * @return bool
	 */
	public static function is_release_layout( string $parent_dir, string $previous_name, string $current_name ): bool {
		if ( in_array( strtolower( basename( $parent_dir ) ), self::RELEASE_PARENTS, true ) ) {
			return true;
		}
		return self::is_release_name( $previous_name ) && self::is_release_name( $current_name );
	}

	/**
	 * Whether a directory name is a release identifier: digits (timestamps),
	 * a Git hash (7-40 hex characters) or a version number.
	 *
	 * @param string $name Directory name.
	 * @return bool
	 */
	public static function is_release_name( string $name ): bool {
		return 1 === preg_match( '/^(?:\d+|[0-9a-f]{7,40}|v?\d+(?:\.\d+)+)$/i', $name );
	}

	/**
	 * Whether a directory is too broad to be trusted as a deployment root:
	 * the filesystem root, well-known top-level directories, the user's
	 * home directory, or a directory named like a web root.
	 *
	 * @param string $root Real directory.
	 * @param string $home The current user's home directory, if known.
	 * @return bool
	 */
	public static function is_broad_deploy_root( string $root, string $home = '' ): bool {
		$normalized = rtrim( Paths::normalize( $root ), '/' );
		if ( '' === $normalized || 1 === preg_match( '#^[A-Za-z]:$#', $normalized ) ) {
			return true;
		}
		$lower = strtolower( $normalized );
		if ( in_array( $lower, self::BROAD_ROOTS, true ) ) {
			return true;
		}
		if ( 1 === preg_match( '#^[a-z]:/(?:users|inetpub|xampp|wamp)?$#', $lower ) ) {
			return true;
		}
		$home = rtrim( Paths::normalize( $home ), '/' );
		if ( '' !== $home && Paths::same( $home, $normalized, Paths::is_windows() ) ) {
			return true;
		}
		if ( 1 === preg_match( '#^/(?:home|users|var/www/vhosts|srv/users)/[^/]+$#i', $normalized ) ) {
			return true;
		}
		return in_array( strtolower( basename( $normalized ) ), self::WEB_ROOT_NAMES, true );
	}

	/**
	 * Real parent directory of a path (the path itself may no longer exist).
	 *
	 * @param string   $path     Normalised path without trailing slash.
	 * @param callable $realpath Path resolver.
	 * @return string Normalised parent, empty when it cannot be resolved.
	 */
	public static function parent( string $path, $realpath = null ): string {
		$realpath = is_callable( $realpath ) ? $realpath : 'realpath';
		$parent   = dirname( $path );
		if ( '' === $parent || '.' === $parent || $parent === $path ) {
			return '';
		}
		$real = call_user_func( $realpath, $parent );
		return is_string( $real ) && '' !== $real ? rtrim( Paths::normalize( $real ), '/' ) : '';
	}
}
