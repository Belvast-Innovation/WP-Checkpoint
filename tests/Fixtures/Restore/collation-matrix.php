<?php
/**
 * What the restore's collation check rests on, measured on a real server (CI runs it on every supported one):
 *
 * - probe: which of the two queries the check reads the server's collations with answers
 *   (RestorePreflightStep::COLLATIONS_SQL, else COLLATIONS_SQL_FALLBACK), and whether each name of interest is
 *   listed by it, and whether a table can be created with it (1273 when not). A name created but not listed, or
 *   listed but not created, is a server quirk the expectations record.
 * - eq: for every collation of interest the server creates tables with, whether each pair of sample strings compares
 *   equal under it (1), not (0). The rule table (CollationRules) is judged on these vectors by
 *   tests/unit/Restore/CollationRulesMatrixTest.php: a candidate may add no equality the source lacks, may lose only
 *   the equalities recorded as known differences, and must keep the source's sensitivity (the "ci" pairs equal under
 *   a _ci source, the "ai" pairs equal under an _ai one, each unequal under _cs and _as).
 *
 * Usage: php collation-matrix.php <host> <port> <user> <password> [--check]
 * Without --check the observations are printed as JSON, to be put into collation-matrix.expected.php under the
 * server's group; with --check they are compared with that file.
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

use WPCheckpoint\Jobs\RestorePreflightStep;
use WPCheckpoint\Restore\CollationRules;

mysqli_report( MYSQLI_REPORT_OFF );
$db = mysqli_init();
if ( ! $db || ! mysqli_real_connect( $db, $argv[1] ?? '127.0.0.1', $argv[3] ?? 'root', $argv[4] ?? '', '', (int) ( $argv[2] ?? 3306 ) ) ) {
	fwrite( STDERR, 'Cannot connect (' . mysqli_connect_errno() . ")\n" );
	exit( 2 );
}
mysqli_set_charset( $db, 'utf8mb4' );
$schema = 'wpccm_' . bin2hex( random_bytes( 3 ) );
mysqli_query( $db, 'CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4' );
mysqli_select_db( $db, $schema );

/**
 * The names of interest: every source and candidate of the rule table, and the names the restore's design measured.
 *
 * @return string[]
 */
function cm_names(): array {
	$names = array(
		'utf8mb4_general_ci',
		'utf8mb4_unicode_ci',
		'utf8mb4_unicode_520_ci',
		'utf8mb4_unicode_520_nopad_ci',
		'utf8mb4_bin',
		'utf8mb4_nopad_bin',
		'utf8mb4_0900_ai_ci',
		'utf8mb4_0900_as_ci',
		'utf8mb4_0900_as_cs',
		'utf8mb4_0900_bin',
		'utf8mb4_de_pb_0900_ai_ci',
		'utf8mb4_ja_0900_as_cs',
		'utf8mb4_uca1400_ai_ci',
		'utf8mb4_uca1400_as_ci',
		'utf8mb4_uca1400_as_cs',
		'utf8mb4_uca1400_nopad_ai_ci',
		'utf8mb4_uca1400_nopad_as_ci',
		'utf8mb4_uca1400_nopad_as_cs',
	);
	foreach ( CollationRules::CANDIDATES as $source => $candidates ) {
		$names[] = $source;
		foreach ( $candidates as $candidate ) {
			$names[] = $candidate;
		}
	}
	return array_values( array_unique( $names ) );
}

/**
 * The sample pairs: id => [left, right, class]. The class says what the pair tells: "ci" differs by case only,
 * "ai" by accent only, "pad" by trailing spaces only, "other" anything else (recorded; judged as new or lost
 * equalities only).
 *
 * @return array<string, array{0: string, 1: string, 2: string}>
 */
function cm_pairs(): array {
	return array(
		'case_ascii'       => array( 'a', 'A', 'ci' ),
		'case_word'        => array( 'WordPress', 'wordpress', 'ci' ),
		'case_accented'    => array( 'é', 'É', 'ci' ),
		'accent_a'         => array( 'a', 'á', 'ai' ),
		'accent_e'         => array( 'e', 'é', 'ai' ),
		'accent_u_umlaut'  => array( 'u', 'ü', 'ai' ),
		'accent_n_tilde'   => array( 'n', 'ñ', 'ai' ),
		'accent_c_cedilla' => array( 'c', 'ç', 'ai' ),
		'pad_one'          => array( 'a', 'a ', 'pad' ),
		'pad_two'          => array( 'ab', 'ab  ', 'pad' ),
		'nfc_nfd'          => array( "\u{E9}", "e\u{301}", 'other' ),
		'eszett'           => array( 'ß', 'ss', 'other' ),
		'ae_ligature'      => array( 'æ', 'ae', 'other' ),
		'oe_slash'         => array( 'ø', 'o', 'other' ),
		'turkish_dotted'   => array( 'i', 'İ', 'other' ),
		'turkish_dotless'  => array( 'ı', 'I', 'other' ),
		'greek_sigma'      => array( 'σ', 'ς', 'other' ),
		'kana_width'       => array( 'ｱ', 'ア', 'other' ),
		'kana_kind'        => array( 'あ', 'ア', 'other' ),
		'cjk_two'          => array( '中', '國', 'other' ),
		'emoji_two'        => array( '😀', '😁', 'other' ),
		'emoji_skin'       => array( '👍', '👍🏽', 'other' ),
		'digits'           => array( '1', '１', 'other' ),
		'control'          => array( 'a', 'b', 'other' ),
	);
}

