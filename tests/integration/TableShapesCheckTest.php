<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Tests\Fixtures\TableShapes;

/**
 * The control that the table-shape check runs at all: the listener registered in phpunit.xml.dist is watching this
 * test, and read the test site's tables when it started.
 */
final class TableShapesCheckTest extends \WP_UnitTestCase {

	public function test_the_check_is_watching_this_test_and_read_the_tables(): void {
		global $wpdb;
		$this->assertSame( self::class . '::' . __FUNCTION__, TableShapes::watching(), 'the listener in phpunit.xml.dist is on, and watching this test' );
		$this->assertGreaterThanOrEqual( 10, TableShapes::read(), 'it read the test site\'s tables (' . $wpdb->base_prefix . '*) when the test started' );
	}

	/**
	 * What restoring does (TableShapes::RESTORE), on a table of this test's own: a table whose engine and columns were
	 * changed is made again as it was with its rows, and one that is gone is made again empty.
	 */
	public function test_a_changed_table_is_made_again_as_it_was_and_a_gone_one_empty(): void {
		global $wpdb;
		$db    = $wpdb->dbh; // Not through wpdb's query(): the core test case would make the table temporary.
		$table = $wpdb->base_prefix . 'wpcshape_probe';
		$read  = static function () use ( $db, $table ): string {
			return TableShapes::read_shapes( $db, $table )[ $table ] ?? '';
		};
		$rows  = static function () use ( $db, $table ): array {
			$out    = array();
			$result = mysqli_query( $db, "SELECT id, v FROM `{$table}` ORDER BY id" );
			$row    = $result instanceof \mysqli_result ? mysqli_fetch_row( $result ) : null;
			while ( is_array( $row ) ) {
				$out[] = implode( '=', $row );
				$row   = mysqli_fetch_row( $result );
			}
			return $out;
		};
		try {
			$this->assertTrue( mysqli_query( $db, "CREATE TABLE `{$table}` (id int NOT NULL AUTO_INCREMENT, v varchar(20) NOT NULL DEFAULT '', PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" ), mysqli_error( $db ) );
			$this->assertTrue( mysqli_query( $db, "INSERT INTO `{$table}` (v) VALUES ('one'), ('two')" ), mysqli_error( $db ) );
			$was = $read();
			$this->assertNotSame( '', $was, 'the control: its shape read' );
			$this->assertTrue( mysqli_query( $db, "ALTER TABLE `{$table}` ENGINE=MyISAM, ADD COLUMN extra int NULL" ), mysqli_error( $db ) );
			$this->assertNotSame( $was, $read(), 'the control: its shape changed' );
			$this->assertSame( '', TableShapes::remake_one( $db, $table, $was, true ) );
			$this->assertSame( $was, $read(), 'made again as it was' );
			$this->assertSame( array( '1=one', '2=two' ), $rows(), 'with its rows' );
			$this->assertTrue( mysqli_query( $db, "DROP TABLE `{$table}`" ), mysqli_error( $db ) );
			$this->assertSame( '', TableShapes::remake_one( $db, $table, $was, false ) );
			$this->assertSame( $was, $read(), 'a gone table made again' );
			$this->assertSame( array(), $rows(), 'empty' );
		} finally {
			mysqli_query( $db, "DROP TABLE IF EXISTS `{$table}`" );
		}
		$left = mysqli_query( $db, "SHOW TABLES LIKE 'wpcshape\\_%'" );
		$this->assertSame( 0, $left instanceof \mysqli_result ? mysqli_num_rows( $left ) : -1, 'no temporary name of the remake is left' );
	}
}
