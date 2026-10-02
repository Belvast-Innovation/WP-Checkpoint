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
 * row stored under one of the plugin's exact names (Support\StoredNames:
 * the name itself and a transient's value and expiry) with the live
 * table's rows, and puts this plugin in the temporary table's list of
 * active plugins; and does the same with the names the plugin builds at run
 * time (a loopback token, a probe challenge: StoredNames::is_built(), rows
 * found by their prefixes and kept only when what follows is of the right
 * form), so that another job's token or a pending probe outlives the swap.
 * The plugin's names, not "everything that starts with wpcheckpoint_": a
 * row of the backup's own named like that (a table prefix "wpcheckpoint_"
 * makes "wpcheckpoint_user_roles") stays the backup's. The names are
 * matched by the columns' collation, as WordPress reads them (get_option()
 * ends in an SQL equality). It is a delete and an insert, so running
 * it again gives the same result. guard() checks that list and runs the
 * swap it is given only when this plugin is in it, right after the check.
 * The swap (Jobs\SwapStep) calls carry(), then guard() with its first
 * batch of renames; the import uses readable().
 *
 * Not carried: the user-level dismissed notices (the users table is
 * replaced and dismissals start over), the plugin's cron events inside the
 * cron option (the running plugin reschedules what it drives), the options
 * of a network's other sites.
 */
final class StateCarry {

	/**
	 * Names per statement when carrying built names.
	 */
	const CHUNK = 200;

	/**
	 * Connection.
	 *
	 * @var ImportSession
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
	 * @param ImportSession $db      Connection.
	 * @param string        $plugin  This plugin's file ("wp-checkpoint/wp-checkpoint.php").
	 * @param int           $network The network's id on multisite, 0 on a single site.
	 * @param callable|null $seam    A crash seam (tests): called at "carry_written", inside the transaction.
	 */
	public function __construct( ImportSession $db, string $plugin, int $network, $seam = null ) {
		$this->db      = $db;
		$this->plugin  = $plugin;
		$this->network = $network;
		$this->seam    = is_callable( $seam ) ? $seam : null;
	}

