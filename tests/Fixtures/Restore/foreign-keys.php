<?php
/**
 * What the database server does with foreign keys when tables are imported
 * under temporary names and swapped in with one RENAME TABLE, the way a
 * restore does (T042). The restore's rules for foreign keys rest on these
 * observations, and servers change: CI runs this against every server it
 * supports and compares with foreign-keys.expected.php.
 *
 * Usage: php foreign-keys.php <host> <port> <user> <password> [--check]
 *
 * Prints the observations as JSON. With --check, compares them with the
 * expectations for the server's group and exits 1 on any difference (or on
 * a server no group covers), naming each one.
 *
 * Each case starts from a live parent and child with a foreign key and
 * imports the backup's version under temporary names with
 * FOREIGN_KEY_CHECKS=0 (as the database chunks do). Which parent a key
 * enforces is observed, not read from information_schema: with checks on,
 * a child row whose parent id exists only in the new parent (11) and one
 * whose parent id exists only in the old one (2) are inserted.
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
$schema = 'wpcfk_' . bin2hex( random_bytes( 3 ) );
mysqli_query( $db, 'CREATE DATABASE `' . $schema . '`' );
mysqli_select_db( $db, $schema );

/**
 * Run a statement: "ok" or "E<errno>".
 *
 * @param string $sql SQL.
 * @return string
 */
function fk_run( string $sql ): string {
	global $db;
	return false !== mysqli_query( $db, $sql ) ? 'ok' : 'E' . mysqli_errno( $db );
}

/**
 * The foreign keys of a table as the server records them: constraint => referenced table.
 *
 * @param string $table Table.
 * @return array<string, string>
 */
function fk_refs( string $table ): array {
	global $db, $schema;
	$out    = array();
	$result = mysqli_query( $db, "SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = '" . $schema . "' AND TABLE_NAME = '" . $table . "' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME" );
	while ( $result && ( $row = mysqli_fetch_row( $result ) ) ) {
		$out[ $row[0] ] = $row[1];
	}
	return $out;
}

/**
 * SHOW CREATE TABLE's constraint lines.
 *
 * @param string $table Table.
 * @return string[]
 */
function fk_shown( string $table ): array {
	global $db;
	$result = mysqli_query( $db, 'SHOW CREATE TABLE `' . $table . '`' );
	$row    = $result ? mysqli_fetch_row( $result ) : null;
	preg_match_all( '/^\s*CONSTRAINT .*$/m', (string) ( $row[1] ?? '' ), $lines );
	return array_map( 'trim', $lines[0] );
}

/**
 * Which parent ids a child accepts with checks on.
 *
 * @param string $child Child table (columns id, parent_id).
 * @return array{only_in_new_parent: string, only_in_old_parent: string}
 */
function fk_enforced( string $child ): array {
	fk_run( 'SET FOREIGN_KEY_CHECKS=1' );
	$out = array(
		'only_in_new_parent' => fk_run( 'INSERT INTO `' . $child . '` (id, parent_id) VALUES (900, 11)' ),
		'only_in_old_parent' => fk_run( 'INSERT INTO `' . $child . '` (id, parent_id) VALUES (901, 2)' ),
	);
	fk_run( 'DELETE FROM `' . $child . '` WHERE id IN (900, 901)' );
	return $out;
}

/**
 * Drop every table of the scratch database; checks off for the rest of the case.
 *
 * @return void
 */
function fk_reset(): void {
	global $db, $schema;
	fk_run( 'SET FOREIGN_KEY_CHECKS=0' );
	$result = mysqli_query( $db, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . $schema . "'" );
	while ( $result && ( $row = mysqli_fetch_row( $result ) ) ) {
		fk_run( 'DROP TABLE `' . $row[0] . '`' );
	}
}

/**
 * The live pair: wp_parent (ids 1-3) and wp_child with fk_child_parent; then the backup's parent as wcptmp_parent (ids 10-12).
 *
 * @return void
 */
