<?php
/**
 * The relative-path rule shared by the manifest and the volume reader.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Archive;

/**
 * A path inside an archive must not be able to change the "inside the
 * target directory" verdict: forward slashes only, no empty, "." or ".."
 * segments, no leading slash, no backslash, no scheme-shaped or
 * drive-letter first segment, no control characters. Everything else
 * (case collisions, trailing dots, over-long segments) is the unpacker's
 * job, with a realpath containment check as the last line.
 */
final class EntryPath {

	const MAX_BYTES = 4096;

	/**
	 * Why a path is not acceptable, or null when it is.
	 *
	 * @param string $value Path.
	 * @return string|null
	 */
	public static function problem( string $value ) {
		if ( '' === $value || strlen( $value ) > self::MAX_BYTES ) {
			return 'Empty or too long.';
		}
		if ( false !== strpos( $value, '\\' ) || '/' === $value[0] ) {
			return 'Not a relative path with forward slashes.';
		}
		if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			return 'Path contains a control character.';
		}
		$segments = explode( '/', $value );
		// A first segment shaped like a scheme or a drive letter ("C:", "data:", "php:") is not a relative path.
		if ( 1 === preg_match( '/\A[A-Za-z][A-Za-z0-9+.-]*:/', $segments[0] ) ) {
			return 'Not a relative path with forward slashes.';
		}
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return 'Path contains an empty, "." or ".." segment.';
			}
		}
		return null;
	}

	/**
	 * Whether a path is acceptable.
	 *
	 * @param string $value Path.
	 * @return bool
	 */
	public static function is_valid( string $value ): bool {
		return null === self::problem( $value );
	}
}
