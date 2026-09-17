<?php
/**
 * Recursive deletion confined to a base directory.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

// phpcs:disable WordPress.WP.AlternativeFunctions -- pure PHP deleter also used from uninstall.php; WP_Filesystem is unavailable there and would follow links.

/**
 * Deletes trees the plugin owns without ever following a link.
 *
 * Every path is checked with Paths::is_inside() against the base before it is
 * touched, symbolic links and junctions are removed as links (their targets
 * are never entered), and failures are collected instead of thrown.
 */
final class Deleter {

	/**
	 * Delete a file or directory tree strictly inside $base.
	 *
	 * @param string $base   Directory the plugin owns.
	 * @param string $target Entry to delete; must be inside $base.
	 * @return array{deleted: int, failed: string[]}
	 */
	public static function delete_tree( string $base, string $target ): array {
		$result = array(
			'deleted' => 0,
			'failed'  => array(),
		);

		$target = rtrim( $target, '/\\' );
		if ( '' === $target ) {
			$result['failed'][] = $target;
			return $result;
		}

		if ( self::is_reparse( $target ) ) {
			// A link may be removed only when the link itself sits inside base.
			if ( ! Paths::is_inside( $base, dirname( $target ) ) && ! self::same_dir( $base, dirname( $target ) ) ) {
				$result['failed'][] = $target;
				return $result;
			}
			self::remove_link( $target, $result );
			return $result;
		}

		if ( ! Paths::is_inside( $base, $target ) ) {
			$result['failed'][] = $target;
			return $result;
		}

		$real = realpath( $target );
		if ( false === $real ) {
			$result['failed'][] = $target;
			return $result;
		}

		if ( is_dir( $real ) ) {
			self::delete_children( $base, $real, $result );
			self::remove_dir( $real, $result );
		} else {
			self::remove_file( $real, $result );
		}

		return $result;
	}

	/**
	 * Delete everything inside $base but keep $base itself.
	 *
	 * @param string $base Directory the plugin owns.
	 * @return array{deleted: int, failed: string[]}
	 */
	public static function empty_directory( string $base ): array {
		$result = array(
			'deleted' => 0,
			'failed'  => array(),
		);
		$real   = realpath( rtrim( $base, '/\\' ) );
		if ( false === $real || ! is_dir( $real ) || self::is_reparse( $base ) ) {
			$result['failed'][] = $base;
			return $result;
		}
		self::delete_children( $real, $real, $result );
		return $result;
	}

	/**
	 * Whether a path is a symbolic link, junction or other reparse point.
	 *
	 * The is_link() check covers symbolic links. Windows junctions are not
	 * reported by is_link(), but PHP's Windows readlink() returns the final
	 * path of any directory (it resolves junctions and symlinks), so a
	 * directory whose final path is not its parent's final path plus its own
	 * name is a reparse point. Elsewhere, a child entry is resolved through
	 * the directory as a fallback: realpath()
	 * resolves reparse points in intermediate path components on every
	 * platform (PHP's Windows implementation strips a trailing "." before
	 * resolving, so the entry must be a real child), and a result that is not
	 * the parent's real path plus the entry name means the entry redirects
	 * somewhere else. An empty directory has no child to probe; entering it
	 * cannot delete anything, and rmdir() on a junction removes the junction.
	 *
	 * @param string $path Path to inspect (no trailing separator).
	 * @return bool
	 */
	public static function is_reparse( string $path ): bool {
		$path = rtrim( $path, '/\\' );
		if ( '' === $path ) {
			return false;
		}
		if ( is_link( $path ) ) {
			return true;
		}
		if ( ! is_dir( $path ) ) {
			return false;
		}
		if ( Paths::is_windows() ) {
			// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- readlink() warns when it cannot resolve; handled below.
			$final        = @readlink( $path );
			$final_parent = @readlink( dirname( $path ) );
			// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_string( $final ) && '' !== $final && is_string( $final_parent ) && '' !== $final_parent ) {
				$expected = rtrim( self::strip_windows_prefix( $final_parent ), '/\\' ) . DIRECTORY_SEPARATOR . basename( $path );
				return ! Paths::same( $expected, self::strip_windows_prefix( $final ), true );
			}
		}

