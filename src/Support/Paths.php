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
	 * rejected: callers must remove the link, never what it points to. The
	 * base directory itself is not considered inside.
	 *
	 * @param string $base   Directory the plugin created and owns.
	 * @param string $target Path that is about to be deleted or written.
	 * @return bool
	 */
	public static function is_inside( string $base, string $target ): bool {
		if ( '' === $base || '' === $target ) {
			return false;
		}

		if ( is_link( $target ) ) {
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

		$prefix = rtrim( $real_base, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;

		return 0 === strpos( $real_target, $prefix );
	}
}