function fk_live_and_temporary_parent(): void {
	fk_reset();
	fk_run( 'CREATE TABLE wp_parent (id INT PRIMARY KEY) ENGINE=InnoDB' );
	fk_run( 'CREATE TABLE wp_child (id INT PRIMARY KEY, parent_id INT, CONSTRAINT fk_child_parent FOREIGN KEY (parent_id) REFERENCES wp_parent (id)) ENGINE=InnoDB' );
	fk_run( 'INSERT INTO wp_parent VALUES (1), (2), (3)' );
	fk_run( 'INSERT INTO wp_child VALUES (1, 1), (2, 2)' );
	fk_run( 'CREATE TABLE wcptmp_parent (id INT PRIMARY KEY) ENGINE=InnoDB' );
	fk_run( 'INSERT INTO wcptmp_parent VALUES (10), (11), (12)' );
}

/**
 * Create the backup's child under its temporary name.
 *
 * @param string $constraint "CONSTRAINT `x` " or "".
 * @param string $target     Referenced table.
 * @return string
 */
function fk_temporary_child( string $constraint, string $target ): string {
	return fk_run( 'CREATE TABLE wcptmp_child (id INT PRIMARY KEY, parent_id INT, ' . $constraint . 'FOREIGN KEY (parent_id) REFERENCES `' . $target . '` (id)) ENGINE=InnoDB' );
}

/**
 * Every table of the scratch database.
 *
 * @return string[]
 */
function fk_tables(): array {
	global $db, $schema;
	$out    = array();
	$result = mysqli_query( $db, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . $schema . "' ORDER BY TABLE_NAME" );
	while ( $result && ( $row = mysqli_fetch_row( $result ) ) ) {
		$out[] = $row[0];
	}
	return $out;
}

const FK_SWAP = 'RENAME TABLE wp_parent TO wcpold_parent, wp_child TO wcpold_child, wcptmp_parent TO wp_parent, wcptmp_child TO wp_child';
const FK_UNDO = 'RENAME TABLE wp_parent TO wcptmp_parent, wp_child TO wcptmp_child, wcpold_parent TO wp_parent, wcpold_child TO wp_child';

$version = (string) mysqli_fetch_row( mysqli_query( $db, 'SELECT VERSION()' ) )[0];
$cases   = array();

// 1: the temporary child references the original name, or the temporary parent; the swap and its undo.
foreach ( array( 'original_name' => 'wp_parent', 'rewritten_to_temporary' => 'wcptmp_parent' ) as $label => $target ) {
	fk_live_and_temporary_parent();
	$case                          = array( 'create' => fk_temporary_child( 'CONSTRAINT fk_tmp_child ', $target ) );
	$case['before_swap']           = fk_refs( 'wcptmp_child' );
	$case['swap']                  = fk_run( FK_SWAP );
	$case['after_swap_new_child']  = fk_refs( 'wp_child' );
	$case['after_swap_old_child']  = fk_refs( 'wcpold_child' );
	$case['new_child_accepts']     = fk_enforced( 'wp_child' );
	$case['undo']                  = fk_run( FK_UNDO );
	$case['after_undo_live_child'] = fk_refs( 'wp_child' );
	$case['after_undo_temporary']  = fk_refs( 'wcptmp_child' );
	$case['live_child_accepts']    = fk_enforced( 'wp_child' );

	$cases[ '1_child_references_' . $label ] = $case;
}

// 3: constraint names. The live child's own name; names the server generates; what SHOW CREATE TABLE writes into a backup.
fk_live_and_temporary_parent();
$cases['3a_same_name_as_live'] = array( 'create' => fk_temporary_child( 'CONSTRAINT fk_child_parent ', 'wcptmp_parent' ) );

