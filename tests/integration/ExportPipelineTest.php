<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Archive\ArchiveVerifier;
use WPCheckpoint\Archive\ChunkHasher;
use WPCheckpoint\Archive\IndexLine;
use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Archive\VerificationResult;
use WPCheckpoint\Archive\ZipReader;
use WPCheckpoint\Database\WpdbConnection;
use WPCheckpoint\Files\PathKey;
use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\DatabaseExportStep;
use WPCheckpoint\Jobs\ExportPlan;
use WPCheckpoint\Jobs\FileScanStep;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobPresenter;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\JobTypes;
use WPCheckpoint\Jobs\ManifestStep;
use WPCheckpoint\Jobs\PackStep;
use WPCheckpoint\Jobs\PreflightStep;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Jobs\ReviewStep;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Jobs\StoreStep;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Jobs\FixtureJobType;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * The seven export steps in a row under the real Runner, one unit per
 * tick, with a crash simulated at every boundary that has a replay rule:
 * torn work files and bytes past the committed volume length during the
 * pack, the finish and the standalone manifest done but not checkpointed,
 * a rename done but not checkpointed during the store. The result is
 * checked by the reader at full depth, by unzip, and by importing the
 * database chunks through the mysql client.
 */
final class ExportPipelineTest extends JobTestCase {

	const PREFIX  = 'wpcpipe_';
	const CHUNK   = 1048576; // The reader's minimum content chunk.
	const VOLUME  = 1572864; // Volumes seal at 1.5 MB: the small archive still spans several.
	const SCRATCH = 'wpcheckpoint_test_pipeline';

	/** @var float */
	private $now;

	/** @var JobRepository */
	private $repo;

	/** @var JobTypes */
	private $types;

	/** @var Directories */
	private $dirs;

	/** @var string */
	private $uploads;

	/** @var string */
	private $scratch;

	/** @var string[] */
	private $tables = array();

	/** @var string */
	private $slug = 'Example.Test Site';

	public function set_up(): void {
		parent::set_up();
		$this->dirs  = new Directories( array( 'is_web_request' => false, 'document_root' => '' ) );
		$this->now   = 1_800_000_000.0;
		$this->repo  = new JobRepository( $this->dirs, null, function (): int {
			return (int) floor( $this->now );
		} );
		$this->types = new JobTypes();
		Schema::ensure();
		$this->uploads = wp_upload_dir()['basedir'] . '/wpcpipe-uploads';
		$this->scratch = WP_CONTENT_DIR . '/wp-checkpoint-pipeline-scratch';
		mkdir( $this->uploads . '/images', 0755, true );
		mkdir( $this->uploads . '/docs', 0755, true );
		mkdir( $this->scratch . '/verify', 0755, true );
		file_put_contents( $this->uploads . '/images/a.jpg', str_repeat( 'jpeg', 750 ) );
		for ( $i = 0; $i < 20; $i++ ) {
			file_put_contents( sprintf( '%s/images/img-%02d.txt', $this->uploads, $i ), str_repeat( chr( 65 + $i ), 100 + $i ) );
		}
		// Larger than two content chunks and than a volume: several chunks, a volume boundary inside a file.
		$handle = fopen( $this->uploads . '/docs/big.bin', 'wb' );
		for ( $i = 0; $i < 41; $i++ ) {
			fwrite( $handle, random_bytes( 65536 ) );
		}
		fwrite( $handle, 'tail' );
		fclose( $handle );
		$this->create_tables();
	}

