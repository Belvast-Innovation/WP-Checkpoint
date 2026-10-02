<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Restore\TableMoves;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Which live tables the backup does not have the swap moves away, and which it leaves and reports.
 */
final class TableMovesTest extends TestCase {

	const TOKEN = 'a1b2c3d4e5f6';

	/**
	 * WordPress's own table names read without a prefix (a single site).
	 */
	const CORE = array( 'posts', 'comments', 'links', 'options', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'commentmeta', 'users', 'usermeta' );

	/**
	 * The plugin's tables and the restore's temporary and old ones, with the base prefix.
	 *
	 * @return string[]
	 */
	private static function plugins_own( string $base ): array {
		return array(
			$base . 'wpcheckpoint_jobs',
			$base . 'wpcheckpoint_swap_plan',
			TempTables::ledger( self::TOKEN, 12, 'beef' ),
			TempTables::name( self::TOKEN, 12, 'beef', 'posts' ),
			TempTables::old( self::TOKEN, 12, 'beef', 'users' ),
			TempTables::name( 'ffffffffffff', 7, 'cafe', 'options' ), // Another installation's restore.
		);
	}

	public function test_with_an_empty_prefix_only_wordpress_tables_are_moved_and_the_others_reported(): void {
		$live = array_merge(
			self::CORE,
			array( 'app_sessions', 'wp_posts', 'shop_orders' ),
			self::plugins_own( '' ),
			array( 'excluded_log' )
		);
		$out  = TableMoves::select(
			'',
			false,
			$live,
			array( 'posts', 'options', 'users' ), // The backup's.
			array( 'comments', 'excluded_log' ), // Left out of the restore.
			self::CORE
		);
		$this->assertSame( array( 'links', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'commentmeta', 'usermeta' ), $out['move'] );
		$this->assertSame( array( 'app_sessions', 'wp_posts', 'shop_orders' ), $out['report'] );
		foreach ( array_merge( self::plugins_own( '' ), array( 'posts', 'options', 'users', 'comments', 'excluded_log' ) ) as $name ) {
			$this->assertNotContains( $name, $out['move'], $name );
			$this->assertNotContains( $name, $out['report'], $name );
		}
	}

	/**
	 * A site's WordPress tables under a prefix.
	 *
	 * @return string[]
	 */
	private static function site( string $prefix ): array {
		return array_map(
			static function ( string $name ) use ( $prefix ): string {
				return $prefix . $name;
			},
			array( 'posts', 'postmeta', 'options', 'comments', 'commentmeta', 'terms', 'termmeta', 'term_taxonomy', 'term_relationships', 'links' )
		);
	}

	public function test_with_a_prefix_the_tables_under_it_are_moved_and_the_others_reported(): void {
		$live = array_merge(
			array( 'wp_posts', 'wp_options', 'wp_links', 'wp_2_posts', 'wp_shop_orders', 'wp_excluded_log', 'other_posts', 'app_sessions' ),
			self::plugins_own( 'wp_' )
		);
		$out  = TableMoves::select( 'wp_', false, $live, array( 'wp_posts', 'wp_options' ), array( 'wp_excluded_log' ), array_merge( self::site( 'wp_' ), array( 'wp_users', 'wp_usermeta' ) ) );
		$this->assertSame( array( 'wp_links', 'wp_2_posts', 'wp_shop_orders' ), $out['move'], 'no other installation claims them (one table of a "wp_2_" is not an installation)' );
		$this->assertSame( array( 'other_posts', 'app_sessions' ), $out['report'], 'outside the prefix: left, and said so' );
	}

