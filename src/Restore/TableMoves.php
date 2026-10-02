<?php
/**
 * Which live tables the backup does not have the swap moves away, and which it leaves and reports.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Database\OwnTables;
use WPCheckpoint\Database\TableSelection;

defined( 'ABSPATH' ) || exit;

/**
 * The swap makes the site's set of tables the backup's: a live table of the
 * backup's name is replaced (its entry in the plan moves it away first),
 * and a live table the backup does not have is moved away only when it is
 * shown to be this site's. The rule is the export's (TableSelection): with
 * a table prefix, every table under it that no other installation in the
 * same database claims, a network's sub-sites' tables among them. A table
 * that another installation's WordPress tables show to be its own (a longer
 * prefix, such as "wp_old_" under "wp_"), or that may be either's, is left
 * where it is and reported, even when it is this site's too (a users table
 * the two share), as is every table outside the prefix. With an empty prefix,
 * only WordPress's own table names are this site's: every other table is
 * left and reported.
 *
 * Never moved nor reported: the backup's own tables (their entries replace
 * them), the tables left out of the restore (the live one stays), and this
 * plugin's (its jobs table and swap plan, and the temporary and old tables,
 * the restore's ledger among them).
 *
 * Where the server compares table names without case, names are compared
 * lowercased (it lists them so; WordPress spells the prefix as wp-config.php
 * does); the live tables are returned as listed.
 */
final class TableMoves {

	/**
	 * The live tables the backup does not have: those the swap moves away, and those it leaves and reports.
	 *
	 * @param string   $prefix    The site's table prefix ('' for none).
	 * @param bool     $multisite Whether the site is a network (its sub-sites' tables are its own).
	 * @param string[] $live      The live tables.
	 * @param string[] $incoming  The final names of the backup's tables.
	 * @param string[] $excluded  The final names of the tables left out of the restore.
	 * @param string[] $core      WordPress's own tables of this site (read with an empty prefix only).
	 * @param bool     $fold      Whether the server compares table names without case (lower_case_table_names <> 0).
	 * @return array{move: string[], report: string[]} Each in the order of $live.
	 */
	public static function select( string $prefix, bool $multisite, array $live, array $incoming, array $excluded, array $core, bool $fold = false ): array {
		$key    = static function ( string $name ) use ( $fold ): string {
			return $fold ? strtolower( $name ) : $name;
		};
		$skip   = array_fill_keys( array_map( $key, array_map( 'strval', array_merge( $incoming, $excluded ) ) ), true );
		$own    = array_fill_keys( array_map( $key, array_map( 'strval', $core ) ), true );
		$prefix = $key( $prefix );
		$out    = array(
			'move'   => array(),
			'report' => array(),
		);
		$theirs = array();
		if ( '' !== $prefix ) {
			// Every live table under the prefix, the backup's included: another installation shows by its own tables.
			$under = array();
			foreach ( $live as $name ) {
				if ( 0 === strncmp( $key( (string) $name ), $prefix, strlen( $prefix ) ) ) {
					$under[] = $key( (string) $name );
				}
			}
			// This site's WordPress tables are not protected here, unlike in the export: one that another installation
			// claims too (a users table shared through CUSTOM_USER_TABLE) is left where it is, not moved.
			foreach ( TableSelection::foreign( $under, $prefix, $multisite ) as $group ) {
				foreach ( array_merge( $group['excluded'], $group['kept'] ) as $name ) {
					$theirs[ $name ] = true;
				}
			}
		}
		foreach ( $live as $name ) {
			$name = (string) $name;
			$k    = $key( $name );
			if ( isset( $skip[ $k ] ) || self::never( $name ) ) {
				continue;
			}
			if ( '' === $prefix ) {
				$mine = isset( $own[ $k ] );
			} else {
				$mine = 0 === strncmp( $k, $prefix, strlen( $prefix ) ) && ! isset( $theirs[ $k ] );
			}
			$out[ $mine ? 'move' : 'report' ][] = $name;
		}
		return $out;
	}

	/**
	 * Whether a table is one the swap never touches: a run table of this plugin, this installation's or another's
	 * in the same database (Database\OwnTables).
	 *
	 * @param string $name Table name.
	 * @return bool
	 */
	public static function never( string $name ): bool {
		return OwnTables::is_own( $name );
	}
}
