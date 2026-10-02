<?php
/**
 * What the swap assumes of RENAME TABLE, checked on a real server (CI runs it on every supported one), each
 * observation against the value written here:
 *
 * - a statement that fails (a target name in use, 1050) renames none of its tables;
 * - the session's lock_wait_timeout bounds how long a RENAME waits for a table another connection holds (by LOCK
 *   TABLES, or by reading it in a transaction that is still open), and the error is 1205 (the one the swap retries
 *   after waiting: SwapStep::RETRIES);
 * - a batch of the size the swap allows (SwapRules::limit() of the server's max_allowed_packet) is accepted;
 * - within one statement, the live table moved aside first leaves its name free for the restored one;
 * - a listing right after the statement shows what it did (the swap decides by it).
 *
 * Usage: php rename-matrix.php <host> <port> <user> <password>
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

use WPCheckpoint\Restore\SwapRules;

mysqli_report( MYSQLI_REPORT_OFF );

/**
 * A connection to the test database.
 *
 * @param array<int, string> $argv Arguments.
 * @param bool               $make Whether to create the database first.
 * @return mysqli
 */
function rnm_connect( array $argv, bool $make = false ): mysqli {
	$db = mysqli_init();
	if ( ! $db || ! mysqli_real_connect( $db, $argv[1] ?? '127.0.0.1', $argv[3] ?? 'root', $argv[4] ?? '', '', (int) ( $argv[2] ?? 3306 ) ) ) {
		fwrite( STDERR, 'Cannot connect (' . mysqli_connect_errno() . ")\n" );
		exit( 2 );
	}
	if ( $make ) {
		mysqli_query( $db, 'DROP DATABASE IF EXISTS wpc_rename_matrix' );
		mysqli_query( $db, 'CREATE DATABASE wpc_rename_matrix' );
	}
	mysqli_select_db( $db, 'wpc_rename_matrix' );
	return $db;
}

/**
 * Run a statement: [errno, seconds].
 *
 * @param mysqli $db  Connection.
 * @param string $sql SQL.
 * @return array{0: int, 1: float}
 */
function rnm_run( mysqli $db, string $sql ): array {
	$start = microtime( true );
	mysqli_query( $db, $sql );
	return array( (int) mysqli_errno( $db ), microtime( true ) - $start );
}

/**
 * The tables of the test database, sorted.
 *
 * @param mysqli $db Connection.
 * @return string[]
 */
