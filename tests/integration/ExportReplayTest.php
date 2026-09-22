<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Database\WpdbConnection;
use WPCheckpoint\Files\PathKey;
use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\DatabaseExportStep;
use WPCheckpoint\Jobs\FileScanStep;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\JobTypes;
use WPCheckpoint\Jobs\LockLost;
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
 * The systematic replay: for every cursor write k of an uninterrupted
 * export, the state "disk as the run left it right before writing
 * cursor k+1, cursor still k" is the state a process that died there
 * leaves behind. From each such state the job is driven to completion
 * and the archive must be byte for byte the one of the uninterrupted
 * run. Every unit of every step is covered this way, including a seal
 * in the middle of a tick and the single-volume rename.
 *
 * The run is deterministic: the pre-flight's clock and random suffix,
 * the database export's period, and the manifest's clock are injected,
 * and the fixture files keep their mtimes.
 */
final class ExportReplayTest extends JobTestCase {

	const PREFIX = 'wpcreplay_';
	const CHUNK  = 1048576; // The reader's minimum content chunk.
	const VOLUME = 2097152; // Small volumes: several seals, some in the middle of a tick.

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

	/** @var string[] */
	private $tables = array();

	/** @var callable|null */
	private $on_persist;

	public function set_up(): void {
		parent::set_up();
		$this->dirs  = new Directories( array( 'is_web_request' => false, 'document_root' => '' ) );
		$this->now   = 1_800_000_000.0;
		$this->repo  = new JobRepository( $this->dirs, null, function (): int {
			return (int) floor( $this->now );
		} );
		$this->types = new JobTypes();
		Schema::ensure();
		$this->uploads = wp_upload_dir()['basedir'] . '/wpcreplay-uploads';
		mkdir( $this->uploads . '/a', 0755, true );
		mkdir( $this->uploads . '/b', 0755, true );
		mt_srand( 7 );
		// Sizes chosen so that volumes seal between database chunks, inside the file list, and so that the
		// summaries may or may not fit the last data volume: 2 MiB volumes, 1 MiB chunks, about 7 MB in all.
		foreach ( array( 'a/one.bin' => 1500000, 'a/two.bin' => 900000, 'b/three.bin' => 2300000, 'b/four.txt' => 40000 ) as $rel => $bytes ) {
			$handle = fopen( $this->uploads . '/' . $rel, 'wb' );
			for ( $left = $bytes; $left > 0; $left -= 65536 ) {
				$piece = '';
				for ( $i = 0; $i < min( $left, 65536 ); $i++ ) {
					$piece .= chr( mt_rand( 32, 126 ) );
				}
				fwrite( $handle, $piece );
			}
			fclose( $handle );
			touch( $this->uploads . '/' . $rel, 1700000000 );
		}
		for ( $i = 0; $i < 12; $i++ ) {
			file_put_contents( sprintf( '%s/a/small-%02d.txt', $this->uploads, $i ), str_repeat( chr( 65 + $i ), 300 + $i ) );
			touch( sprintf( '%s/a/small-%02d.txt', $this->uploads, $i ), 1700000000 + $i );
		}
		$this->create_tables();
	}

