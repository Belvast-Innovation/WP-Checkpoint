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

	public function test_on_the_tests_database_an_earlier_runs_fixture_table_is_named_then_dropped_and_no_table_of_wordpress_or_the_plugin(): void {
		global $wpdb;
		$table = $wpdb->base_prefix . 'swt_leftover_check';
		$this->assertTrue( mysqli_query( $wpdb->dbh, "CREATE TABLE `{$table}` ( id int )" ), $table );
		$said    = array();
		$there   = null;
		$dropped = array();
		try {
			$dropped = Leftovers::start_cleanup(
				$wpdb,
				static function ( string $text ) use ( &$said, &$there, $wpdb, $table ): void {
					$said[] = $text;
					$there  = in_array( $table, (array) $wpdb->get_col( 'SHOW TABLES' ), true );
				}
			);
		} finally {
			mysqli_query( $wpdb->dbh, "DROP TABLE IF EXISTS `{$table}`" );
		}
		$this->assertSame( array( $table ), $dropped, 'dropped: the only one there' );
		$this->assertCount( 1, $said );
		$this->assertStringContainsString( "Dropping the tables an earlier run left that are neither WordPress's nor this plugin's:\n  " . $table, $said[0] );
		$this->assertTrue( $there, 'named while it was still there' );
		$this->assertNotContains( $table, (array) $wpdb->get_col( 'SHOW TABLES' ), 'dropped' );
		foreach ( array( $wpdb->posts, $wpdb->users, $wpdb->options, $wpdb->base_prefix . 'wpcheckpoint_fence', $wpdb->base_prefix . 'wpcheckpoint_swap_plan' ) as $known ) {
			$this->assertContains( $known, (array) $wpdb->get_col( 'SHOW TABLES' ), 'the control: there, and kept' );
		}
	}

	public function test_on_another_database_nothing_is_dropped_and_the_reason_is_said(): void {
		global $wpdb;
		$name = 'wpc_leftovers_not_tests';
		$this->assertTrue( mysqli_query( $wpdb->dbh, "CREATE DATABASE `{$name}`" ), 'a database of this test' );
		try {
			$other = new \wpdb( DB_USER, DB_PASSWORD, $name, DB_HOST );
			$other->set_prefix( $wpdb->base_prefix );
			$this->assertTrue( (bool) $other->query( "CREATE TABLE `{$wpdb->base_prefix}swt_other` ( id int )" ), 'a fixture-like table there' );
			$this->assertSame( array( 'table:' . $wpdb->base_prefix . 'swt_other' ), Leftovers::stray_listing( $other ), 'the control: listed as one an earlier run would leave' );
			$said    = array();
			$dropped = Leftovers::start_cleanup(
				$other,
				static function ( string $text ) use ( &$said ): void {
					$said[] = $text;
				}
			);
			$this->assertSame( array(), $dropped );
			$this->assertCount( 1, $said );
			$this->assertStringContainsString( 'the connection is to the database "' . $name . '", not to wp-env\'s tests database "tests-wordpress". Nothing was dropped.', $said[0] );
			$this->assertContains( $wpdb->base_prefix . 'swt_other', (array) $other->get_col( 'SHOW TABLES' ), 'still there' );
			$other->close();
		} finally {
			mysqli_query( $wpdb->dbh, "DROP DATABASE IF EXISTS `{$name}`" );
		}
	}

	public function test_it_sees_and_removes_a_storage_directory_in_wp_content_other_than_the_stored_one(): void {
		$prefix = WP_CONTENT_DIR . '/' . \WPCheckpoint\Support\Directories::DIR_PREFIX;
		$dir    = $prefix . 'leftovercheck';
		$stored = $prefix . 'leftoverstored';
		try {
			$this->assertTrue( mkdir( $dir . '/tmp', 0755, true ) );
			file_put_contents( $dir . '/tmp/index.php', '<?php' );
			$this->assertTrue( mkdir( $stored ) );
			// A stored state that names $stored, in this test's transaction (read back on the same connection).
			\WPCheckpoint\Support\Options::set( \WPCheckpoint\Support\Directories::OPTION, array_merge( \WPCheckpoint\Support\Directories::load_state(), array( 'path' => $stored ) ) );
			wp_cache_flush();
			$this->assertSame( $stored, \WPCheckpoint\Tests\Fixtures\StorageDirs::stored_path(), 'read from the database' );
			$this->assertSame( $stored, rtrim( (string) \WPCheckpoint\Support\Directories::load_state()['path'], '/' ), 'as the plugin reads it' );
			$items = Leftovers::listing();
			$this->assertContains( 'storage:' . $dir, $items, 'a storage directory that is not the stored one' );
			$this->assertNotContains( 'storage:' . $stored, $items, 'the stored one is the plugin\'s own, kept from test to test' );
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
			} finally {
				\WPCheckpoint\Support\Deleter::replace_roots( $roots );
			}
		} finally {
			clearstatcache();
			\WPCheckpoint\Tests\Fixtures\StorageDirs::remove( array_values( array_filter( array( $dir, $stored ), 'is_dir' ) ) );
		}
		clearstatcache();
		$this->assertDirectoryDoesNotExist( $dir );
		$this->assertDirectoryDoesNotExist( $stored );
	}
}
