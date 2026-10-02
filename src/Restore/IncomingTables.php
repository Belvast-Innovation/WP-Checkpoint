<?php
/**
 * Which of a backup's tables would replace a live table another installation in the same database uses.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Database\TableSelection;

defined( 'ABSPATH' ) || exit;

/**
 * Pure PHP. A restore replaces the live table of each of the backup's tables. In a database that other WordPress
 * installations share, some of those live tables may be theirs: a backup made before neighbours were left out of
 * backups holds them, and an installation may use another one's tables (CUSTOM_USER_TABLE). The judgement is the
 * swap's (TableMoves): TableSelection::foreign() on the live tables under this site's prefix, without protecting this
 * site's own WordPress tables. Each table of the backup, by its final name on this site, is one of:
 *
 * - shared: this site uses it, and another installation shows it uses it too. Either this site's own WordPress
 *   table falls in a neighbour's group (this site's CUSTOM_USER_TABLE is "wp_old_users"), or the usermeta table this
 *   site uses holds another installation's capabilities key (evidence()), and then this site's users and usermeta
 *   tables both are. Restoring it changes the other installation's data.
 * - neighbour: one of a neighbour's own WordPress tables, and not this site's: never restored over it.
 * - uncertain: under a neighbour's prefix, but not one of its WordPress tables ("wp_old_" + "shop_orders" may be
 *   "wp_" + "old_shop_orders"): either installation's.
 * - none of these: this site's, restored as usual. A WordPress table of this site's that falls among a neighbour's
 *   tables that may be either's (its CUSTOM_USER_TABLE "wp_old_members") is this site's: WordPress says this site
 *   uses it, and the neighbour does not claim it.
 *
 * Not seen: an installation that uses this site's users table (its CUSTOM_USER_TABLE names it) and a usermeta table
 * of its own leaves nothing in this database that shows it; nor one whose prefix does not end in "_" (its key
 * "abccapabilities" is not looked for). With an empty prefix no table is judged, as in TableMoves: no prefix tells
 * installations apart.
 *
 * Names are compared as given: a server that compares table names without case is refused, unless every name the
 * restore compares is lowercase (fold_refusal()).
 */
final class IncomingTables {

	const SHARED    = 'shared';
	const NEIGHBOUR = 'neighbour';
	const UNCERTAIN = 'uncertain';

	/**
	 * The suffix of a user's capabilities key ("{prefix}capabilities"), as looked for (the prefix ending in "_").
	 */
	const CAPABILITIES = '_capabilities';

