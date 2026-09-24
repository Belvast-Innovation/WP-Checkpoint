<?php
/**
 * Test probe: what Standalone\ConfigLoader reads from this site's
 * wp-config.php in a web request, as SHA-256 of each value (never a value).
 * Guarded by guard.php; see StandaloneConfigHttpTest.
 *
 * @package WPCheckpoint
 */

require __DIR__ . '/guard.php';

$wpcheckpoint_plugin = dirname( __DIR__, 4 );
ini_set( 'zend.exception_ignore_args', '1' );
define( 'ABSPATH', $wpcheckpoint_plugin . '/src/Standalone/stub/' );
require $wpcheckpoint_plugin . '/vendor/autoload.php';

use WPCheckpoint\Standalone\ConfigLoader;
use WPCheckpoint\Standalone\Failure;

header( 'Content-Type: application/json' );
try {
	$wpcheckpoint_credentials = ConfigLoader::load( ConfigLoader::locate( dirname( $wpcheckpoint_plugin, 3 ) ), ConfigLoader::stub_dir() );
	$wpcheckpoint_hashes      = array();
	foreach ( array( 'name', 'user', 'password', 'host', 'charset', 'collate', 'prefix' ) as $wpcheckpoint_key ) {
		$wpcheckpoint_hashes[ $wpcheckpoint_key ] = hash( 'sha256', $wpcheckpoint_credentials->get( $wpcheckpoint_key ) );
	}
	$wpcheckpoint_hashes['flags'] = hash( 'sha256', (string) $wpcheckpoint_credentials->flags() );
	echo json_encode( $wpcheckpoint_hashes );
} catch ( Failure $wpcheckpoint_failure ) {
	echo json_encode( array( 'failure' => $wpcheckpoint_failure->reason() ) );
}
