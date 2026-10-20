<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\ImportSession;
use WPCheckpoint\Restore\StateCarry;
use WPCheckpoint\Restore\SwapPlan;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Support\StoredNames;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * L-1: the swap's carry of this plugin's state into a temporary options table without transactions (MyISAM), cut
 * short by a kill after any of its statements, leaves some of its rows behind; the next run rolls the swap back,
 * the retry's final check counts the table again, and that count must not see the carry's rows: the plugin's own
 * rows (StateCarry::own_names(), the one function the carry and the count share) are left out of it, and what the
 * import wrote of them was recorded at the first count. The retry then carries again, by name, and the restored
 * site ends with the same rows whichever statement the kill came after. And own_names() takes the plugin's rows,
 * not rows that merely look like them.
 */
final class StateCarryCrashTest extends SwapTestCase {

	/**
	 * The tables the carry writes, live name => how StateCarry::own_names() reads them: the main site's options
	 * table, and on a network its sitemeta table.
	 *
	 * @return array<string, array{sitemeta: bool, site: string[]}>
	 */
	private static function carried(): array {
		global $wpdb;
		return StateCarry::counted( $wpdb->base_prefix, is_multisite() ? get_current_network_id() : 0 );
	}

	/**
	 * The job's temporary tables of the carried ones (the plan's entries for them).
	 *
	 * @return string[]
	 */
	private function temp_tables( Job $job ): array {
		$out = array();
		foreach ( $this->plan_of( $job )['tables'] as $entry ) {
			if ( SwapPlan::TABLE_OF === $entry['kind'] && isset( self::carried()[ $entry['live'] ] ) ) {
				$out[] = $entry['stage'];
			}
		}
		$this->assertCount( count( self::carried() ), $out, 'the control: the plan has an entry for each carried table' );
		return $out;
	}

