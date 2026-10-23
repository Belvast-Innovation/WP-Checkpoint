<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\ImportSession;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Restore\StateCarry;
use WPCheckpoint\Restore\SwapPlan;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Support\StoredNames;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * A swap that was made and is then put back (the restored site failed its checks; an undo) brings the old options
 * table (and a network's sitemeta) back. What this plugin wrote into the live tables since the swap (a setting, the
 * storage state) is carried into the old ones before they are renamed back (StateCarry::carry_back()), so putting the
 * site back does not put the plugin's state back too. The carry is cut short after each of its statements (on an old
 * table without transactions, so a cut leaves part of it), and the run that finishes the put-back ends with the same
 * rows. The self-check row is never carried back, and the old site's lists of active plugins are left as they are.
 * Putting the site back comes first: a carry that fails for a moment is waited out, one that fails otherwise is
 * left out, and a table under the staging name (the live one is then not the restored one) means no carry.
 */
final class StateCarryBackTest extends SwapTestCase {

	/** What this plugin writes after the swap (I9 of SwapSequencesTest uses the same row). */
	const STATE = 'written since the swap';

	/**
	 * The old site's lists of active plugins when the swap was made: options value, and on a network sitemeta value.
	 *
	 * @var array{options: string|null, meta: string|null}
	 */
	private $lists = array(
		'options' => null,
		'meta'    => null,
	);

