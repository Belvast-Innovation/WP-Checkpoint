<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Archive\ChunkHasher;
use WPCheckpoint\Archive\IndexLine;
use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Archive\Packer;
use WPCheckpoint\Archive\ZipReader;
use WPCheckpoint\Jobs\ExportPlan;
use WPCheckpoint\Jobs\PackStep;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Tests\Fixtures\Jobs\WorkContext;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The pack step over real files: the plan becomes fact in the packed
 * index, chunks are units with their own hash contexts, the three
 * committed lengths move together and are cut back together, and a file
 * that changes under the step is started over or given up.
 */
final class PackStepTest extends TestCase {

	const CHUNK = 65536;
	const BASE  = 'site-20260922-100000-ab12';

	/** @var WorkContext */
	private $ctx;

	/** @var string */
	private $site;

	protected function set_up(): void {
		$this->ctx  = new WorkContext( 'wpcheckpoint-pack-' );
		$this->site = $this->ctx->root . '/site/wp-content/uploads';
		mkdir( $this->site, 0700, true );
		ExportPlan::write( $this->ctx->work(), ExportPlan::PLAN, array( 'base' => self::BASE, 'tables' => array(), 'groups' => array( 'uploads' ), 'exclusions' => array( 'wp-content/uploads/cache' ) ) );
		ExportPlan::write( $this->ctx->work(), ExportPlan::REVIEW, array( 'findings' => array(), 'decisions' => array( 'exclude_tables' => array(), 'exclude_oversize' => array(), 'exclude_paths' => array( 'wp-content/uploads/[2024]/node_modules' ), 'notes' => array() ) ) );
		file_put_contents( $this->ctx->work() . '/database.index.jsonl', '' );
	}

	protected function tear_down(): void {
		$this->ctx->remove();
	}

