<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Database\SqlWriter;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Restore\ClaimLost;
use WPCheckpoint\Restore\ImportSession;
use WPCheckpoint\Restore\Ledger;
use WPCheckpoint\Restore\PluginList;
use WPCheckpoint\Restore\Refused;
use WPCheckpoint\Restore\StateCarry;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Standalone\Connection;
use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\StoredNames;
use WPCheckpoint\Tests\Fixtures\Restore\RestoreTestCase;

/**
 * This plugin's state carried into the imported options (and sitemeta),
 * the guard that runs the swap only when this plugin will still be active,
 * and the two things the import's connection must never do: run a second
 * statement from one call, or let an outdated run move the ledger.
 */
final class RestoreStateCarryTest extends RestoreTestCase {

	/** @var array{0: string, 1: string|null} Temporary options and sitemeta tables. */
	private $temporary;

	/** @var ImportSession */
	private $db;

	private function plugin(): string {
		return plugin_basename( WPCHECKPOINT_FILE );
	}

	/** @var array<string, mixed> Registered values as they were before the test (null: unset). */
	private $saved = array();

	/**
	 * A finished import of this site's options (and sitemeta) whose backup holds its own values of the
	 * plugin's state, and rows named like the plugin's that are not in the registry.
	 */
	private function imported(): void {
		global $wpdb;
		foreach ( array( StoredNames::ESTIMATE, StoredNames::EXPORT_RATE ) as $name ) {
			$this->saved[ $name ] = Options::get( $name, null );
		}
		Options::set( StoredNames::ESTIMATE, 'backup' );
		Options::set( StoredNames::EXPORT_RATE, 'only in the backup' );
		set_site_transient( StoredNames::FOREIGN_TABLES, 'backup', 3600 );
		update_site_option( 'wpcheckpoint_carry_marker', 'backup' );
		update_option( 'wpcheckpoint_user_roles', 'backup' ); // A backup made with the table prefix "wpcheckpoint_".
		set_site_transient( StoredNames::reclaim_message( 7 ), 'backup', 3600 );
		set_site_transient( StoredNames::loopback_job( 99 ), 'only in the backup', 3600 );
		$base = $this->backup( self::site_tables() );
		delete_site_transient( StoredNames::loopback_job( 99 ) );
		Options::set( StoredNames::ESTIMATE, 'live' );
		Options::delete( StoredNames::EXPORT_RATE );
		set_site_transient( StoredNames::FOREIGN_TABLES, 'live', 3600 );
		update_site_option( 'wpcheckpoint_carry_marker', 'live' );
		update_option( 'wpcheckpoint_user_roles', 'live' );
		set_site_transient( StoredNames::reclaim_message( 7 ), 'live', 3600 );
		$job = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$names           = $this->temporary_names( $job );
		$this->temporary = array( $names[ $wpdb->base_prefix . 'options' ], is_multisite() ? $names[ $wpdb->base_prefix . 'sitemeta' ] : null );
		$this->db        = ImportSession::open( Credentials::from_wordpress() );
	}

	public function tear_down(): void {
		if ( null !== $this->db ) {
			$this->db->close();
		}
		foreach ( $this->saved as $name => $value ) {
			if ( null === $value ) {
				Options::delete( $name );
			} else {
				Options::set( $name, $value );
			}
		}
		delete_site_transient( StoredNames::FOREIGN_TABLES );
		delete_site_transient( StoredNames::reclaim_message( 7 ) );
		delete_site_transient( StoredNames::loopback_job( 99 ) );
		delete_site_option( 'wpcheckpoint_carry_marker' );
		delete_option( 'wpcheckpoint_user_roles' );
		parent::tear_down();
	}

	private function carry(): StateCarry {
		return new StateCarry( $this->db, $this->plugin(), is_multisite() ? get_current_network_id() : 0 );
	}

	/**
	 * Rows of a table under the given names (or, with none, under every stored form of the registry): name => value.
	 *
	 * @param string[] $names Names.
	 * @return array<string, string>
	 */
	private function rows_of_names( string $table, array $names = array() ): array {
		$meta  = false !== strpos( $table, 'sitemeta' );
		$names = array() === $names ? StoredNames::stored_forms( $meta ) : $names;
		$rows  = $this->db->rows( 'SELECT ' . ( $meta ? 'meta_key, meta_value' : 'option_name, option_value' ) . ' FROM ' . SqlWriter::identifier( $table ) . ' WHERE ' . ( $meta ? 'meta_key' : 'option_name' ) . ' IN (' . implode( ', ', array_fill( 0, count( $names ), '?' ) ) . ') ORDER BY 1', $names );
		return array_column( $rows, 1, 0 );
	}

