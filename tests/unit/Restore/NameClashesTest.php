<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\NameClashes;
use WPCheckpoint\Restore\TargetNames;
use WPCheckpoint\Tests\Fixtures\MemoryBudget;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Keys written while the index is read, clashes found one bucket at a time.
 */
final class NameClashesTest extends TestCase {

	/**
	 * The records of a list of paths, as the preflight writes them (each path after the previous one).
	 *
	 * @param string[]    $paths Paths (offset = position * 100).
	 * @param TargetNames $names Names.
	 * @return array<int, string> Bucket => records.
	 */
	private static function write( array $paths, TargetNames $names, int $parent = 0, int $first = 0 ): array {
		$buckets  = array();
		$previous = '';
		foreach ( $paths as $i => $path ) {
			foreach ( NameClashes::records( $parent, $path, $names, $first + $i * 100, $previous ) as $b => $records ) {
				$buckets[ $b ] = ( $buckets[ $b ] ?? '' ) . $records;
			}
			$previous = $path;
		}
		return $buckets;
	}

	/**
	 * The first clash over all buckets.
	 *
	 * @param array<int, string> $buckets Buckets.
	 * @return array{0: int, 1: int}|null
	 */
	private static function clash( array $buckets ) {
		foreach ( $buckets as $records ) {
			$clash = NameClashes::first_clash( $records );
			if ( null !== $clash ) {
				return $clash;
			}
		}
		return null;
	}

	public function test_two_files_one_file_system_folds_together_clash_and_are_named_by_their_lines(): void {
		$folding = new TargetNames( true, true, false, false );
		$this->assertSame( array( 100, 200 ), self::clash( self::write( array( 'uploads/a.txt', 'uploads/Foo.txt', 'uploads/foo.txt' ), $folding ) ) );
		$this->assertNull( self::clash( self::write( array( 'uploads/a.txt', 'uploads/Foo.txt', 'uploads/foo.txt' ), new TargetNames( false, false, false, false ) ) ), 'the control: a file system that keeps them apart' );
	}

	public function test_a_file_where_a_directory_must_be_clashes_either_way_round(): void {
		$folding = new TargetNames( true, false, false, false );
		$this->assertSame( array( 0, 100 ), self::clash( self::write( array( 'uploads/a', 'uploads/A/b' ), $folding ) ) );
		$this->assertSame( array( 0, 100 ), self::clash( self::write( array( 'uploads/A/b', 'uploads/a' ), $folding ) ) );
		$this->assertSame( array( 0, 100 ), self::clash( self::write( array( 'uploads/x', 'uploads/x/y' ), new TargetNames( false, false, false, false ) ) ), 'the same spelling too' );
	}

	public function test_directories_folded_together_are_one_directory_and_a_replayed_unit_is_no_clash(): void {
		$folding = new TargetNames( true, false, false, false );
		$this->assertNull( self::clash( self::write( array( 'uploads/Dir/a', 'uploads/dir/b', 'uploads/DIR/c/d' ), $folding ) ) );
		$buckets = self::write( array( 'uploads/a/b', 'uploads/a/c' ), $folding );
		$again   = self::write( array( 'uploads/a/b', 'uploads/a/c' ), $folding );
		foreach ( $again as $b => $records ) {
			$buckets[ $b ] .= $records;
		}
		$this->assertNull( self::clash( $buckets ), 'the same records twice' );
		$this->assertSame( array( 0, 100 ), self::clash( self::write( array( 'uploads/a/b', 'uploads/a/b' ), $folding ) ), 'the control: the same path on two lines' );
	}

	public function test_different_parents_never_clash(): void {
		$folding = new TargetNames( true, false, false, false );
		$buckets = self::write( array( 'plugins/a' ), $folding, 0 );
		foreach ( self::write( array( 'plugins/A' ), $folding, 1, 100 ) as $b => $records ) {
			$buckets[ $b ] = ( $buckets[ $b ] ?? '' ) . $records;
		}
		$this->assertNull( self::clash( $buckets ) );
		foreach ( self::write( array( 'plugins/A' ), $folding, 0, 100 ) as $b => $records ) {
			$buckets[ $b ] = ( $buckets[ $b ] ?? '' ) . $records;
		}
		$this->assertSame( array( 0, 100 ), self::clash( $buckets ), 'the control: the same two under one parent' );
	}

	public function test_a_directory_shared_with_the_previous_path_is_written_once(): void {
		$names  = new TargetNames( false, false, false, false );
		$first  = NameClashes::records( 0, 'uploads/2026/09/a.jpg', $names, 0, '' );
		$second = NameClashes::records( 0, 'uploads/2026/09/b.jpg', $names, 100, 'uploads/2026/09/a.jpg' );
		$third  = NameClashes::records( 0, 'uploads/2026/10/c.jpg', $names, 200, 'uploads/2026/09/b.jpg' );
		$this->assertSame( 4 * NameClashes::RECORD_BYTES, strlen( implode( '', $first ) ), 'three directories and the file' );
		$this->assertSame( NameClashes::RECORD_BYTES, strlen( implode( '', $second ) ), 'the file only' );
		$this->assertSame( 2 * NameClashes::RECORD_BYTES, strlen( implode( '', $third ) ), 'the new month and the file' );
		// A previous path whose last segment is a file named like the directory: the directory is written.
		$this->assertSame( 2 * NameClashes::RECORD_BYTES, strlen( implode( '', NameClashes::records( 0, 'uploads/a/b', $names, 100, 'uploads/a' ) ) ) );
	}

	public function test_what_is_not_a_bucket_of_records_is_refused(): void {
		foreach ( array( 'short', str_repeat( 'x', NameClashes::RECORD_BYTES ) ) as $records ) {
			try {
				NameClashes::first_clash( $records );
				$this->fail( 'accepted' );
			} catch ( \UnexpectedValueException $e ) {
				$this->assertStringContainsString( 'bucket of name keys', $e->getMessage() );
			}
		}
		$this->expectException( \InvalidArgumentException::class );
		NameClashes::records( 0, 'a', new TargetNames( false, false, false, false ), NameClashes::MAX_OFFSET + 1, '' );
	}

	public function test_the_largest_bucket_is_checked_within_the_memory_of_one_unit(): void {
		$count   = intdiv( NameClashes::MAX_BUCKET_BYTES, NameClashes::RECORD_BYTES );
		$records = '';
		for ( $i = 0; $i < $count; $i++ ) {
			$records .= substr( sha1( (string) $i ), 0, 16 ) . 'f' . str_pad( (string) $i, 12, '0', STR_PAD_LEFT ) . "\n";
		}
		$this->assertLessThanOrEqual( NameClashes::MAX_BUCKET_BYTES, strlen( $records ) );
		$this->assertGreaterThan( NameClashes::MAX_BUCKET_BYTES - NameClashes::RECORD_BYTES, strlen( $records ) );
		// 32 MB per unit, the bucket itself already held.
		$clash = MemoryBudget::within(
			33554432,
			static function () use ( $records ) {
				return NameClashes::first_clash( $records );
			}
		);
		$this->assertNull( $clash );
	}
}
