<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Archive\ChunkHasher;
use WPCheckpoint\Restore\ChunkHashes;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The chunks of bytes as they pass, against the index line's hashes.
 */
final class ChunkHashesTest extends TestCase {

	private static function line( string $content, int $chunk ): array {
		$path = tempnam( sys_get_temp_dir(), 'wpc' );
		file_put_contents( $path, $content );
		$hash = ChunkHasher::content_hash( $path, $chunk );
		unlink( $path );
		return array(
			'b'  => strlen( $content ),
			'h'  => $hash['sha256'],
			'hc' => $hash['chunks'],
		);
	}

	public function test_bytes_in_any_pieces_match_the_chunk_hashes_and_one_changed_byte_does_not(): void {
		$content = str_repeat( 'abcdefghij', 250 ) . 'tail';
		$line    = self::line( $content, 1024 );
		$this->assertCount( 3, $line['hc'] );
		foreach ( array( 1, 7, 1024, 5000 ) as $piece ) {
			$hashes = new ChunkHashes( 0, 1024, strlen( $content ) );
			foreach ( str_split( $content, $piece ) as $bytes ) {
				$hashes->update( $bytes );
			}
			$this->assertNull( $hashes->mismatch( $line ), 'pieces of ' . $piece );
		}
		$changed         = $content;
		$changed[ 1500 ] = 'X';
		$hashes          = new ChunkHashes( 0, 1024, strlen( $content ) );
		$hashes->update( $changed );
		$this->assertSame( 'content chunk 2 does not match its hash', $hashes->mismatch( $line ) );
	}

	public function test_a_unit_from_a_later_chunk_and_files_of_one_chunk_or_none(): void {
		$content = str_repeat( 'z', 3000 );
		$line    = self::line( $content, 1024 );
		$hashes  = new ChunkHashes( 2048, 1024, 3000 );
		$hashes->update( substr( $content, 2048 ) );
		$this->assertNull( $hashes->mismatch( $line ), 'the last chunk, short' );

		$small  = self::line( 'hello', 1024 );
		$hashes = new ChunkHashes( 0, 1024, 5 );
		$hashes->update( 'hellO' );
		$this->assertSame( 'content chunk 1 does not match its hash', $hashes->mismatch( $small ), 'one chunk: the file\'s hash' );
		$this->assertNull( $hashes->mismatch( array( 'b' => 5 ) ), 'no hash in the line: nothing to compare' );

		$empty = new ChunkHashes( 0, 1024, 0 );
		$this->assertNull( $empty->mismatch( self::line( '', 1024 ) ), 'an empty file' );
		$this->assertNotNull( $empty->mismatch( array( 'b' => 0, 'h' => str_repeat( '0', 64 ) ) ) );

		$this->expectException( \InvalidArgumentException::class );
		new ChunkHashes( 100, 1024, 3000 );
	}
}
