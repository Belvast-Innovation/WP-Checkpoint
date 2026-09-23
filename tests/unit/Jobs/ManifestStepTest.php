<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Archive\ArchiveVerifier;
use WPCheckpoint\Archive\ChunkHasher;
use WPCheckpoint\Archive\IndexLine;
use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Archive\VerificationResult;
use WPCheckpoint\Archive\ZipReader;
use WPCheckpoint\Database\TableExporter;
use WPCheckpoint\Jobs\ExportPlan;
use WPCheckpoint\Jobs\ManifestStep;
use WPCheckpoint\Jobs\PackStep;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Tests\Fixtures\Database\FakeConnection;
use WPCheckpoint\Tests\Fixtures\Jobs\WorkContext;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The manifest step over a work directory the pack step filled: the
 * audits, the sealed archive, the standalone manifest, the self-check,
 * and what a self-check failure says.
 */
final class ManifestStepTest extends TestCase {

	const CHUNK = 1048576; // The reader's minimum; files below span several chunks.
	const BASE  = 'example-site-20260922-100000-ab12';

	/** @var WorkContext */
	private $ctx;

	/** @var string */
	private $site;

	/** @var array<string, mixed> */
	private $site_facts;

	protected function set_up(): void {
		$this->ctx  = new WorkContext( 'wpcheckpoint-manifest-' );
		$this->site = $this->ctx->root . '/site/wp-content/uploads';
		mkdir( $this->site . '/2026', 0700, true );
		$base             = json_decode( (string) file_get_contents( __DIR__ . '/../../Fixtures/Manifest/valid/base.json' ), true );
		$this->site_facts = $base['site'];
		ExportPlan::write( $this->ctx->work(), ExportPlan::PLAN, array( 'base' => self::BASE, 'tables' => array( 'wp_posts' ), 'notes' => array(), 'groups' => array( 'uploads' ), 'exclusions' => array( 'wp-content/cache' ) ) );
		ExportPlan::write( $this->ctx->work(), ExportPlan::PREFLIGHT, array( 'checks' => array(), 'findings' => array( 'oversize' => array() ), 'warnings' => array( 'The zlib extension is not available; files are stored without compression.' ) ) );
		ExportPlan::write( $this->ctx->work(), ExportPlan::REVIEW, array( 'findings' => array(), 'decisions' => array( 'exclude_tables' => array(), 'exclude_oversize' => array(), 'exclude_paths' => array( 'wp-content/uploads/[2024]/node_modules' ), 'notes' => array( 'Directory wp-content/uploads/[2024]/node_modules (60 MB) was left out of the backup, as chosen.' ) ) ) );
		// A real table export over the in-memory connection: chunk files, index, summary.
		$db   = new FakeConnection();
		$rows = array();
		for ( $i = 1; $i <= 300; $i++ ) {
			$rows[] = array( (string) $i, 'title ' . $i, str_repeat( 'x', 400 ) );
		}
		$db->add_table( 'wp_posts', array( array( 'ID', 'bigint(20)' ), array( 'post_title', 'text' ), array( 'post_content', 'longtext' ) ), array( 'ID' ), $rows );
		mkdir( $this->ctx->work() . '/database' );
		$exporter = new TableExporter( $db, $this->ctx->work() . '/database', self::CHUNK, 8192 );
		$state    = TableExporter::initial_state( 'wp_posts' );
		$lines    = array();
		$hashes   = array();
		while ( empty( $state['done'] ) ) {
			$state = $exporter->step( $state );
			if ( null !== $state['closed'] ) {
				$lines[]  = json_encode( array( 't' => 'wp_posts', 'c' => $state['closed']['chunk'], 'p' => IndexLine::database_path( 'wp_posts', $state['closed']['chunk'] ), 'b' => $state['closed']['bytes'], 'h' => $state['closed']['hash'] ), JSON_UNESCAPED_SLASHES );
				$hashes[] = $state['closed']['hash'];
			}
		}
		file_put_contents( $this->ctx->work() . '/database.index.jsonl', implode( "\n", $lines ) . "\n" );
		ExportPlan::write( $this->ctx->work(), 'database.summary.json', array(
			'exported' => array( 'started_at' => '2026-09-22T10:00:00Z', 'finished_at' => '2026-09-22T10:00:05Z' ),
			'tables'   => array( array( 'name' => 'wp_posts', 'rows' => 300, 'bytes' => $state['total'], 'chunks' => $state['chunks'], 'sha256' => ChunkHasher::list_hash( $hashes ) ) ),
			'warnings' => array(),
			'snapshot' => false,
		) );
		// Files, scanned then packed.
		file_put_contents( $this->site . '/2026/a.jpg', str_repeat( 'A', 3000 ) );
		file_put_contents( $this->site . '/2026/big.bin', str_repeat( 'B', 2 * self::CHUNK + 100 ) );
		$index = array();
		foreach ( array( '2026/a.jpg', '2026/big.bin' ) as $rel ) {
			$index[] = json_encode( array( 'p' => 'wp-content/uploads/' . $rel, 'b' => filesize( $this->site . '/' . $rel ), 'm' => filemtime( $this->site . '/' . $rel ) ), JSON_UNESCAPED_SLASHES );
		}
		file_put_contents( $this->ctx->work() . '/files.index.jsonl', implode( "\n", $index ) . "\n" );
		file_put_contents( $this->ctx->work() . '/scan.summary.json', json_encode( array( 'counts' => array(), 'lists' => array(), 'warnings' => array( 'A scan warning.' ) ) ) );
		$pack   = new PackStep( array( array( 'group' => 'uploads', 'path' => $this->site, 'prefix' => 'wp-content/uploads', 'skip' => array() ) ), $this->packer_options(), self::CHUNK );
		$cursor = array();
		do {
			$result = $pack->run( $this->ctx->context( $cursor ) );
			$cursor = StepResult::PROGRESS === $result->kind ? $result->cursor : $cursor;
		} while ( StepResult::DONE !== $result->kind );
		$this->ctx->checkpoints = array();
	}