/**
 * Run a statement: ok, or E and the error number.
 *
 * @param mysqli $db  Connection.
 * @param string $sql SQL.
 * @return string
 */
function cm_run( mysqli $db, string $sql ): string {
	return false !== mysqli_query( $db, $sql ) ? 'ok' : 'E' . mysqli_errno( $db );
}

/**
 * The expectations group of a server version: the behaviours differ between MariaDB 10.6 (no UCA 14), 10.11 (UCA
 * 14), 11.4 and later (the 0900 names as aliases) and 12; and between MySQL 5.7 (no 0900, no NO PAD) and 8.
 *
 * @param string $version Server version.
 * @return string
 */
function cm_group( string $version ): string {
	if ( false !== stripos( $version, 'mariadb' ) ) {
		$major = (int) $version;
		$minor = (int) explode( '.', $version )[1];
		if ( $major >= 12 ) {
			return 'mariadb-12';
		}
		if ( 11 === $major ) {
			return 'mariadb-11';
		}
		return $minor >= 10 ? 'mariadb-10.11' : 'mariadb-10.6';
	}
	if ( 0 === strpos( $version, '5.7.' ) ) {
		return 'mysql-5.7';
	}
	return 0 === strpos( $version, '8.' ) ? 'mysql-8' : '';
}

$version = (string) mysqli_get_server_info( $db );
$cases   = array();

// The probe, as the check runs it.
$listed = array();
$probe  = '';
foreach ( array( 'full' => RestorePreflightStep::COLLATIONS_SQL, 'fallback' => RestorePreflightStep::COLLATIONS_SQL_FALLBACK ) as $which => $sql ) {
	$result = mysqli_query( $db, $sql );
	if ( $result ) {
		while ( $row = mysqli_fetch_row( $result ) ) {
			$listed[ strtolower( (string) $row[0] ) ] = true;
		}
		$probe = $which;
		break;
	}
}
$cases['probe'] = array(
	'query' => $probe,
	'count' => count( $listed ),
);
if ( '' === $probe ) {
	fwrite( STDERR, "Neither query of the check answers on this server.\n" );
	mysqli_query( $db, 'DROP DATABASE `' . $schema . '`' );
	exit( 1 );
}

// Each name: listed by the probe, and accepted in CREATE TABLE.
$knows = array();
foreach ( cm_names() as $name ) {
	$created = cm_run( $db, 'CREATE TABLE t (a VARCHAR(10)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=' . $name );
	mysqli_query( $db, 'DROP TABLE IF EXISTS t' );
	$knows[ $name ]           = 'ok' === $created;
	$cases[ 'name.' . $name ] = array(
		'listed'  => isset( $listed[ $name ] ) ? 1 : 0,
		'created' => $created,
	);
}

// The equality vectors, for every name the server creates tables with.
foreach ( cm_names() as $name ) {
	if ( ! $knows[ $name ] ) {
		continue;
	}
	$vector = array();
	foreach ( cm_pairs() as $id => $pair ) {
		$result = mysqli_query( $db, "SELECT _utf8mb4'" . mysqli_real_escape_string( $db, $pair[0] ) . "' COLLATE " . $name . " = _utf8mb4'" . mysqli_real_escape_string( $db, $pair[1] ) . "' COLLATE " . $name );
		$row    = $result ? mysqli_fetch_row( $result ) : null;
		$vector[ $id ] = null === $row ? 'E' . mysqli_errno( $db ) : (string) (int) $row[0];
	}
	$cases[ 'eq.' . $name ] = $vector;
}

mysqli_query( $db, 'DROP DATABASE `' . $schema . '`' );

/**
 * One level of keys per case, case.field => value.
 *
 * @param array<string, array<string, mixed>> $cases Cases.
 * @return array<string, string>
 */
function cm_flat( array $cases ): array {
	$out = array();
	foreach ( $cases as $case => $fields ) {
		foreach ( $fields as $field => $value ) {
			$out[ $case . '.' . $field ] = is_string( $value ) ? $value : (string) json_encode( $value, JSON_UNESCAPED_SLASHES );
		}
	}
	return $out;
}

$observed = cm_flat( $cases );
$group    = cm_group( $version );
if ( ! in_array( '--check', $argv, true ) ) {
	echo json_encode(
		array(
			'server' => $version,
			'group'  => $group,
			'cases'  => $observed,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	), "\n";
	exit( 0 );
}
$expected = require __DIR__ . '/collation-matrix.expected.php';
if ( ! isset( $expected[ $group ] ) ) {
	fwrite( STDERR, 'No expectations for server ' . $version . ' (' . $group . "): measure it and add a group.\n" );
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
	fwrite( STDERR, 'Server ' . $version . ' (' . $group . ') behaves differently from what the collation check relies on:' . "\n" . implode( "\n", $differences ) . "\n" );
	exit( 1 );
}
echo 'Server ', $version, ' (', $group, '): ', count( $observed ), " observations as expected.\n";