fk_reset();
fk_run( 'CREATE TABLE wp_parent (id INT PRIMARY KEY) ENGINE=InnoDB' );
fk_run( 'CREATE TABLE wp_child (id INT PRIMARY KEY, parent_id INT, FOREIGN KEY (parent_id) REFERENCES wp_parent (id)) ENGINE=InnoDB' );
fk_run( 'CREATE TABLE wcptmp_parent (id INT PRIMARY KEY) ENGINE=InnoDB' );
$shown = fk_shown( 'wp_child' );
$case  = array(
	'live_child_shown'       => $shown,
	'create_with_shown_line' => fk_run( 'CREATE TABLE wcptmp_child (id INT PRIMARY KEY, parent_id INT, ' . str_replace( '`wp_parent`', '`wcptmp_parent`', (string) ( $shown[0] ?? '' ) ) . ') ENGINE=InnoDB' ),
);
fk_run( 'DROP TABLE IF EXISTS wcptmp_child' );
$case['create_unnamed']          = fk_temporary_child( '', 'wcptmp_parent' );
$case['unnamed_temporary_child'] = fk_refs( 'wcptmp_child' );
$case['swap']                    = fk_run( FK_SWAP );
$case['unnamed_after_swap_new']  = fk_refs( 'wp_child' );
$case['unnamed_after_swap_old']  = fk_refs( 'wcpold_child' );
$cases['3b_generated_names']     = $case;

// 3c: a name in the generated form of the temporary table, written explicitly: does the swap rename it?
fk_live_and_temporary_parent();
$cases['3c_explicit_generated_form'] = array(
	'create'         => fk_temporary_child( 'CONSTRAINT `wcptmp_child_ibfk_1` ', 'wcptmp_parent' ),
	'swap'           => fk_run( FK_SWAP ),
	'after_swap_new' => fk_shown( 'wp_child' ),
	'after_swap_old' => fk_shown( 'wcpold_child' ),
);

// 3d: any other name given a temporary name: what the table keeps, and when the original name can come back.
fk_live_and_temporary_parent();
fk_temporary_child( 'CONSTRAINT `wcp1a2b_fk_child_parent` ', 'wcptmp_parent' );
$case                                 = array(
	'swap'           => fk_run( FK_SWAP ),
	'after_swap_new' => fk_shown( 'wp_child' ),
);
$case['rename_back_while_old_exists'] = fk_run( 'ALTER TABLE wp_child DROP FOREIGN KEY `wcp1a2b_fk_child_parent`, ADD CONSTRAINT `fk_child_parent` FOREIGN KEY (parent_id) REFERENCES wp_parent (id)' );
$case['after_first_attempt']          = fk_shown( 'wp_child' );
fk_run( 'SET FOREIGN_KEY_CHECKS=1' );
$case['drop_old'] = fk_run( 'DROP TABLE wcpold_child, wcpold_parent' );
fk_run( 'SET FOREIGN_KEY_CHECKS=0' );
$current = fk_shown( 'wp_child' );
preg_match( '/CONSTRAINT `([^`]+)`/', (string) ( $current[0] ?? '' ), $name );
$case['rename_back_after_old_dropped'] = fk_run( 'ALTER TABLE wp_child DROP FOREIGN KEY `' . ( $name[1] ?? '' ) . '`, ADD CONSTRAINT `fk_child_parent` FOREIGN KEY (parent_id) REFERENCES wp_parent (id)' );
$case['after_rename_back']             = fk_shown( 'wp_child' );
$cases['3d_temporary_name']            = $case;

// 3e: two tables of one backup with the same constraint name (a MariaDB 12 backup's "1").
fk_reset();
fk_run( 'CREATE TABLE p (id INT PRIMARY KEY) ENGINE=InnoDB' );
$cases['3e_same_name_in_two_tables'] = array(
	'first'  => fk_run( 'CREATE TABLE a (id INT PRIMARY KEY, p INT, CONSTRAINT `1` FOREIGN KEY (p) REFERENCES p (id)) ENGINE=InnoDB' ),
	'second' => fk_run( 'CREATE TABLE b (id INT PRIMARY KEY, p INT, CONSTRAINT `1` FOREIGN KEY (p) REFERENCES p (id)) ENGINE=InnoDB' ),
);

