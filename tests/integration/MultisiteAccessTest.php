<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Admin\Menu;
use WPCheckpoint\Admin\Page;
use WPCheckpoint\Admin\SettingsActions;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Guard;
use WPCheckpoint\Support\Uninstaller;

/**
 * On multisite only super admins may see the admin page or change settings.
 * A site administrator (no network capability) must be refused by both gates
 * that options.php and admin.php rely on.
 *
 * @group ms-required
 */
final class MultisiteAccessTest extends WP_UnitTestCase {

	/** @var int */
	private static $site_admin;

	/** @var int */
	private static $super_admin;

	public static function wpSetUpBeforeClass( $factory ): void {
		self::$site_admin  = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$super_admin = $factory->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( self::$super_admin );
		}
	}

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	public function tear_down(): void {
		global $wp_settings_errors;
		$wp_settings_errors = array();
		unset( $GLOBALS['plugin_page'] );
		parent::tear_down();
	}

	/**
	 * Emulate the admin.php access check for ?page=wp-checkpoint.
	 */
	private function can_access_admin_page(): bool {
		global $menu, $submenu, $_wp_menu_nopriv, $_wp_submenu_nopriv, $_registered_pages, $admin_page_hooks, $pagenow, $plugin_page;
		$menu               = array();
		$submenu            = array();
		$_wp_menu_nopriv    = array();
		$_wp_submenu_nopriv = array();
		$_registered_pages  = array();
		$admin_page_hooks   = array();
		$pagenow            = 'admin.php';
		$plugin_page        = Page::SLUG;

		$this->menu = new Menu( Plugin::instance()->admin_page() );
		$this->menu->add_menu_page();

		// wp-admin/includes/menu.php marks menus the user may not see before admin.php checks access.
		foreach ( $menu as $data ) {
			if ( ! current_user_can( $data[1] ) ) {
				$_wp_menu_nopriv[ $data[2] ] = true;
			}
		}

		return user_can_access_admin_page();
	}

	/** @var Menu|null */
	private $menu;

	public function test_site_admin_lacks_the_plugin_capability(): void {
		wp_set_current_user( self::$site_admin );
		$this->assertTrue( current_user_can( 'manage_options' ) );
		$this->assertSame( 'manage_network_options', Guard::capability() );
		$this->assertFalse( Guard::current_user_can() );
	}

	public function test_site_admin_is_denied_the_admin_page(): void {
		wp_set_current_user( self::$site_admin );
		$this->assertFalse( $this->can_access_admin_page() );
		$this->assertArrayHasKey( Page::SLUG, $GLOBALS['_wp_menu_nopriv'] );
		$this->assertFalse( has_action( $this->menu->hook_suffix() ), 'no render callback is attached for this user' );
	}

	public function test_super_admin_can_access_the_admin_page(): void {
		wp_set_current_user( self::$super_admin );
		$this->assertTrue( $this->can_access_admin_page() );
		$this->assertNotFalse( has_action( $this->menu->hook_suffix() ) );
	}

	public function test_site_admin_cannot_save_the_settings(): void {
		update_site_option( Uninstaller::OPTION_DELETE_DATA, false );
		wp_set_current_user( self::$site_admin );
		$_REQUEST['_wpnonce']                       = Guard::nonce( SettingsActions::NONCE_ACTION );
		$_POST[ Uninstaller::OPTION_DELETE_DATA ] = '1';
		try {
			$this->expectException( \WPDieException::class );
			( new SettingsActions() )->save();
		} finally {
			unset( $_REQUEST['_wpnonce'], $_POST[ Uninstaller::OPTION_DELETE_DATA ] );
			$this->assertFalse( (bool) get_site_option( Uninstaller::OPTION_DELETE_DATA ), 'network option unchanged' );
		}
	}

	public function test_super_admin_saves_the_network_option(): void {
		update_site_option( Uninstaller::OPTION_DELETE_DATA, false );
		wp_set_current_user( self::$super_admin );

		( new SettingsActions() )->run_save( true );

		$this->assertTrue( (bool) get_site_option( Uninstaller::OPTION_DELETE_DATA ) );
		$this->assertTrue( Uninstaller::should_delete_data() );
	}
}