	/**
	 * Rows of a table whose names contain "wpcheckpoint", selected without the registry: name => value.
	 *
	 * @return array<string, string>
	 */
	private function rows_like_ours( string $table ): array {
		$meta   = false !== strpos( $table, 'sitemeta' );
		$column = $meta ? 'meta_key' : 'option_name';
		$rows   = $this->db->rows( 'SELECT ' . $column . ', ' . ( $meta ? 'meta_value' : 'option_value' ) . ' FROM ' . SqlWriter::identifier( $table ) . ' WHERE ' . $column . " LIKE '%wpcheckpoint%' ORDER BY 1" );
		return array_diff_key( array_column( $rows, 1, 0 ), array_flip( array( 'wpcheckpoint_carry_marker', 'wpcheckpoint_user_roles' ) ) );
	}

	/**
	 * Live and temporary table pairs: options (a network's main site), and sitemeta on a network.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function tables(): array {
		global $wpdb;
		$pairs = array( array( $wpdb->base_prefix . 'options', $this->temporary[0] ) );
		if ( is_multisite() ) {
			$pairs[] = array( $wpdb->base_prefix . 'sitemeta', (string) $this->temporary[1] );
		}
		return $pairs;
	}

	/**
	 * The temporary list of active plugins (network-wide on multisite).
	 *
	 * @return array<int|string, int|string>
	 */
	private function active(): array {
		$sql = is_multisite()
			? 'SELECT meta_value FROM ' . SqlWriter::identifier( (string) $this->temporary[1] ) . " WHERE meta_key = 'active_sitewide_plugins'"
			: 'SELECT option_value FROM ' . SqlWriter::identifier( $this->temporary[0] ) . " WHERE option_name = 'active_plugins'";
		$rows = $this->db->rows( $sql );
		return array() === $rows ? array() : (array) PluginList::read( (string) $rows[0][0] );
	}

	private function is_active( array $list ): bool {
		return is_multisite() ? array_key_exists( $this->plugin(), $list ) : in_array( $this->plugin(), $list, true );
	}

	public function test_the_plugins_state_is_carried_by_its_exact_names_and_it_is_active_in_the_restored_tables(): void {
		global $wpdb;
		$this->imported();
		$state   = is_multisite() ? (string) $this->temporary[1] : $this->temporary[0];
		$others  = array( 'wpcheckpoint_carry_marker' );
		$before  = $this->rows_of_names( $state );
		$foreign = $this->rows_of_names( $state, $others ) + $this->rows_of_names( $this->temporary[0], array( 'wpcheckpoint_user_roles' ) );
		$this->assertStringContainsString( 'backup', $before[ StoredNames::ESTIMATE ], 'the control: the imported table holds the backup\'s values' );
		$this->assertArrayHasKey( StoredNames::EXPORT_RATE, $before );
		$this->assertCount( 2, $foreign, 'and rows named like the plugin\'s that are not in the registry' );
		$this->assertArrayHasKey( '_site_transient_' . StoredNames::loopback_job( 99 ), $this->rows_like_ours( $state ), 'and a built name only the backup has' );
		foreach ( $foreign as $value ) {
			$this->assertStringContainsString( 'backup', $value );
		}

		$this->carry()->carry( $wpdb->base_prefix . 'options', $this->temporary[0], is_multisite() ? $wpdb->base_prefix . 'sitemeta' : null, $this->temporary[1] );
		foreach ( $this->tables() as $pair ) {
			$this->assertSame( $this->rows_of_names( $pair[0] ), $this->rows_of_names( $pair[1] ), 'every row under a registered name is the live one, and only those: ' . $pair[1] );
		}
		$after = $this->rows_of_names( $state );
		$this->assertStringContainsString( 'live', $after[ StoredNames::ESTIMATE ] );
		$this->assertArrayNotHasKey( StoredNames::EXPORT_RATE, $after, 'a name only the backup has is gone' );
		$this->assertStringContainsString( 'live', $after[ '_site_transient_' . StoredNames::FOREIGN_TABLES ], 'a transient is carried with its value' );
		$built = $this->rows_like_ours( $state );
		$this->assertStringContainsString( 'live', $built[ '_site_transient_' . StoredNames::reclaim_message( 7 ) ], 'a built name is carried' );
		$this->assertArrayNotHasKey( '_site_transient_' . StoredNames::loopback_job( 99 ), $built, 'a built name only the backup has is gone' );
		// Selected by what WordPress wrote, not by the registry: every such live row is registered and carried.
		foreach ( $this->tables() as $pair ) {
			$live = $this->rows_like_ours( $pair[0] );
			foreach ( array_keys( $live ) as $name ) {
				$this->assertTrue( in_array( $name, StoredNames::stored_forms( false !== strpos( $pair[0], 'sitemeta' ) ), true ) || StoredNames::is_built( $name ), 'registered: ' . $name );
			}
			$this->assertSame( $live, $this->rows_like_ours( $pair[1] ), 'carried as it is: ' . $pair[1] );
		}
		$state_live = $this->rows_like_ours( is_multisite() ? $wpdb->base_prefix . 'sitemeta' : $wpdb->base_prefix . 'options' );
		$this->assertNotEmpty( preg_grep( '/_timeout_/', array_keys( $state_live ) ), 'the control: the live rows include a transient\'s expiry' );
		$this->assertSame( $foreign, $this->rows_of_names( $state, $others ) + $this->rows_of_names( $this->temporary[0], array( 'wpcheckpoint_user_roles' ) ), 'the rows outside the registry are the backup\'s' );
		$this->assertTrue( $this->is_active( $this->active() ) );

		$this->carry()->carry( $wpdb->base_prefix . 'options', $this->temporary[0], is_multisite() ? $wpdb->base_prefix . 'sitemeta' : null, $this->temporary[1] );
		foreach ( $this->tables() as $pair ) {
			$this->assertSame( $this->rows_of_names( $pair[0] ), $this->rows_of_names( $pair[1] ), 'again: the same result' );
		}
	}

