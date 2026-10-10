<?php
/**
 * Which collation to write when the server does not know the backup's.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * The rule table of the restore's collation check: for a collation the target server does not know, the names to try
 * instead, in order; the first the server knows is written (CreateTable::rewrite()). One table for both directions
 * (a MySQL 8 backup on MariaDB, a MariaDB backup on MySQL). A row is in the table only once it was measured on every
 * supported server (tests/Fixtures/Restore/collation-matrix.php, judged by CollationRulesMatrixTest): no new equalities
 * between sample strings, the lost ones recorded, and the collation's sensitivity kept (case for _ci, accents for _ai,
 * neither for _as and _cs). A name
 * the server knows is never replaced; a name without a row, or whose candidates it does not know either, has no
 * replacement, and the restore stops before it creates anything (RestorePreflightStep).
 *
 * Pure PHP.
 */
final class CollationRules {

	/**
	 * Source collation (lowercase) => candidates in order.
	 *
	 * Every row was measured on MySQL 5.7, 8.0 and 8.4 and MariaDB 10.6, 10.11, 11.4, 11.8 and 12.3 (CI's
	 * database-servers job, tests/Fixtures/Restore/collation-matrix.php): on the sample pairs no candidate makes
	 * two strings equal that the source keeps apart, and the only equalities a candidate loses are the ones of
	 * trailing spaces, when a PAD SPACE MariaDB collation (utf8mb4_uca1400_as_ci, _as_cs) is written as MySQL's NO PAD
	 * one (there is no PAD SPACE accent-sensitive collation on MySQL); those are recorded in
	 * collation-matrix.expected.php, and CollationRulesMatrixTest refuses any other loss. MariaDB 11.4.5 and later know
	 * the 0900 names themselves (as aliases), and are never asked for a replacement. Language-specific names
	 * (utf8mb4_ja_0900_as_cs, utf8mb4_de_pb_0900_ai_ci) have no row: their equalities differ from every candidate.
	 *
	 * MySQL 8 to MariaDB: the NO PAD UCA 14.0.0 collation (MariaDB 10.10 and later), else the NO PAD UCA 5.2.0 one
	 * (10.6; MySQL 5.7 has no NO PAD collation at all, so nothing maps there). MariaDB to MySQL and to MariaDB 10.6:
	 * the PAD SPACE UCA 5.2.0 one for utf8mb4_uca1400_ai_ci (every supported server has it), MySQL's 0900 names for
	 * the NO PAD and the accent-sensitive ones, the NO PAD UCA 5.2.0 one on MariaDB 10.6.
	 */
	const CANDIDATES = array(
		'utf8mb4_0900_ai_ci'          => array( 'utf8mb4_uca1400_nopad_ai_ci', 'utf8mb4_unicode_520_nopad_ci' ),
		'utf8mb4_0900_as_ci'          => array( 'utf8mb4_uca1400_nopad_as_ci' ),
		'utf8mb4_0900_as_cs'          => array( 'utf8mb4_uca1400_nopad_as_cs' ),
		'utf8mb4_0900_bin'            => array( 'utf8mb4_nopad_bin' ),
		'utf8mb4_uca1400_ai_ci'       => array( 'utf8mb4_unicode_520_ci' ),
		'utf8mb4_uca1400_nopad_ai_ci' => array( 'utf8mb4_0900_ai_ci', 'utf8mb4_unicode_520_nopad_ci' ),
		'utf8mb4_uca1400_as_ci'       => array( 'utf8mb4_0900_as_ci' ),
		'utf8mb4_uca1400_nopad_as_ci' => array( 'utf8mb4_0900_as_ci' ),
		'utf8mb4_uca1400_as_cs'       => array( 'utf8mb4_0900_as_cs' ),
		'utf8mb4_uca1400_nopad_as_cs' => array( 'utf8mb4_0900_as_cs' ),
	);

	/**
	 * The name to write for $name on a server that knows $known: $name itself when the server knows it, else the first
	 * candidate it knows, else null (no replacement).
	 *
	 * @param string              $name  The backup's collation, any case.
	 * @param array<string, bool> $known The server's collations, lowercase names as keys.
	 * @return string|null
	 */
	public static function resolve( string $name, array $known ) {
		$lower = strtolower( $name );
		if ( isset( $known[ $lower ] ) ) {
			return $name;
		}
		foreach ( self::CANDIDATES[ $lower ] ?? array() as $candidate ) {
			if ( isset( $known[ $candidate ] ) ) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * $names as a set the way resolve() takes it: lowercase names as keys.
	 *
	 * @param array<int, mixed> $names Names, as the server lists them.
	 * @return array<string, bool>
	 */
	public static function set( array $names ): array {
		$known = array();
		foreach ( $names as $name ) {
			$known[ strtolower( (string) $name ) ] = true;
		}
		return $known;
	}
}