	public function tear_down(): void {
		global $wpdb;
		Deleter::empty_directory( $this->uploads );
		@rmdir( $this->uploads );
		foreach ( $this->tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		parent::tear_down();
	}

	private function create_tables(): void {
		global $wpdb;
		$p            = self::PREFIX;
		$this->tables = array( $p . 'options', $p . 'posts' );
		foreach ( $this->tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		$wpdb->query( "CREATE TABLE `{$p}options` (`option_id` bigint(20) NOT NULL AUTO_INCREMENT, `option_name` varchar(191) NOT NULL, `option_value` longtext, PRIMARY KEY (`option_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
		$wpdb->query( "CREATE TABLE `{$p}posts` (`ID` bigint(20) NOT NULL AUTO_INCREMENT, `post_title` text, `post_content` longtext, PRIMARY KEY (`ID`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
		$this->assertSame( '', $wpdb->last_error );
		$wpdb->query( "INSERT INTO `{$p}options` (`option_name`, `option_value`) VALUES ('plain', 'value'), ('unicode', 'Grüße — 日本語')" );
		// About 1.3 MB of rows: two chunks.
		for ( $batch = 0; $batch < 6; $batch++ ) {
			$rows = array();
			for ( $i = 0; $i < 400; $i++ ) {
				$n      = $batch * 400 + $i;
				$rows[] = sprintf( "('title %d', CONCAT(REPEAT('%s', 180), '%d'))", $n, chr( 97 + ( $n % 26 ) ) . 'ä', $n );
			}
			$wpdb->query( "INSERT INTO `{$p}posts` (`post_title`, `post_content`) VALUES " . implode( ', ', $rows ) );
			$this->assertSame( '', $wpdb->last_error );
		}
	}

	/**
	 * A runner whose clock moves 0.1 s per reading: with a 20-second budget a tick runs many units, and the
	 * checkpoint rhythm (2 s or 16 MB) leaves several units between two cursor writes. That is the shape a
	 * production tick has, and the one a crash must survive.
	 */
	private function runner(): Runner {
		return new Runner(
			$this->repo,
			$this->types,
			new Redactor( Redactor::installation_secrets() ),
			array(
				'clock'        => function (): float {
					$this->now += 0.1;
					return $this->now;
				},
				'memory'       => static function (): int {
					return 10 * 1048576;
				},
				'budget'       => new Budget( 20, 32 * 1048576, false ),
				'memory_limit' => -1,
				'paths'        => array( '{abspath}' => rtrim( ABSPATH, '/' ) ),
				'on_persist'   => function ( Job $job, string $step, array $cursor ): void {
					if ( null !== $this->on_persist ) {
						call_user_func( $this->on_persist, $job, $step, $cursor );
					}
				},
			)
		);
	}

	private function packer_options(): array {
		return array(
			'volume_bytes'       => self::VOLUME,
			'volume_chunk_bytes' => self::CHUNK,
		);
	}

	private function register_export(): void {
		$connection = new WpdbConnection();
		$dirs       = $this->dirs;
		$site       = json_decode( (string) file_get_contents( __DIR__ . '/../Fixtures/Manifest/valid/base.json' ), true )['site'];
		$fixed      = static function (): int {
			return 1800000000;
		};
		$this->types->add(
			new FixtureJobType(
				'export-replay',
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
							'slug'          => static function (): string {
								return 'Replay Site';
							},
							'can_deflate'   => true,
							'normalization' => PathKey::normalization_available(),
							'int_size'      => PHP_INT_SIZE,
							'now'           => $fixed,
							'random'        => static function (): string {
								return 'c0de';
							},
						),
						self::CHUNK
					),
					FileScanStep::from_plan( self::CHUNK ),
					new ReviewStep(),
					DatabaseExportStep::from_plan( $connection, self::CHUNK, $fixed ),
					new PackStep( null, $this->packer_options(), self::CHUNK ),
					new ManifestStep( $site, array( 'name' => 'wp-checkpoint', 'version' => '0.1.0-test' ), $this->packer_options(), self::CHUNK, null, $fixed ),
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

	/**
	 * Tick until the job stops being "more" (or lost, which the caller handles).
	 */
	private function drive( int $id ): TickResult {
		for ( $i = 0; $i < 2000; $i++ ) {
			$result = $this->runner()->tick( $id, $this->now );
			if ( TickResult::MORE !== $result->status ) {
				return $result;
			}
		}
		$this->fail( 'the job did not stop' );
	}

	/**
	 * Put the job row back to a fresh start (or to a given cursor) with the lock cleared, and empty the
	 * work and backups directories as required.
	 */
	private function reset_job( int $id ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->base_prefix . Schema::JOBS_TABLE,
			array(
				'status'       => Job::QUEUED,
				'step'         => '',
				'cursor_json'  => '[]',
				'lock_token'   => '',
				'locked_until' => 0,
				'last_error'   => '',
				'progress'     => 0,
			),
			array( 'id' => $id )
		);
		$this->assertSame( '', $wpdb->last_error );
		$work = Residue::work_dir( $this->dirs->base(), $id );
		Deleter::empty_directory( $work );
		@rmdir( $work );
		foreach ( glob( $this->dirs->backups() . '/*.wpcheckpoint.zip' ) ?: array() as $path ) {
			unlink( $path );
		}
		foreach ( glob( $this->dirs->backups() . '/*.manifest.json' ) ?: array() as $path ) {
			unlink( $path );
		}
		$this->now = 1_800_000_000.0;
	}

	/**
	 * The archive in backups/ as a map of file name to sha256, in name order.
	 *
	 * @return array<string, string>
	 */
	private function digest(): array {
		$out = array();
		foreach ( array_merge( glob( $this->dirs->backups() . '/*.wpcheckpoint.zip' ) ?: array(), glob( $this->dirs->backups() . '/*.manifest.json' ) ?: array() ) as $path ) {
			$out[ basename( $path ) ] = hash_file( 'sha256', $path );
		}
		ksort( $out );
		return $out;
	}

	public function test_every_crash_between_a_disk_change_and_the_next_cursor_write_replays_to_the_same_archive(): void {
		$this->register_export();
		$job = $this->repo->create( 'export-replay', 0, array(), $this->options() );
		$id  = $job->id;

		// The uninterrupted run: count the cursor writes and remember every cursor.
		$cursors          = array();
		$this->on_persist = static function ( Job $job, string $step, array $cursor ) use ( &$cursors ): void {
			$cursors[] = array( $step, $cursor );
		};
		$result           = $this->drive( $id );
		$this->assertSame( TickResult::COMPLETED, $result->status, (string) $this->repo->find( $id )->last_error );
		$reference = $this->digest();
		$this->assertGreaterThanOrEqual( 2, count( $reference ) );
		$manifests = glob( $this->dirs->backups() . '/*.manifest.json' ) ?: array();
		$this->assertCount( 1, $manifests );
		$this->assertStringStartsWith( 'replay-site-20270115-080000-c0de', basename( $manifests[0] ), 'the injected clock and suffix name the archive' );
		$manifest  = Manifest::from_json( (string) file_get_contents( $manifests[0] ) );
		$this->assertGreaterThanOrEqual( 3, count( $manifest->volumes() ), 'the fixture seals several times' );
		$writes = count( $cursors );
		$this->assertGreaterThan( 20, $writes );
		$this->assertSame( array( 'wpcreplay_options', 'wpcreplay_posts' ), array_column( $manifest->tables(), 'name' ) );

		// A second uninterrupted run is byte for byte the same: the run is deterministic, so a difference
		// below is a replay defect and not noise.
		$this->reset_job( $id );
		$this->on_persist = null;
		$this->assertSame( TickResult::COMPLETED, $this->drive( $id )->status );
		$this->assertSame( $reference, $this->digest(), 'the run is deterministic' );

		// For every k: die right before cursor write k+1 (disk as the units since write k left it, cursor k).
		$failed = array();
		for ( $k = 0; $k < $writes; $k++ ) {
			$this->reset_job( $id );
			$count            = 0;
			$this->on_persist = static function ( Job $job, string $step, array $cursor ) use ( &$count, $k ): void {
				++$count;
				if ( $count === $k + 1 ) {
					throw new LockLost( 'simulated crash before cursor write ' . $count );
				}
			};
			$crashed          = false;
			for ( $i = 0; $i < 2000; $i++ ) {
				$tick = $this->runner()->tick( $id, $this->now );
				if ( TickResult::LOST === $tick->status ) {
					$crashed = true;
					break;
				}
				if ( TickResult::MORE !== $tick->status ) {
					break;
				}
			}
			$this->assertTrue( $crashed, "write {$k}: the run reached the crash point" );
			$this->on_persist = null;
			// The lease the dead process held expires; the next driver takes over from cursor k.
			$this->now += JobRepository::LOCK_SECONDS + 1;
			$stored     = $this->repo->find( $id );
			$this->assertSame( $k > 0 ? $cursors[ $k - 1 ][0] : '', $stored->step, "write {$k}: the stored step is the one of write {$k}" );
			$result = $this->drive( $id );
			if ( TickResult::COMPLETED !== $result->status ) {
				$failed[ $k ] = sprintf( 'after write %d (step %s): %s', $k, $k > 0 ? $cursors[ $k - 1 ][0] : '-', (string) $this->repo->find( $id )->last_error );
				continue;
			}
			if ( $reference !== $this->digest() ) {
				$failed[ $k ] = sprintf( 'after write %d (step %s): the archive differs', $k, $k > 0 ? $cursors[ $k - 1 ][0] : '-' );
			}
		}
		$this->assertSame( array(), $failed, "crash points that did not replay to the reference archive:\n" . implode( "\n", $failed ) );
	}
}
