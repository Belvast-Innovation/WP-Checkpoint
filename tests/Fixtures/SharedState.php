<?php

namespace WPCheckpoint\Tests\Fixtures;

use WPCheckpoint\Admin\Notices;
use WPCheckpoint\Jobs\Loopback;
use WPCheckpoint\Jobs\UninstallFence;
use WPCheckpoint\Restore\SwapPlan;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Support\StoredNames;

/**
 * The register of state every test shares and must leave at its baseline: what one test leaves committed off it, the
 * tests after it start from, and which of them meets it depends on the order of the run. The listener (Leftovers)
 * asks after each test (and each class): an item off its baseline fails that test by name, and is put back, so the
 * tests after it are not affected. It asks when a run starts too: what an earlier run left is said and put back.
 *
 * An item: what it is about in the plugin ("subject": a class constant, by name), the method here that says how it is
 * off its baseline ("reads": '' when it is at it), and the method here that puts it back ("restores"). An item whose
 * subject or methods are no longer there is stale, and fails the run (stale()): a check of something that is gone
 * passes for ever.
 *
 * Read on the test site's connection itself (not through wpdb's filters) after the test's transaction is over: what
 * the next test would find; written there too, but for the cron option (WordPress's own functions) and the commit. INVENTORY lists every state the plugin owns with its baseline and its item
 * here, or why it has none (the plugin's own option rows: OwnOptions puts them back and says them when the run ends,
 * without failing the test).
 */
final class SharedState {

	/**
	 * The items, by the name a failure says.
	 *
	 * The uninstall fence: its own table what the test site's connection reads (no temporary table of its name over
	 * it), its one row there and open. Left closed, every later swap is refused and every later uninstall finds it
	 * held.
	 */
	const ITEMS = array(
		'the uninstall fence' => array(
			'subject'  => UninstallFence::class . '::TABLE',
			'reads'    => 'fence',
			'restores' => 'open_fence',
		),
		'the jobs table'      => array(
			'subject'  => Schema::class . '::JOBS_TABLE',
			'reads'    => 'jobs',
			'restores' => 'empty_jobs',
		),
		'the swap plan'       => array(
			'subject'  => SwapPlan::class . '::TABLE',
			'reads'    => 'plan',
			'restores' => 'empty_plan',
		),
		'the cron option'     => array(
			'subject'  => Loopback::class . '::HOOK',
			'reads'    => 'cron',
			'restores' => 'clear_cron',
		),
		'the dismissed notices' => array(
			'subject'  => Notices::class . '::USER_META',
			'reads'    => 'dismissed',
			'restores' => 'clear_dismissed',
		),
	);

	/** Why an option row of the plugin's is not an item of the register. */
	const OPTION_ROWS = 'put back as the test found it after every test, and listed with the tests that left it when the run ends (OwnOptions): no later test meets it; not failed test by test';

