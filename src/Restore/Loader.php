<?php
/**
 * The must-use plugin a restore's swap puts next to the site's own while it holds the site.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Support\AtomicFile;
use WPCheckpoint\Support\AtomicWriteFailed;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\DeletionRefused;
use WPCheckpoint\Support\HostFunctions;
use WPCheckpoint\Support\Paths;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- a small file in the must-use plugins directory, read byte for byte.
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would name the path; what could not be done is returned.

/**
 * NAME in the must-use plugins directory, the same code for every restore (CODE; KNOWN holds every code a released
 * version wrote). WordPress loads must-use plugins by name, sorted byte for byte: "!" comes before the digits and the
 * letters, so the file is loaded before the site's own must-use plugins but those whose names start with "!" and sort
 * before it. In an interactive shell the name must be quoted ("!" starts a history expansion).
 *
 * What CODE does, in a request (never under WP-CLI or the command line): nothing unless the runtime
 * (Maintenance::RUNTIME) is next to the WordPress directory's maintenance file's place and the data file
 * (Maintenance::DATA) is a held one that names a runtime; nothing for a request whose X-WPCheckpoint-Pass header the
 * data file lets through (the restore's own checks; the rule of Maintenance::CODE). Otherwise the site is held: the
 * request ends here with the maintenance page's answer (503, Retry-After), whether or not .maintenance is still there
 * (WordPress did not stop for it: it was deleted, or the maintenance file's code let this request through to flush the
 * object cache, WPCHECKPOINT_FLUSH_PASS). Before it ends, the runtime is run as Maintenance::CODE runs it (the visitor
 * file's time and lock; a request let through to flush was counted by the maintenance file's code already), with
 * WordPress's object cache flush for it to finish a put-back that only waits for the flush. It reads no table and no
 * option: whether a job holds the site is the files' to say.
 *
 * Put up by the swap after the runtime, the visitor file and the runtime log and before the maintenance file (put()),
 * never into a directory that is not there (the preflight's LoaderProbe made it; one gone since is not made again: the
 * plan may be to rename the restored one into its place). Removed by content only (remove()): a file under NAME whose
 * contents are not in KNOWN is someone else's, said and kept.
 */
final class Loader {

	const NAME = '!wpcheckpoint-runtime.php';

	/**
	 * Most bytes read of a file under NAME: ours are shorter, so a longer one is someone else's.
	 */
	const MAX_READ = 8192;

