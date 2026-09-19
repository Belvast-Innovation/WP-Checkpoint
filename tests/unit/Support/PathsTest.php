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
		if ( is_link( $path ) ) {
			// On Windows a symlink to a directory must be removed with rmdir().
			if ( ! @unlink( $path ) ) {
				rmdir( $path );
			}
			return;
		}
		if ( is_file( $path ) ) {
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

	private function require_symlinks(): void {
		$probe = $this->root . '/probe-link';
		if ( ! @symlink( $this->root . '/outside/secret.txt', $probe ) ) {
			$this->markTestSkipped( 'Symbolic links cannot be created in this environment.' );
		}
		unlink( $probe );
	}

	public function test_trailing_separator_on_a_symlink_is_still_rejected(): void {
		$this->require_symlinks();
		symlink( $this->base() . '/sub', $this->base() . '/link-in' );
		symlink( $this->root . '/outside', $this->base() . '/link-out' );

		$this->assertFalse( Paths::is_inside( $this->base(), $this->base() . '/link-in/' ) );
		$this->assertFalse( Paths::is_inside( $this->base(), $this->base() . '/link-in//' ) );
		$this->assertFalse( Paths::is_inside( $this->base(), $this->base() . '/link-out/' ) );
		$this->assertTrue( Paths::is_inside( $this->base(), $this->base() . '/sub/' ), 'trailing slash on a real directory is fine' );
	}

	public function test_only_separators_is_rejected(): void {
		$this->assertFalse( Paths::is_inside( $this->base(), '/' ) );
		$this->assertFalse( Paths::is_inside( $this->base(), '///' ) );
	}

	public function test_prefix_comparison_normalises_separators(): void {
		$this->assertTrue( Paths::is_prefix( 'C:\\sites\\base', 'C:/sites/base/file.txt', false ) );
		$this->assertTrue( Paths::is_prefix( '/srv/base/', '/srv/base//sub/file.txt', false ) );
		$this->assertFalse( Paths::is_prefix( '/srv/base', '/srv/base', false ) );
		$this->assertFalse( Paths::is_prefix( '/srv/base', '/srv/base/', false ) );
		$this->assertFalse( Paths::is_prefix( '/srv/base', '/srv/base-evil/file.txt', false ) );
	}

	public function test_prefix_comparison_is_case_sensitive_unless_asked(): void {
		$this->assertFalse( Paths::is_prefix( '/srv/base', '/srv/BASE/file.txt', false ) );
		$this->assertTrue( Paths::is_prefix( '/srv/base', '/srv/BASE/file.txt', true ) );
		$this->assertTrue( Paths::is_prefix( 'c:\\Sites\\Base', 'C:\\sites\\base\\file.txt', true ), 'drive letter and directory case are ignored on Windows' );
		$this->assertFalse( Paths::is_prefix( 'c:\\sites\\base', 'D:\\sites\\base\\file.txt', true ), 'a different drive is never inside' );
	}

	public function test_case_differences_on_a_case_sensitive_filesystem_are_rejected(): void {
		if ( Paths::is_windows() ) {
			$this->markTestSkipped( 'Case-sensitive filesystem only.' );
		}
		$this->assertFalse( Paths::is_inside( $this->base(), $this->base() . '/FILE.txt' ) );
	}

	public function test_windows_case_and_drive_letter_are_ignored(): void {
		if ( ! Paths::is_windows() ) {
			$this->markTestSkipped( 'Windows only.' );
		}
		$upper = strtoupper( substr( $this->base(), 0, 1 ) ) . substr( $this->base(), 1 );
		$lower = strtolower( substr( $this->base(), 0, 1 ) ) . substr( $this->base(), 1 );
		$this->assertTrue( Paths::is_inside( $lower, $upper . '/FILE.TXT' ) );
		$this->assertTrue( Paths::is_inside( $upper, $lower . '\\sub\\deep.txt' ) );
		$this->assertFalse( Paths::is_inside( $upper, $this->root . '/OUTSIDE/secret.txt' ) );
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
		$this->require_symlinks();
		symlink( $this->base() . '/file.txt', $this->base() . '/link-inside' );
		symlink( $this->root . '/outside/secret.txt', $this->base() . '/link-outside' );
		$this->assertFalse( Paths::is_inside( $this->base(), $this->base() . '/link-inside' ) );
		$this->assertFalse( Paths::is_inside( $this->base(), $this->base() . '/link-outside' ) );
	}

	public function test_path_through_symlinked_directory_pointing_outside_is_rejected(): void {
		$this->require_symlinks();
		symlink( $this->root . '/outside', $this->base() . '/linked-dir' );
		$this->assertFalse( Paths::is_inside( $this->base(), $this->base() . '/linked-dir/secret.txt' ) );
	}

	public function test_symlinked_base_resolves_to_real_directory(): void {
		if ( Paths::is_windows() ) {
			// PHP's realpath() on Windows leaves a symlink alone when it is the final path
			// component, so a symlinked base is not resolved there; is_inside() then rejects
			// every target (fail-closed), which is acceptable but not what this test asserts.
			$this->markTestSkipped( 'realpath() does not resolve a final-component symlink on Windows.' );
		}
		$this->require_symlinks();
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

	public function test_same_or_inside_counts_the_base_itself(): void {
		$this->assertTrue( Paths::is_same_or_inside( $this->base(), $this->base() ) );
		$this->assertTrue( Paths::is_same_or_inside( $this->base(), $this->base() . '/' ) );
		$this->assertTrue( Paths::is_same_or_inside( $this->base(), $this->base() . '/sub/deep.txt' ) );
		$this->assertFalse( Paths::is_same_or_inside( $this->base(), $this->root . '/outside' ) );
		$this->assertFalse( Paths::is_same_or_inside( $this->base(), $this->root . '/base-missing' ) );
		$this->assertTrue( Paths::same( '/a/b/', '/a//b', false ) );
		$this->assertFalse( Paths::same( '/a/B', '/a/b', false ) );
		$this->assertTrue( Paths::same( 'C:\\A\\B', 'c:/a/b/', true ) );
	}

	public function test_empty_and_separator_only_inputs_fail_closed_everywhere(): void {
		$cwd = getcwd();
		chdir( $this->root . '/base' );
		try {
			foreach ( array( '', '/', '//', '\\', '/\\/' ) as $bad ) {
				$this->assertFalse( Paths::is_inside( $bad, $this->root . '/base/file.txt' ), "is_inside base [{$bad}]" );
				$this->assertFalse( Paths::is_inside( $this->root . '/base', $bad ), "is_inside target [{$bad}]" );
				$this->assertFalse( Paths::is_same_or_inside( $bad, $this->root . '/base' ), "is_same_or_inside base [{$bad}]" );
				$this->assertFalse( Paths::is_same_or_inside( $this->root . '/base', $bad ), "is_same_or_inside target [{$bad}]" );
				$this->assertFalse( Paths::same( $bad, $bad, false ), "same [{$bad}]" );
				$this->assertFalse( Paths::same( $bad, $this->root . '/base', true ), "same one side [{$bad}]" );
			}
			// The working directory is the base here, and still nothing empty resolves to it.
			$this->assertFalse( Paths::is_same_or_inside( '', $this->root . '/base' ) );
			$this->assertTrue( Paths::is_same_or_inside( $this->root . '/base', $this->root . '/base/' ), 'a trailing separator on a real path is still fine' );
		} finally {
			chdir( (string) $cwd );
		}
	}

	public function test_base_must_be_a_directory(): void {
		$this->assertFalse( Paths::is_inside( $this->base() . '/file.txt', $this->base() . '/file.txt' ) );
	}
}
