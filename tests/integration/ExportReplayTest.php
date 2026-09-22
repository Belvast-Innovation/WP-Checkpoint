<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Database\WpdbConnection;
use WPCheckpoint\Files\PathKey;
use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\DatabaseExportStep;
use WPCheckpoint\Jobs\FileScanStep;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobContext;
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

	const COVERAGE_LOST = 'The fixture no longer covers this path: its sizes (files, rows, volume and chunk sizes, clock) changed and the replay lost its coverage. Adjust the fixture so the path is exercised again.';

	/** @var int Volume size of this run. */
	private $volume = 2097152;

	/** @var int Tick number of the run in progress (for the coverage checks). */
	private $tick_no = 0;

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
			'volume_bytes'       => $this->volume,
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
			++$this->tick_no;
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

	/**
	 * Two shapes: small volumes (several seals, one of them in the middle of a tick with entries the
	 * previous cursor write did not have), and one large volume (a site under the volume size: the lone
	 * volume is renamed to the single name when the archive is finished).
	 *
	 * @return array<string, array{0: int, 1: string}>
	 */
	public function shapes(): array {
		return array(
			'several volumes' => array( 2097152, 'multi' ),
			'one volume'      => array( 1073741824, 'single' ),
		);
	}

	/**
	 * What the reference run must have gone through for the replay to prove anything, from its cursor
	 * writes: every step checkpointed (so every step's units are replayed), and per shape the path the
	 * shape exists for. Returns what is missing.
	 *
	 * @param array<int, array{0: string, 1: array<string, mixed>, 2: int}> $cursors Step, cursor and tick of each write.
	 * @param string                                                          $shape   'multi' or 'single'.
	 * @return string[]
	 */
	private function missing_coverage( array $cursors, string $shape ): array {
		$missing = array();
		$steps   = array_values( array_unique( array_column( $cursors, 0 ) ) );
		foreach ( array( PreflightStep::ID, FileScanStep::ID, ReviewStep::ID, DatabaseExportStep::ID, PackStep::ID, ManifestStep::ID, StoreStep::ID ) as $id ) {
			if ( ! in_array( $id, $steps, true ) ) {
				$missing[] = sprintf( 'no cursor write of step "%s"', $id );
			}
		}
		$volume = static function ( array $write ) {
			return $write[1]['packer']['volume'] ?? null;
		};
		$sealed = static function ( array $write ): array {
			return isset( $write[1]['packer']['sealed'] ) && is_array( $write[1]['packer']['sealed'] ) ? $write[1]['packer']['sealed'] : array();
		};
		if ( 'multi' === $shape ) {
			// A seal in the middle of a tick: write j is the checkpoint before the seal, write j+1 records it in the
			// same tick, and write j-1 (the one before, possibly the end of the previous tick) knew fewer entries
			// of that volume than write j: units of this tick added entries before the seal, so a crash between
			// them and the seal leaves entries on disk that the stored cursor does not have.
			$found = false;
			for ( $j = 1; $j + 1 < count( $cursors ) && ! $found; $j++ ) {
				list( $before, $at, $after ) = array( $cursors[ $j - 1 ], $cursors[ $j ], $cursors[ $j + 1 ] );
				if ( PackStep::ID !== $before[0] || PackStep::ID !== $at[0] || PackStep::ID !== $after[0] ) {
					continue;
				}
				if ( $at[2] !== $after[2] ) {
					continue;
				}
				$open = $volume( $at );
				$prev = $volume( $before );
				if ( null === $open || null !== $volume( $after ) || count( $sealed( $after ) ) !== count( $sealed( $at ) ) + 1 ) {
					continue;
				}
				$found = null !== $prev && (int) $prev['index'] === (int) $open['index'] && (int) $open['entries'] > (int) $prev['entries'];
			}
			if ( ! $found ) {
				$missing[] = 'no seal in the middle of a tick with entries the previous cursor write did not have';
			}
		}
		if ( 'single' === $shape ) {
			// The finish that renames the lone volume: write j-1 says "finish", write j records the single name.
			$found = false;
			for ( $j = 1; $j < count( $cursors ) && ! $found; $j++ ) {
				$names = array_column( $sealed( $cursors[ $j ] ), 'path' );
				$found = ManifestStep::ID === $cursors[ $j ][0] && 'finish' === ( $cursors[ $j - 1 ][1]['phase'] ?? '' ) && 'blocks_after' === ( $cursors[ $j ][1]['phase'] ?? '' )
					&& 1 === count( $names ) && 1 === preg_match( '/\A[a-z0-9-]+\.wpcheckpoint\.zip\z/', (string) $names[0] );
			}
			if ( ! $found ) {
				$missing[] = 'no cursor write recording the single-volume rename after the finish phase';
			}
		}
		return $missing;
	}

	/**
	 * @dataProvider shapes
	 */
	public function test_every_crash_between_a_disk_change_and_the_next_cursor_write_replays_to_the_same_archive( int $volume, string $shape ): void {
		$this->volume = $volume;
		$this->register_export();
		$job = $this->repo->create( 'export-replay', 0, array(), $this->options() );
		$id  = $job->id;

		// The uninterrupted run: count the cursor writes and remember every cursor.
		$cursors          = array();
		$this->on_persist = function ( Job $job, string $step, array $cursor ) use ( &$cursors ): void {
			$cursors[] = array( $step, $cursor, $this->tick_no );
		};
		$result           = $this->drive( $id );
		$this->assertSame( TickResult::COMPLETED, $result->status, (string) $this->repo->find( $id )->last_error );
		$reference = $this->digest();
		$this->assertGreaterThanOrEqual( 2, count( $reference ) );
		$manifests = glob( $this->dirs->backups() . '/*.manifest.json' ) ?: array();
		$this->assertCount( 1, $manifests );
		$this->assertStringStartsWith( 'replay-site-20270115-080000-c0de', basename( $manifests[0] ), 'the injected clock and suffix name the archive' );
		$manifest  = Manifest::from_json( (string) file_get_contents( $manifests[0] ) );
		$writes    = count( $cursors );
		$this->assertSame( array( 'wpcreplay_options', 'wpcreplay_posts' ), array_column( $manifest->tables(), 'name' ) );
		if ( 'single' === $shape ) {
			$this->assertCount( 1, $manifest->volumes(), self::COVERAGE_LOST );
			$this->assertStringEndsWith( '-c0de.wpcheckpoint.zip', $manifest->volumes()[0]['path'], self::COVERAGE_LOST );
		} else {
			$this->assertGreaterThanOrEqual( 3, count( $manifest->volumes() ), self::COVERAGE_LOST );
		}
		// Every crash point below is replayed; these are the paths they must include.
		$missing = $this->missing_coverage( $cursors, $shape );
		$this->assertSame( array(), $missing, self::COVERAGE_LOST . "\nMissing:\n" . implode( "\n", $missing ) );

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
			$last             = array();
			$this->on_persist = static function ( Job $job, string $step, array $cursor ) use ( &$count, &$last, $k ): void {
				++$count;
				if ( $count === $k + 1 ) {
					throw new LockLost( 'simulated crash before cursor write ' . $count );
				}
				$last = $cursor;
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
			if ( $k > 0 ) {
				// This run's own write k (the pre-flight cursor carries the live free disk space, so not the reference run's).
				$this->assertSame( wp_json_encode( JobContext::strip_reserved( $last ) ), wp_json_encode( JobContext::strip_reserved( $stored->cursor ) ), "write {$k}: the stored cursor is write {$k}, the disk is ahead of it" );
			}
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
