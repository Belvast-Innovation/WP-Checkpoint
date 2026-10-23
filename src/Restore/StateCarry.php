<?php
/**
 * This plugin's own state, carried into the restored tables just before the swap.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Database\SqlWriter;
use WPCheckpoint\Support\StoredNames;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- job errors with table names; the presenter cleans them.

/**
 * A restore replaces the options table (and on multisite the sitemeta
 * table), where this plugin keeps its own state: the storage directory and
 * its token, the schema version, the uninstall setting, caches and
 * results. The running plugin must find its own state after the swap, not
 * the backup's, or it takes its storage directory for another one and fails
 * every job, the restore included. And it must still be active, or after
 * the swap WordPress no longer loads it and nothing is left to finish or
 * undo the restore but the standalone endpoint.
 *
 * The method carry() replaces, in the temporary options table (and sitemeta), every
 * row stored under one of the plugin's own names with the live table's
 * rows, and puts this plugin in the temporary table's list of active
 * plugins. Which rows are the plugin's own is decided in one place,
 * own_names(): the rows stored under one of the plugin's exact names
 * (Support\StoredNames: the name itself and a transient's value and
 * expiry), the names the plugin builds at run time (a loopback token, a
 * probe challenge: StoredNames::is_built(), rows found by their prefixes
 * and kept only when what follows is of the right form), so that another
 * job's token or a pending probe outlives the swap, and the row the carry
 * activates this plugin in (active_plugins, active_sitewide_plugins). The
 * plugin's names, not "everything that starts with wpcheckpoint_": a row of
 * the backup's own named like that (a table prefix "wpcheckpoint_" makes
 * "wpcheckpoint_user_roles") stays the backup's. The names are matched by
 * the columns' collation, as WordPress reads them (get_option() ends in an
 * SQL equality). It is a delete by name and an insert by name, so running
 * it again, whole or after it was cut short, gives the same rows. The
 * count of a temporary options or sitemeta table (Jobs\TempTableCount)
 * leaves the same rows out, through the same own_names(): on a table
 * without transactions (MyISAM, Aria) a carry that was cut short leaves
 * some of its rows behind, and the count of what the import left must not
 * see them. guard() checks that list and runs the
 * swap it is given only when this plugin is in it, right after the check.
 * The swap (Jobs\SwapStep) calls carry(), then guard() with its first
 * batch of renames; the import uses readable().
 *
 * The other way, carry_back(): before a swap that was made is put back (an
 * undo, a rollback once the options table was renamed), the rows under the
 * plugin's own names that are in the live table (written since the swap: a
 * setting, the storage state, the schema version) are carried into the old
 * table about to come back, by the same names and the same statements, so
 * that putting the site back does not put the plugin's state back too. The
 * self-check row (StoredNames::SELF_CHECK) is left out: it belongs to the
 * restored site's checks, and must not reach the old site or a later backup.
 *
 * Not carried: the user-level dismissed notices (the users table is
 * replaced and dismissals start over), the plugin's cron events inside the
 * cron option (the running plugin reschedules what it drives), the options
 * of a network's other sites.
 */
final class StateCarry {

	/**
	 * Names per statement when carrying or counting by name.
	 */
	const CHUNK = 200;

	/**
	 * The rows the carry activates this plugin in: a site's and a network's list of active plugins.
	 */
	const ACTIVE         = 'active_plugins';
	const ACTIVE_NETWORK = 'active_sitewide_plugins';

	/**
	 * Connection.
	 *
	 * @var Queries
	 */
	private $db;

	/**
	 * This plugin's file relative to the plugins directory ("wp-checkpoint/wp-checkpoint.php").
	 *
	 * @var string
	 */
	private $plugin;

	/**
	 * The network's id in sitemeta, or 0 on a single site.
	 *
	 * @var int
	 */
	private $network;

	/**
	 * A crash seam (tests): function( string $point ): void, or null.
	 *
	 * @var callable|null
	 */
	private $seam;

