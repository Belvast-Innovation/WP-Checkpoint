<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Files\ScanRoots;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\StagingLayout;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Support\Uninstaller;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * A restore's staging roots and probes live next to the site's
 * directories, not under the storage directory: they are registered
 * residue all the same. The reaper removes an ended job's, keeps a running
 * one's (a probe only while a run holds the job) and never touches another
 * installation's; a cancelled job's go with its work, wherever its storage
 * directory is now; uninstall removes this installation's.
 */
final class StagingResidueTest extends JobTestCase {

	/** @var string[] Paths a test created. */
	private $made = array();

	public function tear_down(): void {
		foreach ( $this->made as $path ) {
			if ( is_dir( $path ) ) {
				Deleter::empty_directory( $path );
				@rmdir( $path );
			} elseif ( file_exists( $path ) ) {
				@unlink( $path );
			}
		}
		parent::tear_down();
	}

	/**
	 * The layout of a job on this site.
	 */
	private function layout( int $job_id, string $token = '' ): StagingLayout {
		return new StagingLayout( ScanRoots::site_directories(), '' === $token ? (string) Plugin::instance()->directories()->state()['token'] : $token, $job_id, StagingLayout::new_random() );
	}

	/**
	 * The staging roots of a layout, then its probes (top level, as leave() made them).
	 *
	 * @return array{0: string[], 1: string[]}
	 */
	private static function split( array $made ): array {
		$roots  = array_values( array_filter( $made, static function ( $path ) { return 0 === strpos( basename( $path ), StagingLayout::STAGE_PREFIX ); } ) );
		$probes = array_values( array_diff( $made, $roots ) );
		return array( $roots, $probes );
	}

	private static function set( int $id, array $row ): void {
		global $wpdb;
		$wpdb->update( Schema::jobs_table(), $row, array( 'id' => $id ) );
	}

	/**
	 * This installation's tokens now, plus an earlier one it used.
	 */
	private static function past_token( string $token ): void {
		$state                = Directories::load_state();
		$state['past_tokens'] = array( $token );
		Options::set( Directories::OPTION, $state );
	}

	/**
	 * A staging root with a file in each group, and a probe directory and a loader probe file, for $layout.
	 *
	 * @return string[] Everything made, top level only.
	 */
	private function leave( StagingLayout $layout ): array {
		$made = array();
		foreach ( StagingLayout::GROUPS as $group ) {
			wp_mkdir_p( $layout->stage_dir( $group ) . '/sub' );
			file_put_contents( $layout->stage_dir( $group ) . '/sub/a.txt', 'staged' );
			$made[ $layout->root( $group ) ] = true;
		}
		$probe = $layout->parent( 'plugins' ) . '/' . $layout->probe_name();
		mkdir( $probe );
		$made[ $probe ] = true;
		$mu             = ScanRoots::site_directories()['mu-plugins'];
		wp_mkdir_p( $mu );
		$file = $mu . '/' . $layout->probe_name( '.php' );
		file_put_contents( $file, "<?php\n" );
		$made[ $file ] = true;
		$this->made    = array_merge( $this->made, array_keys( $made ) );
		return array_keys( $made );
	}

	private function reap(): void {
		delete_site_transient( 'wpcheckpoint_jobs_reaped' );
		Plugin::instance()->jobs()->reap_residue();
	}

	public function test_an_ended_jobs_staging_and_probes_are_reaped_and_a_running_ones_kept(): void {
		$this->register( 'plain', array( $this->counting_step( 'p', 5 ) ) );
		$running = $this->job_of( 'plain' );
		$ended   = $this->job_of( 'plain' );
		Plugin::instance()->jobs()->transition( Plugin::instance()->jobs()->find( $ended ), Job::CANCELLED );
		$this->assertNotNull( Plugin::instance()->jobs()->acquire( $running ), 'a unit of the running job is under way' );
		$kept = $this->leave( $this->layout( $running ) );
		$gone = $this->leave( $this->layout( $ended ) );
		$found = Residue::scan_site( Residue::site_dirs( ScanRoots::site_directories() ), array( (string) Plugin::instance()->directories()->state()['token'] ) );
		$this->assertCount( 6, $found, 'the control: the scan finds both jobs\' roots and probes' );

		$this->reap();
		foreach ( $gone as $path ) {
			$this->assertFileDoesNotExist( $path, 'an ended job\'s: reaped' );
		}
		foreach ( $kept as $path ) {
			$this->assertFileExists( $path, 'a running job\'s: kept' );
		}
	}

	public function test_a_probe_goes_once_no_run_holds_its_job_and_the_staging_root_stays(): void {
		$this->register( 'plain', array( $this->counting_step( 'p', 5 ) ) );
		$id                      = $this->job_of( 'plain' );
		list( $roots, $probes ) = self::split( $this->leave( $this->layout( $id ) ) );
		$this->assertCount( 2, $probes );
		$this->assertFalse( Plugin::instance()->jobs()->find( $id )->is_locked( time() ), 'between two units' );

		$this->reap();
		foreach ( $probes as $path ) {
			$this->assertFileDoesNotExist( $path, 'a probe lives within one unit: none is under way' );
		}
		foreach ( $roots as $path ) {
			$this->assertFileExists( $path, 'the staging lives as long as the restore' );
		}
	}