// 4: a table referencing itself.
foreach ( array( 'original_name' => 'wp_node', 'rewritten_to_temporary' => 'wcptmp_node' ) as $label => $target ) {
	fk_reset();
	fk_run( 'CREATE TABLE wp_node (id INT PRIMARY KEY, parent_id INT NULL, CONSTRAINT fk_node_live FOREIGN KEY (parent_id) REFERENCES wp_node (id)) ENGINE=InnoDB' );
	fk_run( 'INSERT INTO wp_node VALUES (1, NULL), (2, 1), (3, 1)' );
	$case = array( 'create' => fk_run( 'CREATE TABLE wcptmp_node (id INT PRIMARY KEY, parent_id INT NULL, CONSTRAINT fk_node_tmp FOREIGN KEY (parent_id) REFERENCES `' . $target . '` (id)) ENGINE=InnoDB' ) );
	fk_run( 'INSERT INTO wcptmp_node VALUES (10, NULL), (11, 10), (12, 10)' );
	$case['swap']           = fk_run( 'RENAME TABLE wp_node TO wcpold_node, wcptmp_node TO wp_node' );
	$case['after_swap_new'] = fk_refs( 'wp_node' );
	$case['new_accepts']    = fk_enforced( 'wp_node' );
	$case['drop_old']       = fk_run( 'DROP TABLE wcpold_node' );

	$cases[ '4_self_reference_' . $label ] = $case;
}

// 5: two tables referencing each other, the first created before the second exists.
foreach ( array( 'original_names' => array( 'wp_a', 'wp_b' ), 'rewritten_to_temporary' => array( 'wcptmp_a', 'wcptmp_b' ) ) as $label => $targets ) {
	fk_reset();
	fk_run( 'CREATE TABLE wp_a (id INT PRIMARY KEY, b_id INT NULL, CONSTRAINT fk_a_live FOREIGN KEY (b_id) REFERENCES wp_b (id)) ENGINE=InnoDB' );
	fk_run( 'CREATE TABLE wp_b (id INT PRIMARY KEY, a_id INT NULL, CONSTRAINT fk_b_live FOREIGN KEY (a_id) REFERENCES wp_a (id)) ENGINE=InnoDB' );

	$cases[ '5_circular_' . $label ] = array(
		'create_a_before_b' => fk_run( 'CREATE TABLE wcptmp_a (id INT PRIMARY KEY, b_id INT NULL, CONSTRAINT fk_a_tmp FOREIGN KEY (b_id) REFERENCES `' . $targets[1] . '` (id)) ENGINE=InnoDB' ),
		'create_b'          => fk_run( 'CREATE TABLE wcptmp_b (id INT PRIMARY KEY, a_id INT NULL, CONSTRAINT fk_b_tmp FOREIGN KEY (a_id) REFERENCES `' . $targets[0] . '` (id)) ENGINE=InnoDB' ),
		'swap'              => fk_run( 'RENAME TABLE wp_a TO wcpold_a, wp_b TO wcpold_b, wcptmp_a TO wp_a, wcptmp_b TO wp_b' ),
		'after_swap_new_a'  => fk_refs( 'wp_a' ),
		'after_swap_new_b'  => fk_refs( 'wp_b' ),
		'after_swap_old_a'  => fk_refs( 'wcpold_a' ),
	);
}

// 6: one swap statement over linked tables, one of whose renames fails.
fk_live_and_temporary_parent();
fk_temporary_child( 'CONSTRAINT fk_tmp_child ', 'wcptmp_parent' );
fk_run( 'CREATE TABLE wcpold_child (id INT PRIMARY KEY) ENGINE=InnoDB' );
$cases['6_swap_with_one_failing_rename'] = array(
	'swap'         => fk_run( FK_SWAP ),
	'tables_after' => fk_tables(),
);