	public function tear_down(): void {
		global $wpdb;
		Deleter::empty_directory( $this->uploads );
		@rmdir( $this->uploads );
		Deleter::empty_directory( $this->scratch );
		@rmdir( $this->scratch );
		foreach ( $this->tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		$wpdb->query( 'DROP DATABASE IF EXISTS `' . self::SCRATCH . '`' );
		parent::tear_down();
	}

	private function create_tables(): void {
		global $wpdb;
		$p            = self::PREFIX;
		$this->tables = array( $p . 'options', $p . 'posts' );
		foreach ( $this->tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		$wpdb->query( "CREATE TABLE `{$p}options` (`option_id` bigint(20) NOT NULL AUTO_INCREMENT, `option_name` varchar(191) NOT NULL, `option_value` longtext, `blob_value` varbinary(255), PRIMARY KEY (`option_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
		$wpdb->query( "CREATE TABLE `{$p}posts` (`ID` bigint(20) NOT NULL AUTO_INCREMENT, `post_title` text, `post_content` longtext, PRIMARY KEY (`ID`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
		$this->assertSame( '', $wpdb->last_error );
		$wpdb->query( "INSERT INTO `{$p}options` (`option_name`, `option_value`, `blob_value`) VALUES ('plain', 'value', NULL), ('unicode', 'Grüße — 日本語 \\\\ \\'quoted\\'', UNHEX('00FF10')), ('empty', '', UNHEX(''))" );
		$this->assertSame( '', $wpdb->last_error );
		// About 2 MB of rows: the table needs more than one 1 MB chunk.
		for ( $batch = 0; $batch < 8; $batch++ ) {
			$rows = array();
			for ( $i = 0; $i < 400; $i++ ) {
				$n      = $batch * 400 + $i;
				$rows[] = sprintf( "('title %d', CONCAT(REPEAT('%s', 220), '%d'))", $n, chr( 97 + ( $n % 26 ) ) . 'ä', $n );
			}
			$wpdb->query( "INSERT INTO `{$p}posts` (`post_title`, `post_content`) VALUES " . implode( ', ', $rows ) );
			$this->assertSame( '', $wpdb->last_error );
		}
	}

	private function runner( int $seconds ): Runner {
		return new Runner(
			$this->repo,
			$this->types,
			new Redactor( Redactor::installation_secrets() ),
			array(
				'clock'        => function (): float {
					$this->now += 1.0;
					return $this->now;
				},
				'memory'       => static function (): int {
					return 10 * 1048576;
				},
				'budget'       => new Budget( $seconds, 32 * 1048576, false ),
				'memory_limit' => -1,
				'paths'        => array( '{abspath}' => rtrim( ABSPATH, '/' ) ),
			)
		);
	}

	private function packer_options(): array {
		return array(
			'volume_bytes'       => self::VOLUME,
			'volume_chunk_bytes' => self::CHUNK,
		);
	}

	/**
	 * The seven steps as the export job will chain them (part C2 registers the real type).
	 *
	 * @param callable|null $after_chunk PackStep test seam.
	 */
	private function register_export( string $id, $after_chunk = null ): void {
		$connection = new WpdbConnection();
		$dirs       = $this->dirs;
		$site       = json_decode( (string) file_get_contents( __DIR__ . '/../Fixtures/Manifest/valid/base.json' ), true )['site'];
		$this->types->add(
			new FixtureJobType(
				$id,
				array(
					new PreflightStep(
						$connection,
						array(
							'prefix'        => self::PREFIX,
							'tables'        => array( $connection, 'tables_with_prefix' ),
							'writable'      => static function (): array {
								return array();
							},
							'disk_free'     => static function () use ( $dirs ) {
								return disk_free_space( $dirs->base() );
							},
							'slug'          => function (): string {
								return $this->slug;
							},
							'can_deflate'   => true,
							'normalization' => PathKey::normalization_available(),
							'int_size'      => PHP_INT_SIZE,
							'now'           => function (): int {
								return (int) $this->now;
							},
						),
						self::CHUNK
					),
					FileScanStep::from_plan(),
					new ReviewStep(),
					DatabaseExportStep::from_plan( $connection, self::CHUNK ),
					new PackStep( null, $this->packer_options(), self::CHUNK, $after_chunk ),
					new ManifestStep( $site, array( 'name' => 'wp-checkpoint', 'version' => '0.1.0-test' ), $this->packer_options(), self::CHUNK ),
					new StoreStep( $dirs->backups() ),
				)
			)
		);
	}

	private function options(): array {
		return array(
			'contents' => array( 'files' => array( 'uploads' ) ),
			'policy'   => array( 'unreadable' => 'continue', 'oversize' => 'fail', 'large_dirs' => 'include' ),
		);
	}

	private function work( Job $job ): string {
		return Residue::work_dir( $job->storage_path, $job->id );
	}

	private function volumes( Job $job ): string {
		return $this->work( $job ) . '/' . PackStep::VOLUMES;
	}

	private function log( Job $job ): string {
		return (string) file_get_contents( $this->dirs->base() . '/' . $job->log_path );
	}

	/**
	 * Overwrite the stored cursor as a crashed tick would have left it: the lock is taken and released
	 * around the write, as the Runner does.
	 */
	private function rewind( Job $job, array $cursor ): void {
		$lock = $this->repo->acquire( $job->id, 60 );
		$this->assertNotNull( $lock );
		$this->repo->save_progress( $lock['job'], $lock['token'], $lock['job']->step, $cursor, $lock['job']->progress, 'rewound by the test', false );
		$this->assertTrue( $this->repo->release( $this->repo->find( $job->id ), $lock['token'] ) );
	}

	/**
	 * Tick with a one-second budget (one unit per tick on a clock that advances per reading) until the job
	 * stops being "more", calling $between with the stored job before each tick.
	 */
	private function drive( int $id, callable $between = null, int $limit = 3000 ): TickResult {
		for ( $i = 0; $i < $limit; $i++ ) {
			if ( null !== $between ) {
				$between( $this->repo->find( $id ) );
			}
			$result = $this->runner( 1 )->tick( $id, $this->now );
			if ( TickResult::MORE !== $result->status ) {
				return $result;
			}
		}
		$this->fail( 'the job did not stop' );
	}

	public function test_the_pipeline_writes_a_verified_archive_into_backups_and_replays_every_boundary(): void {
		global $wpdb;
		$this->register_export( 'export-c' );
		$job     = $this->repo->create( 'export-c', 0, array(), $this->options() );
		$done          = array();
		$sealed        = array();
		$partial       = null;
		$finish_cursor = null;
		$result        = $this->drive( $job->id, function ( Job $stored ) use ( &$done, &$sealed, &$partial, &$finish_cursor ): void {
			$cursor = $stored->cursor;
			if ( PackStep::ID === $stored->step && ! isset( $done['torn'] ) && 'files' === ( $cursor['phase'] ?? '' ) && isset( $cursor['file']['chunk'] ) && $cursor['file']['chunk'] >= 1 ) {
				// A tick that wrote a chunk line, an index line and volume bytes after the last checkpoint and died.
				$work = $this->work( $stored );
				file_put_contents( $work . '/' . PackStep::PACKED_INDEX, "{\"p\":\"wp-content/uploads/ghost.txt\",\"b\":1,\"m\":1,\"h\":\"" . str_repeat( '0', 64 ) . "\"}\n", FILE_APPEND );
				file_put_contents( $work . '/' . PackStep::CHUNKS, "{\"i\":9,\"h\":\"torn", FILE_APPEND );
				$partials = glob( $this->volumes( $stored ) . '/*.partial' ) ?: array();
				$this->assertCount( 1, $partials, 'a volume is open mid-file' );
				$partial = filesize( $partials[0] );
				file_put_contents( $partials[0], 'garbage past the committed length', FILE_APPEND );
				$done['torn'] = $cursor['packed_bytes'];
			}
			if ( ManifestStep::ID === $stored->step && 'finish' === ( $cursor['phase'] ?? '' ) && null === $finish_cursor ) {
				$finish_cursor = $cursor; // The checkpoint before finish(): the indexes appended, the volume still open.
			}
			if ( ManifestStep::ID === $stored->step && 'verify' === ( $cursor['phase'] ?? '' ) ) {
				if ( ! isset( $done['prepare'] ) ) {
					// prepare_finish() sealed (or kept) the volume and the tick died before the checkpoint: the cursor
					// still says "prepare" and holds no packer state, so the pack step's state file is read again.
					foreach ( glob( $this->volumes( $stored ) . '/*.wpcheckpoint.zip' ) ?: array() as $volume ) {
						$sealed[ basename( $volume ) ] = hash_file( 'sha256', $volume );
					}
					$this->assertNotEmpty( $sealed );
					$this->assertFileExists( $this->volumes( $stored ) . '/' . ExportPlan::read( $this->work( $stored ), ExportPlan::PLAN )['base'] . '.manifest.json' );
					$this->rewind( $stored, array_diff_key( array_merge( $cursor, array( 'phase' => 'prepare' ) ), array( 'packer' => 1, 'verifier' => 1 ) ) );
					$done['prepare'] = true;
				} elseif ( ! isset( $done['finish'] ) ) {
					// finish() sealed the last volume and the tick died before the checkpoint.
					$this->assertNotNull( $finish_cursor, 'the finish phase was observed between ticks' );
					$this->rewind( $stored, $finish_cursor );
					$done['finish'] = true;
				} elseif ( ! isset( $done['standalone'] ) ) {
					// The standalone manifest was written and the tick died before its checkpoint.
					$this->rewind( $stored, array_merge( $cursor, array( 'phase' => 'standalone' ) ) );
					$done['standalone'] = true;
				}
			}
			if ( StoreStep::ID === $stored->step && ! isset( $done['store'] ) && 1 === ( $cursor['moved'] ?? 0 ) ) {
				// The second file was renamed by a tick that died before recording it.
				$base     = ExportPlan::read( $this->work( $stored ), ExportPlan::PLAN )['base'];
				$manifest = Manifest::from_json( (string) file_get_contents( $this->volumes( $stored ) . '/' . $base . '.manifest.json' ) );
				$name     = $manifest->volumes()[1]['path'];
				$this->assertTrue( rename( $this->volumes( $stored ) . '/' . $name, $this->dirs->backups() . '/' . $name ) );
				$done['store'] = true;
			}
		} );
		$stored = $this->repo->find( $job->id );
		$this->assertSame( TickResult::COMPLETED, $result->status, (string) $stored->last_error );
		$this->assertSame( array( 'torn', 'prepare', 'finish', 'standalone', 'store' ), array_keys( $done ), 'every boundary was hit' );
		$this->assertSame( '', $stored->last_error );
		$log = $this->log( $stored );
		$this->assertStringNotContainsString( 'Step made no progress', $log );
		$this->assertStringContainsString( 'Pack finished', $log );
		$this->assertStringContainsString( 'Self-check passed', $log );
		$this->assertStringContainsString( 'Backup stored', $log );
		$this->assertStringNotContainsString( $this->uploads, $log, 'no absolute path in the log' );
		$this->assertStringNotContainsString( DB_PASSWORD, $log );

		// backups/ holds the volumes and the manifest, nothing else; the work directory is leftovers now.
		$base     = ExportPlan::read( $this->work( $stored ), ExportPlan::PLAN )['base'];
		$manifest = Manifest::from_json( (string) file_get_contents( $this->dirs->backups() . '/' . $base . '.manifest.json' ) );
		$names    = array_column( $manifest->volumes(), 'path' );
		$this->assertGreaterThanOrEqual( 2, count( $names ), 'the database chunks, the big file and the indexes spread over volumes' );
		$listing = array_values( array_diff( scandir( $this->dirs->backups() ) ?: array(), array( '.', '..', 'index.php', '.htaccess' ) ) );
		sort( $names );
		$expected = array_merge( $names, array( $base . '.manifest.json' ) );
		sort( $expected );
		$this->assertSame( $expected, $listing );
		foreach ( $manifest->volumes() as $volume ) {
			$path = $this->dirs->backups() . '/' . $volume['path'];
			$this->assertSame( $volume['bytes'], filesize( $path ) );
			// A volume above one container chunk is described by the list hash of its chunks.
			$expected = $volume['bytes'] > self::CHUNK ? ChunkHasher::list_hash( ChunkHasher::hash_chunks( $path, self::CHUNK ) ) : hash_file( 'sha256', $path );
			$this->assertSame( $expected, $volume['sha256'], 'the manifest describes the file as stored' );
			if ( isset( $sealed[ $volume['path'] ] ) ) {
				$this->assertSame( $sealed[ $volume['path'] ], hash_file( 'sha256', $path ), 'a volume sealed before the finish replay is byte for byte the same' );
			}
		}
		$this->assertSame( 22, $manifest->files_summary()['count'] );
		$this->assertSame( 3000 + array_sum( range( 100, 119 ) ) + 41 * 65536 + 4, $manifest->files_summary()['bytes'] );
		$this->assertSame( array( self::PREFIX . 'options', self::PREFIX . 'posts' ), array_column( $manifest->tables(), 'name' ) );
		$this->assertGreaterThan( 1, $manifest->tables()[1]['chunks'] );
		$this->assertStringNotContainsString( $this->uploads, wp_json_encode( $manifest->warnings() ) );
		$this->assertStringNotContainsString( 'ghost', (string) file_get_contents( $this->dirs->backups() . '/' . $base . '.manifest.json' ) );
		$this->assertDirectoryExists( $this->work( $stored ) );
		$this->repo->reap();
		$this->assertDirectoryDoesNotExist( $this->work( $stored ), 'a completed job\'s work directory is leftovers for the reaper' );
		$this->assertFileExists( $this->dirs->backups() . '/' . $names[0] );

		// The reader, at full depth, over what is in backups/.
		$verified = ArchiveVerifier::open( $this->dirs->backups() . '/' . $base . '.manifest.json', $this->scratch . '/verify', ArchiveVerifier::DEPTH_FULL )->run();
		$this->assertSame( VerificationResult::PASSED, $verified->outcome(), $verified->to_text( static function ( string $t ): string {
			return $t;
		} ) );

		// Another reader: every volume is a zip that unzip (or, where there is none, libzip) accepts whole.
		$unzip = trim( (string) shell_exec( 'command -v unzip' ) );
		if ( '' === $unzip && ! class_exists( '\\ZipArchive' ) ) {
			$this->fail( 'Neither unzip nor the zip extension is available; an independent reader is the proof that the format is open.' );
		}
		foreach ( $manifest->volumes() as $volume ) {
			$path = $this->dirs->backups() . '/' . $volume['path'];
			if ( '' !== $unzip ) {
				exec( escapeshellarg( $unzip ) . ' -tqq ' . escapeshellarg( $path ) . ' 2>&1', $output, $code );
				$this->assertSame( 0, $code, implode( "\n", $output ) );
				continue;
			}
			$zip = new \ZipArchive();
			$this->assertTrue( $zip->open( $path, \ZipArchive::CHECKCONS ), $volume['path'] );
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$stream = $zip->getStream( (string) $zip->getNameIndex( $i ) );
				$this->assertIsResource( $stream );
				while ( ! feof( $stream ) ) {
					fread( $stream, 1048576 ); // libzip checks the CRC at the end of the stream.
				}
				$this->assertTrue( fclose( $stream ), 'the entry read to its end with a matching CRC' );
			}
			$zip->close();
		}

		// The content: the files byte for byte, the database through the mysql client.
		$extracted = $this->extract_all( $manifest );
		$this->assertSame( hash_file( 'sha256', $this->uploads . '/docs/big.bin' ), hash_file( 'sha256', $extracted . '/files/wp-content/uploads/wpcpipe-uploads/docs/big.bin' ) );
		$this->assertSame( str_repeat( 'jpeg', 750 ), (string) file_get_contents( $extracted . '/files/wp-content/uploads/wpcpipe-uploads/images/a.jpg' ) );
		$index = array_values( array_filter( explode( "\n", (string) file_get_contents( $extracted . '/' . Manifest::FILES_INDEX ) ) ) );
		$this->assertCount( 22, $index );
		$this->assertStringNotContainsString( 'ghost', implode( "\n", $index ), 'the torn index line was cut off on resume' );
		$lines = array();
		foreach ( array_filter( explode( "\n", (string) file_get_contents( $extracted . '/' . Manifest::DATABASE_INDEX ) ) ) as $line ) {
			$lines[] = IndexLine::database( $line, self::CHUNK );
		}
		$this->round_trip( $extracted, $lines );
		$this->assertSame( 3, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . self::SCRATCH . '`.`' . self::PREFIX . 'options`' ) );
	}

	public function test_three_ticks_in_a_row_that_restart_a_changed_file_are_progress_and_the_final_content_is_packed(): void {
		$this->register_export( 'export-c' );
		$job      = $this->repo->create( 'export-c', 0, array(), $this->options() );
		$big      = $this->uploads . '/docs/big.bin';
		$restarts = array();
		$result   = $this->drive( $job->id, function ( Job $stored ) use ( $big, &$restarts ): void {
			$file = $stored->cursor['file'] ?? null;
			if ( PackStep::ID !== $stored->step || ! is_array( $file ) || 'wp-content/uploads/wpcpipe-uploads/docs/big.bin' !== $file['p'] ) {
				return;
			}
			if ( (int) $file['restarts'] < PackStep::MAX_RESTARTS ) {
				// Between two ticks the file grows and its mtime moves on: the next chunk finds it changed.
				file_put_contents( $big, str_repeat( 'x', 1000 ), FILE_APPEND );
				touch( $big, filemtime( $big ) + 10 );
				clearstatcache( true, $big );
				$restarts[] = (int) $file['restarts'];
			}
		} );
		$stored = $this->repo->find( $job->id );
		$this->assertSame( TickResult::COMPLETED, $result->status, (string) $stored->last_error );
		$this->assertSame( array( 0, 1, 2 ), array_slice( $restarts, 0, 3 ), 'three consecutive ticks each found the file changed' );
		$log = $this->log( $stored );
		$this->assertSame( 3, substr_count( $log, 'starting it over' ) );
		$this->assertStringNotContainsString( 'Step made no progress', $log, 'a restart moves the cursor: it is progress' );
		$this->assertStringNotContainsString( 'changed repeatedly', $log );
		$base     = ExportPlan::read( $this->work( $stored ), ExportPlan::PLAN )['base'];
		$manifest = Manifest::from_json( (string) file_get_contents( $this->dirs->backups() . '/' . $base . '.manifest.json' ) );
		$this->assertSame( array(), preg_grep( '/big\.bin/', $manifest->warnings() ), 'three restarts are within the limit: no warning' );
		$verified = ArchiveVerifier::open( $this->dirs->backups() . '/' . $base . '.manifest.json', $this->scratch . '/verify', ArchiveVerifier::DEPTH_FULL )->run();
		$this->assertSame( VerificationResult::PASSED, $verified->outcome(), 'the aborted entries were cut out of the volume' );
		$extracted = $this->extract_all( $manifest );
		$this->assertSame( hash_file( 'sha256', $big ), hash_file( 'sha256', $extracted . '/files/wp-content/uploads/wpcpipe-uploads/docs/big.bin' ), 'the content after the last change' );
		$this->assertSame( filesize( $big ), 41 * 65536 + 4 + 3000 );
	}

	public function test_a_volume_shorter_than_its_committed_length_fails_the_job_and_keeps_the_work_for_a_retry(): void {
		$this->register_export( 'export-c' );
		$job    = $this->repo->create( 'export-c', 0, array(), $this->options() );
		$cut    = false;
		$result = $this->drive( $job->id, function ( Job $stored ) use ( &$cut ): void {
			$cursor = $stored->cursor;
			if ( ! $cut && PackStep::ID === $stored->step && 'files' === ( $cursor['phase'] ?? '' ) && isset( $cursor['file']['chunk'] ) && $cursor['file']['chunk'] >= 1 ) {
				$partials = glob( $this->volumes( $stored ) . '/*.partial' ) ?: array();
				$this->assertCount( 1, $partials );
				$handle = fopen( $partials[0], 'r+b' );
				ftruncate( $handle, filesize( $partials[0] ) - 1 );
				fclose( $handle );
				$cut = true;
			}
		} );
		$this->assertTrue( $cut );
		$this->assertSame( TickResult::FAILED, $result->status );
		$stored = $this->repo->find( $job->id );
		$this->assertStringContainsString( 'shorter than its recorded committed length', $stored->last_error );
		$this->assertStringNotContainsString( $this->dirs->base(), $stored->last_error );
		$this->assertTrue( $stored->can_retry() );
		$this->assertDirectoryExists( $this->work( $stored ), 'a failed job keeps its work directory' );
		$this->assertSame( array(), array_diff( scandir( $this->dirs->backups() ) ?: array(), array( '.', '..', 'index.php', '.htaccess' ) ), 'nothing reached backups/' );
	}

	public function test_a_name_already_in_backups_fails_the_store_and_overwrites_nothing(): void {
		$this->register_export( 'export-c' );
		$job     = $this->repo->create( 'export-c', 0, array(), $this->options() );
		$planted = null;
		$result  = $this->drive( $job->id, function ( Job $stored ) use ( &$planted ): void {
			if ( null === $planted && PackStep::ID === $stored->step ) {
				$planted = $this->dirs->backups() . '/' . ExportPlan::read( $this->work( $stored ), ExportPlan::PLAN )['base'] . '.manifest.json';
				file_put_contents( $planted, 'someone else' );
			}
		} );
		$this->assertSame( TickResult::FAILED, $result->status );
		$stored = $this->repo->find( $job->id );
		$this->assertSame( StoreStep::ID, $stored->step );
		$this->assertStringContainsString( 'The manifest (file 3 of 3) already exists in the backups directory; nothing was overwritten', $stored->last_error );
		$this->assertStringNotContainsString( 'example-test-site', $stored->last_error, 'no backup name, no slug' );
		$this->assertSame( 'someone else', (string) file_get_contents( (string) $planted ) );
		$this->assertCount( 1, array_diff( scandir( $this->dirs->backups() ) ?: array(), array( '.', '..', 'index.php', '.htaccess' ) ), 'no volume was moved' );
		$this->assertNotEmpty( glob( $this->volumes( $stored ) . '/*.wpcheckpoint.zip' ), 'the finished archive waits in the work directory' );
	}

	/**
	 * Sentinel: a slug that cannot occur by accident must not reach any output a person could copy: the
	 * job log, the cursors, the step summaries, the error text, the presenter's payload. The standalone
	 * manifest's volume list is the one place it belongs (it names the files).
	 */
	public function test_the_site_slug_reaches_no_copyable_output(): void {
		$this->slug = 'Zebra Quokka Site';
		$this->register_export( 'export-c' );
		file_put_contents( $this->dirs->backups() . '/placeholder.txt', '' ); // No collision: just a directory that is not empty.
		$job     = $this->repo->create( 'export-c', 0, array(), $this->options() );
		$cursors = '';
		$result  = $this->drive( $job->id, function ( Job $stored ) use ( &$cursors ): void {
			$cursors .= wp_json_encode( $stored->cursor ) . "\n";
		} );
		$stored = $this->repo->find( $job->id );
		$this->assertSame( TickResult::COMPLETED, $result->status, (string) $stored->last_error );
		$base = ExportPlan::read( $this->work( $stored ), ExportPlan::PLAN )['base'];
		$this->assertStringStartsWith( 'zebra-quokka-site-', $base );
		$this->assertStringNotContainsString( 'zebra-quokka', $this->log( $stored ), 'the job log' );
		// The cursors do carry it: the packer state names the archive (its base) and the sealed volumes, and the
		// entry in progress by its absolute source path. Cursors never leave the engine (the presenter has no
		// field for them); the outputs below are what a person can copy.
		$this->assertStringContainsString( 'zebra-quokka', $cursors, 'the packer state in the pack cursor names the archive' );
		foreach ( array( ExportPlan::PREFLIGHT, ExportPlan::REVIEW, FileScanStep::SUMMARY, DatabaseExportStep::SUMMARY, PackStep::SUMMARY ) as $name ) {
			$this->assertStringNotContainsString( 'zebra-quokka', (string) file_get_contents( $this->work( $stored ) . '/' . $name ), $name );
		}
		$this->assertSame( '', $stored->last_error );
		// A failure that names a backup file by name would be masked by the presenter as a second line of defence.
		$presenter = new JobPresenter( new Redactor( Redactor::installation_secrets() ), $this->types, $this->dirs );
		$this->assertSame( 'File [backup].part002.wpcheckpoint.zip is missing.', $presenter->clean( sprintf( 'File %s.part002.wpcheckpoint.zip is missing.', $base ) ) );
		foreach ( array( '%s', '%s.', '%s.wpcheckpoint.zip', '%s.wpcheckpoint.tar', '%s.part002.wpcheckpoint.zip.partial', '%s.part1000.wpcheckpoint.zip', '%s.part2000.wpcheckpoint.zip.cdr', '%s.wpcheckpoint.zip.cdr', '%s.manifest.json', '(%s)' ) as $form ) {
			$this->assertStringNotContainsString( 'zebra-quokka', $presenter->clean( 'Name: ' . sprintf( $form, $base ) ), $form );
		}
		// A user's file that looks like a base name but has its own extension is named as it is.
		$this->assertSame( 'Unreadable: wp-content/uploads/report-20260922-100000-cafe.pdf', $presenter->clean( 'Unreadable: wp-content/uploads/report-20260922-100000-cafe.pdf' ) );
		$this->assertStringNotContainsString( 'zebra-quokka', wp_json_encode( $presenter->present( $stored ) ) );
	}

	/**
	 * Every entry of every volume, into the scratch directory.
	 */
	private function extract_all( Manifest $manifest ): string {
		$dir = $this->scratch . '/extracted';
		mkdir( $dir, 0755, true );
		foreach ( $manifest->volumes() as $volume ) {
			$reader = ZipReader::open( $this->dirs->backups() . '/' . $volume['path'] );
			$reader->each( static function ( array $entry ) use ( $reader, $dir ): bool {
				if ( empty( $entry['directory'] ) ) {
					$reader->extract( $entry, $dir );
				}
				return true;
			} );
		}
		return $dir;
	}

	/**
	 * The chunks in index order through the mysql client into a scratch database, then row for row
	 * against the source.
	 *
	 * @param array<int, array<string, mixed>> $lines Database index lines.
	 */
	private function round_trip( string $extracted, array $lines ): void {
		global $wpdb;
		$client = trim( (string) shell_exec( 'command -v mariadb || command -v mysql' ) );
		if ( '' === $client ) {
			if ( false !== getenv( 'CI' ) ) {
				$this->fail( 'The round trip through the mysql client is the proof that the archive restores; CI must not skip it.' );
			}
			$this->markTestSkipped( 'No mysql client in this environment.' );
		}
		$wpdb->query( 'DROP DATABASE IF EXISTS `' . self::SCRATCH . '`' );
		$wpdb->query( 'CREATE DATABASE `' . self::SCRATCH . '` DEFAULT CHARACTER SET utf8mb4' );
		$this->assertSame( '', $wpdb->last_error, 'the test user can create the scratch database' );
		$host = DB_HOST;
		$port = '3306';
		if ( false !== strpos( $host, ':' ) ) {
			list( $host, $port ) = explode( ':', $host, 2 );
		}
		$files = array();
		foreach ( $lines as $line ) {
			$this->assertFileExists( $extracted . '/' . $line['p'] );
			$files[] = escapeshellarg( $extracted . '/' . $line['p'] );
		}
		$this->assertNotEmpty( $files );
		$command = sprintf(
			'cat %s | MYSQL_PWD=%s %s --host=%s --port=%s --user=%s --default-character-set=utf8mb4 %s 2>&1',
			implode( ' ', $files ),
			escapeshellarg( DB_PASSWORD ),
			escapeshellarg( $client ),
			escapeshellarg( $host ),
			escapeshellarg( $port ),
			escapeshellarg( DB_USER ),
			escapeshellarg( self::SCRATCH )
		);
		$output  = (string) shell_exec( $command );
		$this->assertDoesNotMatchRegularExpression( '/ERROR/', $output, 'the client imported every chunk without an error' );
		foreach ( $this->tables as $table ) {
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" );
			$order   = implode( ', ', array_map( static function ( string $c ): string {
				return "`{$c}`";
			}, $columns ) );
			$source  = $wpdb->get_results( "SELECT {$order} FROM `" . DB_NAME . "`.`{$table}` ORDER BY {$order}", ARRAY_N );
			$copy    = $wpdb->get_results( "SELECT {$order} FROM `" . self::SCRATCH . "`.`{$table}` ORDER BY {$order}", ARRAY_N );
			$this->assertSame( '', $wpdb->last_error );
			$this->assertNotEmpty( $source );
			$this->assertSame( $source, $copy, "every row of {$table} came back byte for byte" );
		}
	}
}
