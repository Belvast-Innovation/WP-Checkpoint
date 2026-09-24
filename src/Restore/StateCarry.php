<?php
/**
 * This plugin's own state, carried into the restored tables just before the swap.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Database\SqlWriter;

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
 * row named by one of PREFIXES with the live table's rows (a prefix, not a
 * list: the plugin has no single list of what it stores, and every name it
 * writes starts with one of these), and puts this plugin in the temporary
 * table's list of active plugins. It is a delete and an insert, so running
 * it again gives the same result. guard() checks that list and runs the
 * swap it is given only when this plugin is in it, right after the check.
 * There is no swap yet: the swap unit (a later part of T042) is to call
 * carry(), then guard() with the RENAME, and nothing in between. Today the
 * import uses readable() only.
 *
 * Not carried: the user-level dismissed notices (the users table is
 * replaced and dismissals start over), the plugin's cron events inside the
 * cron option (the running plugin reschedules what it drives), the options
 * of a network's other sites.
 */
final class StateCarry {

	/**
	 * Every name the plugin writes starts with one of these (options, transients, site options, site transients).
	 */
	const PREFIXES = array( 'wpcheckpoint_', '_transient_wpcheckpoint_', '_transient_timeout_wpcheckpoint_', '_site_transient_wpcheckpoint_', '_site_transient_timeout_wpcheckpoint_' );

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
	 * Constructor.
	 *
	 * @param ImportSession $db      Connection.
	 * @param string        $plugin  This plugin's file ("wp-checkpoint/wp-checkpoint.php").
	 * @param int           $network The network's id on multisite, 0 on a single site.
	 */
	public function __construct( ImportSession $db, string $plugin, int $network ) {
		$this->db      = $db;
		$this->plugin  = $plugin;
		$this->network = $network;
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
			$like = self::like_clause( 'option_name' );
			$this->db->rows( 'DELETE FROM ' . SqlWriter::identifier( $temp_options ) . ' WHERE ' . $like['sql'], $like['args'] );
			$this->db->rows( 'INSERT INTO ' . SqlWriter::identifier( $temp_options ) . ' (option_name, option_value, autoload) SELECT option_name, option_value, autoload FROM ' . SqlWriter::identifier( $live_options ) . ' WHERE ' . $like['sql'], $like['args'] );
			if ( null !== $live_meta && null !== $temp_meta ) {
				$like = self::like_clause( 'meta_key' );
				$args = array_merge( array( (string) $this->network ), $like['args'] );
				$this->db->rows( 'DELETE FROM ' . SqlWriter::identifier( $temp_meta ) . ' WHERE site_id = ? AND (' . $like['sql'] . ')', $args );
				$this->db->rows( 'INSERT INTO ' . SqlWriter::identifier( $temp_meta ) . ' (site_id, meta_key, meta_value) SELECT site_id, meta_key, meta_value FROM ' . SqlWriter::identifier( $live_meta ) . ' WHERE site_id = ? AND (' . $like['sql'] . ')', $args );
				$this->activate_network( $temp_meta );
			} else {
				$this->activate( $temp_options );
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
	 * "(column LIKE ? OR ...)" over PREFIXES, their wildcards escaped.
	 *
	 * @param string $column Column.
	 * @return array{sql: string, args: string[]}
	 */
	private static function like_clause( string $column ): array {
		$parts = array();
		$args  = array();
		foreach ( self::PREFIXES as $prefix ) {
			$parts[] = $column . ' LIKE ?';
			$args[]  = addcslashes( $prefix, '\\%_' ) . '%';
		}
		return array(
			'sql'  => implode( ' OR ', $parts ),
			'args' => $args,
		);
	}
}
