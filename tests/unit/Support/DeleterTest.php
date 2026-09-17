<?php

namespace WPCheckpoint\Tests\Unit\Support;

use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Paths;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class DeleterTest extends TestCase {

	/** @var string */
	private $root;

	protected function set_up(): void {
		$this->root = sys_get_temp_dir() . '/wpcheckpoint-deleter-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/base/a/b', 0700, true );
		mkdir( $this->root . '/outside/dir', 0700, true );
		file_put_contents( $this->root . '/base/top.txt', 'x' );
		file_put_contents( $this->root . '/base/a/b/deep.txt', 'x' );
		file_put_contents( $this->root . '/outside/secret.txt', 'keep' );
		file_put_contents( $this->root . '/outside/dir/inner.txt', 'keep' );
	}

	protected function tear_down(): void {
		$this->nuke( $this->root );
	}

	private function nuke( string $path ): void {
		if ( is_link( $path ) ) {
			if ( ! @unlink( $path ) ) {
				rmdir( $path );
			}
			return;
		}
		if ( is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->nuke( $path . '/' . $entry );
			}
		}
		rmdir( $path );
	}

	private function base(): string {
		return $this->root . '/base';
	}

	private function require_symlinks(): void {
		$probe = $this->root . '/probe';
		if ( ! @symlink( $this->root . '/outside/secret.txt', $probe ) ) {
			$this->markTestSkipped( 'Symbolic links cannot be created in this environment.' );
		}
		unlink( $probe );
	}

	private function assert_outside_untouched(): void {
		$this->assertFileExists( $this->root . '/outside/secret.txt' );
		$this->assertFileExists( $this->root . '/outside/dir/inner.txt' );
		$this->assertSame( 'keep', file_get_contents( $this->root . '/outside/dir/inner.txt' ) );
	}

	public function test_deletes_nested_tree(): void {
		$result = Deleter::delete_tree( $this->base(), $this->base() . '/a' );
		$this->assertSame( array(), $result['failed'] );
		$this->assertSame( 3, $result['deleted'] );
		$this->assertDirectoryDoesNotExist( $this->base() . '/a' );
		$this->assertFileExists( $this->base() . '/top.txt' );
	}

	public function test_refuses_targets_outside_and_the_base_itself(): void {
		$this->assertNotEmpty( Deleter::delete_tree( $this->base(), $this->root . '/outside' )['failed'] );
		$this->assertNotEmpty( Deleter::delete_tree( $this->base(), $this->base() )['failed'] );
		$this->assertNotEmpty( Deleter::delete_tree( $this->base(), $this->base() . '/a/../../outside/secret.txt' )['failed'] );
		$this->assertNotEmpty( Deleter::delete_tree( $this->base(), '' )['failed'] );
		$this->assert_outside_untouched();
		$this->assertDirectoryExists( $this->base() . '/a/b' );
	}

	public function test_trailing_slash_target(): void {
		$result = Deleter::delete_tree( $this->base(), $this->base() . '/a/' );
		$this->assertSame( array(), $result['failed'] );
		$this->assertDirectoryDoesNotExist( $this->base() . '/a' );
	}

	public function test_links_are_removed_without_touching_targets(): void {
		$this->require_symlinks();
		symlink( $this->root . '/outside/secret.txt', $this->base() . '/a/link-file-out' );
		symlink( $this->root . '/outside/dir', $this->base() . '/a/link-dir-out' );
		symlink( $this->base() . '/top.txt', $this->base() . '/a/link-file-in' );
		symlink( $this->base() . '/a/b', $this->base() . '/a/link-dir-in' );

		$result = Deleter::delete_tree( $this->base(), $this->base() . '/a' );

		$this->assertSame( array(), $result['failed'] );
		$this->assertDirectoryDoesNotExist( $this->base() . '/a' );
		$this->assert_outside_untouched();
		$this->assertFileExists( $this->base() . '/top.txt', 'target of an inside link survives' );
	}

	public function test_link_as_target_is_unlinked_only(): void {
		$this->require_symlinks();
		symlink( $this->root . '/outside/dir', $this->base() . '/link-dir-out' );
		$result = Deleter::delete_tree( $this->base(), $this->base() . '/link-dir-out' );
		$this->assertSame( array(), $result['failed'] );
		$this->assertFalse( is_link( $this->base() . '/link-dir-out' ) );
		$this->assert_outside_untouched();

		$result = Deleter::delete_tree( $this->base(), $this->base() . '/link-dir-out/' );
		$this->assertNotEmpty( $result['failed'], 'already gone' );
	}

	public function test_link_with_trailing_slash_is_still_a_link(): void {
		$this->require_symlinks();
		symlink( $this->root . '/outside/dir', $this->base() . '/link-dir-out' );
		$result = Deleter::delete_tree( $this->base(), $this->base() . '/link-dir-out/' );
		$this->assertSame( array(), $result['failed'] );
		$this->assertFalse( is_link( $this->base() . '/link-dir-out' ) );
		$this->assert_outside_untouched();
	}

	public function test_empty_directory_keeps_base(): void {
		$this->require_symlinks();
		symlink( $this->root . '/outside/dir', $this->base() . '/link-dir-out' );
		$result = Deleter::empty_directory( $this->base() );
		$this->assertSame( array(), $result['failed'] );
		$this->assertDirectoryExists( $this->base() );
		$this->assertSame( array( '.', '..' ), scandir( $this->base() ) );
		$this->assert_outside_untouched();
	}

	public function test_is_reparse_detects_links(): void {
		$this->require_symlinks();
		symlink( $this->root . '/outside/dir', $this->base() . '/ld' );
		$this->assertTrue( Deleter::is_reparse( $this->base() . '/ld' ) );
		$this->assertTrue( Deleter::is_reparse( $this->base() . '/ld/' ) );
		$this->assertFalse( Deleter::is_reparse( $this->base() . '/a' ) );
		$this->assertFalse( Deleter::is_reparse( $this->base() . '/top.txt' ) );
		$this->assertFalse( Deleter::is_reparse( $this->base() . '/missing' ) );
	}

	public function test_leading_link_child_does_not_make_the_directory_a_link(): void {
		$this->require_symlinks();
		// "0-link" sorts before "a" and "top.txt": the first scandir() entry is a link.
		symlink( $this->root . '/outside/dir', $this->base() . '/0-link' );
		mkdir( $this->base() . '/mixed' );
		symlink( $this->root . '/outside/secret.txt', $this->base() . '/mixed/0-link' );
		file_put_contents( $this->base() . '/mixed/real.txt', 'x' );

		$this->assertSame( Deleter::REPARSE_PLAIN, Deleter::reparse_state( $this->base() ) );
		$this->assertFalse( Deleter::is_reparse( $this->base() ) );
		$this->assertSame( Deleter::REPARSE_PLAIN, Deleter::reparse_state( $this->base() . '/mixed' ) );

		$result = Deleter::delete_tree( $this->base(), $this->base() . '/mixed' );
		$this->assertSame( array(), $result['failed'] );
		$this->assertDirectoryDoesNotExist( $this->base() . '/mixed' );
		$this->assert_outside_untouched();
	}

	public function test_empty_or_link_only_directories_are_undecidable_but_deletable(): void {
		$this->require_symlinks();
		mkdir( $this->base() . '/empty' );
		mkdir( $this->base() . '/links-only' );
		symlink( $this->root . '/outside/dir', $this->base() . '/links-only/d' );
		symlink( $this->root . '/outside/secret.txt', $this->base() . '/links-only/f' );

		if ( Paths::is_windows() ) {
			$this->assertNotSame( Deleter::REPARSE_LINK, Deleter::reparse_state( $this->base() . '/empty' ) );
		} else {
			$this->assertSame( Deleter::REPARSE_UNKNOWN, Deleter::reparse_state( $this->base() . '/empty' ) );
			$this->assertSame( Deleter::REPARSE_UNKNOWN, Deleter::reparse_state( $this->base() . '/links-only' ) );
		}
		$this->assertFalse( Deleter::is_reparse( $this->base() . '/empty' ), 'unknown counts as not a link for deletion' );
		$this->assertFalse( Deleter::is_reparse( $this->base() . '/links-only' ) );

		$this->assertSame( array(), Deleter::delete_tree( $this->base(), $this->base() . '/empty' )['failed'] );
		$this->assertSame( array(), Deleter::delete_tree( $this->base(), $this->base() . '/links-only' )['failed'] );
		$this->assertDirectoryDoesNotExist( $this->base() . '/links-only' );
		$this->assert_outside_untouched();
	}

	public function test_windows_junction_to_an_empty_directory_is_not_plain(): void {
		if ( ! Paths::is_windows() ) {
			$this->markTestSkipped( 'Windows only.' );
		}
		mkdir( $this->root . '\outside\empty' );
		$junction = $this->base() . '\junction-empty';
		exec( 'cmd /c mklink /J "' . $junction . '" "' . $this->root . '\outside\empty" 2>&1', $output, $code );
		if ( 0 !== $code ) {
			$this->markTestSkipped( 'mklink /J failed: ' . implode( ' ', $output ) );
		}
		$this->assertNotSame( Deleter::REPARSE_PLAIN, Deleter::reparse_state( $junction ), 'a junction to an empty directory must never be reported as plain; StorageReclaim refuses anything that is not plain' );
		$this->assertSame( array(), Deleter::empty_directory( $this->base() )['failed'] );
		$this->assertDirectoryDoesNotExist( $junction );
		$this->assertDirectoryExists( $this->root . '\outside\empty', 'the junction target survives' );
	}

	public function test_windows_junction_is_removed_as_link(): void {
		if ( ! Paths::is_windows() ) {
			$this->markTestSkipped( 'Windows only.' );
		}
		$junction = $this->base() . '\\junction';
		$target   = $this->root . '\\outside\\dir';
		exec( 'cmd /c mklink /J "' . $junction . '" "' . $target . '" 2>&1', $output, $code );
		if ( 0 !== $code ) {
			$this->markTestSkipped( 'mklink /J failed: ' . implode( ' ', $output ) );
		}
		$this->assertFileExists( $junction . '\\inner.txt', 'junction resolves to the outside directory' );
		$diagnostics = array(
			'is_link'              => is_link( $junction ),
			'readlink(junction)'   => @readlink( $junction ),
			'readlink(parent)'     => @readlink( dirname( $junction ) ),
			'realpath(junction)'   => realpath( $junction ),
			'realpath(child)'      => realpath( $junction . '\\inner.txt' ),
			'realpath(parent)'     => realpath( dirname( $junction ) ),
			'lstat mode'           => decoct( (int) ( @lstat( $junction )['mode'] ?? 0 ) ),
			'stat mode'            => decoct( (int) ( @stat( $junction )['mode'] ?? 0 ) ),
			'lstat ino / stat ino' => ( @lstat( $junction )['ino'] ?? '?' ) . ' / ' . ( @stat( $junction )['ino'] ?? '?' ),
			'filetype'             => @filetype( $junction ),
		);
		$this->assertTrue( Deleter::is_reparse( $junction ), 'junction must be recognised as a reparse point: ' . var_export( $diagnostics, true ) );

		$result = Deleter::delete_tree( $this->base(), $this->base() );
		$this->assertNotEmpty( $result['failed'], 'base itself is refused' );

		$result = Deleter::empty_directory( $this->base() );
		$this->assertSame( array(), $result['failed'] );
		$this->assertDirectoryDoesNotExist( $junction );
		$this->assert_outside_untouched();
	}
}