	public function test_a_neighbour_installation_under_a_longer_prefix_is_left_and_reported(): void {
		$core  = array_merge( self::site( 'wp_' ), array( 'wp_users', 'wp_usermeta' ) );
		$old   = array_merge( self::site( 'wp_old_' ), array( 'wp_old_users', 'wp_old_usermeta' ) );
		$live  = array_merge( $core, $old, array( 'wp_old_wc_orders', 'wp_wc_orders', 'wp_new_plugin_log' ) );
		$out   = TableMoves::select( 'wp_', false, $live, array( 'wp_posts', 'wp_options', 'wp_users' ), array(), $core );
		$this->assertSame( array_values( array_diff( $core, array( 'wp_posts', 'wp_options', 'wp_users' ) ) ), array_values( array_intersect( $out['move'], $core ) ), 'this site\'s own WordPress tables the backup lacks are moved' );
		foreach ( array( 'wp_wc_orders', 'wp_new_plugin_log' ) as $name ) {
			$this->assertContains( $name, $out['move'], $name . ': claimed by no other installation' );
		}
		foreach ( array_merge( $old, array( 'wp_old_wc_orders' ) ) as $name ) {
			$this->assertNotContains( $name, $out['move'], $name . ': the neighbour\'s (or may be) is never moved' );
			$this->assertContains( $name, $out['report'], $name . ': and is reported' );
		}
	}

	public function test_a_neighbour_is_recognised_by_all_its_tables_the_backups_included(): void {
		// A backup made before neighbours were left out holds "wp_old_posts": the rest of that installation still
		// shows it is one, so its other tables stay.
		$core = array_merge( self::site( 'wp_' ), array( 'wp_users', 'wp_usermeta' ) );
		$old  = self::site( 'wp_old_' );
		$out  = TableMoves::select( 'wp_', false, array_merge( $core, $old ), array_merge( $core, array( 'wp_old_posts' ) ), array(), $core );
		$this->assertSame( array(), $out['move'] );
		$this->assertSame( array_values( array_diff( $old, array( 'wp_old_posts' ) ) ), $out['report'] );
	}

	public function test_where_the_server_folds_case_a_mixed_case_prefix_moves_its_own_and_leaves_the_neighbours(): void {
		$core = array_merge( self::site( 'wpABC_' ), array( 'wpABC_users', 'wpABC_usermeta' ) );
		$live = array_map( 'strtolower', array_merge( $core, self::site( 'wpabc_old_' ), array( 'wpabc_shop' ) ) );
		$as   = TableMoves::select( 'wpABC_', false, $live, array( 'wpABC_options', 'wpABC_posts' ), array(), $core );
		$this->assertSame( array(), $as['move'], 'the control: compared as spelled, nothing is this site\'s' );
		$out = TableMoves::select( 'wpABC_', false, $live, array( 'wpABC_options', 'wpABC_posts' ), array( 'wpABC_links' ), $core, true );
		$this->assertContains( 'wpabc_shop', $out['move'], 'this site\'s table the backup lacks, as the server lists it' );
		$this->assertContains( 'wpabc_postmeta', $out['move'] );
		$this->assertNotContains( 'wpabc_options', array_merge( $out['move'], $out['report'] ), 'the backup\'s own: replaced by its entry' );
		$this->assertNotContains( 'wpabc_links', array_merge( $out['move'], $out['report'] ), 'left out of the restore: it stays' );
		foreach ( self::site( 'wpabc_old_' ) as $name ) {
			$this->assertContains( $name, $out['report'], $name . ': the neighbour\'s, left' );
		}
	}

	public function test_a_table_both_installations_claim_is_left(): void {
		// This site's users table is the neighbour's (CUSTOM_USER_TABLE): its own, and not only its own.
		$core = array_merge( self::site( 'wp_' ), array( 'wp_old_users', 'wp_old_usermeta' ) );
		$old  = self::site( 'wp_old_' );
		$out  = TableMoves::select( 'wp_', false, array_merge( $core, $old ), self::site( 'wp_' ), array(), $core );
		$this->assertSame( array(), $out['move'], 'the shared users tables are not moved from under the neighbour' );
		$this->assertContains( 'wp_old_users', $out['report'] );
		$this->assertContains( 'wp_old_usermeta', $out['report'] );
	}

