<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\StagedWriter;
use WPCheckpoint\Restore\StagingChanged;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * A staged file is written only through directories that are directories, created exclusively or resumed
 * only when it is the file that was looked at, and cut back to what was committed, never padded.
 *
 * @requires OS Linux|Darwin
 */
final class StagedWriterTest extends TestCase {

	/** @var string */
	private $root;

	/** @var string */
	private $outside;

	protected function set_up(): void {
		$base          = sys_get_temp_dir() . '/wpc-staged-' . bin2hex( random_bytes( 4 ) );
		$this->root    = $base . '/root';
		$this->outside = $base . '/outside';
		mkdir( $this->root, 0700, true );
		mkdir( $this->outside, 0700, true );
	}

	protected function tear_down(): void {
		exec( 'rm -rf ' . escapeshellarg( dirname( $this->root ) ) );
	}

	private function open( string $relative, int $committed, $between = null ) {
		return StagedWriter::open( $this->root, $relative, $committed, 0750, 0640, null, $between );
	}

	public function test_a_new_file_is_created_with_the_sites_modes_through_new_directories(): void {
		$made   = array();
		$handle = StagedWriter::open(
			$this->root,
			'a/b/c.txt',
			0,
			0750,
			0640,
			static function ( string $dir ) use ( &$made ): void {
				$made[] = basename( $dir );
			}
		);
		fwrite( $handle, 'abc' );
		fclose( $handle );
		$this->assertSame( array( 'a', 'b' ), $made );
		$this->assertSame( 'abc', file_get_contents( $this->root . '/a/b/c.txt' ) );
		$this->assertSame( 0640, fileperms( $this->root . '/a/b/c.txt' ) & 0777 );
		$this->assertSame( 0750, fileperms( $this->root . '/a' ) & 0777, 'whatever the umask' );
	}

	public function test_a_link_on_the_way_is_never_followed(): void {
		symlink( $this->outside, $this->root . '/a' );
		try {
			$this->open( 'a/c.txt', 0 );
			$this->fail( 'written' );
		} catch ( StagingChanged $e ) {
			$this->assertStringContainsString( 'is not a directory (or is a link to one)', $e->getMessage() );
		}
		$this->assertSame( array( '.', '..' ), scandir( $this->outside ), 'nothing written where the link points' );

		// A link in place of the file itself: not followed either, fresh or resumed.
		mkdir( $this->root . '/d' );
		file_put_contents( $this->outside . '/target', 'keep' );
		symlink( $this->outside . '/target', $this->root . '/d/f' );
		foreach ( array( 0, 4 ) as $committed ) {
			try {
				$this->open( 'd/f', $committed );
				$this->fail( 'opened' );
			} catch ( StagingChanged $e ) {
				$this->assertStringContainsString( 'is not a regular file', $e->getMessage() );
			}
		}
		$this->assertSame( 'keep', file_get_contents( $this->outside . '/target' ) );
		// The root itself as a link.
		$link = dirname( $this->root ) . '/root-link';
		symlink( $this->root, $link );
		$this->expectException( StagingChanged::class );
		StagedWriter::open( $link, 'x', 0, 0750, 0640 );
	}

	public function test_a_file_being_resumed_is_cut_back_to_what_was_committed_and_never_padded(): void {
		file_put_contents( $this->root . '/f', 'committed+uncommitted' );
		$handle = $this->open( 'f', 9 );
		$this->assertSame( 9, ftell( $handle ) );
		fclose( $handle );
		$this->assertSame( 'committed', file_get_contents( $this->root . '/f' ) );

		try {
			$this->open( 'f', 20 );
			$this->fail( 'padded' );
		} catch ( StagingChanged $e ) {
			$this->assertStringContainsString( 'shorter than the restore recorded', $e->getMessage() );
		}
		clearstatcache();
		$this->assertSame( 9, filesize( $this->root . '/f' ), 'not padded' );

		try {
			$this->open( 'gone', 5 );
			$this->fail( 'created' );
		} catch ( StagingChanged $e ) {
			$this->assertStringContainsString( 'is gone', $e->getMessage() );
		}
		$this->assertFileDoesNotExist( $this->root . '/gone' );
	}

	public function test_a_file_replaced_between_the_look_and_the_open_is_refused(): void {
		file_put_contents( $this->root . '/f', 'original' );
		$outside = $this->outside;
		try {
			$this->open(
				'f',
				3,
				static function ( string $path ) use ( $outside ): void {
					file_put_contents( $outside . '/other', 'another file' );
					rename( $outside . '/other', $path ); // Another inode under the same name.
				}
			);
			$this->fail( 'opened' );
		} catch ( StagingChanged $e ) {
			$this->assertStringContainsString( 'is not the one that was looked at', $e->getMessage() );
		}
		$this->assertSame( 'another file', file_get_contents( $this->root . '/f' ), 'not cut back either' );

		// The control: the same seam, nothing replaced, and the file opens.
		$handle = $this->open(
			'f',
			3,
			static function (): void {
			}
		);
		$this->assertIsResource( $handle );
		fclose( $handle );
	}
}
