<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Restore\SwapPlan;
use WPCheckpoint\Support\StoredNames;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * A swap that was made and is then put back (the restored site failed its checks; an undo) brings the old options
 * table back. What this plugin wrote into the live options table since the swap (a setting, the storage state) is
 * carried into the old table before it is renamed back (StateCarry::carry_back()), so putting the site back does not
 * put the plugin's state back too. The carry is cut short after each of its statements (on an old table without
 * transactions, so a cut leaves part of it), and the run that finishes the put-back ends with the same state. The
 * self-check row is never carried back.
 */
final class StateCarryBackTest extends SwapTestCase {

	/** What this plugin writes after the swap (I9 of SwapSequencesTest uses the same row). */
	const STATE = 'written since the swap';

	/**
	 * The value of this plugin's own row (StoredNames::DELETE_DATA) in an options table, or null.
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
	 * Write a row into the live options table, as this plugin does after the swap.
	 *
	 * @param string $name  Option name.
	 * @param string $value Value.
	 * @return void
	 */
	private function write_live( string $name, string $value ): void {
		global $wpdb;
		$table = $wpdb->base_prefix . 'options';
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the options table.
		$wpdb->query( $wpdb->prepare( "INSERT INTO `{$table}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the options table.
		$wpdb->query( 'COMMIT' );
	}

	/**
	 * The table the swap moved the live options table to (the plan's entry for it).
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	private function old_options( Job $job ): string {
		global $wpdb;
		foreach ( $this->plan_of( $job )['tables'] as $entry ) {
			if ( SwapPlan::TABLE_OF === $entry['kind'] && $wpdb->base_prefix . 'options' === $entry['live'] ) {
				return (string) $entry['old'];
			}
		}
		$this->fail( 'the control: the plan has an entry for the options table' );
	}

	/**
	 * A swap made (its run killed right after it was recorded as made), this plugin's state written into the live
	 * options table since, and the old options table without transactions; the next check finds the site broken.
	 *
	 * @return Job
	 */
	private function swapped_and_written(): Job {
		global $wpdb;
		$this->forget_state(); // What an earlier round carried back is not this round's.
		$this->swap_parts['batch']  = 1;
		$this->verdicts             = array( 'home', true, true, true, true, true, true, true );
		$this->swap_parts['verify'] = $this->verdicts();
		$this->register_type();
		$this->trace = $this->sandbox . '/trace';
		$job         = $this->at_swap();
		$this->killed_at( $job, 'committed', 1 );
		$old = $this->old_options( $job );
		$this->assertNotNull( $this->value( $old, 'siteurl' ), 'the control: the old options table is there under its moved-aside name' );
		$this->write_live( StoredNames::DELETE_DATA, self::STATE );
		$this->assertNotSame( self::STATE, $this->value( $old ), 'the control: the old table does not have it yet' );
		$this->assertNotFalse( $wpdb->query( "ALTER TABLE `{$old}` ENGINE=MyISAM" ), $wpdb->last_error );
		return $job;
	}

	/**
	 * After the job ended: the swap was put back, the state written since the swap is in the live options table,
	 * and no options table has the self-check row.
	 *
	 * @param Job    $job   Job.
	 * @param string $label For the messages.
	 * @return void
	 */
	private function assert_put_back_with_the_state( Job $job, string $label ): void {
		$done = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::FAILED, $done->status, $label . ': reversed (' . $done->last_error . ')' );
		$this->assertStringContainsString( 'found it broken', (string) $done->last_error, $label );
		$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state, $label . ': the site put back' );
		$this->assertSame( self::STATE, $this->value(), $label . ': the state written since the swap is kept' );
		$this->assertNull( $this->value( '', StoredNames::SELF_CHECK ), $label . ': no self-check row' );
	}

	public function test_a_put_back_cut_short_after_any_statement_of_the_carry_back_keeps_the_state_written_since_the_swap(): void {
		// The whole put-back first: its carry's statements counted.
		$job         = $this->swapped_and_written();
		$this->seams = array();
		$this->cli_run( $job );
		$this->assert_put_back_with_the_state( $job, 'whole' );
		$statements = count( preg_grep( '/^carry_back_\d+$/', $this->seams ) );
		$this->assertGreaterThanOrEqual( 2, $statements, 'the control: the carry back deletes and inserts in at least one statement each (' . implode( ',', $this->seams ) . ')' );
		$this->assertContains( 'carried_back', $this->seams );
		$this->assertLessThan( array_search( 'table_back', $this->seams, true ), array_search( 'carried_back', $this->seams, true ), 'carried back before the first table was renamed back' );
		$this->undo( Plugin::instance()->jobs()->find( $job->id ) );
		$this->tear_down_swap();
		$this->set_up_swap();

		// Killed after each statement of the carry back (0: right after the reverse was recorded), then finished.
		for ( $k = 0; $k <= $statements; $k++ ) {
			$label = 'killed after carry-back statement ' . $k;
			$job   = $this->swapped_and_written();
			file_put_contents( $this->trace, '' );
			$this->killed_at( $job, 0 === $k ? 'reverse_recorded' : 'carry_back_' . $k, 1 );
			$this->assertSame( 'reverse', Plugin::instance()->jobs()->find( $job->id )->cursor['phase'] ?? '', $label . ': the reverse was recorded' );
			$this->seams = array();
			$this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
			$this->assertContains( 'carried_back', $this->seams, $label . ': the finishing run carried back again (both tables still where the swap left them)' );
			$this->assert_put_back_with_the_state( $job, $label );
			$this->undo( Plugin::instance()->jobs()->find( $job->id ) );
			$this->trace = '';
			$this->tear_down_swap();
			$this->set_up_swap();
		}
	}

