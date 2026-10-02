<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\IncomingTables;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Which of a backup's tables would replace a live table another installation in the same database uses: one of its
 * own (left out), one that may be either's, one this site shares with it (both asked about); the other
 * installations' capabilities keys in this site's usermeta table; and the names a server that folds case refuses.
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
	private static function classify( array $live, array $finals, array $core, bool $evidence = false ): array {
		return IncomingTables::classify( 'wp_', false, $live, $finals, $core, 'wp_users', 'wp_usermeta', $evidence );
	}

	/** No site of a network is there. */
	private static function no_blogs(): callable {
		return static function (): bool {
			return false;
		};
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
		$out  = IncomingTables::classify( 'wp_', false, $live, array( 'wp_options', 'wp_old_users', 'wp_old_usermeta' ), $core, 'wp_old_users', 'wp_usermeta', false );
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

	public function test_evidence_of_another_installation_makes_the_users_tables_this_site_uses_shared(): void {
		$finals = array( 'wp_options', 'wp_users', 'wp_usermeta' );
		$this->assertSame( array(), self::classify( self::core(), $finals, self::core() ), 'the control: no evidence, nothing shared' );
		$this->assertSame(
			array(
				'wp_users'    => IncomingTables::SHARED,
				'wp_usermeta' => IncomingTables::SHARED,
			),
			self::classify( self::core(), $finals, self::core(), true ),
			'with no neighbour under this site\'s prefix at all'
		);
		// CUSTOM_USER_TABLE and CUSTOM_USER_META_TABLE of this site: "wp_members", "wp_membermeta".
		$core = array_merge( self::site( 'wp_' ), array( 'wp_members', 'wp_membermeta' ) );
		$this->assertSame(
			array(
				'wp_members'    => IncomingTables::SHARED,
				'wp_membermeta' => IncomingTables::SHARED,
			),
			IncomingTables::classify( 'wp_', false, $core, array( 'wp_users', 'wp_usermeta', 'wp_members', 'wp_membermeta' ), $core, 'wp_members', 'wp_membermeta', true ),
			'the ones this site uses'
		);
	}

	public function test_evidence_is_every_capabilities_key_of_a_prefix_that_is_not_this_sites(): void {
		$keys = array( 'wp_capabilities', 'wp_user_level', 'nickname', 'wp2_capabilities', 'WP_OLD_Capabilities', 'wp_old_capabilities', 'shop_capabilities', 'wp2_capabilities' );
		$this->assertSame( array( 'WP_OLD_', 'shop_', 'wp2_', 'wp_old_' ), IncomingTables::evidence( 'wp_', false, $keys, self::no_blogs() ), 'sorted, each once, in the case found' );
		$this->assertSame( array(), IncomingTables::evidence( 'wp_', false, array( 'wp_capabilities', 'wp_user_level', 'session_tokens' ), self::no_blogs() ), 'the control: this site\'s own' );
		$this->assertSame( array(), IncomingTables::evidence( 'wp_', false, array( 'capabilities', 'abccapabilities', 'wp_old_user_level' ), self::no_blogs() ), 'not looked for: no "_" before "capabilities", or another key' );
	}

	public function test_on_a_network_only_the_sites_it_has_are_its_own(): void {
		$blogs = static function ( int $id ): bool {
			return in_array( $id, array( 1, 2, 7 ), true );
		};
		$keys  = array( 'wp_capabilities', 'wp_2_capabilities', 'wp_7_capabilities', 'wp_3_capabilities', 'wp_02_capabilities', 'wp_2x_capabilities' );
		$this->assertSame( array( 'wp_02_', 'wp_2x_', 'wp_3_' ), IncomingTables::evidence( 'wp_', true, $keys, $blogs ), 'a number with no site, or not a site number' );
		$this->assertSame( array( 'wp_2_', 'wp_3_', 'wp_7_' ), IncomingTables::evidence( 'wp_', false, array( 'wp_capabilities', 'wp_2_capabilities', 'wp_7_capabilities', 'wp_3_capabilities' ), $blogs ), 'a single site has no sites: nothing more is its own' );
	}

	public function test_a_server_that_folds_case_refuses_any_name_with_upper_case_and_says_which(): void {
		$this->assertSame( '', IncomingTables::fold_refusal( true, 'wp_', 'wp_users', 'wp_usermeta', array( 'wp_options', 'wp_posts' ) ), 'the control: all lowercase' );
		$this->assertSame( '', IncomingTables::fold_refusal( false, 'wpABC_', 'Users', 'Meta', array( 'wp_Options' ) ), 'a server that keeps case: nothing in the way' );
		foreach (
			array(
				'the table prefix of this site (wpABC_)' => array( 'wpABC_', null, null, array( 'wpabc_options' ) ),
				'CUSTOM_USER_TABLE (Members)'            => array( 'wp_', 'Members', null, array( 'wp_options' ) ),
				'CUSTOM_USER_META_TABLE (MemberMeta)'    => array( 'wp_', 'members', 'MemberMeta', array( 'wp_options' ) ),
				'the backup\'s table wp_Shop'            => array( 'wp_', null, null, array( 'wp_options', 'wp_Shop' ) ),
			) as $what => $given
		) {
			$why = IncomingTables::fold_refusal( true, $given[0], $given[1], $given[2], $given[3] );
			$this->assertStringContainsString( 'compares table names without letter case', $why, $what );
			$this->assertStringContainsString( $what . ' has upper-case letters or letters outside ASCII', $why );
			$this->assertStringContainsString( 'full support for it is not implemented yet', $why, 'says what is not there yet' );
		}
	}

	public function test_a_server_that_folds_case_refuses_live_and_left_out_names_and_letters_outside_ascii(): void {
		$this->assertSame( '', IncomingTables::fold_refusal( true, 'wp_', null, null, array( 'wp_options' ), array( 'wp_options', 'wp_old_posts' ), array( 'wp_skip' ) ), 'the control: all lowercase ASCII' );
		foreach (
			array(
				'the table wp_Old_posts of this database'     => array( array( 'wp_options' ), array( 'wp_Old_posts' ), array() ),
				'the table wp_Skip left out of the restore'   => array( array( 'wp_options' ), array(), array( 'wp_Skip' ) ),
				"the backup's table wp_\xC3\xA4rger"         => array( array( 'wp_options', "wp_\xC3\xA4rger" ), array(), array() ),
			) as $what => $given
		) {
			$this->assertStringContainsString( $what . ' has upper-case letters or letters outside ASCII', IncomingTables::fold_refusal( true, 'wp_', null, null, $given[0], $given[1], $given[2] ) );
		}
	}

	public function test_with_an_empty_prefix_nothing_is_judged(): void {
		$live = array_merge( self::site( '' ), array( 'users', 'usermeta' ), self::site( 'wp_' ) );
		$this->assertSame( array(), IncomingTables::classify( '', false, $live, array( 'wp_posts', 'options' ), self::site( '' ), 'users', 'usermeta', false ) );
	}
}