	protected function tear_down(): void {
		$this->ctx->remove();
	}

	private function packer_options(): array {
		return array( 'volume_bytes' => 1572864, 'volume_chunk_bytes' => self::CHUNK, 'deflate_max_bytes' => 4096, 'disk_free' => static function (): int {
			return PHP_INT_MAX;
		} );
	}

	private function step(): ManifestStep {
		return new ManifestStep( $this->site_facts, array( 'name' => 'wp-checkpoint', 'version' => '0.1.0-test' ), $this->packer_options(), self::CHUNK );
	}

	private function drive( ManifestStep $step, array $cursor = array() ): array {
		$ticks = 0;
		do {
			$result = $step->run( $this->ctx->context( $cursor ) );
			$cursor = StepResult::PROGRESS === $result->kind ? $result->cursor : $cursor;
			$this->assertLessThan( 100, ++$ticks );
		} while ( StepResult::DONE !== $result->kind );
		return $cursor;
	}

	private function volumes(): string {
		return $this->ctx->work() . '/' . PackStep::VOLUMES;
	}

	public function test_the_archive_is_sealed_described_and_passes_its_own_full_verification(): void {
		$this->drive( $this->step() );
		$manifest_path = $this->volumes() . '/' . self::BASE . '.manifest.json';
		$this->assertFileExists( $manifest_path );
		$manifest = Manifest::from_json( (string) file_get_contents( $manifest_path ) );
		$this->assertFalse( $manifest->embedded() );
		$this->assertGreaterThanOrEqual( 2, count( $manifest->volumes() ), 'small volumes: the database chunks and files spread over several' );
		$this->assertSame( 2, $manifest->files_summary()['count'] );
		$this->assertSame( 3000 + 2 * self::CHUNK + 100, $manifest->files_summary()['bytes'] );
		$this->assertSame( 'wp_posts', $manifest->tables()[0]['name'] );
		$this->assertSame( array( 'started_at' => '2026-09-22T10:00:00Z', 'finished_at' => '2026-09-22T10:00:05Z' ), $manifest->database_exported() );
		$warnings = $manifest->warnings();
		$this->assertContains( 'The zlib extension is not available; files are stored without compression.', $warnings );
		$this->assertContains( 'A scan warning.', $warnings );
		$this->assertContains( 'Directory wp-content/uploads/[2024]/node_modules (60 MB) was left out of the backup, as chosen.', $warnings );
		$this->assertSame( array( 'wp-content/cache', 'wp-content/uploads/[2024]/node_modules' ), $manifest->to_array()['exclusions'] );
		$this->assertStringNotContainsString( '.partial', implode( ',', glob( $this->volumes() . '/*' ) ?: array() ), 'no volume left open' );
		// The reader at full depth agrees with the writer.
		$dir = $this->ctx->root . '/verify-full';
		mkdir( $dir );
		$result = ArchiveVerifier::open( $manifest_path, $dir, ArchiveVerifier::DEPTH_FULL )->run();
		$this->assertSame( VerificationResult::PASSED, $result->outcome(), $result->to_text( static function ( string $t ): string {
			return $t;
		} ) );
		$this->assertStringContainsString( 'Self-check passed', $this->ctx->log() );
	}