	/**
	 * The state the plugin owns that outlasts a request, but for its files (the listener's own lists): each table
	 * src/ creates (Database\OwnTables) and each name it stores under (Support\StoredNames: exact names and the
	 * prefixes of built ones, whatever they are stored as), the user meta it removes on uninstall
	 * (Support\Uninstaller::USER_META), and the one option of WordPress's it writes into (the events of its one
	 * cron hook: a second hook scheduled in src/ is not noticed here). For
	 * each: what a test must leave ("baseline"), and the item of the register that holds it to that ("item"), or why
	 * none does ("why"). A table or name added to the plugin and not here fails SharedStateTest.
	 */
	const INVENTORY = array(
		'table:' . Schema::JOBS_TABLE              => array(
			'baseline' => 'its own table what the connection reads; no row',
			'item'     => 'the jobs table',
		),
		'table:' . SwapPlan::TABLE                 => array(
			'baseline' => 'its own table what the connection reads; no row',
			'item'     => 'the swap plan',
		),
		'table:' . UninstallFence::TABLE           => array(
			'baseline' => 'its own table what the connection reads; its one row there, open, no one\'s',
			'item'     => 'the uninstall fence',
		),
		'tables:src/Restore/Ledger.php'            => array(
			'baseline' => 'none there (a restore\'s ledger, under a generated name as its temporary tables)',
			'why'      => 'a table of a generated name left after a test fails it by name and is dropped (Leftovers::tables())',
		),
		'option:cron'                              => array(
			'baseline' => 'no event of the plugin\'s hook in the main site\'s stored cron option',
			'item'     => 'the cron option',
		),
		'usermeta:' . Notices::USER_META           => array(
			'baseline' => 'no row of the key',
			'item'     => 'the dismissed notices',
		),
		'name:' . StoredNames::STORAGE             => array(
			'baseline' => 'the state the run settled before the test, its directory there',
			'why'      => 'put back after every test by its own rule, and a directory left fails the test (StorageDirs)',
		),
		'name:' . StoredNames::VERSION             => array( 'baseline' => 'absent', 'why' => self::OPTION_ROWS ),
		'name:' . StoredNames::DB_VERSION          => array( 'baseline' => 'absent', 'why' => self::OPTION_ROWS ),
		'name:' . StoredNames::DB_UPGRADE_RETRY    => array( 'baseline' => 'absent', 'why' => self::OPTION_ROWS ),
		'name:' . StoredNames::DELETE_DATA         => array( 'baseline' => 'absent', 'why' => self::OPTION_ROWS ),
		'name:' . StoredNames::UNINSTALL_NOTICE    => array( 'baseline' => 'absent', 'why' => self::OPTION_ROWS ),
		'name:' . StoredNames::ESTIMATE            => array( 'baseline' => 'absent', 'why' => self::OPTION_ROWS ),
		'name:' . StoredNames::EXPORT_RATE         => array( 'baseline' => 'absent', 'why' => self::OPTION_ROWS ),
		'name:' . StoredNames::EXPORT_RESULTS      => array( 'baseline' => 'absent', 'why' => self::OPTION_ROWS ),
		'name:' . StoredNames::ENVIRONMENT         => array( 'baseline' => 'absent', 'why' => self::OPTION_ROWS ),
		'name:' . StoredNames::FOREIGN_TABLES      => array( 'baseline' => 'absent', 'why' => self::OPTION_ROWS ),
		'name:' . StoredNames::JOBS_REAPED         => array( 'baseline' => 'absent', 'why' => self::OPTION_ROWS ),
		'name:' . StoredNames::JOBS_PURGED         => array( 'baseline' => 'absent', 'why' => self::OPTION_ROWS ),
		'name:' . StoredNames::JOBS_SWEPT          => array( 'baseline' => 'absent', 'why' => self::OPTION_ROWS ),
		'name:' . StoredNames::LOCK_RECHECK        => array( 'baseline' => 'absent', 'why' => self::OPTION_ROWS ),
		'name:' . StoredNames::LOCK_VERIFY         => array( 'baseline' => 'absent', 'why' => self::OPTION_ROWS ),
		'name:' . StoredNames::AUTO_UPDATE_UNCHECKED => array( 'baseline' => 'absent', 'why' => self::OPTION_ROWS ),
		'name:' . StoredNames::AFTER_SWAP          => array( 'baseline' => 'absent', 'why' => self::OPTION_ROWS ),
		'name:' . StoredNames::SELF_CHECK          => array( 'baseline' => 'absent', 'why' => self::OPTION_ROWS ),
		'built:' . StoredNames::LOOPBACK_TOKEN     => array( 'baseline' => 'none there', 'why' => self::OPTION_ROWS ),
		'built:' . StoredNames::LOOPBACK_JOB       => array( 'baseline' => 'none there', 'why' => self::OPTION_ROWS ),
		'built:' . StoredNames::RECLAIM_MESSAGE    => array( 'baseline' => 'none there', 'why' => self::OPTION_ROWS ),
		'built:' . StoredNames::PROBE              => array( 'baseline' => 'none there', 'why' => self::OPTION_ROWS ),
	);

