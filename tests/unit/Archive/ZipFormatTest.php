<?php

namespace WPCheckpoint\Tests\Unit\Archive;

use WPCheckpoint\Archive\ZipFormat;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class ZipFormatTest extends TestCase {

	private function central( string $name, int $size = 10 ): array {
		$parsed = ZipFormat::parse_central_header(
			ZipFormat::central_header(
				array(
					'name'   => $name,
					'method' => ZipFormat::METHOD_STORE,
					'mtime'  => 1758196800,
					'crc'    => 0,
					'csize'  => $size,
					'usize'  => $size,
					'offset' => 0,
				)
			)
		);
		$this->assertIsArray( $parsed );
		return $parsed;
	}

	public function test_entries_are_made_by_a_unix_host_with_a_file_mode(): void {
		$entry = $this->central( 'files/wp-content/uploads/café-日本語.txt' );
		$this->assertSame( 3, $entry['made_by'] >> 8, 'host 3 (Unix): no reader decodes the UTF-8 name from an OEM code page' );
		$this->assertSame( ZipFormat::VERSION_DEFAULT, $entry['made_by'] & 0xFF );
		$this->assertSame( 0100644, $entry['external'] >> 16, 'a regular file, rw-r--r--' );
		$this->assertSame( ZipFormat::FLAG_UTF8, $entry['flags'] & ZipFormat::FLAG_UTF8 );
	}

	public function test_a_directory_entry_carries_a_directory_mode_and_the_dos_directory_bit(): void {
		$entry = $this->central( 'files/wp-content/uploads/empty/', 0 );
		$this->assertSame( 040755, $entry['external'] >> 16, 'a directory, rwxr-xr-x: traversable after extraction' );
		$this->assertSame( ZipFormat::DOS_DIRECTORY, $entry['external'] & 0xFF );
	}

	public function test_a_zip64_entry_keeps_the_unix_host(): void {
		$entry = $this->central( 'files/big.bin', 0xFFFFFFFF + 1 );
		$this->assertSame( ( 3 << 8 ) | ZipFormat::VERSION_ZIP64, $entry['made_by'] );
		$this->assertSame( 0xFFFFFFFF + 1, $entry['usize'] );
	}
}
