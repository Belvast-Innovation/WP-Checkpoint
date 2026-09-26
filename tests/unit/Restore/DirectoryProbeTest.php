<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\CannotStage;
use WPCheckpoint\Restore\DirectoryProbe;
use WPCheckpoint\Restore\StagingLayout;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The probe does in a staging parent what staging and the swap will do there, and leaves nothing.
 */
final class DirectoryProbeTest extends TestCase {

	/** @var string */
	private $dir;

	/** @var string */
	private $name;

	protected function set_up(): void {
		$this->dir  = sys_get_temp_dir() . '/wpcheckpoint-dprobe-' . bin2hex( random_bytes( 4 ) );
		$this->name = ( new StagingLayout( array_fill_keys( StagingLayout::GROUPS, $this->dir ), 'a1b2c3d4e5f6', 7, StagingLayout::new_random() ) )->probe_name();
		mkdir( $this->dir );
	}

	protected function tear_down(): void {
		@chmod( $this->dir, 0700 );
		foreach ( array( $this->dir . '/' . $this->name, $this->dir . '/' . $this->name . '-r' ) as $probe ) {
			foreach ( (array) @scandir( $probe ) as $entry ) {
				if ( is_string( $entry ) && '.' !== $entry && '..' !== $entry ) {
					@unlink( $probe . '/' . $entry );
				}
			}
			@rmdir( $probe );
		}
		@rmdir( $this->dir );
	}

	/**
	 * @return string[]
	 */
	private function left(): array {
		return array_values( array_diff( (array) scandir( $this->dir ), array( '.', '..' ) ) );
	}