	/**
	 * Constructor.
	 *
	 * @param Queries       $db      Connection (the swap's).
	 * @param string        $plugin  This plugin's file ("wp-checkpoint/wp-checkpoint.php").
	 * @param int           $network The network's id on multisite, 0 on a single site.
	 * @param callable|null $seam    A crash seam (tests): called after each statement ("carry_1", ...) and at
	 *                               "carry_written", inside the transaction.
	 */
	public function __construct( Queries $db, string $plugin, int $network, $seam = null ) {
		$this->db      = $db;
		$this->plugin  = $plugin;
		$this->network = $network;
		$this->seam    = is_callable( $seam ) ? $seam : null;
	}

	/**
	 * Carry the live rows into the temporary tables and make this plugin active there: in each temporary table the
	 * rows under the plugin's own names (own_names(), without the activation row) are deleted, the live table's rows
	 * under its own names inserted, and the plugin put into the list of active plugins; a crash seam after each
	 * statement ("carry_1", "carry_2", ...) and one at the end ("carry_written"), inside the transaction.
	 *
	 * In the fenced transaction (JobFence::within(): its first statement locks the job row under the run's token):
	 * a take-over rewrites that row, so it waits until this transaction ends, and a run that lost the job finds no
	 * row, rolls back and writes nothing. That holds for temporary tables without transactions too: the lock is on
	 * the job row.
	 *
	 * @param string                                       $live_options Live options table.
	 * @param string                                       $temp_options Temporary options table.
	 * @param string|null                                  $live_meta    Live sitemeta table (multisite), null on a single site.
	 * @param string|null                                  $temp_meta    Temporary sitemeta table (multisite).
	 * @param array{table: string, id: int, token: string} $fence        The job row and the run's token.
	 * @return void
	 * @throws Refused When the temporary table's list of active plugins cannot be read.
	 * @throws \WPCheckpoint\Jobs\LockLost When the job is no longer this run's: nothing is written.
	 * @throws \Throwable Whatever else stops it, after the transaction is rolled back.
	 */
	public function carry( string $live_options, string $temp_options, $live_meta, $temp_meta, array $fence ): void {
		$this->carry_between( $live_options, $temp_options, $live_meta, $temp_meta, $fence, 'carry', array() );
	}

	/**
	 * Carry this plugin's rows the other way, from the live tables into the old ones about to be renamed back, and
	 * keep it active there: as carry(), but without the self-check row (StoredNames::SELF_CHECK), with its seams
	 * named "carry_back_…". The caller runs it only while both tables are where the swap left them.
	 *
	 * @param string                                       $live_options Live options table (the restored site's).
	 * @param string                                       $old_options  The options table the swap moved aside.
	 * @param string|null                                  $live_meta    Live sitemeta table (multisite), null when not carried.
	 * @param string|null                                  $old_meta     The sitemeta table the swap moved aside, null when not carried.
	 * @param array{table: string, id: int, token: string} $fence        The job row and the run's token.
	 * @return void
	 * @throws Refused When the old table's list of active plugins cannot be read.
	 * @throws \WPCheckpoint\Jobs\LockLost When the job is no longer this run's: nothing is written.
	 * @throws \Throwable Whatever else stops it, after the transaction is rolled back.
	 */
	public function carry_back( string $live_options, string $old_options, $live_meta, $old_meta, array $fence ): void {
		$this->carry_between( $live_options, $old_options, $live_meta, $old_meta, $fence, 'carry_back', array( StoredNames::SELF_CHECK ) );
	}

	/**
	 * What carry() and carry_back() run: one fenced transaction from one pair of tables into the other.
	 *
	 * @param string                                       $from_options Options table read.
	 * @param string                                       $to_options   Options table written.
	 * @param string|null                                  $from_meta    Sitemeta table read, or null.
	 * @param string|null                                  $to_meta      Sitemeta table written, or null.
	 * @param array{table: string, id: int, token: string} $fence        The job row and the run's token.
	 * @param string                                       $seams        The seams' prefix ("carry", "carry_back").
	 * @param string[]                                     $except       Own names not carried.
	 * @return void
	 */
	private function carry_between( string $from_options, string $to_options, $from_meta, $to_meta, array $fence, string $seams, array $except ): void {
		JobFence::within(
			$this->db,
			$fence,
			function () use ( $from_options, $to_options, $from_meta, $to_meta, $seams, $except ): void {
				$this->seams = $seams;
				$this->at( $seams . '_locked' );
				$this->statements = 0;
				$this->carry_table( $from_options, $to_options, false, array(), '(option_name, option_value, autoload) SELECT option_name, option_value, autoload', $except );
				if ( null !== $from_meta && null !== $to_meta ) {
					$this->carry_table( $from_meta, $to_meta, true, array( (string) $this->network ), '(site_id, meta_key, meta_value) SELECT site_id, meta_key, meta_value', $except );
					$this->activate_network( $to_meta );
				} else {
					$this->activate( $to_options );
				}
				$this->at( $seams . '_written' );
			}
		);
	}