	public function test_the_self_check_row_is_not_carried_back(): void {
		global $wpdb;
		$job = $this->swapped_and_written();
		// A self-check row in the live table when the put-back carries (none is there in a real run by then: it is
		// deleted before the reverse; written here to see that the carry leaves it out).
		$this->write_live( StoredNames::SELF_CHECK, 'a self-check row' );
		$this->seams = array();
		$this->cli_run( $job );
		$this->assertContains( 'carried_back', $this->seams, 'the control: it carried back' );
		$this->assertSame( self::STATE, $this->value(), 'the control: the plugin\'s state was carried back' );
		$this->assertNull( $this->value( '', StoredNames::SELF_CHECK ), 'the self-check row was not carried into the site as it was' );
		// What the reverse moved aside (the restored options table) still has it: removed here.
		foreach ( (array) $wpdb->get_col( "SHOW TABLES LIKE '%options'" ) as $table ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE option_name = %s", StoredNames::SELF_CHECK ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- an options table.
		}
		$wpdb->query( 'COMMIT' );
		$this->undo( Plugin::instance()->jobs()->find( $job->id ) );
	}

	public function test_an_old_list_of_active_plugins_that_cannot_be_read_does_not_keep_the_site_from_being_put_back(): void {
		global $wpdb;
		$job    = $this->swapped_and_written();
		$old    = $this->old_options( $job );
		$active = $this->value( $old, 'active_plugins' );
		$this->assertNotNull( $active, 'the control: the old site has its list of active plugins' );
		$wpdb->query( $wpdb->prepare( "UPDATE `{$old}` SET option_value = %s WHERE option_name = 'active_plugins'", 'not a list WordPress wrote' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the old options table.
		$wpdb->query( 'COMMIT' );
		try {
			$this->seams = array();
			$this->cli_run( $job );
			$done = Plugin::instance()->jobs()->find( $job->id );
			$this->assertSame( Job::FAILED, $done->status, 'put back (' . $done->last_error . ')' );
			$this->assertStringContainsString( 'found it broken', (string) $done->last_error );
			$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state, 'the site was put back all the same' );
			$this->assertSame( array(), $this->maintenance_files(), 'and its maintenance file is down' );
			$this->assertNotContains( 'carried_back', $this->seams, 'nothing was carried back' );
			$this->assertNotSame( self::STATE, $this->value(), 'the state written since the swap was not carried (said in the log)' );
			$this->assertStringContainsString( 'were not carried back; the site is put back all the same', Plugin::instance()->job_presenter()->log_tail( $done ) );
		} finally {
			// The old table is the live one now: its list put back as it was, for what runs after.
			$wpdb->query( $wpdb->prepare( "UPDATE `{$wpdb->base_prefix}options` SET option_value = %s WHERE option_name = 'active_plugins'", $active ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the options table.
			$wpdb->query( 'COMMIT' );
			$this->undo( Plugin::instance()->jobs()->find( $job->id ) );
		}
	}

	public function test_a_carry_back_that_fails_for_a_moment_is_waited_out_with_the_file_held(): void {
		$job     = $this->swapped_and_written();
		$fails   = 1;
		$connect = static function () use ( &$fails ) {
			return new class( \WPCheckpoint\Restore\ImportSession::open( \WPCheckpoint\Standalone\Credentials::from_wordpress() ), $fails ) implements \WPCheckpoint\Restore\Queries {
				/** @var \WPCheckpoint\Restore\ImportSession */
				private $db;
				/** @var int */
				private $fails;
				public function __construct( $db, int &$fails ) {
					$this->db    = $db;
					$this->fails = &$fails;
				}
				public function run( string $sql ): int {
					return $this->db->run( $sql );
				}
				public function rows( string $sql, array $params = array() ): array {
					if ( $this->fails > 0 && 0 === strpos( $sql, 'INSERT INTO' ) && false !== strpos( $sql, \WPCheckpoint\Jobs\TempTables::OLD_PREFIX ) ) {
						--$this->fails;
						throw new \RuntimeException( 'Lost connection to MySQL server during query' ); // The carry back's first insert, once.
					}
					return $this->db->rows( $sql, $params );
				}
				public function write( string $sql, array $params ): int {
					return $this->db->write( $sql, $params );
				}
			};
		};
		$this->retype( $job, array(), $connect );
		$waiting = $this->cli_run( $job );
		$this->assertSame( 0, $fails, 'the control: the carry back failed once' );
		$this->assertSame( Job::RUNNING, $waiting->status, 'waited out, not failed (' . $waiting->last_error . ')' );
		$this->assertSame( 'reverse', $waiting->cursor['phase'] ?? '', 'still putting the site back' );
		$this->assertTrue( Maintenance::held_in( $this->abspath ), 'its file held meanwhile' );
		$this->assertStringContainsString( 'could not be carried back into the site as it was yet', (string) $waiting->progress_message . (string) $waiting->last_error . Plugin::instance()->job_presenter()->log_tail( $waiting ) );
		$this->seams = array();
		$this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertContains( 'carried_back', $this->seams, 'carried back on the next run' );
		$this->assert_put_back_with_the_state( $job, 'after the wait' );
		$this->undo( Plugin::instance()->jobs()->find( $job->id ) );
	}

	/**
	 * Remove the row this test writes (the plugin's uninstall setting) from the live options table.
	 *
	 * @return void
	 */
	private function forget_state(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->base_prefix}options` WHERE option_name = %s", StoredNames::DELETE_DATA ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the options table.
		$wpdb->query( 'COMMIT' );
	}

	public function tear_down(): void {
		parent::tear_down();
		// After the shared tear-down put the site back: the row is not left behind in whichever table is live then.
		$this->forget_state();
	}
}
