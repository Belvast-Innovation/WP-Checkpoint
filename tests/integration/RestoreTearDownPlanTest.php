<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Tests\Fixtures\Restore\RestoreTestCase;

/**
 * A job test's tear-down (JobTestCase) takes the rows of the swap plan and the events of the plugin's cron hook its
 * test committed, committed: it ends in a rollback, and job ids start over with every test.
 *
 * What is stored is read right after the tear-down, before the listener (Leftovers) looks: it would put the same
 * back, and fail the test by name.
 */
final class RestoreTearDownPlanTest extends RestoreTestCase {

	const PLAN_JOB = 987654;

	/** @var bool Whether the test that commits a row of the plan ran. */
	private static $planned = false;

	/** @var array{0: int, 1: string}|null The plan's rows and the cron option (SharedState::cron()) after the first test's tear-down. */
	private static $left = null;

	public function tear_down(): void {
		parent::tear_down();
		if ( 'test_a_test_commits_a_row_of_the_swap_plan_and_an_event_of_the_hook' === $this->getName( false ) ) {
			self::$left = array( self::plan_rows(), \WPCheckpoint\Tests\Fixtures\SharedState::cron() );
		}
	}

	/**
	 * The rows of the swap plan a test's restores committed, as stored (on the connection itself, committed or not).
	 */
	private static function plan_rows(): int {
		global $wpdb;
		$read = mysqli_query( $wpdb->dbh, 'SELECT COUNT(*) FROM `' . $wpdb->base_prefix . \WPCheckpoint\Restore\SwapPlan::TABLE . '` WHERE job_id = ' . self::PLAN_JOB );
		return $read instanceof \mysqli_result ? (int) mysqli_fetch_row( $read )[0] : -1;
	}

	public function test_a_test_commits_a_row_of_the_swap_plan_and_an_event_of_the_hook(): void {
		global $wpdb;
		mysqli_query( $wpdb->dbh, 'INSERT INTO `' . $wpdb->base_prefix . \WPCheckpoint\Restore\SwapPlan::TABLE . "` (job_id, attempt, seq, kind, live, stage, old, had_live) VALUES (" . self::PLAN_JOB . ", 1, 0, 'move', 'a', '', 'b', 0)" );
		wp_schedule_single_event( 2000000000, \WPCheckpoint\Jobs\Loopback::HOOK, array( self::PLAN_JOB ) );
		mysqli_query( $wpdb->dbh, 'COMMIT' );
		self::$planned = true;
		$this->assertSame( 1, self::plan_rows(), 'the control: the row is there' );
		$this->assertSame( '1 time(s) with an event of ' . \WPCheckpoint\Jobs\Loopback::HOOK, \WPCheckpoint\Tests\Fixtures\SharedState::cron(), 'the control: so is the event' );
	}

	/**
	 * @depends test_a_test_commits_a_row_of_the_swap_plan_and_an_event_of_the_hook
	 */
	public function test_its_tear_down_took_them_committed(): void {
		$this->assertTrue( self::$planned, 'the control: the row and the event were written' );
		$this->assertSame( array( 0, '' ), self::$left, 'left, they are the plan and the event of the next test\'s job of the same id' );
	}
}
