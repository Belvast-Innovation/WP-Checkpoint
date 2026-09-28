<?php
/**
 * Which live tables the backup does not have the swap moves away, and which it leaves and reports.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * The swap makes the site's set of tables the backup's: a live table of the
 * backup's name is replaced (its entry in the plan moves it away first),
 * and a live table the backup does not have is moved away when it is known
 * to be this site's. With a table prefix, those are the tables that start
 * with it; with an empty prefix, only WordPress's own table names are known
 * to be this site's: every other table is left where it is and reported.
 *
 * Never moved nor reported: the backup's own tables (their entries replace
 * them), the tables left out of the restore (the live one stays), and this
 * plugin's (its jobs table and swap plan, and the temporary and old tables,
 * the restore's ledger among them).
 */
final class TableMoves {

	/**
	 * The live tables the backup does not have: those the swap moves away, and those it leaves and reports.
	 *
	 * @param string   $prefix   The site's table prefix ('' for none).
	 * @param string   $base     The site's base table prefix (the plugin's own tables have it).
	 * @param string[] $live     The live tables.
	 * @param string[] $incoming The final names of the backup's tables.
	 * @param string[] $excluded The final names of the tables left out of the restore.
	 * @param string[] $core     WordPress's own table names for this site (read with an empty prefix only).
	 * @return array{move: string[], report: string[]} Each in the order of $live.
	 */
	public static function select( string $prefix, string $base, array $live, array $incoming, array $excluded, array $core ): array {
		$skip = array_fill_keys( array_merge( $incoming, $excluded ), true );
		$own  = array_fill_keys( $core, true );
		$out  = array(
			'move'   => array(),
			'report' => array(),
		);
		foreach ( $live as $name ) {
			$name = (string) $name;
			if ( isset( $skip[ $name ] ) || self::never( $name, $base ) ) {
				continue;
			}
			if ( '' === $prefix ) {
				$out[ isset( $own[ $name ] ) ? 'move' : 'report' ][] = $name;
			} elseif ( 0 === strncmp( $name, $prefix, strlen( $prefix ) ) ) {
				$out['move'][] = $name;
			}
			// Otherwise not this site's (another installation's prefix): left where it is.
		}
		return $out;
	}

	/**
	 * Whether a table is one the swap never touches: the plugin's jobs table and swap plan, and its temporary and
	 * old tables.
	 *
	 * @param string $name Table name.
	 * @param string $base The site's base table prefix.
	 * @return bool
	 */
	public static function never( string $name, string $base ): bool {
		return $base . Schema::JOBS_TABLE === $name || $base . SwapPlan::TABLE === $name || 0 === strncmp( $name, TempTables::PREFIX, strlen( TempTables::PREFIX ) ) || 0 === strncmp( $name, TempTables::OLD_PREFIX, strlen( TempTables::OLD_PREFIX ) );
	}
}
