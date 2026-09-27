<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Archive\ArchiveVerifier;
use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\FileStagingStep;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Jobs\RestoreFilesPreflightStep;
use WPCheckpoint\Jobs\RestoreJob;
use WPCheckpoint\Jobs\RestoreVerifyStep;
use WPCheckpoint\Jobs\Step;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Restore\StageModes;
use WPCheckpoint\Tests\Fixtures\Archive\ArchiveBuilder;
use WPCheckpoint\Tests\Fixtures\Archive\EntryMode;
use WPCheckpoint\Tests\Fixtures\Restore\RestoreTestCase;

/**
 * The backup's files written next to the site, as the backup holds them and with this site's modes; this
 * plugin's running copy in place of any of the backup's; links and other special files reported, never
 * made; a hash mismatch named by its cause; and a staged tree that is the same however often the staging
 * was interrupted.
 */
final class RestoreStagingTest extends RestoreTestCase {

	/** @var string Random bytes of a file of three content chunks (stored). */
	private static $big;

	/**
	 * The backup's files: every group, a stored file of three chunks, a deflated one of three.
	 *
	 * @return array<string, string>
	 */
	private static function files(): array {
		if ( null === self::$big ) {
			self::$big = random_bytes( 2621440 );
		}
		return array(
			'wp-content/plugins/demo/demo.php'  => "<?php\n/* Plugin Name: Demo */\n",
			'wp-content/themes/t/style.css'     => '/* Theme Name: T */',
			'wp-content/uploads/2026/09/a.txt'  => 'a',
			'wp-content/uploads/big.bin'        => self::$big,
			'wp-content/uploads/zeros.bin'      => str_repeat( '0', 2621440 ),
			'wp-content/languages/de_DE.mo'     => 'mo',
			'wp-content/uploads/2026/09/empty'  => '',
		);
	}

	/**
	 * A backup of the site's own tables and these files (volumes in the backups directory).
	 *
	 * @param array<string, string> $files   Files.
	 * @param array<string, mixed>  $options ArchiveBuilder options.
	 */
	private function with( array $files, array $options = array() ): string {
		return $this->backup( self::site_tables(), null, null, array( 'files' => $files ) + $options );
	}

	/**
	 * The backup's volumes in the backups directory.
	 *
	 * @return string[]
	 */
	private static function volumes(): array {
		return glob( Plugin::instance()->directories()->backups() . '/' . ArchiveBuilder::BASE . '*.zip' );
	}

	/**
	 * Give an entry of the backup another Unix mode, in whichever volume holds it.
	 */
	private static function mode( string $path, int $mode ): void {
		foreach ( self::volumes() as $volume ) {
			try {
				EntryMode::set( $volume, ArchiveVerifier::FILES_PREFIX . $path, $mode );
				return;
			} catch ( \RuntimeException $e ) {
				continue;
			}
		}
		throw new \RuntimeException( 'No volume holds ' . $path );
	}

	/**
	 * The restore's steps with these in place of the ones of the same id.
	 */
	private function type_with( Step ...$replacements ): string {
		$steps = Plugin::instance()->job_types()->get( RestoreJob::ID )->steps();
		foreach ( $replacements as $replacement ) {
			foreach ( $steps as $i => $step ) {
				if ( $step->id() === $replacement->id() ) {
					$steps[ $i ] = $replacement;
				}
			}
		}
		$type = 'restore_staging_' . bin2hex( random_bytes( 3 ) );
		$this->register( $type, $steps );
		return $type;
	}

	private function job_of_type( string $type, string $base ): Job {
		return Plugin::instance()->jobs()->create( $type, self::$admin_id, array(), array( 'base' => $base ) );
	}

	/**
	 * Every staged group's tree: path => [type, content hash, mode, modification time].
	 *
	 * @return array<string, array<string, array<int, mixed>>>
	 */
	private function tree( Job $job ): array {
		$work    = $this->work( $job );
		$staging = RestoreFilesPreflightStep::staging( $work );
		$layout  = RestoreFilesPreflightStep::layout_of( $staging, $job );
		$out     = array();
		foreach ( (array) $staging['staged'] as $group ) {
			$out[ $group ] = self::walk( $layout->stage_dir( (string) $group ), '' );
		}
		return $out;
	}

