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
 * supported server (tests/Fixtures/Restore/collation-matrix.php): no new equalities between sample strings, the lost
 * ones recorded, and the collation's sensitivity kept (case for _ci, accents for _ai, neither for _as and _cs). A name
 * the server knows is never replaced; a name without a row, or whose candidates it does not know either, has no
 * replacement, and the restore stops before it creates anything (RestorePreflightStep).
 *
 * Pure PHP.
 */
final class CollationRules {

	/**
	 * Source collation (lowercase) => candidates in order.
	 *
	 * Measured 2026-09-29 (MySQL 8 to MariaDB): utf8mb4_0900_ai_ci to the NO PAD UCA 14.0.0 collation (MariaDB
	 * 10.10 and later), else to the NO PAD UCA 5.2.0 one (10.6); utf8mb4_0900_bin to utf8mb4_nopad_bin;
	 * utf8mb4_0900_as_cs to the NO PAD accent- and case-sensitive UCA 14.0.0 one. MariaDB 11.4.5 and later know the
	 * 0900 names themselves (as aliases), and are never asked for a replacement.
	 */
	const CANDIDATES = array(
		'utf8mb4_0900_ai_ci' => array( 'utf8mb4_uca1400_nopad_ai_ci', 'utf8mb4_unicode_520_nopad_ci' ),
		'utf8mb4_0900_bin'   => array( 'utf8mb4_nopad_bin' ),
		'utf8mb4_0900_as_cs' => array( 'utf8mb4_uca1400_nopad_as_cs' ),
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