	/**
	 * Carry the live rows into the temporary tables and make this plugin active there.
	 *
	 * @param string      $live_options Live options table.
	 * @param string      $temp_options Temporary options table.
	 * @param string|null $live_meta    Live sitemeta table (multisite), null on a single site.
	 * @param string|null $temp_meta    Temporary sitemeta table (multisite).
	 * @return void
	 * @throws Refused When the temporary table's list of active plugins cannot be read.
	 * @throws \Throwable Whatever else stops it, after the transaction is rolled back.
	 */
	public function carry( string $live_options, string $temp_options, $live_meta = null, $temp_meta = null ): void {
		$this->db->begin();
		try {
			$names = StoredNames::stored_forms( false );
			$in    = self::marks( $names );
			$this->db->rows( 'DELETE FROM ' . SqlWriter::identifier( $temp_options ) . ' WHERE option_name IN (' . $in . ')', $names );
			$this->db->rows( 'INSERT INTO ' . SqlWriter::identifier( $temp_options ) . ' (option_name, option_value, autoload) SELECT option_name, option_value, autoload FROM ' . SqlWriter::identifier( $live_options ) . ' WHERE option_name IN (' . $in . ')', $names );
			$this->carry_built( $live_options, $temp_options, 'option_name', array(), '(option_name, option_value, autoload) SELECT option_name, option_value, autoload' );
			if ( null !== $live_meta && null !== $temp_meta ) {
				$names = StoredNames::stored_forms( true );
				$in    = self::marks( $names );
				$args  = array_merge( array( (string) $this->network ), $names );
				$this->db->rows( 'DELETE FROM ' . SqlWriter::identifier( $temp_meta ) . ' WHERE site_id = ? AND meta_key IN (' . $in . ')', $args );
				$this->db->rows( 'INSERT INTO ' . SqlWriter::identifier( $temp_meta ) . ' (site_id, meta_key, meta_value) SELECT site_id, meta_key, meta_value FROM ' . SqlWriter::identifier( $live_meta ) . ' WHERE site_id = ? AND meta_key IN (' . $in . ')', $args );
				$this->carry_built( $live_meta, $temp_meta, 'meta_key', array( (string) $this->network ), '(site_id, meta_key, meta_value) SELECT site_id, meta_key, meta_value' );
				$this->activate_network( $temp_meta );
			} else {
				$this->activate( $temp_options );
			}
			if ( null !== $this->seam ) {
				call_user_func( $this->seam, 'carry_written' );
			}
			$this->db->commit();
		} catch ( \Throwable $e ) {
			$this->db->rollback();
			throw $e;
		}
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
		$rows = $this->db->rows( 'SELECT option_value FROM ' . SqlWriter::identifier( $temp_options ) . " WHERE option_name = 'active_plugins'" );
		if ( array() === $rows ) {
			$this->db->rows( 'INSERT INTO ' . SqlWriter::identifier( $temp_options ) . " (option_name, option_value, autoload) VALUES ('active_plugins', ?, 'yes')", array( PluginList::write( array( $this->plugin ) ) ) );
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
		$this->db->rows( 'UPDATE ' . SqlWriter::identifier( $temp_options ) . " SET option_value = ? WHERE option_name = 'active_plugins'", array( PluginList::write( array_values( $list ) ) ) );
	}

	/**
	 * Put this plugin into active_sitewide_plugins of the temporary sitemeta table.
	 *
	 * @param string $temp_meta Temporary sitemeta table.
	 * @return void
	 * @throws Refused When the list cannot be read.
	 */
	private function activate_network( string $temp_meta ): void {
		$rows = $this->db->rows( 'SELECT meta_value FROM ' . SqlWriter::identifier( $temp_meta ) . " WHERE site_id = ? AND meta_key = 'active_sitewide_plugins'", array( (string) $this->network ) );
		if ( array() === $rows ) {
			$this->db->rows( 'INSERT INTO ' . SqlWriter::identifier( $temp_meta ) . " (site_id, meta_key, meta_value) VALUES (?, 'active_sitewide_plugins', ?)", array( (string) $this->network, PluginList::write( array( $this->plugin => time() ) ) ) );
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
		$this->db->rows( 'UPDATE ' . SqlWriter::identifier( $temp_meta ) . " SET meta_value = ? WHERE site_id = ? AND meta_key = 'active_sitewide_plugins'", array( PluginList::write( $list ), (string) $this->network ) );
	}

	/**
	 * The temporary table's active_plugins, null when missing or unreadable.
	 *
	 * @param string $temp_options Temporary options table.
	 * @return array<int|string, int|string>|null
	 */
	private function site_list( string $temp_options ) {
		$rows = $this->db->rows( 'SELECT option_value FROM ' . SqlWriter::identifier( $temp_options ) . " WHERE option_name = 'active_plugins'" );
		return array() === $rows ? null : PluginList::read( (string) $rows[0][0] );
	}

	/**
	 * The temporary table's active_sitewide_plugins, null when missing or unreadable.
	 *
	 * @param string $temp_meta Temporary sitemeta table.
	 * @return array<int|string, int|string>|null
	 */
	private function network_list( string $temp_meta ) {
		$rows = $this->db->rows( 'SELECT meta_value FROM ' . SqlWriter::identifier( $temp_meta ) . " WHERE site_id = ? AND meta_key = 'active_sitewide_plugins'", array( (string) $this->network ) );
		return array() === $rows ? null : PluginList::read( (string) $rows[0][0] );
	}

	/**
	 * Replace the temporary table's rows under built names with the live table's.
	 *
	 * @param string   $live    Live table.
	 * @param string   $temp    Temporary table.
	 * @param string   $column  Name column.
	 * @param string[] $site    The network's id (sitemeta), or nothing (options).
	 * @param string   $columns Columns inserted and their SELECT list.
	 * @return void
	 */
	private function carry_built( string $live, string $temp, string $column, array $site, string $columns ): void {
		$where = null === ( $site[0] ?? null ) ? '' : 'site_id = ? AND ';
		foreach ( array_chunk( $this->built_in( $temp, $column, $where, $site ), self::CHUNK ) as $names ) {
			$this->db->rows( 'DELETE FROM ' . SqlWriter::identifier( $temp ) . ' WHERE ' . $where . $column . ' IN (' . self::marks( $names ) . ')', array_merge( $site, $names ) );
		}
		foreach ( array_chunk( $this->built_in( $live, $column, $where, $site ), self::CHUNK ) as $names ) {
			$this->db->rows( 'INSERT INTO ' . SqlWriter::identifier( $temp ) . ' ' . $columns . ' FROM ' . SqlWriter::identifier( $live ) . ' WHERE ' . $where . $column . ' IN (' . self::marks( $names ) . ')', array_merge( $site, $names ) );
		}
	}

	/**
	 * The names in a table that are built names (StoredNames::is_built()), found by their prefixes in every form.
	 *
	 * @param string   $table  Table.
	 * @param string   $column Name column.
	 * @param string   $where  "site_id = ? AND " or nothing.
	 * @param string[] $site   Its value.
	 * @return string[]
	 */
	private function built_in( string $table, string $column, string $where, array $site ): array {
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
		foreach ( $this->db->rows( 'SELECT ' . $column . ' FROM ' . SqlWriter::identifier( $table ) . ' WHERE ' . $where . '(' . implode( ' OR ', $likes ) . ')', $args ) as $row ) {
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
