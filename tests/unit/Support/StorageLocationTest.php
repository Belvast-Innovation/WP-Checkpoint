<?php

namespace WPCheckpoint\Tests\Unit\Support;

use WPCheckpoint\Support\OwnerMarker;
use WPCheckpoint\Support\StorageLocation;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Where a custom storage directory may be, against stand-ins for WordPress's directories in a sandbox (the function
 * only reads). The real directories are asked about in the integration tests.
 */
final class StorageLocationTest extends TestCase {

	/** @var string */
	private $sandbox = '';

	/** @var array<string, string> */
	private $within = array();

	/** @var array<string, string> */
	private $itself = array();

	protected function set_up(): void {
		parent::set_up();
		$this->sandbox = sys_get_temp_dir() . '/wpc-storage-location-' . bin2hex( random_bytes( 4 ) );
		foreach ( array( 'wp/wp-admin', 'wp/wp-content/uploads', 'wp/wp-content/plugins', 'wp/wp-content/themes', 'wp/wp-content/cache', 'media/uploads' ) as $dir ) {
			mkdir( $this->sandbox . '/' . $dir, 0755, true );
		}
		$this->sandbox = (string) realpath( $this->sandbox );
		$this->assertNotSame( '', $this->sandbox );
		$this->within = array(
			$this->sandbox . '/wp/wp-admin'            => 'wp-admin',
			$this->sandbox . '/wp/wp-content/uploads' => 'uploads',
			$this->sandbox . '/wp/wp-content/plugins' => 'plugins',
			$this->sandbox . '/wp/wp-content/themes'  => 'themes',
		);
		$this->itself = array( $this->sandbox . '/wp/wp-content' => 'wp-content' );
	}

	protected function tear_down(): void {
		if ( '' !== $this->sandbox ) {
			self::remove( $this->sandbox );
		}
		parent::tear_down();
	}

	/**
	 * Remove the sandbox (links as themselves).
	 */
	private static function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path ) || @rmdir( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( (array) scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				self::remove( $path . '/' . $entry );
			}
		}
		@rmdir( $path );
	}

	private function refusal( string $relative ): string {
		return StorageLocation::refusal( $this->sandbox . '/' . $relative, $this->within, $this->itself );
	}

	public function test_a_wordpress_directory_what_lies_in_it_and_what_holds_it_are_refused(): void {
		$this->assertSame( 'uploads', $this->refusal( 'wp/wp-content/uploads' ), 'an existing (empty) uploads directory' );
		$this->assertSame( 'uploads', $this->refusal( 'wp/wp-content/uploads/' ), 'with a trailing separator' );
		$this->assertSame( 'uploads', $this->refusal( 'wp/wp-content/uploads/wpc/new' ), 'a new directory inside it' );
		$this->assertSame( 'wp-admin', $this->refusal( 'wp/wp-admin' ) );
		$this->assertSame( 'themes', $this->refusal( 'wp/wp-content/themes/store' ) );
		$this->assertSame( 'wp-content', $this->refusal( 'wp/wp-content' ), 'the content directory itself' );
		$this->assertSame( 'wp-content', $this->refusal( 'wp' ), 'a directory that holds them' );
		unset( $this->itself[ $this->sandbox . '/wp/wp-content' ] );
		$this->assertSame( 'wp-admin', $this->refusal( 'wp' ), 'a directory that holds them, the content directory aside' );
		$this->assertSame( 'uploads', $this->refusal( 'wp/wp-content' ), 'the same' );

		$this->assertSame( '', $this->refusal( 'wp/wp-content/cache' ), 'the control: an existing directory of wp-content that is none of them' );
		$this->assertSame( '', $this->refusal( 'wp/wp-content/wpc-store' ), 'the control: a new directory in wp-content' );
		$this->assertSame( '', $this->refusal( 'wp/wp-content/uploads-old' ), 'the control: a name that only starts like one' );
		$this->assertSame( '', $this->refusal( 'wpc-store' ), 'the control: next to the WordPress directory' );
	}

	public function test_a_moved_directory_is_compared_where_it_is(): void {
		// The uploads moved out of wp-content (UPLOADS, upload_path): a directory that holds them is refused too.
		$this->within[ $this->sandbox . '/media/uploads' ] = 'uploads (moved)';
		$this->assertSame( 'uploads (moved)', $this->refusal( 'media' ) );
		$this->assertSame( 'uploads (moved)', $this->refusal( 'media/uploads/x' ) );
		$this->assertSame( '', $this->refusal( 'media-store' ), 'the control' );
	}

	public function test_a_link_does_not_get_around_it(): void {
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$this->markTestSkipped( 'Symbolic links need privileges on Windows.' );
		}
		symlink( $this->sandbox . '/wp/wp-content/uploads', $this->sandbox . '/to-uploads' );
		symlink( $this->sandbox . '/wp/wp-content/cache', $this->sandbox . '/to-cache' );
		$this->assertSame( 'uploads', $this->refusal( 'to-uploads' ), 'a link to it' );
		$this->assertSame( 'uploads', $this->refusal( 'to-uploads/new/store' ), 'a new directory under a link to it' );
		$this->assertSame( '', $this->refusal( 'to-cache' ), 'the control: a link to a directory that may be used' );
	}

	public function test_an_owner_marker_is_created_only_where_none_is(): void {
		$marker = $this->sandbox . '/' . OwnerMarker::FILENAME;
		$this->assertTrue( OwnerMarker::create( $marker, "first\n" ), 'the control: created' );
		$this->assertSame( "first\n", file_get_contents( $marker ) );
		$this->assertFalse( OwnerMarker::create( $marker, "second\n" ) );
		$this->assertSame( "first\n", file_get_contents( $marker ), 'not overwritten' );
	}
}