		$parent = realpath( dirname( $path ) );
		if ( false === $parent ) {
			return false;
		}
		$child = self::first_child( $path );
		if ( '' === $child ) {
			return false;
		}
		$resolved = realpath( $path . DIRECTORY_SEPARATOR . $child );
		if ( false === $resolved ) {
			return false;
		}
		$expected = rtrim( $parent, '/\\' ) . DIRECTORY_SEPARATOR . basename( $path ) . DIRECTORY_SEPARATOR . $child;
		return ! Paths::same( $expected, $resolved, Paths::is_windows() );
	}

	/**
	 * Remove the \\?\ and \\?\UNC\ prefixes Windows may put on final paths.
	 *
	 * @param string $path Path from readlink().
	 * @return string
	 */
	private static function strip_windows_prefix( string $path ): string {
		if ( 0 === strpos( $path, '\\\\?\\UNC\\' ) ) {
			return '\\\\' . substr( $path, 8 );
		}
		if ( 0 === strpos( $path, '\\\\?\\' ) ) {
			return substr( $path, 4 );
		}
		return $path;
	}

	/**
	 * Name of the first entry inside a directory, or empty when it is empty
	 * or unreadable.
	 *
	 * @param string $dir Directory.
	 * @return string
	 */
	private static function first_child( string $dir ): string {
		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable directories simply yield no child.
		if ( ! is_array( $entries ) ) {
			return '';
		}
		foreach ( $entries as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				return $entry;
			}
		}
		return '';
	}

	/**
	 * Delete the children of a real directory.
	 *
	 * @param string                                $base   Owning base directory.
	 * @param string                                $dir    Real directory path.
	 * @param array{deleted: int, failed: string[]} $result Accumulator.
	 * @return void
	 */
	private static function delete_children( string $base, string $dir, array &$result ): void {
		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable directories are reported as failures.
		if ( false === $entries ) {
			$result['failed'][] = $dir;
			return;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( self::is_reparse( $path ) ) {
				self::remove_link( $path, $result );
				continue;
			}
			if ( ! Paths::is_inside( $base, $path ) ) {
				$result['failed'][] = $path;
				continue;
			}
			if ( is_dir( $path ) ) {
				self::delete_children( $base, $path, $result );
				self::remove_dir( $path, $result );
			} else {
				self::remove_file( $path, $result );
			}
		}
	}

	/**
	 * Remove a link without touching its target.
	 *
	 * @param string                                $path   Link path.
	 * @param array{deleted: int, failed: string[]} $result Accumulator.
	 * @return void
	 */
	private static function remove_link( string $path, array &$result ): void {
		// Directory links on Windows must be removed with rmdir().
		if ( @unlink( $path ) || @rmdir( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure is recorded below.
			++$result['deleted'];
			return;
		}
		$result['failed'][] = $path;
	}

	/**
	 * Remove a regular file.
	 *
	 * @param string                                $path   File path.
	 * @param array{deleted: int, failed: string[]} $result Accumulator.
	 * @return void
	 */
	private static function remove_file( string $path, array &$result ): void {
		if ( @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure is recorded below.
			++$result['deleted'];
			return;
		}
		$result['failed'][] = $path;
	}

	/**
	 * Remove an (empty) directory.
	 *
	 * @param string                                $path   Directory path.
	 * @param array{deleted: int, failed: string[]} $result Accumulator.
	 * @return void
	 */
	private static function remove_dir( string $path, array &$result ): void {
		if ( @rmdir( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure is recorded below.
			++$result['deleted'];
			return;
		}
		$result['failed'][] = $path;
	}

	/**
	 * Whether two directories resolve to the same location.
	 *
	 * @param string $a First directory.
	 * @param string $b Second directory.
	 * @return bool
	 */
	private static function same_dir( string $a, string $b ): bool {
		$ra = realpath( $a );
		$rb = realpath( $b );
		return false !== $ra && false !== $rb && Paths::same( $ra, $rb, Paths::is_windows() );
	}
}
