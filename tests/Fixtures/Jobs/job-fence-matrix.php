<?php
/**
 * What the job-row fence assumes of the server (Restore\JobFence), checked on a real server (CI runs it on every
 * supported one) at READ COMMITTED and REPEATABLE READ, each observation against the value written here. Two
 * connections: "a" is a run that holds the job under its token, "b" a take-over by another run.
 *
 * - The run's SELECT ... FOR UPDATE of its job row, in a transaction not yet committed, holds the row: the take-over's
 *   UPDATE of that row waits for it (with a lock wait timeout of one second it gives up, 1205, after about that
 *   second), and so does the ledger's UPDATE joined with the row.
 * - Once the run commits, the take-over changes the row.
 * - Taken over, the run's SELECT ... FOR UPDATE under its old token finds no row and does not wait; the ledger's
 *   UPDATE joined with the row under the old token changes nothing; under the new token it changes the record.
 * - The jobs table must be InnoDB for any of this: on another engine the locked read holds nothing (what the schema
 *   pins, and what JobFence::within() refuses).
 *
 * Usage: php job-fence-matrix.php <host> <port> <user> <password>
 *
 * @package WPCheckpoint
 */

'cli' === PHP_SAPI || exit;

mysqli_report( MYSQLI_REPORT_OFF );

/**
 * Connect (and make the database).
 *
 * @param string[] $argv Arguments.
 * @param bool     $make Whether to create the database first.
 * @return mysqli
 */
function jfm_connect( array $argv, bool $make = false ): mysqli {
	$db = mysqli_init();
	if ( ! $db || ! mysqli_real_connect( $db, $argv[1] ?? '127.0.0.1', $argv[3] ?? 'root', $argv[4] ?? '', '', (int) ( $argv[2] ?? 3306 ) ) ) {
		fwrite( STDERR, 'Cannot connect (' . mysqli_connect_errno() . ")\n" );
		exit( 2 );
	}
	if ( $make ) {
		mysqli_query( $db, 'DROP DATABASE IF EXISTS wpc_jobfence_matrix' );
		mysqli_query( $db, 'CREATE DATABASE wpc_jobfence_matrix' );
	}
	mysqli_select_db( $db, 'wpc_jobfence_matrix' );
	return $db;
}

/**
 * Run a statement: [errno, affected rows or row count, seconds].
 *
 * @param mysqli $db  Connection.
 * @param string $sql Statement.
 * @return array{0: int, 1: int, 2: float}
 */
function jfm_run( mysqli $db, string $sql ): array {
	$start  = microtime( true );
	$result = mysqli_query( $db, $sql );
	$took   = microtime( true ) - $start;
	if ( false === $result ) {
		return array( (int) mysqli_errno( $db ), 0, $took );
	}
	if ( $result instanceof mysqli_result ) {
		$n = (int) mysqli_num_rows( $result );
		mysqli_free_result( $result );
		return array( 0, $n, $took );
	}
	return array( 0, (int) mysqli_affected_rows( $db ), $took );
}

$a = jfm_connect( $argv, true );
$b = jfm_connect( $argv );
mysqli_query( $a, 'CREATE TABLE jobs (id bigint unsigned NOT NULL PRIMARY KEY, lock_token varchar(64) NOT NULL DEFAULT \'\') ENGINE=InnoDB' );
mysqli_query( $a, 'CREATE TABLE ledger (n int unsigned NOT NULL PRIMARY KEY, owned bigint NOT NULL DEFAULT -1) ENGINE=InnoDB' );
mysqli_query( $a, "INSERT INTO jobs (id, lock_token) VALUES (1, 'old')" );
mysqli_query( $a, 'INSERT INTO ledger (n, owned) VALUES (1, -1)' );

