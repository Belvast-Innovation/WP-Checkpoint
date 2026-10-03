<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\QuestionText;
use WPCheckpoint\Restore\LinkedTargets;
use WPCheckpoint\Files\ScanRoots;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Jobs\RestoreFilesPreflightStep;
use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Jobs\SwapCheckStep;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Restore\NameClashes;
use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Restore\StagingLayout;
use WPCheckpoint\Restore\TargetNames;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Tests\Fixtures\Restore\RestoreTestCase;
use WPCheckpoint\Tests\Fixtures\Sandbox;

/**
 * What stops a restore before any file is staged: paths this site's file
 * system would put on one file, too little free space, a storage directory
 * the swap would move, layouts the swap cannot undo, directories on another
 * disk, paths too long once staged; and a preflight that leaves nothing
 * next to the site and resumes from its committed lengths.
 */
final class RestoreFilesPreflightTest extends RestoreTestCase {

	/** @var string[] Paths a test created. */
	private $made = array();

	public function tear_down(): void {
		foreach ( array_reverse( $this->made ) as $path ) {
			if ( '' === Sandbox::refusal( $path ) ) {
				// A directory of the temporary directory, whole; and what a restore staged next to it, in its parent.
				foreach ( Residue::scan_site( array( dirname( $path ) ), Directories::own_tokens() ) as $entry ) {
					Deleter::delete_tree( $entry['parent'], $entry['path'] );
				}
				Sandbox::remove( $path );
			} elseif ( is_link( $path ) || is_file( $path ) ) {
				@unlink( $path ); // A link it made in the content directory.
			} elseif ( is_dir( $path ) ) {
				@rmdir( $path );
			}
		}
		parent::tear_down();
	}

	/**
	 * A restore type whose files preflight has these parts.
	 */
	private function type( array $parts ): string {
		// The temporary directory, where these tests put the site's directories they make, counts as this site's
		// (a trusted deployment root) unless a test says otherwise: the question about directories outside the site is
		// tested on its own.
		$parts += array(
			'trusted_root' => static function (): string {
				return sys_get_temp_dir();
			},
		);
		$type  = 'restore_files_' . bin2hex( random_bytes( 3 ) );
		$steps = self::restore_steps_with( new RestoreFilesPreflightStep( $parts ) );
		if ( isset( $parts['directories'] ) ) {
			// The final check sees the same site directories the preflight was given.
			foreach ( $steps as $i => $step ) {
				if ( SwapCheckStep::ID === $step->id() ) {
					$steps[ $i ] = new SwapCheckStep( null, $this->check_parts( array( 'site_dirs' => $parts['directories'] ) ) );
				}
			}
		}
		$this->register( $type, $steps );
		return $type;
	}

	/**
	 * A job of a type, for a backup.
	 */
	private function job_for( string $type, string $base, array $options = array() ): Job {
		return Plugin::instance()->jobs()->create( $type, self::$admin_id, array(), array_merge( array( 'base' => $base ), $options ) );
	}

	/**
	 * A backup of the site's own tables with these files.
	 *
	 * @param array<string, string> $files Path => content.
	 */
	private function with_files( array $files, array $options = array() ): string {
		return $this->backup( self::site_tables(), null, null, array( 'files' => $files ) + $options );
	}

	/**
	 * What the probes might have left next to the site (a completed restore leaves its staging roots, for the swap).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function left_next_to_the_site(): array {
		return array_values(
			array_filter(
				Residue::scan_site( Residue::site_dirs( ScanRoots::site_directories() ), Directories::own_tokens() ),
				static function ( array $entry ): bool {
					return Residue::PROBE === $entry['kind'];
				}
			)
		);
	}

	private function dir( string $path ): string {
		if ( ! is_dir( $path ) ) {
			mkdir( $path, 0700, true );
		}
		$this->made[] = $path;
		return $path;
	}

	/**
	 * The job's log.
	 */
	private static function log( Job $job ): string {
		return (string) file_get_contents( $job->storage_path . '/' . $job->log_path );
	}

	/**
	 * A path as a job's error shows it (the content directory masked).
	 */
	private static function shown( string $path ): string {
		return str_replace( rtrim( WP_CONTENT_DIR, '/' ), '{wp-content}', $path );
	}

