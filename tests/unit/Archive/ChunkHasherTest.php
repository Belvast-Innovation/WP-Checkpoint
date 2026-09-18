<?php

namespace WPCheckpoint\Tests\Unit\Archive;

use WPCheckpoint\Archive\ChunkHasher;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class ChunkHasherTest extends TestCase {

	/** @var string */
	private $dir;

	protected function set_up(): void {
		$this->dir = sys_get_temp_dir() . '/wpcheckpoint-hash-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir, 0700, true );
	}

	protected function tear_down(): void {
		foreach ( glob( $this->dir . '/*' ) ?: array() as $f ) {
			unlink( $f );
		}
		rmdir( $this->dir );
	}

	private function file( string $name, string $content ): string {
		$path = $this->dir . '/' . $name;
		file_put_contents( $path, $content );
		return $path;
	}

	public function test_chunk_count(): void {
		$this->assertSame( 0, ChunkHasher::chunk_count( 0, 16 ) );
		$this->assertSame( 1, ChunkHasher::chunk_count( 1, 16 ) );
		$this->assertSame( 1, ChunkHasher::chunk_count( 16, 16 ) );
		$this->assertSame( 2, ChunkHasher::chunk_count( 17, 16 ) );
		$this->assertSame( 0, ChunkHasher::chunk_count( 5, 0 ) );
	}

	public function test_chunk_hashes_match_sha256_of_each_slice_and_the_list_hash_is_over_hex(): void {
		$content = random_bytes( 16 * 3 + 5 );
		$path    = $this->file( 'three-and-a-bit', $content );
		$chunks  = ChunkHasher::hash_chunks( $path, 16 );
		$this->assertCount( 4, $chunks );
		foreach ( $chunks as $i => $hash ) {
			$this->assertSame( hash( 'sha256', substr( $content, $i * 16, 16 ) ), $hash, 'chunk ' . $i );
			$this->assertSame( $hash, ChunkHasher::hash_chunk( $path, $i, 16 ) );
			$this->assertTrue( ChunkHasher::verify_chunk( $path, $i, 16, $hash ) );
			$this->assertTrue( ChunkHasher::verify_chunk( $path, $i, 16, strtoupper( $hash ) ), 'case-insensitive comparison' );
		}
		$this->assertSame( hash( 'sha256', implode( '', $chunks ) ), ChunkHasher::list_hash( $chunks ), 'hex strings concatenated, not raw digests' );
		$this->assertNotSame( hash( 'sha256', implode( '', array_map( 'hex2bin', $chunks ) ) ), ChunkHasher::list_hash( $chunks ) );
		$this->assertSame( ChunkHasher::list_hash( $chunks ), ChunkHasher::list_hash( array_map( 'strtoupper', $chunks ) ) );

		$this->assertFalse( ChunkHasher::verify_chunk( $path, 4, 16, $chunks[0] ), 'no such chunk' );
		$this->assertFalse( ChunkHasher::verify_chunk( $path, 0, 16, $chunks[1] ), 'mismatch' );
		$this->assertFalse( ChunkHasher::verify_chunk( $this->dir . '/missing', 0, 16, $chunks[0] ) );
	}

	public function test_content_hash_rule_at_the_chunk_boundary(): void {
		$below  = $this->file( 'below', random_bytes( 15 ) );
		$exact  = $this->file( 'exact', random_bytes( 16 ) );
		$above  = $this->file( 'above', random_bytes( 17 ) );
		$empty  = $this->file( 'empty', '' );
		$result = ChunkHasher::content_hash( $below, 16 );
		$this->assertNull( $result['chunks'] );
		$this->assertSame( hash_file( 'sha256', $below ), $result['sha256'], 'equal to sha256sum' );
		$result = ChunkHasher::content_hash( $exact, 16 );
		$this->assertNull( $result['chunks'], 'exactly chunk_bytes is still one whole' );
		$this->assertSame( hash_file( 'sha256', $exact ), $result['sha256'] );
		$result = ChunkHasher::content_hash( $above, 16 );
		$this->assertCount( 2, $result['chunks'] );
		$this->assertSame( ChunkHasher::list_hash( $result['chunks'] ), $result['sha256'] );
		$this->assertSame( 17, $result['bytes'] );
		$result = ChunkHasher::content_hash( $empty, 16 );
		$this->assertSame( array(), ChunkHasher::hash_chunks( $empty, 16 ) );
		$this->assertNull( $result['chunks'] );
		$this->assertSame( hash( 'sha256', '' ), $result['sha256'] );
	}

	public function test_errors(): void {
		$this->expectException( \RuntimeException::class );
		ChunkHasher::hash_chunks( $this->dir . '/missing', 16 );
	}

	/**
	 * A 1 GiB sparse file: memory does not grow with the file, and the chunks
	 * cover it without gaps or overlaps (feeding every chunk into one running
	 * hash reproduces the whole-file hash).
	 */
	public function test_one_gigabyte_file_is_hashed_in_constant_memory_and_the_chunks_cover_it(): void {
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'Sparse files are not guaranteed on the Windows runner.' );
		}
		$size  = 1073741824;
		$chunk = 16777216;
		$path  = $this->dir . '/sparse';
		$h     = fopen( $path, 'wb' );
		fwrite( $h, 'start' );
		ftruncate( $h, $size );
		fseek( $h, $size - 3 );
		fwrite( $h, 'end' );
		fclose( $h );
		$this->assertSame( $size, filesize( $path ) );

		$before = memory_get_usage( true );
		$chunks = ChunkHasher::hash_chunks( $path, $chunk );
		$this->assertLessThan( 8 * 1048576, memory_get_usage( true ) - $before, 'memory growth stays below 8 MB' );
		$this->assertCount( 64, $chunks );

		// Coverage: the same bytes, chunk by chunk, through one running hash equal the whole-file hash.
		$running = hash_init( 'sha256' );
		$handle  = fopen( $path, 'rb' );
		for ( $i = 0; $i < 64; $i++ ) {
			fseek( $handle, $i * $chunk );
			$piece = hash_init( 'sha256' );
			$left  = $chunk;
			while ( $left > 0 ) {
				$data = fread( $handle, min( 1048576, $left ) );
				hash_update( $running, $data );
				hash_update( $piece, $data );
				$left -= strlen( $data );
			}
			$this->assertSame( $chunks[ $i ], hash_final( $piece ), 'chunk ' . $i . ' is exactly bytes ' . ( $i * $chunk ) . '..' . ( ( $i + 1 ) * $chunk - 1 ) );
		}
		fclose( $handle );
		$this->assertSame( hash_file( 'sha256', $path ), hash_final( $running ), 'the chunks cover the file without gaps or overlaps' );
		$this->assertNotSame( $chunks[0], $chunks[1], 'the first chunk carries the marker bytes' );
		$this->assertSame( $chunks[1], $chunks[2], 'all-zero chunks hash alike' );
	}
}
