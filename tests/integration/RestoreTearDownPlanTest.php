<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Tests\Fixtures\Restore\RestoreTestCase;

/**
 * A job test's tear-down (JobTestCase) takes the rows of the swap plan its test's restores committed, committed: it
 * ends in a rollback, and job ids start over with every test.
 *
 * Two tests and no other in the class, so that the second follows the first in any order of the run: another test's
 * tear-down between them could drop a table, which commits.
 */
final class RestoreTearDownPlanTest extends RestoreTestCase {

	const PLAN_JOB = 987654;

	/** @var bool Whether the test that commits a row of the plan ran. */
	private static $planned = false;

	/**
	 * The rows of the swap plan a test's restores committed, as stored (on the connection itself, committed or not).
	 */
	private static function plan_rows(): int {
		global $wpdb;
		$read = mysqli_query( $wpdb->dbh, 'SELECT COUNT(*) FROM `' . $wpdb->base_prefix . \WPCheckpoint\Restore\SwapPlan::TABLE . '` WHERE job_id = ' . self::PLAN_JOB );
		return $read instanceof \mysqli_result ? (int) mysqli_fetch_row( $read )[0] : -1;
	}

	/**
	 * A test that commits a row of the plan and makes no table: nothing its tear-down drops commits for it.
	 */
	public function test_a_test_commits_a_row_of_the_swap_plan(): void {
		global $wpdb;
		mysqli_query( $wpdb->dbh, 'INSERT INTO `' . $wpdb->base_prefix . \WPCheckpoint\Restore\SwapPlan::TABLE . "` (job_id, attempt, seq, kind, live, stage, old, had_live) VALUES (" . self::PLAN_JOB . ", 1, 0, 'move', 'a', '', 'b', 0)" );
		mysqli_query( $wpdb->dbh, 'COMMIT' );
		self::$planned = true;
		$this->assertSame( 1, self::plan_rows(), 'the control: the row is there' );
	}

	/**
	 * @depends test_a_test_commits_a_row_of_the_swap_plan
	 */
	public function test_its_tear_down_took_the_row_committed(): void {
		mysqli_query( $GLOBALS['wpdb']->dbh, 'ROLLBACK' ); // What is stored, not what this test's transaction sees.
		$this->assertSame( 0, self::plan_rows(), 'left, it is the plan of the next test\'s job of the same id' );
		$this->assertTrue( self::$planned, 'the control: the row was written' );
	}
}