	/**
	 * A directory's tree.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	private static function walk( string $dir, string $prefix ): array {
		$out     = array();
		$entries = scandir( $dir );
		sort( $entries );
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			$stat = lstat( $path );
			$type = $stat['mode'] & 0170000;
			if ( 0040000 === $type ) {
				$out[ $prefix . $entry . '/' ] = array( 'd', null, $stat['mode'] & 0777, null );
				$out                          += self::walk( $path, $prefix . $entry . '/' );
			} else {
				$out[ $prefix . $entry ] = array( 0100000 === $type ? 'f' : sprintf( '%o', $type ), 0100000 === $type ? hash_file( 'sha256', $path ) : null, $stat['mode'] & 0777, $stat['mtime'] );
			}
		}
		return $out;
	}

	/**
	 * The report's lines.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function report( Job $job ): array {
		$path = RestoreFiles::path( $this->work( $job ), RestoreFiles::REPORT );
		return array_map(
			static function ( string $line ): array {
				return json_decode( $line, true );
			},
			is_file( $path ) ? array_values( array_filter( explode( "\n", (string) file_get_contents( $path ) ) ) ) : array()
		);
	}

	private static function staged_path( Job $job, string $group, string $relative ): string {
		$staging = RestoreFilesPreflightStep::staging( Residue::work_dir( $job->storage_path, $job->id ) );
		return RestoreFilesPreflightStep::layout_of( $staging, $job )->stage_dir( $group ) . '/' . $relative;
	}

	public function test_the_files_are_staged_as_the_backup_holds_them_with_this_sites_modes(): void {
		$files = self::files();
		$job   = $this->run_restore( $this->start_restore( $this->with( $files ) ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$tree = $this->tree( $job );
		foreach ( $files as $path => $content ) {
			$parts    = explode( '/', $path );
			$group    = in_array( $parts[1], array( 'plugins', 'themes', 'uploads', 'mu-plugins' ), true ) ? $parts[1] : 'other-content';
			$relative = implode( '/', array_slice( $parts, 'other-content' === $group ? 1 : 2 ) );
			$this->assertArrayHasKey( $relative, $tree[ $group ], $path );
			$this->assertSame( array( 'f', hash( 'sha256', $content ), StageModes::file() & 0777, ArchiveBuilder::MTIME ), $tree[ $group ][ $relative ], $path );
		}
		$this->assertSame( array( 'd', null, StageModes::dir() & 0777, null ), $tree['uploads']['2026/'], 'a directory: this site\'s mode' );
		// The running copy of this plugin, under its own name, with its files' times.
		$this->assertSame( array( 'f', hash_file( 'sha256', $this->plugin_copy . '/src/Thing.php' ), StageModes::file() & 0777, 1700000100 ), $tree['plugins']['wp-checkpoint/src/Thing.php'] );
		$this->assertArrayHasKey( 'wp-checkpoint/wp-checkpoint.php', $tree['plugins'] );
		// The roots are closed to the web while the files are there.
		$staging = RestoreFilesPreflightStep::staging( $this->work( $job ) );
		$root    = RestoreFilesPreflightStep::layout_of( $staging, $job )->root( 'uploads' );
		$this->assertFileExists( $root . '/.htaccess' );
		$this->assertFileExists( $root . '/index.php' );
	}

	public function test_files_across_several_volumes_are_walked_after_the_database(): void {
		$files = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$files[ 'wp-content/uploads/v/' . $i . '.bin' ] = random_bytes( 60000 );
		}
		$base = $this->with( $files, array( 'volume_bytes' => 200000 ) );
		$this->assertGreaterThan( 2, count( self::volumes() ), 'the control: several volumes' );
		$job = $this->run_restore( $this->start_restore( $base ), true );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$tree = $this->tree( $job );
		foreach ( $files as $path => $content ) {
			$this->assertSame( hash( 'sha256', $content ), $tree['uploads'][ substr( $path, strlen( 'wp-content/uploads/' ) ) ][1], $path );
		}
	}

	public function test_this_plugin_is_staged_only_as_the_running_copy_and_every_other_copy_is_reported(): void {
		$files = array(
			'wp-content/plugins/wp-checkpoint-old/wp-checkpoint.php' => "<?php\n/**\n * Plugin Name: WP Checkpoint\n * Text Domain: wp-checkpoint\n */\n",
			'wp-content/plugins/wp-checkpoint-old/src/Old.php'        => "<?php\n",
			'wp-content/plugins/renamed/main.php'                     => "<?php\ndefine( 'WPCHECKPOINT_VERSION', '0.0.1' );\n",
			'wp-content/plugins/wp-checkpoint/other.php'              => "<?php\n/* Plugin Name: Something Else */\n",
			'wp-content/plugins/wp-checkpoint/readme.txt'             => 'not this plugin',
			'wp-content/plugins/demo/demo.php'                        => "<?php\n",
		);
		$job   = $this->run_restore( $this->start_restore( $this->with( $files ) ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$plugins = array_keys( $this->tree( $job )['plugins'] );
		$this->assertSame( array( 'demo/', 'demo/demo.php', 'wp-checkpoint/', 'wp-checkpoint/readme.txt', 'wp-checkpoint/src/', 'wp-checkpoint/src/Thing.php', 'wp-checkpoint/wp-checkpoint.php' ), $plugins, 'one copy of this plugin, the running one' );
		$this->assertSame( "=== WP Checkpoint (test stand-in) ===\n", file_get_contents( self::staged_path( $job, 'plugins', 'wp-checkpoint/readme.txt' ) ), 'the running copy\'s readme, not the backup\'s' );
		$report = $this->report( $job );
		$this->assertSame(
			array(
				array( 'plugin_self', 'wp-content/plugins/wp-checkpoint-old', 'plugin header' ),
				array( 'plugin_self', 'wp-content/plugins/renamed', 'constant' ),
				array( 'plugin_name_taken', 'wp-content/plugins/wp-checkpoint', null ),
			),
			array_map(
				static function ( array $item ): array {
					return array( $item['kind'], $item['p'], $item['basis'] ?? null );
				},
				$report
			)
		);
		$this->assertStringContainsString( 'but it is not WP Checkpoint: it is left out', $report[2]['why'] );
		$this->assertStringContainsString( 'Entries of the backup that were not staged, and why', (string) file_get_contents( $job->storage_path . '/' . $job->log_path ) );
	}

	public function test_links_and_other_special_files_are_reported_and_never_made(): void {
		$files = array(
			'wp-content/uploads/link'   => '../../wp-config.php',
			'wp-content/uploads/fifo'   => '',
			'wp-content/uploads/char'   => '',
			'wp-content/uploads/block'  => '',
			'wp-content/uploads/socket' => '',
			'wp-content/uploads/dos'    => 'an MS-DOS creator: a regular file',
			'wp-content/uploads/plain'  => 'plain',
		);
		$base = $this->with( $files );
		foreach ( array( 'link' => 0120777, 'fifo' => 0010644, 'char' => 0020644, 'block' => 0060644, 'socket' => 0140755 ) as $name => $mode ) {
			self::mode( 'wp-content/uploads/' . $name, $mode );
		}
		foreach ( self::volumes() as $volume ) {
			try {
				EntryMode::set( $volume, ArchiveVerifier::FILES_PREFIX . 'wp-content/uploads/dos', 0120777, 0 );
			} catch ( \RuntimeException $e ) {
				continue;
			}
		}
		$job = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$tree = $this->tree( $job )['uploads'];
		$this->assertSame( array( 'dos', 'plain' ), array_keys( $tree ), 'nothing else was made' );
		$this->assertSame( 'f', $tree['dos'][0] );
		$this->assertSame(
			array(
				'wp-content/uploads/link'   => 'symbolic link',
				'wp-content/uploads/fifo'   => 'FIFO',
				'wp-content/uploads/char'   => 'character device',
				'wp-content/uploads/block'  => 'block device',
				'wp-content/uploads/socket' => 'socket',
			),
			array_column( $this->report( $job ), 'type', 'p' )
		);
	}

	public function test_a_hash_mismatch_is_named_by_its_cause_and_leaves_no_file(): void {
		$files = self::files();
		$base  = $this->with( $files );
		$flip  = static function ( string $piece ): string {
			if ( '' !== $piece ) {
				$piece[0] = chr( ord( $piece[0] ) ^ 1 );
			}
			return $piece;
		};
		// The control: where the file goes when nothing is wrong.
		$clean = $this->run_restore( $this->start_restore( $base ) );
		$this->assertFileExists( self::staged_path( $clean, 'uploads', '2026/09/a.txt' ) );

		// The backup file changed since the check.
		$volumes = self::volumes();
		$touched = false;
		$changed = $this->type_with(
			new FileStagingStep(
				$this->staging_parts(
					array(
						'read' => static function ( string $piece ) use ( $flip, $volumes, &$touched ): string {
							if ( ! $touched ) {
								$touched = true;
								foreach ( $volumes as $volume ) {
									touch( $volume, time() + 3600 );
								}
							}
							return $flip( $piece );
						},
					)
				)
			)
		);
		$job = $this->run_restore( $this->job_of_type( $changed, $base ) );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'was changed while it was being restored', (string) $job->last_error );
		$this->assertSame( Job::FAILURE_FINAL, $job->failure_kind );
		$this->assertStaged( $job, false );
		foreach ( $volumes as $volume ) {
			touch( $volume, ArchiveBuilder::MTIME );
		}

		// Unchanged, and checked in full before: it read differently this time.
		$full = $this->type_with(
			new RestoreVerifyStep(
				static function (): string {
					return Plugin::instance()->directories()->backups();
				},
				static function ( string $text ): string {
					return $text;
				},
				ArchiveVerifier::DEPTH_FULL
			),
			new FileStagingStep( $this->staging_parts( array( 'read' => $flip ) ) )
		);
		$job = $this->run_restore( $this->job_of_type( $full, $base ) );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'could not be read the same way twice', (string) $job->last_error );
		$this->assertNotSame( Job::FAILURE_FINAL, $job->failure_kind, 'the storage may read right next time' );
		$this->assertStaged( $job, false );

		// Unchanged, never read in full: damaged.
		$damaged = $this->with(
			$files,
			array(
				'files_lines' => static function ( array $lines ): array {
					foreach ( $lines as $i => $line ) {
						if ( 'wp-content/uploads/2026/09/a.txt' === $line['p'] ) {
							$lines[ $i ]['h'] = hash( 'sha256', 'b' );
						}
					}
					return $lines;
				},
			)
		);
		$job = $this->run_restore( $this->start_restore( $damaged ) );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'The backup is damaged: wp-content/uploads/2026/09/a.txt', (string) $job->last_error );
		$this->assertSame( Job::FAILURE_FINAL, $job->failure_kind );
		$this->assertStaged( $job, false );
	}

	public function test_bytes_that_fail_the_archives_own_check_are_named_the_same_way(): void {
		$files = self::files();
		$base  = $this->with( $files );
		// One byte of the small file changed in the volume, which keeps its size and modification time: the
		// zip's CRC fails before any hash is compared.
		foreach ( self::volumes() as $volume ) {
			$entry = \WPCheckpoint\Archive\ZipReader::open( $volume )->find( ArchiveVerifier::FILES_PREFIX . 'wp-content/uploads/2026/09/a.txt' );
			if ( null === $entry ) {
				continue;
			}
			$this->assertSame( \WPCheckpoint\Archive\ZipFormat::METHOD_STORE, (int) $entry['method'], 'the control: stored, so the byte is the content' );
			$mtime  = filemtime( $volume );
			$handle = fopen( $volume, 'r+b' );
			fseek( $handle, (int) $entry['offset'] + 26 );
			$lengths = unpack( 'vname/vextra', (string) fread( $handle, 4 ) );
			fseek( $handle, (int) $entry['offset'] + 30 + $lengths['name'] + $lengths['extra'] );
			fwrite( $handle, 'b' );
			fclose( $handle );
			touch( $volume, $mtime );
			clearstatcache();
		}
		$job = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'The backup is damaged: wp-content/uploads/2026/09/a.txt', (string) $job->last_error );
		$this->assertStringContainsString( 'CRC mismatch', (string) $job->last_error );
		$this->assertSame( Job::FAILURE_FINAL, $job->failure_kind );
		$this->assertStaged( $job, false );
	}

	/**
	 * Whether the small file is staged.
	 */
	private function assertStaged( Job $job, bool $expected ): void {
		$this->assertSame( $expected, file_exists( self::staged_path( $job, 'uploads', '2026/09/a.txt' ) ), 'the file that did not match is not left staged' );
	}

	public function test_a_full_disk_is_said_so_and_another_write_failure_is_not_taken_for_one(): void {
		$base = $this->with( self::files() );
		foreach ( array( 0.0 => 'is full: the restore could not write its staged files there', 1e12 => 'could not be written' ) as $free => $text ) {
			$type = $this->type_with(
				new FileStagingStep(
					$this->staging_parts(
						array(
							'write' => static function () {
								return false;
							},
							'free'  => static function () use ( $free ) {
								return (float) $free;
							},
						)
					)
				)
			);
			$job = $this->run_restore( $this->job_of_type( $type, $base ) );
			$this->assertSame( Job::FAILED, $job->status );
			$this->assertStringContainsString( $text, (string) $job->last_error );
			$this->assertNotSame( Job::FAILURE_FINAL, $job->failure_kind, 'retried once there is room' );
		}
	}

	public function test_an_interrupted_staging_ends_in_the_same_tree_as_one_that_ran_through(): void {
		$files                                             = self::files();
		$files['wp-content/uploads/l']                     = 'target';
		$files['wp-content/plugins/renamed/main.php']      = "<?php\ndefine( 'WPCHECKPOINT_VERSION', '0.0.1' );\n";
		$base                          = $this->with( $files );
		self::mode( 'wp-content/uploads/l', 0120777 );
		$clean = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::COMPLETED, $clean->status, (string) $clean->last_error );
		$want = $this->tree( $clean );
		$this->assertCount( 2, $this->report( $clean ), 'a copy of this plugin and a link' );

		foreach ( array( 'roots', 'identified', 'dir', 'piece', 'complete', 'touched', 'report', 'plugin_list', 'plugin_piece' ) as $point ) {
			$hit  = 0;
			$type = $this->type_with(
				new FileStagingStep(
					$this->staging_parts(
						array(
							'at' => static function ( string $at ) use ( $point, &$hit ): void {
								if ( $at === $point && 1 === ++$hit ) {
									throw new \RuntimeException( 'simulated: the run is killed here (' . $point . ')' );
								}
							},
						)
					)
				)
			);
			$job = $this->run_restore( $this->job_of_type( $type, $base ) );
			$this->assertSame( Job::FAILED, $job->status, $point );
			// The point's name may be redacted in the error (the test database's user is "root").
			$this->assertStringContainsString( 'simulated: the run is killed here', (string) $job->last_error, 'the control: killed there' );
			$this->assertSame( 1, $hit, 'the control: at ' . $point );
			Plugin::instance()->job_actions()->retry( $job->id );
			$job = $this->run_restore( $job );
			$this->assertSame( Job::COMPLETED, $job->status, $point . ': ' . $job->last_error );
			$this->assertSame( $want, $this->tree( $job ), $point . ': the same tree, times included' );
			$this->assertCount( 2, $this->report( $job ), $point . ': the report once' );
		}
	}

	public function test_reading_the_plugins_heads_counts_toward_a_unit(): void {
		// Three plugins whose main files are large and deflate well: each head read inflates the whole entry.
		$files = array();
		foreach ( array( 'one', 'two', 'three' ) as $name ) {
			$files[ 'wp-content/plugins/' . $name . '/' . $name . '.php' ] = "<?php\n/* Plugin Name: " . $name . " */\n" . str_repeat( '// padding padding padding' . "\n", 30000 );
		}
		$job    = $this->start_restore( $this->with( $files ) );
		$runner = new Runner( Plugin::instance()->jobs(), Plugin::instance()->job_types(), new Redactor( Redactor::installation_secrets() ), array( 'budget' => new Budget( 20, 32 * 1048576, false ) ) );
		$phases = array();
		for ( $i = 0; $i < 5000; $i++ ) {
			$now = Plugin::instance()->jobs()->find( $job->id );
			if ( FileStagingStep::ID === $now->step ) {
				$phases[] = (string) ( $now->cursor['phase'] ?? '' );
			}
			if ( ! in_array( $now->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				break;
			}
			$runner->tick( $job->id, microtime( true ) - 3600 ); // No time left: one unit per tick.
		}
		$GLOBALS['wpdb']->query( 'COMMIT' );
		$this->assertSame( Job::COMPLETED, $now->status, (string) $now->last_error );
		$this->assertGreaterThanOrEqual( 2, count( array_keys( $phases, 'identify', true ) ), 'the heads took more than one unit: a page is bounded by what it reads, not by the index alone' );
	}

	public function test_a_directory_named_like_this_plugin_in_another_case_is_left_out(): void {
		$files = array(
			'wp-content/plugins/WP-Checkpoint/other.php' => "<?php\n/* Plugin Name: Something Else */\n",
			'wp-content/plugins/demo/demo.php'          => "<?php\n",
		);
		$job = $this->run_restore( $this->start_restore( $this->with( $files ) ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$this->assertArrayNotHasKey( 'WP-Checkpoint/other.php', $this->tree( $job )['plugins'], 'a file system that folds case would put it into the running copy' );
		$this->assertArrayHasKey( 'wp-checkpoint/wp-checkpoint.php', $this->tree( $job )['plugins'], 'the control: the running copy' );
		$this->assertSame( array( array( 'plugin_name_taken', 'wp-content/plugins/WP-Checkpoint' ) ), array_map( static function ( array $item ): array { return array( $item['kind'], $item['p'] ); }, $this->report( $job ) ) );
	}

	/**
	 * @requires OS Linux|Darwin
	 */
	public function test_links_in_the_running_plugin_are_never_followed(): void {
		$outside = sys_get_temp_dir() . '/wpc-outside-' . bin2hex( random_bytes( 3 ) );
		mkdir( $outside . '/dir', 0700, true );
		file_put_contents( $outside . '/secret.txt', 'secret' );
		file_put_contents( $outside . '/dir/inside.txt', 'inside' );
		symlink( $outside . '/secret.txt', $this->plugin_copy . '/linked.txt' );
		symlink( $outside . '/dir', $this->plugin_copy . '/linked-dir' );
		try {
			$base = $this->with( array( 'wp-content/plugins/demo/demo.php' => "<?php\n" ) );
			$job  = $this->run_restore( $this->start_restore( $base ) );
			$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
			$staged = array_keys( $this->tree( $job )['plugins'] );
			$this->assertNotContains( 'wp-checkpoint/linked.txt', $staged );
			$this->assertNotContains( 'wp-checkpoint/linked-dir/', $staged );
			$this->assertContains( 'wp-checkpoint/readme.txt', $staged, 'the control: the plugin\'s own files are copied' );

			// A file of the list replaced by a link once the list was made: refused, not followed.
			$copy = $this->plugin_copy;
			$type = $this->type_with(
				new FileStagingStep(
					$this->staging_parts(
						array(
							'at' => static function ( string $point ) use ( $copy, $outside ): void {
								if ( 'plugin_list' === $point ) {
									unlink( $copy . '/readme.txt' );
									symlink( $outside . '/secret.txt', $copy . '/readme.txt' );
								}
							},
						)
					)
				)
			);
			$job = $this->run_restore( $this->job_of_type( $type, $base ) );
			$this->assertSame( Job::FAILED, $job->status );
			$this->assertStringContainsString( 'cannot be read as it was listed (readme.txt)', (string) $job->last_error );
			$this->assertNotContains( 'wp-checkpoint/readme.txt', array_keys( $this->tree( $job )['plugins'] ), 'what the link points to was not copied' );
		} finally {
			@unlink( $this->plugin_copy . '/linked.txt' );
			@unlink( $this->plugin_copy . '/linked-dir' );
			@unlink( $this->plugin_copy . '/readme.txt' );
			file_put_contents( $this->plugin_copy . '/readme.txt', "=== WP Checkpoint (test stand-in) ===\n" );
			exec( 'rm -rf ' . escapeshellarg( $outside ) );
		}
	}

	public function test_a_range_that_reads_wrong_once_and_right_again_is_the_servers_storage(): void {
		$base  = $this->with( self::files() );
		$reads = 0;
		$type  = $this->type_with(
			new FileStagingStep(
				$this->staging_parts(
					array(
						'read' => static function ( string $piece ) use ( &$reads ): string {
							if ( 1 === ++$reads && '' !== $piece ) {
								$piece[0] = chr( ord( $piece[0] ) ^ 1 ); // The first read only.
							}
							return $piece;
						},
					)
				)
			)
		);
		$job = $this->run_restore( $this->job_of_type( $type, $base ) );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'read wrong once', (string) $job->last_error );
		$this->assertStringContainsString( 'and right when it was read again', (string) $job->last_error );
		$this->assertNotSame( Job::FAILURE_FINAL, $job->failure_kind, 'not called damaged: the backup may well be intact' );
	}

	public function test_a_staging_interrupted_in_the_middle_of_a_file_resumes_it_to_the_same_tree(): void {
		$base  = $this->with( self::files() );
		$clean = $this->run_restore( $this->start_restore( $base ), true );
		$want  = $this->tree( $clean );
		foreach ( array( 'piece' => 3, 'plugin_piece' => 2 ) as $point => $at_hit ) {
			$hit  = 0;
			$type = $this->type_with(
				new FileStagingStep(
					$this->staging_parts(
						array(
							'at' => static function ( string $at ) use ( $point, $at_hit, &$hit ): void {
								if ( $at === $point && $at_hit === ++$hit ) {
									throw new \RuntimeException( 'simulated: the run is killed here' );
								}
							},
						)
					)
				)
			);
			// Short ticks: each commits its units, so the death comes after a committed part of the file.
			$job = $this->run_restore( $this->job_of_type( $type, $base ), true );
			$this->assertSame( Job::FAILED, $job->status, $point );
			$this->assertSame( $at_hit, $hit, 'the control: at ' . $point );
			Plugin::instance()->job_actions()->retry( $job->id );
			$job = $this->run_restore( $job, true );
			$this->assertSame( Job::COMPLETED, $job->status, $point . ': ' . $job->last_error );
			$this->assertSame( $want, $this->tree( $job ), $point );
		}
	}

	/**
	 * @requires OS Linux|Darwin
	 */
	public function test_a_staging_root_replaced_by_a_link_after_it_was_made_is_never_written_through(): void {
		$base    = $this->with( array( 'wp-content/uploads/a.txt' => 'a' ) );
		$outside = sys_get_temp_dir() . '/wpc-elsewhere-' . bin2hex( random_bytes( 3 ) );
		$roots   = array();
		$type    = $this->type_with(
			new FileStagingStep(
				$this->staging_parts(
					array(
						'at' => function ( string $point ) use ( $outside, &$roots ): void {
							if ( 'identified' !== $point ) {
								return;
							}
							// Every root moved aside, a link to a copy of its tree in its place.
							foreach ( glob( WP_CONTENT_DIR . '/wp-checkpoint-stage-*' ) as $root ) {
								exec( 'cp -a ' . escapeshellarg( $root ) . ' ' . escapeshellarg( $outside ) );
								exec( 'rm -rf ' . escapeshellarg( $root ) );
								symlink( $outside, $root );
								$roots[] = $root;
							}
						},
					)
				)
			)
		);
		try {
			$job = $this->run_restore( $this->job_of_type( $type, $base ) );
			$this->assertNotSame( array(), $roots, 'the control: a root was replaced' );
			$this->assertSame( Job::FAILED, $job->status );
			$this->assertStringContainsString( '(the staging directory) is not a directory (or is a link to one)', (string) $job->last_error );
			$this->assertFileDoesNotExist( $outside . '/uploads/a.txt', 'nothing written where the link points' );
		} finally {
			foreach ( $roots as $root ) {
				@unlink( $root );
			}
			exec( 'rm -rf ' . escapeshellarg( $outside ) );
		}
	}

	public function test_the_spellings_of_this_plugins_name_are_bounded(): void {
		// Spellings of the running copy's name that a case-folding file system takes as one: at most MAX_SELF.
		$spellings = function ( int $count ): array {
			$out = array();
			for ( $mask = 0; count( $out ) < $count; $mask++ ) {
				$name = '';
				foreach ( str_split( 'wp-checkpoint' ) as $n => $char ) {
					$name .= ( $mask >> $n ) & 1 ? strtoupper( $char ) : $char;
				}
				$out[ $name ] = true;
			}
			return array_keys( $out );
		};
		foreach ( array( FileStagingStep::MAX_SELF => Job::COMPLETED, FileStagingStep::MAX_SELF + 1 => Job::FAILED ) as $count => $status ) {
			$files = array();
			foreach ( $spellings( $count ) as $name ) {
				if ( 'wp-checkpoint' !== $name ) {
					$files[ 'wp-content/plugins/' . $name . '/x.txt' ] = 'x';
				}
			}
			$files['wp-content/plugins/wp-checkpoint/x.txt'] = 'x';
			$job = $this->run_restore( $this->start_restore( $this->with( $files ) ) );
			$this->assertSame( $status, $job->status, $count . ': ' . $job->last_error );
			if ( Job::FAILED === $status ) {
				$this->assertStringContainsString( sprintf( 'more than %d plugin directories named like WP Checkpoint', FileStagingStep::MAX_SELF ), (string) $job->last_error );
			} else {
				$this->assertCount( FileStagingStep::MAX_SELF, $this->report( $job ), 'each spelling reported' );
			}
		}
	}

	/**
	 * @requires OS Linux|Darwin
	 */
	public function test_a_plugin_file_that_changed_after_it_was_listed_is_refused(): void {
		$base    = $this->with( array( 'wp-content/plugins/demo/demo.php' => "<?php\n" ) );
		$copy    = $this->plugin_copy;
		$outside = sys_get_temp_dir() . '/wpc-src-' . bin2hex( random_bytes( 3 ) );
		mkdir( $outside );
		file_put_contents( $outside . '/Thing.php', "<?php\n// A class file of the stand-in.\n" ); // The same bytes, elsewhere.
		$changes = array(
			'grew'          => static function () use ( $copy ): void {
				file_put_contents( $copy . '/readme.txt', 'more', FILE_APPEND );
			},
			'dir to a link' => static function () use ( $copy, $outside ): void {
				rename( $copy . '/src', $copy . '/src-moved' );
				symlink( $outside, $copy . '/src' );
			},
		);
		try {
			foreach ( $changes as $what => $change ) {
				$type = $this->type_with(
					new FileStagingStep(
						$this->staging_parts(
							array(
								'at' => static function ( string $point ) use ( $change ): void {
									if ( 'plugin_list' === $point ) {
										$change();
									}
								},
							)
						)
					)
				);
				$job = $this->run_restore( $this->job_of_type( $type, $base ) );
				$this->assertSame( Job::FAILED, $job->status, $what );
				$this->assertStringContainsString( 'cannot be read as it was listed', (string) $job->last_error, $what );
				// Put the stand-in back for the next change.
				if ( is_link( $copy . '/src' ) ) {
					unlink( $copy . '/src' );
					rename( $copy . '/src-moved', $copy . '/src' );
				}
				file_put_contents( $copy . '/readme.txt', "=== WP Checkpoint (test stand-in) ===\n" );
			}
			// The control: nothing changed, the copy goes through.
			$this->assertSame( Job::COMPLETED, $this->run_restore( $this->start_restore( $base ) )->status );
		} finally {
			exec( 'rm -rf ' . escapeshellarg( $outside ) );
		}
	}

	/**
	 * @requires OS Linux|Darwin
	 */
	public function test_a_replay_replaces_links_planted_at_the_roots_protection_files(): void {
		$base    = $this->with( array( 'wp-content/uploads/a.txt' => 'a' ) );
		$outside = sys_get_temp_dir() . '/wpc-protect-' . bin2hex( random_bytes( 3 ) );
		mkdir( $outside );
		file_put_contents( $outside . '/index.php', 'original index' );
		file_put_contents( $outside . '/.htaccess', 'original htaccess' );
		$hit  = 0;
		$type = $this->type_with(
			new FileStagingStep(
				$this->staging_parts(
					array(
						'at' => static function ( string $point ) use ( &$hit ): void {
							if ( 'roots' === $point && 1 === ++$hit ) {
								throw new \RuntimeException( 'simulated: the run is killed here' );
							}
						},
					)
				)
			)
		);
		try {
			$job = $this->run_restore( $this->job_of_type( $type, $base ) );
			$this->assertSame( Job::FAILED, $job->status );
			$roots = glob( WP_CONTENT_DIR . '/wp-checkpoint-stage-*' );
			$this->assertCount( 1, $roots, 'the control: the root was made before the death' );
			foreach ( array( 'index.php', '.htaccess' ) as $name ) {
				symlink( $outside . '/' . $name, $roots[0] . '/' . $name );
			}
			Plugin::instance()->job_actions()->retry( $job->id );
			$job = $this->run_restore( $job );
			$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
			$this->assertSame( \WPCheckpoint\Support\Protection::htaccess(), file_get_contents( $roots[0] . '/.htaccess' ) );
			$this->assertSame( \WPCheckpoint\Support\Protection::INDEX_PHP, file_get_contents( $roots[0] . '/index.php' ) );
			foreach ( array( 'index.php', '.htaccess' ) as $name ) {
				$this->assertFalse( is_link( $roots[0] . '/' . $name ), $name . ': the link was replaced by a file' );
			}
			$this->assertSame( 'original index', file_get_contents( $outside . '/index.php' ), 'what the links pointed to is untouched' );
			$this->assertSame( 'original htaccess', file_get_contents( $outside . '/.htaccess' ) );
		} finally {
			exec( 'rm -rf ' . escapeshellarg( $outside ) );
		}
	}
}
