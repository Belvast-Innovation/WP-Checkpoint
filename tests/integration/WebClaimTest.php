<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\SwapPlan;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * A run made without WordPress takes a job holding the site only on all five pieces of evidence that it is this
 * site's (WebJobs::here()): the WordPress directory and the table prefix the plan's SITE entry recorded, this job's
 * maintenance file here, the job's storage directory with its files, and every directory of the plan under its
 * staging or its moved-aside name. Each one alone not holding, nothing is claimed and nothing is written; put right,
 * the same run claims it (the control).
 */
final class WebClaimTest extends SwapTestCase {

	/**
	 * A restore killed with a directory swapped in (its staging and moved-aside names there).
	 *
	 * @return Job
	 */
	private function killed(): Job {
		$job = $this->at_swap();
		$this->killed_at( $job, 'dir_in' );
		return Plugin::instance()->jobs()->find( $job->id );
	}

	/**
	 * The job row as stored.
	 *
	 * @param int $id Job id.
	 * @return array<string, string|null>
	 */
	private static function row( int $id ): array {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . JobRepository::table() . ' WHERE id = %d', $id ), ARRAY_A );
	}

	/**
	 * With $break done and until $mend is, a run without WordPress claims nothing and writes nothing; after it, it
	 * claims the job.
	 *
	 * @param Job           $job     Job.
	 * @param callable      $break   function(): void.
	 * @param callable      $mend    function(): void.
	 * @param string        $why     What the evidence says (WebJobs::here()).
	 * @param string|null   $abspath The directory the run is made in.
	 * @return void
	 */
	private function refused_until_mended( Job $job, callable $break, callable $mend, string $why, $abspath = null ): void {
		$before = self::row( $job->id );
		call_user_func( $break );
		$GLOBALS['wpdb']->query( 'COMMIT' );
		$broken = self::row( $job->id );
		$this->seams = array();
		$refused     = $this->web_tick( $job, array(), true, null, $abspath );
		$this->assertSame( TickResult::BUSY, $refused->status, $why . ': ' . $refused->message );
		$this->assertSame( $broken, self::row( $job->id ), $why . ': nothing written' );
		$this->assertSame( array(), $this->seams, $why . ': nothing of the swap ran' );
		call_user_func( $mend );
		$GLOBALS['wpdb']->query( 'COMMIT' );
		$this->assertSame( $before, self::row( $job->id ), 'the control: mended as it was' );
		$claimed = $this->web_tick( $job );
		$this->assertNotSame( TickResult::BUSY, $claimed->status, 'the control: claimed once mended: ' . $claimed->message . ' ' . implode( ' | ', $this->web_notes ) );
		$this->assertNotSame( array(), $this->seams, 'the control: the swap ran' );
	}

	/**
	 * Change the plan's SITE entry (a column of it) for the job's attempt, and give back its value before.
	 *
	 * @param Job    $job    Job.
	 * @param string $column Column.
	 * @param string $value  Value.
	 * @return string
	 */
	private function site_entry( Job $job, string $column, string $value ): string {
		global $wpdb;
		$table = $wpdb->base_prefix . SwapPlan::TABLE;
		$wpdb->query( 'COMMIT' );
		$was = (string) $wpdb->get_var( $wpdb->prepare( "SELECT {$column} FROM `{$table}` WHERE job_id = %d AND kind = %s", $job->id, SwapPlan::SITE ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plan's table and a column of the test's.
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET {$column} = %s WHERE job_id = %d AND kind = %s", $value, $job->id, SwapPlan::SITE ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- as above.
		$wpdb->query( 'COMMIT' );
		return $was;
	}

	public function test_another_wordpress_directory_with_this_jobs_maintenance_file_is_not_this_site(): void {
		$job   = $this->killed();
		$other = $this->sandbox . '/elsewhere';
		mkdir( $other );
		$this->copy_maintenance( $this->abspath, $other ); // The maintenance file holds there too: only the directory differs.
		$job = Plugin::instance()->jobs()->find( $job->id );
		$before = self::row( $job->id );
		$refused = $this->web_tick( $job, array(), true, null, $other );
		$this->assertSame( TickResult::BUSY, $refused->status, $refused->message );
		$this->assertSame( $before, self::row( $job->id ), 'nothing written' );
		$claimed = $this->web_tick( $job );
		$this->assertNotSame( TickResult::BUSY, $claimed->status, 'the control: claimed in its own directory: ' . $claimed->message );
	}

	public function test_a_table_prefix_other_than_the_one_planned_is_not_this_site(): void {
		$job = $this->killed();
		$was = '';
		$this->refused_until_mended(
			$job,
			function () use ( $job, &$was ): void {
				$was = $this->site_entry( $job, 'stage', 'other_' );
			},
			function () use ( $job, &$was ): void {
				$this->site_entry( $job, 'stage', $was );
			},
			'the prefix'
		);
	}

	public function test_a_maintenance_file_not_this_jobs_is_not_this_site(): void {
		$job  = $this->killed();
		$keep = $this->sandbox . '/kept-maintenance';
		mkdir( $keep );
		$this->refused_until_mended(
			$job,
			function () use ( $keep ): void {
				$this->copy_maintenance( $this->abspath, $keep );
				$this->remove_maintenance();
				$this->put_maintenance( 'WP Checkpoint restore ' . str_repeat( 'ab', 16 ), time() );
			},
			function () use ( $keep ): void {
				$this->remove_maintenance();
				$this->copy_maintenance( $keep, $this->abspath );
			},
			'the maintenance file'
		);
	}

	public function test_a_storage_directory_not_here_is_not_this_site(): void {
		global $wpdb;
		$job = $this->killed();
		$was = $job->storage_path;
		$this->refused_until_mended(
			$job,
			static function () use ( $wpdb, $job ): void {
				$wpdb->update( JobRepository::table(), array( 'storage_path' => '/nonexistent/wp-checkpoint-elsewhere' ), array( 'id' => $job->id ) );
			},
			static function () use ( $wpdb, $job, $was ): void {
				$wpdb->update( JobRepository::table(), array( 'storage_path' => $was ), array( 'id' => $job->id ) );
			},
			'the storage directory'
		);
	}

	public function test_a_directory_of_the_plan_under_neither_of_its_names_is_not_this_site(): void {
		$job   = $this->killed();
		$entry = null;
		foreach ( $this->plan_of( $job )['dirs'] as $dir ) {
			if ( file_exists( $dir['stage'] ) || file_exists( $dir['old'] ) ) {
				$entry = $dir;
				break;
			}
		}
		$this->assertNotNull( $entry, 'the control: a directory of the plan under one of its names' );
		$away = $this->sandbox . '/away-' . bin2hex( random_bytes( 3 ) );
		mkdir( $away );
		$moved = array();
		$this->refused_until_mended(
			$job,
			static function () use ( $entry, $away, &$moved ): void {
				foreach ( array( 'stage', 'old' ) as $name ) {
					if ( file_exists( $entry[ $name ] ) ) {
						rename( $entry[ $name ], $away . '/' . $name );
						$moved[ $name ] = $entry[ $name ];
					}
				}
			},
			static function () use ( $away, &$moved ): void {
				foreach ( $moved as $name => $path ) {
					rename( $away . '/' . $name, $path );
				}
			},
			'a directory of the plan'
		);
	}
}
