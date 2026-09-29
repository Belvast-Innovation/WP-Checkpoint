<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use WPCheckpoint\Tests\Fixtures\Junction;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests' own deleting: only under the temporary directory, never through a link. What it must refuse is asserted
 * with refusal() alone; what it removes is in sandboxes of this test.
 */
final class SandboxTest extends TestCase {

	/** @var string[] Sandboxes of this test. */
	private $made = array();

	protected function tear_down(): void {
		foreach ( $this->made as $dir ) {
			Sandbox::remove( $dir );
		}
		parent::tear_down();
	}

	private function sandbox(): string {
		$dir          = Sandbox::make( 'sandbox-test' );
		$this->made[] = $dir;
		return $dir;
	}

	public function test_what_is_not_strictly_under_the_temporary_directory_is_refused(): void {
		$temp = (string) realpath( sys_get_temp_dir() );
		$dir  = $this->sandbox();
		$this->assertSame( '', Sandbox::refusal( $dir ), 'the control: a sandbox may be removed' );
		$this->assertSame( '', Sandbox::refusal( $dir . '/not-there' ), 'the control: and a path in it' );
		$this->assertSame( 'empty path', Sandbox::refusal( '' ) );
		foreach ( array( 'x', './x', 'tests', basename( $dir ) ) as $relative ) {
			$this->assertSame( 'relative path', Sandbox::refusal( $relative ), $relative );
		}
		$this->assertSame( 'dot segment', Sandbox::refusal( $dir . '/../..' ) );
		$this->assertSame( 'dot segment', Sandbox::refusal( $dir . '/./x' ) );
		$this->assertSame( 'outside the temporary directory', Sandbox::refusal( $temp ), 'the temporary directory itself' );
		$this->assertSame( 'outside the temporary directory', Sandbox::refusal( $temp . '/' ) );
		$this->assertSame( 'outside the temporary directory', Sandbox::refusal( dirname( __DIR__, 3 ) ), 'the plugin' );
		$this->assertSame( 'outside the temporary directory', Sandbox::refusal( dirname( __DIR__, 3 ) . '/tests' ) );
		$this->assertSame( 'outside the temporary directory', Sandbox::refusal( $temp . '-other/x' ), 'a neighbour sharing its name\'s start' );
		$this->assertSame( 'outside the temporary directory', Sandbox::refusal( 'Windows' === PHP_OS_FAMILY ? 'C:\\' : '/' ) );
	}

	public function test_a_path_that_leads_out_through_a_link_is_refused_and_a_link_is_removed_not_followed(): void {
		$dir   = $this->sandbox();
		$other = $this->sandbox();
		file_put_contents( $other . '/keep.txt', 'keep' );
		$this->link( dirname( __DIR__, 3 ), $dir . '/to-plugin' );
		$this->link( $other, $dir . '/to-other' );
		$this->assertSame( 'outside the temporary directory, through a link', Sandbox::refusal( $dir . '/to-plugin/tests' ) );
		$this->assertSame( '', Sandbox::refusal( $dir . '/to-plugin' ), 'the link itself may be removed' );
		$this->assertSame( '', Sandbox::refusal( $dir . '/to-other/keep.txt' ), 'the control: a link to another place in the temporary directory' );

		$this->assertTrue( Sandbox::remove( $dir . '/to-other' ) );
		$this->assertFileExists( $other . '/keep.txt', 'the link is removed, not what it leads to' );
		$this->assertTrue( Sandbox::remove( $dir ) );
		$this->assertDirectoryExists( dirname( __DIR__, 3 ) . '/tests', 'removing a tree holding a link to the plugin removes the link' );
		$this->assertFileExists( $other . '/keep.txt' );
	}

	public function test_what_holds_the_working_directory_is_refused(): void {
		$dir = $this->sandbox();
		mkdir( $dir . '/in' );
		$cwd = (string) getcwd();
		chdir( $dir . '/in' );
		try {
			$why = 'the plugin, the site or the working directory, or holding or inside one';
			$this->assertSame( $why, Sandbox::refusal( $dir ), 'holding it' );
			$this->assertSame( $why, Sandbox::refusal( $dir . '/in' ), 'it' );
			$this->assertSame( $why, Sandbox::refusal( $dir . '/in/x' ), 'inside it' );
			$this->assertSame( $why, Sandbox::refusal( $dir . '/IN' ), 'another letter case: the same directory where the file system folds case, and refused anyway where it does not (the safe direction)' );
		} finally {
			chdir( $cwd );
		}
		$this->assertSame( '', Sandbox::refusal( $dir ), 'the control: once the working directory is elsewhere' );
	}

