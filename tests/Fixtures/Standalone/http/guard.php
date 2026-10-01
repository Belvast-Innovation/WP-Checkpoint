<?php
/**
 * The probes answer only inside a test run: a run writes a one-time key into a directory of its own next to
 * wp-content's other directories (wp-content/wpcheckpoint-it-probe.<run ID>/probe.key: never in the plugin, never
 * committed, and tests/ is never packaged), sends the run ID and the key in request headers, and deletes the
 * directory afterwards; a key file older than ten minutes is refused. The run ID is checked against its fixed form
 * (16 lowercase hexadecimal digits) before anything is built from it; the directory must be a direct child of the
 * wp-content this file lies in, and neither it nor the key file may be a link. Anything else gets a bare 404 and
 * nothing runs.
 *
 * @package WPCheckpoint
 */

/**
 * Refuse the request: a bare 404.
 *
 * @return void
 */
function wpcheckpoint_probe_refuse() {
	http_response_code( 404 );
	exit;
}

// From headers, not the query string, so they stay out of access logs.
$wpcheckpoint_run   = isset( $_SERVER['HTTP_X_WPCHECKPOINT_PROBE_RUN'] ) && is_string( $_SERVER['HTTP_X_WPCHECKPOINT_PROBE_RUN'] ) ? $_SERVER['HTTP_X_WPCHECKPOINT_PROBE_RUN'] : ''; // phpcs:ignore WordPress.Security
$wpcheckpoint_given = isset( $_SERVER['HTTP_X_WPCHECKPOINT_PROBE_KEY'] ) && is_string( $_SERVER['HTTP_X_WPCHECKPOINT_PROBE_KEY'] ) ? $_SERVER['HTTP_X_WPCHECKPOINT_PROBE_KEY'] : ''; // phpcs:ignore WordPress.Security
if ( 1 !== preg_match( '/^[0-9a-f]{16}$/D', $wpcheckpoint_run ) ) {
	wpcheckpoint_probe_refuse(); // Never built into a path unless it has the form.
}

// wp-content/plugins/wp-checkpoint/tests/Fixtures/Standalone/http: six levels up.
$wpcheckpoint_content = realpath( dirname( __DIR__, 6 ) );
if ( false === $wpcheckpoint_content ) {
	wpcheckpoint_probe_refuse();
}
$wpcheckpoint_dir      = $wpcheckpoint_content . DIRECTORY_SEPARATOR . 'wpcheckpoint-it-probe.' . $wpcheckpoint_run;
$wpcheckpoint_key_file = $wpcheckpoint_dir . DIRECTORY_SEPARATOR . 'probe.key';
clearstatcache( true );
$wpcheckpoint_dir_stat = @lstat( $wpcheckpoint_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing directory is a refusal, not a warning.
$wpcheckpoint_key_stat = @lstat( $wpcheckpoint_key_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
// lstat(): the directory itself and the key file itself, not where a link would lead.
if ( false === $wpcheckpoint_dir_stat || 0040000 !== ( $wpcheckpoint_dir_stat['mode'] & 0170000 ) || false === $wpcheckpoint_key_stat || 0100000 !== ( $wpcheckpoint_key_stat['mode'] & 0170000 ) ) {
	wpcheckpoint_probe_refuse();
}
// A direct child of wp-content, also as resolved (no link on the way).
if ( realpath( $wpcheckpoint_dir ) !== $wpcheckpoint_dir ) {
	wpcheckpoint_probe_refuse();
}
// A key left behind by a test run that was killed stops working after ten minutes.
$wpcheckpoint_expected = time() - (int) $wpcheckpoint_key_stat['mtime'] < 600 ? (string) file_get_contents( $wpcheckpoint_key_file ) : '';
if ( strlen( $wpcheckpoint_expected ) < 32 || ! hash_equals( $wpcheckpoint_expected, $wpcheckpoint_given ) ) {
	wpcheckpoint_probe_refuse();
}
