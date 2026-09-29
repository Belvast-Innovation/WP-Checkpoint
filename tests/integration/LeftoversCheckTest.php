<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Tests\Fixtures\Leftovers;
use WPCheckpoint\Tests\Fixtures\Sandbox;

/**
 * The leftover check (tests/Fixtures/Leftovers.php) sees a real table of the restore's names and a real entry in the
 * temporary directory, and removes them: the control for every test that passes it by leaving nothing.
 */
final class LeftoversCheckTest extends WP_UnitTestCase {

	public function test_it_sees_and_removes_a_real_table_and_a_real_temporary_entry(): void {
		global $wpdb;
		$table = 'wcptmpabcdef_1_beef_leftover_check';
		$other = 'wcpoldabcdef_1_beef_leftover_check';
		$dir   = Sandbox::make( 'leftover-check' );
		foreach ( array( $table, $other ) as $name ) {
			// On the connection itself: through wpdb a test's CREATE TABLE becomes a temporary table.
			$this->assertTrue( mysqli_query( $wpdb->dbh, "CREATE TABLE `{$name}` ( id int )" ), $name );
		}
		$items = Leftovers::listing();
		$this->assertContains( 'table:' . $table, $items );
		$this->assertContains( 'table:' . $other, $items );
		$this->assertContains( 'temp:' . rtrim( sys_get_temp_dir(), '/' ) . '/' . basename( $dir ), $items );

		Leftovers::removal( array( 'table:' . $table, 'table:' . $other, 'temp:' . rtrim( sys_get_temp_dir(), '/' ) . '/' . basename( $dir ) ) );
		$items = Leftovers::listing();
		$this->assertNotContains( 'table:' . $table, $items );
		$this->assertNotContains( 'table:' . $other, $items );
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ( %s, %s )', $table, $other ) ), 'dropped' );
		clearstatcache();
		$this->assertDirectoryDoesNotExist( $dir );
	}
}
