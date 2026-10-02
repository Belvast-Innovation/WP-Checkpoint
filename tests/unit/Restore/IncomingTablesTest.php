<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\IncomingTables;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Which of a backup's tables would replace a live table another installation in the same database uses: one of its
 * own (left out), one that may be either's, one this site shares with it (both asked about).
 */
final class IncomingTablesTest extends TestCase {

	/**
	 * An installation's per-site WordPress tables under a prefix.
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

	/** This site ("wp_") with its users tables. */
	private static function core(): array {
		return array_merge( self::site( 'wp_' ), array( 'wp_users', 'wp_usermeta' ) );
	}

	/**
	 * Classify with this site's usual users tables.
	 *
	 * @return array<string, string>
	 */
	private static function classify( array $live, array $finals, array $core, array $found = array(), bool $multisite = false ): array {
		return IncomingTables::classify( 'wp_', $multisite, $live, $finals, $core, 'wp_users', 'wp_usermeta', $found );
	}

	public function test_a_neighbours_own_table_is_left_out_and_this_sites_are_not_judged(): void {
		$live = array_merge( self::core(), self::site( 'wp_old_' ) );
		$out  = self::classify( $live, array( 'wp_options', 'wp_posts', 'wp_old_posts', 'wp_old_options' ), self::core() );
		$this->assertSame(
			array(
				'wp_old_posts'   => IncomingTables::NEIGHBOUR,
				'wp_old_options' => IncomingTables::NEIGHBOUR,
			),
			$out,
			'the neighbour\'s tables, and none of this site\'s'
		);
	}

	public function test_without_a_neighbour_nothing_is_judged(): void {
		// "wp_old_posts" alone is no installation: it is this site's (a plugin's table, an old copy).
		$live = array_merge( self::core(), array( 'wp_old_posts' ) );
		$this->assertSame( array(), self::classify( $live, array( 'wp_options', 'wp_old_posts' ), self::core() ) );
	}

	public function test_a_table_under_a_neighbours_prefix_that_is_not_one_of_its_own_may_be_either(): void {
		$live = array_merge( self::core(), self::site( 'wp_old_' ), array( 'wp_old_shop_orders' ) );
		$out  = self::classify( $live, array( 'wp_options', 'wp_old_shop_orders' ), self::core() );
		$this->assertSame( array( 'wp_old_shop_orders' => IncomingTables::UNCERTAIN ), $out );
	}

	public function test_this_sites_table_in_a_neighbours_group_is_shared(): void {
		// This site's CUSTOM_USER_TABLE is the neighbour's users table.
		$live = array_merge( self::core(), self::site( 'wp_old_' ), array( 'wp_old_users', 'wp_old_usermeta' ) );
		$core = array_merge( self::site( 'wp_' ), array( 'wp_old_users', 'wp_usermeta' ) );
		$out  = IncomingTables::classify( 'wp_', false, $live, array( 'wp_options', 'wp_old_users', 'wp_old_usermeta' ), $core, 'wp_old_users', 'wp_usermeta', array() );
		$this->assertSame(
			array(
				'wp_old_users'    => IncomingTables::SHARED,
				'wp_old_usermeta' => IncomingTables::NEIGHBOUR,
			),
			$out,
			'shared: this site uses it; the other one only the neighbour does'
		);
		$this->assertSame( IncomingTables::NEIGHBOUR, self::classify( $live, array( 'wp_old_users' ), self::core() )['wp_old_users'], 'the control: not this site\'s, the neighbour\'s own' );
	}

	public function test_a_table_of_this_site_under_a_neighbours_prefix_that_the_neighbour_does_not_claim_is_this_sites(): void {
		// This site's CUSTOM_USER_TABLE "wp_old_members": a name no WordPress of "wp_old_" has.
		$live = array_merge( self::core(), self::site( 'wp_old_' ), array( 'wp_old_members' ) );
		$core = array_merge( self::core(), array( 'wp_old_members' ) );
		$this->assertSame( array(), self::classify( $live, array( 'wp_old_members' ), $core ) );
		$this->assertSame( array( 'wp_old_members' => IncomingTables::UNCERTAIN ), self::classify( $live, array( 'wp_old_members' ), self::core() ), 'the control: not this site\'s, either\'s' );
	}