	/**
	 * What the inventory lacks or holds too much of, against the plugin's own lists; and what in it is not sound.
	 *
	 * @param string[]                             $tables    The tables kept by name, without a prefix.
	 * @param array<string, string>                $sources   The files of src/ that create a table => what (OwnTables::SOURCES).
	 * @param string[]                             $exact     The exact stored names.
	 * @param string[]                             $built     The prefixes of the built names.
	 * @param string[]                             $usermeta  The user meta keys.
	 * @param array<string, array<string, string>> $inventory The inventory.
	 * @param array<string, array<string, string>> $items     The register's items.
	 * @return string[]
	 */
	public static function inventory_problems( array $tables, array $sources, array $exact, array $built, array $usermeta, array $inventory, array $items ): array {
		$expect = array( 'option:cron' );
		foreach ( $tables as $table ) {
			$expect[] = 'table:' . $table;
		}
		$named = 0;
		foreach ( $sources as $source => $what ) {
			// A source that creates a table kept by name is that table's; any other creates tables of generated names.
			if ( false !== strpos( (string) $what, '(names())' ) ) {
				++$named;
				continue;
			}
			$expect[] = 'tables:' . $source;
		}
		$out = array();
		if ( count( $tables ) !== $named ) {
			$out[] = sprintf( '%d table(s) kept by name and %d source(s) said to create one', count( $tables ), $named );
		}
		foreach ( $exact as $name ) {
			$expect[] = 'name:' . $name;
		}
		foreach ( $built as $prefix ) {
			$expect[] = 'built:' . $prefix;
		}
		foreach ( $usermeta as $key ) {
			$expect[] = 'usermeta:' . $key;
		}
		foreach ( array_diff( $expect, array_keys( $inventory ) ) as $key ) {
			$out[] = $key . ': not in the inventory';
		}
		foreach ( array_diff( array_keys( $inventory ), $expect ) as $key ) {
			$out[] = $key . ': in the inventory, and no longer the plugin\'s';
		}
		$held = array();
		foreach ( $inventory as $key => $entry ) {
			if ( '' === (string) ( $entry['baseline'] ?? '' ) ) {
				$out[] = $key . ': no baseline';
			}
			$item = (string) ( $entry['item'] ?? '' );
			$why  = (string) ( $entry['why'] ?? '' );
			if ( ( '' === $item ) === ( '' === $why ) ) {
				$out[] = $key . ': an item of the register, or why none (one of the two)';
			}
			if ( '' !== $item ) {
				if ( ! isset( $items[ $item ] ) ) {
					$out[] = $key . ': its item "' . $item . '" is not in the register';
				}
				$held[] = $item;
			}
		}
		foreach ( array_diff( array_keys( $items ), $held ) as $item ) {
			$out[] = 'the register\'s item "' . $item . '": of nothing in the inventory';
		}
		return $out;
	}

	/** @var int The server's error number for the last statement of on_connection() (0: none). */
	private static $errno = 0;

	/** @var array<string, array<string, string>>|null The items a test put in the register's place (replace()). */
	private static $replaced = null;

	/**
	 * The items the listener asks about: ITEMS, or what a test of the listener put in their place.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function items(): array {
		return null === self::$replaced ? self::ITEMS : self::$replaced;
	}

	/**
	 * Put other items in the register's place (a test of the listener; null: the register again).
	 *
	 * @param array<string, array<string, string>>|null $items The items.
	 * @return void
	 */
	public static function replace( ?array $items ): void {
		self::$replaced = $items;
	}

