<?php
/**
 * The restore's constraint names, run end to end on a real server: every
 * table definition in show-create/ (what MySQL 5.7, 8.4, MariaDB 10.6 and
 * 12 write, so backups from each of them) is created as the live site,
 * imported next to it under temporary names through CreateTable's rewrite,
 * swapped in with one RENAME TABLE, and cleaned up as the cleanup after the
 * swap does (old tables dropped child first, constraints renamed to the
 * names they are meant to have). Checks that nothing collides, that the new
 * child's keys enforce against the new parent, and that every foreign key
 * ends with its intended name. CI runs it on every supported server.
 *
 * Usage: php constraint-rules.php <host> <port> <user> <password>
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

use WPCheckpoint\Database\SqlWriter;
use WPCheckpoint\Restore\ConstraintNames;
use WPCheckpoint\Restore\CreateTable;
use WPCheckpoint\Restore\SqlLexer;

mysqli_report( MYSQLI_REPORT_OFF );
$db = mysqli_init();
if ( ! $db || ! mysqli_real_connect( $db, $argv[1] ?? '127.0.0.1', $argv[3] ?? 'root', $argv[4] ?? '', '', (int) ( $argv[2] ?? 3306 ) ) ) {
	fwrite( STDERR, 'Cannot connect (' . mysqli_connect_errno() . ")\n" );
	exit( 2 );
}
$version = (string) mysqli_fetch_row( mysqli_query( $db, 'SELECT VERSION()' ) )[0];

/**
 * Run a statement or fail with the server's error.
 *
 * @param string $sql SQL.
 * @return void
 * @throws RuntimeException On an error.
 */
function cr_run( string $sql ): void {
	global $db;
	if ( false === mysqli_query( $db, $sql ) ) {
		throw new RuntimeException( mysqli_errno( $db ) . ' ' . mysqli_error( $db ) . "\n    in: " . substr( $sql, 0, 300 ) );
	}
}

/**
 * Foreign keys of a table: name => [referenced table, columns, referenced columns].
 *
 * @param string $table Table.
 * @return array<string, array{0: string, 1: string, 2: string}>
 */
