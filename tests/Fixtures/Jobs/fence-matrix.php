<?php
/**
 * What the uninstall fence assumes of the server (Jobs\UninstallFence), checked on a real server (CI runs it on every
 * supported one) at READ COMMITTED and REPEATABLE READ, each observation against the value written here. The
 * statements are the plugin's own (UninstallFence::entering_template(), ::close_template()), on two connections: "a"
 * is a swap entering the site, "b" an uninstall.
 *
 * - A swap's entering statement in a transaction not yet committed holds the fence row: the uninstall's close waits
 *   for it (with a lock wait timeout of one second it gives up, 1205, after about that second).
 * - Once the swap commits, the close changes the row, and a plain read right after it sees the swap's site_state.
 * - Once the fence is closed, the entering statement changes nothing.
 * - The reverse: a statement that writes the job's row alone (the fence row left out) does not make the close wait;
 *   the first observation rests on the fence row and nothing else.
 * - The heartbeat (UninstallFence::beat_template()) of the uninstall that closed the fence changes the row even in the
 *   second it closed it (beats counts up, so the server reports it changed); another's changes nothing, nor does it
 *   once the fence is open.
 *
 * Usage: php fence-matrix.php <host> <port> <user> <password>
 *
 * @package WPCheckpoint
 */

'cli' === PHP_SAPI || exit;

define( 'ABSPATH', __DIR__ . '/' );
spl_autoload_register(
	static function ( string $class ): void {
		if ( 0 === strpos( $class, 'WPCheckpoint\\' ) ) {
			$file = dirname( __DIR__, 3 ) . '/src/' . str_replace( '\\', '/', substr( $class, 13 ) ) . '.php';
			if ( is_file( $file ) ) {
				require $file;
			}
		}
	}
);

use WPCheckpoint\Jobs\UninstallFence;

mysqli_report( MYSQLI_REPORT_OFF );

/**
 * A connection to the test database.
 *
 * @param array<int, string> $argv Arguments.
 * @param bool               $make Whether to create the database first.
 * @return mysqli
 */
function fm_connect( array $argv, bool $make = false ): mysqli {
	$db = mysqli_init();
	if ( ! $db || ! mysqli_real_connect( $db, $argv[1] ?? '127.0.0.1', $argv[3] ?? 'root', $argv[4] ?? '', '', (int) ( $argv[2] ?? 3306 ) ) ) {
		fwrite( STDERR, 'Cannot connect (' . mysqli_connect_errno() . ")\n" );
		exit( 2 );
	}
	if ( $make ) {
		mysqli_query( $db, 'DROP DATABASE IF EXISTS wpc_fence_matrix' );
		mysqli_query( $db, 'CREATE DATABASE wpc_fence_matrix' );
	}
	mysqli_select_db( $db, 'wpc_fence_matrix' );
	return $db;
}

/**
 * A statement with its %d and %s placeholders filled in, in order.
 *
 * @param mysqli            $db       Connection.
 * @param string            $template Template.
 * @param array<int, mixed> $values   Values.
 * @return string
 */
function fm_fill( mysqli $db, string $template, array $values ): string {
	if ( preg_match_all( '/%[ds]/', $template ) !== count( $values ) ) {
		fwrite( STDERR, "A template's placeholders and its values differ in number\n" );
		exit( 2 );
	}
	return (string) preg_replace_callback(
		'/%[ds]/',
		static function ( array $m ) use ( $db, &$values ): string {
			$value = array_shift( $values );
			return '%d' === $m[0] ? (string) (int) $value : "'" . mysqli_real_escape_string( $db, (string) $value ) . "'";
		},
		$template
	);
}

/**
 * Run a statement: [errno, affected rows, seconds].
 *
 * @param mysqli $db  Connection.
 * @param string $sql SQL.
 * @return array{0: int, 1: int, 2: float}
 */
function fm_run( mysqli $db, string $sql ): array {
	$start = microtime( true );
	mysqli_query( $db, $sql );
	return array( (int) mysqli_errno( $db ), (int) mysqli_affected_rows( $db ), microtime( true ) - $start );
}

$a = fm_connect( $argv, true );
$b = fm_connect( $argv );
mysqli_query( $a, 'CREATE TABLE jobs (id bigint(20) unsigned NOT NULL, site_state tinyint(3) unsigned NOT NULL DEFAULT 0, lock_token varchar(64) NOT NULL DEFAULT \'\', updated_at bigint(20) unsigned NOT NULL DEFAULT 0, PRIMARY KEY (id)) ENGINE=InnoDB' );
mysqli_query( $a, UninstallFence::create_sql( 'fence' ) );
mysqli_query( $a, "INSERT INTO jobs (id, lock_token) VALUES (1, 'run')" );
mysqli_query( $a, "INSERT INTO fence (id, state) VALUES (1, 'open')" );

