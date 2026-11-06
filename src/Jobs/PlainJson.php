<?php
/**
 * The encoder of a run made without WordPress.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * What wp_json_encode() writes for a value it can encode, without WordPress: json_encode() with the flags and depth
 * wp_json_encode() passes (none, 512), and JSON_THROW_ON_ERROR. Where wp_json_encode() cannot encode (invalid UTF-8)
 * it rewrites the bytes and returns something (_wp_json_sanity_check()); this throws instead: a run without WordPress
 * writes nothing it could not read back as it was. For every value both can encode, the bytes are the same
 * (EncoderParityTest): the jobs' lookups by the first bytes of a cursor (JobRepository: cursor_json LIKE '{"phase":…')
 * find what either wrote.
 */
final class PlainJson {

	/**
	 * Encode.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \JsonException When it cannot be encoded.
	 */
	public static function encode( $value ): string {
		return (string) json_encode( $value, JSON_THROW_ON_ERROR, 512 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- outside WordPress: wp_json_encode()'s own call, made to throw.
	}
}