// 8: dropping the old tables with checks on: the parent alone, the set in either order; a dangling key with checks off.
foreach ( array( 'parent_first' => 'wcpold_parent, wcpold_child', 'child_first' => 'wcpold_child, wcpold_parent' ) as $label => $list ) {
	fk_live_and_temporary_parent();
	fk_temporary_child( 'CONSTRAINT fk_tmp_child ', 'wcptmp_parent' );
	fk_run( FK_SWAP );
	fk_run( 'SET FOREIGN_KEY_CHECKS=1' );

	$cases[ '8a_drop_old_set_' . $label ] = array(
		'parent_alone' => fk_run( 'DROP TABLE wcpold_parent' ),
		'set'          => fk_run( 'DROP TABLE ' . $list ),
	);
}
fk_live_and_temporary_parent();
fk_temporary_child( 'CONSTRAINT fk_tmp_child ', 'wp_parent' );
fk_run( FK_SWAP );
$cases['8b_unrewritten_key_old_dropped_with_checks_off'] = array(
	'drop'              => fk_run( 'DROP TABLE wcpold_parent, wcpold_child' ),
	'new_child_refs'    => fk_refs( 'wp_child' ),
	'new_child_accepts' => fk_enforced( 'wp_child' ),
);

// 9: a live table outside the restore referencing a restored table; restored tables referencing tables outside it.
fk_live_and_temporary_parent();
fk_run( 'CREATE TABLE wp_kept (id INT PRIMARY KEY, parent_id INT, CONSTRAINT fk_kept FOREIGN KEY (parent_id) REFERENCES wp_parent (id)) ENGINE=InnoDB' );
fk_temporary_child( 'CONSTRAINT fk_tmp_child ', 'wcptmp_parent' );
$cases['9a_kept_table_references_restored_table'] = array(
	'swap'         => fk_run( FK_SWAP ),
	'kept_refs'    => fk_refs( 'wp_kept' ),
	'kept_accepts' => fk_enforced( 'wp_kept' ),
	'drop_old_set' => fk_run( 'DROP TABLE wcpold_child, wcpold_parent' ),
);
fk_reset();
fk_run( 'CREATE TABLE wp_parent (id INT PRIMARY KEY) ENGINE=InnoDB' );
fk_run( 'INSERT INTO wp_parent VALUES (1), (2), (3)' );
$cases['9b_restored_table_references_live_only_table'] = array(
	'create'  => fk_temporary_child( 'CONSTRAINT fk_tmp_child ', 'wp_parent' ),
	'swap'    => fk_run( 'RENAME TABLE wcptmp_child TO wp_child' ),
	'refs'    => fk_refs( 'wp_child' ),
	'accepts' => fk_enforced( 'wp_child' ),
);
fk_reset();
$case = array(
	'create_checks_off' => fk_temporary_child( 'CONSTRAINT fk_tmp_child ', 'wp_missing' ),
	'insert_checks_off' => fk_run( 'INSERT INTO wcptmp_child VALUES (1, 1)' ),
);
fk_run( 'SET FOREIGN_KEY_CHECKS=1' );
$case['insert_checks_on'] = fk_run( 'INSERT INTO wcptmp_child VALUES (2, 1)' );
fk_run( 'DROP TABLE wcptmp_child' );
$case['create_checks_on'] = fk_temporary_child( 'CONSTRAINT fk_tmp_child ', 'wp_missing' );

$cases['9c_restored_table_references_missing_table'] = $case;

