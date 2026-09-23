<?php

namespace WPCheckpoint\Tests\Unit\Database;

use WPCheckpoint\Database\TableSelection;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class TableSelectionTest extends TestCase {

	private const CORE = array( 'wp_commentmeta', 'wp_comments', 'wp_links', 'wp_options', 'wp_postmeta', 'wp_posts', 'wp_term_relationships', 'wp_term_taxonomy', 'wp_termmeta', 'wp_terms', 'wp_usermeta', 'wp_users' );

	/**
	 * The per-site core of an installation under a prefix, plus extra names.
	 *
	 * @return string[]
	 */
	private static function site( string $prefix, array $extra = array() ): array {
		return array_merge(
			array_map(
				static function ( string $name ) use ( $prefix ): string {
					return $prefix . $name;
				},
				array( 'commentmeta', 'comments', 'links', 'options', 'postmeta', 'posts', 'term_relationships', 'term_taxonomy', 'termmeta', 'terms' )
			),
			array_map(
				static function ( string $name ) use ( $prefix ): string {
					return $prefix . $name;
				},
				$extra
			)
		);
	}

	private static function sorted( array $tables ): array {
		sort( $tables, SORT_STRING );
		return $tables;
	}

	public function test_another_installation_leaves_out_its_core_and_names_the_rest(): void {
		$tables = array_merge(
			self::CORE,
			array( 'wp_wpcheckpoint_jobs', 'wp_woocommerce_sessions' ), // Plugin tables of this site.
			self::site( 'wp_old_', array( 'users', 'usermeta', 'yoast_indexable' ) ) // A neighbour with a plugin table.
		);
		$this->assertSame(
			array(
				'wp_old_' => array(
					'excluded' => self::sorted( self::site( 'wp_old_', array( 'users', 'usermeta' ) ) ),
					'kept'     => array( 'wp_old_yoast_indexable' ), // Could be this site's "old_yoast_indexable": stays, named.
				),
			),
			TableSelection::foreign( $tables, 'wp_', false, self::CORE )
		);
	}

	public function test_a_prefix_without_the_complete_core_is_kept_as_plugin_tables(): void {
		// A forum plugin with its own "posts", "options", "users" and more: not an installation.
		$tables = array_merge( self::CORE, array( 'wp_forum_posts', 'wp_forum_options', 'wp_forum_users', 'wp_forum_postmeta', 'wp_forum_topics' ) );
		$this->assertSame( array(), TableSelection::foreign( $tables, 'wp_', false, self::CORE ) );
	}

	public function test_an_installation_that_shares_this_sites_users_is_recognised(): void {
		// CUSTOM_USER_TABLE: the neighbour has no users table of its own.
		$tables = array_merge( self::CORE, self::site( 'wp_b_' ) );
		$this->assertSame( self::sorted( self::site( 'wp_b_' ) ), TableSelection::foreign( $tables, 'wp_', false, self::CORE )['wp_b_']['excluded'] );
	}

	public function test_multisite_sub_sites_are_this_installation_and_a_single_site_sees_them_as_foreign(): void {
		$tables = array_merge( self::CORE, self::site( 'wp_2_' ) );
		$this->assertSame( array(), TableSelection::foreign( $tables, 'wp_', true, self::CORE ), 'wp_2_ is a sub-site of this network' );
		$this->assertSame( self::sorted( self::site( 'wp_2_' ) ), TableSelection::foreign( $tables, 'wp_', false, self::CORE )['wp_2_']['excluded'], 'a single site has no sub-sites: a complete wp_2_ core is someone else' );
	}

	public function test_a_prefix_without_underscore_cannot_take_this_networks_sub_sites(): void {
		// A neighbour "wp_1" beside a network "wp_": sub-sites 10-19, 100-199 ... start with "wp_1" too.
		$tables = array_merge( self::CORE, array( 'wp_blogs', 'wp_site' ), self::site( 'wp_1' ), self::site( 'wp_10_' ), self::site( 'wp_12_', array( 'wc_orders' ) ) );
		$groups = TableSelection::foreign( $tables, 'wp_', true, array_merge( self::CORE, array( 'wp_blogs', 'wp_site' ) ) );
		$this->assertSame( array( 'wp_1' ), array_keys( $groups ) );
		$this->assertSame( self::sorted( self::site( 'wp_1' ) ), $groups['wp_1']['excluded'], 'only the neighbour\'s own core' );
		$this->assertSame( array(), $groups['wp_1']['kept'], 'the sub-sites are protected before the neighbour is judged, so they are not even named' );
	}

	public function test_a_short_neighbour_prefix_cannot_take_this_sites_plugin_tables(): void {
		$tables = array_merge( self::CORE, array( 'wp_wc_orders', 'wp_woocommerce_sessions', 'wp_wfconfig' ), self::site( 'wp_w' ) );
		$groups = TableSelection::foreign( $tables, 'wp_', false, self::CORE );
		$this->assertSame( self::sorted( self::site( 'wp_w' ) ), $groups['wp_w']['excluded'] );
		$this->assertSame( array( 'wp_wc_orders', 'wp_wfconfig', 'wp_woocommerce_sessions' ), $groups['wp_w']['kept'], 'ambiguous: in the backup, and named' );
	}

	public function test_a_foreign_network_is_one_group_with_its_own_sub_sites(): void {
		$tables = array_merge( self::CORE, self::site( 'wp_old_', array( 'users', 'usermeta', 'blogs', 'site' ) ), self::site( 'wp_old_2_', array( 'custom' ) ) );
		$groups = TableSelection::foreign( $tables, 'wp_', true, self::CORE );
		$this->assertSame( array( 'wp_old_' ), array_keys( $groups ), 'the sub-site is claimed by the shorter prefix' );
		$this->assertCount( 24, $groups['wp_old_']['excluded'] );
		$this->assertSame( array( 'wp_old_2_custom' ), $groups['wp_old_']['kept'] );
	}

	public function test_this_installations_core_tables_are_never_left_out(): void {
		// A prefix whose name makes a core table look like a member cannot take it: "wp_term_" + "relationships"
		// is not a core name anyway, and a core table cannot be a marker either.
		$tables = array_merge( self::CORE, self::site( 'wp_term_' ) );
		$groups = TableSelection::foreign( $tables, 'wp_', false, self::CORE );
		$this->assertNotContains( 'wp_term_relationships', $groups['wp_term_']['excluded'] );
		$this->assertNotContains( 'wp_term_taxonomy', $groups['wp_term_']['excluded'] );
		$this->assertSame( array(), TableSelection::foreign( self::CORE, 'wp_', false, self::CORE ) );
	}
}
