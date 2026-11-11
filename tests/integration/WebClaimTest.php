<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\Maintenance;
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
	 * @param string        $why     What the evidence says (WebJobs::here(), as the run notes it).
	 * @param string|null   $abspath The directory the run is made in.
	 * @return void
	 */
	private function refused_until_mended( Job $job, callable $break, callable $mend, string $why, $abspath = null ): void {
		$before = self::row( $job->id );
		call_user_func( $break );
		$GLOBALS['wpdb']->query( 'COMMIT' );
		$broken = self::row( $job->id );
		$this->seams = array();
		$refused     = $this->tick_without_wordpress( $job, array(), true, null, $abspath );
		$this->assertSame( TickResult::LEFT, $refused->status, $why . ': ' . $refused->message );
		$this->assertStringContainsString( $why, implode( ' | ', $this->web_notes ), 'refused for that evidence' );
		$this->assertSame( $broken, self::row( $job->id ), $why . ': nothing written' );
		$this->assertSame( array(), $this->seams, $why . ': nothing of the swap ran' );
		call_user_func( $mend );
		$GLOBALS['wpdb']->query( 'COMMIT' );
		$this->assertSame( $before, self::row( $job->id ), 'the control: mended as it was' );
		$claimed = $this->tick_without_wordpress( $job );
		$this->assertNotSame( TickResult::LEFT, $claimed->status, 'the control: claimed once mended: ' . $claimed->message . ' ' . implode( ' | ', $this->web_notes ) );
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
		$refused = $this->tick_without_wordpress( $job, array(), true, null, $other );
		$this->assertSame( TickResult::LEFT, $refused->status, $refused->message );
		$this->assertStringContainsString( 'The WordPress directory is not the one the swap was planned for.', implode( ' | ', $this->web_notes ) );
		$this->assertSame( $before, self::row( $job->id ), 'nothing written' );
		$claimed = $this->tick_without_wordpress( $job );
		$this->assertNotSame( TickResult::LEFT, $claimed->status, 'the control: claimed in its own directory: ' . $claimed->message );
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
			'The table prefix is not the one the swap was planned for.'
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
			'The maintenance file here is not this job\'s.'
		);
	}

	/**
	 * What takes the place of the job's maintenance file, each not this job's.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function not_this_jobs(): array {
		return array(
			'someone else\'s .maintenance (an update\'s) with the job\'s data file' => array( 'foreign' ),
			'.maintenance deleted, and no data file'                               => array( 'no data' ),
			'.maintenance deleted, the data file another restore\'s'               => array( 'other mark' ),
			'.maintenance deleted, the data file not a held one'                   => array( 'not held' ),
			'.maintenance deleted, the data file of no form'                       => array( 'no form' ),
		);
	}

	/**
	 * @dataProvider not_this_jobs
	 */
	public function test_with_its_maintenance_file_deleted_only_the_held_data_file_of_the_job_is_this_site( string $what ): void {
		$job  = $this->killed();
		$mark = (string) $job->site_mark;
		$keep = $this->sandbox . '/kept-maintenance';
		mkdir( $keep );
		$file = new Maintenance( $this->abspath, $mark );
		$this->assertTrue( $file->is_held(), 'the control: the killed run left the site held' );
		$this->refused_until_mended(
			$job,
			function () use ( $keep, $what, $file, $mark ): void {
				$this->copy_maintenance( $this->abspath, $keep );
				if ( 'foreign' === $what ) {
					file_put_contents( $file->path(), '<?php $upgrading = ' . time() . '; ?>' ); // As WordPress's upgrader writes it.
					return;
				}
				$this->remove_maintenance();
				if ( 'other mark' === $what ) {
					file_put_contents( $file->data_path(), ( new Maintenance( $this->abspath, 'WP Checkpoint restore ' . str_repeat( 'ab', 16 ) ) )->data( Maintenance::held() ) );
				} elseif ( 'not held' === $what ) {
					file_put_contents( $file->data_path(), $file->data( time() ) );
				} elseif ( 'no form' === $what ) {
					file_put_contents( $file->data_path(), $mark . "\n" . Maintenance::held() . "\n" );
				}
			},
			function () use ( $keep ): void {
				$this->remove_maintenance();
				$this->copy_maintenance( $keep, $this->abspath );
			},
			'The maintenance file here is not this job\'s.'
		);
	}

	/**
	 * Where the first rename of the put-back is: a directory's, a table's.
	 *
	 * @return array<string, array{0: string, 1: int, 2: string}>
	 */
	public function first_renames(): array {
		return array(
			'a directory\'s' => array( 'dir_in', 1, 'rename' ),
			'a table\'s'     => array( 'committed', 1, 'table_back' ),
		);
	}

	/**
	 * @dataProvider first_renames
	 */
	public function test_with_its_maintenance_file_deleted_the_job_is_taken_and_the_file_is_up_again_at_the_first_rename( string $seam, int $nth, string $first ): void {
		$job = $this->at_swap();
		$this->killed_at( $job, $seam, $nth );
		$job  = Plugin::instance()->jobs()->find( $job->id );
		$file = new Maintenance( $this->abspath, (string) $job->site_mark );
		$this->assertTrue( $file->is_held(), 'the control: the killed run left the site held' );
		\WPCheckpoint\Tests\Fixtures\Sandbox::remove( $file->path() ); // As the upgrader's maintenance_mode( false ) does.
		$this->assertTrue( $file->left_held() );
		$seen = array();
		$look = function ( string $at ) use ( &$seen, $file ): void {
			clearstatcache();
			$seen[] = array( $at, $this->maintenance_files()['.maintenance'] ?? null, $file->is_held() );
		};
		$this->seams = array();
		$result      = $this->tick_without_wordpress(
			$job,
			array(
				'at'     => function ( string $point ) use ( $look ): void {
					$this->seams[] = $point;
					$look( $point );
				},
				'rename' => static function ( string $from, string $to ) use ( $look ): bool {
					$look( 'rename' ); // Within the unit, right before the directory is renamed.
					return rename( $from, $to );
				},
			)
		);
		$this->assertNotSame( TickResult::LEFT, $result->status, 'taken on the held data file alone: ' . $result->message . ' ' . implode( ' | ', $this->web_notes ) );
		$starts = array_values(
			array_filter(
				$seen,
				static function ( array $one ): bool {
					return 'rollback' === $one[0];
				}
			)
		);
		$this->assertSame( array( array( 'rollback', null, false ) ), $starts, 'the control: the put-back started with no .maintenance, and the observation tells' );
		$renames = array_values(
			array_filter(
				$seen,
				static function ( array $one ): bool {
					return in_array( $one[0], array( 'rename', 'table_back' ), true );
				}
			)
		);
		$this->assertNotSame( array(), $renames, 'the control: something was renamed: ' . implode( ' ', $this->seams ) );
		$this->assertSame( $first, $renames[0][0], 'the first rename is the one this case is about' );
		foreach ( $renames as $one ) {
			$this->assertSame( Maintenance::CODE, $one[1], 'at ' . $one[0] . ': .maintenance is this plugin\'s code again' );
			$this->assertTrue( $one[2], 'at ' . $one[0] . ': and held for this job' );
		}
		$this->assertContains( Maintenance::CODE, Maintenance::KNOWN_CODES );
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
			'The job\'s storage directory, with its files, is not here.'
		);
	}

	public function test_a_directory_of_the_plan_under_neither_of_its_names_is_not_this_site(): void {
		$job   = $this->killed();
		$entry = null;
		foreach ( $this->plan_of( $job )['dirs'] as $dir ) {
			if ( ! empty( $dir['had_live'] ) && ( file_exists( $dir['stage'] ) || file_exists( $dir['old'] ) ) ) {
				$entry = $dir;
				break;
			}
		}
		$this->assertNotNull( $entry, 'the control: a directory of the plan the site had, under one of its names' );
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
			'A directory the swap moved is not here.'
		);
	}

	/**
	 * A restore killed with every directory swapped in, and the first directory of the plan the site did not have.
	 *
	 * @return array{0: Job, 1: array<string, mixed>}
	 */
	private function all_swapped_in(): array {
		$job  = $this->at_swap();
		$plan = $this->plan_of( $job );
		$new  = array_values(
			array_filter(
				$plan['dirs'],
				static function ( array $dir ): bool {
					return empty( $dir['had_live'] );
				}
			)
		);
		$this->assertNotSame( array(), $new, 'the control: the plan has a directory the site did not have' );
		$this->killed_at( $job, 'dir_in', count( $plan['dirs'] ) ); // Every directory swapped in.
		$this->assertFalse( file_exists( $new[0]['stage'] ), 'the control: no longer under its staged name' );
		$this->assertTrue( file_exists( $new[0]['live'] ), 'the control: under its live name' );
		return array( Plugin::instance()->jobs()->find( $job->id ), $new[0] );
	}

	public function test_a_directory_the_site_did_not_have_is_found_under_its_live_name_once_swapped_in(): void {
		list( $job )  = $this->all_swapped_in();
		$claimed      = $this->tick_without_wordpress( $job );
		$this->assertNotSame( TickResult::LEFT, $claimed->status, 'claimed: ' . $claimed->message . ' ' . implode( ' | ', $this->web_notes ) );
	}

	public function test_a_directory_the_site_did_not_have_under_neither_its_staged_nor_its_live_name_is_not_this_site(): void {
		list( $job, $entry ) = $this->all_swapped_in();
		$away                = $this->sandbox . '/away-new-' . bin2hex( random_bytes( 3 ) );
		$this->refused_until_mended(
			$job,
			static function () use ( $entry, $away ): void {
				rename( $entry['live'], $away );
			},
			static function () use ( $entry, $away ): void {
				rename( $away, $entry['live'] );
			},
			'A directory the swap moved is not here.'
		);
	}
}