	/**
	 * The prefix of the seams of the carry under way ("carry", "carry_back").
	 *
	 * @var string
	 */
	private $seams = 'carry';

	/**
	 * Statements the carry has sent (the seams count them).
	 *
	 * @var int
	 */
	private $statements = 0;

	/**
	 * A crash seam, after a statement or at the end.
	 *
	 * @param string $point Point.
	 * @return void
	 */
	private function at( string $point ): void {
		if ( null !== $this->seam ) {
			call_user_func( $this->seam, $point );
		}
	}

	/**
	 * One table: the temporary table's rows under the plugin's own names deleted, the live table's inserted, by
	 * name, in chunks; a seam after each statement.
	 *
	 * @param string   $live     Live table.
	 * @param string   $temp     Temporary table.
	 * @param bool     $sitemeta Whether the tables are sitemeta tables.
	 * @param string[] $site     The network's id (sitemeta), or nothing (options).
	 * @param string   $columns  Columns inserted and their SELECT list.
	 * @param string[] $except   Own names not carried (neither deleted nor inserted).
	 * @return void
	 */
	private function carry_table( string $live, string $temp, bool $sitemeta, array $site, string $columns, array $except ): void {
		JobFence::assert_locked( $this->db );
		$column = $sitemeta ? 'meta_key' : 'option_name';
		$where  = array() === $site ? '' : 'site_id = ? AND ';
		$active = $sitemeta ? self::ACTIVE_NETWORK : self::ACTIVE;
		$skip   = array_merge( array( $active ), $except );
		foreach ( array_chunk( array_values( array_diff( self::own_names( $this->db, $temp, $sitemeta, $site ), $skip ) ), self::CHUNK ) as $names ) {
			$this->db->rows( 'DELETE FROM ' . SqlWriter::identifier( $temp ) . ' WHERE ' . $where . $column . ' IN (' . self::marks( $names ) . ')', array_merge( $site, $names ) );
			$this->at( $this->seams . '_' . ( ++$this->statements ) );
		}
		foreach ( array_chunk( array_values( array_diff( self::own_names( $this->db, $live, $sitemeta, $site ), $skip ) ), self::CHUNK ) as $names ) {
			$this->db->rows( 'INSERT INTO ' . SqlWriter::identifier( $temp ) . ' ' . $columns . ' FROM ' . SqlWriter::identifier( $live ) . ' WHERE ' . $where . $column . ' IN (' . self::marks( $names ) . ')', array_merge( $site, $names ) );
			$this->at( $this->seams . '_' . ( ++$this->statements ) );
		}
	}

	/**
	 * The plugin's own names in a table, the one place that decides which rows the carry writes and the count
	 * leaves out: the exact stored names (StoredNames::stored_forms()) that are there, the built names that are
	 * there (found by their prefixes in every form, kept when StoredNames::is_built() says so), and the row the
	 * carry activates this plugin in (ACTIVE, ACTIVE_NETWORK), whether or not it is there.
	 *
	 * @param Queries  $db       Connection.
	 * @param string   $table    Table.
	 * @param bool     $sitemeta Whether it is a sitemeta table (meta_key, with a network id) or an options table.
	 * @param string[] $site     The network's id (sitemeta), or nothing (options).
	 * @return string[]
	 */
	public static function own_names( Queries $db, string $table, bool $sitemeta, array $site ): array {
		$column = $sitemeta ? 'meta_key' : 'option_name';
		$where  = array() === $site ? '' : 'site_id = ? AND ';
		$names  = array();
		foreach ( array_chunk( StoredNames::stored_forms( $sitemeta ), self::CHUNK ) as $exact ) {
			foreach ( $db->rows( 'SELECT ' . $column . ' FROM ' . SqlWriter::identifier( $table ) . ' WHERE ' . $where . $column . ' IN (' . self::marks( $exact ) . ')', array_merge( $site, $exact ) ) as $row ) {
				$names[ (string) $row[0] ] = true;
			}
		}
		foreach ( self::built_in( $db, $table, $column, $where, $site ) as $name ) {
			$names[ $name ] = true;
		}
		$names[ $sitemeta ? self::ACTIVE_NETWORK : self::ACTIVE ] = true;
		return array_keys( $names );
	}