	/**
	 * The items that are stale, each with why.
	 *
	 * @param array<string, array<string, string>>|null $items The items (null: items()).
	 * @return string[]
	 */
	public static function stale( ?array $items = null ): array {
		$items = null === $items ? self::items() : $items;
		$out   = array();
		foreach ( $items as $name => $item ) {
			$subject = (string) ( $item['subject'] ?? '' );
			if ( '' === $subject || ! defined( $subject ) ) {
				$out[] = sprintf( '%s: its subject %s is not there', $name, '' === $subject ? '(none)' : $subject );
			}
			foreach ( array( 'reads', 'restores' ) as $key ) {
				$method = (string) ( $item[ $key ] ?? '' );
				if ( '' === $method || ! is_callable( array( self::class, $method ) ) ) {
					$out[] = sprintf( '%s: its "%s" method %s is not there', $name, $key, '' === $method ? '(none)' : $method );
				}
			}
		}
		return $out;
	}

	/**
	 * The items off their baseline now: name => how.
	 *
	 * @param array<string, array<string, string>>|null $items The items (null: items()).
	 * @return array<string, string>
	 */
	public static function off_baseline( ?array $items = null ): array {
		$items = null === $items ? self::items() : $items;
		$out   = array();
		foreach ( $items as $name => $item ) {
			if ( ! is_callable( array( self::class, (string) ( $item['reads'] ?? '' ) ) ) ) {
				continue; // Stale: said by stale().
			}
			$how = (string) call_user_func( array( self::class, $item['reads'] ) );
			if ( '' !== $how ) {
				$out[ $name ] = $how;
			}
		}
		return $out;
	}

	/**
	 * Put the named items back at their baseline, committed.
	 *
	 * @param string[]                                  $names Names.
	 * @param array<string, array<string, string>>|null $items The items (null: items()).
	 * @return void
	 */
	public static function restore( array $names, ?array $items = null ): void {
		global $wpdb;
		$items = null === $items ? self::items() : $items;
		foreach ( $names as $name ) {
			if ( isset( $items[ $name ] ) && is_callable( array( self::class, (string) ( $items[ $name ]['restores'] ?? '' ) ) ) ) {
				call_user_func( array( self::class, $items[ $name ]['restores'] ) );
			}
		}
		$wpdb->query( 'COMMIT' );
	}

	/**
	 * A statement on the test site's connection itself: through wpdb a test's CREATE TABLE and DROP TABLE become
	 * those of a temporary table (the core test case's filters), and the fence's base table would not be reached.
	 *
	 * @param string $sql Statement.
	 * @return array<int, array<string, string|null>>|string Its rows (none for a statement without a result), or the error.
	 */
	private static function on_connection( string $sql ) {
		global $wpdb;
		self::$errno = 0;
		try {
			$done = mysqli_query( $wpdb->dbh, $sql );
		} catch ( \mysqli_sql_exception $e ) {
			self::$errno = (int) $e->getCode();
			return '' === $e->getMessage() ? 'failed' : $e->getMessage();
		}
		if ( false === $done ) {
			self::$errno = (int) mysqli_errno( $wpdb->dbh );
			$error       = (string) mysqli_error( $wpdb->dbh );
			return '' === $error ? 'failed' : $error;
		}
		if ( true === $done ) {
			return array();
		}
		$rows = mysqli_fetch_all( $done, MYSQLI_ASSOC );
		mysqli_free_result( $done );
		return $rows;
	}

	/**
	 * Whether a temporary table of the fence's name is on the test site's connection (it hides the fence's own table
	 * from every statement of that connection); null when it cannot be told (no table of the name at all).
	 *
	 * @return bool|null
	 */
	private static function fence_hidden(): ?bool {
		return self::hidden( UninstallFence::name() );
	}

	/**
	 * Whether a temporary table of a name is on the test site's connection; null when no table of the name is.
	 *
	 * @param string $table Table.
	 * @return bool|null
	 */
	private static function hidden( string $table ): ?bool {
		$shown = self::on_connection( 'SHOW CREATE TABLE `' . str_replace( '`', '``', $table ) . '`' );
		if ( ! is_array( $shown ) || ! isset( $shown[0]['Create Table'] ) ) {
			return null;
		}
		return 0 === strpos( (string) $shown[0]['Create Table'], 'CREATE TEMPORARY TABLE' );
	}

