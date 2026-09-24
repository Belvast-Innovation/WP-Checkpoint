<?php
/**
 * Runs Standalone\ConfigLoader in a process of its own for ConfigLoaderTest
 * (constants cannot be defined twice) and prints what it read as JSON.
 *
 * Usage: php load-config.php <abspath> [<HTTP_HOST>]
 *
 * @package WPCheckpoint
 */

'cli' === PHP_SAPI || exit; // Prints the values it reads: never over the web.

// What an entry that runs without WordPress does first: no arguments in exception traces, ABSPATH at the stub.
ini_set( 'zend.exception_ignore_args', '1' );
// WPC_OTHER_SPELLING=1: the same directory written another way (as "D:/a" and "D:\\a" on Windows).
define( 'ABSPATH', dirname( __DIR__, 3 ) . ( '1' === getenv( 'WPC_OTHER_SPELLING' ) ? '/src/Standalone/../Standalone/stub/' : '/src/Standalone/stub/' ) );
require dirname( __DIR__, 3 ) . '/vendor/autoload.php';

use WPCheckpoint\Standalone\ConfigLoader;
use WPCheckpoint\Standalone\Failure;

if ( isset( $argv[2] ) && '' !== $argv[2] ) {
	$_SERVER['HTTP_HOST'] = $argv[2];
}
// With WPC_ISOLATION=1: the caller's error handler, buffer and settings, to see that they come back.
$isolation = '1' === getenv( 'WPC_ISOLATION' );
$seen      = 0;
if ( $isolation ) {
	set_error_handler(
		static function () use ( &$seen ): bool {
			++$seen;
			return true;
		}
	);
	ini_set( 'display_errors', '0' );
	ob_start();
	echo 'caller output;';
}
$level = ob_get_level();
$after = static function () use ( $isolation, &$seen, $level ): array {
	if ( ! $isolation ) {
		return array();
	}
	trigger_error( 'after', E_USER_WARNING );
	$state = array(
		'handler'   => $seen,
		'level'     => ob_get_level() === $level,
		'display'   => ini_get( 'display_errors' ),
		'kept'      => ob_get_level() > 0 ? (string) ob_get_contents() : '',
	);
	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}
	return array( 'after' => $state );
};
$report = static function ( Failure $failure ): void {
	$trace = var_export( $failure->getTrace(), true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- what a logger would see.
	echo json_encode(
		array(
			'failure' => $failure->reason(),
			'message' => $failure->getMessage(),
			'trace'   => $trace,
		)
	);
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
	$values                     = array_merge( $values, $after() );
	echo json_encode( $values );
} catch ( Failure $failure ) {
	$report( $failure );
}
