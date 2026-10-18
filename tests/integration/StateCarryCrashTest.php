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
	 * The job's temporary options table (the plan's entry for the live options table).
	 */
	private function temp_options( Job $job ): string {
		global $wpdb;
		foreach ( $this->plan_of( $job )['tables'] as $entry ) {
			if ( SwapPlan::TABLE_OF === $entry['kind'] && $wpdb->prefix . 'options' === $entry['live'] ) {
				return $entry['stage'];
			}
		}
		$this->fail( 'the plan has no entry for the options table' );
	}

	/**
	 * A table without transactions: the carry's statements stay whatever happens to the run.
	 */
	private function without_transactions( string $table ): void {
		global $wpdb;
		$this->assertNotFalse( $wpdb->query( 'ALTER TABLE `' . $table . '` ENGINE=MyISAM' ), $wpdb->last_error );
		$this->assertSame( 'MyISAM', $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) ), 'the control' );
	}

	/**
	 * The live options table's rows as they can be compared between runs: name => value for the backup's rows, the
	 * name alone for the plugin's own rows (their values carry tokens and times) and for what WordPress rewrites.
	 *
	 * @return array<string, string|true>
	 */
	private function comparable(): array {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$db  = ImportSession::open( Credentials::from_wordpress() );
		$own = StateCarry::own_names( $db, $wpdb->prefix . 'options', false, array() );
		$db->close();
		$out = array();
		foreach ( (array) $wpdb->get_results( "SELECT option_name, option_value FROM `{$wpdb->prefix}options` ORDER BY option_name", ARRAY_A ) as $row ) {
			$name = (string) $row['option_name'];
			if ( in_array( $name, $own, true ) || 'cron' === $name || 1 === preg_match( '/^_(site_)?transient_/', $name ) ) {
				$out[ $name ] = true;
			} else {
				$out[ $name ] = (string) $row['option_value'];
			}
		}
		return $out;
	}

	public function test_a_carry_cut_short_after_any_statement_is_finished_by_the_retry_with_the_same_rows(): void {
		$this->swap_parts['batch'] = 1;
		$this->register_type();
		// A whole swap first: the carry's statements counted, its outcome the expectation.
		$job = $this->at_swap();
		$this->without_transactions( $this->temp_options( $job ) );
		$done = $this->cli_run( $job );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$statements = count( preg_grep( '/^carry_\d+$/', $this->seams ) );
		$this->assertGreaterThanOrEqual( 2, $statements, 'the control: the carry deletes and inserts in at least one statement each' );
		$this->assertContains( 'carry_written', $this->seams );
		$expected = $this->comparable();
		$this->assertArrayHasKey( StoredNames::STORAGE, $expected, 'the control: the plugin\'s own state is in the restored options' );
		$this->assertArrayHasKey( 'siteurl', $expected, 'the control: the backup\'s rows are there' );
		$this->undo( Plugin::instance()->jobs()->find( $job->id ) );
		$this->tear_down_swap();
		$this->set_up_swap();

		// Killed after each statement (0: before the first one), the swap rolled back, retried, done: the same rows.
		for ( $k = 0; $k <= $statements; $k++ ) {
			$label = 'killed after carry statement ' . $k;
			$this->swap_parts['batch'] = 1;
			$this->register_type();
			$job = $this->at_swap();
			$this->without_transactions( $this->temp_options( $job ) );
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
