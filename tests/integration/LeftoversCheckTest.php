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

	public function test_the_registered_check_watches_this_test_in_the_runs_own_temporary_directory(): void {
		$this->assertSame( self::class . '::' . __FUNCTION__, Leftovers::watching(), 'the listener in phpunit.xml.dist is on, and watching this test' );
		$this->assertNotSame( '', Leftovers::run_temp_dir(), 'the run has a temporary directory of its own (bin/test-integration.sh), so its entries are looked at' );
		$this->assertSame( Leftovers::run_temp_dir(), realpath( sys_get_temp_dir() ) );
	}

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
		$this->assertContains( 'temp:' . Leftovers::run_temp_dir() . '/' . basename( $dir ), $items );

		Leftovers::removal( array( 'table:' . $table, 'table:' . $other, 'temp:' . Leftovers::run_temp_dir() . '/' . basename( $dir ) ) );
		$items = Leftovers::listing();
		$this->assertNotContains( 'table:' . $table, $items );
		$this->assertNotContains( 'table:' . $other, $items );
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ( %s, %s )', $table, $other ) ), 'dropped' );
		clearstatcache();
		$this->assertDirectoryDoesNotExist( $dir );
	}

	public function test_it_sees_a_real_fixture_table_an_earlier_run_left_and_no_table_of_wordpress_or_the_plugin(): void {
		global $wpdb;
		$table = $wpdb->base_prefix . 'swt_leftover_check';
		$this->assertTrue( mysqli_query( $wpdb->dbh, "CREATE TABLE `{$table}` ( id int )" ), $table );
		try {
			$strays = Leftovers::stray_listing();
			$this->assertContains( 'table:' . $table, $strays );
			foreach ( array( $wpdb->posts, $wpdb->users, $wpdb->options, $wpdb->base_prefix . 'wpcheckpoint_fence', $wpdb->base_prefix . 'wpcheckpoint_swap_plan' ) as $known ) {
				$this->assertNotContains( 'table:' . $known, $strays, 'the control: a table that is there' );
				$this->assertContains( $known, (array) $wpdb->get_col( 'SHOW TABLES' ), 'the control: it is there' );
			}
		} finally {
			Leftovers::removal( array( 'table:' . $table ) );
		}
		$this->assertNotContains( 'table:' . $table, Leftovers::stray_listing(), 'dropped' );
	}

	public function test_it_sees_and_removes_a_storage_directory_in_wp_content_other_than_the_stored_one(): void {
		$stored = rtrim( (string) \WPCheckpoint\Support\Directories::load_state()['path'], '/' );
		$dir    = WP_CONTENT_DIR . '/' . \WPCheckpoint\Support\Directories::DIR_PREFIX . 'leftovercheck';
		$this->assertTrue( mkdir( $dir . '/tmp', 0755, true ) );
		file_put_contents( $dir . '/tmp/index.php', '<?php' );
		$items = Leftovers::listing();
		$this->assertContains( 'storage:' . $dir, $items, 'a storage directory that is not the stored one' );
		if ( '' !== $stored && is_dir( $stored ) ) {
			$this->assertNotContains( 'storage:' . $stored, $items, 'the stored one is the plugin\'s own, kept from test to test' );
		}
		Leftovers::removal( array( 'storage:' . $dir ) );
		clearstatcache();
		$this->assertDirectoryDoesNotExist( $dir, 'removed, though it has no owner marker' );
		$this->assertNotContains( 'storage:' . $dir, Leftovers::listing() );
		// Registered with the Deleter for that deletion only: the same directory, made again, is refused.
		$this->assertTrue( mkdir( $dir ) );
		$roots = \WPCheckpoint\Support\Deleter::replace_roots( array() );
		\WPCheckpoint\Support\Deleter::replace_roots( $roots );
		try {
			$this->assertNotSame( '', \WPCheckpoint\Support\Deleter::refusal( $dir ), 'not registered any more' );
			\WPCheckpoint\Support\Deleter::allow( $dir );
			$this->assertSame( '', \WPCheckpoint\Support\Deleter::refusal( $dir ), 'the control: registered, it is allowed' );
			\WPCheckpoint\Support\Deleter::delete_tree( dirname( $dir ), $dir );
		} finally {
			\WPCheckpoint\Support\Deleter::replace_roots( $roots );
		}
		clearstatcache();
		$this->assertDirectoryDoesNotExist( $dir );
	}
}
