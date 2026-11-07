<?php

namespace WPCheckpoint\Tests\Fixtures;

use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\DeletionRefused;

/**
 * What the integration tests leave in the test site's uploads directory. The names they make there are read from the
 * tests' code (names(): a call of wp_upload_dir() followed by the basedir key and a slash-led literal, in
 * tests/integration and tests/Fixtures, comments not read), so the list is the code's, not kept by hand; a call it
 * cannot read is registered (UNREAD) or fails it. Before a run (leftovers-precheck.php, from bin/test-integration.sh) an
 * entry of the uploads directory that starts with one of them stops the run: an earlier run left it, and a test that
 * makes it again meets it (a failed mkdir) or packs it with its own (an export of the site's uploads). During a run
 * Leftovers lists every top-level entry of the uploads directory but WordPress's own (a year's directory, a network's
 * "sites"), so an entry a test or a class leaves fails it like any other leftover; removal() removes one only when its
 * name starts with a test's name (anything else is said and left alone).
 */
final class UploadsLeftovers {

	/**
	 * Uses of wp_upload_dir() in the tests' code that make no name in the uploads directory (or not one names() can
	 * read), by file, as many as the file has: what they do instead. names() fails on any use it cannot read that is
	 * not counted here, and on a count that no longer matches: a new way of making a name there is never missed.
	 */
	const UNREAD = array(
		'tests/Fixtures/UploadsLeftovers.php'         => array( 1, 'dir(): the uploads directory itself, which this class reads' ),
		'tests/integration/DeleterProtectionTest.php' => array( 1, 'the Deleter\'s refusal of the uploads directory itself' ),
		'tests/integration/StagingResidueTest.php'    => array( 1, 'a site\'s own uploads directory, compared with the main site\'s' ),
		'tests/integration/StorageTest.php'           => array( 3, 'the storage location\'s refusal of the uploads directories, read, not written' ),
	);

	/**
	 * The names the tests make in the uploads directory, read from their code (sorted, unique): every
	 * wp_upload_dir( ... )['basedir'] . '/NAME' in tests/integration and tests/Fixtures, in code (comments are not
	 * read).
	 *
	 * @param string|null                       $root       The plugin's directory (this one when null).
	 * @param array<string, array{0: int, 1: string}> $registered Uses that make no name (UNREAD when null).
	 * @return string[]
	 * @throws \LogicException When a use cannot be read and is not registered, or a registration no longer matches.
	 */
	public static function names( $root = null, $registered = null ): array {
		$scan       = self::scan( null === $root ? dirname( __DIR__, 2 ) : (string) $root );
		$registered = null === $registered ? self::UNREAD : $registered;
		$wrong      = array();
		foreach ( $scan['unread'] as $file => $lines ) {
			if ( count( $lines ) !== ( $registered[ $file ][0] ?? 0 ) ) {
				$wrong[] = sprintf( '%s: %d use(s) of wp_upload_dir() that make no name it can read (lines %s), %d registered', $file, count( $lines ), implode( ', ', $lines ), $registered[ $file ][0] ?? 0 );
			}
		}
		foreach ( $registered as $file => $entry ) {
			if ( ! isset( $scan['unread'][ $file ] ) ) {
				$wrong[] = sprintf( '%s: %d registered, none found (registration out of date)', $file, $entry[0] );
			}
		}
		if ( array() !== $wrong ) {
			throw new \LogicException( "The names the tests make in the uploads directory cannot all be read:\n  " . implode( "\n  ", $wrong ) );
		}
		return $scan['names'];
	}

	/**
	 * The names read, and the uses that make none it can read (file => lines), from the PHP files of a tree's
	 * tests/integration and tests/Fixtures; by tokens, so that comments and strings are not code.
	 *
	 * @param string $root The plugin's directory.
	 * @return array{names: string[], unread: array<string, int[]>}
	 */
	public static function scan( string $root ): array {
		$names  = array();
		$unread = array();
		foreach ( array( 'tests/integration', 'tests/Fixtures' ) as $dir ) {
			if ( ! is_dir( $root . '/' . $dir ) ) {
				continue;
			}
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
			$list  = array();
			foreach ( $files as $file ) {
				if ( '.php' === substr( (string) $file, -4 ) ) {
					$list[] = (string) $file;
				}
			}
			sort( $list );
			foreach ( $list as $file ) {
				$relative = $dir . substr( str_replace( '\\', '/', $file ), strlen( str_replace( '\\', '/', $root . '/' . $dir ) ) );
				$code     = array_values(
					array_filter(
						token_get_all( (string) file_get_contents( $file ) ),
						static function ( $t ): bool {
							return ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
						}
					)
				);
				foreach ( $code as $i => $t ) {
					if ( ! is_array( $t ) || T_STRING !== $t[0] || 'wp_upload_dir' !== strtolower( $t[1] ) || '(' !== ( $code[ $i + 1 ] ?? null ) ) {
						continue;
					}
					$prev = $code[ $i - 1 ] ?? null;
					if ( is_array( $prev ) && in_array( $prev[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
						continue; // A method of that name, or its declaration.
					}
					$name = self::name_after( $code, $i + 1 );
					if ( null === $name ) {
						$unread[ $relative ][] = (int) $t[2];
					} else {
						$names[ $name ] = true;
					}
				}
			}
		}
		$names = array_keys( $names );
		sort( $names );
		ksort( $unread );
		return array(
			'names'  => $names,
			'unread' => $unread,
		);
	}

	/**
	 * After wp_upload_dir( ... ) whose "(" is at $open: the NAME of ['basedir'] . '/NAME' (a literal), or null.
	 *
	 * @param array<int, mixed> $code  Tokens, without whitespace and comments.
	 * @param int               $open  The "(".
	 * @return string|null
	 */
	private static function name_after( array $code, int $open ): ?string {
		$level = 0;
		for ( $k = $open; isset( $code[ $k ] ); $k++ ) {
			if ( '(' === $code[ $k ] ) {
				++$level;
			} elseif ( ')' === $code[ $k ] && 0 === --$level ) {
				break;
			}
		}
		$next = array_slice( $code, $k + 1, 5 );
		if ( 5 !== count( $next ) || '[' !== $next[0] || ! is_array( $next[1] ) || T_CONSTANT_ENCAPSED_STRING !== $next[1][0] || "'basedir'" !== $next[1][1] || ']' !== $next[2] || '.' !== $next[3] || ! is_array( $next[4] ) || T_CONSTANT_ENCAPSED_STRING !== $next[4][0] ) {
			return null;
		}
		if ( 1 !== preg_match( "/\\A'\\/([A-Za-z0-9._-]+)'\\z/", $next[4][1], $m ) ) {
			return null;
		}
		return $m[1];
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
