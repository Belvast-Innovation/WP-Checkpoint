<?php
/**
 * Test probe: the same settings as WordPress itself loads them in a web
 * request (wp-load.php, SHORTINIT), as SHA-256 of each value. Guarded by
 * guard.php; see StandaloneConfigHttpTest.
 *
 * @package WPCheckpoint
 */

require __DIR__ . '/guard.php';

define( 'SHORTINIT', true );
require dirname( __DIR__, 7 ) . '/wp-load.php';

header( 'Content-Type: application/json' );
$wpcheckpoint_values = array(
	'name'     => DB_NAME,
	'user'     => DB_USER,
	'password' => DB_PASSWORD,
	'host'     => DB_HOST,
	'charset'  => defined( 'DB_CHARSET' ) ? DB_CHARSET : '',
	'collate'  => defined( 'DB_COLLATE' ) ? DB_COLLATE : '',
	'prefix'   => $GLOBALS['table_prefix'],
	'flags'    => (string) ( defined( 'MYSQL_CLIENT_FLAGS' ) ? MYSQL_CLIENT_FLAGS : 0 ),
);
echo json_encode( array_map( static function ( $value ) {
	return hash( 'sha256', (string) $value );
}, $wpcheckpoint_values ) );
