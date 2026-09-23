<?php
/**
 * Which tables with the installation's prefix belong to it.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Database;

/**
 * A shared database can hold several WordPress installations whose
 * prefixes overlap: "wp_" and "wp_old_" both match "wp_%". Backing up by
 * prefix alone would put the other installation's tables into this
 * site's backup, and a restore would write them back over the
 * neighbour's data. Leaving out one of this site's own tables is worse:
 * the loss is silent and cannot be undone once the site is gone, while an
 * extra table can still be dealt with at restore time. So a table is left
 * out only when it is certain to be another installation's, and the
 * recognition runs in a fixed order:
 *
 * 1. Protected: this installation's core tables and, on a multisite
 *    network, every table of its sub-sites ("{prefix}{n}_..."). Nothing
 *    later can take them.
 * 2. Another installation: a longer prefix P with the per-site core
 *    tables that every WordPress version since 2.3 creates
 *    (INSTALLATION_MARKERS), none of them protected. "users" is not one of
 *    them: an installation may share this site's users table.
 * 3. Left out: P followed by a WordPress core table name, or by a
 *    sub-site number and a per-site core table name (that installation's
 *    own sub-sites). Nothing else.
 * 4. Kept: every other table under P. It may belong to either
 *    installation ("wp_w" + "c_orders" or "wp_" + "wc_orders"); it stays in
 *    the backup and is named so that the user can leave it out.
 *
 * Every table belongs to at most one P, the shortest that claims it.
 * Pure PHP.
 */
final class TableSelection {

	/**
	 * Per-site core tables whose presence under one prefix means a WordPress installation.
	 */
	const INSTALLATION_MARKERS = array( 'posts', 'postmeta', 'options', 'comments', 'terms', 'term_taxonomy', 'term_relationships' );

	/**
	 * WordPress's per-site core table names.
	 */
	const SITE_TABLES = array( 'posts', 'postmeta', 'comments', 'commentmeta', 'terms', 'termmeta', 'term_taxonomy', 'term_relationships', 'options', 'links' );

	/**
	 * WordPress's global and network core table names.
	 */
	const GLOBAL_TABLES = array( 'users', 'usermeta', 'blogs', 'blogmeta', 'signups', 'site', 'sitemeta', 'registration_log', 'sitecategories' );

	/**
	 * Groups of tables that look like another installation.
	 *
	 * @param string[] $tables    Tables with the prefix (base tables, not views).
	 * @param string   $prefix    This installation's prefix.
	 * @param bool     $multisite Whether this installation is a multisite network.
	 * @param string[] $core      This installation's core tables (never left out).
	 * @return array<string, array{excluded: string[], kept: string[]}> Foreign prefix => the tables left out and the
	 *                                                                  tables under it that stay in; all sorted.
	 */
	public static function foreign( array $tables, string $prefix, bool $multisite, array $core = array() ): array {
		$tables = array_values( array_unique( array_map( 'strval', $tables ) ) );
		sort( $tables, SORT_STRING );

		// 1. Protected, before anything else is judged.
		$protected = array_fill_keys( array_map( 'strval', $core ), true );
		foreach ( $tables as $table ) {
			if ( $multisite && self::is_subsite_table( $table, $prefix ) ) {
				$protected[ $table ] = true;
			}
		}
		$open = array();
		foreach ( $tables as $table ) {
			if ( ! isset( $protected[ $table ] ) && 0 === strpos( $table, $prefix ) ) {
				$open[ $table ] = true;
			}
		}

		// 2. Other installations.
		$candidates = array();
		foreach ( array_keys( $open ) as $table ) {
			$table = (string) $table;
			if ( 'posts' !== substr( $table, -5 ) ) {
				continue;
			}
			$candidate = substr( $table, 0, -5 );
			if ( strlen( $candidate ) <= strlen( $prefix ) ) {
				continue;
			}
			foreach ( self::INSTALLATION_MARKERS as $marker ) {
				if ( ! isset( $open[ $candidate . $marker ] ) ) {
					continue 2;
				}
			}
			$candidates[] = $candidate;
		}
		usort(
			$candidates,
			static function ( string $a, string $b ): int {
				$by_length = strlen( $a ) <=> strlen( $b );
				return 0 !== $by_length ? $by_length : strcmp( $a, $b );
			}
		);

		// 3. Left out: core names only, each table to the shortest prefix that claims it.
		$groups  = array();
		$claimed = array();
		foreach ( $candidates as $candidate ) {
			foreach ( array_keys( $open ) as $table ) {
				$table = (string) $table;
				if ( ! isset( $claimed[ $table ] ) && 0 === strpos( $table, $candidate ) && self::is_core_name( substr( $table, strlen( $candidate ) ) ) ) {
					$claimed[ $table ]                  = true;
					$groups[ $candidate ]['excluded'][] = $table;
				}
			}
		}

		// 4. Kept: the rest under a prefix that left something out.
		$out = array();
		foreach ( $groups as $candidate => $group ) {
			$kept = array();
			foreach ( array_keys( $open ) as $table ) {
				$table = (string) $table;
				if ( isset( $claimed[ $table ] ) || 0 !== strpos( $table, (string) $candidate ) ) {
					continue;
				}
				foreach ( array_keys( $out ) as $shorter ) {
					if ( 0 === strpos( $table, (string) $shorter ) ) {
						continue 2; // Named under the shorter prefix already.
					}
				}
				$kept[] = $table;
			}
			$out[ (string) $candidate ] = array(
				'excluded' => $group['excluded'],
				'kept'     => $kept,
			);
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * Whether a table is one of this network's sub-site tables
	 * ("{base}{n}_..."), by WordPress's naming rule.
	 *
	 * @param string $table  Table.
	 * @param string $prefix Base prefix.
	 * @return bool
	 */
	private static function is_subsite_table( string $table, string $prefix ): bool {
		return 0 === strpos( $table, $prefix ) && 1 === preg_match( '/\A[1-9][0-9]*_/', substr( $table, strlen( $prefix ) ) );
	}

	/**
	 * Whether a name after an installation's prefix is a WordPress core
	 * table: a per-site or global name, or a sub-site number and a per-site name.
	 *
	 * @param string $rest Name after the prefix.
	 * @return bool
	 */
	private static function is_core_name( string $rest ): bool {
		if ( in_array( $rest, self::SITE_TABLES, true ) || in_array( $rest, self::GLOBAL_TABLES, true ) ) {
			return true;
		}
		return 1 === preg_match( '/\A[1-9][0-9]*_(.+)\z/s', $rest, $m ) && in_array( $m[1], self::SITE_TABLES, true );
	}
}