	/**
	 * The loader's code. Pinned (LoaderTest), with its SHA-256 named in Maintenance::CODE (which lets a request through
	 * to flush only when the file there is this code). Written line by line with "\n" (see Maintenance::CODE_1).
	 */
	const CODE =
		'<?php' . "\n"
		. '// WP Checkpoint: while a restore holds this site, this file answers as its maintenance file does should that file be gone, and lets a held request finish putting a half-made restore back. It does nothing otherwise, and the restore removes it when it ends.' . "\n"
		. 'if ( defined( \'ABSPATH\' ) && \'cli\' !== PHP_SAPI && ! ( defined( \'WP_CLI\' ) && WP_CLI ) ) {' . "\n"
		. '	( static function () {' . "\n"
		. '		$dir = rtrim( ABSPATH, \'/\\\\\' );' . "\n"
		. '		if ( ! @file_exists( $dir . \'/.wpcheckpoint-runtime.php\' ) ) {' . "\n"
		. '			return;' . "\n"
		. '		}' . "\n"
		. '		$data = @file_get_contents( $dir . \'/.wpcheckpoint-maintenance.php\' );' . "\n"
		. '		if ( ! is_string( $data ) || 1 !== preg_match( \'/\\A<\\?php exit; \\?>\\n(WP Checkpoint restore [0-9a-f]{32})\\n(?:' . Maintenance::HELD_VALUES[0] . '|' . Maintenance::HELD_VALUES[1] . ')\\n([0-9a-f]{64}|-)\\n([0-9a-f]{64})\\n\\z/\', $data, $found ) ) {' . "\n"
		. '			return;' . "\n"
		. '		}' . "\n"
		. '		$pass = isset( $_SERVER[\'HTTP_X_WPCHECKPOINT_PASS\'] ) && is_string( $_SERVER[\'HTTP_X_WPCHECKPOINT_PASS\'] ) ? $_SERVER[\'HTTP_X_WPCHECKPOINT_PASS\'] : \'\';' . "\n"
		. '		if ( \'-\' !== $found[2] && 1 === preg_match( \'/\\A[0-9a-f]{64}\\z/\', $pass ) && hash_equals( $found[2], hash( \'sha256\', $pass ) ) ) {' . "\n"
		. '			return;' . "\n"
		. '		}' . "\n"
		. '		$due = defined( \'WPCHECKPOINT_FLUSH_PASS\' );' . "\n"
		. '		if ( ! $due ) {' . "\n"
		. '			$visitor = $dir . \'/.wpcheckpoint-visitor.php\';' . "\n"
		. '			$lock    = @fopen( $visitor, \'r+\' );' . "\n"
		. '			$busy    = 0;' . "\n"
		. '			if ( false !== $lock && ( ! function_exists( \'posix_geteuid\' ) || @fileowner( $visitor ) === posix_geteuid() ) && ( @flock( $lock, LOCK_EX | LOCK_NB, $busy ) || 1 !== $busy ) ) {' . "\n"
		. '				clearstatcache( true, $visitor );' . "\n"
		. '				$next = @filemtime( $visitor );' . "\n"
		. '				$due  = false !== $next && $next <= time() && @touch( $visitor, time() + 5 );' . "\n"
		. '				@flock( $lock, LOCK_UN );' . "\n"
		. '			}' . "\n"
		. '			if ( false !== $lock ) {' . "\n"
		. '				@fclose( $lock );' . "\n"
		. '			}' . "\n"
		. '		}' . "\n"
		. '		if ( $due ) {' . "\n"
		. '			if ( ! defined( \'WPCHECKPOINT_WEB_ENTRY\' ) ) {' . "\n"
		. '				define( \'WPCHECKPOINT_WEB_ENTRY\', true );' . "\n"
		. '			}' . "\n"
		. '			try {' . "\n"
		. '				$said = class_exists( \'WPCheckpoint\\Jobs\\WebEntry\', false ) ? null : @include $dir . \'/.wpcheckpoint-runtime.php\';' . "\n"
		. '				if ( is_string( $said ) && class_exists( \'WPCheckpoint\\Jobs\\WebEntry\', false ) ) {' . "\n"
		. '					\\WPCheckpoint\\Jobs\\WebEntry::run( $dir, $found[1], $found[3], $said, function_exists( \'wp_cache_flush\' ) ? \'wp_cache_flush\' : null );' . "\n"
		. '				}' . "\n"
		. '			} catch ( \\Throwable $error ) {' . "\n"
		. '				unset( $error );' . "\n"
		. '			}' . "\n"
		. '		}' . "\n"
		. '		if ( ! headers_sent() ) {' . "\n"
		. '			http_response_code( 503 );' . "\n"
		. '			header( \'Content-Type: text/html; charset=utf-8\' );' . "\n"
		. '			header( \'Retry-After: 600\' );' . "\n"
		. '			header( \'Cache-Control: no-cache, no-store, must-revalidate, max-age=0\' );' . "\n"
		. '		}' . "\n"
		. '		echo \'<!DOCTYPE html><html><head><meta charset="utf-8"><title>Maintenance</title></head><body><p>Briefly unavailable for scheduled maintenance. Check back in a minute.</p></body></html>\';' . "\n"
		. '		exit;' . "\n"
		. '	} )();' . "\n"
		. '}' . "\n";

	/**
	 * Every code a released version wrote: a file under NAME is this plugin's only when its contents are one of these.
	 */
	const KNOWN = array( self::CODE );

	/**
	 * Why no loader was put up.
	 */
	const MISSING    = 'missing';
	const UNWRITABLE = 'unwritable';
	const TAKEN      = 'taken';
	const UNKNOWN    = 'unknown';