	/**
	 * The swap runs right after the check and only when it passes: after the carry, this plugin is taken
	 * out of the temporary list of active plugins, and the guard refuses, the swap never runs and every
	 * live table is as it was.
	 */
	public function test_the_swap_does_not_happen_when_this_plugin_would_not_be_active(): void {
		global $wpdb;
		$this->imported();
		$this->carry()->carry( $wpdb->base_prefix . 'options', $this->temporary[0], is_multisite() ? $wpdb->base_prefix . 'sitemeta' : null, $this->temporary[1] );

		$swaps  = 0;
		$swap   = static function () use ( &$swaps ): string {
			++$swaps;
			return 'swapped';
		};
		$result = $this->carry()->guard( $this->temporary[0], $this->temporary[1], $swap );
		$this->assertSame( array( 'swapped', 1 ), array( $result, $swaps ), 'the control: with this plugin active the swap runs' );

		$list = $this->active();
		if ( is_multisite() ) {
			unset( $list[ $this->plugin() ] );
			$this->db->rows( 'UPDATE ' . SqlWriter::identifier( (string) $this->temporary[1] ) . " SET meta_value = ? WHERE meta_key = 'active_sitewide_plugins'", array( PluginList::write( $list ) ) );
		} else {
			$list = array_values( array_diff( $list, array( $this->plugin() ) ) );
			$this->db->rows( 'UPDATE ' . SqlWriter::identifier( $this->temporary[0] ) . " SET option_value = ? WHERE option_name = 'active_plugins'", array( PluginList::write( $list ) ) );
		}
		$before = $this->live_site();
		try {
			$this->carry()->guard( $this->temporary[0], $this->temporary[1], $swap );
			$this->fail( 'refused' );
		} catch ( Refused $e ) {
			$this->assertStringContainsString( 'the swap was not made and the site is as it was', $e->getMessage() );
		}
		$this->assertSame( 1, $swaps, 'the swap did not run' );
		$this->assertSame( $before, $this->live_site() );
	}

