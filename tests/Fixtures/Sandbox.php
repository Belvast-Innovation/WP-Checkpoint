<?php

namespace WPCheckpoint\Tests\Fixtures;

use WPCheckpoint\Files\Links;
use WPCheckpoint\Support\Paths;

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
	 * Why a path may not be removed, or '' when it may.
	 *
	 * What is removed is decided by where the entry is, never by what it leads to: its location is its directory's
	 * real path and its name as that directory lists it. Nothing about the entry itself is resolved, so a link (a
	 * junction on Windows too) is judged as the link. The rules, which together guarantee that nothing outside the
	 * temporary directory and nothing protected is ever removed (SandboxLayoutsTest checks them on generated
	 * layouts):
	 *
	 * - The path is absolute, without "." or ".." segments, and strictly under the temporary directory as written.
	 * - An entry that is there is named as its directory lists it: exactly, or in another letter case where only one
	 *   listed name matches (a file system that folds case). Any other name for it (a Windows 8.3 short name, say)
	 *   is refused, as is an entry whose directory cannot be resolved or listed.
	 * - Its location is strictly under the temporary directory's real path (not reached through a link out of it).
	 * - Its location is not the plugin's directory, ABSPATH or the working directory, holds none of them and is
	 *   inside none of them (compared without regard to letter case: the safe direction).
	 *
	 * A path that is not there has nothing to remove; it is judged as written.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function refusal( string $path ): string {
		return self::judged( $path );
	}

	/**
	 * refusal() with some of its rules withdrawn: for SandboxLayoutsTest's reverse checks only, which show that the
	 * generated layouts catch what each rule is there for. "listed" judges an entry by the name given; "parent" by
	 * the path as written; "inside" lets through what is inside a protected directory; "resolve" judges an entry
	 * that is there also by where it leads (as before the rules: a link to a protected directory refused);
	 * "backslash" takes the name after the last "\\" everywhere (as before: on POSIX it is part of a name). $resolve,
	 * when given, resolves the entry's directory in place of Paths::real(): a directory that cannot be resolved
	 * (SandboxTest; no way to bring that about for real is known on the platforms the tests run on).
	 *
	 * @param string        $path      Path.
	 * @param string[]      $withdrawn Rules withdrawn.
	 * @param callable|null $resolve   function( string $dir ): string|false.
	 * @return string
	 */
	public static function judged( string $path, array $withdrawn = array(), $resolve = null ): string {
		if ( '' === $path ) {
			return 'empty path';
		}
		// The very string remove() acts on: judged and removed are one entry.
		$path    = self::trimmed( $path );
		// Its name: after the last separator ("backslash" withdrawn: after the last "\\" too, as before).
		$base = basename( in_array( 'backslash', $withdrawn, true ) ? str_replace( '\\', '/', $path ) : $path );
		$slashed = self::slashed( $path );
		if ( 1 !== preg_match( '#\A(?:/|[A-Za-z]:/|//)#', $slashed ) ) {
			return 'relative path';
		}
		if ( 1 === preg_match( '#(?:\A|/)\.{1,2}(?:/|\z)#', $slashed ) ) {
			return 'dot segment';
		}
		$temp = self::slashed( (string) sys_get_temp_dir() );
		$real = Paths::real( sys_get_temp_dir() );
		if ( false === $real ) {
			return 'no temporary directory';
		}
		$real = self::slashed( $real );
		if ( 1 === preg_match( '#\A(?:/|[A-Za-z]:/?|//[^/]+/[^/]+/?)\z#', $real ) ) {
			return 'the temporary directory is a filesystem root';
		}
		$location = self::slashed( $slashed );
		if ( ! self::strictly_inside( $temp, $location ) && ! self::strictly_inside( $real, $location ) ) {
			return 'outside the temporary directory';
		}
		$there  = file_exists( $path ) || is_link( $path );
		$parent = null === $resolve ? Paths::real( dirname( $path ) ) : call_user_func( $resolve, dirname( $path ) );
		if ( in_array( 'parent', $withdrawn, true ) ) {
			$parent = false;
			$there  = false;
		}
		if ( $there ) {
			if ( false === $parent ) {
				return 'its directory cannot be resolved';
			}
			$name = in_array( 'listed', $withdrawn, true ) ? $base : self::listed_name( $parent, $base );
			if ( null === $name ) {
				return 'not named as its directory lists it (a short name or another alias)';
			}
			$location = self::slashed( $parent ) . '/' . $name;
		} elseif ( false !== $parent ) {
			$location = self::slashed( $parent ) . '/' . $base;
		}
		if ( false !== $parent && ! self::strictly_inside( $real, $location ) ) {
			return 'outside the temporary directory, through a link';
		}
		$protected = array( dirname( __DIR__, 2 ), (string) getcwd() );
		if ( defined( 'ABSPATH' ) ) {
			$protected[] = (string) ABSPATH;
		}
		$judged = array( $location );
		if ( $there && in_array( 'resolve', $withdrawn, true ) && false !== Paths::real( $path ) ) {
			$judged[] = self::slashed( (string) Paths::real( $path ) );
		}
		foreach ( $protected as $keep ) {
			$keep = '' === $keep ? false : Paths::real( $keep );
			if ( false === $keep ) {
				continue;
			}
			$keep = strtolower( self::slashed( $keep ) );
			foreach ( $judged as $one ) {
				$at     = strtolower( $one );
				$inside = ! in_array( 'inside', $withdrawn, true ) && 0 === strpos( $at, rtrim( $keep, '/' ) . '/' );
				if ( $at === $keep || 0 === strpos( $keep, rtrim( $at, '/' ) . '/' ) || $inside ) {
					return 'the plugin, the site or the working directory, or holding or inside one';
				}
			}
		}
		return '';
	}

	/**
	 * The name a directory lists an entry under: $name itself, or the one listed name that matches it without regard
	 * to letter case; null when the directory lists neither (or cannot be listed).
	 *
	 * @param string $dir  Directory (real path).
	 * @param string $name Name as given.
	 * @return string|null
	 */
	private static function listed_name( string $dir, string $name ): ?string {
		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unlistable directory refuses.
		if ( false === $entries ) {
			return null;
		}
		if ( in_array( $name, $entries, true ) ) {
			return $name;
		}
		$folded = array_values(
			array_filter(
				$entries,
				static function ( $entry ) use ( $name ): bool {
					return 0 === strcasecmp( (string) $entry, $name );
				}
			)
		);
		return 1 === count( $folded ) ? (string) $folded[0] : null;
	}

	/**
	 * Remove a file, a link (not what it leads to) or a directory tree; a path that is not there is fine.
	 *
	 * @param string $path Path under the temporary directory.
	 * @return bool Whether it is gone.
	 * @throws \LogicException When the path is refused (refusal()); nothing is touched.
	 */
	public static function remove( string $path ): bool {
		$path = self::trimmed( $path );
		$why  = self::refusal( $path );
		if ( '' !== $why ) {
			throw new \LogicException( sprintf( 'Sandbox::remove( %s ) refused: %s. Nothing was deleted.', var_export( $path, true ), $why ) );
		}
		self::remove_entry( $path );
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
	 * A path without trailing separators ("\\" is one on Windows only: elsewhere it is part of a name); a root stays.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function trimmed( string $path ): string {
		if ( 'Windows' === PHP_OS_FAMILY && 1 === preg_match( '#\A[A-Za-z]:[/\\\\]+\z#', $path ) ) {
			return substr( $path, 0, 3 ); // A drive's root: without its separator, "C:" would be a relative path.
		}
		$trimmed = rtrim( $path, 'Windows' === PHP_OS_FAMILY ? '/\\' : '/' );
		return '' === $trimmed ? substr( $path, 0, 1 ) : $trimmed;
	}

	/**
	 * A path with "/" separators (converting "\\" on Windows only) and no trailing one.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function slashed( string $path ): string {
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$path = str_replace( '\\', '/', $path );
			if ( 1 === preg_match( '#\A[A-Za-z]:/+\z#', $path ) ) {
				return substr( $path, 0, 3 ); // A drive's root keeps its separator, as in trimmed().
			}
		}
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
