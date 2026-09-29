<?php
/**
 * The plugin's own run tables: never in a backup, never restored from one.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Database;

use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Restore\SwapPlan;
use WPCheckpoint\Support\Schema;

/**
 * One list for the tables this plugin keeps for its own work: the jobs
 * table and the swap plan (by name, with the installation's base prefix),
 * and the tables a restore makes (by the grammar TempTables names them
 * with: temporary tables and the ledger, "wcptmp", and the site's tables
 * moved aside, "wcpold"). They describe this installation's jobs, not the
 * site: the export leaves them out, and a restore skips them where an
 * older backup holds them.
 *
 * Generated names are recognised by their whole grammar, never by a
 * prefix: a site whose table prefix is "wcp_" or "w" has tables such as
 * "wcp_posts", which must stay in its backup. (Only a table spelled in
 * the whole form itself, a prefix "wcptmp" and a table "abcdef_7_1a2b_x",
 * cannot be told apart from one.)
 *
 * Every statement in src/ that creates a table is listed in SOURCES
 * (OwnTablesUsageTest): a new one fails the test until it is registered.
 *
 * Pure PHP.
 */
final class OwnTables {

	/**
	 * Where src/ creates a table, and what it creates.
	 */
	const SOURCES = array(
		'src/Support/Schema.php'   => 'the jobs table (names())',
		'src/Restore/SwapPlan.php' => 'the swap plan (names())',
		'src/Restore/Ledger.php'   => 'a restore\'s ledger (generated(): TempTables::ledger())',
	);

	/**
	 * The tables kept by name.
	 *
	 * @param string $base_prefix The installation's base table prefix.
	 * @return string[]
	 */
	public static function names( string $base_prefix ): array {
		return array( $base_prefix . Schema::JOBS_TABLE, $base_prefix . SwapPlan::TABLE );
	}

	/**
	 * Whether a name is one TempTables makes: "wcptmp" or "wcpold", six hex characters of an installation's
	 * token, "_", a job id, "_", four hex characters of the run, "_", and the table's own name (only characters
	 * TempTables keeps; nothing after the run's characters: a ledger, "wcptmp" only), at most TempTables::MAX_NAME
	 * bytes. Any installation's: two sites sharing a database never back up each other's either.
	 *
	 * @param string $name Table name.
	 * @return bool
	 */
	public static function generated( string $name ): bool {
		if ( strlen( $name ) > TempTables::MAX_NAME ) {
			return false;
		}
		$head = '[0-9a-f]{' . TempTables::TOKEN_LEN . '}_[1-9][0-9]{0,18}_[0-9a-f]{' . TempTables::RANDOM_LEN . '}_';
		return 1 === preg_match( '/\A' . TempTables::PREFIX . $head . '[A-Za-z0-9_]*\z/', $name )
			|| 1 === preg_match( '/\A' . TempTables::OLD_PREFIX . $head . '[A-Za-z0-9_]+\z/', $name );
	}

	/**
	 * Whether a table is one of the plugin's own.
	 *
	 * @param string $name        Table name.
	 * @param string $base_prefix The installation's base table prefix.
	 * @param bool   $fold_case   Compare the names kept by name without regard to case (lower_case_table_names).
	 * @return bool
	 */
	public static function is_own( string $name, string $base_prefix, bool $fold_case = false ): bool {
		foreach ( self::names( $base_prefix ) as $own ) {
			if ( $own === $name || ( $fold_case && strtolower( $own ) === strtolower( $name ) ) ) {
				return true;
			}
		}
		return self::generated( $name );
	}
}