	public function test_one_call_never_runs_a_second_statement(): void {
		global $wpdb;
		$this->db = ImportSession::open( Credentials::from_wordpress() );
		$wpdb->query( 'COMMIT' );
		$this->create( $wpdb->base_prefix . 'wpcr_victim', '(`id` int PRIMARY KEY)' );
		// The control: the server runs both statements of one text when a client asks for it (mysqli_multi_query()).
		// PHP's mysqli_real_connect() drops the flag by itself (removing it in Connection::connect() too changes
		// nothing this test sees); what keeps the import to one statement per call is that it only uses mysqli_query().
		$raw = mysqli_init();
		$this->assertTrue( mysqli_real_connect( $raw, DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, null, null, Connection::MULTI_STATEMENTS ) );
		$this->assertTrue( mysqli_multi_query( $raw, 'SELECT 1; INSERT INTO `' . $wpdb->base_prefix . 'wpcr_victim` VALUES (1)' ) );
		// Every result taken and freed before the next, and before the connection closes: a result left unread
		// ends the loop early, and closing then may cut the batch off before the server ran the INSERT.
		do {
			$result = mysqli_store_result( $raw );
			if ( $result instanceof \mysqli_result ) {
				mysqli_free_result( $result );
			}
		} while ( mysqli_more_results( $raw ) && mysqli_next_result( $raw ) );
		$this->assertSame( 0, mysqli_errno( $raw ), 'both statements ran' );
		mysqli_close( $raw );
		$this->assertSame( '1', $this->db->rows( 'SELECT COUNT(*) FROM `' . $wpdb->base_prefix . 'wpcr_victim`' )[0][0] );

		// The import's connection, even with the flag in MYSQL_CLIENT_FLAGS: the text is one statement to the server, a syntax error.
		$flagged = Credentials::from_values(
			array(
				'name'     => DB_NAME,
				'user'     => DB_USER,
				'password' => DB_PASSWORD,
				'host'     => DB_HOST,
				'flags'    => Connection::MULTI_STATEMENTS,
				'prefix'   => $wpdb->base_prefix,
			)
		);
		$session = ImportSession::open( $flagged );
		try {
			$session->run( 'SELECT 1; INSERT INTO `' . $wpdb->base_prefix . 'wpcr_victim` VALUES (2)' );
			$this->fail( 'refused as one statement with a syntax error' );
		} catch ( \WPCheckpoint\Restore\StatementFailed $e ) {
			$this->assertSame( 1064, $e->getCode() );
		} finally {
			$session->close();
		}
		$this->assertSame( '1', $this->db->rows( 'SELECT COUNT(*) FROM `' . $wpdb->base_prefix . 'wpcr_victim`' )[0][0] );
	}

	/**
	 * A chunk's SET NAMES must reach both sides of the connection: sent as a statement it changes only
	 * the server's, and a value escaped for the old character set is read by the server in the new one.
	 */
	public function test_the_character_set_a_chunk_sets_is_the_one_values_are_escaped_for(): void {
		$value   = "\xbf' OR '1'='1";
		$session = ImportSession::open( Credentials::from_wordpress() );
		try {
			// The control: SET NAMES as a statement. The escape before the quote becomes part of a gbk character, the quote ends the string.
			$session->run( 'SET NAMES gbk' );
			try {
				$read = (string) $session->rows( 'SELECT HEX(?)', array( $value ) )[0][0];
			} catch ( \WPCheckpoint\Restore\StatementFailed $e ) {
				$read = 'error ' . $e->getCode(); // The quote ended the string and what followed was not SQL.
			}
			$this->assertNotSame( strtoupper( bin2hex( $value ) ), $read, 'the server did not read the value it was given' );

			$session->names( 'gbk' );
			$this->assertSame( 'gbk', $session->charset() );
			$this->assertSame( strtoupper( bin2hex( $value ) ), (string) $session->rows( 'SELECT HEX(?)', array( $value ) )[0][0], 'the value, exactly' );
		} finally {
			$session->close();
		}
	}

	/**
	 * write() binds its values like rows() and says how many rows the statement changed.
	 */
	public function test_a_write_binds_its_values_and_counts_the_rows_it_changed(): void {
		global $wpdb;
		$this->db = ImportSession::open( Credentials::from_wordpress() );
		$wpdb->query( 'COMMIT' );
		$table = $wpdb->base_prefix . 'wpcr_written';
		$this->create( $table, '(`id` int PRIMARY KEY, `v` varchar(64))' );
		$value = "it's \\ a \"test\"; --";
		$this->assertSame( 1, $this->db->write( 'INSERT INTO `' . $table . '` (`id`, `v`) VALUES (?, ?)', array( '1', $value ) ) );
		$this->assertSame( $value, $this->db->rows( 'SELECT `v` FROM `' . $table . '` WHERE `id` = ?', array( '1' ) )[0][0], 'the value, exactly' );
		$this->assertSame( 1, $this->db->write( 'UPDATE `' . $table . '` SET `v` = ? WHERE `id` = ?', array( 'x', '1' ) ) );
		$this->assertSame( 0, $this->db->write( 'UPDATE `' . $table . '` SET `v` = ? WHERE `id` = ?', array( 'x', '2' ) ), 'no row, none changed' );
	}

