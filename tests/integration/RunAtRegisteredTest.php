<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobActions;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\ImportSession;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;
use WPCheckpoint\Tests\Unit\Jobs\RunAtWritesTest;

/**
 * What RunAtWritesTest registers of the methods JobRepository writes itself without stamping wp_run_at holds:
 * "not_claimable", the job is left in a state a run without WordPress does not take (failed, not holding the site:
 * its claim asks site_state <> 0 and an active status); "not_a_run" (record_blocked()), wp_run_at as it was (WebGraceTest
 * shows the scenario: another installation's refusals).
 */
final class RunAtRegisteredTest extends JobTestCase {

	/**
	 * A job of a plain type, run once.
	 *
	 * @return int
	 */
	private function running_job(): int {
		$this->register( 'plain', array( $this->counting_step( 'p', 5 ) ) );
		return $this->job_of( 'plain' );
	}

	/**
	 * Whether a run without WordPress (WebRun's storage) takes the job: it takes none it leaves untaken here.
	 *
	 * @param int $id Job id.
	 * @return bool
	 */
	private function taken_without_wordpress( int $id ): bool {
		$session = ImportSession::open( Credentials::from_wordpress() );
		try {
			$run  = new \WPCheckpoint\Jobs\WebRun(
				Credentials::from_wordpress(),
				ABSPATH,
				is_multisite(),
				plugin_basename( WPCHECKPOINT_FILE ),
				static function ( string $line ): void {
					unset( $line );
				}
			);
			return null !== $run->jobs( $session )->acquire( $id );
		} finally {
			$session->close();
		}
	}

	/**
	 * The row as stored.
	 *
	 * @param int $id Job id.
	 * @return array<string, string|null>
	 */
	private static function row( int $id ): array {
		global $wpdb;
		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . JobRepository::table() . ' WHERE id = %d', $id ), ARRAY_A );
	}

	public function test_the_registrations_are_these(): void {
		require_once dirname( __DIR__ ) . '/unit/Jobs/RunAtWritesTest.php'; // The unit suite's directory is not autoloaded by its namespace's case.
		$this->assertSame( array( 'fail_unreadable', 'fail_for_missing_columns', 'record_blocked' ), array_keys( RunAtWritesTest::REGISTERED ), 'each registration has its case below' );
	}

	public function test_fail_unreadable_leaves_a_job_a_run_without_wordpress_does_not_take(): void {
		global $wpdb;
		$id = $this->running_job();
		$wpdb->update( JobRepository::table(), array( 'cursor_json' => 'not json' ), array( 'id' => $id ) );
		$before = (int) self::row( $id )['wp_run_at'];
		Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT );
		$row = self::row( $id );
		$this->assertSame( Job::FAILED, $row['status'], 'the control: fail_unreadable() wrote it' );
		$this->assertSame( '0', $row['site_state'] );
		$this->assertSame( $before, (int) $row['wp_run_at'] );
		$this->assertFalse( $this->taken_without_wordpress( $id ) );
	}

	public function test_fail_for_missing_columns_leaves_a_job_a_run_without_wordpress_does_not_take(): void {
		global $wpdb;
		$id = $this->running_job();
		$wpdb->query( 'ALTER TABLE ' . Schema::jobs_table() . ' DROP COLUMN cron_deferrals' );
		$before = (int) self::row( $id )['wp_run_at'];
		Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT );
		$row = self::row( $id );
		$this->assertSame( Job::FAILED, $row['status'], 'the control: fail_for_missing_columns() wrote it' );
		$this->assertStringContainsString( 'cron_deferrals', (string) $row['last_error'] );
		$this->assertSame( '0', $row['site_state'] );
		$this->assertSame( $before, (int) $row['wp_run_at'] );
		$this->assertFalse( $this->taken_without_wordpress( $id ) );
	}

	public function test_record_blocked_leaves_wp_run_at(): void {
		global $wpdb;
		$id = $this->running_job();
		$wpdb->update( JobRepository::table(), array( 'wp_run_at' => 12345 ), array( 'id' => $id ) );
		$job = Plugin::instance()->jobs()->find( $id );
		Plugin::instance()->jobs()->record_blocked( $job );
		$row = self::row( $id );
		$this->assertSame( '1', $row['blocked_count'], 'the control: it wrote' );
		$this->assertSame( '12345', $row['wp_run_at'] );
	}
}
