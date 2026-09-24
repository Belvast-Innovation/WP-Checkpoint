<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Admin\Menu;
use WPCheckpoint\Admin\Page;
use WPCheckpoint\Plugin;

/**
 * On multisite the plugin page is only in the network admin: a backup covers
 * every site and a restore overwrites every site. On a single site nothing
 * changes. The REST side of the same boundary is in RestPermissionsTest.
 */
final class NetworkAdminTest extends WP_UnitTestCase {

	/** @var int */
	private static $super;

	public static function wpSetUpBeforeClass( $factory ): void {
		self::$super = $factory->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( self::$super );
		}
	}

	public function set_up(): void {
		parent::set_up();
		// The admin layer boots only when is_admin(); the menu is registered as it would be there.
		( new Menu( Plugin::instance()->admin_page() ) )->register();
	}

	public function tear_down(): void {
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Build a menu as core does on one admin screen: clear what an earlier
	 * build left, set the screen, fire the screen's menu action.
	 *
	 * @param string $screen Screen id (a "-network" id is the network admin).
	 * @param string $action admin_menu or network_admin_menu.
	 * @return string|null The page's hook, as admin.php looks it up (null: "Sorry, you are not allowed to access this page").
	 */
	private function page_hook_on( string $screen, string $action ): ?string {
		global $admin_page_hooks, $_registered_pages;
		$admin_page_hooks  = array();
		$_registered_pages = array();
		remove_all_actions( 'toplevel_page_' . Page::SLUG );
		set_current_screen( $screen );
		do_action( $action, '' );
		return get_plugin_page_hook( Page::SLUG, 'admin.php' );
	}

	public function test_on_multisite_only_the_network_admin_has_the_page(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		wp_set_current_user( self::$super );
		// The control: the network admin builds the page, so the observation sees a registered page.
		$this->assertSame( 'toplevel_page_' . Page::SLUG, $this->page_hook_on( 'dashboard-network', 'network_admin_menu' ) );
		$this->assertTrue( is_network_admin() );
		// A site's dashboard does not, even for a network administrator: a direct visit to the page is refused.
		$this->assertNull( $this->page_hook_on( 'dashboard', 'admin_menu' ) );
		$this->assertFalse( is_network_admin() );
	}

	public function test_on_a_single_site_the_page_stays_in_the_dashboard(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single site only.' );
		}
		wp_set_current_user( self::$super );
		$this->assertSame( 'toplevel_page_' . Page::SLUG, $this->page_hook_on( 'dashboard', 'admin_menu' ) );
		$this->assertSame( admin_url( 'admin.php' ), Page::base_url() );
		$this->assertSame( admin_url( 'admin-post.php' ), Page::post_url() );
	}

	public function test_on_multisite_every_link_to_the_page_goes_to_the_network_admin(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$this->assertSame( network_admin_url( 'admin.php' ), Page::base_url() );
		$this->assertStringStartsWith( network_admin_url( 'admin.php' ) . '?page=' . Page::SLUG, Plugin::instance()->admin_page()->tab_url( 'backups' ) );
		$site = self::factory()->blog->create();
		switch_to_blog( $site );
		try {
			// The control: printed on another site, the site's own admin-post.php is somewhere else.
			$this->assertNotSame( get_admin_url( get_main_site_id(), 'admin-post.php' ), admin_url( 'admin-post.php' ) );
			$this->assertSame( get_admin_url( get_main_site_id(), 'admin-post.php' ), Page::post_url(), 'forms and downloads go to the main site, whichever site printed them' );
			$this->assertSame( network_admin_url( 'admin.php' ), Page::base_url() );
		} finally {
			restore_current_blog();
		}
	}

	public function test_the_page_is_recognised_on_both_admin_screens(): void {
		$this->assertTrue( Page::is_screen( 'toplevel_page_' . Page::SLUG ) );
		$this->assertTrue( Page::is_screen( 'toplevel_page_' . Page::SLUG . '-network' ) );
		$this->assertFalse( Page::is_screen( 'dashboard-network' ) );
	}
}