	/**
	 * A run claims a table before it works on it; once a newer run has claimed it, the older run can record
	 * nothing: not its CREATE TABLE, not a batch (whose rows roll back with it), not a step of starting
	 * the table over.
	 */
	public function test_a_run_that_lost_its_claim_can_record_nothing_and_its_rows_roll_back(): void {
		global $wpdb;
		$this->db = ImportSession::open( Credentials::from_wordpress() );
		$wpdb->query( 'COMMIT' );
		$ledger_name     = 'wcptmpabcdef_9_0000_';
		$this->created[] = $ledger_name;
		$this->create( $wpdb->base_prefix . 'wpcr_rows', '(`id` int PRIMARY KEY) ENGINE=InnoDB' );
		$other = ImportSession::open( Credentials::from_wordpress() );
		try {
			$old = new Ledger( $this->db, $ledger_name, 'aaaa' );
			$new = new Ledger( $other, $ledger_name, 'bbbb' );

			// Table 0: the old run claims it and creates it; then the new run claims it.
			$this->assertSame( 0, $old->claim( 0 )['chunk'] );
			$old->created( 0, 100, true, '[]' );
			$this->assertSame( 'bbbb', $new->claim( 0 )['holder'] );
			$this->db->begin();
			$this->db->run( 'INSERT INTO `' . $wpdb->base_prefix . 'wpcr_rows` VALUES (1)' );
			try {
				$old->advance( 0, 1, 100, 1, 150, 1 );
				$this->fail( 'the old run stops' );
			} catch ( ClaimLost $e ) {
				$this->db->rollback();
			}
			$this->assertSame( '0', $this->db->rows( 'SELECT COUNT(*) FROM `' . $wpdb->base_prefix . 'wpcr_rows`' )[0][0], 'its rows are gone with the batch' );
			$new->advance( 0, 1, 100, 1, 200, 0 );
			$this->assertSame( 200, $new->get( 0 )['pos'], 'the control: the new holder records' );

			// Table 1: claimed by the old run, not yet created, then claimed by the new one: the old CREATE is not recorded.
			$old->claim( 1 );
			$new->claim( 1 );
			try {
				$old->created( 1, 100, true, '[]' );
				$this->fail( 'the old run stops' );
			} catch ( ClaimLost $e ) {
				$this->assertSame( 0, $new->get( 1 )['chunk'], 'still not created' );
			}
			foreach ( array( 'mark_restarting', 'reset', 'restarted' ) as $step ) {
				try {
					$old->$step( 0 );
					$this->fail( 'the old run stops: ' . $step );
				} catch ( ClaimLost $e ) {
					$this->assertSame( array( 200, false, 0 ), array( $new->get( 0 )['pos'], $new->get( 0 )['restarting'], $new->get( 0 )['restarts'] ), $step );
				}
			}

			// Starting over, by the holder: each step can be repeated, and marking again counts nothing.
			$new->mark_restarting( 0 );
			$new->mark_restarting( 0 );
			$this->assertSame( array( true, 1, 200 ), array( $new->get( 0 )['restarting'], $new->get( 0 )['restarts'], $new->get( 0 )['pos'] ), 'marked once, nothing moved yet' );
			// Every step's statement carries the holder: on a table another run marked, this run moves nothing.
			try {
				$old->reset( 0 );
				$this->fail( 'the old run stops' );
			} catch ( ClaimLost $e ) {
				$this->assertSame( 200, $new->get( 0 )['pos'], 'not reset by the old run' );
			}
			$new->reset( 0 );
			$new->reset( 0 );
			$this->assertSame( array( true, 1, 100, 0 ), array( $new->get( 0 )['restarting'], $new->get( 0 )['chunk'], $new->get( 0 )['pos'], $new->get( 0 )['rows'] ), 'back at the start of its rows, still marked' );
			try {
				$old->restarted( 0 );
				$this->fail( 'the old run stops' );
			} catch ( ClaimLost $e ) {
				$this->assertTrue( $new->get( 0 )['restarting'], 'the mark is not removed by the old run' );
			}
			$new->restarted( 0 );
			$this->assertSame( array( false, 1 ), array( $new->get( 0 )['restarting'], $new->get( 0 )['restarts'] ) );
		} finally {
			$other->close();
		}
	}
}