	/**
	 * The value of a row in an options table, or null.
	 *
	 * @param string $table Options table ('' for the live one).
	 * @param string $name  Option name.
	 * @return string|null
	 */
	private function value( string $table = '', string $name = StoredNames::DELETE_DATA ) {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$table = '' === $table ? $wpdb->base_prefix . 'options' : $table;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$table}` WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- an options table.
		return is_string( $value ) ? $value : null;
	}

	/**
	 * The value of a row of the network in a sitemeta table, or null.
	 *
	 * @param string $table Sitemeta table ('' for the live one).
	 * @param string $name  Meta key.
	 * @return string|null
	 */
	private function meta( string $table = '', string $name = StoredNames::DELETE_DATA ) {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$table = '' === $table ? $wpdb->base_prefix . 'sitemeta' : $table;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM `{$table}` WHERE site_id = %d AND meta_key = %s", get_current_network_id(), $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a sitemeta table.
		return is_string( $value ) ? $value : null;
	}

	/**
	 * Write a row into the live options table (and on a network the same row into the live sitemeta), as this plugin
	 * does after the swap.
	 *
	 * @param string $name  Name.
	 * @param string $value Value.
	 * @param bool   $meta  Whether the sitemeta row too (a network).
	 * @return void
	 */
	private function write_live( string $name, string $value, bool $meta = true ): void {
		global $wpdb;
		$table = $wpdb->base_prefix . 'options';
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the options table.
		$wpdb->query( $wpdb->prepare( "INSERT INTO `{$table}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the options table.
		if ( $meta && is_multisite() ) {
			$table = $wpdb->base_prefix . 'sitemeta';
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE site_id = %d AND meta_key = %s", get_current_network_id(), $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the sitemeta table.
			$wpdb->query( $wpdb->prepare( "INSERT INTO `{$table}` (site_id, meta_key, meta_value) VALUES (%d, %s, %s)", get_current_network_id(), $name, $value ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the sitemeta table.
		}
		$wpdb->query( 'COMMIT' );
	}

	/**
	 * The plan's entry for a table the swap replaced.
	 *
	 * @param Job    $job   Job.
	 * @param string $table Live name without the prefix ("options", "sitemeta").
	 * @return array<string, mixed>
	 */
	private function plan_entry( Job $job, string $table ): array {
		global $wpdb;
		foreach ( $this->plan_of( $job )['tables'] as $entry ) {
			if ( SwapPlan::TABLE_OF === $entry['kind'] && $wpdb->base_prefix . $table === $entry['live'] ) {
				return $entry;
			}
		}
		$this->fail( 'the control: the plan has an entry for the ' . $table . ' table' );
	}

	/**
	 * Remove the row this test writes (the plugin's uninstall setting) from the live tables.
	 *
	 * @return void
	 */
	private function forget_state(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->base_prefix}options` WHERE option_name = %s", StoredNames::DELETE_DATA ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the options table.
		if ( is_multisite() ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->base_prefix}sitemeta` WHERE meta_key = %s", StoredNames::DELETE_DATA ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the sitemeta table.
		}
		$wpdb->query( 'COMMIT' );
	}

	/**
	 * A swap made (its run killed right after it was recorded as made), this plugin's state written into the live
	 * tables since, the old site's lists of active plugins noted; the next check finds the site broken.
	 *
	 * @param bool $myisam Whether the old options table is made one without transactions.
	 * @return Job
	 */
	private function swapped_and_written( bool $myisam = true ): Job {
		global $wpdb;
		$this->forget_state(); // What an earlier round carried back is not this round's.
		$this->swap_parts['batch']  = 1;
		$this->verdicts             = array( 'home', true, true, true, true, true, true, true );
		$this->swap_parts['verify'] = $this->verdicts();
		$this->register_type();
		$this->trace = $this->sandbox . '/trace';
		$job         = $this->at_swap();
		$this->killed_at( $job, 'committed', 1 );
		$old = (string) $this->plan_entry( $job, 'options' )['old'];
		$this->assertNotNull( $this->value( $old, 'siteurl' ), 'the control: the old options table is there under its moved-aside name' );
		$this->lists = array(
			'options' => $this->value( $old, 'active_plugins' ),
			'meta'    => is_multisite() ? $this->meta( (string) $this->plan_entry( $job, 'sitemeta' )['old'], 'active_sitewide_plugins' ) : null,
		);
		$this->write_live( StoredNames::DELETE_DATA, self::STATE );
		$this->assertNotSame( self::STATE, $this->value( $old ), 'the control: the old table does not have it yet' );
		if ( $myisam ) {
			$this->assertNotFalse( $wpdb->query( "ALTER TABLE `{$old}` ENGINE=MyISAM" ), $wpdb->last_error );
		}
		return $job;
	}

	/**
	 * After the job ended: the swap was put back, its file down, no options table under the live name has the
	 * self-check row, and the old site's lists of active plugins are as they were; and, unless $carried is false, the
	 * state written since the swap is in the live tables.
	 *
	 * @param Job    $job     Job.
	 * @param string $label   For the messages.
	 * @param bool   $carried Whether the state is expected carried back.
	 * @return void
	 */
	private function assert_put_back( Job $job, string $label, bool $carried = true ): void {
		$done = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::FAILED, $done->status, $label . ': reversed (' . $done->last_error . ')' );
		$this->assertStringContainsString( 'found it broken', (string) $done->last_error, $label );
		$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state, $label . ': the site put back' );
		$this->assertSame( array(), $this->maintenance_files(), $label . ': its maintenance file is down' );
		$this->assertNull( $this->value( '', StoredNames::SELF_CHECK ), $label . ': no self-check row' );
		$this->assertSame( $this->lists['options'], $this->value( '', 'active_plugins' ), $label . ': the old site\'s list of active plugins as it was' );
		if ( is_multisite() ) {
			$this->assertSame( $this->lists['meta'], $this->meta( '', 'active_sitewide_plugins' ), $label . ': the network\'s list as it was' );
		}
		if ( $carried ) {
			$this->assertSame( self::STATE, $this->value(), $label . ': the state written since the swap is kept' );
			if ( is_multisite() ) {
				$this->assertSame( self::STATE, $this->meta(), $label . ': in the network\'s sitemeta too' );
			}
		} else {
			$this->assertNotSame( self::STATE, $this->value(), $label . ': the state written since the swap was not carried' );
		}
	}

	/**
	 * The names of this plugin's own rows in an options table (and a sitemeta table), "o:name" / "m:name": the exact
	 * names only (a transient may be deleted by a run on its own), without the lists of active plugins.
	 *
	 * @param string      $options Options table.
	 * @param string|null $meta    Sitemeta table, or null.
	 * @return string[]
	 */
	private function own_names_in( string $options, $meta ): array {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$db    = ImportSession::open( Credentials::from_wordpress() );
		$out   = array();
		$tables = array( 'o' => array( $options, false, array() ) );
		if ( null !== $meta ) {
			$tables['m'] = array( $meta, true, array( (string) get_current_network_id() ) );
		}
		foreach ( $tables as $key => $how ) {
			foreach ( StateCarry::own_names( $db, $how[0], $how[1], $how[2] ) as $name ) {
				if ( in_array( $name, array( StateCarry::ACTIVE, StateCarry::ACTIVE_NETWORK ), true ) || 1 === preg_match( '/^_(site_)?transient_/', $name ) ) {
					continue;
				}
				$column = $how[1] ? 'meta_key' : 'option_name';
				$site   = $how[1] ? $wpdb->prepare( ' AND site_id = %d', get_current_network_id() ) : '';
				if ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM `{$how[0]}` WHERE {$column} = %s{$site} LIMIT 1", $name ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a carried table and its column.
					$out[] = $key . ':' . $name;
				}
			}
		}
		$db->close();
		sort( $out );
		return $out;
	}

	/**
	 * The old tables of a job (options, and on a network sitemeta).
	 *
	 * @param Job $job Job.
	 * @return array{0: string, 1: string|null}
	 */
	private function old_tables( Job $job ): array {
		return array( (string) $this->plan_entry( $job, 'options' )['old'], is_multisite() ? (string) $this->plan_entry( $job, 'sitemeta' )['old'] : null );
	}

	/**
	 * A connection that throws on the carry back's $nth statement into an old table (REPLACE or DELETE), once.
	 *
	 * @param int        $nth   Which statement (from 1).
	 * @param int        $seen  Statements seen (counted).
	 * @param \Throwable $error What it throws.
	 * @return callable
	 */
	private static function failing_at( int $nth, int &$seen, \Throwable $error ): callable {
		return static function () use ( $nth, &$seen, $error ) {
			return new class( ImportSession::open( Credentials::from_wordpress() ), $nth, $seen, $error ) implements \WPCheckpoint\Restore\Queries {
				/** @var ImportSession */
				private $db;
				/** @var int */
				private $nth;
				/** @var int */
				private $seen;
				/** @var \Throwable */
				private $error;
				public function __construct( $db, int $nth, int &$seen, \Throwable $error ) {
					$this->db    = $db;
					$this->nth   = $nth;
					$this->seen  = &$seen;
					$this->error = $error;
				}
				/**
				 * Count a statement that writes into an old table, however it is sent, and fail the nth.
				 *
				 * @param string $sql Statement.
				 * @return void
				 */
				private function count( string $sql ): void {
					if ( 1 === preg_match( '/^(REPLACE|INSERT|DELETE|UPDATE)\b/', $sql ) && false !== strpos( $sql, \WPCheckpoint\Jobs\TempTables::OLD_PREFIX ) && ++$this->seen === $this->nth ) {
						throw $this->error;
					}
				}
				public function run( string $sql ): int {
					$this->count( $sql );
					return $this->db->run( $sql );
				}
				public function rows( string $sql, array $params = array() ): array {
					$this->count( $sql );
					return $this->db->rows( $sql, $params );
				}
				public function write( string $sql, array $params ): int {
					$this->count( $sql );
					return $this->db->write( $sql, $params );
				}
			};
		};
	}

	/**
	 * The invariant of the carry back, whatever cut it or failed it: the site put back, and every own name the old
	 * site had is there, under the live name.
	 *
	 * @param Job      $job    Job.
	 * @param string[] $before The old site's own names before the carry (own_names_in()).
	 * @param string   $label  For the messages.
	 * @param bool     $state  Whether the state written since the swap is expected carried.
	 * @return void
	 */
	private function assert_nothing_missing( Job $job, array $before, string $label, bool $state ): void {
		global $wpdb;
		$this->assert_put_back( $job, $label, $state );
		$after = $this->own_names_in( $wpdb->base_prefix . 'options', is_multisite() ? $wpdb->base_prefix . 'sitemeta' : null );
		$this->assertSame( array(), array_values( array_diff( $before, $after ) ), $label . ': no own row of the old site is missing' );
	}

	public function test_after_any_statement_of_the_carry_back_is_cut_or_fails_no_row_of_the_plugin_is_missing(): void {
		global $wpdb;
		// The whole put-back first: its carry's statements counted, the old site's own names noted.
		$job              = $this->swapped_and_written();
		list( $o, $m )    = $this->old_tables( $job );
		$before           = $this->own_names_in( $o, $m );
		$this->seams      = array();
		$this->cli_run( $job );
		$this->assert_nothing_missing( $job, $before, 'whole', true );
		$statements = count( preg_grep( '/^carry_back_\d+$/', $this->seams ) );
		$this->assertGreaterThanOrEqual( 2, $statements, 'the control: the carry back replaces and deletes in at least one statement each (' . implode( ',', $this->seams ) . ')' );
		$this->assertContains( 'o:' . StoredNames::STORAGE, $before, 'the control: the old site has rows of the plugin\'s own (its storage state)' );
		$this->assertLessThan( array_search( 'table_back', $this->seams, true ), array_search( 'carried_back', $this->seams, true ), 'carried back before the first table was renamed back' );
		$this->undo( Plugin::instance()->jobs()->find( $job->id ) );
		$this->tear_down_swap();
		$this->set_up_swap();

		$runs = 0;
		foreach ( array( 'MyISAM', 'InnoDB' ) as $engine ) {
			for ( $k = 0; $k <= $statements; $k++ ) {
				foreach ( array( 'killed', 'failed' ) as $how ) {
					if ( 'failed' === $how && 0 === $k ) {
						continue; // A failure is at a statement.
					}
					$label         = sprintf( '%s old table, %s at carry-back statement %d', $engine, $how, $k );
					$job           = $this->swapped_and_written( 'MyISAM' === $engine );
					list( $o, $m ) = $this->old_tables( $job );
					if ( 'MyISAM' === $engine && null !== $m ) {
						$this->assertNotFalse( $wpdb->query( "ALTER TABLE `{$m}` ENGINE=MyISAM" ), $wpdb->last_error );
					}
					$before = $this->own_names_in( $o, $m );
					if ( 'killed' === $how ) {
						file_put_contents( $this->trace, '' );
						$this->killed_at( $job, 0 === $k ? 'reverse_recorded' : 'carry_back_' . $k, 1 );
						$this->seams = array();
						$this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
						$this->assertContains( 'carried_back', $this->seams, $label . ': the finishing run carried back again' );
						$this->assert_nothing_missing( $job, $before, $label, true );
					} else {
						$seen = 0;
						$this->retype( $job, array(), self::failing_at( $k, $seen, new \RuntimeException( 'Table is marked as crashed and should be repaired' ) ) );
						$this->seams = array();
						$this->cli_run( $job );
						$this->assertGreaterThanOrEqual( $k, $seen, $label . ': the control, the statement was reached' );
						$this->assertNotContains( 'carried_back', $this->seams, $label . ': the carry failed' );
						$this->nothing_missing_whatever_the_state( $job, $before, $label );
					}
					++$runs;
					$this->undo( Plugin::instance()->jobs()->find( $job->id ) );
					$this->trace = '';
					$this->tear_down_swap();
					$this->set_up_swap();
				}
			}
		}
		$this->assertSame( 2 * ( 2 * $statements + 1 ), $runs, 'the control: every statement cut and failed, on both engines' );
	}

	/**
	 * assert_nothing_missing() for a carry that failed part-way: the state written since the swap may or may not have
	 * been carried before the failure.
	 *
	 * @param Job      $job    Job.
	 * @param string[] $before The old site's own names before the carry.
	 * @param string   $label  For the messages.
	 * @return void
	 */
	private function nothing_missing_whatever_the_state( Job $job, array $before, string $label ): void {
		$this->assert_nothing_missing( $job, $before, $label, self::STATE === $this->value() );
		$this->assertStringContainsString( 'the site is put back all the same', Plugin::instance()->job_presenter()->log_tail( Plugin::instance()->jobs()->find( $job->id ) ), $label . ': the failure is said' );
	}

	public function test_the_self_check_row_is_not_carried_back(): void {
		global $wpdb;
		$job = $this->swapped_and_written();
		// Killed once the reverse was recorded: the check that wrote and deleted the self-check row is over. Then a
		// self-check row in the live table when the next run carries back (none is there in a real run by then;
		// written here to see that the carry leaves it out).
		file_put_contents( $this->trace, '' );
		$this->killed_at( $job, 'reverse_recorded', 1 );
		$this->write_live( StoredNames::SELF_CHECK, 'a self-check row', false );
		$this->assertSame( 'a self-check row', $this->value( '', StoredNames::SELF_CHECK ), 'the control: the row is in the live table before the carry' );
		$this->seams = array();
		$this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertContains( 'carried_back', $this->seams, 'the control: it carried back' );
		$this->assert_put_back( $job, 'self-check row' );
		// What the reverse moved aside (the restored options table) still has it: removed here.
		foreach ( (array) $wpdb->get_col( "SHOW TABLES LIKE '%options'" ) as $table ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE option_name = %s", StoredNames::SELF_CHECK ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- an options table.
		}
		$wpdb->query( 'COMMIT' );
		$this->undo( Plugin::instance()->jobs()->find( $job->id ) );
	}

	public function test_an_old_list_of_active_plugins_that_cannot_be_read_is_left_as_it_is_and_the_rows_are_carried(): void {
		global $wpdb;
		$job    = $this->swapped_and_written();
		$old    = (string) $this->plan_entry( $job, 'options' )['old'];
		$active = $this->value( $old, 'active_plugins' );
		$this->assertNotNull( $active, 'the control: the old site has its list of active plugins' );
		$wpdb->query( $wpdb->prepare( "UPDATE `{$old}` SET option_value = %s WHERE option_name = 'active_plugins'", 'not a list WordPress wrote' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the old options table.
		$wpdb->query( 'COMMIT' );
		$this->lists['options'] = 'not a list WordPress wrote';
		try {
			$this->seams = array();
			$this->cli_run( $job );
			$this->assertContains( 'carried_back', $this->seams, 'the rows were carried back' );
			$this->assert_put_back( $job, 'old list that cannot be read' );
		} finally {
			// The list put back as it was wherever it is now, for what runs after.
			$this->undo( Plugin::instance()->jobs()->find( $job->id ) );
			foreach ( (array) $wpdb->get_col( "SHOW TABLES LIKE '%options'" ) as $table ) {
				$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET option_value = %s WHERE option_name = 'active_plugins' AND option_value = 'not a list WordPress wrote'", $active ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- an options table.
			}
			$wpdb->query( 'COMMIT' );
		}
	}

	/**
	 * A connection that throws on the carry back's first insert, once.
	 *
	 * @param int        $fails How many times (decreased).
	 * @param \Throwable $error What it throws.
	 * @return callable
	 */
	private static function failing_insert( int &$fails, \Throwable $error ): callable {
		return static function () use ( &$fails, $error ) {
			return new class( ImportSession::open( Credentials::from_wordpress() ), $fails, $error ) implements \WPCheckpoint\Restore\Queries {
				/** @var ImportSession */
				private $db;
				/** @var int */
				private $fails;
				/** @var \Throwable */
				private $error;
				public function __construct( $db, int &$fails, \Throwable $error ) {
					$this->db    = $db;
					$this->fails = &$fails;
					$this->error = $error;
				}
				public function run( string $sql ): int {
					return $this->db->run( $sql );
				}
				public function rows( string $sql, array $params = array() ): array {
					if ( $this->fails > 0 && 0 === strpos( $sql, 'INSERT INTO' ) && false !== strpos( $sql, \WPCheckpoint\Jobs\TempTables::OLD_PREFIX ) ) {
						--$this->fails;
						throw $this->error;
					}
					return $this->db->rows( $sql, $params );
				}
				public function write( string $sql, array $params ): int {
					return $this->db->write( $sql, $params );
				}
			};
		};
	}

	public function test_a_carry_back_that_fails_for_a_moment_is_waited_out_with_the_file_held(): void {
		$job   = $this->swapped_and_written();
		$fails = 1;
		// As the connection raises it for a lost connection, a lock wait or a deadlock.
		$this->retype( $job, array(), self::failing_insert( $fails, new \WPCheckpoint\Jobs\TransientFailure( 'Lost connection to MySQL server during query', 2013 ) ) );
		$waiting = $this->cli_run( $job );
		$this->assertSame( 0, $fails, 'the control: the carry back failed once' );
		$this->assertSame( Job::RUNNING, $waiting->status, 'waited out, not failed (' . $waiting->last_error . ')' );
		$this->assertSame( 'reverse', $waiting->cursor['phase'] ?? '', 'still putting the site back' );
		$this->assertTrue( Maintenance::held_in( $this->abspath ), 'its file held meanwhile' );
		$this->seams = array();
		$this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertContains( 'carried_back', $this->seams, 'carried back on the next run' );
		$this->assert_put_back( $job, 'after the wait' );
		$this->undo( Plugin::instance()->jobs()->find( $job->id ) );
	}

	public function test_a_carry_back_that_fails_otherwise_does_not_keep_the_site_from_being_put_back(): void {
		$job   = $this->swapped_and_written( false ); // An old table with transactions: the failed carry leaves nothing.
		$fails = 1;
		$this->retype( $job, array(), self::failing_insert( $fails, new \RuntimeException( 'Table is marked as crashed and should be repaired' ) ) );
		$this->seams = array();
		$this->cli_run( $job );
		$this->assertSame( 0, $fails, 'the control: the carry back failed' );
		$this->assertNotContains( 'carried_back', $this->seams, 'not carried' );
		$this->assert_put_back( $job, 'failed otherwise', false );
		$this->assertStringContainsString( 'were not carried back; the site is put back all the same', Plugin::instance()->job_presenter()->log_tail( Plugin::instance()->jobs()->find( $job->id ) ) );
		$this->undo( Plugin::instance()->jobs()->find( $job->id ) );
	}

	public function test_with_a_table_under_the_staging_name_too_nothing_is_carried(): void {
		global $wpdb;
		$job   = $this->swapped_and_written();
		$entry = $this->plan_entry( $job, 'options' );
		$stage = (string) $entry['stage'];
		// Something made a table under the staging name since the swap: the one under the live name is then not known
		// to be the restored one (the put-back moves it out of the way).
		$this->assertNotFalse( $wpdb->query( "CREATE TABLE `{$stage}` LIKE `{$wpdb->base_prefix}options`" ), $wpdb->last_error );
		$wpdb->query( 'COMMIT' );
		try {
			$this->seams = array();
			$this->cli_run( $job );
			$this->assertNotContains( 'carried_back', $this->seams, 'nothing carried' );
			$this->assertContains( 'table_back', $this->seams, 'the control: the put-back renamed tables back' );
			$this->assertNotSame( self::STATE, $this->value(), 'the state under the live name was not carried into the old table' );
		} finally {
			$this->undo( Plugin::instance()->jobs()->find( $job->id ) );
			$wpdb->query( "DROP TABLE IF EXISTS `{$stage}`" );
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( \WPCheckpoint\Jobs\TempTables::STRAY_PREFIX ) . '%' ) ) as $table ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // What the put-back moved out of the way.
			}
			$wpdb->query( 'COMMIT' );
		}
	}

	public function tear_down(): void {
		parent::tear_down();
		// After the shared tear-down put the site back: the row is not left behind in whichever table is live then.
		$this->forget_state();
	}
}
