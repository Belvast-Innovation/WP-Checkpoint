<?php
/**
 * Before an integration run (bin/test-integration.sh, before PHPUnit), what an earlier run left that would make a test
 * fail for a reason not its own, and the run does not start: an entry of the test site's uploads directory whose name
 * starts with one the tests make there (UploadsLeftovers::names(), read from the tests; a test that makes it again
 * meets it, or packs it with its own), and a restore's loader probe in its must-use plugins directory
 * (ContentLeftovers::probes(); the site loads it as a must-use plugin). They are listed; nothing is removed.
 *
 * Usage: php leftovers-precheck.php [<uploads directory> <must-use plugins directory>]. Without them, the test site's
 * (the tests configuration's ABSPATH, WP_CONTENT_DIR and WPMU_PLUGIN_DIR; WordPress is not loaded); without the tests
 * configuration nothing is checked.
 *
 * Exit status: 77 when such an entry is there, else 0.
 *
 * @package WPCheckpoint
 */

// phpcs:disable -- a test fixture run as a script.

use WPCheckpoint\Tests\Fixtures\ContentLeftovers;
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
} elseif ( ! defined( 'ABSPATH' ) ) {
	// Given the directories (a test of this script): the plugin's classes load only with ABSPATH defined (their first
	// statement), and nothing here reads it.
	define( 'ABSPATH', rtrim( sys_get_temp_dir(), '/\\' ) . '/' );
}
$wpcheckpoint_uploads = isset( $argv[1] ) ? (string) $argv[1] : UploadsLeftovers::dir();
$wpcheckpoint_mu      = isset( $argv[2] ) ? (string) $argv[2] : ContentLeftovers::mu_dir();
$wpcheckpoint_refusal = UploadsLeftovers::refusal( rtrim( $wpcheckpoint_uploads, '/' ), UploadsLeftovers::names() ) . ContentLeftovers::refusal( rtrim( $wpcheckpoint_mu, '/' ) );
if ( '' !== $wpcheckpoint_refusal ) {
	fwrite( STDERR, $wpcheckpoint_refusal );
	exit( 77 );
}
exit( 0 );
