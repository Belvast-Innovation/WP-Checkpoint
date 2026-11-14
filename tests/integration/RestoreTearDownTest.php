<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Tests\Fixtures\Restore\RestoreTestCase;

/**
 * A restore test's tear-down drops the restore's tables its test made, "wcpold" ones included, and no other: a table
 * of the same names that was there before the test (another test's, or another run's) is left for whoever made it.
 */
final class RestoreTearDownTest extends RestoreTestCase {

	const NEIGHBOUR = 'wcptmpabcdef_1_beef_teardown_neighbour';
	const OWN       = array( 'wcptmpabcdef_2_beef_teardown_own', 'wcpoldabcdef_2_beef_teardown_own' );

	const PLAN_JOB = 987654;

	/** @var bool Whether the test that commits a row of the plan ran. */
	private static $planned = false;

	/** @var bool Whether the test that makes the tables ran (in this process, before the one that looks). */
	private static $made = false;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		self::query( 'CREATE TABLE `' . self::NEIGHBOUR . '` ( id int )' );
	}

	public static function tear_down_after_class(): void {
		\WPCheckpoint\Tests\Fixtures\OpenTransaction::roll_back(); // What the test left uncommitted is not committed by what follows.
		self::query( 'DROP TABLE IF EXISTS `' . self::NEIGHBOUR . '`' );
		parent::tear_down_after_class();
	}

	/**
	 * A statement on the connection itself (a test's CREATE TABLE through wpdb may become a temporary table).
	 */
	private static function query( string $sql ): void {
		global $wpdb;
		mysqli_query( $wpdb->dbh, $sql );
	}

	private static function exists( string $table ): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s', $table ) );
	}

	public function test_a_test_makes_tables_of_its_own(): void {
		foreach ( self::OWN as $table ) {
			self::query( "CREATE TABLE `{$table}` ( id int )" );
		}
		self::$made = true;
		foreach ( self::OWN as $table ) {
			$this->assertTrue( self::exists( $table ), 'the control: ' . $table . ' is there' );
		}
		$this->assertTrue( self::exists( self::NEIGHBOUR ) );
	}

	/**
	 * Runs after the test above, which it depends on so that a run in random order (the daily random-order run)
	 * keeps that order: a failure there skips this one, and the failure there is what the run reports.
	 *
	 * @depends test_a_test_makes_tables_of_its_own
	 */
	public function test_its_tear_down_dropped_them_and_left_the_one_that_was_there_before(): void {
		foreach ( self::OWN as $table ) {
			$this->assertFalse( self::exists( $table ), $table );
		}
		$this->assertTrue( self::exists( self::NEIGHBOUR ), 'another\'s table is left' );
		$this->assertTrue( self::$made, 'the control: the tables were made (run the class whole, in declaration order)' );
	}

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
		self::query( 'INSERT INTO `' . $wpdb->base_prefix . \WPCheckpoint\Restore\SwapPlan::TABLE . "` (job_id, attempt, seq, kind, live, stage, old, had_live) VALUES (" . self::PLAN_JOB . ", 1, 0, 'move', 'a', '', 'b', 0)" );
		self::query( 'COMMIT' );
		self::$planned = true;
		$this->assertSame( 1, self::plan_rows(), 'the control: the row is there' );
	}

	/**
	 * @depends test_a_test_commits_a_row_of_the_swap_plan
	 */
	public function test_its_tear_down_took_the_row_committed(): void {
		self::query( 'ROLLBACK' ); // What is stored, not what this test's transaction sees.
		$this->assertSame( 0, self::plan_rows(), 'left, it is the plan of the next test\'s job of the same id' );
		$this->assertTrue( self::$planned, 'the control: the row was written' );
	}
}