	public function test_a_networks_sub_sites_are_its_own_and_a_single_sites_look_alike_is_not(): void {
		$core = array_merge( self::site( 'wp_' ), array( 'wp_users', 'wp_usermeta', 'wp_blogs', 'wp_site', 'wp_sitemeta' ) );
		$live = array_merge( $core, self::site( 'wp_2_' ) );
		$net  = TableMoves::select( 'wp_', true, $live, $core, array(), $core );
		$this->assertSame( self::site( 'wp_2_' ), $net['move'], 'a sub-site of this network the backup lacks is moved' );
		$this->assertSame( array(), $net['report'] );
		$one = TableMoves::select( 'wp_', false, $live, $core, array(), $core );
		$this->assertSame( array(), $one['move'], 'a single site has no sub-sites: a complete "wp_2_" is another installation' );
		$this->assertSame( self::site( 'wp_2_' ), $one['report'] );
	}

	public function test_short_prefixes_move_their_own_and_never_the_restores_tables_nor_a_longer_neighbour(): void {
		foreach ( array( 'w', 'wc', 'wcp', 'wcp_', 'wcptmp', 'wcpold' ) as $prefix ) {
			$core   = array_merge( self::site( $prefix ), array( $prefix . 'users', $prefix . 'usermeta' ) );
			// A whole restore's worth: its temporary and old tables of every WordPress table form what looks like
			// an installation of their own under a short prefix ("wcptmp…_12_beef_posts", "…_options", …).
			$runs = array( TempTables::ledger( self::TOKEN, 12, 'beef' ) );
			foreach ( array( 'posts', 'postmeta', 'options', 'comments', 'commentmeta', 'terms', 'term_taxonomy', 'term_relationships', 'users' ) as $name ) {
				$runs[] = TempTables::name( self::TOKEN, 12, 'beef', $name );
				$runs[] = TempTables::old( self::TOKEN, 12, 'beef', $name );
			}
			$live   = array_merge( $core, $runs, array( $prefix . 'shop_orders' ) );
			$out    = TableMoves::select( $prefix, false, $live, array( $prefix . 'posts', $prefix . 'options' ), array(), $core );
			$expect = array_values( array_merge( array_diff( $core, array( $prefix . 'posts', $prefix . 'options' ) ), array( $prefix . 'shop_orders' ) ) );
			$this->assertSame( $expect, $out['move'], $prefix . ': its own tables' );
			foreach ( $runs as $name ) {
				$this->assertNotContains( $name, $out['move'], $prefix . ': ' . $name );
				$this->assertNotContains( $name, $out['report'], $prefix . ': ' . $name );
			}
		}
		// A site with the prefix "w" and a WordPress installation "wp_" in the same database: "wp_" starts with "w".
		$core = array_merge( self::site( 'w' ), array( 'wusers', 'wusermeta' ) );
		$wp   = array_merge( self::site( 'wp_' ), array( 'wp_users', 'wp_usermeta' ) );
		$out  = TableMoves::select( 'w', false, array_merge( $core, $wp ), array( 'wposts' ), array(), $core );
		$this->assertSame( array(), array_values( array_intersect( $wp, $out['move'] ) ), 'the "wp_" installation is never moved' );
		$this->assertSame( $wp, $out['report'] );
	}

	public function test_the_plugins_own_tables_are_never_touched(): void {
		foreach ( array_merge( self::plugins_own( 'wp_' ), array( 'wpcheckpoint_jobs', 'wp_old_wpcheckpoint_swap_plan' ) ) as $name ) {
			$this->assertTrue( TableMoves::never( $name ), $name . ': this installation\'s, or a neighbour\'s' );
		}
		foreach ( array( 'wp_posts', 'wp_wpcheckpoint_jobs_old', 'wcp_posts', 'wcptmp_notes' ) as $name ) {
			$this->assertFalse( TableMoves::never( $name ), $name );
		}
	}
}
