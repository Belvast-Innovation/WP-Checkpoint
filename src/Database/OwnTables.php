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
 * table and the swap plan (by name, after any table prefix: another
 * installation in the same database, even one under a longer prefix such
 * as "wp_old_", has its own, and they are its recovery record), and the
 * tables a restore makes (by the grammar TempTables names them with:
 * temporary tables and the ledger, "wcptmp", the site's tables moved
 * aside, "wcpold", and tables the swap's rollback moved out of the way,
 * "wcpstray"; any installation's). They describe an installation's
 * jobs, not a site: the export leaves them out, a restore skips them where
 * an older backup holds them, and the swap never moves them. Names are
 * compared without regard to letter case (lower_case_table_names).
 *
 * Generated names are recognised by their whole grammar, never by a
 * prefix: a site whose table prefix is "wcp_" or "w" has tables such as
 * "wcp_posts", which must stay in its backup. (Only a table spelled in
 * the whole form itself, a prefix "wcptmp" and a table "abcdef_7_1a2b_x",
 * cannot be told apart from one.)
 *
 * Every file that creates a table with a statement of its own is listed
 * in SOURCES (OwnTablesTest): a new one fails the test until it is
 * registered. The restore's temporary tables are made from the backup's
 * own CREATE TABLE under a name of the grammar above, and are covered by
 * it rather than listed.
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
	 * Whether a name is one TempTables makes: "wcptmp", "wcpold" or "wcpstray", six hex characters of an
	 * installation's token, "_", a job id, "_", four hex characters of the run (of the move, for "wcpstray"), "_",
	 * and the table's own name (only characters TempTables keeps; nothing after the run's characters: a ledger,
	 * "wcptmp" only), at most TempTables::MAX_NAME bytes. Any installation's: two sites sharing a database never
	 * back up each other's either.
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
			|| 1 === preg_match( '/\A(?:' . TempTables::OLD_PREFIX . '|' . TempTables::STRAY_PREFIX . ')' . $head . '[A-Za-z0-9_]+\z/', $name );
	}

	/**
	 * Whether a name is the jobs table or the swap plan of any installation: the name after a table prefix
	 * (letters, digits and "_", or none), in any letter case. (A site's own table of that very name would be
	 * taken for one; the names are this plugin's.)
	 *
	 * @param string $name Table name.
	 * @return bool
	 */
	public static function named( string $name ): bool {
		return 1 === preg_match( '/\A[A-Za-z0-9_]*(?:' . preg_quote( Schema::JOBS_TABLE, '/' ) . '|' . preg_quote( SwapPlan::TABLE, '/' ) . ')\z/i', $name );
	}

	/**
	 * Whether a table is one of the plugin's own: this installation's or another's (named(), generated()).
	 *
	 * @param string $name Table name.
	 * @return bool
	 */
	public static function is_own( string $name ): bool {
		return self::named( $name ) || self::generated( $name );
	}
}
