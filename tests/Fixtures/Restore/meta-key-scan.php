<?php
/**
 * The restore's walk over a usermeta table for "{prefix}capabilities" keys, run on a real server: one window of
 * umeta_id at a time (the window alone bounds the batch; the suffix is filtered in SQL), and over a gap the next
 * existing id (SELECT MIN(umeta_id) ... WHERE umeta_id > ?). Checks, on every supported server, that both are read
 * through the primary key (a range, or the minimum optimized away), and that the walk finds exactly the keys one
 * SELECT DISTINCT finds, across sparse ids and keys that differ only in case. CI runs it on every supported server.
 *
 * Not "the next distinct key" (SELECT MIN(meta_key) ... WHERE meta_key > ?): WordPress indexes meta_key by its first
 * 191 characters only, and a server cannot answer that from such an index (a scan of everything after the key).
 *
 * Usage: php meta-key-scan.php <host> <port> <user> <password>
 *
 * @package WPCheckpoint
 */

'cli' === PHP_SAPI || exit;

mysqli_report( MYSQLI_REPORT_OFF );
$db = mysqli_init();
if ( ! $db || ! mysqli_real_connect( $db, $argv[1] ?? '127.0.0.1', $argv[3] ?? 'root', $argv[4] ?? '', '', (int) ( $argv[2] ?? 3306 ) ) ) {
	fwrite( STDERR, 'Cannot connect (' . mysqli_connect_errno() . ")\n" );
	exit( 2 );
}
$version = (string) mysqli_fetch_row( mysqli_query( $db, 'SELECT VERSION()' ) )[0];

/**
 * A query's rows, or a failure with the server's error.
 *
 * @param string $sql SQL.
 * @return array<int, array<string, string|null>>
 * @throws RuntimeException On an error.
 */
function mks_rows( string $sql ): array {
	global $db;
	$result = mysqli_query( $db, $sql );
	if ( false === $result ) {
		throw new RuntimeException( mysqli_errno( $db ) . ' ' . mysqli_error( $db ) . "\n    in: " . substr( $sql, 0, 300 ) );
	}
	return true === $result ? array() : mysqli_fetch_all( $result, MYSQLI_ASSOC );
}

const MKS_WINDOW = 5000;

$failed = array();
try {
	mks_rows( 'DROP DATABASE IF EXISTS wpc_meta_keys' );
	mks_rows( 'CREATE DATABASE wpc_meta_keys CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci' );
	mks_rows( 'USE wpc_meta_keys' );
	// WordPress's usermeta table (wp-admin/includes/schema.php).
	mks_rows( "CREATE TABLE wp_usermeta ( umeta_id bigint(20) unsigned NOT NULL auto_increment, user_id bigint(20) unsigned NOT NULL default '0', meta_key varchar(255) default NULL, meta_value longtext, PRIMARY KEY  (umeta_id), KEY user_id (user_id), KEY meta_key (meta_key(191)) ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci" );
	$keys = array( 'wp_capabilities', 'wp_user_level', 'wp_2_capabilities', 'wp2_capabilities', 'WP2_CAPABILITIES', 'nickname', 'session_tokens', 'capabilities', 'wp_old_user_level' );
	for ( $i = 0; $i < 40; $i++ ) {
		$keys[] = 'plugin_key_' . $i;
	}
	$values = array();
	for ( $user = 1; $user <= 400; $user++ ) {
		foreach ( $keys as $key ) {
			$values[] = '(' . $user . ", '" . mysqli_real_escape_string( $db, $key ) . "', 'v')";
		}
	}
	foreach ( array_chunk( $values, 1000 ) as $chunk ) {
		mks_rows( 'INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES ' . implode( ', ', $chunk ) );
	}
	// A gap of many windows, then a key found only past it.
	mks_rows( "INSERT INTO wp_usermeta (umeta_id, user_id, meta_key, meta_value) VALUES (900000, 1, 'wp_far_capabilities', 'v')" );
	mks_rows( 'ANALYZE TABLE wp_usermeta' );

	$window = sprintf( "SELECT meta_key FROM wp_usermeta WHERE umeta_id > %d AND umeta_id <= %d AND meta_key LIKE '%%\\_capabilities'", 5000, 5000 + MKS_WINDOW );
	$next   = 'SELECT MIN(umeta_id) AS id FROM wp_usermeta WHERE umeta_id > 20000';
	foreach ( array(
		'window' => $window,
		'next'   => $next,
	) as $label => $sql ) {
		$row = mks_rows( 'EXPLAIN ' . $sql )[0] ?? array();
		echo $version, ' ', $label, ': ', json_encode( $row ), "\n";
		$primary  = 'PRIMARY' === ( $row['key'] ?? null ) && in_array( $row['type'] ?? null, array( 'range', 'index' ), true );
		$constant = false !== strpos( (string) ( $row['Extra'] ?? '' ), 'Select tables optimized away' );
		if ( ! $primary && ! ( 'next' === $label && $constant ) ) {
			$failed[] = 'the ' . $label . ' query is not read through the primary key';
		}
	}

	// The walk finds exactly the keys one query finds.
	$found = array();
	$after = 0;
	for ( $steps = 0; $steps < 100000; $steps++ ) {
		$rows = mks_rows( sprintf( "SELECT meta_key FROM wp_usermeta WHERE umeta_id > %d AND umeta_id <= %d AND meta_key LIKE '%%\\_capabilities'", $after, $after + MKS_WINDOW ) );
		foreach ( $rows as $row ) {
			$found[ (string) $row['meta_key'] ] = true;
		}
		$after += MKS_WINDOW;
		$next   = mks_rows( 'SELECT MIN(umeta_id) AS id FROM wp_usermeta WHERE umeta_id > ' . $after )[0]['id'] ?? null;
		if ( null === $next ) {
			break;
		}
		$after = max( $after, (int) $next - 1 );
	}
	$walked = array_keys( $found );
	sort( $walked, SORT_STRING );
	$all = array_column( mks_rows( "SELECT DISTINCT BINARY meta_key AS k FROM wp_usermeta WHERE meta_key LIKE '%\\_capabilities'" ), 'k' );
	sort( $all, SORT_STRING );
	if ( $walked !== $all ) {
		$failed[] = 'the walk found ' . json_encode( $walked ) . ', one query ' . json_encode( $all );
	}
	if ( ! in_array( 'wp_far_capabilities', $walked, true ) || ! in_array( 'WP2_CAPABILITIES', $walked, true ) ) {
		$failed[] = 'the walk missed the key past the gap, or a key in another case';
	}
	echo $version, ': found ', json_encode( $walked ), ' in ', $steps + 1, " windows\n";
} catch ( RuntimeException $e ) {
	$failed[] = $e->getMessage();
}
mks_rows( 'DROP DATABASE IF EXISTS wpc_meta_keys' );
if ( array() !== $failed ) {
	fwrite( STDERR, $version . ":\n  " . implode( "\n  ", $failed ) . "\n" );
	exit( 1 );
}
echo $version, ": ok\n";