	/**
	 * The prefixes of other installations among capabilities keys of the usermeta table this site uses: every key
	 * ending in "_capabilities" (in any case) whose prefix is neither this site's nor, on a network, one of its sites'
	 * ("{prefix}{blog_id}_" for a blog_id its blogs table has). On a single site nothing else is this site's. A
	 * plugin's own key, or one left from an earlier prefix of this site, counts too: it can only make the restore ask.
	 *
	 * @param string   $prefix    This site's table prefix (the network's base prefix).
	 * @param bool     $multisite Whether this site is a network.
	 * @param string[] $keys      Meta keys.
	 * @param callable $is_blog   function( int $blog_id ): bool, whether this network has that site.
	 * @return string[] Prefixes, sorted, each once.
	 */
	public static function evidence( string $prefix, bool $multisite, array $keys, callable $is_blog ): array {
		$out    = array();
		$suffix = strlen( self::CAPABILITIES );
		foreach ( $keys as $key ) {
			$key = (string) $key;
			if ( strlen( $key ) < $suffix || 0 !== substr_compare( $key, self::CAPABILITIES, -$suffix, $suffix, true ) ) {
				continue;
			}
			$owner = substr( $key, 0, 1 - $suffix ); // "capabilities" off: the prefix keeps its "_".
			if ( $owner === $prefix ) {
				continue;
			}
			if ( $multisite && 0 === strncmp( $owner, $prefix, strlen( $prefix ) ) && 1 === preg_match( '/\A([1-9][0-9]{0,18})_\z/', substr( $owner, strlen( $prefix ) ), $m ) && call_user_func( $is_blog, (int) $m[1] ) ) {
				continue; // One of this network's sites.
			}
			$out[ $owner ] = true;
		}
		$out = array_map( 'strval', array_keys( $out ) );
		sort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * Why names this restore compares cannot be compared here: the server compares table names without case and one
	 * of them is not lowercase. '' when nothing is in the way.
	 *
	 * @param bool        $fold     Whether the server compares table names without case.
	 * @param string      $prefix   This site's table prefix.
	 * @param string|null $users    CUSTOM_USER_TABLE, when defined.
	 * @param string|null $usermeta CUSTOM_USER_META_TABLE, when defined.
	 * @param string[]    $backup   The tables of the backup.
	 * @return string
	 */
	public static function fold_refusal( bool $fold, string $prefix, $users, $usermeta, array $backup ): string {
		if ( ! $fold ) {
			return '';
		}
		$named = array( array( 'the table prefix of this site (' . $prefix . ')', $prefix ) );
		if ( null !== $users ) {
			$named[] = array( 'CUSTOM_USER_TABLE (' . $users . ')', (string) $users );
		}
		if ( null !== $usermeta ) {
			$named[] = array( 'CUSTOM_USER_META_TABLE (' . $usermeta . ')', (string) $usermeta );
		}
		foreach ( $backup as $table ) {
			$named[] = array( 'the backup\'s table ' . $table, (string) $table );
		}
		foreach ( $named as $item ) {
			if ( strtolower( $item[1] ) !== $item[1] ) {
				return sprintf( 'This database server compares table names without letter case (lower_case_table_names), and %s has upper-case letters. This version of WP Checkpoint restores on such a server only when every table name involved is lowercase. Nothing was changed.', $item[0] );
			}
		}
		return '';
	}

	/**
	 * The backup's tables that are not simply this site's, by their final names.
	 *
	 * @param string   $prefix    This site's table prefix.
	 * @param bool     $multisite Whether this site is a network.
	 * @param string[] $live      The live tables.
	 * @param string[] $finals    The final names of the backup's tables.
	 * @param string[] $core      This site's WordPress tables, as WordPress names them (CUSTOM_USER_TABLE included).
	 * @param string   $users     The users table this site uses.
	 * @param string   $usermeta  The usermeta table this site uses.
	 * @param bool     $evidence  Whether the usermeta table this site uses shows another installation (evidence(), or
	 *                            a search that could not finish).
	 * @return array<string, string> Final name => SHARED, NEIGHBOUR or UNCERTAIN, in the order of $finals.
	 */
	public static function classify( string $prefix, bool $multisite, array $live, array $finals, array $core, string $users, string $usermeta, bool $evidence ): array {
		$theirs = array();
		$either = array();
		foreach ( self::groups( $prefix, $multisite, $live ) as $group ) {
			foreach ( $group['excluded'] as $name ) {
				$theirs[ $name ] = true;
			}
			foreach ( $group['kept'] as $name ) {
				$either[ $name ] = true;
			}
		}
		$own    = array_fill_keys( array_map( 'strval', $core ), true );
		$shared = array();
		foreach ( array_keys( $own ) as $name ) {
			if ( isset( $theirs[ $name ] ) ) {
				$shared[ $name ] = true;
			}
		}
		if ( $evidence ) {
			$shared[ $users ]    = true;
			$shared[ $usermeta ] = true;
		}
		$out = array();
		foreach ( $finals as $name ) {
			$name = (string) $name;
			if ( isset( $shared[ $name ] ) ) {
				$out[ $name ] = self::SHARED;
			} elseif ( isset( $own[ $name ] ) ) {
				continue; // One of this site's WordPress tables that no neighbour claims as one of its own.
			} elseif ( isset( $theirs[ $name ] ) ) {
				$out[ $name ] = self::NEIGHBOUR;
			} elseif ( isset( $either[ $name ] ) ) {
				$out[ $name ] = self::UNCERTAIN;
			}
		}
		return $out;
	}

	/**
	 * The neighbours under this site's prefix, as the swap sees them.
	 *
	 * @param string   $prefix    This site's table prefix.
	 * @param bool     $multisite Whether this site is a network.
	 * @param string[] $live      The live tables.
	 * @return array<string, array{excluded: string[], kept: string[]}>
	 */
	private static function groups( string $prefix, bool $multisite, array $live ): array {
		if ( '' === $prefix ) {
			return array();
		}
		$under = array();
		foreach ( $live as $name ) {
			if ( 0 === strncmp( (string) $name, $prefix, strlen( $prefix ) ) ) {
				$under[] = (string) $name;
			}
		}
		return TableSelection::foreign( $under, $prefix, $multisite );
	}
}