function cr_keys( string $table ): array {
	global $db;
	$out    = array();
	$result = mysqli_query( $db, "SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION), GROUP_CONCAT(REFERENCED_COLUMN_NAME ORDER BY ORDINAL_POSITION) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . mysqli_real_escape_string( $db, $table ) . "' AND REFERENCED_TABLE_NAME IS NOT NULL GROUP BY CONSTRAINT_NAME, REFERENCED_TABLE_NAME" );
	while ( $result && ( $row = mysqli_fetch_row( $result ) ) ) {
		$out[ $row[0] ] = array( $row[1], $row[2], $row[3] );
	}
	return $out;
}

/**
 * A definition as another server would accept it: the collations only some servers know become one all know.
 *
 * @param string $sql CREATE TABLE.
 * @return string
 */
function cr_portable( string $sql ): string {
	return (string) preg_replace( '/utf8mb4_(?:0900_ai_ci|uca1400_ai_ci)/', 'utf8mb4_unicode_ci', $sql );
}

$failures = array();
$checked  = 0;
foreach ( glob( __DIR__ . '/show-create/*.json' ) as $file ) {
	$source = basename( $file, '.json' );
	$shown  = json_decode( (string) file_get_contents( $file ), true );
	$schema = 'wpccr_' . bin2hex( random_bytes( 3 ) );
	cr_run( 'CREATE DATABASE `' . $schema . '`' );
	mysqli_select_db( $db, $schema );
	try {
		cr_run( 'SET FOREIGN_KEY_CHECKS=0' );
		// The live site: the same tables, created from the backup's own definitions.
		foreach ( array( 'wp_parent', 'wp_child;x' ) as $table ) {
			cr_run( cr_portable( $shown[ $table ] ) );
		}
		cr_run( "INSERT INTO wp_parent (id, code) VALUES (1, 'a'), (2, 'b')" );

		$temporary = array(
			'wp_parent'  => 'wcptmpabcdef_7_1a2b_parent',
			'wp_child;x' => 'wcptmpabcdef_7_1a2b_child_x_1c2d3e4',
		);
		$names     = new ConstraintNames( '1a2b' );
		$intended  = array();
		$number    = 0;
		foreach ( $temporary as $table => $temp ) {
			$buffer  = cr_portable( $shown[ $table ] ) . ';';
			$create  = CreateTable::read( $buffer, SqlLexer::tokens( $buffer, 0, true ), $table );
			$rewrite = $create->rewrite( $temp, $table, $number++, $names, $temporary );
			cr_run( $rewrite['sql'] );
			foreach ( $rewrite['constraints'] as $constraint ) {
				if ( 'foreign' === $constraint['kind'] ) {
					$intended[ $constraint['intended'] ] = $constraint['name'];
				}
			}
		}
		cr_run( "INSERT INTO `wcptmpabcdef_7_1a2b_parent` (id, code) VALUES (10, 'x'), (11, 'y')" );
		cr_run( 'RENAME TABLE `wp_parent` TO `wcpold1a2b_parent`, `wp_child;x` TO `wcpold1a2b_child_x`, `wcptmpabcdef_7_1a2b_parent` TO `wp_parent`, `wcptmpabcdef_7_1a2b_child_x_1c2d3e4` TO `wp_child;x`' );

		// The new child enforces against the new parent: a row whose parent is only in the new table goes in, one only in the old does not.
		cr_run( 'SET FOREIGN_KEY_CHECKS=1' );
		cr_run( "INSERT INTO `wp_child;x` (id, parent_id, parent_code, qty, pt) VALUES (1, 10, 'y', 1, POINT(0, 0))" );
		if ( false !== mysqli_query( $db, "INSERT INTO `wp_child;x` (id, parent_id, qty, pt) VALUES (2, 1, 1, POINT(0, 0))" ) ) {
			throw new RuntimeException( 'the new child accepted a parent id that exists only in the old parent' );
		}
		foreach ( cr_keys( 'wp_child;x' ) as $name => $key ) {
			if ( 'wp_parent' !== $key[0] ) {
				throw new RuntimeException( 'after the swap, key ' . $name . ' references ' . $key[0] );
			}
		}

		// The cleanup: old tables child first, then every foreign key to the name it is meant to have.
		cr_run( 'DROP TABLE `wcpold1a2b_child_x`, `wcpold1a2b_parent`' );
		cr_run( 'SET FOREIGN_KEY_CHECKS=0' );
		foreach ( cr_keys( 'wp_child;x' ) as $name => $key ) {
			$goal = array_search( $name, $intended, true );
			if ( false === $goal ) {
				$goal = null;
				foreach ( $intended as $want => $given ) {
					if ( $want === $name ) {
						$goal = $want; // The server already renamed it with the table.
					}
				}
				if ( null === $goal ) {
					throw new RuntimeException( 'after the swap, key ' . $name . ' is neither the name given nor the one intended' );
				}
			}
			if ( $goal !== $name ) {
				$columns = implode( ', ', array_map( array( SqlWriter::class, 'identifier' ), explode( ',', $key[1] ) ) );
				$refs    = implode( ', ', array_map( array( SqlWriter::class, 'identifier' ), explode( ',', $key[2] ) ) );
				cr_run( 'ALTER TABLE `wp_child;x` DROP FOREIGN KEY ' . SqlWriter::identifier( $name ) . ', ADD CONSTRAINT ' . SqlWriter::identifier( (string) $goal ) . ' FOREIGN KEY (' . $columns . ') REFERENCES `wp_parent` (' . $refs . ')' );
			}
		}
		$final = array_keys( cr_keys( 'wp_child;x' ) );
		sort( $final );
		$want = array_keys( $intended );
		sort( $want );
		if ( $final !== $want ) {
			throw new RuntimeException( 'after the cleanup the keys are ' . implode( ', ', $final ) . ', meant to be ' . implode( ', ', $want ) );
		}
		++$checked;
	} catch ( Throwable $e ) {
		$failures[] = $source . ': ' . $e->getMessage();
	} finally {
		mysqli_query( $db, 'DROP DATABASE `' . $schema . '`' );
	}
}

if ( array() !== $failures ) {
	fwrite( STDERR, 'Server ' . $version . ": the restore's constraint names do not work for backups from\n" . implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo 'Server ', $version, ': backups from ', $checked, " servers import, swap and clean up with their constraint names.\n";