$lock      = static function ( string $token ): string {
	return "SELECT id FROM jobs WHERE id = 1 AND lock_token = '{$token}' FOR UPDATE";
};
$take_over = "UPDATE jobs SET lock_token = 'new' WHERE id = 1 AND lock_token = 'old'";
$record    = static function ( string $token ): string {
	return "UPDATE ledger AS l JOIN jobs AS j ON j.id = 1 SET l.owned = 5 WHERE l.n = 1 AND l.owned < 0 AND j.lock_token = '{$token}'";
};
$reset     = static function () use ( $a ): void {
	mysqli_query( $a, "UPDATE jobs SET lock_token = 'old'" );
	mysqli_query( $a, 'UPDATE ledger SET owned = -1' );
};

$seen     = array();
$expected = array();
foreach ( array( 'READ COMMITTED', 'REPEATABLE READ' ) as $level ) {
	$key = strtolower( str_replace( ' ', '_', $level ) );
	foreach ( array( $a, $b ) as $db ) {
		mysqli_query( $db, 'SET SESSION TRANSACTION ISOLATION LEVEL ' . $level );
		mysqli_query( $db, 'SET SESSION innodb_lock_wait_timeout = 1' );
	}

	// The run holds its row: the take-over waits, and so does the ledger's joined update from another connection.
	$reset();
	mysqli_query( $a, 'START TRANSACTION' );
	list( , $held )                       = jfm_run( $a, $lock( 'old' ) );
	list( $errno, , $seconds )            = jfm_run( $b, $take_over );
	list( $join_errno )                   = jfm_run( $b, $record( 'old' ) );
	mysqli_query( $a, 'COMMIT' );
	list( $after_errno, $after_affected ) = jfm_run( $b, $take_over );
	$seen[ $key . ':held' ]               = $held;
	$seen[ $key . ':take_over_waits' ]    = $errno;
	$seen[ $key . ':waited_a_second' ]    = $seconds >= 0.9;
	$seen[ $key . ':joined_update_waits' ] = $join_errno;
	$seen[ $key . ':take_over_after_commit' ] = array( $after_errno, $after_affected );

	// Taken over: the old token finds no row, without waiting; the record under the old token changes nothing, under
	// the new one it is made.
	mysqli_query( $a, 'START TRANSACTION' );
	list( $old_errno, $old_rows, $old_seconds ) = jfm_run( $a, $lock( 'old' ) );
	mysqli_query( $a, 'ROLLBACK' );
	list( , $old_record )                 = jfm_run( $a, $record( 'old' ) );
	list( , $new_record )                 = jfm_run( $b, $record( 'new' ) );
	$seen[ $key . ':old_token_after_take_over' ] = array( $old_errno, $old_rows, $old_seconds < 0.5 );
	$seen[ $key . ':record_under_old_token' ]    = $old_record;
	$seen[ $key . ':record_under_new_token' ]    = $new_record;

	$expected += array(
		$key . ':held'                      => 1,
		$key . ':take_over_waits'           => 1205,
		$key . ':waited_a_second'           => true,
		$key . ':joined_update_waits'       => 1205,
		$key . ':take_over_after_commit'    => array( 0, 1 ),
		$key . ':old_token_after_take_over' => array( 0, 0, true ),
		$key . ':record_under_old_token'    => 0,
		$key . ':record_under_new_token'    => 1,
	);
}

$version = (string) mysqli_get_server_info( $a );
mysqli_query( $a, 'DROP DATABASE IF EXISTS wpc_jobfence_matrix' );
$wrong = array();
foreach ( $expected as $key => $value ) {
	if ( ( $seen[ $key ] ?? null ) !== $value ) {
		$wrong[] = sprintf( '%s: expected %s, seen %s', $key, var_export( $value, true ), var_export( $seen[ $key ] ?? null, true ) );
	}
}
echo $version, "\n";
if ( array() !== $wrong ) {
	echo implode( "\n", $wrong ), "\n";
	exit( 1 );
}
echo 'Server ', $version, ': the job-row fence holds (', count( $expected ), " observations as expected).\n";
