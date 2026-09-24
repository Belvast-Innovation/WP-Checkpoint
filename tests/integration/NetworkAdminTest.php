<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Admin\Menu;
use WPCheckpoint\Admin\Notices;
use WPCheckpoint\Admin\Page;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\UninstallSetting;

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
			$this->assertNotSame( Page::post_url(), admin_url( 'admin-post.php' ) );
			$this->assertSame( network_site_url( 'wp-admin/admin-post.php', 'admin' ), Page::post_url(), 'forms and downloads go where the network admin is served, whichever site printed them' );
			$this->assertStringStartsWith( substr( network_admin_url(), 0, (int) strpos( network_admin_url(), '/wp-admin/' ) ) . '/wp-admin/', Page::post_url(), 'the same origin and path as the network admin' );
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
	/**
	 * What the notices print on a screen.
	 *
	 * @param string $screen Screen id.
	 * @return string
	 */
	private static function notices_on( string $screen ): string {
		set_current_screen( $screen );
		ob_start();
		do_action( 'all_admin_notices' );
		do_action( 'admin_notices' );
		return (string) ob_get_clean();
	}

	public function test_on_multisite_the_notices_show_on_the_network_page_once_and_nowhere_else(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		wp_set_current_user( self::$super );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'admin_notices' );
		( new Notices( Plugin::instance()->directories() ) )->register();
		update_site_option( UninstallSetting::NOTICE_FLAG, UninstallSetting::NOTICE_LEFTOVER );
		// The control: on the plugin's page in the network admin the notice is printed, once.
		$this->assertSame( 1, substr_count( self::notices_on( 'toplevel_page_' . Page::SLUG . '-network' ), 'network-wide and starts switched off' ) );
		foreach ( array( 'dashboard-network', 'dashboard', 'dashboard-user', 'toplevel_page_' . Page::SLUG . '-user' ) as $screen ) {
			$this->assertStringNotContainsString( 'network-wide', self::notices_on( $screen ), $screen );
		}
		delete_site_option( UninstallSetting::NOTICE_FLAG );
	}

	public function test_the_plugin_can_only_be_activated_for_the_whole_network(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$data = get_plugin_data( WPCHECKPOINT_FILE, false, false );
		$this->assertSame( 'WP Checkpoint', $data['Name'], 'the control: the header is read' );
		$this->assertTrue( $data['Network'], 'activated on one site only, it would have no page anywhere' );
	}
}
