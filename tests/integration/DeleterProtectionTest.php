<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Deleter;

/**
 * The real WordPress directories, asked about through Deleter::refusal() only (it deletes nothing): the
 * Deleter's own tests work in a sandbox with stand-ins for them (DeleterGuardTest).
 */
final class DeleterProtectionTest extends WP_UnitTestCase {

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function wordpress_dirs(): array {
		return array(
			'ABSPATH'                  => array( 'ABSPATH', 'ABSPATH' ),
			'what holds ABSPATH'       => array( 'dirname:ABSPATH', 'ABSPATH' ),
			'wp-content'               => array( 'WP_CONTENT_DIR', 'content directory' ),
			'the plugins directory'    => array( 'WP_PLUGIN_DIR', 'plugins directory' ),
			'the plugin itself'        => array( 'WPCHECKPOINT_DIR', 'directory of WP Checkpoint' ),
		);
	}

	/**
	 * @dataProvider wordpress_dirs
	 */
	public function test_the_wordpress_directories_and_what_holds_them_are_never_deleted( string $which, string $because ): void {
		$path = 0 === strpos( $which, 'dirname:' ) ? dirname( (string) constant( substr( $which, 8 ) ) ) : (string) constant( $which );
		$this->assertStringContainsString( $because, Deleter::refusal( $path ) );
		$this->assertStringContainsString( $because, Deleter::refusal( rtrim( $path, '/' ) . '/' ), 'with a trailing separator' );
		$this->assertStringContainsString( $because, Deleter::storage_refusal( $path ), 'nor can it be a storage directory' );
	}

	public function test_the_root_and_the_site_s_other_directories_are_refused_and_the_storage_directory_is_not(): void {
		$this->assertStringContainsString( 'root of the file system', Deleter::refusal( '/' ) );
		$this->assertStringContainsString( 'outside every directory', Deleter::refusal( wp_upload_dir()['basedir'] ), 'uploads: not a directory of the plugin' );
		$this->assertStringContainsString( 'outside every directory', Deleter::refusal( WP_CONTENT_DIR . '/themes' ) );
		$storage = Plugin::instance()->directories()->base();
		$this->assertNotSame( '', $storage );
		$this->assertSame( '', Deleter::refusal( $storage . '/tmp' ), 'the control: inside the storage directory (its owner marker)' );
		$this->assertSame( '', Deleter::storage_refusal( $storage ), 'the control: the storage directory can be one' );
		$this->assertSame( '', Deleter::storage_refusal( WP_CONTENT_DIR . '/wpc-storage' ), 'the control: a new directory in wp-content can be one' );
	}
}