$entering = fm_fill( $a, UninstallFence::entering_template( 'jobs', 'fence', array( 'site_state', 'updated_at' ), array( '%d', '%d' ) ), array( UninstallFence::ROW, UninstallFence::OPEN, 1, time(), time(), 1, 'run' ) );
$close    = fm_fill( $b, UninstallFence::close_template( 'fence' ), array( UninstallFence::CLOSED, 'run', UninstallFence::ROW, UninstallFence::OPEN ) );
$beat     = static function ( mysqli $db, string $by ): string {
	return fm_fill( $db, UninstallFence::beat_template( 'fence' ), array( UninstallFence::ROW, UninstallFence::CLOSED, $by ) );
};
$alone    = "UPDATE jobs SET site_state = 1, updated_at = 0 WHERE id = 1 AND lock_token = 'run'";
$reset    = static function () use ( $a ): void {
	mysqli_query( $a, 'UPDATE jobs SET site_state = 0' );
	mysqli_query( $a, "UPDATE fence SET state = 'open'" );
};

$seen     = array();
$expected = array();
foreach ( array( 'READ COMMITTED', 'REPEATABLE READ' ) as $level ) {
	$key = strtolower( str_replace( ' ', '_', $level ) );
	foreach ( array( $a, $b ) as $db ) {
		mysqli_query( $db, 'SET SESSION TRANSACTION ISOLATION LEVEL ' . $level );
		mysqli_query( $db, 'SET SESSION innodb_lock_wait_timeout = 1' );
	}

	// A swap entering, not yet committed: the close waits for it.
	$reset();
	mysqli_query( $a, 'START TRANSACTION' );
	list( , $entered )         = fm_run( $a, $entering );
	list( $errno, , $seconds ) = fm_run( $b, $close );
	mysqli_query( $a, 'COMMIT' );
	list( $after_errno, $after_affected ) = fm_run( $b, $close );
	$state                                = mysqli_query( $b, 'SELECT site_state FROM jobs WHERE id = 1' );
	$seen[ $key . ':entered' ]            = $entered;
	$seen[ $key . ':close_waits' ]        = $errno;
	$seen[ $key . ':waited_a_second' ]    = $seconds >= 0.9;
	$seen[ $key . ':close_after_commit' ] = array( $after_errno, $after_affected );
	$seen[ $key . ':read_after_close' ]   = $state ? (int) mysqli_fetch_row( $state )[0] : -1;

	// Closed first: the entering statement changes nothing.
	$reset();
	list( , $closed )                 = fm_run( $b, $close );
	list( $enter_errno, $refused )    = fm_run( $a, $entering );
	$seen[ $key . ':closed' ]         = $closed;
	$seen[ $key . ':entering_closed' ] = array( $enter_errno, $refused );

	// The heartbeat, right after the close: its own (twice in the same second), another's, and once open.
	list( , $own )           = fm_run( $b, $beat( $b, 'run' ) );
	list( , $again )         = fm_run( $b, $beat( $b, 'run' ) );
	list( , $another )       = fm_run( $b, $beat( $b, 'another' ) );
	mysqli_query( $a, "UPDATE fence SET state = 'open'" );
	list( , $open )          = fm_run( $b, $beat( $b, 'run' ) );
	$seen[ $key . ':beats' ] = array( $own, $again, $another, $open );

	// The reverse: the job's row alone does not hold the close.
	$reset();
	mysqli_query( $a, 'START TRANSACTION' );
	fm_run( $a, $alone );
	list( $alone_errno ) = fm_run( $b, $close );
	mysqli_query( $a, 'ROLLBACK' );
	$seen[ $key . ':close_without_the_fence_row' ] = $alone_errno;

	$expected += array(
		$key . ':entered'                     => 2,
		$key . ':close_waits'                 => 1205,
		$key . ':waited_a_second'             => true,
		$key . ':close_after_commit'          => array( 0, 1 ),
		$key . ':read_after_close'            => 1,
		$key . ':closed'                      => 1,
		$key . ':entering_closed'             => array( 0, 0 ),
		$key . ':beats'                       => array( 1, 1, 0, 0 ),
		$key . ':close_without_the_fence_row' => 0,
	);
}

$version = (string) mysqli_get_server_info( $a );
mysqli_query( $a, 'DROP DATABASE IF EXISTS wpc_fence_matrix' );
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
echo "The uninstall fence behaves as the plugin expects.\n";
