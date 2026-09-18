<?php

namespace WPCheckpoint\Tests\Unit\Archive;

use WPCheckpoint\Archive\Crc32;
use WPCheckpoint\Archive\ZipFormat;
use WPCheckpoint\Archive\ZipReader;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class ZipReaderTest extends TestCase {

	/** @var string */
	private $dir;

	protected function set_up(): void {
		$this->dir = sys_get_temp_dir() . '/wpcheckpoint-reader-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir . '/out', 0700, true );
	}

	protected function tear_down(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->dir ) );
	}

	/**
	 * A hand-built zip with arbitrary (possibly hostile) entry names.
	 *
	 * @param array<string, string> $entries name => content.
	 */
	private function craft( array $entries ): string {
		$body    = '';
		$central = '';
		foreach ( $entries as $name => $content ) {
			$offset   = strlen( $body );
			$crc      = Crc32::of( $content );
			$body    .= ZipFormat::local_header( $name, ZipFormat::METHOD_STORE, 1758196800, $crc, strlen( $content ), strlen( $content ), false ) . $content;
			$central .= ZipFormat::central_header( array( 'name' => $name, 'method' => ZipFormat::METHOD_STORE, 'mtime' => 1758196800, 'crc' => $crc, 'csize' => strlen( $content ), 'usize' => strlen( $content ), 'offset' => $offset ) );
		}
		$path = $this->dir . '/crafted.zip';
		file_put_contents( $path, $body . $central . ZipFormat::end_of_central_directory( count( $entries ), strlen( $central ), strlen( $body ) ) );
		return $path;
	}

	public function test_unsafe_entry_names_are_reported_and_never_extracted(): void {
		$path   = $this->craft( array( 'ok.txt' => 'fine', '../evil.txt' => 'x', '/abs.txt' => 'x', 'a\\b.txt' => 'x', "c\x00d" => 'x' ) );
		$reader = ZipReader::open( $path );
		$this->assertSame( 5, $reader->count() );
		$entries = $reader->entries();
		$this->assertNull( $entries[0]['problem'] );
		foreach ( array_slice( $entries, 1 ) as $entry ) {
			$this->assertNotNull( $entry['problem'], $entry['name'] );
			try {
				$reader->extract( $entry, $this->dir . '/out' );
				$this->fail( 'extracted ' . $entry['name'] );
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( 'unsafe entry name', $e->getMessage() );
			}
		}
		$reader->extract( $entries[0], $this->dir . '/out' );
		$this->assertSame( 'fine', file_get_contents( $this->dir . '/out/ok.txt' ) );
		$this->assertFileDoesNotExist( $this->dir . '/evil.txt' );
		$this->assertSame( array( 'ok.txt' ), array_values( array_diff( scandir( $this->dir . '/out' ), array( '.', '..' ) ) ) );
	}

	public function test_a_symlinked_parent_inside_the_target_is_refused(): void {
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'symlinks' );
		}
		mkdir( $this->dir . '/elsewhere' );
		symlink( $this->dir . '/elsewhere', $this->dir . '/out/link' );
		$path   = $this->craft( array( 'link/payload.txt' => 'x' ) );
		$reader = ZipReader::open( $path );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'outside the target directory' );
		$reader->extract( $reader->entries()[0], $this->dir . '/out' );
	}

	public function test_corrupt_data_is_detected_by_crc(): void {
		$path = $this->craft( array( 'a.txt' => str_repeat( 'abc', 1000 ) ) );
		$h    = fopen( $path, 'r+b' );
		fseek( $h, 40 + 10 );
		fwrite( $h, 'Z' );
		fclose( $h );
		$reader = ZipReader::open( $path );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'CRC mismatch' );
		$reader->read( $reader->entries()[0] );
	}

	public function test_not_a_zip(): void {
		file_put_contents( $this->dir . '/x.zip', str_repeat( 'nope', 100 ) );
		$this->expectException( \RuntimeException::class );
		ZipReader::open( $this->dir . '/x.zip' );
	}
}
