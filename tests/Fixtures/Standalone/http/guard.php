<?php
/**
 * The probes answer only inside a test run: a run writes a one-time key
 * next to them (probe.key, never committed, and tests/ is never packaged),
 * sends it in a request header, and deletes it afterwards; a key file older
 * than ten minutes is refused. Anything else gets a bare 404 and nothing
 * runs.
 *
 * @package WPCheckpoint
 */

$wpcheckpoint_key_file = __DIR__ . '/probe.key';
// From a header, not the query string, so it stays out of access logs.
$wpcheckpoint_given    = isset( $_SERVER['HTTP_X_WPCHECKPOINT_PROBE_KEY'] ) && is_string( $_SERVER['HTTP_X_WPCHECKPOINT_PROBE_KEY'] ) ? $_SERVER['HTTP_X_WPCHECKPOINT_PROBE_KEY'] : ''; // phpcs:ignore WordPress.Security
clearstatcache( true, $wpcheckpoint_key_file );
// A key left behind by a test run that was killed stops working after ten minutes.
$wpcheckpoint_fresh    = is_file( $wpcheckpoint_key_file ) && time() - (int) filemtime( $wpcheckpoint_key_file ) < 600;
$wpcheckpoint_expected = $wpcheckpoint_fresh ? (string) file_get_contents( $wpcheckpoint_key_file ) : '';
if ( strlen( $wpcheckpoint_expected ) < 32 || ! hash_equals( $wpcheckpoint_expected, $wpcheckpoint_given ) ) {
	http_response_code( 404 );
	exit;
}
