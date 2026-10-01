<?php
/**
 * Ownership marker for the storage directory.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

/**
 * Ties a storage directory to one installation.
 *
 * A cloned or migrated site carries the same options and would otherwise
 * write into (or delete) the original site's directory. The marker file
 * holds the random install ID and a hash of ABSPATH; both must match.
 *
 * The hash is of the directory ABSPATH resolves to (hash_path()); markers
 * written before hashed ABSPATH as spelled, and those match as written, for
 * good: under the spelling they were written with, or wherever that spelling
 * is the resolved path (hashes()). They are never rewritten to the new form.
 */
final class OwnerMarker {

	/**
	 * Marker file name inside the storage base directory.
	 */
	const FILENAME = '.wpcheckpoint-owner';

	/**
	 * Marker contents for an installation.
	 *
	 * @param string $install_id Random ID stored in the plugin options.
	 * @param string $abspath    ABSPATH of the installation.
	 * @return string
	 */
	public static function build( string $install_id, string $abspath ): string {
		return $install_id . "\n" . self::hash_path( $abspath ) . "\n";
	}

	/**
	 * Whether marker contents belong to this installation.
	 *
	 * @param string $contents   Raw marker file contents.
	 * @param string $install_id Expected install ID.
	 * @param string $abspath    Expected ABSPATH.
	 * @return bool
	 */
	public static function matches( string $contents, string $install_id, string $abspath ): bool {
		if ( '' === $install_id ) {
			return false;
		}
		$lines = self::lines( $contents );
		return null !== $lines && hash_equals( $install_id, $lines[0] ) && self::is_hash_of( $lines[1], $abspath );
	}

	/**
	 * A marker's install ID and path hash, or null when it does not hold both.
	 *
	 * @param string $contents Raw marker file contents.
	 * @return array{0: string, 1: string}|null
	 */
	public static function lines( string $contents ) {
		$lines = array_map( 'trim', explode( "\n", trim( $contents ) ) );
		return count( $lines ) >= 2 ? array( $lines[0], $lines[1] ) : null;
	}

	/**
	 * Whether a marker's path hash is one of an ABSPATH's (hashes()).
	 *
	 * @param string $hash    Hash from a marker.
	 * @param string $abspath ABSPATH.
	 * @return bool
	 */
	public static function is_hash_of( string $hash, string $abspath ): bool {
		foreach ( self::hashes( $abspath ) as $candidate ) {
			if ( hash_equals( $candidate, $hash ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Hash a marker is written with: of the directory ABSPATH resolves to (WP-CLI's --path through a link and the web
	 * server's resolved __DIR__ are one installation), of its spelling when it cannot be resolved.
	 *
	 * @param string $abspath ABSPATH.
	 * @return string
	 */
	public static function hash_path( string $abspath ): string {
		$real = self::real( $abspath );
		return self::hash_spelling( '' !== $real ? $real : $abspath );
	}

	/**
	 * The hashes a marker of an ABSPATH may carry: of its spelling (what markers written before hashed) and of the
	 * directory it resolves to, when it can be resolved.
	 *
	 * @param string $abspath ABSPATH.
	 * @return string[]
	 */
	public static function hashes( string $abspath ): array {
		return array_values( array_unique( array( self::hash_spelling( $abspath ), self::hash_path( $abspath ) ) ) );
	}

	/**
	 * The directory an ABSPATH resolves to, '' when it cannot be resolved (it does not exist here, a directory on the
	 * way cannot be searched, open_basedir).
	 *
	 * @param string $abspath ABSPATH.
	 * @return string
	 */
	public static function real( string $abspath ): string {
		$real = '' === $abspath ? false : Paths::real( rtrim( $abspath, '/\\' ) );
		return false === $real ? '' : $real;
	}

	/**
	 * Hash of an ABSPATH as spelled, normalised (separators, trailing slash; case on Windows).
	 *
	 * @param string $abspath ABSPATH.
	 * @return string
	 */
	public static function hash_spelling( string $abspath ): string {
		$normalized = rtrim( Paths::normalize( $abspath ), '/' );
		if ( Paths::is_windows() ) {
			$normalized = strtolower( $normalized );
		}
		return hash( 'sha256', $normalized );
	}

	/**
	 * Whether marker contents are the start of this installation's marker and not all of it (empty included): what a
	 * request that died while writing it leaves behind.
	 *
	 * @param string $contents   Raw marker file contents.
	 * @param string $install_id Install ID.
	 * @param string $abspath    ABSPATH.
	 * @return bool
	 */
	public static function is_unfinished( string $contents, string $install_id, string $abspath ): bool {
		if ( '' === $install_id ) {
			return false;
		}
		// Of either form: a request of the version before may have died writing one.
		foreach ( self::hashes( $abspath ) as $hash ) {
			$expected = $install_id . "\n" . $hash . "\n";
			if ( strlen( $contents ) < strlen( $expected ) && 0 === strncmp( $expected, $contents, strlen( $contents ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Write a marker only if none is there yet (exclusive creation: of two requests preparing the same directory,
	 * one writes it and the other finds it). A marker this call created but could not write in full (a full disk) is
	 * left as it is: it holds the start of these contents, which is_unfinished() recognises next time. Removing it
	 * could remove another request's finished marker that replaced it meanwhile.
	 *
	 * @param string $path     Marker path.
	 * @param string $contents Marker contents (build()).
	 * @return bool Whether this call created it with all of its contents.
	 */
	public static function create( string $path, string $contents ): bool {
		$handle = @fopen( $path, 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- an existing marker is an answer, not an error.
		if ( false === $handle ) {
			return false;
		}
		$written = fwrite( $handle, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- plugin-owned file.
		$closed  = fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- plugin-owned file.
		return strlen( $contents ) === $written && $closed;
	}

	/**
	 * Generate a random install ID.
	 *
	 * @return string
	 */
	public static function generate_id(): string {
		return bin2hex( random_bytes( 16 ) );
	}
}