	/**
	 * The tables the job created.
	 *
	 * @return string[]
	 */
	private static function created( Job $job ): array {
		global $wpdb;
		return (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( TempTables::job_prefix( $job->storage_token, $job->id ) ) . '%' ) );
	}

	/**
	 * A plugin directory of $count empty files and one of $bytes.
	 */
	private function plugin_dir( int $bytes, int $count = 0 ): string {
		$dir = $this->dir( sys_get_temp_dir() . '/wpc-plugin-' . bin2hex( random_bytes( 3 ) ) );
		file_put_contents( $dir . '/big.bin', str_repeat( 'p', $bytes ) );
		$this->made[] = $dir . '/big.bin';
		for ( $i = 0; $i < $count; $i++ ) {
			touch( $dir . '/f' . $i );
			$this->made[] = $dir . '/f' . $i;
		}
		return $dir;
	}

	private static function set( int $id, array $row ): void {
		global $wpdb;
		$wpdb->update( Schema::jobs_table(), $row, array( 'id' => $id ) );
	}

	public function test_a_backup_whose_files_fit_is_checked_and_nothing_is_left_next_to_the_site(): void {
		$mu     = ScanRoots::site_directories()['mu-plugins'];
		$was_mu = is_dir( $mu ) ? scandir( $mu ) : null;
		$base   = $this->with_files(
			array(
				'wp-content/plugins/demo/demo.php'        => '<?php',
				'wp-content/uploads/2026/09/a.txt'        => 'a',
				'wp-content/languages/de_DE.mo'           => 'mo',
				'readme.html'                             => 'outside the content directory',
				'wp-content/wp-checkpoint-stage-x/a.txt'  => 'named like this plugin\'s own',
			)
		);
		$job = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );

		$work    = $this->work( $job );
		$staging = RestoreFilesPreflightStep::staging( $work );
		$this->assertMatchesRegularExpression( StagingLayout::RANDOM_PATTERN, $staging['random'] );
		$this->assertSame( ScanRoots::site_directories(), $staging['groups'] );
		$unmapped = array_map(
			static function ( string $line ): string {
				return json_decode( $line, true )['p'];
			},
			array_filter( explode( "\n", (string) file_get_contents( RestoreFiles::path( $work, RestoreFiles::UNMAPPED ) ) ) )
		);
		$this->assertSame( array( 'readme.html', 'wp-content/wp-checkpoint-stage-x/a.txt' ), array_values( $unmapped ), 'listed, in index order' );
		$this->assertStringContainsString( 'Paths of the backup that belong to no content group are not restored', self::log( $job ) );

		$this->assertSame( array(), self::left_next_to_the_site(), 'no probe left' );
		$this->assertSame( $was_mu, is_dir( $mu ) ? scandir( $mu ) : null, 'the must-use plugins directory as it was' );
		// The control: the scan finds a probe where the preflight made them.
		$probe        = dirname( ScanRoots::site_directories()['plugins'] ) . '/' . StagingLayout::PROBE_PREFIX . $job->storage_token . '-' . $job->id . '-0123456789abcdef';
		$this->made[] = $this->dir( $probe );
		$this->assertCount( 1, self::left_next_to_the_site() );
	}

	public function test_two_paths_this_file_system_folds_together_stop_the_restore_and_are_named(): void {
		$base = $this->with_files(
			array(
				'wp-content/uploads/a.txt'   => 'a',
				'wp-content/uploads/Foo.txt' => 'upper',
				'wp-content/uploads/b.txt'   => 'b',
				'wp-content/uploads/foo.txt' => 'lower',
			)
		);
		$folding = $this->type(
			array(
				'page_lines' => 1, // Each on its own page: the clash is found across units.
				'names'      => static function ( string $parent, TargetNames $probed ): TargetNames {
					unset( $parent, $probed );
					return new TargetNames( true, true, false, false );
				},
			)
		);
		$job = $this->run_restore( $this->job_for( $folding, $base ) );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'The backup holds wp-content/uploads/Foo.txt and wp-content/uploads/foo.txt, which the file system of this site treats as one name', (string) $job->last_error );
		$this->assertSame( array(), self::created( $job ), 'stopped before the import created anything' );

		// The control: this site's own file system keeps them apart.
		$this->assertSame( Job::COMPLETED, $this->run_restore( $this->start_restore( $base ) )->status );
	}

	public function test_a_file_system_that_is_too_full_stops_the_restore_and_one_that_does_not_say_asks(): void {
		$base   = $this->with_files( array( 'wp-content/uploads/a.txt' => str_repeat( 'a', 1000 ) ) );
		$plugin = $this->plugin_dir( 2097152 );
		$full   = $this->type(
			array(
				'plugin_dir' => $plugin,
				'free'       => static function (): int {
					return 1048576;
				},
			)
		);
		$job = $this->run_restore( $this->job_for( $full, $base ) );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'There is not enough free space on the disk of', (string) $job->last_error );
		// 1000 bytes of the backup and 2 MiB of this plugin's copy on the one disk here; the margin is 100 MiB.
		$this->assertStringContainsString( 'the restore stages 3 MB of files there and needs 103 MB free with a margin, and 1 MB are free', (string) $job->last_error );

		$enough = $this->type(
			array(
				'plugin_dir' => $plugin,
				'free'       => static function (): int {
					return 1000 + 2097152 + 104857600;
				},
			)
		);
		$this->assertSame( Job::COMPLETED, $this->run_restore( $this->job_for( $enough, $base ) )->status, 'the control: exactly enough' );

		$unknown = $this->type(
			array(
				'plugin_dir' => $plugin,
				'free'       => static function () {
					return null;
				},
			)
		);
		$job = $this->run_restore( $this->job_for( $unknown, $base ) );
		$this->assertSame( Job::PAUSED, $job->status, (string) $job->last_error );
		$this->assertSame( 'free_space', $job->questions[0]['id'] );
		$this->assertSame( 'free_space_unknown', $job->questions[0]['kind'] );
		$this->assertSame( array( 'continue', 'stop' ), $job->questions[0]['choices'] );
		$this->assertNotNull( Plugin::instance()->job_actions()->answer( $job->id, array( 'free_space' => 'continue' ) ) );
		Plugin::instance()->runner()->tick( $job->id, microtime( true ) ); // Answered: paused, and ticked again.
		$job = $this->run_restore( $job );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$this->assertStringContainsString( 'the restore continues as you chose', self::log( $job ) );

		$job = $this->run_restore( $this->job_for( $unknown, $base ) );
		$this->assertNotNull( Plugin::instance()->job_actions()->answer( $job->id, array( 'free_space' => 'stop' ) ) );
		Plugin::instance()->runner()->tick( $job->id, microtime( true ) );
		$job = $this->run_restore( $job );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'The restore was stopped because the free space for its staged files could not be confirmed.', (string) $job->last_error );
		$this->assertArrayNotHasKey( 'free_space', (array) ( $job->options['answers'] ?? array() ), 'the answer went with the failure' );

		// A retry asks again, rather than stopping for the same reason.
		Plugin::instance()->job_actions()->retry( $job->id );
		$job = $this->run_restore( $job );
		$this->assertSame( Job::PAUSED, $job->status, (string) $job->last_error );
		$this->assertSame( array( 'free_space' ), array_column( $job->questions, 'id' ) );
	}

	public function test_a_storage_directory_the_swap_would_move_stops_the_restore_as_named_and_as_resolved(): void {
		$base    = $this->with_files( array( 'wp-content/uploads/a.txt' => 'a' ) );
		$storage = (string) realpath( Plugin::instance()->directories()->base() );
		$site    = ScanRoots::site_directories();

		// Named through a link inside the uploads directory: moving uploads breaks the name.
		$uploads = $this->dir( WP_CONTENT_DIR . '/wpc-uploads-' . bin2hex( random_bytes( 3 ) ) );
		symlink( $storage, $uploads . '/store' );
		$this->made[] = $uploads . '/store';
		$named        = $this->type(
			array(
				'directories' => static function () use ( $site, $uploads ): array {
					return array_merge( $site, array( 'uploads' => $uploads ) );
				},
			)
		);
		$job = $this->job_for( $named, $base );
		self::set( $job->id, array( 'storage_path' => $uploads . '/store' ) );
		$job = $this->run_restore( $job );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'The storage directory of WP Checkpoint is inside ' . self::shown( $uploads ), (string) $job->last_error );

		// Named elsewhere, a link to a directory inside uploads.
		$link = sys_get_temp_dir() . '/wpc-store-' . bin2hex( random_bytes( 3 ) );
		symlink( $storage, $link );
		$this->made[] = $link;
		$resolved     = $this->type(
			array(
				'directories' => static function () use ( $site, $storage ): array {
					return array_merge( $site, array( 'uploads' => dirname( $storage ) . '/' . basename( $storage ) ) );
				},
			)
		);
		$job = $this->job_for( $resolved, $base );
		self::set( $job->id, array( 'storage_path' => $link ) );
		$job = $this->run_restore( $job );
		$this->assertSame( Job::FAILED, $job->status );
		// The storage directory's own name is redacted in what the job shows (its token is a secret of the installation).
		$this->assertStringContainsString( 'The storage directory of WP Checkpoint is inside {wp-content}/wp-checkpoint-[redacted]', (string) $job->last_error );

		// A top-level entry of the content directory the backup restores, holding the storage directory's name.
		$entry = $this->dir( WP_CONTENT_DIR . '/wpc-private-' . bin2hex( random_bytes( 3 ) ) );
		symlink( $storage, $entry . '/store' );
		$this->made[] = $entry . '/store';
		$with_entry   = $this->with_files( array( 'wp-content/' . basename( $entry ) . '/readme.txt' => 'x' ) );
		$job          = $this->start_restore( $with_entry );
		self::set( $job->id, array( 'storage_path' => $entry . '/store' ) );
		$job = $this->run_restore( $job );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'The storage directory of WP Checkpoint is inside ' . self::shown( $entry ), (string) $job->last_error );

		// The control: the same storage directory, named where it is.
		$job = $this->start_restore( $with_entry );
		$this->assertSame( Job::COMPLETED, $this->run_restore( $job )->status );
	}

	public function test_layouts_the_swap_cannot_undo_are_refused_before_anything_is_probed(): void {
		$base  = $this->with_files( array( 'wp-content/uploads/a.txt' => 'a' ) );
		$site  = ScanRoots::site_directories();
		$cases = array(
			'at the top of its disk'           => array( 'uploads' => '/wpc-uploads-at-the-root' ),
			'are one inside the other'         => array( 'uploads' => $site['plugins'] ),
			'is inside another directory of'   => array( 'uploads' => $site['other-content'] . '/site/uploads' ),
			'is the content directory'         => array( 'plugins' => $site['other-content'] ),
		);
		foreach ( $cases as $why => $dirs ) {
			$type = $this->type(
				array(
					'directories' => static function () use ( $site, $dirs ): array {
						return array_merge( $site, $dirs );
					},
				)
			);
			// Swapped as usual when asked (a directory outside the site, as at the top of a disk, is asked about first:
			// left out, it would not be staged and nothing would be refused).
			$job = $this->run_restore( $this->job_for( $type, $base, array( 'policy' => array( LinkedTargets::POLICY_KEY => LinkedTargets::SWAP ) ) ) );
			$this->assertSame( Job::FAILED, $job->status, $why );
			$this->assertStringContainsString( $why, (string) $job->last_error );
			$this->assertSame( array(), self::left_next_to_the_site(), $why . ': nothing probed' );
		}
	}

	public function test_a_directory_on_another_disk_than_where_it_is_staged_is_refused(): void {
		$site    = ScanRoots::site_directories();
		$entry   = $site['other-content'] . '/languages';
		$base    = $this->with_files(
			array(
				'wp-content/uploads/a.txt'  => 'a',
				'wp-content/languages/a.mo' => 'mo',
			)
		);
		$foreign = function ( string $which ) {
			return function ( string $path ) use ( $which ) {
				if ( $path === $which ) {
					return 424242;
				}
				$stat = @lstat( $path );
				return false === $stat ? null : (int) $stat['dev'];
			};
		};
		foreach ( array( $site['uploads'], $entry ) as $which ) {
			$job = $this->run_restore( $this->job_for( $this->type( array( 'dev' => $foreign( $which ) ) ), $base ) );
			$this->assertSame( Job::FAILED, $job->status, $which );
			$this->assertStringContainsString( 'The directory ' . self::shown( $which ) . ' is on another disk than', (string) $job->last_error );
			$this->assertStringContainsString( 'If it has to stay on its own disk, make your own copy of ' . self::shown( $which ) . ' and restore by hand instead', (string) $job->last_error, 'the second way out' );
		}
		$this->assertSame( Job::COMPLETED, $this->run_restore( $this->job_for( $this->type( array( 'dev' => $foreign( '/nowhere' ) ) ), $base ) )->status, 'the control' );
	}

	public function test_a_path_too_long_once_staged_is_refused_and_one_that_just_fits_is_not(): void {
		$parent = $this->dir( sys_get_temp_dir() . '/wpc-long-' . bin2hex( random_bytes( 3 ) ) );
		$site   = ScanRoots::site_directories();
		$type   = $this->type(
			array(
				'directories' => static function () use ( $site, $parent ): array {
					return array_merge( $site, array( 'uploads' => $parent . '/uploads' ) );
				},
			)
		);
		foreach ( array( 0 => Job::COMPLETED, 1 => Job::FAILED ) as $over => $status ) {
			// The job first: its id is part of the staging root's name, so the path is made to measure.
			$job    = $this->job_for( $type, 'unused' );
			$root   = strlen( StagingLayout::STAGE_PREFIX . $job->storage_token . '-' . $job->id . '-' ) + 32;
			$staged = strlen( (string) realpath( $parent ) ) + 1 + $root + strlen( '/uploads/' );
			$length = StagingLayout::MAX_PATH_BYTES + $over - $staged;
			$parts  = array();
			for ( $left = $length; $left > 0; $left -= 201 ) {
				$parts[] = str_repeat( chr( 97 + count( $parts ) % 26 ), min( 200, $left ) );
			}
			$relative = implode( '/', $parts );
			$this->assertSame( $length, strlen( $relative ) );
			$base = $this->with_files( array( 'wp-content/uploads/' . $relative => 'x' ) );
			self::set( $job->id, array( 'options_json' => wp_json_encode( array( 'base' => $base ) ) ) );
			$job = $this->run_restore( Plugin::instance()->jobs()->find( $job->id ) );
			$this->assertSame( $status, $job->status, (string) $job->last_error );
			if ( 1 === $over ) {
				$this->assertStringContainsString( sprintf( 'bytes long where the restore stages it, more than the %d bytes this server allows', StagingLayout::MAX_PATH_BYTES ), (string) $job->last_error );
			}
		}
	}

	public function test_a_unit_that_dies_after_writing_its_keys_is_replayed_from_the_committed_lengths(): void {
		$files = array();
		for ( $i = 0; $i < 9; $i++ ) {
			$files[ 'wp-content/uploads/d' . ( $i % 3 ) . '/f' . $i . '.txt' ] = (string) $i;
			$files[ 'outside-' . $i . '.txt' ]                                 = (string) $i;
		}
		$base  = $this->with_files( $files );
		$clean = $this->run_restore( $this->job_for( $this->type( array( 'page_lines' => 4 ) ), $base ) );
		$this->assertSame( Job::COMPLETED, $clean->status, (string) $clean->last_error );
		$died  = 0;
		$crash = $this->type(
			array(
				'page_lines' => 4,
				'at'         => static function ( string $point ) use ( &$died ): void {
					if ( 'appended' === $point && 2 === ++$died ) {
						throw new \RuntimeException( 'simulated: the run is killed here' );
					}
				},
			)
		);
		$job = $this->run_restore( $this->job_for( $crash, $base ) );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'simulated', (string) $job->last_error, 'the control: killed after its bytes were written' );
		// A write the death tore: half a record at the end of every bucket. Whole records a dead unit wrote are
		// the same bytes its replay writes; a torn one would shift every record after it.
		$torn = 0;
		foreach ( (array) glob( RestoreFiles::path( $this->work( $job ), RestoreFiles::KEYS ) . '/*' ) as $bucket ) {
			file_put_contents( (string) $bucket, '0123456789abc', FILE_APPEND );
			++$torn;
		}
		$this->assertGreaterThan( 0, $torn, 'the control: the dead unit wrote buckets' );
		Plugin::instance()->job_actions()->retry( $job->id );
		$job = $this->run_restore( $job );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$lines = array_filter( explode( "\n", (string) file_get_contents( RestoreFiles::path( $this->work( $job ), RestoreFiles::UNMAPPED ) ) ) );
		$this->assertCount( 9, $lines, 'each unmapped path once: the dead unit\'s bytes were cut back' );
		$sizes = static function ( Job $job ): array {
			$out = array();
			foreach ( (array) glob( RestoreFiles::path( Residue::work_dir( $job->storage_path, $job->id ), RestoreFiles::KEYS ) . '/*' ) as $bucket ) {
				$out[ basename( (string) $bucket ) ] = filesize( (string) $bucket );
			}
			return $out;
		};
		$this->assertNotSame( array(), array_filter( $sizes( $clean ) ), 'the control: the clean run wrote keys' );
		$this->assertSame( $sizes( $clean ), $sizes( $job ), 'every name key once, as in a run that did not die' );
	}

	public function test_a_work_file_cut_shorter_than_committed_fails_the_restore_and_is_not_padded(): void {
		$files = array();
		for ( $i = 0; $i < 40; $i++ ) {
			$files[ 'wp-content/uploads/f' . $i . '.txt' ] = (string) $i;
		}
		$base   = $this->with_files( $files );
		$type   = $this->type( array( 'page_lines' => 1 ) );
		$job    = $this->job_for( $type, $base );
		$runner = $this->small_runner();
		$cursor = array();
		for ( $i = 0; $i < 400; $i++ ) {
			$runner->tick( $job->id, microtime( true ) );
			$now    = Plugin::instance()->jobs()->find( $job->id );
			$cursor = $now->cursor;
			if ( RestoreFilesPreflightStep::ID === $now->step && 'index' === ( $cursor['phase'] ?? '' ) && (int) ( $cursor['offset'] ?? 0 ) > 0 ) {
				break;
			}
			$this->assertSame( Job::RUNNING, $now->status, (string) $now->last_error );
		}
		$this->assertGreaterThan( 0, (int) ( $cursor['offset'] ?? 0 ), 'the control: stopped inside the index phase' );
		$bucket = null;
		foreach ( (array) $cursor['lengths'] as $b => $length ) {
			if ( $length > 0 ) {
				$bucket = RestoreFiles::path( $this->work( $job ), RestoreFiles::KEYS ) . '/' . sprintf( '%03d', $b );
				break;
			}
		}
		$this->assertNotNull( $bucket, 'a bucket with committed records' );
		$short = filesize( $bucket ) - NameClashes::RECORD_BYTES;
		$handle = fopen( $bucket, 'r+b' );
		ftruncate( $handle, $short );
		fclose( $handle );
		$job = $this->run_restore( $job );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'A work file of the restore is shorter than recorded; the work directory was changed.', (string) $job->last_error );
		clearstatcache();
		$this->assertSame( $short, filesize( $bucket ), 'not padded' );
	}

	public function test_a_plugin_directory_with_more_entries_than_this_plugin_has_is_refused(): void {
		$base = $this->with_files( array( 'wp-content/plugins/demo/demo.php' => '<?php' ) );
		$most = $this->plugin_dir( 0, RestoreFilesPreflightStep::MAX_PLUGIN_ENTRIES - 1 ); // With big.bin: the most there may be.
		$this->assertSame( Job::COMPLETED, $this->run_restore( $this->job_for( $this->type( array( 'plugin_dir' => $most ) ), $base ) )->status );
		touch( $most . '/one-more' );
		$this->made[] = $most . '/one-more';
		$job          = $this->run_restore( $this->job_for( $this->type( array( 'plugin_dir' => $most ) ), $base ) );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( sprintf( 'holds more than %d entries', RestoreFilesPreflightStep::MAX_PLUGIN_ENTRIES ), (string) $job->last_error );
	}

	public function test_a_directory_reached_through_a_link_is_staged_next_to_where_it_is(): void {
		$real = $this->dir( sys_get_temp_dir() . '/wpc-real-uploads-' . bin2hex( random_bytes( 3 ) ) );
		$link = WP_CONTENT_DIR . '/wpc-linked-uploads-' . bin2hex( random_bytes( 3 ) );
		symlink( $real, $link );
		$this->made[] = $link;
		$site         = ScanRoots::site_directories();
		$type         = $this->type(
			array(
				'directories' => static function () use ( $site, $link ): array {
					return array_merge( $site, array( 'uploads' => $link ) );
				},
			)
		);
		// Its target is outside this site (the temporary directory): swapped as the policy says, not asked.
		$job = $this->run_restore( $this->job_for( $type, $this->with_files( array( 'wp-content/uploads/a.txt' => 'a' ) ), array( 'policy' => array( LinkedTargets::POLICY_KEY => LinkedTargets::SWAP ) ) ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$staging = RestoreFilesPreflightStep::staging( $this->work( $job ) );
		$this->assertSame( rtrim( str_replace( '\\', '/', (string) realpath( $real ) ), '/' ), $staging['groups']['uploads'], 'the directory the swap renames' );
		$this->assertContains( dirname( $staging['groups']['uploads'] ), $staging['parents'], 'probed and staged next to it, not next to the link' );
	}

	/**
	 * A restore type whose uploads directory is a link (in the content directory) to $real.
	 *
	 * @return array{0: string, 1: string} The type and the link.
	 */
	private function linked_uploads( string $real, array $more = array() ): array {
		$link = WP_CONTENT_DIR . '/wpc-linked-uploads-' . bin2hex( random_bytes( 3 ) );
		symlink( $real, $link );
		$this->made[] = $link;
		$site         = ScanRoots::site_directories();
		$type         = $this->type(
			$more + array(
				'directories'  => static function () use ( $site, $link ): array {
					return array_merge( $site, array( 'uploads' => $link ) );
				},
				'trusted_root' => static function (): string {
					return ''; // None: the temporary directory is outside the site.
				},
			)
		);
		return array( $type, $link );
	}

	/**
	 * What the question's id is made of for one group outside this site.
	 *
	 * @return array<string, array{target: string, verdict: string, at: string}>
	 */
	private static function outside( string $group, string $target ): array {
		return array(
			$group => array(
				'target'  => $target,
				'verdict' => LinkedTargets::OUTSIDE,
				'at'      => '',
			),
		);
	}

	private static function real( string $dir ): string {
		return rtrim( str_replace( '\\', '/', (string) realpath( $dir ) ), '/' );
	}

	private function answered( Job $job, string $answer ): Job {
		$job = Plugin::instance()->jobs()->find( $job->id );
		$this->assertNotNull( Plugin::instance()->job_actions()->answer( $job->id, array( (string) $job->questions[0]['id'] => $answer ) ) );
		Plugin::instance()->runner()->tick( $job->id, microtime( true ) ); // Answered: paused, and ticked again.
		return $this->run_restore( Plugin::instance()->jobs()->find( $job->id ) );
	}

	public function test_a_directory_linked_outside_the_site_is_asked_about_and_swapped_or_left_out_as_answered(): void {
		$real           = $this->dir( sys_get_temp_dir() . '/wpc-real-uploads-' . bin2hex( random_bytes( 3 ) ) );
		list( $type, )  = $this->linked_uploads( $real );
		$base           = $this->with_files( array( 'wp-content/uploads/a.txt' => 'a' ) );
		$job            = $this->run_restore( $this->job_for( $type, $base ) );
		$this->assertSame( Job::PAUSED, $job->status, (string) $job->last_error );
		$this->assertCount( 1, $job->questions );
		$question = $job->questions[0];
		$this->assertSame( LinkedTargets::KIND, $question['kind'] );
		$this->assertSame( LinkedTargets::id( self::outside( 'uploads', self::real( $real ) ) ), $question['id'], 'bound to the group and its target' );
		$this->assertSame( array( 'swap', 'exclude' ), $question['choices'], 'no default' );
		$this->assertSame( 1, $question['count'] );
		// As the admin and the terminal show it: the target masked, the text whole.
		$shown = QuestionText::for_job( $job, Plugin::instance()->directories(), array( Plugin::instance()->job_presenter(), 'clean' ) );
		$this->assertStringContainsString( 'is not positively this site\'s own', $shown[0]['text'] );
		$this->assertStringContainsString( '(outside this site\'s directories)', $shown[0]['listed'][0] );
		$this->assertCount( 1, $shown[0]['listed'] );
		$this->assertStringStartsWith( 'uploads: {tmp}/', $shown[0]['listed'][0] );
		$this->assertStringContainsString( basename( $real ), $shown[0]['listed'][0], 'the control: the line is the target' );
		$this->assertStringNotContainsString( sys_get_temp_dir(), implode( "\n", $shown[0]['listed'] ) . $shown[0]['text'] );

		$swapped = $this->answered( $job, 'swap' );
		$this->assertSame( Job::COMPLETED, $swapped->status, (string) $swapped->last_error );
		$staging = RestoreFilesPreflightStep::staging( $this->work( $swapped ) );
		$this->assertContains( 'uploads', $staging['staged'] );
		$this->assertSame( self::real( $real ), $staging['groups']['uploads'] );
		$this->assertSame( array(), $staging['left_out'] );

		$left = $this->answered( $this->run_restore( $this->job_for( $type, $base ) ), 'exclude' );
		$this->assertSame( Job::COMPLETED, $left->status, (string) $left->last_error );
		$staging = RestoreFilesPreflightStep::staging( $this->work( $left ) );
		$this->assertNotContains( 'uploads', $staging['staged'], 'left out of the restore' );
		$this->assertSame( array( 'uploads' ), $staging['left_out'] );
		$this->assertStringContainsString( 'A content group of the backup is not restored: its directory is not positively this site', self::log( $left ) );
		$this->assertStringNotContainsString( 'belong to no content group', self::log( $left ), 'its files are the left-out group\'s, not of no group' );
		$staged_here = array_filter(
			Residue::scan_site( array( dirname( self::real( $real ) ) ), Directories::own_tokens() ),
			static function ( array $entry ) use ( $left ): bool {
				return Residue::STAGE_DIR === $entry['kind'] && $entry['id'] === $left->id;
			}
		);
		$this->assertSame( array(), array_values( $staged_here ), 'nothing staged next to the target' );
		$this->assertNotSame(
			array(),
			array_values(
				array_filter(
					Residue::scan_site( array( dirname( self::real( $real ) ) ), Directories::own_tokens() ),
					static function ( array $entry ) use ( $swapped ): bool {
						return Residue::STAGE_DIR === $entry['kind'] && $entry['id'] === $swapped->id;
					}
				)
			),
			'the control: the restore that swaps it staged next to the target'
		);
	}

	public function test_a_policy_says_it_up_front_and_an_unattended_restore_must(): void {
		$real          = $this->dir( sys_get_temp_dir() . '/wpc-real-uploads-' . bin2hex( random_bytes( 3 ) ) );
		list( $type, ) = $this->linked_uploads( $real );
		$base          = $this->with_files( array( 'wp-content/uploads/a.txt' => 'a' ) );
		$swap          = $this->run_restore( $this->job_for( $type, $base, array( 'policy' => array( LinkedTargets::POLICY_KEY => 'swap' ) ) ) );
		$this->assertSame( Job::COMPLETED, $swap->status, (string) $swap->last_error );
		$this->assertSame( array(), $swap->questions );
		$this->assertContains( 'uploads', RestoreFilesPreflightStep::staging( $this->work( $swap ) )['staged'] );
		$this->assertStringContainsString( '"from":"policy"', self::log( $swap ) );
		$out = $this->run_restore( $this->job_for( $type, $base, array( 'policy' => array( LinkedTargets::POLICY_KEY => 'exclude' ) ) ) );
		$this->assertSame( Job::COMPLETED, $out->status, (string) $out->last_error );
		$this->assertSame( array( 'uploads' ), RestoreFilesPreflightStep::staging( $this->work( $out ) )['left_out'] );
		$tables  = array(
			'uncertain_tables' => 'exclude',
			'shared_tables'    => 'exclude',
		);
		$refused = $this->run_restore( $this->job_for( $type, $base, array( 'unattended' => true, 'policy' => $tables ) ) );
		$this->assertSame( Job::FAILED, $refused->status );
		$this->assertStringContainsString( 'An unattended restore must say what to do with content directories that are not positively this site\'s own (outside its directories, inside another installation, or not to be told): set the restore policy "linked_targets" to "swap" or "exclude".', (string) $refused->last_error );
		$this->assertFileDoesNotExist( RestoreFiles::path( $this->work( $refused ), RestoreFiles::MANIFEST ), 'refused before the backup was even checked' );
		$said = $this->run_restore( $this->job_for( $type, $base, array( 'unattended' => true, 'policy' => $tables + array( LinkedTargets::POLICY_KEY => 'swap' ) ) ) );
		$this->assertSame( Job::COMPLETED, $said->status, 'the control: all three said' );
	}

	public function test_an_answer_holds_only_for_the_target_it_was_given_for(): void {
		$real              = $this->dir( sys_get_temp_dir() . '/wpc-real-uploads-' . bin2hex( random_bytes( 3 ) ) );
		$other             = $this->dir( sys_get_temp_dir() . '/wpc-other-uploads-' . bin2hex( random_bytes( 3 ) ) );
		list( $type, $link ) = $this->linked_uploads( $real );
		$job               = $this->run_restore( $this->job_for( $type, $this->with_files( array( 'wp-content/uploads/a.txt' => 'a' ) ) ) );
		$this->assertSame( Job::PAUSED, $job->status, (string) $job->last_error );
		$first = (string) $job->questions[0]['id'];
		$this->assertNotNull( Plugin::instance()->job_actions()->answer( $job->id, array( $first => 'swap' ) ) );
		// Before the answer is taken up, the link is pointed elsewhere (a new link renamed over it).
		$moved = $link . '-new';
		symlink( $other, $moved );
		rename( $moved, $link );
		$this->assertSame( self::real( $other ), self::real( $link ), 'the control: the link points elsewhere now' );
		Plugin::instance()->runner()->tick( $job->id, microtime( true ) );
		$job = $this->run_restore( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::PAUSED, $job->status, 'asked again' );
		$this->assertNotSame( $first, $job->questions[0]['id'] );
		$this->assertSame( LinkedTargets::id( self::outside( 'uploads', self::real( $other ) ) ), $job->questions[0]['id'] );
		$this->assertStringContainsString( 'An answer given for other content directories than the restore would now ask about is not used', self::log( $job ) );
	}

	public function test_a_group_under_a_directory_that_is_a_link_is_asked_about_too(): void {
		// The group's own directory is not a link; the one holding it is (a content directory linked elsewhere).
		$real = $this->dir( sys_get_temp_dir() . '/wpc-real-content-' . bin2hex( random_bytes( 3 ) ) );
		mkdir( $real . '/uploads' );
		$link = WP_CONTENT_DIR . '/wpc-linked-content-' . bin2hex( random_bytes( 3 ) );
		symlink( $real, $link );
		$this->made[] = $link;
		$this->assertFalse( is_link( $link . '/uploads' ), 'the control: the group directory itself is no link' );
		$site = ScanRoots::site_directories();
		$type = $this->type(
			array(
				'directories'  => static function () use ( $site, $link ): array {
					return array_merge( $site, array( 'uploads' => $link . '/uploads' ) );
				},
				'trusted_root' => static function (): string {
					return '';
				},
			)
		);
		$job  = $this->run_restore( $this->job_for( $type, $this->with_files( array( 'wp-content/uploads/a.txt' => 'a' ) ) ) );
		$this->assertSame( Job::PAUSED, $job->status, (string) $job->last_error );
		$this->assertSame( LinkedTargets::id( self::outside( 'uploads', self::real( $real . '/uploads' ) ) ), $job->questions[0]['id'] );
	}

	public function test_an_answer_holds_only_for_the_reason_it_was_given_for(): void {
		$root    = $this->dir( sys_get_temp_dir() . '/wpc-reason-' . bin2hex( random_bytes( 3 ) ) );
		$uploads = $root . '/site/uploads';
		mkdir( $uploads, 0755, true );
		$trusted = '';
		$site    = ScanRoots::site_directories();
		$type    = $this->type(
			array(
				'directories'  => static function () use ( $site, $uploads ): array {
					return array_merge( $site, array( 'uploads' => $uploads ) );
				},
				'trusted_root' => static function () use ( &$trusted ): string {
					return $trusted;
				},
			)
		);
		$job = $this->run_restore( $this->job_for( $type, $this->with_files( array( 'wp-content/uploads/a.txt' => 'a' ) ) ) );
		$this->assertSame( Job::PAUSED, $job->status, (string) $job->last_error );
		$first = (string) $job->questions[0]['id'];
		$this->assertSame( LinkedTargets::id( self::outside( 'uploads', self::real( $uploads ) ) ), $first, 'asked as outside the site' );
		$this->assertNotNull( Plugin::instance()->job_actions()->answer( $job->id, array( $first => 'swap' ) ) );
		// Before the answer is taken up, the same directory is inside another installation (in the trusted root).
		$trusted = $root;
		file_put_contents( $root . '/site/wp-load.php', '<?php' );
		Plugin::instance()->runner()->tick( $job->id, microtime( true ) );
		$job = $this->run_restore( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::PAUSED, $job->status, 'asked again: the reason changed' );
		$this->assertSame(
			LinkedTargets::id(
				array(
					'uploads' => array(
						'target'  => self::real( $uploads ),
						'verdict' => LinkedTargets::INSTALLATION,
						'at'      => self::real( $root . '/site' ),
					),
				)
			),
			$job->questions[0]['id']
		);
		$this->assertStringContainsString( 'An answer given for other content directories than the restore would now ask about is not used', self::log( $job ) );
	}

	public function test_a_directory_in_the_trusted_root_is_not_asked_about_and_one_outside_the_site_is_with_or_without_a_link(): void {
		$real          = $this->dir( sys_get_temp_dir() . '/wpc-real-uploads-' . bin2hex( random_bytes( 3 ) ) );
		$base          = $this->with_files( array( 'wp-content/uploads/a.txt' => 'a' ) );
		list( $type, ) = $this->linked_uploads(
			$real,
			array(
				'trusted_root' => static function () use ( $real ): string {
					return dirname( $real );
				},
			)
		);
		$trusted = $this->run_restore( $this->job_for( $type, $base ) );
		$this->assertSame( Job::COMPLETED, $trusted->status, (string) $trusted->last_error );
		$this->assertSame( array(), $trusted->questions, 'reached through a link, in the trusted root' );
		// Named by WordPress directly, no link on the way (UPLOADS, upload_path): outside the site, asked about all the
		// same; in the trusted root, not.
		$site  = ScanRoots::site_directories();
		$plain = static function ( string $trusted ) use ( $site, $real ): array {
			return array(
				'directories'  => static function () use ( $site, $real ): array {
					return array_merge( $site, array( 'uploads' => $real ) );
				},
				'trusted_root' => static function () use ( $trusted ): string {
					return $trusted;
				},
			);
		};
		$asked = $this->run_restore( $this->job_for( $this->type( $plain( '' ) ), $base ) );
		$this->assertSame( Job::PAUSED, $asked->status, 'a directory outside the site, no link: asked about' );
		$this->assertSame( LinkedTargets::id( self::outside( 'uploads', self::real( $real ) ) ), $asked->questions[0]['id'] );
		$job = $this->run_restore( $this->job_for( $this->type( $plain( dirname( $real ) ) ), $base ) );
		$this->assertSame( Job::COMPLETED, $job->status, 'the same directory in the trusted root: this site\'s' );
		$this->assertSame( array(), $job->questions );
	}

	public function test_a_name_this_file_system_cannot_store_stops_the_restore_and_is_named(): void {
		$base  = $this->with_files( array( 'wp-content/uploads/a:b.txt' => 'x' ) );
		$win32 = $this->type(
			array(
				'names' => static function ( string $parent, TargetNames $probed ): TargetNames {
					unset( $parent, $probed );
					return new TargetNames( true, true, false, true, true );
				},
			)
		);
		$job = $this->run_restore( $this->job_for( $win32, $base ) );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'The backup holds wp-content/uploads/a:b.txt, and the file system of this site cannot store the name a:b.txt', (string) $job->last_error );
		$this->assertSame( Job::COMPLETED, $this->run_restore( $this->start_restore( $base ) )->status, 'the control: this file system stores it' );
	}

	public function test_a_clash_is_found_when_every_line_is_read_in_a_tick_of_its_own(): void {
		// The directory "D" is written by its first file only; the file "d" comes after. With no time left each
		// tick reads one line, so the line before each is read back from its offset when the next tick resumes.
		$base    = $this->with_files(
			array(
				'wp-content/uploads/a.txt'   => 'a',
				'wp-content/uploads/D/x.txt' => 'x',
				'wp-content/uploads/D/y.txt' => 'y',
				'wp-content/uploads/d'       => 'file',
			)
		);
		$folding = $this->type(
			array(
				'page_lines' => 1,
				'names'      => static function ( string $parent, TargetNames $probed ): TargetNames {
					unset( $parent, $probed );
					return new TargetNames( true, true, false, false );
				},
			)
		);
		$job    = $this->job_for( $folding, $base );
		$runner = new Runner( Plugin::instance()->jobs(), Plugin::instance()->job_types(), new Redactor( Redactor::installation_secrets() ), array( 'budget' => new Budget( 20, 32 * 1048576, false ) ) );
		$ticks  = 0;
		for ( $i = 0; $i < 5000; $i++ ) {
			$now = Plugin::instance()->jobs()->find( $job->id );
			if ( ! in_array( $now->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				break;
			}
			if ( RestoreFilesPreflightStep::ID === $now->step && 'index' === ( $now->cursor['phase'] ?? '' ) ) {
				++$ticks;
			}
			$runner->tick( $job->id, microtime( true ) - 3600 );
		}
		$GLOBALS['wpdb']->query( 'COMMIT' );
		$this->assertGreaterThanOrEqual( 4, $ticks, 'the control: the index was read across ticks' );
		$this->assertSame( Job::FAILED, $now->status );
		$this->assertStringContainsString( 'The backup holds wp-content/uploads/D/x.txt and wp-content/uploads/d, which the file system of this site treats as one name', (string) $now->last_error );
	}

	public function test_a_question_on_more_staged_bytes_than_an_integer_holds_carries_the_largest_integer(): void {
		// No real backup stages that much (a manifest total is at most 2^53 bytes): the cursor the space phase
		// reads is given more, as 32-bit PHP meets it past 2 GiB.
		$base    = $this->with_files( array( 'wp-content/uploads/a.txt' => 'a' ) );
		$unknown = $this->type(
			array(
				'plugin_dir' => $this->plugin_dir( 0 ),
				'free'       => static function () {
					return null;
				},
			)
		);
		$job    = $this->job_for( $unknown, $base );
		$runner = new Runner( Plugin::instance()->jobs(), Plugin::instance()->job_types(), new Redactor( Redactor::installation_secrets() ), array( 'budget' => new Budget( 20, 32 * 1048576, false ) ) );
		for ( $i = 0; $i < 5000; $i++ ) {
			$now = Plugin::instance()->jobs()->find( $job->id );
			if ( RestoreFilesPreflightStep::ID === $now->step && 'space' === ( $now->cursor['phase'] ?? '' ) ) {
				break;
			}
			$this->assertContains( $now->status, array( Job::QUEUED, Job::RUNNING ), (string) $now->last_error );
			$runner->tick( $job->id, microtime( true ) - 3600 );
		}
		$this->assertSame( 'space', $now->cursor['phase'] ?? '', 'the control: stopped before the space phase' );
		$cursor          = $now->cursor;
		$cursor['bytes'] = array_fill( 0, count( (array) $cursor['bytes'] ), 2.0 ** 64 );
		self::set( $job->id, array( 'cursor_json' => wp_json_encode( $cursor ) ) );
		$runner->tick( $job->id, microtime( true ) );
		$job = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::PAUSED, $job->status, (string) $job->last_error );
		$this->assertSame( PHP_INT_MAX, $job->questions[0]['bytes'], 'capped, not wrapped to a negative count' );
	}
}
