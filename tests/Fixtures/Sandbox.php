<?php

namespace WPCheckpoint\Tests\Fixtures;

use WPCheckpoint\Files\Links;

/**
 * Deleting in tests, apart from the Deleter: only under the system's temporary directory, never following a link.
 * Tests delete through this class or through the Deleter (TestDeletionUsageTest): a test's path that is empty,
 * relative, or anywhere else is refused before anything is touched, whatever state the test was left in.
 */
final class Sandbox {

	/**
	 * A new directory under the system's temporary directory, by its real path.
	 *
	 * @param string $label A word for its name.
	 * @return string
	 * @throws \RuntimeException When it cannot be made.
	 */
	public static function make( string $label ): string {
		$path = rtrim( sys_get_temp_dir(), '/\\' ) . DIRECTORY_SEPARATOR . 'wpc-' . $label . '-' . bin2hex( random_bytes( 4 ) );
		if ( ! mkdir( $path, 0755, true ) ) {
			throw new \RuntimeException( 'The sandbox could not be made: ' . $path );
		}
		$real = realpath( $path );
		if ( false === $real ) {
			throw new \RuntimeException( 'The sandbox cannot be resolved: ' . $path );
		}
		return $real;
	}

	/**
	 * Why a path may not be removed, or '' when it may: it must be absolute, without "." or ".." segments, strictly
	 * under the temporary directory both as written and as its parent resolves, and neither the plugin's directory,
	 * ABSPATH, the working directory, nor any directory holding one of them.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function refusal( string $path ): string {
		if ( '' === $path ) {
			return 'empty path';
		}
		$slashed = str_replace( '\\', '/', $path );
		if ( 1 !== preg_match( '#\A(?:/|[A-Za-z]:/|//)#', $slashed ) ) {
			return 'relative path';
		}
		if ( 1 === preg_match( '#(?:\A|/)\.{1,2}(?:/|\z)#', $slashed ) ) {
			return 'dot segment';
		}
		$temp = self::slashed( (string) sys_get_temp_dir() );
		$real = realpath( sys_get_temp_dir() );
		if ( false === $real ) {
			return 'no temporary directory';
		}
		$real = self::slashed( $real );
		if ( 1 === preg_match( '#\A(?:/|[A-Za-z]:/?|//[^/]+/[^/]+/?)\z#', $real ) ) {
			return 'the temporary directory is a filesystem root';
		}
		$target = self::slashed( $slashed );
		if ( ! self::strictly_inside( $temp, $target ) && ! self::strictly_inside( $real, $target ) ) {
			return 'outside the temporary directory';
		}
		$parent = realpath( dirname( $path ) );
		if ( false !== $parent ) {
			// Where it is, through any link on the way (not the entry itself: a link is removed, not followed).
			$target = self::slashed( $parent ) . '/' . basename( $target );
			if ( ! self::strictly_inside( $real, $target ) ) {
				return 'outside the temporary directory, through a link';
			}
		}
		$protected = array( dirname( __DIR__, 2 ), (string) getcwd() );
		if ( defined( 'ABSPATH' ) ) {
			$protected[] = (string) ABSPATH;
		}
		foreach ( $protected as $keep ) {
			$keep = '' === $keep ? false : realpath( $keep );
			if ( false !== $keep && ( self::strictly_inside( $target, self::slashed( $keep ) ) || self::same( $target, self::slashed( $keep ) ) ) ) {
				return 'holds the plugin, the site or the working directory';
			}
		}
		return '';
	}

	/**
	 * Remove a file, a link (not what it leads to) or a directory tree; a path that is not there is fine.
	 *
	 * @param string $path Path under the temporary directory.
	 * @return bool Whether it is gone.
	 * @throws \LogicException When the path is refused (refusal()); nothing is touched.
	 */
	public static function remove( string $path ): bool {
		$why = self::refusal( $path );
		if ( '' !== $why ) {
			throw new \LogicException( sprintf( 'Sandbox::remove( %s ) refused: %s. Nothing was deleted.', var_export( $path, true ), $why ) );
		}
		self::remove_entry( rtrim( $path, '/\\' ) );
		clearstatcache();
		return ! file_exists( $path ) && ! is_link( $path );
	}

	/**
	 * Remove one entry, entering only a directory known not to be a link.
	 *
	 * @param string $path Path.
	 * @return void
	 */
	private static function remove_entry( string $path ): void {
		clearstatcache();
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return;
		}
		$state = Links::state( $path );
		if ( Links::LINK === $state ) {
			if ( ! @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a directory link on Windows needs rmdir().
				@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- what remains is reported by remove().
			}
			return;
		}
		if ( ! is_dir( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- what remains is reported by remove().
			return;
		}
		if ( Links::PLAIN === $state ) {
			foreach ( (array) scandir( $path ) as $entry ) {
				if ( '.' !== $entry && '..' !== $entry ) {
					self::remove_entry( $path . DIRECTORY_SEPARATOR . $entry );
				}
			}
		}
		// Not known to be plain: not entered. rmdir() removes it when empty, or when it is a junction.
		@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- what remains is reported by remove().
	}

	/**
	 * A path with "/" separators and no trailing one.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function slashed( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		return strlen( $path ) > 1 ? rtrim( $path, '/' ) : $path;
	}

	/**
	 * Whether two slashed paths are the same (without regard to letter case on Windows).
	 *
	 * @param string $a Path.
	 * @param string $b Path.
	 * @return bool
	 */
	private static function same( string $a, string $b ): bool {
		return 'Windows' === PHP_OS_FAMILY ? 0 === strcasecmp( $a, $b ) : $a === $b;
	}

	/**
	 * Whether the slashed path $inner is strictly under the slashed path $outer.
	 *
	 * @param string $outer Path.
	 * @param string $inner Path.
	 * @return bool
	 */
	private static function strictly_inside( string $outer, string $inner ): bool {
		$outer = rtrim( $outer, '/' ) . '/';
		return strlen( $inner ) > strlen( $outer ) && self::same( substr( $inner, 0, strlen( $outer ) ), $outer );
	}
}
