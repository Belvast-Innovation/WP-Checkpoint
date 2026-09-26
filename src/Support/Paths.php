<?php
/**
 * Filesystem path checks that do not depend on WordPress.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

/**
 * Pure helpers for deciding whether a path may be touched.
 */
final class Paths {

	/**
	 * Whether $target is a real descendant of $base.
	 *
	 * Both paths are resolved with realpath(), so ".." segments and symlinked
	 * parents cannot escape the base. A target that is itself a symlink is
	 * rejected (trailing separators are stripped first, otherwise lstat would
	 * follow the link): callers must remove the link, never what it points to.
	 * The base directory itself is not considered inside. On Windows the
	 * comparison ignores case, matching NTFS; note that realpath() there does
	 * not resolve a symlink given as the final path component, so a symlinked
	 * base makes every target fail the check (fail-closed).
	 *
	 * @param string $base   Directory the plugin created and owns.
	 * @param string $target Path that is about to be deleted or written.
	 * @return bool
	 */
	public static function is_inside( string $base, string $target ): bool {
		$base   = rtrim( $base, '/\\' );
		$target = rtrim( $target, '/\\' );
		if ( '' === $base || '' === $target || is_link( $target ) ) {
			return false;
		}

		$real_base = realpath( $base );
		if ( false === $real_base || ! is_dir( $real_base ) ) {
			return false;
		}

		$real_target = realpath( $target );
		if ( false === $real_target ) {
			return false;
		}

		return self::is_prefix( $real_base, $real_target, self::is_windows() );
	}

	/**
	 * Whether $target is $base itself or a real descendant of it.
	 *
	 * Used for "is this location under the document root" questions where the
	 * root itself counts. Symlinks are resolved on both sides.
	 *
	 * An empty string, or one that is nothing but separators, is refused on
	 * either side: realpath( '' ) is the working directory, so such an input
	 * would answer a question about the wrong root.
	 *
	 * @param string $base   Directory.
	 * @param string $target Path.
	 * @return bool
	 */
	public static function is_same_or_inside( string $base, string $target ): bool {
		$base   = rtrim( $base, '/\\' );
		$target = rtrim( $target, '/\\' );
		if ( '' === $base || '' === $target ) {
			return false;
		}
		$real_base   = realpath( $base );
		$real_target = realpath( $target );
		if ( false === $real_base || false === $real_target ) {
			return false;
		}
		$ci = self::is_windows();
		return self::same( $real_base, $real_target, $ci ) || self::is_prefix( $real_base, $real_target, $ci );
	}

	/**
	 * Whether two resolved paths denote the same location. Two empty (or
	 * separator-only) strings are not "the same location": a job whose
	 * storage path was never set must not pass an ownership check.
	 *
	 * @param string $a                First path.
	 * @param string $b                Second path.
	 * @param bool   $case_insensitive Fold case before comparing (Windows).
	 * @return bool
	 */
	public static function same( string $a, string $b, bool $case_insensitive ): bool {
		$a = rtrim( self::normalize( $a ), '/' );
		$b = rtrim( self::normalize( $b ), '/' );
		if ( '' === $a || '' === $b ) {
			return false;
		}
		if ( $case_insensitive ) {
			$a = strtolower( $a );
			$b = strtolower( $b );
		}
		return $a === $b;
	}

