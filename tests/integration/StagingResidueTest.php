<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Files\ScanRoots;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\StagingLayout;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * A restore's staging roots and probes live next to the site's
 * directories, not under the storage directory: they are registered
 * residue all the same. The reaper removes an ended job's, keeps a running
 * one's and never touches another installation's; a cancelled job's go
 * with its work.
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
		$kept = $this->leave( $this->layout( $running ) );
		$gone = $this->leave( $this->layout( $ended ) );
		$found = Residue::scan_site( Residue::site_dirs( ScanRoots::site_directories() ), (string) Plugin::instance()->directories()->state()['token'] );
		$this->assertCount( 6, $found, 'the control: the scan finds both jobs\' roots and probes' );

		$this->reap();
		foreach ( $gone as $path ) {
			$this->assertFileDoesNotExist( $path, 'an ended job\'s: reaped' );
		}
		foreach ( $kept as $path ) {
			$this->assertFileExists( $path, 'a running job\'s: kept' );
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
}