	public function test_it_creates_renames_and_removes_and_reports_the_file_system(): void {
		$confirmed = 0;
		$result    = DirectoryProbe::run(
			$this->dir,
			$this->name,
			static function () use ( &$confirmed ): void {
				++$confirmed;
			}
		);
		$this->assertSame( 2, $confirmed, 'before creating, before renaming' );
		$this->assertFalse( $result['left'] );
		$this->assertSame( array(), $this->left(), 'nothing left' );
		$this->assertSame( (int) stat( $this->dir )['dev'], $result['dev'] );
		$flags = $result['names']->to_array();
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			// NTFS through PHP: case folds (Unicode too), forms stay apart, "<" is refused, and PHP refuses a path
			// ending in a dot (Windows would drop it): such names cannot be written at all.
			$this->assertTrue( $flags['fold_ascii'] );
			$this->assertTrue( $flags['fold_unicode'] );
			$this->assertFalse( $flags['normalize'] );
			$this->assertFalse( $flags['trim_trailing'] );
			$this->assertTrue( $flags['win32'] );
			$this->assertTrue( $flags['refuse_trailing'] );
		} elseif ( 'Linux' === PHP_OS_FAMILY ) {
			$this->assertSame(
				array(
					'fold_ascii'    => false,
					'fold_unicode'  => false,
					'normalize'     => false,
					'trim_trailing' => false,
					'win32'         => false,
					'refuse_trailing' => false,
				),
				$flags,
				'ext4, tmpfs, overlay: nothing folds'
			);
		}
	}

	public function test_a_refused_confirmation_creates_nothing(): void {
		try {
			DirectoryProbe::run(
				$this->dir,
				$this->name,
				static function (): void {
					throw new \RuntimeException( 'Lease lost.' );
				}
			);
			$this->fail( 'ran' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Lease lost.', $e->getMessage() );
		}
		$this->assertSame( array(), $this->left() );
	}

	public function test_a_lease_lost_before_the_rename_leaves_nothing(): void {
		$calls = 0;
		try {
			DirectoryProbe::run(
				$this->dir,
				$this->name,
				static function () use ( &$calls ): void {
					if ( ++$calls > 1 ) {
						throw new \RuntimeException( 'Lease lost.' );
					}
				}
			);
			$this->fail( 'ran' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Lease lost.', $e->getMessage() );
		}
		$this->assertSame( 2, $calls, 'the control: it got as far as the rename' );
		$this->assertSame( array(), $this->left() );
	}

	public function test_a_directory_it_cannot_write_is_refused_with_the_reason(): void {
		if ( '\\' === DIRECTORY_SEPARATOR || ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) ) {
			$this->markTestSkipped( 'Permissions do not stop this user here.' );
		}
		chmod( $this->dir, 0500 );
		try {
			DirectoryProbe::run(
				$this->dir,
				$this->name,
				static function (): void {
				}
			);
			$this->fail( 'ran' );
		} catch ( CannotStage $e ) {
			$this->assertStringContainsString( 'A directory cannot be created in ' . $this->dir, $e->getMessage() );
			$this->assertStringContainsString( 'writable by the web server', $e->getMessage() );
		}
	}

	/**
	 * @requires OS Linux|Darwin
	 */
	public function test_cleaning_up_never_follows_a_link_put_in_place_of_the_probe(): void {
		$target = $this->dir . '/elsewhere';
		mkdir( $target );
		foreach ( array( DirectoryProbe::NAME, DirectoryProbe::BARE, 'keep.txt' ) as $file ) {
			file_put_contents( $target . '/' . $file, 'x' );
		}
		symlink( $target, $this->dir . '/' . $this->name );
		$remove = new \ReflectionMethod( DirectoryProbe::class, 'remove' );
		$remove->setAccessible( true );
		$remove->invoke( null, $this->dir . '/' . $this->name );
		$left = array_values( array_diff( scandir( $target ), array( '.', '..' ) ) );
		$want = array( DirectoryProbe::NAME, DirectoryProbe::BARE, 'keep.txt' );
		sort( $left );
		sort( $want );
		$this->assertSame( $want, $left, 'what the link points to is untouched' );

		// The control: the same names in a real probe directory are removed, and only those.
		$real = $this->dir . '/' . $this->name . '-r';
		mkdir( $real );
		foreach ( array( DirectoryProbe::NAME, DirectoryProbe::BARE ) as $file ) {
			file_put_contents( $real . '/' . $file, 'x' );
		}
		$remove->invoke( null, $real );
		$this->assertDirectoryDoesNotExist( $real );
		unlink( $this->dir . '/' . $this->name );
		foreach ( array( DirectoryProbe::NAME, DirectoryProbe::BARE, 'keep.txt' ) as $file ) {
			unlink( $target . '/' . $file );
		}
		rmdir( $target );
	}

	public function test_a_probe_directory_kept_only_by_someone_elses_entry_is_left_to_the_reaper(): void {
		$left = new \ReflectionMethod( DirectoryProbe::class, 'left_behind' );
		$left->setAccessible( true );
		$dir = $this->dir . '/' . $this->name . '-r';
		$this->assertFalse( $left->invoke( null, $dir, $this->dir ), 'removed: nothing left' );

		mkdir( $dir );
		file_put_contents( $dir . '/.DS_Store', 'x' );
		$this->assertTrue( $left->invoke( null, $dir, $this->dir ), 'only another program\'s entry: left, not refused' );

		file_put_contents( $dir . '/' . DirectoryProbe::BARE, 'x' );
		try {
			$left->invoke( null, $dir, $this->dir );
			$this->fail( 'accepted' );
		} catch ( CannotStage $e ) {
			$this->assertStringContainsString( 'cannot be removed again', $e->getMessage(), 'its own file is still there' );
		}
		unlink( $dir . '/' . DirectoryProbe::BARE );
		unlink( $dir . '/.DS_Store' );
		try {
			$left->invoke( null, $dir, $this->dir );
			$this->fail( 'accepted' );
		} catch ( CannotStage $e ) {
			$this->assertStringContainsString( 'cannot be removed again', $e->getMessage(), 'empty and still there: it could not be removed' );
		}
		rmdir( $dir );
	}
}