	public function test_a_neighbours_role_keys_in_this_sites_usermeta_table_make_both_users_tables_shared(): void {
		$live   = array_merge( self::core(), self::site( 'wp_old_' ) );
		$finals = array( 'wp_options', 'wp_users', 'wp_usermeta' );
		$this->assertSame( array(), self::classify( $live, $finals, self::core() ), 'the control: no role keys, nothing shared' );
		foreach ( array( 'wp_old_capabilities', 'wp_old_user_level' ) as $key ) {
			$this->assertSame(
				array(
					'wp_users'    => IncomingTables::SHARED,
					'wp_usermeta' => IncomingTables::SHARED,
				),
				self::classify( $live, $finals, self::core(), array( $key ) ),
				$key
			);
		}
	}

	public function test_the_users_tables_this_site_uses_are_the_ones_shared(): void {
		// CUSTOM_USER_TABLE and CUSTOM_USER_META_TABLE of this site: "wp_members", "wp_membermeta".
		$core   = array_merge( self::site( 'wp_' ), array( 'wp_members', 'wp_membermeta' ) );
		$live   = array_merge( $core, array( 'wp_users', 'wp_usermeta' ), self::site( 'wp_old_' ) );
		$finals = array( 'wp_users', 'wp_usermeta', 'wp_members', 'wp_membermeta' );
		$out    = IncomingTables::classify( 'wp_', false, $live, $finals, $core, 'wp_members', 'wp_membermeta', array( 'wp_old_capabilities' ) );
		$this->assertSame(
			array(
				'wp_members'    => IncomingTables::SHARED,
				'wp_membermeta' => IncomingTables::SHARED,
			),
			$out
		);
	}

	public function test_only_the_neighbours_prefixes_count_for_role_keys(): void {
		// A network's sub-site keys ("wp_2_capabilities") in its usermeta: the sub-sites are the network's own.
		$network = array_merge( self::core(), array( 'wp_blogs', 'wp_site', 'wp_sitemeta' ), self::site( 'wp_2_' ) );
		$finals  = array( 'wp_options', 'wp_users', 'wp_usermeta' );
		$this->assertSame( array(), IncomingTables::role_keys( 'wp_', true, $network ) );
		$this->assertSame( array(), self::classify( $network, $finals, $network, array( 'wp_2_capabilities', 'wp_2_user_level' ), true ) );
		// A single site with such keys and no such installation: nothing.
		$this->assertSame( array(), self::classify( self::core(), $finals, self::core(), array( 'wp_2_capabilities' ) ) );
		// The control: on a single site, a whole "wp_2_" installation is a neighbour, and its keys count.
		$single = array_merge( self::core(), self::site( 'wp_2_' ) );
		$this->assertSame( array( 'wp_2_capabilities', 'wp_2_user_level' ), IncomingTables::role_keys( 'wp_', false, $single ) );
		$this->assertSame( IncomingTables::SHARED, self::classify( $single, $finals, self::core(), array( 'wp_2_capabilities' ) )['wp_users'] ?? null );
		// Keys of other prefixes than the neighbours' are not evidence.
		$this->assertSame( array(), self::classify( array_merge( self::core(), self::site( 'wp_old_' ) ), $finals, self::core(), array( 'wp_capabilities', 'wp_new_capabilities' ) ) );
	}

	public function test_role_keys_name_each_neighbours_two_keys(): void {
		$live = array_merge( self::core(), self::site( 'wp_old_' ), self::site( 'wp_new_' ) );
		$this->assertSame( array( 'wp_new_capabilities', 'wp_new_user_level', 'wp_old_capabilities', 'wp_old_user_level' ), IncomingTables::role_keys( 'wp_', false, $live ) );
		$this->assertSame( array(), IncomingTables::role_keys( 'wp_', false, self::core() ), 'no neighbour, nothing to look for' );
	}

	public function test_with_an_empty_prefix_nothing_is_judged(): void {
		$live = array_merge( self::site( '' ), array( 'users', 'usermeta' ), self::site( 'wp_' ) );
		$this->assertSame( array(), IncomingTables::classify( '', false, $live, array( 'wp_posts', 'options' ), self::site( '' ), 'users', 'usermeta', array( 'wp_capabilities' ) ) );
		$this->assertSame( array(), IncomingTables::role_keys( '', false, $live ) );
	}
}