	public function test_a_backup_that_was_to_contain_the_database_is_not_finished_without_a_table(): void {
		// The listing came back empty (or everything was filtered out): the manifest would say "no database" and
		// look complete. The job asked for the database (the default), so the step stops before anything is sealed.
		$summary           = ExportPlan::read( $this->ctx->work(), 'database.summary.json' );
		$summary['tables'] = array();
		ExportPlan::write( $this->ctx->work(), 'database.summary.json', $summary );
		$this->ctx->options = array();
		try {
			$this->step()->run( $this->ctx->context() );
			$this->fail( 'the step must stop' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'The backup was to contain the database, but no table was exported. It is stopped rather than finished without the database. If the database is meant to be left out, back up the files only (wp wpcheckpoint export --files-only).', $e->getMessage() );
		}
		$this->assertSame( array(), $this->ctx->checkpoints, 'not even the audit started' );
	}

	public function test_a_line_without_a_hash_stops_the_step_before_anything_is_sealed(): void {
		file_put_contents( $this->ctx->work() . '/' . PackStep::PACKED_INDEX, json_encode( array( 'p' => 'wp-content/uploads/late.txt', 'b' => 1, 'm' => 1 ), JSON_UNESCAPED_SLASHES ) . "\n", FILE_APPEND );
		try {
			$this->step()->run( $this->ctx->context() );
			$this->fail( 'must refuse' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'line 3 has no content hash', $e->getMessage() );
		}
		$this->assertFileDoesNotExist( $this->volumes() . '/' . self::BASE . '.manifest.json' );
		$this->assertNotEmpty( glob( $this->volumes() . '/*.partial' ), 'the open volume was not sealed' );
	}

