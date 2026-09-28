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
		$lines = array_map( 'trim', explode( "\n", trim( $contents ) ) );
		return count( $lines ) >= 2 && hash_equals( $install_id, $lines[0] ) && hash_equals( self::hash_path( $abspath ), $lines[1] );
	}

	/**
	 * Hash of a normalised installation path.
	 *
	 * @param string $abspath ABSPATH.
	 * @return string
	 */
	public static function hash_path( string $abspath ): string {
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
		$expected = self::build( $install_id, $abspath );
		return strlen( $contents ) < strlen( $expected ) && 0 === strncmp( $expected, $contents, strlen( $contents ) );
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