	public function test_a_short_name_is_refused_and_one_on_the_way_is_resolved(): void {
		if ( 'Windows' !== PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'Windows only: 8.3 short names.' );
		}
		$dir = $this->sandbox();
		mkdir( $dir . '/a-long-directory-name/in', 0755, true );
		$out = array();
		exec( 'cmd /c for %I in ("' . str_replace( '/', '\\', $dir . '/a-long-directory-name' ) . '") do @echo %~sI', $out );
		$short = basename( str_replace( '\\', '/', trim( (string) end( $out ) ) ) );
		if ( false === strpos( $short, '~' ) ) {
			$this->markTestSkipped( 'This volume makes no 8.3 names (' . $short . ').' );
		}
		$alias = 'not named as its directory lists it (a short name or another alias)';
		$cwd   = (string) getcwd();
		chdir( $dir . '/a-long-directory-name/in' );
		try {
			$this->assertSame( $alias, Sandbox::refusal( $dir . '/' . $short ), $short . ', which holds the working directory' );
			$this->assertSame( 'the plugin, the site or the working directory, or holding or inside one', Sandbox::refusal( $dir . '/a-long-directory-name' ), 'the control: by its listed name' );
		} finally {
			chdir( $cwd );
		}
		$this->assertSame( $alias, Sandbox::refusal( $dir . '/' . $short ), 'a short name for an entry, wherever the working directory is' );
		$this->assertSame( '', Sandbox::refusal( $dir . '/a-long-directory-name' ), 'the control: its listed name' );
		$this->assertSame( '', Sandbox::refusal( $dir . '/' . $short . '/in' ), 'a short name on the way is resolved with the directory' );
	}

	public function test_a_backslash_is_part_of_a_name_where_it_is_no_separator(): void {
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'On Windows "\\" is a separator.' );
		}
		$dir = $this->sandbox();
		mkdir( $dir . '/b' );
		mkdir( $dir . '/a\\b' );
		file_put_contents( $dir . '/a\\b/keep.txt', 'keep' );
		$cwd = (string) getcwd();
		chdir( $dir . '/a\\b' );
		try {
			$this->assertSame( 'the plugin, the site or the working directory, or holding or inside one', Sandbox::refusal( $dir . '/a\\b' ), 'the directory "a\\b" itself, not "b" in "a"' );
			$this->assertSame( '', Sandbox::refusal( $dir . '/b' ), 'the control: its neighbour "b"' );
			try {
				Sandbox::remove( $dir . '/a\\b' );
				$this->fail( 'removed' );
			} catch ( \LogicException $e ) {
				$this->assertStringContainsString( 'Nothing was deleted', $e->getMessage() );
			}
		} finally {
			chdir( $cwd );
		}
		$this->assertFileExists( $dir . '/a\\b/keep.txt' );
		$this->assertTrue( Sandbox::remove( $dir . '/a\\b' ), 'once the working directory is elsewhere' );
		$this->assertDirectoryExists( $dir . '/b', 'and only it' );
	}

	public function test_a_refused_path_throws_and_nothing_is_touched(): void {
		$dir = $this->sandbox();
		file_put_contents( $dir . '/keep.txt', 'keep' );
		$this->link( $dir, $dir . '-link' );
		$this->made[] = $dir . '-link';
		foreach ( array( '', basename( $dir ), $dir . '/../' . basename( $dir ) ) as $path ) {
			try {
				Sandbox::remove( $path );
				$this->fail( "{$path} was not refused" );
			} catch ( \LogicException $e ) {
				$this->assertStringContainsString( 'Nothing was deleted', $e->getMessage() );
			}
		}
		$this->assertFileExists( $dir . '/keep.txt' );
	}

	public function test_a_tree_and_a_file_are_removed_and_a_missing_path_is_fine(): void {
		$dir = $this->sandbox();
		mkdir( $dir . '/a/b/c', 0755, true );
		file_put_contents( $dir . '/a/b/c/one.txt', '1' );
		file_put_contents( $dir . '/a/two.txt', '2' );
		file_put_contents( $dir . '/three.txt', '3' );
		$this->assertTrue( Sandbox::remove( $dir . '/three.txt' ) );
		$this->assertFileDoesNotExist( $dir . '/three.txt' );
		$this->assertFileExists( $dir . '/a/two.txt', 'only the file' );
		$this->assertTrue( Sandbox::remove( $dir . '/a/' ) );
		$this->assertDirectoryDoesNotExist( $dir . '/a' );
		$this->assertTrue( Sandbox::remove( $dir . '/a' ), 'already gone' );
		$this->assertTrue( Sandbox::remove( $dir ) );
		$this->assertDirectoryDoesNotExist( $dir );
	}

	/**
	 * A link to a directory: a symbolic link, or a junction on Windows.
	 */
	private function link( string $target, string $link ): void {
		if ( 'Windows' === PHP_OS_FAMILY ) {
			Junction::make( $target, $link );
			return;
		}
		$this->assertTrue( symlink( $target, $link ) );
	}
}
