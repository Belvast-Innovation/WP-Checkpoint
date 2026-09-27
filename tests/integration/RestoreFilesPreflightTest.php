<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Files\ScanRoots;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Jobs\RestoreFilesPreflightStep;
use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Restore\NameClashes;
use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Restore\StagingLayout;
use WPCheckpoint\Restore\TargetNames;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Restore\RestoreTestCase;

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
			if ( is_link( $path ) || is_file( $path ) ) {
				@unlink( $path );
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
		$type = 'restore_files_' . bin2hex( random_bytes( 3 ) );
		$this->register( $type, self::restore_steps_with( new RestoreFilesPreflightStep( $parts ) ) );
		return $type;
	}

	/**
	 * A job of a type, for a backup.
	 */
	private function job_for( string $type, string $base ): Job {
		return Plugin::instance()->jobs()->create( $type, self::$admin_id, array(), array( 'base' => $base ) );
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
	 * What the probes might have left next to the site.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function left_next_to_the_site(): array {
		return Residue::scan_site( Residue::site_dirs( ScanRoots::site_directories() ), Directories::own_tokens() );
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
			$job = $this->run_restore( $this->job_for( $type, $base ) );
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
		$job = $this->run_restore( $this->job_for( $type, $this->with_files( array( 'wp-content/uploads/a.txt' => 'a' ) ) ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$staging = RestoreFilesPreflightStep::staging( $this->work( $job ) );
		$this->assertSame( rtrim( str_replace( '\\', '/', (string) realpath( $real ) ), '/' ), $staging['groups']['uploads'], 'the directory the swap renames' );
		$this->assertContains( dirname( $staging['groups']['uploads'] ), $staging['parents'], 'probed and staged next to it, not next to the link' );
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
