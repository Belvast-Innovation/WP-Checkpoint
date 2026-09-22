<?php

namespace WPCheckpoint\Tests\Unit\Archive;

use WPCheckpoint\Archive\ChunkHasher;
use WPCheckpoint\Archive\InsufficientSpace;
use WPCheckpoint\Archive\IndexLine;
use WPCheckpoint\Archive\Packer;
use WPCheckpoint\Archive\SourceChanged;
use WPCheckpoint\Archive\SourceGone;
use WPCheckpoint\Archive\ZipFormat;
use WPCheckpoint\Archive\ZipReader;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class PackerTest extends TestCase {

	/** @var string */
	private $root;

	/** @var string */
	private $src;

	/** @var string */
	private $out;

	protected function set_up(): void {
		$this->root = sys_get_temp_dir() . '/wpcheckpoint-packer-' . bin2hex( random_bytes( 4 ) );
		$this->src  = $this->root . '/src';
		$this->out  = $this->root . '/out';
		mkdir( $this->src, 0700, true );
		mkdir( $this->out, 0700, true );
	}

	protected function tear_down(): void {
		$this->rm( $this->root );
	}

	private function rm( string $dir ): void {
		foreach ( scandir( $dir ) ?: array() as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->rm( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * A deterministic source file.
	 */
	private function source( string $name, int $bytes, int $seed = 1 ): string {
		$path = $this->src . '/' . $name;
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0700, true );
		}
		$h    = fopen( $path, 'wb' );
		$left = $bytes;
		$i    = $seed;
		while ( $left > 0 ) {
			$piece = str_repeat( hash( 'sha256', (string) $i++, true ), 1024 ); // 32 KiB of pseudo-random, incompressible bytes.
			$piece = substr( $piece, 0, $left );
			fwrite( $h, $piece );
			$left -= strlen( $piece );
		}
		fclose( $h );
		return $path;
	}

	private function options( array $extra = array() ): array {
		return array_merge(
			array(
				'volume_bytes'       => 1048576,   // 1 MiB threshold, so tests cross it cheaply.
				'volume_chunk_bytes' => 262144,    // 256 KiB container chunks.
				'deflate_max_bytes'  => 65536,
				'disk_free'          => static function (): int {
					return PHP_INT_MAX;
				},
			),
			$extra
		);
	}

	/**
	 * Drive a packer through a list of [source, entry, mtime] with the given piece size.
	 */
	private function pack( array $files, array $options, int $piece = 4194304, $manifest = '{"embedded":true}' ): Packer {
		$packer = Packer::open( $this->out, 'site-20260918-100000-a1b2', array(), $options );
		foreach ( $files as list( $source, $entry, $mtime ) ) {
			$packer->add_entry( $source, $entry, $mtime );
			while ( $packer->write_piece( $piece ) > 0 ) {
				continue;
			}
		}
		$index = $this->source( 'files.index.jsonl', 300 );
		$packer->prepare_finish( 300 + strlen( (string) $manifest ) + 4096 );
		$packer->finish( array( 'files.index.jsonl' => $index ), $manifest, 1758196800 );
		while ( $packer->hash_next_block() ) {
			continue;
		}
		$packer->close();
		return $packer;
	}

	private function unzip_ok( string $path ): bool {
		if ( 'Windows' === PHP_OS_FAMILY || ! is_executable( '/usr/bin/unzip' ) ) {
			$this->markTestSkipped( 'unzip is not available' );
		}
		exec( '/usr/bin/unzip -t ' . escapeshellarg( $path ) . ' 2>&1', $lines, $code );
		return 0 === $code;
	}

	public function test_a_single_volume_archive_is_a_valid_zip_that_other_tools_open(): void {
		$files  = array(
			array( $this->source( 'small.txt', 1000 ), 'files/wp-content/small.txt', 1758196800 ),
			array( $this->source( 'empty.txt', 0 ), 'files/wp-content/empty.txt', 1758196800 ),
			array( $this->source( 'big.bin', 200000 ), 'files/wp-content/uploads/2026/09/big.bin', 1600000000 ),
			array( $this->source( 'umlaut.txt', 10 ), 'files/wp-content/uploads/Übergrößen 中文.txt', 1758196800 ),
		);
		$packer = $this->pack( $files, $this->options() );
		$paths  = $packer->sealed_paths();
		$this->assertCount( 1, $paths );
		$this->assertStringEndsWith( 'site-20260918-100000-a1b2.wpcheckpoint.zip', $paths[0], 'a single volume takes the single name' );
		$this->assertTrue( $this->unzip_ok( $paths[0] ), 'unzip -t accepts it' );
		$this->assertSame( array(), glob( $this->out . '/*.partial' ) ?: array() );
		$this->assertSame( array(), glob( $this->out . '/*.cdr' ) ?: array() );

		$reader  = ZipReader::open( $paths[0] );
		$entries = $reader->entries();
		$this->assertSame( array( 'files/wp-content/small.txt', 'files/wp-content/empty.txt', 'files/wp-content/uploads/2026/09/big.bin', 'files/wp-content/uploads/Übergrößen 中文.txt', 'files.index.jsonl', 'manifest.json' ), array_column( $entries, 'name' ) );
		$this->assertSame( ZipFormat::METHOD_DEFLATE, $entries[0]['method'], 'small entries are deflated' );
		$this->assertSame( ZipFormat::METHOD_STORE, $entries[1]['method'], 'empty entries are stored' );
		$this->assertSame( ZipFormat::METHOD_STORE, $entries[2]['method'], 'large entries are stored' );
		foreach ( $entries as $entry ) {
			$this->assertSame( ZipFormat::FLAG_UTF8, $entry['flags'] & ZipFormat::FLAG_UTF8, 'UTF-8 names are flagged' );
			$this->assertNull( $entry['problem'] );
		}
		$this->assertSame( '{"embedded":true}', $reader->read( $reader->find( 'manifest.json' ) ) );

		$extracted = $this->root . '/x';
		mkdir( $extracted );
		foreach ( $entries as $entry ) {
			$reader->extract( $entry, $extracted );
		}
		foreach ( $files as list( $source, $entry ) ) {
			$this->assertSame( hash_file( 'sha256', $source ), hash_file( 'sha256', $extracted . '/' . $entry ), $entry );
		}

		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new \ZipArchive();
			$this->assertTrue( $zip->open( $paths[0] ) );
			$this->assertSame( 6, $zip->numFiles );
			$this->assertSame( hash_file( 'sha256', $files[2][0] ), hash( 'sha256', (string) $zip->getFromName( 'files/wp-content/uploads/2026/09/big.bin' ) ), 'ZipArchive reads a stored entry' );
			$this->assertSame( hash_file( 'sha256', $files[0][0] ), hash( 'sha256', (string) $zip->getFromName( 'files/wp-content/small.txt' ) ), 'ZipArchive inflates a deflated entry' );
			$zip->close();
		}

		$volumes = $packer->volume_entries();
		$this->assertCount( 1, $volumes );
		$expect = ChunkHasher::content_hash( $paths[0], 262144 );
		$this->assertSame( $expect['sha256'], $volumes[0]['sha256'] );
		$this->assertSame( $expect['chunks'], isset( $volumes[0]['chunks'] ) ? $volumes[0]['chunks'] : null, 'container chunks hashed after sealing match the format rule' );
	}

	public function test_volumes_are_sealed_between_entries_and_an_entry_never_spans_volumes(): void {
		$files = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$files[] = array( $this->source( "f$i.bin", 400000, $i + 1 ), "files/f$i.bin", 1758196800 );
		}
		$files[] = array( $this->source( 'huge.bin', 1500000, 9 ), 'files/huge.bin', 1758196800 ); // Larger than the volume threshold on its own.
		$packer  = $this->pack( $files, $this->options() );
		$paths   = $packer->sealed_paths();
		$this->assertGreaterThanOrEqual( 3, count( $paths ) );
		$this->assertStringEndsWith( '.part001.wpcheckpoint.zip', $paths[0] );
		$names = array();
		foreach ( $paths as $path ) {
			$this->assertTrue( $this->unzip_ok( $path ), basename( $path ) );
			$reader = ZipReader::open( $path );
			foreach ( $reader->entries() as $entry ) {
				$names[] = $entry['name'];
			}
		}
		$this->assertSame( array( 'files/f0.bin', 'files/f1.bin', 'files/f2.bin', 'files/f3.bin', 'files/f4.bin', 'files/huge.bin', 'files.index.jsonl', 'manifest.json' ), $names, 'every entry exactly once, in order, whole' );
		$huge = null;
		foreach ( $paths as $path ) {
			$found = ZipReader::open( $path )->find( 'files/huge.bin' );
			if ( null !== $found ) {
				$huge = filesize( $path );
			}
		}
		$this->assertGreaterThan( 1048576, $huge, 'the volume holding the oversized entry exceeds the threshold' );
		$entries = $packer->volume_entries();
		$this->assertCount( count( $paths ), $entries );
		foreach ( $entries as $i => $entry ) {
			$this->assertSame( ChunkHasher::content_hash( $paths[ $i ], 262144 )['sha256'], $entry['sha256'], basename( $paths[ $i ] ) );
		}
	}

	public function test_resuming_from_state_after_a_crash_yields_the_same_bytes(): void {
		$files = array(
			array( $this->source( 'a.bin', 300000, 1 ), 'files/a.bin', 1758196800 ),
			array( $this->source( 'b.txt', 3000, 2 ), 'files/b.txt', 1758196800 ),
			array( $this->source( 'c.bin', 700000, 3 ), 'files/c.bin', 1758196800 ),
		);
		$reference = $this->pack( $files, $this->options() );
		$expected  = array();
		foreach ( $reference->sealed_paths() as $path ) {
			$expected[ basename( $path ) ] = hash_file( 'sha256', $path );
		}
		$this->rm( $this->out );
		mkdir( $this->out );

		// Crash after every piece: keep only the state, corrupt what a dying tick may leave behind, resume.
		$state = array();
		$index = $this->source( 'files.index.jsonl', 300 );
		$step  = 0;
		foreach ( $files as list( $source, $entry, $mtime ) ) {
			$packer = Packer::open( $this->out, 'site-20260918-100000-a1b2', $state, $this->options() );
			$packer->add_entry( $source, $entry, $mtime );
			$state = $packer->state();
			$packer->close();
			do {
				$packer = Packer::open( $this->out, 'site-20260918-100000-a1b2', $state, $this->options() );
				$more   = $packer->write_piece( 100000 );
				$state  = $packer->state();
				$packer->close();
				// The tick died after the checkpoint: garbage after the committed point, a torn record line.
				foreach ( glob( $this->out . '/*.partial' ) ?: array() as $partial ) {
					file_put_contents( $partial, str_repeat( 'X', 1 + ( $step % 7 ) ), FILE_APPEND );
				}
				foreach ( glob( $this->out . '/*.cdr' ) ?: array() as $cdr ) {
					file_put_contents( $cdr, '{"torn":', FILE_APPEND );
				}
				++$step;
			} while ( $more > 0 );
		}
		$packer = Packer::open( $this->out, 'site-20260918-100000-a1b2', $state, $this->options() );
		$packer->prepare_finish( 300 + 4096 );
		$packer->finish( array( 'files.index.jsonl' => $index ), '{"embedded":true}', 1758196800 );
		$state = $packer->state();
		$packer->close();
		$packer = Packer::open( $this->out, 'site-20260918-100000-a1b2', $state, $this->options() );
		while ( $packer->hash_next_block() ) {
			$state  = $packer->state();
			$packer = Packer::open( $this->out, 'site-20260918-100000-a1b2', $state, $this->options() );
		}
		$this->assertGreaterThan( 10, $step, 'many ticks' );
		$actual = array();
		foreach ( $packer->sealed_paths() as $path ) {
			$actual[ basename( $path ) ] = hash_file( 'sha256', $path );
		}
		$this->assertSame( $expected, $actual, 'byte-for-byte identical to the uninterrupted run' );
		foreach ( $packer->sealed_paths() as $path ) {
			$this->assertTrue( $this->unzip_ok( $path ) );
		}
	}

	public function test_a_crash_between_the_seal_rename_and_the_checkpoint_is_replayed(): void {
		$files = array(
			array( $this->source( 'a.bin', 700000, 1 ), 'files/a.bin', 1758196800 ),
			array( $this->source( 'b.bin', 700000, 2 ), 'files/b.bin', 1758196800 ),
			array( $this->source( 'c.bin', 100000, 3 ), 'files/c.bin', 1758196800 ),
		);
		$reference = $this->pack( $files, $this->options() );
		$expected  = array();
		foreach ( $reference->sealed_paths() as $path ) {
			$expected[ basename( $path ) ] = hash_file( 'sha256', $path );
		}
		$this->rm( $this->out );
		mkdir( $this->out );

		$packer = Packer::open( $this->out, 'site-20260918-100000-a1b2', array(), $this->options() );
		foreach ( array_slice( $files, 0, 2 ) as list( $source, $entry, $mtime ) ) {
			$packer->add_entry( $source, $entry, $mtime );
			while ( $packer->write_piece() > 0 ) {
				continue;
			}
		}
		$before = $packer->state();       // The last checkpoint: volume 1 open with two entries.
		$this->assertTrue( $packer->has_open_volume() );
		$packer->seal_volume();           // rename() happened ...
		$packer->close();                 // ... and the tick dies before the step writes its cursor.
		unset( $packer );
		$this->assertFileExists( $this->out . '/site-20260918-100000-a1b2.part001.wpcheckpoint.zip' );
		$this->assertFileDoesNotExist( $this->out . '/site-20260918-100000-a1b2.part001.wpcheckpoint.zip.partial' );

		$packer = Packer::open( $this->out, 'site-20260918-100000-a1b2', $before, $this->options() );
		$this->assertFalse( $packer->has_open_volume(), 'the sealed volume was recognised from the stale cursor' );
		$this->assertSame( array(), glob( $this->out . '/*.cdr' ) ?: array(), 'the record file was cleaned up' );
		list( $source, $entry, $mtime ) = $files[2];
		$packer->add_entry( $source, $entry, $mtime );
		while ( $packer->write_piece() > 0 ) {
			continue;
		}
		$packer->prepare_finish( 300 + 4096 );
		$packer->finish( array( 'files.index.jsonl' => $this->source( 'files.index.jsonl', 300 ) ), '{"embedded":true}', 1758196800 );
		while ( $packer->hash_next_block() ) {
			continue;
		}
		$actual = array();
		foreach ( $packer->sealed_paths() as $path ) {
			$actual[ basename( $path ) ] = hash_file( 'sha256', $path );
		}
		$this->assertSame( $expected, $actual, 'byte-for-byte identical to the uninterrupted run' );

		// A genuinely foreign file under the next volume's name is still an error.
		$packer = Packer::open( $this->out, 'other', array(), $this->options() );
		file_put_contents( $this->out . '/other.part001.wpcheckpoint.zip', 'not ours' );
		$this->expectException( \RuntimeException::class );
		$packer->add_entry( $files[0][0], 'files/a.bin', 1758196800 );
	}

	public function test_a_crash_inside_finish_is_replayed_from_the_previous_checkpoint(): void {
		$files = array(
			array( $this->source( 'a.bin', 300000, 1 ), 'files/a.bin', 1758196800 ),
			array( $this->source( 'b.txt', 3000, 2 ), 'files/b.txt', 1758196800 ),
		);
		$index     = $this->source( 'files.index.jsonl', 300 );
		$reference = $this->pack( $files, $this->options() );
		$expected  = hash_file( 'sha256', $reference->sealed_paths()[0] );
		$this->rm( $this->out );
		mkdir( $this->out );

		$run = function ( array $state ): Packer {
			$packer = Packer::open( $this->out, 'site-20260918-100000-a1b2', $state, $this->options() );
			return $packer;
		};
		$packer = $run( array() );
		foreach ( $files as list( $source, $entry, $mtime ) ) {
			$packer->add_entry( $source, $entry, $mtime );
			while ( $packer->write_piece() > 0 ) {
				continue;
			}
		}
		$packer->prepare_finish( 300 + 4096 );
		$before = $packer->state(); // The checkpoint before finish(): prepared, the open volume keeps the summaries.

		// Crash 1: the summaries were appended and the volume sealed, but not renamed to the single name and not checkpointed.
		$packer->add_entry( $index, 'files.index.jsonl', 1758196800 );
		while ( $packer->write_piece() > 0 ) {
			continue;
		}
		$packer->add_string_entry( 'manifest.json', '{"embedded":true}', 1758196800 );
		$packer->seal_volume();
		$packer->close();
		unset( $packer );
		$packer = $run( $before );
		$this->assertFalse( $packer->has_open_volume(), 'adopted although it holds two more entries than the cursor knows' );
		$packer->finish( array( 'files.index.jsonl' => $index ), '{"embedded":true}', 1758196800 );
		while ( $packer->hash_next_block() ) {
			continue;
		}
		$this->assertStringEndsWith( 'site-20260918-100000-a1b2.wpcheckpoint.zip', $packer->sealed_paths()[0] );
		$this->assertSame( $expected, hash_file( 'sha256', $packer->sealed_paths()[0] ), 'byte-identical' );
		$this->assertCount( 1, glob( $this->out . '/*.zip' ) ?: array() );
		$this->rm( $this->out );
		mkdir( $this->out );

		// Crash 2: finish() completed (single name applied) and the tick died before the checkpoint.
		$packer = $run( array() );
		foreach ( $files as list( $source, $entry, $mtime ) ) {
			$packer->add_entry( $source, $entry, $mtime );
			while ( $packer->write_piece() > 0 ) {
				continue;
			}
		}
		$packer->prepare_finish( 300 + 4096 );
		$before = $packer->state();
		$packer->finish( array( 'files.index.jsonl' => $index ), '{"embedded":true}', 1758196800 );
		$packer->close();
		unset( $packer );
		$packer = $run( $before );
		$this->assertFalse( $packer->has_open_volume(), 'adopted under the single-volume name' );
		$packer->finish( array( 'files.index.jsonl' => $index ), '{"embedded":true}', 1758196800 );
		while ( $packer->hash_next_block() ) {
			continue;
		}
		$this->assertSame( $expected, hash_file( 'sha256', $packer->sealed_paths()[0] ), 'byte-identical' );
		$this->assertCount( 1, glob( $this->out . '/*' ) ?: array(), 'no stray files' );

		// A sealed volume with a foreign extra entry is not adopted.
		$this->rm( $this->out );
		mkdir( $this->out );
		$packer = $run( array() );
		$packer->add_entry( $files[0][0], 'files/a.bin', 1758196800 );
		while ( $packer->write_piece() > 0 ) {
			continue;
		}
		$before = $packer->state();
		$packer->add_entry( $files[1][0], 'files/not-a-summary.txt', 1758196800 );
		while ( $packer->write_piece() > 0 ) {
			continue;
		}
		$packer->seal_volume();
		$packer->close();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'The open volume is missing.' );
		$run( $before );
	}

	public function test_entry_names_must_be_utf8_and_volumes_are_sealed_at_the_entry_limit(): void {
		$source = $this->source( 'a.bin', 10, 1 );
		$packer = Packer::open( $this->out, 'site', array(), $this->options() );
		try {
			$packer->add_entry( $source, "files/latin1-\xE4.txt", 1758196800 );
			$this->fail( 'a Latin-1 name was accepted' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'not valid UTF-8', $e->getMessage() );
		}
		$this->assertFalse( $packer->has_open_volume(), 'refused before anything was written' );
		$this->assertLessThan( ZipReader::MAX_ENTRIES / 10, Packer::MAX_VOLUME_ENTRIES, 'the writer seals far below what the reader accepts' );
	}

	public function test_the_platform_volume_bound_seals_before_an_entry_would_cross_it(): void {
		$files = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$files[] = array( $this->source( "f$i.bin", 400000, $i + 1 ), "files/f$i.bin", 1758196800 );
		}
		// Every entry fits on its own; two would exceed the (lowered) platform bound.
		$packer = $this->pack( $files, $this->options( array( 'volume_bytes' => 10485760, 'max_volume_bytes' => 700000 ) ) );
		$paths  = $packer->sealed_paths();
		$this->assertCount( 3, $paths, 'one entry per volume although the seal threshold was never reached' );
		foreach ( $paths as $path ) {
			$this->assertLessThan( 700000, filesize( $path ) );
		}
		$this->assertSame( PHP_INT_SIZE >= 8 ? 4398046511104 : 2147483647 - 1048576, Packer::max_volume_bytes() );
	}

	public function test_a_volume_shorter_than_its_committed_length_is_refused_not_padded(): void {
		$source = $this->source( 'a.bin', 300000, 1 );
		$packer = Packer::open( $this->out, 'site', array(), $this->options() );
		$packer->add_entry( $source, 'files/a.bin', 1758196800 );
		$packer->write_piece( 100000 );
		$packer->write_piece( 100000 );
		$state = $packer->state();
		$packer->close();
		// The file lost the last 50000 bytes the state says were committed (a changed work directory, or an OS
		// that dropped acknowledged writes): nothing can say what those bytes were, so nothing is padded.
		$partial = glob( $this->out . '/*.partial' )[0];
		$h       = fopen( $partial, 'r+b' );
		ftruncate( $h, filesize( $partial ) - 50000 );
		fclose( $h );
		clearstatcache( true, $partial );
		$before = filesize( $partial );
		try {
			Packer::open( $this->out, 'site', $state, $this->options() );
			$this->fail();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'shorter than its recorded committed length', $e->getMessage() );
		}
		clearstatcache( true, $partial );
		$this->assertSame( $before, filesize( $partial ), 'the volume was not lengthened' );
	}

	public function test_an_aborted_entry_leaves_its_bytes_until_the_next_open_cuts_them_and_a_shrunken_source_is_reported(): void {
		$source = $this->source( 'a.bin', 300000, 1 );
		$packer = Packer::open( $this->out, 'site', array(), $this->options() );
		$packer->add_entry( $source, 'files/a.bin', 1758196800 );
		$packer->write_piece( 100000 );
		$partial = glob( $this->out . '/*.partial' )[0];
		clearstatcache( true, $partial );
		$long = filesize( $partial );
		$packer->abort_entry();
		$this->assertFalse( $packer->has_open_entry() );
		$state = $packer->state();
		$packer->close();
		clearstatcache( true, $partial );
		$this->assertSame( $long, filesize( $partial ), 'the file is not cut before the state is persisted' );
		$this->assertLessThan( $long, $state['volume']['bytes'], 'the state points back at the entry header' );
		$packer = Packer::open( $this->out, 'site', $state, $this->options() );
		clearstatcache( true, $partial );
		$this->assertSame( $state['volume']['bytes'], filesize( $partial ), 'resume cuts the volume back to the committed length' );
		// The source shrinks under an open entry: a distinct exception, so the caller can start the entry over.
		$packer->add_entry( $source, 'files/a.bin', 1758196800 );
		$packer->write_piece( 100000 );
		$h = fopen( $source, 'r+b' );
		ftruncate( $h, 150000 );
		fclose( $h );
		try {
			while ( $packer->write_piece( 100000 ) > 0 ) {
				continue;
			}
			$this->fail();
		} catch ( SourceChanged $e ) {
			$this->assertStringContainsString( 'changed while it was being archived', $e->getMessage() );
		}
	}

	public function test_zip64_records_are_written_when_the_threshold_says_so(): void {
		$files  = array(
			array( $this->source( 'a.bin', 300000, 1 ), 'files/a.bin', 1758196800 ),
			array( $this->source( 'b.bin', 300000, 2 ), 'files/b.bin', 1758196800 ),
		);
		$packer = $this->pack( $files, $this->options( array( 'zip64_threshold' => 200000 ) ) );
		$path   = $packer->sealed_paths()[0];
		$this->assertTrue( $this->unzip_ok( $path ), 'unzip -t accepts the zip64 structures' );
		$reader = ZipReader::open( $path );
		$this->assertSame( 300000, $reader->find( 'files/b.bin' )['usize'], 'sizes come from the zip64 extra field' );
		$out = $this->root . '/x';
		mkdir( $out );
		$reader->extract( $reader->find( 'files/b.bin' ), $out );
		$this->assertSame( hash_file( 'sha256', $files[1][0] ), hash_file( 'sha256', $out . '/files/b.bin' ) );
		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new \ZipArchive();
			$this->assertTrue( $zip->open( $path ) );
			$this->assertSame( 300000, $zip->statName( 'files/a.bin' )['size'] );
			$zip->close();
		}
	}

	public function test_summaries_that_do_not_fit_get_their_own_last_volume(): void {
		$files  = array( array( $this->source( 'a.bin', 1000000, 1 ), 'files/a.bin', 1758196800 ) );
		$packer = Packer::open( $this->out, 'site', array(), $this->options() );
		foreach ( $files as list( $source, $entry, $mtime ) ) {
			$packer->add_entry( $source, $entry, $mtime );
			while ( $packer->write_piece() > 0 ) {
				continue;
			}
		}
		$index = $this->source( 'files.index.jsonl', 200000 );
		$this->assertTrue( $packer->prepare_finish( 200000 + 4096 ), 'the summaries do not fit next to the data: the data volume is sealed first' );
		while ( $packer->hash_next_block() ) {
			continue;
		}
		$this->assertCount( 1, $packer->volume_entries(), 'the embedded copy lists the data volume: every volume but the one holding it' );
		$packer->finish( array( 'files.index.jsonl' => $index ), '{"embedded":true}', 1758196800 );
		$paths = $packer->sealed_paths();
		$this->assertCount( 2, $paths, 'the summaries did not fit next to the data: a volume of their own' );
		$this->assertSame( array( 'files.index.jsonl', 'manifest.json' ), array_column( ZipReader::open( $paths[1] )->entries(), 'name' ) );
	}

	public function test_disk_space_and_entry_limits(): void {
		$source = $this->source( 'a.bin', 300000, 1 );
		$packer = Packer::open( $this->out, 'site', array(), $this->options( array( 'disk_free' => static function (): int {
			return 1000;
		} ) ) );
		$caught = null;
		try {
			$packer->add_entry( $source, 'files/a.bin', 1758196800 );
		} catch ( InsufficientSpace $e ) {
			$caught = $e;
		}
		$this->assertInstanceOf( \WPCheckpoint\Jobs\TransientFailure::class, $caught, 'a full disk is a transient failure the runner retries with back-off' );

		$packer = Packer::open( $this->out, 'site2', array(), $this->options( array( 'disk_free' => static function () {
			return false; // Unknown: does not block.
		} ) ) );
		$packer->add_entry( $source, 'files/a.bin', 1758196800 );
		$this->assertGreaterThan( 0, $packer->write_piece( 1000 ) );
		$this->assertTrue( $packer->has_open_entry() );
		$message = '';
		try {
			$packer->add_entry( $source, 'files/b.bin', 1758196800 );
		} catch ( \RuntimeException $e ) {
			$message = $e->getMessage();
		}
		$this->assertStringContainsString( 'in progress', $message, 'an entry is still open' );
		$packer->abort_entry();
		$this->assertFalse( $packer->has_open_entry() );
		$message = '';
		try {
			$packer->add_entry( $source, '../evil', 1758196800 );
		} catch ( \RuntimeException $e ) {
			$message = $e->getMessage();
		}
		$this->assertStringContainsString( 'Invalid entry path', $message, 'entry paths are validated' );
		$this->assertSame( PHP_INT_SIZE >= 8 ? 4398046511104 : 2147483647, Packer::max_entry_bytes() );
		$this->assertSame( array( 'bytes' => 2147483647, 'limited_by' => 'int_size' ), Packer::max_file_bytes( 16777216, 4 ), 'on 32-bit PHP the container limit is the lower one' );
		$this->assertSame( array( 'bytes' => IndexLine::max_indexable_bytes( 16777216 ), 'limited_by' => 'index' ), Packer::max_file_bytes( 16777216, 8 ), 'on 64-bit PHP the index line is the lower one' );
		$this->assertSame( 4398046511104, Packer::max_file_bytes( 1073741824, 8 )['bytes'], 'with 1 GiB chunks the container limit is the lower one again' );
		$this->assertSame( Packer::VOLUME_BYTES + Packer::SPACE_MARGIN_BYTES, Packer::required_free_bytes() );
	}

	/**
	 * The acceptance case: 2 GiB of data packed within 128 MB of memory.
	 * Sources are sparse (zeros) so only the archive costs disk; the stored
	 * entries are copied byte for byte, so the archive is real.
	 *
	 * @group slow
	 */
	public function test_two_gigabytes_are_packed_within_128_megabytes_of_memory(): void {
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'Sparse sources are not guaranteed on the Windows runner.' );
		}
		if ( -1 === (int) ini_get( 'memory_limit' ) || (int) ini_get( 'memory_limit' ) > 128 ) {
			ini_set( 'memory_limit', '128M' );
		}
		$files = array();
		foreach ( array( 700, 700, 748 ) as $i => $mb ) {
			$path = $this->src . "/big$i.bin";
			$h    = fopen( $path, 'wb' );
			fwrite( $h, "start$i" );
			ftruncate( $h, $mb * 1048576 );
			fclose( $h );
			$files[] = array( $path, "files/big$i.bin", 1758196800 );
		}
		$before = memory_get_usage( true );
		$packer = Packer::open( $this->out, 'big', array(), array( 'disk_free' => static function () {
			return false;
		} ) );
		foreach ( $files as list( $source, $entry, $mtime ) ) {
			$packer->add_entry( $source, $entry, $mtime );
			while ( $packer->write_piece() > 0 ) {
				continue;
			}
		}
		$packer->prepare_finish( 4096 );
		$packer->finish( array(), '{"embedded":true}', 1758196800 );
		while ( $packer->hash_next_block() ) {
			continue;
		}
		$packer->close();
		$this->assertLessThan( 32 * 1048576, memory_get_peak_usage( true ) - $before, 'memory growth stays below the step budget' );
		$paths = $packer->sealed_paths();
		// 700 + 700 MB reach the 1 GiB threshold, so the first volume holds both (it exceeds the threshold
		// by its last entry, an entry never spans volumes) and the second holds the rest.
		$this->assertCount( 2, $paths );
		$this->assertGreaterThan( 1073741824, filesize( $paths[0] ), 'the seal threshold is not a size guarantee' );
		$total = 0;
		foreach ( $paths as $path ) {
			$total += filesize( $path );
			$this->assertTrue( $this->unzip_ok( $path ), basename( $path ) );
		}
		$this->assertGreaterThan( 2 * 1073741824, $total );
		$entries = $packer->volume_entries();
		$this->assertCount( ChunkHasher::chunk_count( (int) filesize( $paths[0] ), 268435456 ), $entries[0]['chunks'], '256 MiB container chunks' );
		$this->assertSame( ChunkHasher::content_hash( $paths[0], 268435456 )['sha256'], $entries[0]['sha256'] );
	}

	public function test_a_changed_source_is_refused(): void {
		$source = $this->source( 'a.bin', 300000, 1 );
		$packer = Packer::open( $this->out, 'site', array(), $this->options() );
		$packer->add_entry( $source, 'files/a.bin', 1758196800 );
		$packer->write_piece( 100000 );
		$state = $packer->state();
		$packer->close();
		file_put_contents( $source, 'grown', FILE_APPEND );
		$packer = Packer::open( $this->out, 'site', $state, $this->options() );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'changed while it was being archived' );
		$packer->write_piece( 100000 );
	}

	public function test_a_crash_between_the_prepare_seal_and_its_checkpoint_is_replayed(): void {
		$files     = array( array( $this->source( 'a.bin', 1000000, 1 ), 'files/a.bin', 1758196800 ) );
		$index     = $this->source( 'files.index.jsonl', 200000 );
		$reference = $this->pack_two( $files, $index );
		$this->rm( $this->out );
		mkdir( $this->out );

		$packer = Packer::open( $this->out, 'site-20260918-100000-a1b2', array(), $this->options() );
		$packer->add_entry( $files[0][0], 'files/a.bin', 1758196800 );
		while ( $packer->write_piece() > 0 ) {
			continue;
		}
		$before = $packer->state(); // The last checkpoint: volume 1 open.
		$this->assertTrue( $packer->prepare_finish( 200000 + 4096 ) ); // Sealed and renamed ...
		$packer->close();                                                // ... and the tick died before the checkpoint.
		unset( $packer );

		$packer = Packer::open( $this->out, 'site-20260918-100000-a1b2', $before, $this->options() );
		$this->assertFalse( $packer->has_open_volume(), 'the sealed data volume was adopted from the stale state' );
		$this->assertFalse( $packer->prepare_finish( 200000 + 4096 ), 'nothing left to seal' );
		while ( $packer->hash_next_block() ) {
			continue;
		}
		$this->assertCount( 1, $packer->volume_entries() );
		$packer->finish( array( 'files.index.jsonl' => $index ), '{"embedded":true}', 1758196800 );
		while ( $packer->hash_next_block() ) {
			continue;
		}
		$this->assertSame( $reference, $this->hashes_of( $packer ), 'byte for byte the uninterrupted run' );
	}

	public function test_a_crash_after_finish_created_the_summary_volume_is_replayed_not_refused(): void {
		$files     = array( array( $this->source( 'a.bin', 1000000, 1 ), 'files/a.bin', 1758196800 ) );
		$index     = $this->source( 'files.index.jsonl', 200000 );
		$reference = $this->pack_two( $files, $index );
		$this->rm( $this->out );
		mkdir( $this->out );

		$packer = Packer::open( $this->out, 'site-20260918-100000-a1b2', array(), $this->options() );
		$packer->add_entry( $files[0][0], 'files/a.bin', 1758196800 );
		while ( $packer->write_piece() > 0 ) {
			continue;
		}
		$this->assertTrue( $packer->prepare_finish( 200000 + 4096 ) );
		$before = $packer->state(); // The checkpoint after prepare_finish(): no volume open, one sealed.
		$packer->finish( array( 'files.index.jsonl' => $index ), '{"embedded":true}', 1758196800 ); // Volume 2 created, sealed ...
		$packer->close();                                                                              // ... and the tick died before the checkpoint.
		unset( $packer );
		$this->assertFileExists( $this->out . '/site-20260918-100000-a1b2.part002.wpcheckpoint.zip' );

		$packer = Packer::open( $this->out, 'site-20260918-100000-a1b2', $before, $this->options() );
		$packer->finish( array( 'files.index.jsonl' => $index ), '{"embedded":true}', 1758196800 );
		while ( $packer->hash_next_block() ) {
			continue;
		}
		$this->assertSame( $reference, $this->hashes_of( $packer ), 'the summary volume on disk was adopted, not refused as a foreign file' );
		$this->assertCount( 2, glob( $this->out . '/*' ) ?: array(), 'no stray files' );

		// A leftover .partial of the summary volume (created, no checkpoint) is replaced, not refused.
		$this->rm( $this->out );
		mkdir( $this->out );
		$packer = Packer::open( $this->out, 'site-20260918-100000-a1b2', array(), $this->options() );
		$packer->add_entry( $files[0][0], 'files/a.bin', 1758196800 );
		while ( $packer->write_piece() > 0 ) {
			continue;
		}
		$packer->prepare_finish( 200000 + 4096 );
		$before = $packer->state();
		$packer->close();
		file_put_contents( $this->out . '/site-20260918-100000-a1b2.part002.wpcheckpoint.zip.partial', 'half a header' );
		file_put_contents( $this->out . '/site-20260918-100000-a1b2.part002.wpcheckpoint.zip.cdr', '' );
		$packer = Packer::open( $this->out, 'site-20260918-100000-a1b2', $before, $this->options() );
		$packer->finish( array( 'files.index.jsonl' => $index ), '{"embedded":true}', 1758196800 );
		while ( $packer->hash_next_block() ) {
			continue;
		}
		$this->assertSame( $reference, $this->hashes_of( $packer ) );
	}

	public function test_finish_without_prepare_is_refused(): void {
		$packer = Packer::open( $this->out, 'site', array(), $this->options() );
		$packer->add_entry( $this->source( 'a.bin', 1000, 1 ), 'files/a.bin', 1758196800 );
		while ( $packer->write_piece() > 0 ) {
			continue;
		}
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'prepare_finish()' );
		$packer->finish( array(), '{"embedded":true}', 1758196800 );
	}

	public function test_a_missing_or_unreadable_source_is_reported_as_gone_not_as_changed(): void {
		$packer = Packer::open( $this->out, 'site', array(), $this->options() );
		try {
			$packer->add_entry( $this->out . '/nope.bin', 'files/nope.bin', 1758196800 );
			$this->fail( 'a missing source must be refused' );
		} catch ( SourceGone $e ) {
			$this->assertFalse( $packer->has_open_entry(), 'nothing was written' );
		}
		$source = $this->source( 'gone.bin', 300000, 4 );
		$packer->add_entry( $source, 'files/gone.bin', 1758196800 );
		$packer->write_piece( 65536 );
		$state = $packer->state();
		$packer->close(); // The tick ends with the entry open; the file is deleted before the next one.
		unlink( $source );
		$packer = Packer::open( $this->out, 'site', $state, $this->options() );
		try {
			$packer->write_piece( 65536 );
			$this->fail( 'a source deleted mid-entry must be refused' );
		} catch ( SourceGone $e ) {
			$this->assertTrue( $packer->has_open_entry(), 'the entry is left for the caller to abort' );
		}
		$packer->abort_entry();
		$this->assertFalse( $packer->has_open_entry() );
	}

	public function test_sealing_right_after_an_abort_cuts_the_aborted_bytes(): void {
		$packer = Packer::open( $this->out, 'site', array(), $this->options() );
		$packer->add_entry( $this->source( 'keep.bin', 100000, 1 ), 'files/keep.bin', 1758196800 );
		while ( $packer->write_piece() > 0 ) {
			continue;
		}
		$packer->add_entry( $this->source( 'drop.bin', 300000, 2 ), 'files/drop.bin', 1758196800 );
		$packer->write_piece( 65536 );
		$packer->abort_entry();
		$sealed = $packer->seal_volume();
		$packer->close();
		$this->assertSame( $sealed['bytes'], filesize( $this->out . '/' . $sealed['path'] ), 'nothing after the end record' );
		$this->assertSame( array( 'files/keep.bin' ), array_column( ZipReader::open( $this->out . '/' . $sealed['path'] )->entries(), 'name' ) );
		$this->assertTrue( $this->unzip_ok( $this->out . '/' . $sealed['path'] ) );
	}

	/**
	 * A reference two-volume archive (data, then summaries) as sha256 by volume name.
	 *
	 * @return array<string, string>
	 */
	private function pack_two( array $files, string $index ): array {
		$packer = Packer::open( $this->out, 'site-20260918-100000-a1b2', array(), $this->options() );
		foreach ( $files as list( $source, $entry, $mtime ) ) {
			$packer->add_entry( $source, $entry, $mtime );
			while ( $packer->write_piece() > 0 ) {
				continue;
			}
		}
		$this->assertTrue( $packer->prepare_finish( 200000 + 4096 ) );
		$packer->finish( array( 'files.index.jsonl' => $index ), '{"embedded":true}', 1758196800 );
		while ( $packer->hash_next_block() ) {
			continue;
		}
		$packer->close();
		$this->assertCount( 2, $packer->sealed_paths() );
		return $this->hashes_of( $packer );
	}

	/**
	 * @return array<string, string>
	 */
	private function hashes_of( Packer $packer ): array {
		$out = array();
		foreach ( $packer->sealed_paths() as $path ) {
			$out[ basename( $path ) ] = hash_file( 'sha256', $path );
		}
		return $out;
	}
}
