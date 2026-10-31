<?php
/**
 * Before an integration run (bin/test-integration.sh, after the maintenance check): the entries of this plugin's
 * directory, counted as the restore counts its own copy (every entry under it, a link counted and not followed), said,
 * with a warning from WARN_SHARE of the limit (RestoreFilesPreflightStep::MAX_PLUGIN_ENTRIES) on. On the test site the
 * directory is the working copy (its node_modules, vendor, .git and build count too). The suite's restores read a
 * stand-in (tests/Fixtures/Restore/PluginStandIn.php) and do not depend on it; the test of the count on the real
 * directory (RealPluginDirCountTest) fails past the limit.
 *
 * Usage: php plugin-entries.php <plugin directory>. Exits 0 whatever it finds (it only says; without the development
 * dependencies, vendor/, that nothing was counted); 1 without a directory.
 *
 * @package WPCheckpoint
 */

use WPCheckpoint\Jobs\RestoreFilesPreflightStep;

const WPCHECKPOINT_ENTRIES_WARN_SHARE = 0.9;

$wpcheckpoint_root = rtrim( (string) ( $argv[1] ?? '' ), '/' );
if ( '' === $wpcheckpoint_root || ! is_dir( $wpcheckpoint_root ) ) {
	fwrite( STDERR, "Usage: php plugin-entries.php <plugin directory>\n" );
	exit( 1 );
}
if ( ! is_readable( dirname( __DIR__, 2 ) . '/vendor/autoload.php' ) ) {
	fwrite( STDERR, "The plugin directory's entries were not counted: vendor/autoload.php is not there to read the restore's limit.\n" );
	exit( 0 );
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' ); // Only to read a constant: the plugin's files exit without it.
}
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$wpcheckpoint_limit   = RestoreFilesPreflightStep::MAX_PLUGIN_ENTRIES;
$wpcheckpoint_entries = 0;
$wpcheckpoint_dirs    = array( $wpcheckpoint_root );
$wpcheckpoint_missed  = 0;
while ( array() !== $wpcheckpoint_dirs ) {
	$wpcheckpoint_dir  = array_pop( $wpcheckpoint_dirs );
	$wpcheckpoint_list = @scandir( $wpcheckpoint_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- counted below.
	if ( ! is_array( $wpcheckpoint_list ) ) {
		++$wpcheckpoint_missed;
		continue;
	}
	foreach ( $wpcheckpoint_list as $wpcheckpoint_entry ) {
		if ( '.' === $wpcheckpoint_entry || '..' === $wpcheckpoint_entry ) {
			continue;
		}
		++$wpcheckpoint_entries;
		$wpcheckpoint_path = $wpcheckpoint_dir . '/' . $wpcheckpoint_entry;
		if ( ! is_link( $wpcheckpoint_path ) && is_dir( $wpcheckpoint_path ) ) {
			$wpcheckpoint_dirs[] = $wpcheckpoint_path;
		}
	}
}

$wpcheckpoint_line = sprintf( 'The plugin directory holds %d entries; a restore takes at most %d in its copy of the running plugin.', $wpcheckpoint_entries, $wpcheckpoint_limit );
if ( 0 < $wpcheckpoint_missed ) {
	$wpcheckpoint_line .= sprintf( ' %d directories could not be listed, and are not counted.', $wpcheckpoint_missed );
}
if ( $wpcheckpoint_entries >= $wpcheckpoint_limit * WPCHECKPOINT_ENTRIES_WARN_SHARE ) {
	$wpcheckpoint_line = 'Warning: ' . $wpcheckpoint_line . ( $wpcheckpoint_entries > $wpcheckpoint_limit ? ' It is past that' : ' It is close to that' )
		. ': the test of the count on the real directory (RealPluginDirCountTest) fails past it. Most of them are in node_modules, vendor, .git and build.';
}
fwrite( STDERR, $wpcheckpoint_line . "\n" );
exit( 0 );