	/**
	 * The must-use plugins directory as WordPress has it: WPMU_PLUGIN_DIR once it is defined, its default
	 * (WP_CONTENT_DIR . '/mu-plugins', WordPress's own) before, as Maintenance::CODE computes it.
	 *
	 * @param string $abspath The WordPress directory (where neither constant is defined: without WordPress).
	 * @return string
	 */
	public static function dir( string $abspath ): string {
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			return (string) constant( 'WPMU_PLUGIN_DIR' );
		}
		return ( defined( 'WP_CONTENT_DIR' ) ? (string) constant( 'WP_CONTENT_DIR' ) : rtrim( $abspath, '/\\' ) . '/wp-content' ) . '/mu-plugins';
	}

	/**
	 * Whether a loader is still a restore's to have: a maintenance code of this plugin's is in the WordPress directory,
	 * or a data file there names a restore (held or not: with .maintenance deleted, the loader is what holds a half
	 * swapped site, and what lets a visitor's request put it back). What cannot be told of either file counts as
	 * needed (a data file that cannot be read now, or is a link, may be a held one). The rule of every removal outside
	 * a swap (the reaper, a deactivation, an uninstall): none of them takes the loader while this holds.
	 *
	 * @param string $dir The WordPress directory.
	 * @return bool
	 */
	public static function needed( string $dir ): bool {
		return false !== Maintenance::code_in( $dir ) || false !== Maintenance::names_restore( $dir );
	}

	/**
	 * The SHA-256 of each known code.
	 *
	 * @return string[]
	 */
	public static function digests(): array {
		return array_map(
			static function ( string $code ): string {
				return hash( 'sha256', $code );
			},
			self::KNOWN
		);
	}

	/**
	 * What is under NAME in a directory: array( 'none' ) when positively nothing, array( 'unknown' ) when it cannot be
	 * told, array( 'other' ) for anything that is not a regular file holding a known code (a link, a directory, other
	 * contents, a longer file), array( 'ours', contents ) for a known code.
	 *
	 * @param string $dir Directory.
	 * @return array{0: string, 1?: string}
	 */
	public static function look( string $dir ): array {
		$path = self::path( $dir );
		clearstatcache( true, $path );
		$stat = @lstat( $path );
		if ( false === $stat ) {
			return array( Paths::positively_gone( $path ) ? 'none' : 'unknown' );
		}
		if ( 0100000 !== ( $stat['mode'] & 0170000 ) ) {
			return array( 'other' );
		}
		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			return array( 'unknown' );
		}
		$contents = @fread( $handle, self::MAX_READ + 1 );
		@fclose( $handle );
		if ( false === $contents ) {
			return array( 'unknown' );
		}
		return in_array( $contents, self::KNOWN, true ) ? array( 'ours', $contents ) : array( 'other' );
	}

	/**
	 * The path of NAME in a directory.
	 *
	 * @param string $dir Directory.
	 * @return string
	 */
	public static function path( string $dir ): string {
		return rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . self::NAME;
	}

	/**
	 * Put the loader into the must-use plugins directory (whole or not at all: AtomicFile), unless it is there. Never
	 * makes the directory, and never writes over a file under NAME that is not a known code.
	 *
	 * @param string        $dir     The must-use plugins directory ('' when it is not known).
	 * @param callable|null $confirm Called right before the file goes in place (the lease; what it throws is thrown on).
	 * @return string '' when the loader is there now; else why not: MISSING (no such directory), TAKEN (another file
	 *                under its name), UNWRITABLE, UNKNOWN (what is there cannot be told).
	 * @throws \RuntimeException What $confirm throws.
	 */
	public static function put( string $dir, $confirm = null ): string {
		clearstatcache( true, $dir );
		if ( '' === $dir || ! @is_dir( $dir ) ) {
			return '' === $dir || Paths::positively_gone( $dir ) ? self::MISSING : self::UNKNOWN;
		}
		$look = self::look( $dir );
		if ( 'other' === $look[0] ) {
			return self::TAKEN;
		}
		if ( 'unknown' === $look[0] ) {
			return self::UNKNOWN;
		}
		if ( 'ours' === $look[0] && self::CODE === $look[1] ) {
			return '';
		}
		try {
			AtomicFile::write( $dir, self::NAME, self::CODE, null === $confirm ? array() : array( 'confirm' => $confirm ) );
		} catch ( AtomicWriteFailed $e ) {
			return self::UNWRITABLE;
		}
		HostFunctions::opcache_invalidate( self::path( $dir ) );
		return '';
	}

	/**
	 * Remove the loader from a directory: only a regular file under NAME whose contents are a known code. Anything else
	 * under the name is said ($note) and kept.
	 *
	 * @param string        $dir     Directory (the must-use plugins directory, or where the swap moved it).
	 * @param callable|null $confirm Called right before the file is deleted (the lease; what it throws is thrown on).
	 * @param callable|null $note    function( string $line ): void, told what was kept or could not be removed (no
	 *                               path in it: the caller knows the directory).
	 * @return bool Whether no loader of this plugin's is there now (removed, none there, or someone else's file).
	 * @throws \RuntimeException What $confirm throws.
	 */
	public static function remove( string $dir, $confirm = null, $note = null ): bool {
		if ( '' === $dir ) {
			return true;
		}
		$look = self::look( $dir );
		if ( 'none' === $look[0] ) {
			return true;
		}
		if ( 'other' === $look[0] ) {
			if ( is_callable( $note ) ) {
				call_user_func( $note, 'A file under the name of the restore\'s must-use plugin is not one this plugin wrote; it was kept.' );
			}
			return true;
		}
		if ( 'unknown' === $look[0] ) {
			if ( is_callable( $note ) ) {
				call_user_func( $note, 'Whether the restore\'s must-use plugin is still in place cannot be told; nothing was removed.' );
			}
			return false;
		}
		try {
			$gone = Deleter::delete_loader_file( $dir, self::NAME, self::digests(), $confirm );
		} catch ( DeletionRefused $e ) {
			$gone = false;
		}
		HostFunctions::opcache_invalidate( self::path( $dir ) );
		if ( ! $gone && is_callable( $note ) ) {
			call_user_func( $note, 'The restore\'s must-use plugin could not be removed; it does nothing once the restore\'s files next to the maintenance file are gone, and is removed later.' );
		}
		return $gone;
	}
}
