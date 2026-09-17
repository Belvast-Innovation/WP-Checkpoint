<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Support\Uninstaller;
use WPCheckpoint\Support\UninstallSetting;

final class UninstallerTest extends WP_UnitTestCase {

	public function test_keeps_options_by_default(): void {
		update_option( Uninstaller::OPTION_VERSION, '1.2.3' );
		UninstallSetting::save( false );

		$this->assertFalse( Uninstaller::should_delete_data() );
		Uninstaller::run();

		$this->assertSame( '1.2.3', get_option( Uninstaller::OPTION_VERSION ) );
	}

	public function test_deletes_options_when_opted_in(): void {
		update_option( Uninstaller::OPTION_VERSION, '1.2.3' );
		UninstallSetting::save( true );

		$this->assertTrue( Uninstaller::should_delete_data() );
		Uninstaller::run();

		foreach ( Uninstaller::OPTIONS as $option ) {
			$this->assertFalse( get_option( $option ), "{$option} should be deleted" );
			if ( is_multisite() ) {
				$this->assertFalse( get_site_option( $option ), "{$option} should be deleted network-wide" );
			}
		}
	}

	public function test_uninstall_file_is_guarded(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );
		$this->assertStringContainsString( "defined( 'WP_UNINSTALL_PLUGIN' ) || exit;", $source );
		$this->assertStringContainsString( 'spl_autoload_register', $source );
		$this->assertStringNotContainsString( 'wp-checkpoint.php', substr( $source, strpos( $source, 'exit;' ) ) );
	}
}