	/**
	 * A file under uploads with deterministic content; returns its archive path.
	 */
	private function file( string $rel, int $bytes, int $seed = 1 ): string {
		$path = $this->site . '/' . $rel;
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0700, true );
		}
		$handle = fopen( $path, 'wb' );
		mt_srand( $seed );
		$left = $bytes;
		while ( $left > 0 ) {
			$piece = '';
			for ( $i = 0; $i < min( $left, 4096 ); $i++ ) {
				$piece .= chr( mt_rand( 32, 126 ) );
			}
			fwrite( $handle, $piece );
			$left -= strlen( $piece );
		}
		fclose( $handle );
		return 'wp-content/uploads/' . $rel;
	}

	/**
	 * Write the scan index for the given archive paths (stat as they are now).
	 *
	 * @param string[] $paths Archive paths.
	 */
	private function index( array $paths ): void {
		$lines = array();
		foreach ( $paths as $p ) {
			$abs     = $this->site . substr( $p, strlen( 'wp-content/uploads' ) );
			$lines[] = json_encode( array( 'p' => $p, 'b' => filesize( $abs ), 'm' => filemtime( $abs ) ), JSON_UNESCAPED_SLASHES );
		}
		file_put_contents( $this->ctx->work() . '/files.index.jsonl', implode( "\n", $lines ) . "\n" );
	}

	private function roots(): array {
		return array( array( 'group' => 'uploads', 'path' => $this->site, 'prefix' => 'wp-content/uploads', 'skip' => array() ) );
	}

	private function options( array $extra = array() ): array {
		return array_merge( array( 'volume_bytes' => 300000, 'volume_chunk_bytes' => self::CHUNK, 'deflate_max_bytes' => 4096, 'disk_free' => static function (): int {
			return PHP_INT_MAX;
		} ), $extra );
	}

	private function step( $after_chunk = null, array $extra = array() ): PackStep {
		return new PackStep( $this->roots(), $this->options( $extra ), self::CHUNK, $after_chunk );
	}

	/**
	 * Run the step tick by tick from a cursor until done; returns the results.
	 *
	 * @return array{0: StepResult, 1: int, 2: array<string, mixed>}
	 */
	private function drive( PackStep $step, array $cursor = array(), int $seconds = 20, int $max = 200 ): array {
		$ticks = 0;
		do {
			$result = $step->run( $this->ctx->context( $cursor, $seconds ) );
			++$ticks;
			if ( StepResult::PROGRESS === $result->kind ) {
				$cursor = $result->cursor;
			}
			$this->assertLessThan( $max, $ticks, 'the step must finish' );
		} while ( StepResult::DONE !== $result->kind );
		return array( $result, $ticks, $cursor );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function packed(): array {
		$lines = array_values( array_filter( explode( "\n", (string) file_get_contents( $this->ctx->work() . '/' . PackStep::PACKED_INDEX ) ) ) );
		return array_map( function ( string $line ): array {
			return IndexLine::files( $line, self::CHUNK );
		}, $lines );
	}

	private function summary(): array {
		return ExportPlan::read( $this->ctx->work(), PackStep::SUMMARY );
	}

	/**
	 * Entry names in archive order: the sealed volumes' central directories, then the open volume's records.
	 *
	 * @return string[]
	 */
	private function entry_names(): array {
		$names = array();
		$dir   = $this->ctx->work() . '/' . PackStep::VOLUMES;
		$zips  = glob( $dir . '/*.wpcheckpoint.zip' ) ?: array();
		sort( $zips );
		foreach ( $zips as $volume ) {
			foreach ( ZipReader::open( $volume )->entries() as $entry ) {
				$names[] = $entry['name'];
			}
		}
		foreach ( glob( $dir . '/*.cdr' ) ?: array() as $records ) {
			foreach ( array_filter( explode( "\n", (string) file_get_contents( $records ) ) ) as $line ) {
				$names[] = json_decode( $line, true )['name'];
			}
		}
		return $names;
	}

	public function test_the_plan_becomes_fact_across_ticks_with_hashes_matching_the_files(): void {
		$paths = array( $this->file( 'a.txt', 100 ), $this->file( 'big.bin', 3 * self::CHUNK + 500, 2 ), $this->file( 'cache/x.tmp', 50 ), $this->file( '[2024]/node_modules/m.js', 60 ), $this->file( '2/node_modules/keep.js', 70 ), $this->file( 'gone.txt', 80 ), $this->file( 'grew.txt', 90 ) );
		$this->index( $paths );
		unlink( $this->site . '/gone.txt' );
		file_put_contents( $this->site . '/grew.txt', str_repeat( 'g', 900 ) );
		// A 20-second budget on a clock that moves 3 seconds per reading: a chunk "takes" 6 seconds, so after
		// two chunks the remaining 8 seconds are less than 1.5 times the last chunk and the tick ends.
		$this->ctx->tick = 3.0;
		list( $result, $ticks ) = $this->drive( $this->step( null, array( 'volume_bytes' => 100000 ) ) );
		$this->assertGreaterThan( 2, $ticks, 'the measured chunk cost ended ticks early' );
		$packed = $this->packed();
		$this->assertSame( array( 'wp-content/uploads/a.txt', 'wp-content/uploads/big.bin', 'wp-content/uploads/2/node_modules/keep.js', 'wp-content/uploads/grew.txt' ), array_column( $packed, 'p' ), 'plan order; cache excluded by pattern, [2024]/node_modules by literal path, gone.txt missing' );
		$this->assertSame( 900, $packed[3]['b'], 'the size as packed, not as scanned' );
		$this->assertSame( hash( 'sha256', str_repeat( 'g', 900 ) ), $packed[3]['h'] );
		$big = $packed[1];
		$this->assertCount( 4, $big['hc'] );
		$this->assertSame( ChunkHasher::list_hash( $big['hc'] ), $big['h'] );
		$this->assertSame( ChunkHasher::hash_chunks( $this->site . '/big.bin', self::CHUNK ), $big['hc'], 'chunk hashes from the same read as the pack equal ChunkHasher on the file' );
		$summary = $this->summary();
		$this->assertSame( 4, $summary['files'] );
		$this->assertSame( 2, $summary['excluded'] );
		$this->assertSame( array( 'count' => 1, 'listed' => array( 'wp-content/uploads/gone.txt' ) ), $summary['skipped'] );
		$this->assertCount( 1, preg_grep( '/1 files listed by the scan were missing or unreadable/', $summary['warnings'] ) );
		$this->assertFileExists( $this->ctx->work() . '/' . PackStep::STATE );
		// The volumes hold exactly those entries, in order, and every sealed volume is hashed.
		$state = ExportPlan::read( $this->ctx->work(), PackStep::STATE );
		$this->assertGreaterThanOrEqual( 1, count( $state['sealed'] ), 'small volumes: at least one sealed' );
		foreach ( $state['sealed'] as $sealed ) {
			$this->assertGreaterThan( 0, $sealed['hashed'] );
		}
		$this->assertSame( array( 'files/wp-content/uploads/a.txt', 'files/wp-content/uploads/big.bin', 'files/wp-content/uploads/2/node_modules/keep.js', 'files/wp-content/uploads/grew.txt' ), $this->entry_names(), 'the volumes hold exactly the packed entries, in order; the last volume stays open for the manifest step' );
	}

	public function test_committed_lengths_are_cut_back_together_and_a_shorter_file_fails_closed(): void {
		$this->index( array( $this->file( 'one.bin', 2 * self::CHUNK + 10, 3 ), $this->file( 'two.bin', 100, 4 ) ) );
		// A reference run in a second work directory with the same files.
		$reference = new WorkContext( 'wpcheckpoint-pack-ref-' );
		foreach ( array( ExportPlan::PLAN, ExportPlan::REVIEW, 'database.index.jsonl', 'files.index.jsonl' ) as $name ) {
			copy( $this->ctx->work() . '/' . $name, $reference->work() . '/' . $name );
		}
		$ref_step = new PackStep( $this->roots(), $this->options(), self::CHUNK );
		do {
			$result = $ref_step->run( $reference->context( isset( $result ) ? $result->cursor : array() ) );
		} while ( StepResult::DONE !== $result->kind );
		$expected = array_column( array_map( function ( string $line ): array {
			return IndexLine::files( $line, self::CHUNK );
		}, array_values( array_filter( explode( "\n", (string) file_get_contents( $reference->work() . '/' . PackStep::PACKED_INDEX ) ) ) ) ), 'h' );
		$reference->remove();

		// One unit per tick (the clock makes every chunk cost 10 of the 20 seconds); stop after the first chunk of one.bin.
		$this->ctx->tick = 5.0;
		$cursor          = array();
		$mid             = null;
		for ( $i = 0; $i < 50 && null === $mid; $i++ ) {
			$result = $this->step()->run( $this->ctx->context( $cursor ) );
			$this->assertSame( StepResult::PROGRESS, $result->kind );
			$cursor = $result->cursor;
			if ( isset( $cursor['file']['p'] ) && 'wp-content/uploads/one.bin' === $cursor['file']['p'] && 1 === (int) $cursor['file']['chunk'] ) {
				$mid = $cursor;
			}
		}
		$this->assertNotNull( $mid, 'a cursor right after the first chunk of one.bin' );
		// The tick then died after more work: bytes beyond every committed length.
		file_put_contents( $this->ctx->work() . '/' . PackStep::PACKED_INDEX, "{\"p\":\"ghost\"}\n", FILE_APPEND );
		file_put_contents( $this->ctx->work() . '/' . PackStep::CHUNKS, "{\"i\":9,\"h\":\"x\"}\n", FILE_APPEND );
		$partial = glob( $this->ctx->work() . '/' . PackStep::VOLUMES . '/*.partial' )[0];
		file_put_contents( $partial, str_repeat( 'Z', 5000 ), FILE_APPEND );
		$this->ctx->tick = 0.0;
		list( $result ) = $this->drive( $this->step(), $mid );
		$this->assertSame( StepResult::DONE, $result->kind );
		$this->assertSame( $expected, array_column( $this->packed(), 'h' ), 'the same hashes as an uninterrupted run: nothing duplicated, nothing lost' );
		$this->assertStringNotContainsString( 'ghost', (string) file_get_contents( $this->ctx->work() . '/' . PackStep::PACKED_INDEX ) );

		// A volume shorter than the committed length: refused, not padded.
		$this->ctx->remove();
		$this->set_up();
		$this->index( array( $this->file( 'one.bin', 2 * self::CHUNK + 10, 3 ), $this->file( 'two.bin', 100, 4 ) ) );
		$this->ctx->tick = 5.0;
		$cursor          = array();
		for ( $i = 0; $i < 10; $i++ ) { // One unit per tick: the phase switch, the volume, the file, then its first chunk.
			$result = $this->step()->run( $this->ctx->context( $cursor ) );
			$cursor = $result->cursor;
			if ( isset( $cursor['file']['chunk'] ) && $cursor['file']['chunk'] >= 1 ) {
				break;
			}
		}
		$this->assertGreaterThanOrEqual( 1, $cursor['file']['chunk'], 'a chunk is committed' );
		$partial = glob( $this->ctx->work() . '/' . PackStep::VOLUMES . '/*.partial' )[0];
		$h               = fopen( $partial, 'r+b' );
		ftruncate( $h, 10 );
		fclose( $h );
		try {
			$this->step()->run( $this->ctx->context( $cursor ) );
			$this->fail( 'must refuse' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'shorter than its recorded committed length', $e->getMessage() );
		}
		clearstatcache( true, $partial );
		$this->assertSame( 10, filesize( $partial ), 'not lengthened' );
		// The same for the packed index.
		$this->ctx->remove();
		$this->set_up();
		$this->index( array( $this->file( 'a.txt', 10 ), $this->file( 'b.txt', 10 ) ) );
		$this->ctx->tick = 5.0;
		$cursor          = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$result = $this->step()->run( $this->ctx->context( $cursor ) );
			$cursor = $result->cursor;
			if ( $cursor['packed_bytes'] > 0 ) {
				break;
			}
		}
		$this->assertGreaterThan( 0, $result->cursor['packed_bytes'] );
		file_put_contents( $this->ctx->work() . '/' . PackStep::PACKED_INDEX, '' );
		try {
			$this->step()->run( $this->ctx->context( $result->cursor ) );
			$this->fail( 'must refuse' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'shorter than its recorded committed length', $e->getMessage() );
		}
	}

	public function test_a_file_changed_between_two_chunks_of_one_tick_is_started_over_and_ends_up_consistent(): void {
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'A file open for reading cannot be renamed over on Windows, and stat() reports no inode there: the inode swap is a POSIX scenario.' );
		}
		$p        = $this->file( 'live.bin', 3 * self::CHUNK, 5 );
		$this->index( array( $p ) );
		$abs      = $this->site . '/live.bin';
		$changed  = false;
		$step     = $this->step( function ( string $path, int $chunk ) use ( $abs, &$changed ): void {
			if ( ! $changed && 1 === $chunk ) {
				// Same size, same second, new content: only the inode differs (write a copy and rename it over).
				$copy = $abs . '.new';
				file_put_contents( $copy, str_repeat( 'N', 3 * self::CHUNK ) );
				touch( $copy, filemtime( $abs ) );
				rename( $copy, $abs );
				$changed = true;
			}
		} );
		list( $result ) = $this->drive( $step );
		$this->assertSame( StepResult::DONE, $result->kind );
		$packed = $this->packed();
		$this->assertCount( 1, $packed );
		$this->assertSame( str_repeat( 'N', 3 * self::CHUNK ), (string) file_get_contents( $abs ) );
		$this->assertSame( ChunkHasher::hash_chunks( $abs, self::CHUNK ), $packed[0]['hc'], 'the packed content is the new file, whole' );
		$this->assertSame( ChunkHasher::list_hash( $packed[0]['hc'] ), $packed[0]['h'] );
		$this->assertStringContainsString( 'starting it over', $this->ctx->log() );
		$this->assertSame( 0, $this->summary()['changed']['count'], 'a restart that succeeded is not a warning' );
		$restarted = array_filter( $this->ctx->checkpoints, static function ( array $c ): bool {
			return isset( $c['cursor']['file']['restarts'] ) && $c['cursor']['file']['restarts'] > 0;
		} );
		$this->assertNotEmpty( $restarted, 'the restart count is in the cursor: a restart is progress for the runner' );
	}

	public function test_a_file_that_shrinks_mid_chunk_is_started_over_from_the_packer_report(): void {
		$p   = $this->file( 'shrink.bin', 3 * self::CHUNK, 6 );
		$this->index( array( $p ) );
		$abs = $this->site . '/shrink.bin';
		$cut = false;
		// The seam runs after a chunk; cutting the file here without touching mtime or size checks would be
		// caught by the stat before the next chunk, so cut it and keep mtime, size differs anyway: the stat
		// path. To reach the packer's own short read, cut it *and* restore the recorded size/mtime/ino... not
		// possible for size; so this test covers the stat path and the next one the packer path.
		$step = $this->step( function ( string $path, int $chunk ) use ( $abs, &$cut ): void {
			if ( ! $cut && 1 === $chunk ) {
				$h = fopen( $abs, 'r+b' );
				ftruncate( $h, self::CHUNK + 5 );
				fclose( $h );
				$cut = true;
			}
		} );
		list( $result ) = $this->drive( $step );
		$this->assertSame( StepResult::DONE, $result->kind );
		$packed = $this->packed();
		$this->assertSame( self::CHUNK + 5, $packed[0]['b'] );
		$this->assertSame( ChunkHasher::hash_chunks( $abs, self::CHUNK ), $packed[0]['hc'] );
	}

	public function test_after_three_restarts_a_shrinking_file_is_left_out_and_a_changing_one_is_finished_with_a_warning(): void {
		$shrink = $this->file( 'shrink.bin', 2 * self::CHUNK, 7 );
		$grow   = $this->file( 'grow.bin', 2 * self::CHUNK, 8 );
		$this->index( array( $shrink, $grow ) );
		$sizes = array( 'shrink.bin' => 2 * self::CHUNK, 'grow.bin' => 2 * self::CHUNK );
		$step  = $this->step( function ( string $path, int $chunk ) use ( &$sizes ): void {
			$name = basename( $path );
			if ( 0 !== $chunk ) {
				return;
			}
			$abs = $this->site . '/' . $name;
			if ( 'shrink.bin' === $name ) {
				$sizes[ $name ] -= 10;
			} else {
				$sizes[ $name ] += 10;
			}
			$h = fopen( $abs, 'r+b' );
			ftruncate( $h, $sizes[ $name ] );
			fclose( $h );
			touch( $abs, time() + 100 + $sizes[ $name ] );
		} );
		list( $result ) = $this->drive( $step );
		$this->assertSame( StepResult::DONE, $result->kind );
		$packed  = $this->packed();
		$summary = $this->summary();
		$this->assertSame( array( 'wp-content/uploads/grow.bin' ), array_column( $packed, 'p' ), 'the shrinking file is left out' );
		$this->assertSame( array( 'count' => 1, 'listed' => array( 'wp-content/uploads/shrink.bin' ) ), $summary['unstable'] );
		$this->assertSame( array( 'count' => 0, 'listed' => array() ), $summary['skipped'], 'a file that kept changing is its own kind, not "missing"' );
		$this->assertSame( array( 'count' => 1, 'listed' => array( 'wp-content/uploads/grow.bin' ) ), $summary['changed'] );
		$this->assertContains( '1 files changed repeatedly while they were being packed and are not in the backup: wp-content/uploads/shrink.bin', $summary['warnings'] );
		$this->assertContains( '1 files were modified while they were being packed; their content in the backup may be inconsistent: wp-content/uploads/grow.bin', $summary['warnings'] );
		$this->assertStringContainsString( 'shrank; left out', $this->ctx->log() );
		$this->assertSame( 2 * self::CHUNK + 40, $packed[0]['b'], 'finished as declared after the fourth stat' );
	}

	public function test_a_chunk_slower_than_the_whole_budget_fails_with_the_reason(): void {
		$this->index( array( $this->file( 'slow.bin', 2 * self::CHUNK, 9 ) ) );
		$this->ctx->tick = 25.0; // A unit's two clock readings put its cost at 25 seconds, more than the 20-second budget.
		$cursor          = array();
		try {
			// The phase switch and the file's opening cost nothing and end their ticks on the budget; the first
			// chunk is the first unit that moves bytes, and it is measured.
			for ( $i = 0; $i < 5; $i++ ) {
				$result = $this->step()->run( $this->ctx->context( $cursor, 20 ) );
				$cursor = $result->cursor;
			}
			$this->fail( 'the slow chunk must fail the step' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Disk throughput is too low', $e->getMessage() );
			$this->assertStringContainsString( 'more than the 20-second time budget', $e->getMessage() );
		}
	}

	public function test_database_chunks_are_verified_against_their_index_lines(): void {
		$chunk = $this->ctx->work() . '/database';
		mkdir( $chunk );
		file_put_contents( $chunk . '/wp_posts.0001.sql', "-- wpcheckpoint table=wp_posts chunk=1 pk_from=null\nINSERT ...;\n-- wpcheckpoint end table=wp_posts chunk=1 rows=1 pk_to=[\"1\"]\n" );
		$sql  = (string) file_get_contents( $chunk . '/wp_posts.0001.sql' );
		$line = array( 't' => 'wp_posts', 'c' => 1, 'p' => 'database/wp_posts.0001.sql', 'b' => strlen( $sql ), 'h' => hash( 'sha256', $sql ) );
		file_put_contents( $this->ctx->work() . '/database.index.jsonl', json_encode( $line, JSON_UNESCAPED_SLASHES ) . "\n" );
		$this->index( array( $this->file( 'a.txt', 10 ) ) );
		list( $result ) = $this->drive( $this->step( null, array( 'volume_bytes' => 100 ) ) );
		$this->assertSame( StepResult::DONE, $result->kind );
		$this->assertSame( 1, $this->summary()['entries'] );
		$this->assertSame( array( 'database/wp_posts.0001.sql', 'files/wp-content/uploads/a.txt' ), $this->entry_names(), 'database chunks first, in index order; the tiny volume sealed after the chunk' );
		$this->assertCount( 1, glob( $this->ctx->work() . '/' . PackStep::VOLUMES . '/*.wpcheckpoint.zip' ) ?: array() );

		// A chunk file that no longer hashes to its line fails the step.
		$this->ctx->remove();
		$this->set_up();
		$chunk = $this->ctx->work() . '/database';
		mkdir( $chunk );
		file_put_contents( $chunk . '/wp_posts.0001.sql', str_pad( 'changed', strlen( $sql ), '.' ) );
		file_put_contents( $this->ctx->work() . '/database.index.jsonl', json_encode( $line, JSON_UNESCAPED_SLASHES ) . "\n" );
		$this->index( array() );
		try {
			$this->step()->run( $this->ctx->context() );
			$this->fail();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'does not hash to what its index line records', $e->getMessage() );
		}
	}

	public function test_a_deflate_cap_above_the_chunk_size_is_held_to_it_so_every_entry_has_one_hash_per_chunk(): void {
		// Compressible content two and a half chunks long, with a deflate cap of four chunks: without the
		// clamp the packer would deflate it in one piece and the index line would carry one hash for three chunks.
		if ( ! is_dir( $this->site . '/2024' ) ) {
			mkdir( $this->site . '/2024', 0700, true );
		}
		file_put_contents( $this->site . '/2024/text.log', str_repeat( "line of text\n", (int) ( 2.5 * self::CHUNK / 13 ) ) );
		$this->index( array( 'wp-content/uploads/2024/text.log' ) );
		list( $result ) = $this->drive( $this->step( null, array( 'deflate_max_bytes' => 4 * self::CHUNK ) ) );
		$this->assertSame( StepResult::DONE, $result->kind );
		$line = json_decode( trim( (string) file_get_contents( $this->ctx->work() . '/' . PackStep::PACKED_INDEX ) ), true );
		$this->assertCount( 3, $line['hc'] );
		$this->assertSame( ChunkHasher::list_hash( $line['hc'] ), $line['h'] );
		$this->assertSame( self::CHUNK, PackStep::packer_options_for( array( 'deflate_max_bytes' => 4 * self::CHUNK ), self::CHUNK )['deflate_max_bytes'] );
		$this->assertSame( 4096, PackStep::packer_options_for( array( 'deflate_max_bytes' => 4096 ), self::CHUNK )['deflate_max_bytes'], 'a lower cap stays' );
		$this->assertSame( Packer::DEFLATE_MAX_BYTES, PackStep::packer_options_for( array(), Manifest::DEFAULT_CHUNK )['deflate_max_bytes'], 'the default cap is below the default chunk and stays' );
	}

	public function test_the_chunk_size_must_be_whole_pieces_or_smaller_than_one(): void {
		$this->assertInstanceOf( PackStep::class, new PackStep( $this->roots(), $this->options(), 65536 ), 'smaller than a piece: read as one piece' );
		$this->assertInstanceOf( PackStep::class, new PackStep( $this->roots(), $this->options(), 2 * Packer::PIECE_BYTES ) );
		$this->expectException( \InvalidArgumentException::class );
		new PackStep( $this->roots(), $this->options(), Packer::PIECE_BYTES + 1048576 );
	}

	public function test_a_file_deleted_between_two_ticks_while_its_entry_is_open_is_skipped_and_the_job_goes_on(): void {
		$gone = $this->file( 'gone.bin', 3 * self::CHUNK, 11 );
		$keep = $this->file( 'keep.bin', 1000, 12 );
		$this->index( array( $gone, $keep ) );
		$this->ctx->tick = 5.0; // One unit per tick: the entry stays open across ticks.
		$cursor          = array();
		$deleted         = false;
		for ( $i = 0; $i < 60; $i++ ) {
			$result = $this->step()->run( $this->ctx->context( $cursor ) );
			if ( StepResult::DONE === $result->kind ) {
				break;
			}
			$cursor = $result->cursor;
			if ( ! $deleted && isset( $cursor['file']['p'] ) && 'wp-content/uploads/gone.bin' === $cursor['file']['p'] && $cursor['file']['chunk'] >= 1 ) {
				unlink( $this->site . '/gone.bin' ); // Between the ticks: the next chunk finds it gone.
				$deleted = true;
			}
		}
		$this->assertTrue( $deleted );
		$this->assertSame( StepResult::DONE, $result->kind );
		$this->assertSame( array( 'wp-content/uploads/keep.bin' ), array_column( $this->packed(), 'p' ) );
		$this->assertSame( array( 'count' => 1, 'listed' => array( 'wp-content/uploads/gone.bin' ) ), $this->summary()['skipped'] );
		$this->assertStringContainsString( 'vanished', $this->ctx->log() );
	}

	public function test_a_directory_replaced_by_a_link_to_outside_the_root_after_the_scan_is_left_out(): void {
		$inside  = $this->file( '2024/in.txt', 500, 13 );
		$linked  = $this->file( 'media/a.txt', 500, 14 );
		$linked2 = $this->file( 'media/b.txt', 500, 15 );
		$this->index( array( $inside, $linked, $linked2 ) );
		// After the scan, "media" becomes a link to a directory outside the uploads root holding files of the same names.
		$outside = $this->ctx->root . '/elsewhere';
		mkdir( $outside, 0700 );
		file_put_contents( $outside . '/a.txt', 'secret a' );
		file_put_contents( $outside . '/b.txt', 'secret b' );
		unlink( $this->site . '/media/a.txt' );
		unlink( $this->site . '/media/b.txt' );
		rmdir( $this->site . '/media' );
		if ( ! @symlink( $outside, $this->site . '/media' ) ) {
			$this->markTestSkipped( 'Symbolic links cannot be created in this environment.' );
		}
		list( $result ) = $this->drive( $this->step() );
		$this->assertSame( StepResult::DONE, $result->kind );
		$this->assertSame( array( 'wp-content/uploads/2024/in.txt' ), array_column( $this->packed(), 'p' ) );
		$summary = $this->summary();
		$this->assertSame( array( 'count' => 2, 'listed' => array( 'wp-content/uploads/media/a.txt', 'wp-content/uploads/media/b.txt' ) ), $summary['outside'] );
		$this->assertContains( '2 files resolve outside their content directory (through a link) and are not in the backup: wp-content/uploads/media/a.txt, wp-content/uploads/media/b.txt', $summary['warnings'] );
		$this->assertStringContainsString( 'resolves outside its content directory', $this->ctx->log() );
		$this->assertStringNotContainsString( 'secret', implode( '', array_map( 'file_get_contents', glob( $this->ctx->work() . '/volumes/*' ) ?: array() ) ) );
	}

	public function test_listed_paths_stop_at_the_cap_while_the_count_and_the_warning_go_on(): void {
		$paths = array();
		for ( $i = 0; $i < PackStep::MAX_LISTED + 10; $i++ ) {
			$paths[] = sprintf( 'wp-content/uploads/missing-%03d.bin', $i );
		}
		$lines = array();
		foreach ( $paths as $p ) {
			$lines[] = json_encode( array( 'p' => $p, 'b' => 10, 'm' => 1 ), JSON_UNESCAPED_SLASHES );
		}
		file_put_contents( $this->ctx->work() . '/files.index.jsonl', implode( "\n", $lines ) . "\n" );
		list( $result ) = $this->drive( $this->step() );
		$this->assertSame( StepResult::DONE, $result->kind );
		$summary = $this->summary();
		$this->assertSame( PackStep::MAX_LISTED + 10, $summary['skipped']['count'] );
		$this->assertCount( PackStep::MAX_LISTED, $summary['skipped']['listed'], 'the cap is reached with the maximal input' );
		$this->assertSame( array_slice( $paths, 0, PackStep::MAX_LISTED ), $summary['skipped']['listed'] );
		$this->assertCount( 1, $summary['warnings'] );
		$this->assertStringEndsWith( ' and 10 more', $summary['warnings'][0] );
		$this->assertStringContainsString( sprintf( '%d files listed by the scan were missing', PackStep::MAX_LISTED + 10 ), $summary['warnings'][0] );
	}

	public function test_more_changing_files_than_the_manifest_can_list_still_give_one_warning(): void {
		// Over Manifest::MAX_WARNINGS files that keep changing: without aggregation the manifest would be refused
		// at the very end. Tiny chunks keep the fixture small; every file is finished "as it is" after MAX_RESTARTS.
		$count = Manifest::MAX_WARNINGS + 1;
		$paths = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$paths[] = $this->file( sprintf( 'c/f%04d.bin', $i ), 2 * 1024, $i + 1 );
		}
		$this->index( $paths );
		$grown = array();
		$step  = new PackStep( $this->roots(), $this->options(), 1024, function ( string $p, int $chunk ) use ( &$grown ): void {
			if ( 0 !== $chunk || ( $grown[ $p ] ?? 0 ) > PackStep::MAX_RESTARTS ) {
				return;
			}
			$grown[ $p ] = ( $grown[ $p ] ?? 0 ) + 1;
			$abs         = $this->site . substr( $p, strlen( 'wp-content/uploads' ) );
			file_put_contents( $abs, 'x', FILE_APPEND );
			touch( $abs, time() + 100 * $grown[ $p ] );
		} );
		list( $result ) = $this->drive( $step, array(), 20, 20000 );
		$this->assertSame( StepResult::DONE, $result->kind );
		$summary = $this->summary();
		$this->assertSame( $count, $summary['changed']['count'] );
		$this->assertCount( PackStep::MAX_LISTED, $summary['changed']['listed'] );
		$this->assertCount( 1, $summary['warnings'], 'one warning however many files changed' );
		$this->assertLessThanOrEqual( Manifest::MAX_WARNINGS, count( $summary['warnings'] ) );
		$this->assertCount( $count, array_filter( explode( "\n", (string) file_get_contents( $this->ctx->work() . '/' . PackStep::PACKED_INDEX ) ) ), 'every file was packed' );
	}

	/**
	 * 32-bit PHP: a volume stops at the platform bound (max_volume_bytes, injected here). A file that keeps
	 * growing next to another one no longer fits after its first restart and goes back to its index line; the
	 * volume is sealed and the file begun again in the next one. Its restart count travels with it, so
	 * MAX_RESTARTS still ends the loop: without that, every return to the line would start the count over and
	 * a file written to all the time could be restarted for ever.
	 */
	public function test_a_file_sent_back_to_its_line_by_the_volume_bound_keeps_its_restart_count(): void {
		$first = $this->file( 'first.bin', 100000, 21 );
		$live  = $this->file( 'live.bin', 3 * self::CHUNK, 22 );
		$this->index( array( $first, $live ) );
		$abs    = $this->site . '/live.bin';
		$grown  = 0;
		$bound  = 365000; // first.bin and its header, live.bin as scanned and the central directory allowance fit; 4 KB more do not.
		$step   = $this->step(
			function ( string $p, int $chunk ) use ( $abs, &$grown ): void {
				if ( 'wp-content/uploads/live.bin' !== $p || 0 !== $chunk ) {
					return;
				}
				// Written to all the time: every attempt finds it grown.
				file_put_contents( $abs, str_repeat( 'g', 4000 ), FILE_APPEND );
				touch( $abs, time() + 100 + ( ++$grown ) );
				clearstatcache( true, $abs );
			},
			array( 'max_volume_bytes' => $bound, 'volume_bytes' => 10 * 1048576 )
		);
		list( $result ) = $this->drive( $step, array(), 20, 400 );
		$this->assertSame( StepResult::DONE, $result->kind );
		$log = $this->ctx->log();
		$this->assertSame( PackStep::MAX_RESTARTS, substr_count( $log, 'starting it over' ), 'at most MAX_RESTARTS restarts for the file, across the return to its line' );
		$this->assertSame( 1, substr_count( $log, 'packed as it is now' ), 'then it is finished as it is' );
		$deferred = array_filter(
			$this->ctx->checkpoints,
			static function ( array $c ): bool {
				return isset( $c['cursor']['pending']['restarts'] ) && 1 === $c['cursor']['pending']['restarts'];
			}
		);
		$this->assertNotEmpty( $deferred, 'the file went back to its line carrying its first restart' );
		$packed = $this->packed();
		$this->assertSame( array( 'wp-content/uploads/first.bin', 'wp-content/uploads/live.bin' ), array_column( $packed, 'p' ), 'each file once' );
		$summary = $this->summary();
		$this->assertSame( array( 'count' => 1, 'listed' => array( 'wp-content/uploads/live.bin' ) ), $summary['changed'], 'counted once, when its entry was complete' );
		$this->assertSame( 0, $summary['unstable']['count'] );
		$this->assertCount( 1, glob( $this->ctx->work() . '/' . PackStep::VOLUMES . '/*.wpcheckpoint.zip' ) ?: array(), 'the first volume was sealed at the bound' );
		$this->assertCount( 1, glob( $this->ctx->work() . '/' . PackStep::VOLUMES . '/*.partial' ) ?: array(), 'the file went on in the next one, left open for the manifest step' );
		// The entry holds the file as it was when it was finished as declared: its first b bytes.
		$copy = $this->ctx->root . '/live.prefix';
		$in   = fopen( $abs, 'rb' );
		$out  = fopen( $copy, 'wb' );
		stream_copy_to_stream( $in, $out, $packed[1]['b'] );
		fclose( $in );
		fclose( $out );
		$this->assertSame( ChunkHasher::hash_chunks( $copy, self::CHUNK ), $packed[1]['hc'] );
	}
}
