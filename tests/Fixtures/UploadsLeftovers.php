<?php

namespace WPCheckpoint\Tests\Fixtures;

use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\DeletionRefused;

/**
 * What the integration tests leave in the test site's uploads directory. The names they make there are read from the
 * tests themselves (names(): every "wp_upload_dir(...)['basedir'] . '/NAME" in tests/integration and tests/Fixtures),
 * so the list is the code's, not kept by hand. Before a run (uploads-precheck.php, from bin/test-integration.sh) an
 * entry of the uploads directory that starts with one of them stops the run: an earlier run left it, and a test that
 * makes it again meets it (a failed mkdir) or packs it with its own (an export of the site's uploads). During a run
 * Leftovers lists every top-level entry of the uploads directory but WordPress's own (a year's directory, a network's
 * "sites"), so an entry a test or a class leaves fails it like any other leftover; removal() removes one only when its
 * name starts with a test's name (anything else is said and left alone).
 */
final class UploadsLeftovers {

	/**
	 * The names the tests make in the uploads directory, read from their code (sorted, unique).
	 *
	 * @param string|null $root The plugin's directory (this one when null).
	 * @return string[]
	 */
	public static function names( $root = null ): array {
		$root  = null === $root ? dirname( __DIR__, 2 ) : (string) $root;
		$names = array();
		foreach ( array( 'tests/integration', 'tests/Fixtures' ) as $dir ) {
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $file ) {
				if ( '.php' !== substr( (string) $file, -4 ) ) {
					continue;
				}
				$code = (string) file_get_contents( (string) $file );
				if ( preg_match_all( "/wp_upload_dir\\([^)]*\\)\\s*\\[\\s*'basedir'\\s*\\]\\s*\\.\\s*'\\/([A-Za-z0-9._-]+)/", $code, $m ) ) {
					foreach ( $m[1] as $name ) {
						$names[ $name ] = true;
					}
				}
			}
		}
		$names = array_keys( $names );
		sort( $names );
		return $names;
	}

	/**
	 * The test site's uploads directory: WordPress's when it is loaded, otherwise where WordPress puts it by default
	 * (the content directory's "uploads"; the test site does not move it).
	 *
	 * @return string
	 */
	public static function dir(): string {
		if ( function_exists( 'wp_upload_dir' ) ) {
			return rtrim( str_replace( '\\', '/', (string) wp_upload_dir( null, false )['basedir'] ), '/' );
		}
		$content = defined( 'WP_CONTENT_DIR' ) ? (string) WP_CONTENT_DIR : rtrim( (string) ABSPATH, '/\\' ) . '/wp-content';
		return rtrim( str_replace( '\\', '/', $content ), '/' ) . '/uploads';
	}

	/**
	 * The top-level entries of an uploads directory but WordPress's own: a year's directory ("2026"), a network's sites
	 * ("sites"); paths with forward slashes, sorted.
	 *
	 * @param string $dir The uploads directory.
	 * @return string[]
	 */
	public static function entries( string $dir ): array {
		$out  = array();
		$base = rtrim( str_replace( '\\', '/', $dir ), '/' );
		foreach ( is_dir( $dir ) ? (array) scandir( $dir ) : array() as $entry ) {
			$entry = (string) $entry;
			if ( '.' === $entry || '..' === $entry || 'sites' === $entry || 1 === preg_match( '/\A[0-9]{4}\z/', $entry ) ) {
				continue;
			}
			$out[] = $base . '/' . $entry;
		}
		sort( $out );
		return $out;
	}

	/**
	 * The entries whose name starts with a test's (what an earlier run left).
	 *
	 * @param string   $dir   The uploads directory.
	 * @param string[] $names The tests' names.
	 * @return string[]
	 */
	public static function left( string $dir, array $names ): array {
		return array_values(
			array_filter(
				self::entries( $dir ),
				static function ( string $path ) use ( $names ): bool {
					return self::is_tests( basename( $path ), $names );
				}
			)
		);
	}

	/**
	 * Why a run does not start ('' when it may): the entries an earlier run left, listed.
	 *
	 * @param string   $dir   The uploads directory.
	 * @param string[] $names The tests' names.
	 * @return string
	 */
	public static function refusal( string $dir, array $names ): string {
		$left = self::left( $dir, $names );
		if ( array() === $left ) {
			return '';
		}
		return "The test site's uploads directory holds what an earlier run left (names the tests make there); the run does not start. Remove them first:\n  " . implode( "\n  ", $left ) . "\n";
	}

	/**
	 * Whether a name starts with one of the tests'.
	 *
	 * @param string   $name  Name.
	 * @param string[] $names The tests' names.
	 * @return bool
	 */
	public static function is_tests( string $name, array $names ): bool {
		foreach ( $names as $prefix ) {
			if ( '' !== $prefix && 0 === strpos( $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Remove an entry a test left in the uploads directory, when its name is a test's (Deleter, registered for that
	 * deletion only); anything else is said and left alone.
	 *
	 * @param string $path The entry.
	 * @return void
	 */
	public static function remove( string $path ): void {
		$dir = self::dir();
		if ( dirname( $path ) !== $dir || ! self::is_tests( basename( $path ), self::names() ) ) {
			fwrite( STDERR, "\nNot an entry the tests make in the uploads directory, left alone: {$path}\n" );
			return;
		}
		$roots = Deleter::replace_roots( array() );
		Deleter::replace_roots( $roots );
		try {
			Deleter::allow( $path );
			Deleter::delete_tree( $dir, $path );
		} catch ( DeletionRefused $e ) {
			fwrite( STDERR, "\n" . $e->getMessage() . "\n" );
		} finally {
			Deleter::replace_roots( $roots );
		}
	}
}