	public function test_an_earlier_tokens_staging_is_this_installations(): void {
		$this->register( 'plain', array( $this->counting_step( 'p', 5 ) ) );
		$running = $this->job_of( 'plain' );
		$ended   = $this->job_of( 'plain' );
		Plugin::instance()->jobs()->transition( Plugin::instance()->jobs()->find( $ended ), Job::CANCELLED );
		// Both jobs were started under an earlier storage directory; the running one still uses it.
		self::set( $running, array( 'storage_token' => 'aaaaaaaaaaaa' ) );
		self::set( $ended, array( 'storage_token' => 'aaaaaaaaaaaa' ) );
		self::past_token( 'aaaaaaaaaaaa' );
		list( $kept )   = self::split( $this->leave( $this->layout( $running, 'aaaaaaaaaaaa' ) ) );
		$gone           = $this->leave( $this->layout( $ended, 'aaaaaaaaaaaa' ) );
		list( $stale )  = self::split( $this->leave( $this->layout( $running ) ) ); // The running job, under a token it does not hold.
		$theirs         = $this->leave( $this->layout( $ended, 'ffffffffffff' ) );

		$this->reap();
		foreach ( $kept as $path ) {
			$this->assertFileExists( $path, 'the restore\'s, under the token it was started with: kept' );
		}
		foreach ( array_merge( $gone, $stale ) as $path ) {
			$this->assertFileDoesNotExist( $path, 'an ended job\'s, and a name the job does not hold: reaped' );
		}
		foreach ( $theirs as $path ) {
			$this->assertFileExists( $path, 'a token that was never ours: untouched' );
		}
	}

	public function test_another_installations_staging_is_never_touched(): void {
		$this->register( 'plain', array( $this->counting_step( 'p', 5 ) ) );
		$ended = $this->job_of( 'plain' );
		Plugin::instance()->jobs()->transition( Plugin::instance()->jobs()->find( $ended ), Job::CANCELLED );
		// A copied site sharing the directories: its names carry its own token, and its job ids overlap ours.
		$theirs = $this->leave( $this->layout( $ended, 'ffffffffffff' ) );
		$ours   = $this->leave( $this->layout( $ended ) );
		$this->reap();
		foreach ( $theirs as $path ) {
			$this->assertFileExists( $path, 'another installation\'s: untouched' );
		}
		foreach ( $ours as $path ) {
			$this->assertFileDoesNotExist( $path, 'the control: ours, reaped' );
		}
	}

	public function test_a_cancelled_jobs_staging_goes_with_its_work(): void {
		$this->register( 'plain', array( $this->counting_step( 'p', 5 ) ) );
		$id     = $this->job_of( 'plain' );
		$other  = $this->job_of( 'plain' );
		$mine   = $this->leave( $this->layout( $id ) );
		$others = $this->leave( $this->layout( $other ) );
		Plugin::instance()->job_actions()->cancel( $id );
		foreach ( $mine as $path ) {
			$this->assertFileDoesNotExist( $path, 'the cancelled job\'s: reclaimed' );
		}
		foreach ( $others as $path ) {
			$this->assertFileExists( $path, 'another job\'s: kept' );
		}
		$this->assertSame( Job::RUNNING, Plugin::instance()->jobs()->find( $other )->status );
	}

	public function test_a_jobs_staging_goes_with_its_work_after_its_storage_directory_changed(): void {
		$this->register( 'plain', array( $this->counting_step( 'p', 5 ) ) );
		$id = $this->job_of( 'plain' );
		self::past_token( 'aaaaaaaaaaaa' ); // The token it was started with is this installation's earlier one.
		self::set( $id, array( 'storage_token' => 'aaaaaaaaaaaa', 'storage_path' => WP_CONTENT_DIR . '/wp-checkpoint-aaaaaaaaaaaa' ) );
		$made = $this->leave( $this->layout( $id, 'aaaaaaaaaaaa' ) );
		$job  = Plugin::instance()->jobs()->find( $id );

		$this->assertFalse( Plugin::instance()->jobs()->reclaim_work( $job ), 'its work files, in the other directory, are left alone' );
		foreach ( $made as $path ) {
			$this->assertFileDoesNotExist( $path, 'its staging, next to the site: reclaimed all the same' );
		}
	}

	public function test_a_row_under_a_token_that_was_never_ours_reclaims_no_staging(): void {
		$this->register( 'plain', array( $this->counting_step( 'p', 5 ) ) );
		$id = $this->job_of( 'plain' );
		// A row of a copied database: the original's token, and the original's staging in shared directories.
		self::set( $id, array( 'storage_token' => 'ffffffffffff' ) );
		$theirs = $this->leave( $this->layout( $id, 'ffffffffffff' ) );
		$this->assertCount( 3, Residue::scan_site( Residue::site_dirs( ScanRoots::site_directories() ), array( 'ffffffffffff' ) ), 'the control: listed under that token' );

		$this->assertFalse( Plugin::instance()->jobs()->reclaim_work( Plugin::instance()->jobs()->find( $id ) ) );
		foreach ( $theirs as $path ) {
			$this->assertFileExists( $path, 'another installation\'s: untouched' );
		}
	}