	/**
	 * Tables without transactions: the carry's statements stay whatever happens to the run.
	 *
	 * @param string[] $tables Tables.
	 */
	private function without_transactions( array $tables ): void {
		global $wpdb;
		foreach ( $tables as $table ) {
			$this->assertNotFalse( $wpdb->query( 'ALTER TABLE `' . $table . '` ENGINE=MyISAM' ), $wpdb->last_error );
			$this->assertSame( 'MyISAM', $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) ), 'the control' );
		}
	}

	/**
	 * The live carried tables' rows as they can be compared between runs: "table:name" => value for the backup's
	 * rows, true for the plugin's own rows (their values carry tokens and times) and for what WordPress rewrites.
	 *
	 * @return array<string, string|true>
	 */
	private function comparable(): array {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$db  = ImportSession::open( Credentials::from_wordpress() );
		$out = array();
		foreach ( self::carried() as $table => $how ) {
			$own    = StateCarry::own_names( $db, $table, $how['sitemeta'], $how['site'] );
			$column = $how['sitemeta'] ? 'meta_key' : 'option_name';
			$value  = $how['sitemeta'] ? 'meta_value' : 'option_value';
			$where  = $how['sitemeta'] ? $wpdb->prepare( ' WHERE site_id = %d', (int) $how['site'][0] ) : '';
			foreach ( (array) $wpdb->get_results( "SELECT {$column} AS n, {$value} AS v FROM `{$table}`{$where} ORDER BY {$column}", ARRAY_A ) as $row ) {
				$name = (string) $row['n'];
				if ( in_array( $name, $own, true ) || 'cron' === $name || 1 === preg_match( '/^_(site_)?transient_/', $name ) ) {
					$out[ $table . ':' . $name ] = true;
				} else {
					$out[ $table . ':' . $name ] = (string) $row['v'];
				}
			}
		}
		$db->close();
		return $out;
	}

	/**
	 * The plugin's own rows that are in the live carried tables now (without the activation rows), "table:name".
	 *
	 * @return string[]
	 */
	private function own_rows_live(): array {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$db  = ImportSession::open( Credentials::from_wordpress() );
		$out = array();
		foreach ( self::carried() as $table => $how ) {
			$column = $how['sitemeta'] ? 'meta_key' : 'option_name';
			$where  = $how['sitemeta'] ? $wpdb->prepare( ' AND site_id = %d', (int) $how['site'][0] ) : '';
			foreach ( array_diff( StateCarry::own_names( $db, $table, $how['sitemeta'], $how['site'] ), array( StateCarry::ACTIVE, StateCarry::ACTIVE_NETWORK ) ) as $name ) {
				if ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM `{$table}` WHERE {$column} = %s{$where} LIMIT 1", $name ) ) ) {
					$out[] = $table . ':' . $name;
				}
			}
		}
		$db->close();
		return $out;
	}

	public function test_a_carry_cut_short_after_any_statement_is_finished_by_the_retry_with_the_same_rows(): void {
		$this->swap_parts['batch'] = 1;
		$this->register_type();
		// A whole swap first: the carry's statements counted, its outcome the expectation.
		$job  = $this->at_swap();
		$live = $this->own_rows_live();
		$this->assertNotSame( array(), $live, 'the control: the live site holds rows of the plugin\'s own' );
		$this->without_transactions( $this->temp_tables( $job ) );
		$done = $this->cli_run( $job );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$statements = count( preg_grep( '/^carry_\d+$/', $this->seams ) );
		$this->assertGreaterThanOrEqual( 2, $statements, 'the control: the carry deletes and inserts in at least one statement each' );
		$this->assertContains( 'carry_written', $this->seams );
		$expected = $this->comparable();
		foreach ( $live as $row ) {
			$this->assertTrue( $expected[ $row ] ?? null, 'the control: the plugin\'s own row ' . $row . ' was carried into the restored site' );
		}
		$this->assertArrayHasKey( $GLOBALS['wpdb']->prefix . 'options:siteurl', $expected, 'the control: the backup\'s rows are there' );
		$this->undo( Plugin::instance()->jobs()->find( $job->id ) );
		$this->tear_down_swap();
		$this->set_up_swap();

		// Killed after each statement (0: before the first one), the swap rolled back, retried, done: the same rows.
		for ( $k = 0; $k <= $statements; $k++ ) {
			$label = 'killed after carry statement ' . $k;
			$this->swap_parts['batch'] = 1;
			$this->register_type();
			$job = $this->at_swap();
			$this->without_transactions( $this->temp_tables( $job ) );
			$this->trace = $this->sandbox . '/trace';
			$this->killed_at( $job, 0 === $k ? 'dir_in' : 'carry_' . $k, 1 );
			$left = Plugin::instance()->jobs()->find( $job->id );
			$this->assertSame( $k > 0 ? 'carry' : 'dirs', $left->cursor['phase'] ?? '', $label . ': the position the killed run left' );
			$this->seams = array();
			$back        = $this->cli_run( $left );
			$this->assertSame( Job::FAILED, $back->status, $label . ': rolled back (' . $back->last_error . ')' );
			$this->assertTrue( $back->retry_useful(), $label . ': ' . $back->last_error );
			$this->assertSame( Job::QUEUED, Plugin::instance()->job_actions()->retry( $job->id )->status, $label );
			$this->seams = array();
			$again       = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
			$this->assertSame( Job::COMPLETED, $again->status, $label . ': the retry counted the table and swapped (' . $again->last_error . ')' );
			$this->assertStringNotContainsString( 'rows where the restore left', (string) $again->last_error, $label );
			$this->assertContains( 'carry_written', $this->seams, $label . ': the retry carried again' );
			$this->assertSame( $expected, $this->comparable(), $label . ': the same rows whichever statement the kill came after' );
			$this->undo( Plugin::instance()->jobs()->find( $job->id ) );
			$this->trace = '';
			$this->tear_down_swap();
			$this->set_up_swap();
		}
	}

	/**
	 * The temporary tables seeded with any subset of the plugin's own rows before the swap, as a multi-row INSERT cut
	 * short on MyISAM leaves them (with values of their own, so that a stale copy is told from a carried one): the
	 * count leaves them out, the carry replaces them, and the restored site ends with the same rows.
	 */
	public function test_the_temporary_tables_seeded_with_any_subset_of_the_plugins_own_rows_end_with_the_same_rows(): void {
		global $wpdb;
		$this->swap_parts['batch'] = 1;
		$this->register_type();
		$job = $this->at_swap();
		$this->without_transactions( $this->temp_tables( $job ) );
		$done = $this->cli_run( $job );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$expected = $this->comparable();
		$this->undo( Plugin::instance()->jobs()->find( $job->id ) );
		$this->tear_down_swap();
		$this->set_up_swap();

		mt_srand( 7 );
		$seeded = 0;
		for ( $round = 0; $round < 6; $round++ ) {
			$label = 'seeded round ' . $round;
			$this->swap_parts['batch'] = 1;
			$this->register_type();
			$job    = $this->at_swap();
			$stages = $this->temp_tables( $job );
			$this->without_transactions( $stages );
			$db = ImportSession::open( Credentials::from_wordpress() );
			$i  = 0;
			foreach ( self::carried() as $live => $how ) {
				$stage  = $stages[ $i++ ];
				$column = $how['sitemeta'] ? 'meta_key' : 'option_name';
				$value  = $how['sitemeta'] ? 'meta_value' : 'option_value';
				$own    = array_values( array_diff( StateCarry::own_names( $db, $live, $how['sitemeta'], $how['site'] ), array( StateCarry::ACTIVE, StateCarry::ACTIVE_NETWORK ) ) );
				// A subset: each own row with probability 1/2 in rounds 0-4, every one in round 5.
				foreach ( $own as $name ) {
					if ( 5 !== $round && 0 === mt_rand( 0, 1 ) ) {
						continue;
					}
					$row = $how['sitemeta'] ? array( 'site_id' => (int) $how['site'][0], $column => $name, $value => 'stale ' . $round ) : array( $column => $name, $value => 'stale ' . $round, 'autoload' => 'no' );
					$wpdb->delete( $stage, $how['sitemeta'] ? array( 'site_id' => (int) $how['site'][0], $column => $name ) : array( $column => $name ) );
					$this->assertNotFalse( $wpdb->insert( $stage, $row ), $label . ': ' . $wpdb->last_error );
					++$seeded;
				}
			}
			$db->close();
			$wpdb->query( 'COMMIT' );
			$done = $this->cli_run( $job );
			$this->assertSame( Job::COMPLETED, $done->status, $label . ': the count left the seeded rows out and the swap went on (' . $done->last_error . ')' );
			$this->assertSame( $expected, $this->comparable(), $label . ': the same rows' );
			foreach ( self::carried() as $live => $how ) {
				$column = $how['sitemeta'] ? 'meta_key' : 'option_name';
				$value  = $how['sitemeta'] ? 'meta_value' : 'option_value';
				$this->assertSame( '0', (string) $wpdb->get_var( "SELECT COUNT(*) FROM `{$live}` WHERE {$value} LIKE 'stale %'" ), $label . ': no stale copy of an own row survives in ' . $live . ' (' . $column . ')' );
			}
			$this->undo( Plugin::instance()->jobs()->find( $job->id ) );
			$this->tear_down_swap();
			$this->set_up_swap();
		}
		$this->assertGreaterThan( 0, $seeded, 'the control: rows were seeded' );
	}

	/**
	 * Two runs counting the same table before either recorded its own rows (a lease that ran out while its run
	 * lived): the one whose record loses takes what the other recorded and goes on, rather than failing the job over
	 * a ledger that is whole. The other run is played by the seam between counting the own rows and recording them.
	 */
	public function test_a_count_whose_ownership_record_loses_to_another_run_takes_that_record_and_goes_on(): void {
		global $wpdb;
		$this->swap_parts['batch'] = 1;
		$ledger                    = null;
		$temp                      = null;
		$number                    = null;
		$raced                     = 0;
		$this->swap_parts['at']    = function ( string $point ) use ( &$ledger, &$temp, &$number, &$raced ): void {
			$this->seams[] = $point;
			if ( 'recount_own_counted' === $point && null !== $ledger && null !== $temp && null !== $number ) {
				// Another run counting the same table records first: the own rows as that run counts them.
				global $wpdb;
				$db    = ImportSession::open( Credentials::from_wordpress() );
				$names = StateCarry::own_names( $db, $temp, false, array() );
				$db->close();
				$owned = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$temp}` WHERE option_name IN (" . implode( ', ', array_fill( 0, count( $names ), '%s' ) ) . ')', $names ) );
				$this->assertSame( 1, $wpdb->query( $wpdb->prepare( "UPDATE `{$ledger}` SET owned = %d WHERE n = %d AND owned < 0", $owned, $number ) ), 'the control: the other run recorded first' );
				$wpdb->query( 'COMMIT' );
				++$raced;
			}
		};
		$this->register_type();
		$job = $this->at_swap();
		// The first count (the final check) recorded the own rows: unset it again, as before any count, and have the
		// options table counted again by the swap's recount, which counts the tables imported without transactions.
		$plan   = \WPCheckpoint\Jobs\RestorePreflightStep::load_plan( \WPCheckpoint\Jobs\Residue::work_dir( $job->storage_path, $job->id ) );
		$ledger = \WPCheckpoint\Jobs\TempTables::ledger( $job->storage_token, $job->id, (string) $plan['random'] );
		foreach ( $plan['plan']->tables() as $table ) {
			if ( $wpdb->prefix . 'options' === $table['table'] ) {
				$number = (int) $table['number'];
				$temp   = (string) $table['temporary'];
			}
		}
		$this->assertNotNull( $number, 'the control: the plan has the options table' );
		$this->assertSame( 1, $wpdb->query( $wpdb->prepare( "UPDATE `{$ledger}` SET owned = -1, transactional = 0 WHERE n = %d", $number ) ), $wpdb->last_error );
		$wpdb->query( 'COMMIT' );
		$done = $this->cli_run( $job );
		$this->assertGreaterThan( 0, $raced, 'the control: the other run recorded between the count and the record (' . $done->status . ': ' . $done->last_error . '; seams ' . implode( ',', $this->seams ) . ')' );
		$this->assertSame( Job::COMPLETED, $done->status, 'the count took the other run\'s record and went on (' . $done->last_error . ')' );
		$this->assertStringNotContainsString( 'recorded its own rows already', (string) $done->last_error );
	}

	public function test_own_names_are_the_plugins_rows_and_nothing_that_merely_looks_like_them(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'swt_own';
		$built = StoredNames::LOOPBACK_TOKEN . str_repeat( 'ab', 32 );
		$this->create( $table, '(option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) NOT NULL, option_value LONGTEXT NOT NULL, autoload VARCHAR(20) NOT NULL DEFAULT \'yes\', UNIQUE KEY option_name (option_name)) ENGINE=InnoDB' );
		foreach ( array( StoredNames::STORAGE, '_transient_timeout_' . StoredNames::VERSION, $built, StoredNames::LOOPBACK_TOKEN . 'not-a-digest', 'wpcheckpoint_user_roles', 'siteurl', 'active_plugins' ) as $name ) {
			$wpdb->insert( $table, array( 'option_name' => $name, 'option_value' => 'x' ) );
		}
		$wpdb->query( 'COMMIT' );
		$db    = ImportSession::open( Credentials::from_wordpress() );
		$names = StateCarry::own_names( $db, $table, false, array() );
		sort( $names );
		$want = array( StoredNames::STORAGE, '_transient_timeout_' . StoredNames::VERSION, 'active_plugins', $built );
		sort( $want );
		$this->assertSame( $want, $names, 'the exact names there, the built name, and the activation row' );
		$wpdb->delete( $table, array( 'option_name' => 'active_plugins' ) );
		$wpdb->query( 'COMMIT' );
		$this->assertContains( 'active_plugins', StateCarry::own_names( $db, $table, false, array() ), 'the activation row is the carry\'s whether or not it is there' );
		$db->close();
		$wpdb->query( "DROP TABLE `{$table}`" );
	}
}
