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
 * site's backup, and a restore would silently write them back over the
 * neighbour's data. The rule:
 *
 * - this installation's core tables (WordPress's own list) always belong;
 * - any other table with the prefix belongs (plugin tables), except
 * - every table under a longer prefix P that has its own complete core
 *   (P . "posts", P . "options" and P . "users" all exist): P looks like
 *   another installation and the whole group is left out. On multisite a
 *   sub-site prefix ("wp_2_") is this installation's own and is never
 *   such a P (sub-sites have no users table of their own anyway).
 *
 * A misjudged group is left out and listed to the user, who can add its
 * tables back; the opposite mistake would be invisible. Pure PHP.
 */
final class TableSelection {

	/**
	 * Core tables every WordPress installation has under its prefix.
	 */
	const INSTALLATION_MARKERS = array( 'posts', 'options', 'users' );

	/**
	 * Groups of tables that look like another installation.
	 *
	 * @param string[] $tables    Tables with the prefix (base tables, not views).
	 * @param string   $prefix    This installation's prefix.
	 * @param bool     $multisite Whether this installation is a multisite network.
	 * @param string[] $core      This installation's core tables (never left out).
	 * @return array<string, string[]> Foreign prefix => its tables, both sorted.
	 */
	public static function foreign( array $tables, string $prefix, bool $multisite, array $core = array() ): array {
		$present  = array_fill_keys( $tables, true );
		$prefixes = array();
		foreach ( $tables as $table ) {
			$table = (string) $table;
			if ( 0 !== strpos( $table, $prefix ) || 'posts' !== substr( $table, -5 ) ) {
				continue;
			}
			$candidate = substr( $table, 0, -5 );
			if ( strlen( $candidate ) <= strlen( $prefix ) || self::is_subsite_prefix( $candidate, $prefix, $multisite ) ) {
				continue;
			}
			$complete = true;
			foreach ( self::INSTALLATION_MARKERS as $marker ) {
				if ( ! isset( $present[ $candidate . $marker ] ) ) {
					$complete = false;
					break;
				}
			}
			if ( $complete ) {
				$prefixes[] = $candidate;
			}
		}
		// The shortest foreign prefix covers the longer ones under it (another installation's own sub-sites).
		sort( $prefixes, SORT_STRING );
		$kept = array();
		foreach ( $prefixes as $candidate ) {
			foreach ( $kept as $outer ) {
				if ( 0 === strpos( $candidate, $outer ) ) {
					continue 2;
				}
			}
			$kept[] = $candidate;
		}
		$core   = array_fill_keys( $core, true );
		$groups = array();
		foreach ( $kept as $foreign ) {
			$members = array();
			foreach ( $tables as $table ) {
				$table = (string) $table;
				if ( 0 === strpos( $table, $foreign ) && ! isset( $core[ $table ] ) ) {
					$members[] = $table;
				}
			}
			sort( $members, SORT_STRING );
			if ( array() !== $members ) {
				$groups[ $foreign ] = $members;
			}
		}
		return $groups;
	}

	/**
	 * Whether a prefix is one of this network's sub-site prefixes
	 * ("{base}{n}_"), by WordPress's naming rule.
	 *
	 * @param string $candidate Prefix.
	 * @param string $prefix    Base prefix.
	 * @param bool   $multisite Whether this is a network.
	 * @return bool
	 */
	private static function is_subsite_prefix( string $candidate, string $prefix, bool $multisite ): bool {
		if ( ! $multisite ) {
			return false;
		}
		$rest = substr( $candidate, strlen( $prefix ) );
		return 1 === preg_match( '/\A[1-9][0-9]*_\z/', (string) $rest );
	}
}