function rnm_tables( mysqli $db ): array {
	$out    = array();
	$result = mysqli_query( $db, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'wpc_rename_matrix'" );
	foreach ( $result ? mysqli_fetch_all( $result ) : array() as $row ) {
		$out[] = (string) $row[0];
	}
	sort( $out );
	return $out;
}

/**
 * Start over with these tables.
 *
 * @param mysqli   $db     Connection.
 * @param string[] $tables Tables.
 * @return void
 */
function rnm_tables_are( mysqli $db, array $tables ): void {
	foreach ( rnm_tables( $db ) as $table ) {
		mysqli_query( $db, "DROP TABLE `{$table}`" );
	}
	foreach ( $tables as $table ) {
		mysqli_query( $db, "CREATE TABLE `{$table}` (id INT PRIMARY KEY) ENGINE=InnoDB" );
	}
}

$a       = rnm_connect( $argv, true );
$b       = rnm_connect( $argv );
$version = (string) mysqli_fetch_row( mysqli_query( $a, 'SELECT VERSION()' ) )[0];
$seen    = array();

// A failing statement renames nothing.
rnm_tables_are( $a, array( 'wp_options', 'wp_posts', 'wcpold_posts' ) );
list( $errno )          = rnm_run( $a, 'RENAME TABLE `wp_options` TO `wcpold_options`, `wp_posts` TO `wcpold_posts`' );
$seen['failed_errno']   = $errno;
$seen['failed_renamed'] = rnm_tables( $a ) !== array( 'wcpold_posts', 'wp_options', 'wp_posts' );

// The session's lock wait bounds a RENAME blocked by LOCK TABLES, and by an open transaction that read the table.
rnm_run( $a, 'SET SESSION lock_wait_timeout = ' . WPCheckpoint\Jobs\SwapStep::LOCK_WAIT );
$seen['lock_wait_set'] = (int) mysqli_fetch_row( mysqli_query( $a, 'SELECT @@SESSION.lock_wait_timeout' ) )[0];
rnm_run( $a, 'SET SESSION lock_wait_timeout = 1' ); // One second here: the same bound, a shorter test.
rnm_tables_are( $a, array( 'wp_options' ) );
rnm_run( $b, 'LOCK TABLES `wp_options` READ' );
list( $errno, $took )        = rnm_run( $a, 'RENAME TABLE `wp_options` TO `wcpold_options`' );
$seen['locked_errno']        = $errno;
$seen['locked_bounded']      = $took >= 0.9 && $took < 3.0;
rnm_run( $b, 'UNLOCK TABLES' );
rnm_run( $b, 'SET autocommit = 0' );
mysqli_query( $b, 'SELECT * FROM `wp_options`' );
list( $errno, $took )        = rnm_run( $a, 'RENAME TABLE `wp_options` TO `wcpold_options`' );
$seen['transaction_errno']   = $errno;
$seen['transaction_bounded'] = $took >= 0.9 && $took < 3.0;
rnm_run( $b, 'COMMIT' );
list( $errno )               = rnm_run( $a, 'RENAME TABLE `wp_options` TO `wcpold_options`' );
$seen['free_errno']          = $errno; // The control: with the lock gone it goes through.

// A batch as large as the swap allows is accepted.
$packet = (int) mysqli_fetch_row( mysqli_query( $a, 'SELECT @@max_allowed_packet' ) )[0];
$limit  = SwapRules::limit( $packet );
rnm_tables_are( $a, array( 'wp_options' ) );
$sql                   = 'RENAME TABLE `wp_options` TO `wcpold_options` /* ';
$sql                  .= str_repeat( 'x', $limit - strlen( $sql ) - 3 ) . ' */';
list( $errno )         = rnm_run( $a, $sql );
$seen['batch_bytes']   = strlen( $sql ) === $limit;
$seen['batch_errno']   = $errno;
$seen['limit_in_room'] = $limit <= $packet;

// Within one statement the live table, moved aside first, leaves its name to the restored one; the listing shows it.
rnm_tables_are( $a, array( 'wp_options', 'wcptmp_options', 'wp_gone' ) );
$batches = SwapRules::batches(
	array(
		array(
			'seq'      => 0,
			'kind'     => 'table',
			'live'     => 'wp_options',
			'stage'    => 'wcptmp_options',
			'old'      => 'wcpold_options',
			'had_live' => true,
		),
		array(
			'seq'      => 1,
			'kind'     => 'move',
			'live'     => 'wp_gone',
			'stage'    => '',
			'old'      => 'wcpold_gone',
			'had_live' => true,
		),
	),
	$limit
);
list( $errno )       = rnm_run( $a, $batches[0]['sql'] );
$seen['swap_errno']  = $errno;
$seen['swap_listed'] = rnm_tables( $a ) === array( 'wcpold_gone', 'wcpold_options', 'wp_options' );

mysqli_query( $a, 'DROP DATABASE wpc_rename_matrix' );

$expected = array(
	'failed_errno'        => 1050,
	'failed_renamed'      => false,
	'lock_wait_set'       => WPCheckpoint\Jobs\SwapStep::LOCK_WAIT,
	'locked_errno'        => 1205,
	'locked_bounded'      => true,
	'transaction_errno'   => 1205,
	'transaction_bounded' => true,
	'free_errno'          => 0,
	'batch_bytes'         => true,
	'batch_errno'         => 0,
	'limit_in_room'       => true,
	'swap_errno'          => 0,
	'swap_listed'         => true,
);
$wrong = array();
foreach ( $expected as $key => $value ) {
	if ( ! array_key_exists( $key, $seen ) || $seen[ $key ] !== $value ) {
		$wrong[] = sprintf( '%s: expected %s, seen %s', $key, var_export( $value, true ), var_export( $seen[ $key ] ?? null, true ) );
	}
}
printf( "%s, max_allowed_packet %d, batch limit %d\n", $version, $packet, $limit );
if ( array() !== $wrong ) {
	fwrite( STDERR, implode( "\n", $wrong ) . "\n" );
	exit( 1 );
}
echo "RENAME behaves as the swap expects.\n";