	/**
	 * How the uninstall fence is off its baseline, '' when its own table is what the connection reads, and its row is
	 * there and open.
	 *
	 * @return string
	 */
	public static function fence(): string {
		if ( true === self::fence_hidden() ) {
			return 'a temporary table of its name hides it on the test site\'s connection';
		}
		$rows = self::on_connection( 'SELECT * FROM `' . str_replace( '`', '``', UninstallFence::name() ) . '` WHERE id = ' . (int) UninstallFence::ROW );
		if ( ! is_array( $rows ) ) {
			return 'its row cannot be read (' . $rows . ')';
		}
		if ( array() === $rows ) {
			return 'its row is not there';
		}
		$state = (string) ( $rows[0]['state'] ?? '' );
		if ( UninstallFence::OPEN === $state ) {
			return '';
		}
		return sprintf( 'left "%s" (closed_by "%s")', $state, (string) ( $rows[0]['closed_by'] ?? '' ) );
	}

	/**
	 * The uninstall fence's own table and row there, the row open and no one's; temporary tables of its name gone.
	 *
	 * @return void
	 */
	public static function open_fence(): void {
		$name = '`' . str_replace( '`', '``', UninstallFence::name() ) . '`';
		for ( $i = 0; $i < 8 && true === self::fence_hidden(); $i++ ) {
			self::on_connection( 'DROP TEMPORARY TABLE ' . $name );
		}
		if ( ! is_array( self::on_connection( 'SELECT id FROM ' . $name . ' LIMIT 1' ) ) ) {
			// Not there (or not readable): made again. Not otherwise: the statement commits and waits for the table.
			self::on_connection( UninstallFence::create_sql( UninstallFence::name() ) );
		}
		self::on_connection( 'INSERT IGNORE INTO ' . $name . ' (id, state) VALUES (' . (int) UninstallFence::ROW . ", '" . UninstallFence::OPEN . "')" );
		if ( ! is_array( self::on_connection( 'UPDATE ' . $name . " SET state = '" . UninstallFence::OPEN . "', closed_by = '' WHERE id = " . (int) UninstallFence::ROW ) ) ) {
			// A table as version 12 made it has no owner to clear (its shape is the table-shape check's to say).
			self::on_connection( 'UPDATE ' . $name . " SET state = '" . UninstallFence::OPEN . "' WHERE id = " . (int) UninstallFence::ROW );
		}
	}

	/**
	 * How a table that must hold no row is off that: a temporary table of its name over it, or rows in it. A table
	 * that is not there is not said here: the table-shape check (TableShapes) fails the test that took it.
	 *
	 * @param string $table Table.
	 * @return string
	 */
	private static function rows_left( string $table ): string {
		if ( true === self::hidden( $table ) ) {
			return 'a temporary table of its name hides it on the test site\'s connection';
		}
		$rows = self::on_connection( 'SELECT COUNT(*) AS n FROM `' . str_replace( '`', '``', $table ) . '`' );
		if ( ! is_array( $rows ) ) {
			// Not there: the table-shape check's to say. Anything else is not "no row".
			return 1146 === self::$errno ? '' : 'its rows cannot be read (' . $rows . ')';
		}
		if ( ! isset( $rows[0]['n'] ) || 0 === (int) $rows[0]['n'] ) {
			return '';
		}
		return (int) $rows[0]['n'] . ' row(s) left in it';
	}

	/**
	 * A table's own emptied; temporary tables of its name gone.
	 *
	 * @param string $table Table.
	 * @return void
	 */
	private static function emptied( string $table ): void {
		$name = '`' . str_replace( '`', '``', $table ) . '`';
		for ( $i = 0; $i < 8 && true === self::hidden( $table ); $i++ ) {
			self::on_connection( 'DROP TEMPORARY TABLE ' . $name );
		}
		self::on_connection( 'DELETE FROM ' . $name );
	}

