<?php
/**
 * Runs Standalone\ConfigLoader in a process of its own for ConfigLoaderTest
 * (constants cannot be defined twice) and prints what it read as JSON.
 *
 * Usage: php load-config.php <abspath> [<HTTP_HOST>]
 *
 * @package WPCheckpoint
 */

define( 'WPCHECKPOINT_STANDALONE', true );
require dirname( __DIR__, 3 ) . '/vendor/autoload.php';

use WPCheckpoint\Standalone\ConfigLoader;
use WPCheckpoint\Standalone\Failure;

if ( isset( $argv[2] ) ) {
	$_SERVER['HTTP_HOST'] = $argv[2];
}
$report = static function ( Failure $failure ): void {
	echo json_encode( array( 'failure' => $failure->reason(), 'message' => $failure->getMessage() ) );
};
try {
	$config      = ConfigLoader::locate( (string) $argv[1] );
	$credentials = ConfigLoader::load( $config, ConfigLoader::stub_dir(), $report );
	$values      = array( 'config' => basename( dirname( $config ) ) . '/' . basename( $config ) );
	foreach ( array( 'name', 'user', 'password', 'host', 'charset', 'collate', 'prefix' ) as $key ) {
		$values[ $key ] = $credentials->get( $key );
	}
	$values['flags']            = $credentials->flags();
	$values['wordpress_loaded'] = function_exists( 'add_action' );
	echo json_encode( $values );
} catch ( Failure $failure ) {
	$report( $failure );
}
