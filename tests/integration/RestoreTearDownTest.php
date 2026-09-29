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

	/** @var bool Whether the test that makes the tables ran (in this process, before the one that looks). */
	private static $made = false;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		self::query( 'CREATE TABLE `' . self::NEIGHBOUR . '` ( id int )' );
	}

	public static function tear_down_after_class(): void {
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
			$this->assertTrue( self::exists( $table ), 'the control: ' . $table . ' is there' );
		}
		$this->assertTrue( self::exists( self::NEIGHBOUR ) );
		self::$made = true;
	}

	/**
	 * Runs after the test above (declaration order), not depending on it: a failure there (the leftover check's
	 * too) must not skip the neighbour's assertion here.
	 */
	public function test_its_tear_down_dropped_them_and_left_the_one_that_was_there_before(): void {
		$this->assertTrue( self::$made, 'the control: the tables were made (run the class whole, in declaration order)' );
		foreach ( self::OWN as $table ) {
			$this->assertFalse( self::exists( $table ), $table );
		}
		$this->assertTrue( self::exists( self::NEIGHBOUR ), 'another\'s table is left' );
	}
}
