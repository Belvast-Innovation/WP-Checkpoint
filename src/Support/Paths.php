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
	 * comparison ignores case, matching NTFS.
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