	/**
	 * The tables whose counts leave the plugin's own rows out (Jobs\TempTableCount): the main site's options table,
	 * and on a network its sitemeta table; final name => how own_names() reads it.
	 *
	 * @param string $base_prefix The base table prefix.
	 * @param int    $network     The network's id on multisite, 0 on a single site.
	 * @return array<string, array{sitemeta: bool, site: string[]}>
	 */
	public static function counted( string $base_prefix, int $network ): array {
		$out = array(
			$base_prefix . 'options' => array(
				'sitemeta' => false,
				'site'     => array(),
			),
		);
		if ( $network > 0 ) {
			$out[ $base_prefix . 'sitemeta' ] = array(
				'sitemeta' => true,
				'site'     => array( (string) $network ),
			);
		}
		return $out;
	}

	/**
	 * Run the swap only when this plugin is active in the temporary tables.
	 * Nothing happens between the check and the swap.
	 *
	 * @template T
	 * @param string        $temp_options Temporary options table.
	 * @param string|null   $temp_meta    Temporary sitemeta table (multisite).
	 * @param callable(): T $swap         The swap.
	 * @return T
	 * @throws Refused When this plugin would not be active after the swap; the swap is not run.
	 */
	public function guard( string $temp_options, $temp_meta, callable $swap ) {
		$list = null !== $temp_meta ? $this->network_list( $temp_meta ) : $this->site_list( $temp_options );
		$on   = null !== $list && ( null !== $temp_meta ? array_key_exists( $this->plugin, $list ) : in_array( $this->plugin, $list, true ) );
		if ( ! $on ) {
			throw new Refused( 'After the swap this plugin would not be active (the restored list of active plugins does not name it), and nothing would be left to finish or undo the restore; the swap was not made and the site is as it was.' );
		}
		return $swap();
	}

	/**
	 * Whether a stored list of active plugins is readable (a flat serialized array).
	 *
	 * @param string|null $value Stored value, null when the row is missing.
	 * @return bool
	 */
	public static function readable( $value ): bool {
		return null === $value || null !== PluginList::read( $value );
	}

	/**
	 * Put this plugin into active_plugins of the temporary options table.
	 *
	 * @param string $temp_options Temporary options table.
	 * @return void
	 * @throws Refused When the list cannot be read.
	 */
	private function activate( string $temp_options ): void {
		JobFence::assert_locked( $this->db );
		$rows = $this->db->rows( 'SELECT option_value FROM ' . SqlWriter::identifier( $temp_options ) . " WHERE option_name = '" . self::ACTIVE . "'" );
		if ( array() === $rows ) {
			$this->db->rows( 'INSERT INTO ' . SqlWriter::identifier( $temp_options ) . " (option_name, option_value, autoload) VALUES ('" . self::ACTIVE . "', ?, 'yes')", array( PluginList::write( array( $this->plugin ) ) ) );
			return;
		}
		$list = PluginList::read( (string) $rows[0][0] );
		if ( null === $list ) {
			throw new Refused( 'The backup\'s list of active plugins (active_plugins) is not one WordPress wrote; the restore does not guess which plugins the restored site runs.' );
		}
		if ( in_array( $this->plugin, $list, true ) ) {
			return;
		}
		$list[] = $this->plugin;
		$this->db->rows( 'UPDATE ' . SqlWriter::identifier( $temp_options ) . " SET option_value = ? WHERE option_name = '" . self::ACTIVE . "'", array( PluginList::write( array_values( $list ) ) ) );
	}

