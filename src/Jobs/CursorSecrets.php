<?php
/**
 * No secret in what a job's row keeps.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Support\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * The check that an encoded cursor (or the options, or the questions) holds none of the installation's secrets, in
 * any of their escaped forms (Redactor::forms()): JobRepository::assert_cursor_has_no_secrets() encodes with
 * wp_json_encode() and asks here; a run made without WordPress encodes with PlainJson and asks here too.
 */
final class CursorSecrets {

	/**
	 * Secrets shorter than this are not looked for (they would match by chance).
	 */
	const MIN_LENGTH = 4;

	/**
	 * Refuse an encoded value that holds a secret.
	 *
	 * @param string|false      $json    The value, encoded (false when it could not be).
	 * @param array<mixed>|null $secrets The secrets (Redactor::installation_secrets() by default).
	 * @return void
	 * @throws \InvalidArgumentException When it could not be encoded, or holds a secret.
	 */
	public static function assert_none( $json, $secrets = null ): void {
		if ( ! is_string( $json ) ) {
			throw new \InvalidArgumentException( 'Cursor cannot be encoded.' );
		}
		$user = defined( 'DB_USER' ) && is_string( DB_USER ) ? DB_USER : '';
		foreach ( is_array( $secrets ) ? $secrets : Redactor::installation_secrets() as $secret ) {
			if ( ! is_string( $secret ) || strlen( $secret ) < self::MIN_LENGTH || ( '' !== $user && $secret === $user ) ) {
				continue;
			}
			foreach ( Redactor::forms( $secret ) as $form ) {
				if ( strlen( $form ) >= self::MIN_LENGTH && false !== strpos( $json, $form ) ) {
					throw new \InvalidArgumentException( 'Cursor must not contain credentials.' );
				}
			}
		}
	}
}