	public function test_a_self_check_failure_names_the_defect_and_never_a_volume_file_or_the_site(): void {
		// Run up to the verification phase, then damage a volume the way a writer defect would show.
		$this->ctx->tick = 5.0;
		$cursor          = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$result = $this->step()->run( $this->ctx->context( $cursor ) );
			$cursor = StepResult::PROGRESS === $result->kind ? $result->cursor : $cursor;
			if ( isset( $cursor['phase'] ) && 'verify' === $cursor['phase'] ) {
				break;
			}
		}
		$this->assertSame( 'verify', $cursor['phase'] );
		$volumes = glob( $this->volumes() . '/*.wpcheckpoint.zip' );
		$h       = fopen( $volumes[0], 'r+b' );
		ftruncate( $h, filesize( $volumes[0] ) - 100 );
		fclose( $h );
		$cursor['verifier'] = array();
		$this->ctx->tick    = 0.0;
		try {
			$this->step()->run( $this->ctx->context( $cursor ) );
			$this->fail( 'must refuse' );
		} catch ( \RuntimeException $e ) {
			$message = $e->getMessage();
			$this->assertStringStartsWith( ManifestStep::SELF_CHECK_PREFIX, $message );
			$this->assertStringContainsString( 'defect in the backup writer, not in your data', $message );
			$this->assertMatchesRegularExpression( '/volume 1\b|volumes/', $message, 'the verifier names the phase and the volume by number' );
			$this->assertStringNotContainsString( 'example-site', $message, 'no site slug' );
			$this->assertStringNotContainsString( '.wpcheckpoint.zip', $message, 'no volume file name' );
			$this->assertStringNotContainsString( $this->ctx->root, $message, 'no path' );
		}
		$this->assertStringContainsString( 'Self-check failed', $this->ctx->log() );
	}

	/**
	 * Every phase boundary with a disk change before its checkpoint, replayed from the cursor that
	 * checkpoint would have overwritten: the archive comes out byte for byte the same each time.
	 */
	public function test_a_crash_before_any_checkpoint_after_a_disk_change_is_replayed_to_the_same_archive(): void {
		$reference = $this->replay_every_phase( $this->packer_options() );
		$this->assertGreaterThanOrEqual( 2, count( $reference ), 'the fixture spans volumes' );
	}

	public function test_a_site_that_fits_one_volume_gets_the_single_name_and_replays_the_same_way(): void {
		$reference = $this->replay_every_phase( array_merge( $this->packer_options(), array( 'volume_bytes' => 1073741824 ) ) );
		$this->assertSame( array( self::BASE . '.wpcheckpoint.zip' ), array_keys( $reference ), 'one volume, under the single name' );
		$manifest = Manifest::from_json( (string) file_get_contents( $this->volumes() . '/' . self::BASE . '.manifest.json' ) );
		$this->assertCount( 1, $manifest->volumes() );
		$reader = ZipReader::open( $this->volumes() . '/' . self::BASE . '.wpcheckpoint.zip' );
		$copy   = Manifest::from_json( $reader->read( $reader->find( 'manifest.json' ) ) );
		$this->assertTrue( $copy->embedded() );
		$this->assertSame( array(), $copy->volumes(), 'the copy lists every volume but the one holding it: none' );
		$this->assertStringContainsString( 'Self-check passed', $this->ctx->log() );
	}

	/**
	 * Drive a step with the given packer options in small ticks, then replay from the first checkpoint of
	 * every writing phase and assert the sealed volumes come out byte for byte the same each time.
	 *
	 * @return array<string, string> The reference volume hashes by name.
	 */
	private function replay_every_phase( array $packer_options ): array {
		$make            = function () use ( $packer_options ): ManifestStep {
			return new ManifestStep( $this->site_facts, array( 'name' => 'wp-checkpoint', 'version' => '0.1.0-test' ), $packer_options, self::CHUNK );
		};
		$this->ctx->tick = 5.0;
		$cursor          = array();
		$by_phase        = array();
		for ( $i = 0; $i < 200; $i++ ) {
			$result = $make()->run( $this->ctx->context( $cursor ) );
			$cursor = StepResult::PROGRESS === $result->kind ? $result->cursor : $cursor;
			foreach ( $this->ctx->checkpoints as $checkpoint ) {
				$phase = $checkpoint['cursor']['phase'];
				if ( ! isset( $by_phase[ $phase ] ) ) {
					$by_phase[ $phase ] = $checkpoint['cursor']; // The first checkpoint of each phase: the state a crash in it replays from.
				}
			}
			$this->ctx->checkpoints = array();
			if ( StepResult::DONE === $result->kind ) {
				break;
			}
		}
		$this->assertSame( StepResult::DONE, $result->kind );
		$this->assertSame( array( 'audit', 'hash', 'prepare', 'blocks_before', 'indexes', 'finish', 'blocks_after', 'standalone', 'verify' ), array_keys( $by_phase ), 'every phase was checkpointed at least once' );
		$reference = $this->volume_hashes();
		$manifest  = hash_file( 'sha256', $this->volumes() . '/' . self::BASE . '.manifest.json' );
		$this->ctx->tick = 0.0;
		foreach ( array( 'prepare', 'blocks_before', 'indexes', 'finish', 'blocks_after', 'standalone' ) as $phase ) {
			$this->drive( $make(), $by_phase[ $phase ] );
			$this->assertSame( $reference, $this->volume_hashes(), "replayed from the first checkpoint of phase {$phase}: the sealed volumes are byte for byte the same" );
			$this->assertSame( $manifest, hash_file( 'sha256', $this->volumes() . '/' . self::BASE . '.manifest.json' ), "replayed from {$phase}: the standalone manifest is the same too (its clock is fixed before anything is written)" );
		}
		$this->assertStringNotContainsString( 'Self-check failed', $this->ctx->log() );
		return $reference;
	}

	public function test_the_summaries_get_a_volume_of_their_own_when_the_open_one_is_full_and_the_copy_lists_every_other_volume(): void {
		// Volumes so small that the data volume cannot take the indexes: prepare seals it, finish opens another.
		$step = new ManifestStep( $this->site_facts, array( 'name' => 'wp-checkpoint', 'version' => '0.1.0-test' ), array_merge( $this->packer_options(), array( 'volume_bytes' => 40000 ) ), self::CHUNK );
		$this->drive( $step );
		$manifest = Manifest::from_json( (string) file_get_contents( $this->volumes() . '/' . self::BASE . '.manifest.json' ) );
		$last     = $manifest->volumes()[ count( $manifest->volumes() ) - 1 ];
		$reader   = ZipReader::open( $this->volumes() . '/' . $last['path'] );
		$this->assertSame( array( Manifest::DATABASE_INDEX, Manifest::FILES_INDEX, 'manifest.json' ), array_column( $reader->entries(), 'name' ), 'the last volume holds the summaries only' );
		$entry = $reader->find( 'manifest.json' );
		$copy  = Manifest::from_json( $reader->read( $entry ) );
		$this->assertTrue( $copy->embedded() );
		$this->assertSame( array_slice( $manifest->volumes(), 0, -1 ), $copy->volumes(), 'the embedded copy lists every volume but the one holding it' );
		$this->assertStringContainsString( 'Self-check passed', $this->ctx->log() );
	}

	public function test_a_local_header_overwritten_before_the_self_check_fails_it_with_the_fields_named(): void {
		// Run to the self-check, then overwrite a local header the way a run that outlived its lease would.
		$this->ctx->tick = 5.0;
		$cursor          = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$result = $this->step()->run( $this->ctx->context( $cursor ) );
			$cursor = StepResult::PROGRESS === $result->kind ? $result->cursor : $cursor;
			if ( isset( $cursor['phase'] ) && 'verify' === $cursor['phase'] ) {
				break;
			}
		}
		$this->assertSame( 'verify', $cursor['phase'] );
		$volumes = glob( $this->volumes() . '/*.wpcheckpoint.zip' ) ?: array();
		sort( $volumes );
		$entry = ZipReader::open( $volumes[0] )->entries()[0];
		$h     = fopen( $volumes[0], 'r+b' );
		fseek( $h, (int) $entry['offset'] + 14 );
		fwrite( $h, str_repeat( chr( 0 ), 12 ) );
		fclose( $h );
		$cursor['verifier'] = array();
		$this->ctx->tick    = 0.0;
		try {
			$this->drive( $this->step(), $cursor );
			$this->fail( 'the self-check must refuse the archive' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringStartsWith( ManifestStep::SELF_CHECK_PREFIX, $e->getMessage() );
			$this->assertStringContainsString( 'The local header of the entry disagrees with the central directory (crc, csize, usize)', $e->getMessage() );
			$this->assertStringContainsString( VerificationResult::INCONSISTENT_ADVICE, $e->getMessage(), 'after the writer-defect prefix' );
			$this->assertStringNotContainsString( 'Use the original volume files', $e->getMessage() );
			$this->assertStringNotContainsString( '.wpcheckpoint.zip', $e->getMessage() );
		}
		foreach ( array_column( $this->ctx->checkpoints, 'cursor' ) as $stored ) {
			$this->assertArrayNotHasKey( 'walk', (array) ( $stored['verifier'] ?? array() ), 'no timing in the cursor' );
		}
		$messages = array_column( $this->ctx->checkpoints, 'message' );
		$this->assertNotEmpty( preg_grep( '/^Checking the written archive: \d+ of \d+ entries$/', $messages ), 'the walk reports its progress' );
	}

	public function test_summaries_larger_than_a_volume_are_written_together_and_pass_the_self_check(): void {
		// Volumes of 100 bytes against indexes of several hundred: after the data volume is sealed, the first
		// index alone passes the volume size, and the second index and the manifest still go into that volume.
		$step = new ManifestStep( $this->site_facts, array( 'name' => 'wp-checkpoint', 'version' => '0.1.0-test' ), array_merge( $this->packer_options(), array( 'volume_bytes' => 100 ) ), self::CHUNK );
		$this->drive( $step );
		$manifest = Manifest::from_json( (string) file_get_contents( $this->volumes() . '/' . self::BASE . '.manifest.json' ) );
		$last     = $manifest->volumes()[ count( $manifest->volumes() ) - 1 ];
		$this->assertSame( array( Manifest::DATABASE_INDEX, Manifest::FILES_INDEX, 'manifest.json' ), array_column( ZipReader::open( $this->volumes() . '/' . $last['path'] )->entries(), 'name' ) );
		$this->assertGreaterThan( 100, $last['bytes'] );
		$this->assertStringContainsString( 'Self-check passed', $this->ctx->log() );
	}

	/**
	 * @return array<string, string>
	 */
	private function volume_hashes(): array {
		$out = array();
		foreach ( glob( $this->volumes() . '/*.wpcheckpoint.zip' ) ?: array() as $path ) {
			$out[ basename( $path ) ] = hash_file( 'sha256', $path );
		}
		ksort( $out );
		return $out;
	}
}
