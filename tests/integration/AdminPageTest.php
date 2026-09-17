<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Admin\Menu;
use WPCheckpoint\Admin\Page;
use WPCheckpoint\Admin\Settings;
use WPCheckpoint\Admin\Tab;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Guard;
use WPCheckpoint\Support\Uninstaller;

final class AdminPageTest extends WP_UnitTestCase {

	public function tear_down(): void {
		unset( $_GET['tab'] );
		remove_all_filters( 'wpcheckpoint_admin_tabs' );
		parent::tear_down();
	}

	private function render( Page $page ): string {
		ob_start();
		$page->render();
		return (string) ob_get_clean();
	}

	public function test_menu_is_registered_with_the_plugin_capability(): void {
		global $menu, $admin_page_hooks;
		$menu             = array();
		$admin_page_hooks = array();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		wp_set_current_user( $admin );

		$menu_registration = new Menu( Plugin::instance()->admin_page() );
		$menu_registration->add_menu_page();

		$this->assertNotSame( '', $menu_registration->hook_suffix() );
		$this->assertArrayHasKey( Page::SLUG, $admin_page_hooks );

		$entries = array_values( array_filter( $menu, static function ( $item ) {
			return isset( $item[2] ) && Page::SLUG === $item[2];
		} ) );
		$this->assertCount( 1, $entries );
		$this->assertSame( Guard::capability(), $entries[0][1] );
	}

	public function test_page_renders_all_four_tabs_with_first_active(): void {
		$html = $this->render( Plugin::instance()->admin_page() );

		$compact = preg_replace( '/>\s+/', '>', preg_replace( '/\s+</', '<', $html ) );
		foreach ( array( 'Backups', 'Checkpoints', 'Tools', 'Settings' ) as $label ) {
			$this->assertStringContainsString( '>' . $label . '</a>', $compact );
		}
		$this->assertSame( 1, substr_count( $html, 'nav-tab-active' ) );
		$this->assertStringContainsString( 'tab=backups" class="nav-tab nav-tab-active"', $html );
	}

	public function test_settings_tab_shows_uninstall_checkbox_reflecting_option(): void {
		$_GET['tab'] = 'settings';

		update_option( Uninstaller::OPTION_DELETE_DATA, false );
		$html = $this->render( Plugin::instance()->admin_page() );
		$this->assertStringContainsString( 'name="' . Uninstaller::OPTION_DELETE_DATA . '"', $html );
		$this->assertStringNotContainsString( "checked='checked'", $html );
		$this->assertStringContainsString( 'action="options.php"', $html );
		$this->assertStringContainsString( '_wpnonce', $html );

		update_option( Uninstaller::OPTION_DELETE_DATA, true );
		$html = $this->render( Plugin::instance()->admin_page() );
		$this->assertStringContainsString( "checked='checked'", $html );
	}

	public function test_unknown_tab_falls_back_to_first(): void {
		$_GET['tab'] = 'does-not-exist';
		$html        = $this->render( Plugin::instance()->admin_page() );
		$this->assertStringContainsString( 'tab=backups" class="nav-tab nav-tab-active"', $html );
	}

	public function test_tabs_filter_can_add_a_tab(): void {
		add_filter( 'wpcheckpoint_admin_tabs', static function ( array $tabs ): array {
			$tabs[] = new class() implements Tab {
				public function slug(): string {
					return 'pro';
				}
				public function label(): string {
					return 'Pro Extra';
				}
				public function render(): void {
					echo 'pro-content';
				}
			};
			$tabs[] = 'not a tab';
			return $tabs;
		} );

		$_GET['tab'] = 'pro';
		$html        = $this->render( Plugin::instance()->admin_page() );
		$this->assertStringContainsString( 'Pro Extra', $html );
		$this->assertStringContainsString( 'pro-content', $html );
	}

	public function test_setting_is_registered_with_boolean_sanitizer(): void {
		global $wp_registered_settings;
		( new Settings() )->register_settings();

		$this->assertArrayHasKey( Uninstaller::OPTION_DELETE_DATA, $wp_registered_settings );
		$this->assertSame( 'boolean', $wp_registered_settings[ Uninstaller::OPTION_DELETE_DATA ]['type'] );

		$this->assertTrue( sanitize_option( Uninstaller::OPTION_DELETE_DATA, '1' ) );
		$this->assertTrue( sanitize_option( Uninstaller::OPTION_DELETE_DATA, 'on' ) );
		$this->assertFalse( sanitize_option( Uninstaller::OPTION_DELETE_DATA, '' ) );
		$this->assertFalse( sanitize_option( Uninstaller::OPTION_DELETE_DATA, 'anything-else' ) );
	}
}
