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
	 * A hand-built zip with arbitrary (possibly hostile) entry names and,
	 * optionally, lying central directory sizes.
	 *
	 * @param array<string, string> $entries name => content.
	 * @param array<string, array{csize?: int, usize?: int, method?: int}> $lies name => overrides for the central directory.
	 */
	private function craft( array $entries, array $lies = array() ): string {
		$body    = '';
		$central = '';
		foreach ( $entries as $name => $content ) {
			$offset   = strlen( $body );
			$crc      = Crc32::of( $content );
			$method   = isset( $lies[ $name ]['method'] ) ? $lies[ $name ]['method'] : ZipFormat::METHOD_STORE;
			$stored   = ZipFormat::METHOD_DEFLATE === $method ? gzdeflate( $content, 6 ) : $content;
			$body    .= ZipFormat::local_header( $name, $method, 1758196800, $crc, strlen( $stored ), strlen( $content ), false ) . $stored;
			$record   = array( 'name' => $name, 'method' => $method, 'mtime' => 1758196800, 'crc' => $crc, 'csize' => strlen( $stored ), 'usize' => strlen( $content ), 'offset' => $offset );
			$central .= ZipFormat::central_header( array_merge( $record, isset( $lies[ $name ] ) ? $lies[ $name ] : array() ) );
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

	public function test_no_directory_is_created_outside_the_target_through_a_symlinked_ancestor(): void {
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'symlinks' );
		}
		mkdir( $this->dir . '/elsewhere' );
		symlink( $this->dir . '/elsewhere', $this->dir . '/out/a' );
		$path   = $this->craft( array( 'a/b/c/payload.txt' => 'x' ) );
		$reader = ZipReader::open( $path );
		try {
			$reader->extract( $reader->entries()[0], $this->dir . '/out' );
			$this->fail( 'extracted through a symlinked ancestor' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'outside the target directory', $e->getMessage() );
		}
		$this->assertDirectoryDoesNotExist( $this->dir . '/elsewhere/b', 'mkdir must not have followed the link' );
	}

	public function test_declared_sizes_never_drive_allocation_or_loops(): void {
		$big    = str_repeat( 'x', 300000 );
		$path   = $this->craft( array( 'lie.bin' => $big, 'def.bin' => $big ), array( 'lie.bin' => array( 'usize' => 10 ), 'def.bin' => array( 'method' => ZipFormat::METHOD_DEFLATE, 'usize' => 0 ) ) );
		$reader = ZipReader::open( $path );
		$lie    = $reader->find( 'lie.bin' );
		try {
			$reader->read( $lie, 1000 );
			$this->fail( 'a stored entry with csize > max was read' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'larger than allowed', $e->getMessage() );
		}
		try {
			$reader->read( $lie, 1048576 );
			$this->fail( 'a stored entry whose sizes disagree was read' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'different sizes', $e->getMessage() );
		}
		try {
			$reader->read( $reader->find( 'def.bin' ), 1048576 );
			$this->fail( 'a deflated entry with usize 0 and data was inflated' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'data but no size', $e->getMessage() );
		}
		// An honest deflated entry still works, and an empty one too.
		$path   = $this->craft( array( 'ok.txt' => $big, 'empty.txt' => '' ), array( 'ok.txt' => array( 'method' => ZipFormat::METHOD_DEFLATE ), 'empty.txt' => array( 'method' => ZipFormat::METHOD_DEFLATE ) ) );
		$reader = ZipReader::open( $path );
		$this->assertSame( $big, $reader->read( $reader->find( 'ok.txt' ), 1048576 ) );
		$this->assertSame( '', $reader->read( $reader->find( 'empty.txt' ), 1048576 ) );
	}

	public function test_an_existing_link_at_the_target_is_replaced_not_written_through(): void {
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'links' );
		}
		file_put_contents( $this->dir . '/original.txt', 'keep me' );
		link( $this->dir . '/original.txt', $this->dir . '/out/hard.txt' );
		symlink( $this->dir . '/original.txt', $this->dir . '/out/soft.txt' );
		$path   = $this->craft( array( 'hard.txt' => 'new', 'soft.txt' => 'new' ) );
		$reader = ZipReader::open( $path );
		foreach ( $reader->entries() as $entry ) {
			$reader->extract( $entry, $this->dir . '/out' );
		}
		$this->assertSame( 'keep me', file_get_contents( $this->dir . '/original.txt' ), 'the linked file was not written through' );
		$this->assertSame( 'new', file_get_contents( $this->dir . '/out/hard.txt' ) );
		$this->assertSame( 'new', file_get_contents( $this->dir . '/out/soft.txt' ) );
		$this->assertFalse( is_link( $this->dir . '/out/soft.txt' ) );
		$this->assertSame( 1, stat( $this->dir . '/out/hard.txt' )['nlink'] );
	}

	public function test_empty_target_directory_and_directory_entries(): void {
		$path   = $this->craft( array( 'dir/' => '', 'dir/x.txt' => 'x' ) );
		$reader = ZipReader::open( $path );
		$entries = $reader->entries();
		$this->assertTrue( $entries[0]['directory'] );
		$this->assertNotNull( $entries[0]['problem'] );
		$this->assertFalse( $entries[1]['directory'] );
		try {
			$reader->extract( $entries[1], '' );
			$this->fail( 'an empty target directory was accepted' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'empty', $e->getMessage() );
		}
		try {
			$reader->extract( $entries[1], '///' );
			$this->fail( 'a separator-only target directory was accepted' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'empty', $e->getMessage() );
		}
	}

	public function test_malformed_zip64_fields_are_rejected_without_warnings(): void {
		// A central header claiming zip64 with a truncated extra field, and one with bit 63 set.
		$name    = 'a.txt';
		$content = 'x';
		$crc     = Crc32::of( $content );
		$local   = ZipFormat::local_header( $name, ZipFormat::METHOD_STORE, 1758196800, $crc, 1, 1, false ) . $content;
		foreach ( array( pack( 'vv', ZipFormat::ZIP64_EXTRA_ID, 16 ) . str_repeat( "\0", 5 ), pack( 'vv', ZipFormat::ZIP64_EXTRA_ID, 16 ) . pack( 'VV', 1, 0x80000000 ) . pack( 'VV', 1, 0 ) ) as $extra ) {
			$central = pack( 'VvvvvvvVVVvvvvvVV', ZipFormat::SIG_CENTRAL, 45, 45, ZipFormat::FLAG_UTF8, 0, 0, 0, $crc, ZipFormat::LIMIT_32, ZipFormat::LIMIT_32, strlen( $name ), strlen( $extra ), 0, 0, 0, 0, 0 ) . $name . $extra;
			$path    = $this->dir . '/z64.zip';
			file_put_contents( $path, $local . $central . ZipFormat::end_of_central_directory( 1, strlen( $central ), strlen( $local ) ) );
			$reader = ZipReader::open( $path );
			try {
				$reader->entries();
				$this->fail( 'malformed zip64 accepted' );
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( 'malformed', $e->getMessage() );
			}
		}
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
