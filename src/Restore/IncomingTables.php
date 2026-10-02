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
 *   site uses holds the role keys of a neighbour's prefix ("wp_old_capabilities", "wp_old_user_level": the neighbour
 *   keeps its users' roles there), and then this site's users and usermeta tables both are. Restoring it changes the
 *   other installation's data.
 * - neighbour: one of a neighbour's own WordPress tables, and not this site's: never restored over it.
 * - uncertain: under a neighbour's prefix, but not one of its WordPress tables ("wp_old_" + "shop_orders" may be
 *   "wp_" + "old_shop_orders"): either installation's.
 * - none of these: this site's, restored as usual.
 *
 * A WordPress table of this site's that falls among a neighbour's tables that may be either's (its CUSTOM_USER_TABLE
 * "wp_old_members") is this site's: WordPress says this site uses it, and the neighbour does not claim it.
 *
 * Only the neighbours' prefixes count for role keys, never a network's sub-sites ("wp_2_capabilities": the sub-sites
 * are this network's, never a group of foreign()). Not seen: an installation that uses this site's users table
 * (its CUSTOM_USER_TABLE names it) and a usermeta table of its own leaves nothing in this database that shows it.
 * With an empty prefix nothing is judged, as in TableMoves: no prefix tells installations apart.
 *
 * Where the server compares table names without case ($fold, lower_case_table_names <> 0), names are compared
 * lowercased: such a server lists them lowercased, while WordPress names them as wp-config.php spells the prefix.
 * The tables are returned as given.
 */
final class IncomingTables {

	const SHARED    = 'shared';
	const NEIGHBOUR = 'neighbour';
	const UNCERTAIN = 'uncertain';

	/**
	 * The role keys of the neighbours' prefixes: the meta keys to look for in the usermeta table this site uses.
	 *
	 * @param string   $prefix    This site's table prefix.
	 * @param bool     $multisite Whether this site is a network.
	 * @param string[] $live      The live tables.
	 * @param bool     $fold      Whether the server compares table names without case.
	 * @return string[]
	 */
	public static function role_keys( string $prefix, bool $multisite, array $live, bool $fold = false ): array {
		$keys = array();
		foreach ( array_keys( self::groups( $prefix, $multisite, $live, $fold ) ) as $neighbour ) {
			$keys[] = $neighbour . 'capabilities';
			$keys[] = $neighbour . 'user_level';
		}
		return $keys;
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
	 * @param string[] $found     The role keys (role_keys()) the usermeta table this site uses holds.
	 * @param bool     $fold      Whether the server compares table names without case.
	 * @return array<string, string> Final name => SHARED, NEIGHBOUR or UNCERTAIN, in the order of $finals.
	 */
	public static function classify( string $prefix, bool $multisite, array $live, array $finals, array $core, string $users, string $usermeta, array $found, bool $fold = false ): array {
		$groups = self::groups( $prefix, $multisite, $live, $fold );
		$theirs = array();
		$either = array();
		foreach ( $groups as $group ) {
			foreach ( $group['excluded'] as $name ) {
				$theirs[ $name ] = true;
			}
			foreach ( $group['kept'] as $name ) {
				$either[ $name ] = true;
			}
		}
		$own = array();
		foreach ( $core as $name ) {
			$own[ self::key( (string) $name, $fold ) ] = true;
		}
		$shared = array();
		foreach ( array_keys( $own ) as $name ) {
			if ( isset( $theirs[ $name ] ) ) {
				$shared[ $name ] = true;
			}
		}
		$evidence = array_intersect(
			array_map(
				static function ( $key ): string {
					return strtolower( (string) $key ); // Meta keys: the column compares them without case.
				},
				$found
			),
			array_map( 'strtolower', self::role_keys( $prefix, $multisite, $live, $fold ) )
		);
		if ( array() !== $evidence ) {
			$shared[ self::key( $users, $fold ) ]    = true;
			$shared[ self::key( $usermeta, $fold ) ] = true;
		}
		$out = array();
		foreach ( $finals as $name ) {
			$name = (string) $name;
			$key  = self::key( $name, $fold );
			if ( isset( $shared[ $key ] ) ) {
				$out[ $name ] = self::SHARED;
			} elseif ( isset( $own[ $key ] ) ) {
				continue; // One of this site's WordPress tables that no neighbour claims as one of its own.
			} elseif ( isset( $theirs[ $key ] ) ) {
				$out[ $name ] = self::NEIGHBOUR;
			} elseif ( isset( $either[ $key ] ) ) {
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
	 * @param bool     $fold      Whether the server compares table names without case (then all lowercased).
	 * @return array<string, array{excluded: string[], kept: string[]}>
	 */
	private static function groups( string $prefix, bool $multisite, array $live, bool $fold ): array {
		if ( '' === $prefix ) {
			return array();
		}
		$prefix = self::key( $prefix, $fold );
		$under  = array();
		foreach ( $live as $name ) {
			$name = self::key( (string) $name, $fold );
			if ( 0 === strncmp( $name, $prefix, strlen( $prefix ) ) ) {
				$under[] = $name;
			}
		}
		return TableSelection::foreign( $under, $prefix, $multisite );
	}

	/**
	 * A table name as the server compares it.
	 *
	 * @param string $name Table name.
	 * @param bool   $fold Whether the server compares table names without case.
	 * @return string
	 */
	private static function key( string $name, bool $fold ): string {
		return $fold ? strtolower( $name ) : $name;
	}
}
