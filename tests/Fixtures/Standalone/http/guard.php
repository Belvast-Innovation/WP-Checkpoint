<?php
/**
 * The probes answer only inside a test run: a run writes a one-time key
 * next to them (probe.key, never committed, and tests/ is never packaged),
 * sends it with each request, and deletes it afterwards. Anything else gets
 * a bare 404 and nothing runs.
 *
 * @package WPCheckpoint
 */

$wpcheckpoint_key_file = __DIR__ . '/probe.key';
$wpcheckpoint_given    = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? $_GET['key'] : ''; // phpcs:ignore WordPress.Security
$wpcheckpoint_expected = is_file( $wpcheckpoint_key_file ) ? (string) file_get_contents( $wpcheckpoint_key_file ) : '';
if ( strlen( $wpcheckpoint_expected ) < 32 || ! hash_equals( $wpcheckpoint_expected, $wpcheckpoint_given ) ) {
	http_response_code( 404 );
	exit;
}