	public function test_nothing_is_claimed_while_a_clone_is_unresolved(): void {
		$this->register( 'plain', array( $this->counting_step( 'p', 5 ) ) );
		$ended = $this->job_of( 'plain' );
		Plugin::instance()->jobs()->transition( Plugin::instance()->jobs()->find( $ended ), Job::CANCELLED );
		$made   = $this->leave( $this->layout( $ended ) );
		$state  = Directories::load_state();
		$copied = array_merge( $state, array( 'clone_detected' => true ) );
		Options::set( Directories::OPTION, $copied );

		$this->reap();
		$this->assertFalse( Plugin::instance()->jobs()->reclaim_work( Plugin::instance()->jobs()->find( $ended ) ) );
		$this->assertSame( 0, Uninstaller::delete_site_residue()['deleted'] );
		foreach ( $made as $path ) {
			$this->assertFileExists( $path, 'the tokens may be the original\'s: kept' );
		}

		Options::set( Directories::OPTION, $state );
		$this->reap();
		foreach ( $made as $path ) {
			$this->assertFileDoesNotExist( $path, 'the control: once resolved, reaped' );
		}
	}

	public function test_uninstall_on_a_copy_that_never_resolved_its_storage_removes_no_staging(): void {
		$this->register( 'plain', array( $this->counting_step( 'p', 5 ) ) );
		$id    = $this->job_of( 'plain' );
		$made  = $this->leave( $this->layout( $id ) );
		$state = Directories::load_state();
		// The copied options name the original's directory, whose marker is not this installation's.
		Options::set( Directories::OPTION, array_merge( $state, array( 'install_id' => 'copy-' . $state['install_id'] ) ) );
		$this->assertSame( 0, Uninstaller::delete_site_residue()['deleted'] );
		foreach ( $made as $path ) {
			$this->assertFileExists( $path );
		}
		Options::set( Directories::OPTION, $state );
		$this->assertGreaterThan( 0, Uninstaller::delete_site_residue()['deleted'], 'the control: this installation\'s own state' );
		foreach ( $made as $path ) {
			$this->assertFileDoesNotExist( $path );
		}
	}

	public function test_reclaim_is_bounded_and_finishes_over_several_calls(): void {
		$this->register( 'plain', array( $this->counting_step( 'p', 5 ) ) );
		$id   = $this->job_of( 'plain' );
		$made = $this->leave( $this->layout( $id ) );
		$job  = Plugin::instance()->jobs()->find( $id );

		$this->assertFalse( Plugin::instance()->jobs()->reclaim_work( $job, 3 ), 'three entries do not reach the end' );
		$this->assertNotEmpty( array_filter( $made, 'file_exists' ), 'something is left for the next call' );
		$calls = 1;
		while ( ! Plugin::instance()->jobs()->reclaim_work( $job, 3 ) ) {
			$this->assertLessThan( 40, ++$calls, 'each call advances' );
		}
		$this->assertSame( array(), array_values( array_filter( $made, 'file_exists' ) ) );
	}

	public function test_uninstall_removes_this_installations_staging_under_every_token_it_used(): void {
		$this->register( 'plain', array( $this->counting_step( 'p', 5 ) ) );
		$id = $this->job_of( 'plain' );
		self::past_token( 'aaaaaaaaaaaa' );
		$ours   = array_merge( $this->leave( $this->layout( $id ) ), $this->leave( $this->layout( $id, 'aaaaaaaaaaaa' ) ) );
		$theirs = $this->leave( $this->layout( $id, 'ffffffffffff' ) );
		update_option( Uninstaller::OPTION_DELETE_DATA, false );

		Uninstaller::run();
		$this->assertSame( Job::CANCELLED, Plugin::instance()->jobs()->find( $id )->status );
		foreach ( $ours as $path ) {
			$this->assertFileDoesNotExist( $path, 'a restore\'s working copy: removed even when data is kept' );
		}
		foreach ( $theirs as $path ) {
			$this->assertFileExists( $path, 'another installation\'s: untouched' );
		}
	}

	public function test_on_a_network_uploads_is_the_main_sites_from_any_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$main = ScanRoots::site_directories()['uploads'];
		$blog = self::factory()->blog->create();
		switch_to_blog( $blog );
		try {
			$this->assertStringContainsString( '/sites/' . $blog, str_replace( '\\', '/', wp_upload_dir( null, false )['basedir'] ), 'the control: a site\'s own upload directory is inside the main site\'s' );
			$this->assertSame( $main, ScanRoots::site_directories()['uploads'] );
		} finally {
			restore_current_blog();
		}
	}
}
