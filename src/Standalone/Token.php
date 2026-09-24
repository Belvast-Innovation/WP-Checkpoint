<?php
/**
 * One-time tokens for requests made without WordPress.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Standalone;

defined( 'ABSPATH' ) || defined( 'WPCHECKPOINT_STANDALONE' ) || exit;

/**
 * 256 random bits, written as 64 hexadecimal digits. The browser keeps the
 * token, the server keeps only its SHA-256; a token is compared in constant
 * time. well_formed() is the check a request passes before anything else
 * runs (see ConfigLoader: no site code for a request that has not passed).
 */
final class Token {

	/**
	 * A new token.
	 *
	 * @return string
	 */
	public static function generate(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Whether a value has a token's form (checked before any work is done for a request).
	 *
	 * @param mixed $token Value.
	 * @return bool
	 */
	public static function well_formed( $token ): bool {
		return is_string( $token ) && 64 === strlen( $token ) && ctype_xdigit( $token ) && strtolower( $token ) === $token;
	}

	/**
	 * What the server keeps.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Whether a token matches a kept hash, in constant time.
	 *
	 * @param string $token Token given.
	 * @param string $hash  Kept hash.
	 * @return bool
	 */
	public static function matches( string $token, string $hash ): bool {
		return self::well_formed( $token ) && 64 === strlen( $hash ) && hash_equals( $hash, self::hash( $token ) );
	}
}
