<?php

namespace WPCheckpoint\Tests\Unit\Support;

use WPCheckpoint\Support\Paths;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class PathsTest extends TestCase {

	/** @var string */
	private $root;

	protected function set_up(): void {
		$this->root = sys_get_temp_dir() . '/wpcheckpoint-paths-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/base/sub', 0700, true );
		mkdir( $this->root . '/outside', 0700, true );
		touch( $this->root . '/base/file.txt' );
		touch( $this->root . '/base/sub/deep.txt' );
		touch( $this->root . '/outside/secret.txt' );
	}

	protected function tear_down(): void {
		$this->remove( $this->root );
	}

	private function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->remove( $path . '/' . $entry );
			}
		}
		rmdir( $path );
	}

	private function base(): string {
		return $this->root . '/base';
	}

	public function test_file_inside_base_is_inside(): void {
		$this->assertTrue( Paths::is_inside( $this->base(), $this->base() . '/file.txt' ) );
		$this->assertTrue( Paths::is_inside( $this->base(), $this->base() . '/sub' ) );
		$this->assertTrue( Paths::is_inside( $this->base(), $this->base() . '/sub/deep.txt' ) );
	}

	public function test_base_itself_is_not_inside(): void {
		$this->assertFalse( Paths::is_inside( $this->base(), $this->base() ) );
		$this->assertFalse( Paths::is_inside( $this->base(), $this->base() . '/' ) );
		$this->assertFalse( Paths::is_inside( $this->base(), $this->base() . '/sub/..' ) );
	}

	public function test_paths_outside_base_are_rejected(): void {
		$this->assertFalse( Paths::is_inside( $this->base(), $this->root . '/outside/secret.txt' ) );
		$this->assertFalse( Paths::is_inside( $this->base(), $this->root ) );
	}

	public function test_dot_dot_traversal_is_rejected(): void {
		$this->assertFalse( Paths::is_inside( $this->base(), $this->base() . '/sub/../../outside/secret.txt' ) );
		$this->assertFalse( Paths::is_inside( $this->base(), $this->base() . '/../outside' ) );
	}

	public function test_sibling_directory_with_base_as_prefix_is_rejected(): void {
		mkdir( $this->root . '/base-evil' );
		touch( $this->root . '/base-evil/x.txt' );
		$this->assertFalse( Paths::is_inside( $this->base(), $this->root . '/base-evil/x.txt' ) );
	}

	public function test_symlink_target_is_rejected_even_when_inside(): void {
		symlink( $this->base() . '/file.txt', $this->base() . '/link-inside' );
		symlink( $this->root . '/outside/secret.txt', $this->base() . '/link-outside' );
		$this->assertFalse( Paths::is_inside( $this->base(), $this->base() . '/link-inside' ) );
		$this->assertFalse( Paths::is_inside( $this->base(), $this->base() . '/link-outside' ) );
	}

	public function test_path_through_symlinked_directory_pointing_outside_is_rejected(): void {
		symlink( $this->root . '/outside', $this->base() . '/linked-dir' );
		$this->assertFalse( Paths::is_inside( $this->base(), $this->base() . '/linked-dir/secret.txt' ) );
	}

	public function test_symlinked_base_resolves_to_real_directory(): void {
		symlink( $this->base(), $this->root . '/base-link' );
		$this->assertTrue( Paths::is_inside( $this->root . '/base-link', $this->base() . '/file.txt' ) );
		$this->assertFalse( Paths::is_inside( $this->root . '/base-link', $this->root . '/outside/secret.txt' ) );
	}

	public function test_missing_paths_and_empty_strings_are_rejected(): void {
		$this->assertFalse( Paths::is_inside( $this->base(), $this->base() . '/does-not-exist' ) );
		$this->assertFalse( Paths::is_inside( $this->root . '/missing-base', $this->base() . '/file.txt' ) );
		$this->assertFalse( Paths::is_inside( '', $this->base() . '/file.txt' ) );
		$this->assertFalse( Paths::is_inside( $this->base(), '' ) );
	}

	public function test_base_must_be_a_directory(): void {
		$this->assertFalse( Paths::is_inside( $this->base() . '/file.txt', $this->base() . '/file.txt' ) );
	}
}