	/**
	 * Whether two paths name the same directory or file: the same as
	 * written, or the same once resolved (realpath(): links, "..", a second
	 * spelling). A path that cannot be resolved (it does not exist, or
	 * open_basedir hides it) is compared as written only, so the answer is
	 * "no" unless the spellings match: callers that refuse on "no" wait
	 * rather than act on a path they cannot see.
	 *
	 * @param string $a First path.
	 * @param string $b Second path.
	 * @return bool
	 */
	public static function same_location( string $a, string $b ): bool {
		$windows = self::is_windows();
		if ( self::same( $a, $b, $windows ) ) {
			return true;
		}
		if ( '' === $a || '' === $b ) {
			return false;
		}
		$ra = @realpath( $a ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would put the path into the error log.
		$rb = @realpath( $b ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		return false !== $ra && false !== $rb && self::same( $ra, $rb, $windows );
	}

	/**
	 * Whether a path is positively gone: it is absolute, the file system
	 * finds nothing under that name (lstat(), which matches case and
	 * Unicode forms the way the file system does), and the nearest ancestor
	 * that exists can be listed and has no entry for the next segment down,
	 * not even one that differs only in case. Only ancestors that do not
	 * exist are climbed past: one that exists but cannot be listed
	 * (permissions, open_basedir) is no evidence, and neither is reaching
	 * the root of a network share (\\server\share, or //server/share) or
	 * of the file system without a listing, nor a relative path: false.
	 * What a listing cannot show is not covered: a mount that is not
	 * mounted right now (an automount, another mount namespace) looks
	 * like a directory without that entry.
	 *
	 * @param string                                 $path     Path.
	 * @param array<string, array<int, string>|null> $listings Listings by directory (null: not listable), reused across calls.
	 * @return bool
	 */
	public static function positively_gone( string $path, array &$listings = array() ): bool {
		$path     = self::normalize( $path );
		$path     = in_array( $path, array( '/', '//' ), true ) ? $path : rtrim( $path, '/' );
		$drive    = self::is_windows() && 1 === preg_match( '#^[A-Za-z]:/#', $path );
		$absolute = '' !== $path && ( '/' === $path[0] || $drive );
		if ( ! $absolute || false !== @lstat( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would put the path into the error log.
			return false;
		}
		$child  = $path;
		$parent = self::parent_of( $child );
		while ( null !== $parent ) {
			if ( ! array_key_exists( $parent, $listings ) ) {
				$entries             = @scandir( $parent ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
				$listings[ $parent ] = is_array( $entries ) ? array_map( 'strtolower', $entries ) : null;
			}
			$listed = $listings[ $parent ];
			if ( null !== $listed ) {
				return ! in_array( strtolower( basename( $child ) ), $listed, true );
			}
			if ( false !== @lstat( $parent ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
				return false; // There but not listable: nothing above it says anything about $path.
			}
			$child  = $parent;
			$parent = self::parent_of( $child );
		}
		return false;
	}

	/**
	 * The parent of a normalised absolute path, or null above which nothing is climbed: the root of the file
	 * system ("/", "C:/") and the root of a network share ("//server/share": its server is not a directory).
	 *
	 * @param string $path Normalised absolute path, without a trailing separator (except a root).
	 * @return string|null
	 */
	private static function parent_of( string $path ) {
		if ( '/' === $path || 1 === preg_match( '#^[A-Za-z]:/?$#', $path ) || 1 === preg_match( '#^//[^/]+(/[^/]+)?$#', $path ) ) {
			return null;
		}
		$slash = strrpos( $path, '/' );
		if ( false === $slash ) {
			return null;
		}
		$parent = substr( $path, 0, $slash );
		if ( '' === $parent ) {
			return '/';
		}
		return 1 === preg_match( '#^[A-Za-z]:$#', $parent ) ? $parent . '/' : $parent;
	}

	/**
	 * Whether $target lies strictly below $base, comparing normalised paths.
	 *
	 * Separated from is_inside() so the comparison rules (separator
	 * normalisation, case folding) can be unit tested on every platform.
	 *
	 * @param string $base             Resolved base directory.
	 * @param string $target           Resolved target path.
	 * @param bool   $case_insensitive Fold case before comparing (Windows).
	 * @return bool
	 */
	public static function is_prefix( string $base, string $target, bool $case_insensitive ): bool {
		$base   = rtrim( self::normalize( $base ), '/' ) . '/';
		$target = self::normalize( $target );

		if ( $case_insensitive ) {
			$base   = strtolower( $base );
			$target = strtolower( $target );
		}

		return strlen( $target ) > strlen( $base ) && 0 === strpos( $target, $base );
	}

	/**
	 * Normalise directory separators the way wp_normalize_path() does.
	 *
	 * Uses core when loaded; the fallback mirrors it so the class stays usable
	 * in pure PHP unit tests and in uninstall.php.
	 *
	 * @param string $path Path to normalise.
	 * @return string
	 */
	public static function normalize( string $path ): string {
		if ( function_exists( 'wp_normalize_path' ) ) {
			return wp_normalize_path( $path );
		}

		$path = str_replace( '\\', '/', $path );
		$path = (string) preg_replace( '|(?<=.)/+|', '/', $path );
		if ( ':' === substr( $path, 1, 1 ) ) {
			$path = ucfirst( $path );
		}
		return $path;
	}

	/**
	 * Whether PHP runs on Windows.
	 *
	 * @return bool
	 */
	public static function is_windows(): bool {
		return 'Windows' === PHP_OS_FAMILY;
	}
}