	/**
	 * Put this plugin into active_sitewide_plugins of the temporary sitemeta table.
	 *
	 * @param string $temp_meta Temporary sitemeta table.
	 * @return void
	 * @throws Refused When the list cannot be read.
	 */
	private function activate_network( string $temp_meta ): void {
		JobFence::assert_locked( $this->db );
		$rows = $this->db->rows( 'SELECT meta_value FROM ' . SqlWriter::identifier( $temp_meta ) . " WHERE site_id = ? AND meta_key = '" . self::ACTIVE_NETWORK . "'", array( (string) $this->network ) );
		if ( array() === $rows ) {
			$this->db->rows( 'INSERT INTO ' . SqlWriter::identifier( $temp_meta ) . " (site_id, meta_key, meta_value) VALUES (?, '" . self::ACTIVE_NETWORK . "', ?)", array( (string) $this->network, PluginList::write( array( $this->plugin => time() ) ) ) );
			return;
		}
		$list = PluginList::read( (string) $rows[0][0] );
		if ( null === $list ) {
			throw new Refused( 'The backup\'s list of network-active plugins (active_sitewide_plugins) is not one WordPress wrote; the restore does not guess which plugins the restored network runs.' );
		}
		if ( array_key_exists( $this->plugin, $list ) ) {
			return;
		}
		$list[ $this->plugin ] = time();
		$this->db->rows( 'UPDATE ' . SqlWriter::identifier( $temp_meta ) . " SET meta_value = ? WHERE site_id = ? AND meta_key = '" . self::ACTIVE_NETWORK . "'", array( PluginList::write( $list ), (string) $this->network ) );
	}

	/**
	 * The temporary table's active_plugins, null when missing or unreadable.
	 *
	 * @param string $temp_options Temporary options table.
	 * @return array<int|string, int|string>|null
	 */
	private function site_list( string $temp_options ) {
		$rows = $this->db->rows( 'SELECT option_value FROM ' . SqlWriter::identifier( $temp_options ) . " WHERE option_name = '" . self::ACTIVE . "'" );
		return array() === $rows ? null : PluginList::read( (string) $rows[0][0] );
	}

	/**
	 * The temporary table's active_sitewide_plugins, null when missing or unreadable.
	 *
	 * @param string $temp_meta Temporary sitemeta table.
	 * @return array<int|string, int|string>|null
	 */
	private function network_list( string $temp_meta ) {
		$rows = $this->db->rows( 'SELECT meta_value FROM ' . SqlWriter::identifier( $temp_meta ) . " WHERE site_id = ? AND meta_key = '" . self::ACTIVE_NETWORK . "'", array( (string) $this->network ) );
		return array() === $rows ? null : PluginList::read( (string) $rows[0][0] );
	}

	/**
	 * The names in a table that are built names (StoredNames::is_built()), found by their prefixes in every form.
	 *
	 * @param Queries  $db     Connection.
	 * @param string   $table  Table.
	 * @param string   $column Name column.
	 * @param string   $where  "site_id = ? AND " or nothing.
	 * @param string[] $site   Its value.
	 * @return string[]
	 */
	private static function built_in( Queries $db, string $table, string $column, string $where, array $site ): array {
		$likes = array();
		$args  = $site;
		foreach ( array_merge( array( '' ), StoredNames::TRANSIENT_FORMS ) as $form ) {
			foreach ( array_keys( StoredNames::BUILT ) as $prefix ) {
				$likes[] = $column . " LIKE ? ESCAPE '!'";
				$args[]  = strtr(
					$form . $prefix,
					array(
						'!' => '!!',
						'%' => '!%',
						'_' => '!_',
					)
				) . '%';
			}
		}
		$names = array();
		foreach ( $db->rows( 'SELECT ' . $column . ' FROM ' . SqlWriter::identifier( $table ) . ' WHERE ' . $where . '(' . implode( ' OR ', $likes ) . ')', $args ) as $row ) {
			if ( StoredNames::is_built( (string) $row[0] ) ) {
				$names[] = (string) $row[0];
			}
		}
		return $names;
	}

	/**
	 * "?, ?, ..." for a list.
	 *
	 * @param string[] $values Values.
	 * @return string
	 */
	private static function marks( array $values ): string {
		return implode( ', ', array_fill( 0, count( $values ), '?' ) );
	}
}
