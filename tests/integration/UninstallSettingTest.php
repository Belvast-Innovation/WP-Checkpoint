<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Admin\Notices;
use WPCheckpoint\Admin\SettingsActions;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\Uninstaller;
use WPCheckpoint\Support\UninstallSetting;

final class UninstallSettingTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( UninstallSetting::OPTION );
		if ( is_multisite() ) {
			delete_site_option( UninstallSetting::OPTION );
			delete_site_option( UninstallSetting::NOTICE_FLAG );
		}
		Options::delete( Directories::OPTION );
		Plugin::instance()->reset_directories();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		wp_set_current_user( $admin );
	}

	public function tear_down(): void {
		foreach ( glob( WP_CONTENT_DIR . '/wp-checkpoint-*' ) ?: array() as $dir ) {
			Deleter::empty_directory( $dir );
			@rmdir( $dir );
		}
		Options::delete( Directories::OPTION );
		Plugin::instance()->reset_directories();
		parent::tear_down();
	}

	public function test_single_site_saves_a_site_option(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single site only.' );
		}
		( new SettingsActions() )->run_save( true );
		$this->assertTrue( (bool) get_option( UninstallSetting::OPTION ) );
		$this->assertTrue( Uninstaller::should_delete_data() );
		( new SettingsActions() )->run_save( false );
		$this->assertFalse( Uninstaller::should_delete_data() );
		$this->assertSame( array( 'ran' => false, 'scanned' => false, 'leftover' => false ), UninstallSetting::migrate_multisite() );
	}

	public function test_multisite_reads_the_network_option_only(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		update_option( UninstallSetting::OPTION, true );
		update_site_option( UninstallSetting::OPTION, false );
		$this->assertFalse( UninstallSetting::enabled(), 'the site option is ignored' );
		$this->assertFalse( Uninstaller::should_delete_data() );

		( new SettingsActions() )->run_save( true );
		$this->assertTrue( (bool) get_site_option( UninstallSetting::OPTION ) );
		$this->assertTrue( Uninstaller::should_delete_data() );
	}

	public function test_migration_never_inherits_on_and_asks_for_confirmation(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$subsite = self::factory()->blog->create();
		update_option( UninstallSetting::OPTION, false );
		update_blog_option( $subsite, UninstallSetting::OPTION, true );

		$result = UninstallSetting::migrate_multisite();

		$this->assertSame( array( 'ran' => true, 'scanned' => true, 'leftover' => true ), $result );
		$this->assertFalse( (bool) get_site_option( UninstallSetting::OPTION ), 'network value starts off' );
		$this->assertTrue( UninstallSetting::needs_confirmation() );
		$this->assertSame( array( 'ran' => false, 'scanned' => false, 'leftover' => false ), UninstallSetting::migrate_multisite(), 'runs once' );

		$dirs    = Plugin::instance()->directories();
		$notices = ( new Notices( $dirs ) )->notices();
		$this->assertArrayHasKey( 'uninstall_setting', $notices );
		$this->assertStringContainsString( 'network-wide', $notices['uninstall_setting']['message'] );
		$this->assertStringContainsString( 'tab=settings', $notices['uninstall_setting']['link'][0] );

		( new Notices( $dirs ) )->record_dismissal( 'uninstall_setting' );
		$meta = get_user_meta( get_current_user_id(), Notices::USER_META, true );
		$this->assertArrayHasKey( 'uninstall_setting', $meta, 'dismissal is stored per user' );
		$this->assertTrue( UninstallSetting::needs_confirmation(), 'dismissing does not confirm' );

		( new SettingsActions() )->run_save( false );
		$this->assertFalse( UninstallSetting::needs_confirmation(), 'saving confirms' );
	}

	public function test_migration_without_leftover_on_shows_no_notice(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$subsite = self::factory()->blog->create();
		update_option( UninstallSetting::OPTION, false );
		update_blog_option( $subsite, UninstallSetting::OPTION, false );
		$this->assertSame( array( 'ran' => true, 'scanned' => true, 'leftover' => false ), UninstallSetting::migrate_multisite() );
		$this->assertFalse( UninstallSetting::needs_confirmation() );
		$this->assertArrayNotHasKey( 'uninstall_setting', ( new Notices( Plugin::instance()->directories() ) )->notices() );

		delete_site_option( UninstallSetting::OPTION );
		$this->assertSame( array( 'ran' => true, 'scanned' => true, 'leftover' => false ), UninstallSetting::migrate_multisite(), 'no site option at all' );
		$this->assertFalse( UninstallSetting::needs_confirmation() );
	}

	public function test_large_networks_are_not_scanned_but_still_get_the_notice(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		update_option( UninstallSetting::OPTION, true );
		$reads = 0;
		add_filter( 'option_' . UninstallSetting::OPTION, static function ( $value ) use ( &$reads ) {
			++$reads;
			return $value;
		} );

		$result = UninstallSetting::migrate_multisite( UninstallSetting::SCAN_LIMIT + 1 );

		$this->assertSame( array( 'ran' => true, 'scanned' => false, 'leftover' => false ), $result );
		$this->assertSame( 0, $reads, 'no per-site scan' );
		$this->assertFalse( (bool) get_site_option( UninstallSetting::OPTION ) );
		$this->assertSame( UninstallSetting::NOTICE_UNSCANNED, UninstallSetting::notice_reason() );
		$this->assertTrue( UninstallSetting::needs_confirmation() );
		$message = ( new Notices( Plugin::instance()->directories() ) )->notices()['uninstall_setting']['message'];
		$this->assertStringContainsString( 'initialised to off', $message );
		$this->assertStringNotContainsString( 'site-level setting was found', $message );

		Plugin::instance()->reset_directories();
		delete_site_option( UninstallSetting::OPTION );
		$this->assertSame( array( 'ran' => true, 'scanned' => true, 'leftover' => true ), UninstallSetting::migrate_multisite( UninstallSetting::SCAN_LIMIT ), 'at the limit the scan still runs' );
		$this->assertSame( UninstallSetting::NOTICE_LEFTOVER, UninstallSetting::notice_reason() );
	}

	public function test_delete_everywhere_walks_the_network_in_batches(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$sites = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$sites[] = self::factory()->blog->create();
		}
		foreach ( $sites as $site ) {
			update_blog_option( $site, UninstallSetting::OPTION, true );
		}
		update_option( UninstallSetting::OPTION, true );
		$this->assertGreaterThan( 2, get_sites( array( 'count' => true ) ) );

		UninstallSetting::delete_everywhere( 2 );

		foreach ( $sites as $site ) {
			$this->assertFalse( get_blog_option( $site, UninstallSetting::OPTION ), "site {$site} cleaned" );
		}
		$this->assertFalse( get_option( UninstallSetting::OPTION ) );
	}

	public function test_plugin_logs_the_migration_when_a_leftover_on_is_found(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		update_option( UninstallSetting::OPTION, true );
		Plugin::instance()->migrate_settings();
		$log = Plugin::instance()->directories()->logs() . '/storage.log';
		$this->assertFileExists( $log );
		$this->assertStringContainsString( 'network-wide', (string) file_get_contents( $log ) );
	}

	public function test_uninstall_removes_leftover_site_options_everywhere(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$subsite = self::factory()->blog->create();
		update_blog_option( $subsite, UninstallSetting::OPTION, true );
		update_option( UninstallSetting::OPTION, true );
		update_site_option( UninstallSetting::NOTICE_FLAG, 1 );
		UninstallSetting::save( true );

		Uninstaller::run();

		$this->assertFalse( get_site_option( UninstallSetting::OPTION ) );
		$this->assertFalse( get_site_option( UninstallSetting::NOTICE_FLAG ) );
		$this->assertFalse( get_option( UninstallSetting::OPTION ) );
		$this->assertFalse( get_blog_option( $subsite, UninstallSetting::OPTION ) );
	}
}
