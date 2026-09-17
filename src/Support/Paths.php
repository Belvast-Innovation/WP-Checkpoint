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
		if ( '' === $base || '' === $target ) {
			return false;
		}

		$target = rtrim( $target, '/\\' );
		if ( '' === $target || is_link( $target ) ) {
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
	 * @param string $base   Directory.
	 * @param string $target Path.
	 * @return bool
	 */
	public static function is_same_or_inside( string $base, string $target ): bool {
		if ( '' === $base || '' === $target ) {
			return false;
		}
		$real_base   = realpath( $base );
		$real_target = realpath( rtrim( $target, '/\\' ) );
		if ( false === $real_base || false === $real_target ) {
			return false;
		}
		$ci = self::is_windows();
		return self::same( $real_base, $real_target, $ci ) || self::is_prefix( $real_base, $real_target, $ci );
	}

	/**
	 * Whether two resolved paths denote the same location.
	 *
	 * @param string $a                First path.
	 * @param string $b                Second path.
	 * @param bool   $case_insensitive Fold case before comparing (Windows).
	 * @return bool
	 */
	public static function same( string $a, string $b, bool $case_insensitive ): bool {
		$a = rtrim( self::normalize( $a ), '/' );
		$b = rtrim( self::normalize( $b ), '/' );
		if ( $case_insensitive ) {
			$a = strtolower( $a );
			$b = strtolower( $b );
		}
		return $a === $b;
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
