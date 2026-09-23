<?php

namespace WPCheckpoint\Tests\Unit\Database;

use WPCheckpoint\Database\TableSelection;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class TableSelectionTest extends TestCase {

	private const CORE = array( 'wp_commentmeta', 'wp_comments', 'wp_links', 'wp_options', 'wp_postmeta', 'wp_posts', 'wp_term_relationships', 'wp_term_taxonomy', 'wp_termmeta', 'wp_terms', 'wp_usermeta', 'wp_users' );

	public function test_another_installation_under_a_longer_prefix_is_left_out_as_a_whole(): void {
		$tables = array_merge(
			self::CORE,
			array( 'wp_wpcheckpoint_jobs', 'wp_woocommerce_sessions' ), // Plugin tables of this site.
			array( 'wp_old_options', 'wp_old_posts', 'wp_old_users', 'wp_old_postmeta', 'wp_old_yoast_indexable' ) // A neighbour.
		);
		$this->assertSame(
			array( 'wp_old_' => array( 'wp_old_options', 'wp_old_postmeta', 'wp_old_posts', 'wp_old_users', 'wp_old_yoast_indexable' ) ),
			TableSelection::foreign( $tables, 'wp_', false, self::CORE )
		);
	}

	public function test_a_group_without_the_complete_core_is_kept_as_plugin_tables(): void {
		// A plugin with its own "posts" and "options" tables, but no users table: not an installation.
		$tables = array_merge( self::CORE, array( 'wp_forum_posts', 'wp_forum_options', 'wp_forum_topics' ) );
		$this->assertSame( array(), TableSelection::foreign( $tables, 'wp_', false, self::CORE ) );
	}

	public function test_multisite_sub_sites_are_this_installation_and_a_single_site_sees_them_as_foreign(): void {
		$tables = array_merge( self::CORE, array( 'wp_2_posts', 'wp_2_options', 'wp_2_users' ) );
		$this->assertSame( array(), TableSelection::foreign( $tables, 'wp_', true, self::CORE ), 'wp_2_ is a sub-site of this network' );
		$this->assertSame( array( 'wp_2_' => array( 'wp_2_options', 'wp_2_posts', 'wp_2_users' ) ), TableSelection::foreign( $tables, 'wp_', false, self::CORE ), 'a single site has no sub-sites: a complete wp_2_ core is someone else' );
	}

	public function test_a_foreign_network_is_one_group_with_its_own_sub_sites(): void {
		$tables = array_merge( self::CORE, array( 'wp_old_posts', 'wp_old_options', 'wp_old_users', 'wp_old_2_posts', 'wp_old_2_options', 'wp_old_2_users' ) );
		$groups = TableSelection::foreign( $tables, 'wp_', true, self::CORE );
		$this->assertSame( array( 'wp_old_' ), array_keys( $groups ) );
		$this->assertCount( 6, $groups['wp_old_'] );
	}

	public function test_this_installations_core_tables_are_never_left_out(): void {
		// A prefix whose name makes a core table look like a member cannot take it.
		$tables = array_merge( self::CORE, array( 'wp_term_posts', 'wp_term_options', 'wp_term_users' ) );
		$groups = TableSelection::foreign( $tables, 'wp_', false, self::CORE );
		$this->assertSame( array( 'wp_term_options', 'wp_term_posts', 'wp_term_users' ), $groups['wp_term_'], 'wp_term_relationships and wp_term_taxonomy are core and stay' );
	}
}
