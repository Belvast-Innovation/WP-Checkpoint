<?php
/**
 * Before an integration run (bin/test-integration.sh, before PHPUnit): an entry of the test site's uploads directory
 * whose name starts with one the tests make there (UploadsLeftovers::names(), read from the tests) is what an earlier
 * run left, and the run does not start: a test that makes it again meets it, or packs it with its own (an export of
 * the site's uploads), and fails for a reason that is not its own. The entries are listed; nothing is removed.
 *
 * Usage: php uploads-precheck.php [<uploads directory>]. Without one, the test site's (the tests configuration's
 * ABSPATH and WP_CONTENT_DIR; WordPress is not loaded); without the tests configuration nothing is checked.
 *
 * Exit status: 77 when such an entry is there, else 0.
 *
 * @package WPCheckpoint
 */

// phpcs:disable -- a test fixture run as a script.

use WPCheckpoint\Tests\Fixtures\UploadsLeftovers;

$wpcheckpoint_config = '';
if ( ! isset( $argv[1] ) ) {
	$wpcheckpoint_config = (string) getenv( 'WP_PHPUNIT__TESTS_CONFIG' );
	if ( '' === $wpcheckpoint_config ) {
		$wpcheckpoint_tests  = (string) ( getenv( 'WP_TESTS_DIR' ) ?: getenv( 'WP_PHPUNIT__DIR' ) );
		$wpcheckpoint_config = '' === $wpcheckpoint_tests ? '' : $wpcheckpoint_tests . '/wp-tests-config.php';
	}
	if ( '' === $wpcheckpoint_config || ! is_readable( $wpcheckpoint_config ) ) {
		exit( 0 );
	}
}
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
if ( '' !== $wpcheckpoint_config ) {
	require $wpcheckpoint_config; // Its constants only (ABSPATH, DB_*): it does not load WordPress.
}
$wpcheckpoint_uploads = isset( $argv[1] ) ? (string) $argv[1] : UploadsLeftovers::dir();
$wpcheckpoint_refusal = UploadsLeftovers::refusal( rtrim( $wpcheckpoint_uploads, '/' ), UploadsLeftovers::names() );
if ( '' !== $wpcheckpoint_refusal ) {
	fwrite( STDERR, $wpcheckpoint_refusal );
	exit( 77 );
}
exit( 0 );
