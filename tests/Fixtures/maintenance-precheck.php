<?php
/**
 * Before an integration run (bin/test-integration.sh, before PHPUnit): the swap's maintenance file, its data file and
 * their temporary names in the test site's WordPress directory (RealAbspath::listing()), said.
 *
 * A held .maintenance stops the run itself: the suite's bootstrap is no install, so WordPress answers it with its
 * maintenance page before the leftover check (Leftovers), which removes this plugin's files as a suite starts, runs at
 * all. A run stopped while a test held the file (SwapMaintenanceModeTest and the other classes RealAbspath names)
 * leaves it there.
 *
 * Removing them here is new deletion logic, so by default this lists only and removes nothing:
 * WPCHECKPOINT_TEST_MAINTENANCE_REMOVE=1 removes what is this plugin's (RealAbspath::clear(); anything else is said and
 * left), and only on wp-env's test site: ABSPATH is the test site's WordPress directory (RealAbspath::refusal()) and
 * the database the configuration names, and the one the server says the connection uses, are wp-env's tests database
 * on its server (Leftovers::database_refusal()); otherwise nothing is removed and the reason is said.
 *
 * Exit status: 76 when .maintenance is still there afterwards (the run would only meet the maintenance page), else 0.
 * Without the tests configuration (WP_PHPUNIT__TESTS_CONFIG, or wp-tests-config.php in WP_TESTS_DIR or
 * WP_PHPUNIT__DIR) nothing is checked: the bootstrap says the test library is missing.
 *
 * @package WPCheckpoint
 */

use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Tests\Fixtures\Leftovers;
use WPCheckpoint\Tests\Fixtures\RealAbspath;

$wpcheckpoint_config = (string) getenv( 'WP_PHPUNIT__TESTS_CONFIG' );
if ( '' === $wpcheckpoint_config ) {
	$wpcheckpoint_tests  = (string) ( getenv( 'WP_TESTS_DIR' ) ?: getenv( 'WP_PHPUNIT__DIR' ) );
	$wpcheckpoint_config = '' === $wpcheckpoint_tests ? '' : $wpcheckpoint_tests . '/wp-tests-config.php';
}
if ( '' === $wpcheckpoint_config || ! is_readable( $wpcheckpoint_config ) ) {
	exit( 0 );
}
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require $wpcheckpoint_config; // Its constants only (ABSPATH, DB_*): it does not load WordPress.

$wpcheckpoint_found = RealAbspath::listing();
if ( array() === $wpcheckpoint_found ) {
	fwrite( STDERR, 'No maintenance file in the test site\'s WordPress directory (' . rtrim( ABSPATH, '/' ) . ").\n" );
	exit( 0 );
}

/**
 * Why nothing may be removed here, or '': RealAbspath's check of ABSPATH, then the database (configured, and as the
 * server says).
 *
 * @return string
 */
function wpcheckpoint_precheck_refusal(): string {
	$why = RealAbspath::refusal();
	if ( '' !== $why ) {
		return $why;
	}
	if ( ! class_exists( 'mysqli' ) ) {
		return 'mysqli is not available to confirm the database is wp-env\'s';
	}
	mysqli_report( MYSQLI_REPORT_OFF );
	$host = (string) DB_HOST;
	$port = null;
	if ( 1 === preg_match( '/\A(.*):([0-9]+)\z/', $host, $parts ) ) {
		$host = $parts[1];
		$port = (int) $parts[2];
	}
	$db = @new mysqli( $host, (string) DB_USER, (string) DB_PASSWORD, (string) DB_NAME, $port ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failure is said below.
	if ( 0 !== $db->connect_errno ) {
		return 'the tests database could not be reached to confirm it is wp-env\'s (' . $db->connect_error . ')';
	}
	$result = $db->query( 'SELECT DATABASE()' );
	$server = false === $result ? '' : (string) ( $result->fetch_row()[0] ?? '' );
	$db->close();
	return Leftovers::database_refusal( (string) DB_NAME, $server, (string) DB_HOST );
}

$wpcheckpoint_lines = array();
foreach ( $wpcheckpoint_found as $wpcheckpoint_path ) {
	$wpcheckpoint_lines[] = '  ' . $wpcheckpoint_path . ( RealAbspath::ours( $wpcheckpoint_path ) ? ' (this plugin\'s)' : ' (not this plugin\'s)' );
}
fwrite( STDERR, "Maintenance files in the test site's WordPress directory before the run:\n" . implode( "\n", $wpcheckpoint_lines ) . "\n" );

if ( '1' !== getenv( 'WPCHECKPOINT_TEST_MAINTENANCE_REMOVE' ) ) {
	fwrite( STDERR, "Listed only; nothing was removed (WPCHECKPOINT_TEST_MAINTENANCE_REMOVE=1 removes this plugin's, on wp-env's test site only).\n" );
} else {
	$wpcheckpoint_refusal = wpcheckpoint_precheck_refusal();
	if ( '' !== $wpcheckpoint_refusal ) {
		fwrite( STDERR, 'Nothing was removed: ' . rtrim( $wpcheckpoint_refusal, '.' ) . ".\n" );
	} else {
		foreach ( $wpcheckpoint_found as $wpcheckpoint_path ) {
			RealAbspath::clear( $wpcheckpoint_path ); // This plugin's only; anything else is said and left.
		}
		$wpcheckpoint_left = RealAbspath::listing();
		fwrite( STDERR, array() === $wpcheckpoint_left ? "Removed.\n" : "Left in place:\n  " . implode( "\n  ", $wpcheckpoint_left ) . "\n" );
	}
}

clearstatcache();
if ( file_exists( rtrim( ABSPATH, '/' ) . '/' . Maintenance::FILE ) ) {
	fwrite( STDERR, "The run does not start: with .maintenance in the test site's WordPress directory, WordPress answers the run's own start with its maintenance page.\n" );
	exit( 76 );
}
exit( 0 );