// 10: name lengths. A generated name is the table's name + "_ibfk_N"; any name is an identifier of at most 64 bytes.
fk_reset();
fk_run( 'CREATE TABLE p (id INT PRIMARY KEY) ENGINE=InnoDB' );
$lengths = array();
foreach ( array( 56, 57, 58 ) as $n ) {
	$table                         = str_pad( 't', $n, 'x' );
	$lengths[ 'unnamed_on_' . $n ] = fk_run( 'CREATE TABLE `' . $table . '` (id INT PRIMARY KEY, p INT, FOREIGN KEY (p) REFERENCES p (id)) ENGINE=InnoDB' );
	fk_run( 'DROP TABLE IF EXISTS `' . $table . '`' );
}
foreach ( array( 57, 58 ) as $n ) {
	$table                                = str_pad( 'g', $n, 'w' );
	$lengths[ 'generated_form_on_' . $n ] = fk_run( 'CREATE TABLE `' . $table . '` (id INT PRIMARY KEY, p INT, CONSTRAINT `' . $table . '_ibfk_1` FOREIGN KEY (p) REFERENCES p (id)) ENGINE=InnoDB' );
	fk_run( 'DROP TABLE IF EXISTS `' . $table . '`' );
}
$lengths['named_64'] = fk_run( 'CREATE TABLE named (id INT PRIMARY KEY, p INT, CONSTRAINT `' . str_pad( 'c', 64, 'y' ) . '` FOREIGN KEY (p) REFERENCES p (id)) ENGINE=InnoDB' );
$lengths['named_65'] = fk_run( 'CREATE TABLE named2 (id INT PRIMARY KEY, p INT, CONSTRAINT `' . str_pad( 'c', 65, 'y' ) . '` FOREIGN KEY (p) REFERENCES p (id)) ENGINE=InnoDB' );
foreach ( array( 57, 58, 64 ) as $n ) {
	fk_run( 'CREATE TABLE s (id INT PRIMARY KEY, p INT, CONSTRAINT `s_ibfk_1` FOREIGN KEY (p) REFERENCES p (id)) ENGINE=InnoDB' );
	$table = str_pad( 'r', $n, 'z' );

	$lengths[ 'rename_generated_to_' . $n ]         = fk_run( 'RENAME TABLE s TO `' . $table . '`' );
	$lengths[ 'rename_generated_to_' . $n . '_has' ] = array_keys( fk_refs( $table ) + fk_refs( 's' ) );
	fk_run( 'DROP TABLE IF EXISTS `' . $table . '`' );
	fk_run( 'DROP TABLE IF EXISTS s' );
}
$cases['10_name_lengths'] = $lengths;

fk_run( 'DROP DATABASE `' . $schema . '`' );

/**
 * One level of keys per case, case.field => value, non-strings as JSON.
 *
 * @param array<string, array<string, mixed>> $cases Cases.
 * @return array<string, string>
 */
function fk_flat( array $cases ): array {
	$out = array();
	foreach ( $cases as $case => $fields ) {
		foreach ( $fields as $field => $value ) {
			$out[ $case . '.' . $field ] = is_string( $value ) ? $value : (string) json_encode( $value, JSON_UNESCAPED_SLASHES );
		}
	}
	return $out;
}

/**
 * The expectations group of a server version.
 *
 * @param string $version VERSION().
 * @return string
 */
function fk_group( string $version ): string {
	if ( false !== stripos( $version, 'mariadb' ) ) {
		return (int) $version >= 12 ? 'mariadb-12' : 'mariadb-10-11';
	}
	if ( 0 === strpos( $version, '5.7.' ) ) {
		return 'mysql-5.7';
	}
	return 0 === strpos( $version, '8.' ) ? 'mysql-8' : '';
}

$observed = fk_flat( $cases );
if ( ! in_array( '--check', $argv, true ) ) {
	echo json_encode(
		array(
			'server' => $version,
			'group'  => fk_group( $version ),
			'cases'  => $observed,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	), "\n";
	exit( 0 );
}
$expected = require __DIR__ . '/foreign-keys.expected.php';
$group    = fk_group( $version );
if ( ! isset( $expected[ $group ] ) ) {
	fwrite( STDERR, 'No expectations for server ' . $version . ": measure it and add a group.\n" );
	exit( 1 );
}
$differences = array();
foreach ( array_keys( $expected[ $group ] + $observed ) as $key ) {
	$want = $expected[ $group ][ $key ] ?? '(not expected)';
	$got  = $observed[ $key ] ?? '(not observed)';
	if ( $want !== $got ) {
		$differences[] = $key . "\n    expected " . $want . "\n    observed " . $got;
	}
}
if ( array() !== $differences ) {
	fwrite( STDERR, 'Server ' . $version . ' (' . $group . ') behaves differently from what the restore relies on:' . "\n" . implode( "\n", $differences ) . "\n" );
	exit( 1 );
}
echo 'Server ', $version, ' (', $group, '): ', count( $observed ), " observations as expected.\n";
