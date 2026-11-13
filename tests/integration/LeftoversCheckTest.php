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
		// A temporary table of the same form (what the core test case makes through wpdb) is gone with the connection.
		$temp = 'wcptmpabcdef_1_beef_leftover_temp';
		$this->assertTrue( mysqli_query( $wpdb->dbh, "CREATE TEMPORARY TABLE `{$temp}` ( id int )" ), $temp );
		$items = Leftovers::listing();
		mysqli_query( $wpdb->dbh, "DROP TEMPORARY TABLE IF EXISTS `{$temp}`" );
		$this->assertNotContains( 'table:' . $temp, $items, 'a temporary table is not listed' );
		$this->assertContains( 'table:' . $table, $items, 'the control: a base table of the same form is' );
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

	public function test_the_plugins_option_rows_a_test_committed_are_put_back_as_they_were(): void {
		global $wpdb;
		$name   = 'wpcheckpoint_leftover_check';
		$before = \WPCheckpoint\Tests\Fixtures\OwnOptions::note();
		$this->assertArrayNotHasKey( 'o:' . $name, $before, 'the control: not there before' );
		// Committed, as a row is that a test wrote before a statement that commits.
		$wpdb->query( $wpdb->prepare( "INSERT INTO `{$wpdb->base_prefix}options` (option_name, option_value, autoload) VALUES (%s, 'left', 'no')", $name ) );
		$expect = array( 'added ' . $name );
		if ( is_multisite() ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->sitemeta} (site_id, meta_key, meta_value) VALUES (%d, %s, 'left')", get_current_network_id(), $name . '_network' ) );
			$expect[] = 'added ' . $name . '_network';
		}
		$wpdb->query( 'COMMIT' );
		try {
			$this->assertSame( $expect, \WPCheckpoint\Tests\Fixtures\OwnOptions::differences( $before, \WPCheckpoint\Tests\Fixtures\OwnOptions::note() ), 'the control: the rows are read' );
			$this->assertSame( $expect, \WPCheckpoint\Tests\Fixtures\OwnOptions::put_back( $before, 'the check of the put-back (LeftoversCheckTest)' ) );
			$this->assertSame( $before, \WPCheckpoint\Tests\Fixtures\OwnOptions::note(), 'as they were' );
			$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->base_prefix}options` WHERE option_name = %s", $name ) ) );
			$this->assertSame( array(), \WPCheckpoint\Tests\Fixtures\OwnOptions::put_back( $before, 'nothing to put back' ), 'and nothing more to put back' );
			$this->assertArrayHasKey( 'the check of the put-back (LeftoversCheckTest)', \WPCheckpoint\Tests\Fixtures\OwnOptions::summary(), 'said when the run ends' );
			$this->assertArrayNotHasKey( 'nothing to put back', \WPCheckpoint\Tests\Fixtures\OwnOptions::summary() );
		} finally {
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->base_prefix}options` WHERE option_name = %s", $name ) );
			if ( is_multisite() ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key = %s", $name . '_network' ) );
			}
			$wpdb->query( 'COMMIT' );
		}
	}

	public function test_it_sees_and_removes_a_real_sites_tables_and_on_a_network_its_row(): void {
		global $wpdb;
		// On the connection itself: through wpdb a test's CREATE TABLE becomes a temporary table, and its rows are rolled
		// back. Committed, as a site created with real tables is. Its id from the sites table's own counter (an id set
		// by hand would move the counter, and every later site's id, with it).
		$id = 424242;
		if ( is_multisite() ) {
			$this->assertTrue( mysqli_query( $wpdb->dbh, "INSERT INTO `{$wpdb->blogs}` ( site_id, domain, path ) VALUES ( 1, 'leftover.check', '/' )" ) );
			$id = (int) mysqli_insert_id( $wpdb->dbh );
			mysqli_query( $wpdb->dbh, 'COMMIT' );
		}
		$table = $wpdb->base_prefix . $id . '_leftover_check';
		$temp  = $wpdb->base_prefix . ( $id + 1 ) . '_leftover_check';
		$this->assertTrue( mysqli_query( $wpdb->dbh, "CREATE TABLE `{$table}` ( id int )" ), $table );
		// A site's temporary table (as the core test case makes them) is gone with the connection: not one left.
		$this->assertTrue( mysqli_query( $wpdb->dbh, "CREATE TEMPORARY TABLE `{$temp}` ( id int )" ), $temp );
		$items = Leftovers::listing();
		mysqli_query( $wpdb->dbh, "DROP TEMPORARY TABLE IF EXISTS `{$temp}`" );
		$this->assertNotContains( 'site-table:' . $temp, $items, 'a temporary table is not listed' );
		$this->assertContains( 'site-table:' . $table, $items, 'the control: a base table of the same form is' );
		$this->assertNotContains( 'site-table:' . $wpdb->posts, $items, 'the main site\'s tables are not a site\'s' );
		if ( is_multisite() ) {
			$this->assertContains( 'site:' . $id, $items );
			$this->assertNotContains( 'site:' . get_main_site_id(), $items, 'nor is the main site' );
		}

		$own = is_multisite() ? array( 'site-table:' . $table, 'site:' . $id ) : array( 'site-table:' . $table );
		Leftovers::removal( $own );
		$items = Leftovers::listing();
		$this->assertSame( array(), array_values( array_intersect( $own, $items ) ) );
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s', $table ) ), 'dropped' );
		if ( is_multisite() ) {
			$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->blogs} WHERE blog_id = %d", $id ) ), 'its row deleted' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the sites table.
			$this->assertNotNull( get_site( get_main_site_id() ), 'the control: the main site is there' );
		}
		$this->assertContains( $wpdb->posts, (array) $wpdb->get_col( 'SHOW TABLES' ), 'the control: the main site\'s tables are kept' );
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

	public function test_on_another_database_a_sites_tables_are_not_removed(): void {
		global $wpdb;
		$name  = 'wpc_leftovers_sites_not_tests';
		$table = $wpdb->base_prefix . '5_leftover_other';
		$tests = $wpdb;
		$this->assertTrue( mysqli_query( $wpdb->dbh, "CREATE DATABASE `{$name}`" ), 'a database of this test' );
		try {
			$other = new \wpdb( DB_USER, DB_PASSWORD, $name, DB_HOST );
			$other->set_prefix( $wpdb->base_prefix );
			$this->assertTrue( (bool) $other->query( "CREATE TABLE `{$table}` ( id int )" ), 'a site\'s table there' );
			$GLOBALS['wpdb'] = $other; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- removal() works on the global connection; put back below.
			try {
				Leftovers::removal( array( 'site-table:' . $table ) );
			} finally {
				$GLOBALS['wpdb'] = $tests; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- put back.
			}
			$this->assertContains( $table, (array) $other->get_col( 'SHOW TABLES' ), 'still there' );
			$other->close();
			// The control: the same removal on the tests database drops a table of that name.
			$this->assertTrue( mysqli_query( $wpdb->dbh, "CREATE TABLE `{$table}` ( id int )" ), $table );
			$this->assertContains( $table, (array) $wpdb->get_col( 'SHOW TABLES' ) );
			Leftovers::removal( array( 'site-table:' . $table ) );
			$this->assertNotContains( $table, (array) $wpdb->get_col( 'SHOW TABLES' ), 'the control: dropped on the tests database' );
		} finally {
			$GLOBALS['wpdb'] = $tests; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- put back.
			mysqli_query( $wpdb->dbh, "DROP TABLE IF EXISTS `{$table}`" );
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

	public function test_a_stored_state_left_after_its_directory_was_removed_goes_and_one_whose_directory_is_there_is_put_back(): void {
		$dirs  = '\\WPCheckpoint\\Tests\\Fixtures\\StorageDirs';
		$found = $dirs::stored_row();
		$this->assertNotNull( $found, 'the run settled a state before this test' );
		$this->assertDirectoryExists( $dirs::path_in( $found['value'] ) );
		try {
			// The test found a state whose directory is gone now, and left another: the one it left goes.
			$gone          = $found;
			$gone['value'] = str_replace( basename( $dirs::path_in( $found['value'] ) ), 'wp-checkpoint-0123456789ab', $found['value'] );
			$this->assertDirectoryDoesNotExist( $dirs::path_in( $gone['value'] ) );
			$dirs::put_back( $gone );
			$this->assertNull( $dirs::stored_row(), 'the row left after the directory was removed' );
		} finally {
			// The control, and the row as this test found it: its directory is there, so it is put back.
			$dirs::put_back( $found );
		}
		$this->assertSame( $found, $dirs::stored_row() );
	}

	public function test_removing_what_a_test_made_keeps_the_settled_directory_and_takes_the_state_the_test_left(): void {
		$dirs   = '\\WPCheckpoint\\Tests\\Fixtures\\StorageDirs';
		$found  = $dirs::stored_row();
		$this->assertNotNull( $found, 'the run settled a state before this test' );
		$own    = $dirs::path_in( $found['value'] );
		$before = $dirs::listing(); // As a test notes it: without the stored one.
		$this->assertNotContains( $own, $before );
		$made   = WP_CONTENT_DIR . '/wp-checkpoint-' . bin2hex( random_bytes( 6 ) );
		$left   = $found;
		$left['value'] = str_replace( basename( $own ), basename( $made ), $found['value'] );
		try {
			// A test that made a directory of its own and left its state committed.
			$this->assertTrue( mkdir( $made ) );
			$dirs::put_back( $left );
			$this->assertSame( $made, $dirs::stored_path(), 'the state the test left is the stored one' );
			$dirs::remove_made_since( $before );
			clearstatcache( true );
			$this->assertDirectoryDoesNotExist( $made );
			$this->assertNull( $dirs::stored_row(), 'the state that named it' );
			$this->assertDirectoryExists( $own, 'the directory the run settled' );
		} finally {
			$dirs::put_back( $found );
			if ( is_dir( $made ) ) {
				$dirs::remove( array( $made ) );
			}
		}
		$this->assertSame( $found, $dirs::stored_row() );
	}

	public function test_it_sees_an_entry_of_the_uploads_directory_and_removes_it_only_under_a_tests_name(): void {
		$dir    = \WPCheckpoint\Tests\Fixtures\UploadsLeftovers::dir();
		$tests  = $dir . '/wpcexport-leftovercheck';
		$others = $dir . '/leftovercheck-someone';
		try {
			$this->assertTrue( mkdir( $tests . '/a', 0755, true ) );
			file_put_contents( $tests . '/a/f.txt', 'x' );
			$this->assertTrue( mkdir( $others ) );
			$items = Leftovers::listing();
			$this->assertContains( 'uploads:' . $tests, $items );
			$this->assertContains( 'uploads:' . $others, $items, 'listed too: any entry but WordPress\'s own' );
			Leftovers::removal( array( 'uploads:' . $tests, 'uploads:' . $others ) );
			clearstatcache();
			$this->assertDirectoryDoesNotExist( $tests, 'under a test\'s name: removed' );
			$this->assertDirectoryExists( $others, 'under no test\'s name: left alone' );
		} finally {
			\WPCheckpoint\Support\Deleter::allow( $others );
			\WPCheckpoint\Support\Deleter::delete_tree( $dir, $others );
			if ( is_dir( $tests ) ) {
				\WPCheckpoint\Support\Deleter::allow( $tests );
				\WPCheckpoint\Support\Deleter::delete_tree( $dir, $tests );
			}
		}
	}

	public function test_it_sees_an_entry_of_wp_content_and_of_the_must_use_plugins_and_removes_only_a_tests(): void {
		$content = \WPCheckpoint\Tests\Fixtures\ContentLeftovers::dir();
		$mu      = \WPCheckpoint\Tests\Fixtures\ContentLeftovers::mu_dir();
		$groups  = array();
		foreach ( \WPCheckpoint\Restore\StagingLayout::GROUPS as $group ) {
			$groups[ $group ] = $content . '/' . $group;
		}
		$probe   = $mu . '/' . ( new \WPCheckpoint\Restore\StagingLayout( $groups, 'abcdef012345', 7, str_repeat( 'a', 32 ) ) )->probe_name( '.php' );
		$made    = array(
			'tests'      => $content . '/wpc-leftovercheck-' . bin2hex( random_bytes( 3 ) ),
			'someone'    => $content . '/leftovercheck-someone',
			'probe'      => $probe,
			'mu-someone' => $mu . '/leftovercheck-someone.php',
		);
		$had_mu  = is_dir( $mu );
		try {
			$this->assertTrue( mkdir( $made['tests'] . '/a', 0755, true ) );
			$this->assertTrue( mkdir( $made['someone'] ) );
			if ( ! $had_mu ) {
				mkdir( $mu );
			}
			file_put_contents( $made['probe'], '<?php' );
			file_put_contents( $made['mu-someone'], '<?php' );
			$items = Leftovers::listing();
			foreach ( array( 'tests', 'someone' ) as $key ) {
				$this->assertContains( 'content:' . $made[ $key ], $items, $key );
			}
			foreach ( array( 'probe', 'mu-someone' ) as $key ) {
				$this->assertContains( 'mu-plugins:' . $made[ $key ], $items, $key );
			}
			Leftovers::removal( array( 'content:' . $made['tests'], 'content:' . $made['someone'], 'mu-plugins:' . $made['probe'], 'mu-plugins:' . $made['mu-someone'] ) );
			clearstatcache();
			$this->assertFileDoesNotExist( $made['tests'], 'a test\'s wpc-* directory: removed' );
			$this->assertFileDoesNotExist( $made['probe'], 'a loader probe: removed' );
			$this->assertFileExists( $made['someone'], 'under no test\'s name: left alone' );
			$this->assertFileExists( $made['mu-someone'], 'not a probe: left alone' );
		} finally {
			foreach ( array( 'someone' => $content, 'mu-someone' => $mu, 'tests' => $content, 'probe' => $mu ) as $key => $dir ) {
				if ( file_exists( $made[ $key ] ) ) {
					\WPCheckpoint\Support\Deleter::allow( $made[ $key ] );
					\WPCheckpoint\Support\Deleter::delete_tree( $dir, $made[ $key ] );
				}
			}
		}
	}

	public function test_it_sees_a_restores_loader_left_in_the_must_use_plugins_and_removes_it(): void {
		\WPCheckpoint\Tests\Fixtures\RealMuPlugins::claim();
		$path = \WPCheckpoint\Tests\Fixtures\ContentLeftovers::mu_dir() . '/' . \WPCheckpoint\Restore\Loader::NAME;
		try {
			$this->assertNotContains( 'mu-plugins:' . $path, Leftovers::listing(), 'the control: not listed while it is not there' );
			\WPCheckpoint\Tests\Fixtures\RealMuPlugins::put();
			$this->assertContains( 'mu-plugins:' . $path, Leftovers::listing(), 'listed: the test that left it fails' );
			Leftovers::removal( array( 'mu-plugins:' . $path ) );
			clearstatcache();
			$this->assertFileDoesNotExist( $path, 'this plugin\'s loader, by its contents: removed (another file under its name is kept: ContentLeftoversTest, in a sandbox)' );
		} finally {
			foreach ( \WPCheckpoint\Tests\Fixtures\RealMuPlugins::listing() as $left ) {
				\WPCheckpoint\Tests\Fixtures\RealMuPlugins::remove( basename( $left ) );
			}
		}
	}

	public function test_every_wp_content_entry_registered_by_name_is_there(): void {
		$content = \WPCheckpoint\Tests\Fixtures\ContentLeftovers::dir();
		foreach ( array_keys( \WPCheckpoint\Tests\Fixtures\ContentLeftovers::OWN ) as $name ) {
			if ( isset( \WPCheckpoint\Tests\Fixtures\ContentLeftovers::MAY_BE_ABSENT[ $name ] ) ) {
				continue;
			}
			$this->assertFileExists( $content . '/' . $name, 'a registration that names nothing in the test site\'s wp-content is out of date: ' . $name );
		}
		foreach ( array_keys( \WPCheckpoint\Tests\Fixtures\ContentLeftovers::MAY_BE_ABSENT ) as $name ) {
			$this->assertArrayHasKey( $name, \WPCheckpoint\Tests\Fixtures\ContentLeftovers::OWN, 'what may be absent is registered: ' . $name );
		}
		$this->assertSame( array(), \WPCheckpoint\Tests\Fixtures\ContentLeftovers::entries( $content ), 'and nothing else is there now' );
	}
}
