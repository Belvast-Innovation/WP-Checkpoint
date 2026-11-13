<?php
/**
 * Before an integration run (bin/test-integration.sh, before PHPUnit): the jobs table of the tests database is there
 * and has every column of the schema's version (JobsTableCheck), or the run does not start. Nothing is changed.
 *
 * Usage: php jobs-table-precheck.php. The tests configuration's database (WP_PHPUNIT__TESTS_CONFIG, or
 * wp-tests-config.php in WP_TESTS_DIR or WP_PHPUNIT__DIR; WordPress is not loaded); without the configuration, or
 * without mysqli, nothing is checked.
 *
 * Exit status: 77 when the table is not whole (or, with a connection, cannot be read), else 0; without a connection
 * nothing is checked (the suite's own start says that).
 *
 * @package WPCheckpoint
 */

// phpcs:disable -- a test fixture run as a script.

use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\JobsTableCheck;

$wpcheckpoint_config = (string) getenv( 'WP_PHPUNIT__TESTS_CONFIG' );
if ( '' === $wpcheckpoint_config ) {
	$wpcheckpoint_tests  = (string) ( getenv( 'WP_TESTS_DIR' ) ?: getenv( 'WP_PHPUNIT__DIR' ) );
	$wpcheckpoint_config = '' === $wpcheckpoint_tests ? '' : $wpcheckpoint_tests . '/wp-tests-config.php';
}
if ( '' === $wpcheckpoint_config || ! is_readable( $wpcheckpoint_config ) || ! class_exists( 'mysqli' ) ) {
	exit( 0 );
}
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require $wpcheckpoint_config; // Its constants and $table_prefix only: it does not load WordPress.

mysqli_report( MYSQLI_REPORT_OFF );
$wpcheckpoint_host = (string) DB_HOST;
$wpcheckpoint_port = null;
if ( 1 === preg_match( '/\A(.*):([0-9]+)\z/', $wpcheckpoint_host, $wpcheckpoint_parts ) ) {
	$wpcheckpoint_host = $wpcheckpoint_parts[1];
	$wpcheckpoint_port = (int) $wpcheckpoint_parts[2];
}
$wpcheckpoint_db = @new mysqli( $wpcheckpoint_host, (string) DB_USER, (string) DB_PASSWORD, (string) DB_NAME, $wpcheckpoint_port );
if ( $wpcheckpoint_db->connect_errno ) {
	// No database to ask: the suite itself says so as it starts, and nothing can be said of the table.
	fwrite( STDERR, "The jobs table of the tests database was not checked (no connection to the database).\n" );
	exit( 0 );
}
$wpcheckpoint_table = ( isset( $table_prefix ) ? (string) $table_prefix : 'wp_' ) . Schema::JOBS_TABLE;
$wpcheckpoint_types = null;
$wpcheckpoint_there = $wpcheckpoint_db->query( "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE' AND `Tables_in_" . $wpcheckpoint_db->real_escape_string( (string) DB_NAME ) . "` = '" . $wpcheckpoint_db->real_escape_string( $wpcheckpoint_table ) . "'" );
if ( false === $wpcheckpoint_there ) {
	fwrite( STDERR, "The jobs table of the tests database could not be checked (the tables could not be listed); the run does not start.\n" );
	exit( 77 );
}
if ( $wpcheckpoint_there->num_rows > 0 ) {
	$wpcheckpoint_rows = $wpcheckpoint_db->query( 'SHOW COLUMNS FROM `' . str_replace( '`', '``', $wpcheckpoint_table ) . '`' );
	if ( false === $wpcheckpoint_rows ) {
		fwrite( STDERR, "The jobs table of the tests database could not be checked (its columns could not be read); the run does not start.\n" );
		exit( 77 );
	}
	$wpcheckpoint_types = array();
	while ( $wpcheckpoint_row = $wpcheckpoint_rows->fetch_assoc() ) {
		$wpcheckpoint_types[ (string) $wpcheckpoint_row['Field'] ] = (string) $wpcheckpoint_row['Type'];
	}
}
$wpcheckpoint_refusal = JobsTableCheck::refusal( $wpcheckpoint_table, $wpcheckpoint_types );
if ( '' !== $wpcheckpoint_refusal ) {
	fwrite( STDERR, $wpcheckpoint_refusal );
	exit( 77 );
}
exit( 0 );
