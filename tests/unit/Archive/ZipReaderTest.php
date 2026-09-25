<?php

namespace WPCheckpoint\Tests\Unit\Archive;

use WPCheckpoint\Archive\Crc32;
use WPCheckpoint\Archive\EnvironmentFailure;
use WPCheckpoint\Archive\Limits;
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

	public function test_entries_are_hashed_by_streaming_with_the_crc_checked(): void {
		$big    = str_repeat( 'x', 1048576 + 5 ) . str_repeat( 'y', 1048576 ) . 'tail';
		$path   = $this->craft(
			array(
				'store.bin' => $big,
				'small.txt' => 'hello',
				'empty.txt' => '',
				'deflate.bin' => $big,
			),
			array( 'deflate.bin' => array( 'method' => ZipFormat::METHOD_DEFLATE ) )
		);
		$reader = ZipReader::open( $path );
		$store  = $reader->find( 'store.bin' );
		$this->assertSame( hash( 'sha256', $big ), $reader->hash_entry( $store ) );
		$this->assertSame( hash( 'sha256', 'hello' ), $reader->hash_entry( $reader->find( 'small.txt' ) ) );
		$this->assertSame( hash( 'sha256', '' ), $reader->hash_entry( $reader->find( 'empty.txt' ) ) );
		$this->assertSame( hash( 'sha256', $big ), $reader->hash_entry( $reader->find( 'deflate.bin' ) ) );

		$chunks = array( hash( 'sha256', substr( $big, 0, 1048576 ) ), hash( 'sha256', substr( $big, 1048576, 1048576 ) ), hash( 'sha256', substr( $big, 2097152 ) ) );
		$this->assertSame( $chunks, $reader->hash_entry_chunks( $reader->find( 'deflate.bin' ), 1048576 ) );
		$this->assertSame( $chunks, $reader->hash_entry_chunks( $store, 1048576 ) );
		$this->assertSame( array(), $reader->hash_entry_chunks( $reader->find( 'empty.txt' ), 1048576 ) );

		$this->assertSame( $chunks[1], $reader->hash_entry_range( $store, 1048576, 1048576 ) );
		$this->assertSame( $chunks[2], $reader->hash_entry_range( $store, 2097152, 9 ) );
		try {
			$reader->hash_entry_range( $store, 2097152, 10 );
			$this->fail( 'A range beyond the entry must be refused.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'outside the entry', $e->getMessage() );
		}
		try {
			$reader->hash_entry_range( $reader->find( 'deflate.bin' ), 0, 10 );
			$this->fail( 'A deflated entry has no ranges.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'stored entries', $e->getMessage() );
		}

		// A flipped byte in the middle of the stored entry: the range hash differs, the whole-entry hash fails on the CRC.
		$h = fopen( $path, 'r+b' );
		fseek( $h, 30 + 9 + 1048576 + 100 );
		fwrite( $h, 'Q' );
		fclose( $h );
		$reader = ZipReader::open( $path );
		$this->assertNotSame( $chunks[1], $reader->hash_entry_range( $reader->find( 'store.bin' ), 1048576, 1048576 ) );
		$this->assertSame( $chunks[0], $reader->hash_entry_range( $reader->find( 'store.bin' ), 0, 1048576 ) );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'CRC mismatch' );
		$reader->hash_entry( $reader->find( 'store.bin' ) );
	}

	public function test_the_walk_resumes_from_a_recorded_position(): void {
		$entries = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$entries[ "dir/file{$i}.txt" ] = str_repeat( (string) $i, $i );
		}
		$reader = ZipReader::open( $this->craft( $entries ) );
		$all    = $reader->entries();
		$this->assertCount( 50, $all );
		$this->assertSame( $reader->central_directory_offset(), $all[0]['cd_offset'] );
		$this->assertSame( $all[1]['cd_offset'], $all[0]['cd_next'] );

		// Resume from every position: the rest of the walk is identical to the full one.
		foreach ( array( 1, 7, 49 ) as $from ) {
			$seen = array();
			$reader->each(
				static function ( array $entry ) use ( &$seen ): bool {
					$seen[] = $entry;
					return true;
				},
				$from,
				$all[ $from ]['cd_offset']
			);
			$this->assertSame( array_slice( $all, $from ), $seen );
		}
		// Stopping and resuming one at a time yields the same sequence.
		$seen  = array();
		$index = 0;
		$next  = -1;
		while ( $index < 50 ) {
			$reader->each(
				static function ( array $entry ) use ( &$seen, &$index, &$next ): bool {
					$seen[] = $entry['name'];
					$index  = $entry['index'] + 1;
					$next   = $entry['cd_next'];
					return false;
				},
				$index,
				$next
			);
		}
		$this->assertSame( array_keys( $entries ), $seen );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'does not belong' );
		$reader->each(
			static function (): bool {
				return true;
			},
			3,
			$reader->central_directory_offset() - 1
		);
	}

	public function test_stored_entries_extract_in_pieces_with_the_crc_carried_across_calls(): void {
		$big  = str_repeat( 'p', 1048576 + 3 ) . str_repeat( 'q', 1048576 ) . 'end';
		$path = $this->craft( array( 'dir/store.bin' => $big, 'small.txt' => 'tiny', 'def.bin' => $big ), array( 'def.bin' => array( 'method' => ZipFormat::METHOD_DEFLATE ) ) );
		$out  = $this->dir . '/out';
		$r    = ZipReader::open( $path );
		$e    = $r->find( 'dir/store.bin' );
		$crc  = 0;
		$off  = 0;
		do {
			$piece = $r->extract_piece( $e, $out, $off, 1048576, $crc );
			$crc   = $piece['crc'];
			$off  += 1048576;
		} while ( ! $piece['done'] );
		$this->assertSame( str_replace( '\\', '/', $out ) . '/dir/store.bin', str_replace( '\\', '/', $piece['path'] ) );
		$this->assertSame( $big, file_get_contents( $piece['path'] ) );
		$this->assertSame( (int) $e['crc'], $crc );
		// One call covering everything, and a small entry, work the same way.
		$this->assertTrue( $r->extract_piece( $e, $out, 0, PHP_INT_MAX, 0 )['done'] );
		$this->assertSame( 'tiny', file_get_contents( $r->extract_piece( $r->find( 'small.txt' ), $out, 0, 1048576, 0 )['path'] ) );
		// A deflated entry only whole.
		$this->assertSame( $big, file_get_contents( $r->extract_piece( $r->find( 'def.bin' ), $out, 0, PHP_INT_MAX, 0 )['path'] ) );
		try {
			$r->extract_piece( $r->find( 'def.bin' ), $out, 0, 10, 0 );
			$this->fail();
		} catch ( \RuntimeException $e2 ) {
			$this->assertStringContainsString( 'in pieces', $e2->getMessage() );
		}
		// Resuming against a file that is not at the expected size is refused and the file removed.
		$r->extract_piece( $e, $out, 0, 1048576, 0 );
		file_put_contents( $out . '/dir/store.bin', 'x', FILE_APPEND );
		try {
			$r->extract_piece( $e, $out, 1048576, 1048576, 0 );
			$this->fail();
		} catch ( \RuntimeException $e2 ) {
			$this->assertStringContainsString( 'not the one being resumed', $e2->getMessage() );
		}
		$this->assertFileDoesNotExist( $out . '/dir/store.bin' );
		// A wrong running CRC is caught by the last piece.
		$r->extract_piece( $e, $out, 0, 1048576, 0 );
		try {
			$r->extract_piece( $e, $out, 1048576, PHP_INT_MAX, 12345 );
			$this->fail();
		} catch ( \RuntimeException $e2 ) {
			$this->assertStringContainsString( 'CRC mismatch', $e2->getMessage() );
		}
		$this->assertFileDoesNotExist( $out . '/dir/store.bin' );
		// The environment, not the archive: a missing target directory is typed.
		try {
			$r->extract_piece( $e, $this->dir . '/nope', 0, 10, 0 );
			$this->fail();
		} catch ( EnvironmentFailure $e2 ) {
			$this->assertStringContainsString( 'does not exist', $e2->getMessage() );
		}
		try {
			$r->extract( $e, $this->dir . '/nope' );
			$this->fail();
		} catch ( EnvironmentFailure $e2 ) {
			$this->assertStringContainsString( 'does not exist', $e2->getMessage() );
		}
	}

	/**
	 * The inflate limit is only a limit if the largest allowed entry fits
	 * the baseline: one unit may add at most 32 MB, so the peak of
	 * inflating Limits::INFLATE_BYTES in one piece is measured here. The
	 * fixture is streamed to disk so the peak before the measurement stays
	 * small.
	 */
	public function test_the_largest_deflated_entry_inflates_within_the_step_memory_budget(): void {
		if ( ! function_exists( 'deflate_init' ) ) {
			$this->markTestSkipped( 'zlib streaming is not available' );
		}
		$size = Limits::INFLATE_BYTES - 65536; // Incompressible data grows a little; both sizes must stay under the limit.
		$path = $this->dir . '/max.zip';
		$h    = fopen( $path, 'wb' );
		$name = 'max.bin';
		fwrite( $h, str_repeat( "\0", 30 + strlen( $name ) ) );
		$crc   = 0;
		$csize = 0;
		$left  = $size;
		$i     = 0;
		$ctx   = deflate_init( ZLIB_ENCODING_RAW, array( 'level' => 6 ) );
		while ( $left > 0 ) {
			$piece = '';
			while ( strlen( $piece ) < min( 1048576, $left ) ) {
				$piece .= hash( 'sha256', (string) $i++, true );
			}
			$piece  = substr( $piece, 0, min( 1048576, $left ) );
			$left  -= strlen( $piece );
			$crc    = Crc32::combine( $crc, Crc32::of( $piece ), strlen( $piece ) );
			$out    = deflate_add( $ctx, $piece, $left > 0 ? ZLIB_NO_FLUSH : ZLIB_FINISH );
			$csize += strlen( $out );
			fwrite( $h, $out );
		}
		$body = ftell( $h );
		fseek( $h, 0 );
		fwrite( $h, ZipFormat::local_header( $name, ZipFormat::METHOD_DEFLATE, 1758196800, $crc, $csize, $size, false ) );
		fseek( $h, $body );
		$central = ZipFormat::central_header( array( 'name' => $name, 'method' => ZipFormat::METHOD_DEFLATE, 'mtime' => 1758196800, 'crc' => $crc, 'csize' => $csize, 'usize' => $size, 'offset' => 0 ) );
		fwrite( $h, $central . ZipFormat::end_of_central_directory( 1, strlen( $central ), $body ) );
		fclose( $h );
		unset( $piece, $out, $ctx, $central );
		gc_collect_cycles();
		$this->assertLessThan( Limits::INFLATE_BYTES, $csize );

		$reader = ZipReader::open( $path );
		$entry  = $reader->entries()[0];
		$before = memory_get_peak_usage( true );
		$hashes = $reader->hash_entry_chunks( $entry, 1048576 );
		$delta  = memory_get_peak_usage( true ) - $before;
		$this->assertCount( (int) ceil( $size / 1048576 ), $hashes );
		$this->assertLessThanOrEqual( 32 * 1048576, $delta, sprintf( 'Inflating %d bytes peaked at %.1f MiB above the baseline.', $size, $delta / 1048576 ) );
	}

	public function test_not_a_zip(): void {
		file_put_contents( $this->dir . '/x.zip', str_repeat( 'nope', 100 ) );
		$this->expectException( \RuntimeException::class );
		ZipReader::open( $this->dir . '/x.zip' );
	}
}