	/**
	 * How the jobs table is off its baseline.
	 *
	 * @return string
	 */
	public static function jobs(): string {
		return self::rows_left( Schema::jobs_table() );
	}

	/**
	 * The jobs table emptied.
	 *
	 * @return void
	 */
	public static function empty_jobs(): void {
		self::emptied( Schema::jobs_table() );
	}

	/**
	 * How the swap plan is off its baseline.
	 *
	 * @return string
	 */
	public static function plan(): string {
		global $wpdb;
		return self::rows_left( $wpdb->base_prefix . SwapPlan::TABLE );
	}

	/**
	 * The swap plan emptied.
	 *
	 * @return void
	 */
	public static function empty_plan(): void {
		global $wpdb;
		self::emptied( $wpdb->base_prefix . SwapPlan::TABLE );
	}

	/**
	 * How the main site's stored cron option is off its baseline: events of the plugin's hook in it (counted in
	 * the value as stored; nothing is unserialized).
	 *
	 * @return string
	 */
	public static function cron(): string {
		global $wpdb;
		$rows = self::on_connection( 'SELECT option_value FROM `' . str_replace( '`', '``', $wpdb->base_prefix . 'options' ) . "` WHERE option_name = 'cron'" );
		if ( ! is_array( $rows ) ) {
			return 'it cannot be read (' . $rows . ')';
		}
		if ( ! isset( $rows[0]['option_value'] ) ) {
			return '';
		}
		$events = substr_count( (string) $rows[0]['option_value'], '"' . Loopback::HOOK . '"' );
		return 0 === $events ? '' : $events . ' time(s) with an event of ' . Loopback::HOOK;
	}

	/**
	 * The events of the plugin's hook taken out of the main site's cron option, through WordPress's own functions
	 * (and so through wpdb), with the option's cached values dropped first so that they read the stored one: after
	 * a rollback the cache may say the events are gone that the table still holds, and nothing would be written.
	 *
	 * @return void
	 */
	public static function clear_cron(): void {
		$switched = is_multisite() && ! is_main_site() ? switch_to_blog( get_main_site_id() ) : false;
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'cron', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$cron = _get_cron_array();
		foreach ( (array) $cron as $time => $hooks ) {
			if ( is_array( $hooks ) && isset( $hooks[ Loopback::HOOK ] ) ) {
				unset( $cron[ $time ][ Loopback::HOOK ] );
				if ( array() === $cron[ $time ] ) {
					unset( $cron[ $time ] );
				}
			}
		}
		_set_cron_array( $cron );
		if ( $switched ) {
			restore_current_blog();
		}
	}

	/**
	 * How the user meta of dismissed notices is off its baseline: rows of the key.
	 *
	 * @return string
	 */
	public static function dismissed(): string {
		global $wpdb;
		$rows = self::on_connection( 'SELECT COUNT(*) AS n FROM `' . str_replace( '`', '``', $wpdb->usermeta ) . "` WHERE meta_key = '" . Notices::USER_META . "'" );
		if ( ! is_array( $rows ) ) {
			return 'it cannot be read (' . $rows . ')';
		}
		return 0 === (int) ( $rows[0]['n'] ?? 0 ) ? '' : (int) $rows[0]['n'] . ' row(s) of the key left';
	}

	/**
	 * The rows of the key gone.
	 *
	 * @return void
	 */
	public static function clear_dismissed(): void {
		global $wpdb;
		self::on_connection( 'DELETE FROM `' . str_replace( '`', '``', $wpdb->usermeta ) . "` WHERE meta_key = '" . Notices::USER_META . "'" );
		wp_cache_flush();
	}

	/**
	 * End the transaction reads began on the test site's connection: a table read in one stays locked against a
	 * change of its shape from another connection (the table-shape check's rebuild) until it ends. The listener
	 * calls it last at the end of every test, after all it reads there (its own lists too).
	 *
	 * @return void
	 */
	public static function release(): void {
		self::on_connection( 'ROLLBACK' );
	}
}
