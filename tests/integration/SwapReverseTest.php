<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\SwapCheckStep;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * The reverse of a swap that was made (SwapStep::reverse()): when the restored site fails its checks (the step's
 * "verify" part stands in for 4c-B's checks) the direction is recorded again, the site is put back behind the held
 * maintenance file from what is there, and the job ends failed with the reason, a retry swapping again. A run that
 * dies in the reverse is continued by the next one in the same direction; nothing of the restored site was served.
 * Without the part nothing is checked and the swap goes on as before (the control).
 */
final class SwapReverseTest extends SwapTestCase {

	private function log_of( Job $job ): string {
		return (string) file_get_contents( Plugin::instance()->directories()->base() . '/' . $job->log_path );
	}

	/**
	 * A job's tables by the names it made: temporary, and moved aside by its swap.
	 *
	 * @return string[]
	 */
	private function job_tables( Job $job ): array {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$out = array();
		foreach ( array( \WPCheckpoint\Jobs\TempTables::job_prefix( $job->storage_token, $job->id ), \WPCheckpoint\Jobs\TempTables::OLD_PREFIX . substr( $job->storage_token, 0, \WPCheckpoint\Jobs\TempTables::TOKEN_LEN ) . '_' . $job->id . '_' ) as $prefix ) {
			$out = array_merge( $out, (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ) );
		}
		sort( $out );
		return $out;
	}

	/**
	 * The kinds of the staging a job left next to the site's directories (by the names it made).
	 *
	 * @return string[]
	 */
	private function staging_of( Job $job ): array {
		$out = array();
		foreach ( \WPCheckpoint\Jobs\Residue::scan_site( \WPCheckpoint\Jobs\Residue::site_dirs( $this->dirs ), array( $job->storage_token ) ) as $entry ) {
			if ( $entry['id'] === $job->id ) {
				$out[] = $entry['kind'] . ' ' . $entry['path'];
			}
		}
		sort( $out );
		return $out;
	}

	public function test_a_restored_site_that_fails_its_checks_is_swapped_back_out_and_the_job_fails_with_a_retry(): void {
		$this->verdicts             = array( 'home' );
		$this->swap_parts['verify'] = $this->verdicts();
		$this->register_type();
		$job    = $this->at_swap();
		$before = $this->site();
		$tables = $this->job_tables( $job );
		$this->assertNotSame( array(), $tables, 'the control: the restored copy has tables' );
		$done = $this->cli_run( $job );
		$this->assertSame( Job::FAILED, $done->status, (string) $done->last_error );
		$this->assertTrue( $done->retry_useful(), 'a retry swaps again from the final check' );
		$this->assertStringContainsString( 'A check of the restored site found it broken: the site was put back as it was before the restore.', (string) $done->last_error );
		$this->assertStringContainsString( 'What failed: the home page did not answer as a WordPress site.', (string) $done->last_error, 'which check failed' );
		$this->assertStringContainsString( 'the restored copy is kept for a retry, which checks the site again', (string) $done->last_error );
		$this->assertSame( SwapCheckStep::ID, $done->cursor[ \WPCheckpoint\Jobs\JobRepository::RETRY_FROM_KEY ] ?? '', 'the retry starts at the final check' );
		// The restored copy, swapped back out, is kept for the retry: its tables under their temporary names, its
		// directories in the staging root, both the engine's residue of this (failed) job.
		$this->assertSame( $tables, $this->job_tables( $done ), 'the restored copy\'s tables are kept' );
		$this->assertNotSame( array(), array_filter( $this->staging_of( $done ), static function ( string $entry ): bool {
			return 0 === strpos( $entry, 'stage_dir ' );
		} ), 'the staging root is kept and listed as this job\'s residue' );
		$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state, 'the site is recorded as untouched again' );
		$this->assertSame( 'reverted', $done->cursor['phase'] ?? '', 'the reverse ran to its end' );
		$this->assertSame( $before, $this->site(), 'the site is as it was' );
		$this->assertFalse( Maintenance::held_in( $this->abspath ), 'the maintenance file is down' );
		$seams = $this->seams;
		$this->assertContains( 'committed', $seams, 'the control: the swap was made first' );
		$this->assertContains( 'verified', $seams );
		$this->assertContains( 'reverse_recorded', $seams );
		$this->assertContains( 'table_back', $seams, 'the tables were put back' );
		$this->assertContains( 'dir_back', $seams, 'the directories were put back' );
		$this->assertNotContains( 'unheld_commit', $seams, 'the file was never let go while the restored site was in place' );
		$this->assertGreaterThan( array_search( 'reverse_recorded', $seams, true ), array_search( 'table_back', $seams, true ), 'the direction is recorded before the first rename back' );
		$log = $this->log_of( $done );
		$this->assertStringContainsString( 'The swap is reversed after it was made', $log );
		$this->assertStringContainsString( '"check":"the home page did not answer as a WordPress site"', $log );

		// The way on: a retry swaps again, and with the checks passing the restore completes.
		$this->verdicts = array( true );
		$this->retype( $job, array( 'verify' => $this->verdicts() ) );
		$this->assertSame( Job::QUEUED, Plugin::instance()->job_actions()->retry( $job->id )->status );
		$again = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::COMPLETED, $again->status, (string) $again->last_error );
		$this->assertSame( 'restored upload', $this->site()['files']['uploads/2026/10/restored.txt'] ?? null, 'the retry swapped the restored site in' );
	}

	public function test_without_the_part_nothing_is_checked_and_the_swap_goes_on(): void {
		$this->register_type();
		$job  = $this->at_swap();
		$done = $this->cli_run( $job );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$this->assertContains( 'verified', $this->seams, 'the control: the step passed the place of the checks' );
		$this->assertContains( 'verdict_recorded', $this->seams, 'and recorded the verdict' );
		$this->assertNotContains( 'reverse_recorded', $this->seams );
		$this->assertStringNotContainsString( 'reversed', $this->log_of( $done ) );
	}

	public function test_a_run_that_dies_after_the_failed_verdict_is_recorded_is_continued_by_the_verdict_not_by_a_new_check(): void {
		$this->swap_parts['batch']  = 1;
		$this->verdicts             = array( 'login', true, true );
		$this->swap_parts['verify'] = $this->verdicts();
		$this->register_type();
		$job    = $this->at_swap();
		$before = $this->site();
		$this->trace = $this->sandbox . '/trace';
		// Killed right after the failed verdict was recorded, before the direction is: the next run reads the verdict.
		$this->killed_at( $job, 'verify_failed', 1 );
		$left = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 'committed', $left->cursor['phase'] ?? '', 'the control: the direction not yet recorded' );
		$this->assertSame( 'failed', $left->cursor['verdict'] ?? '', 'the verdict is' );
		$this->assertSame( 'login', $left->cursor['check'] ?? '' );
		$this->seams = array();
		$done        = $this->cli_run( $left );
		$this->assertSame( Job::FAILED, $done->status, (string) $done->last_error );
		$this->assertNotContains( 'verified', $this->seams, 'the next run did not check again (a second check, which would pass here, must not decide)' );
		$this->assertContains( 'reverse_recorded', $this->seams );
		$this->assertStringContainsString( 'the login page did not answer', (string) $done->last_error );
		$this->assertSame( $before, $this->site(), 'the site is as it was' );
		$this->trace = '';
	}

	public function test_a_run_that_dies_in_the_reverse_is_continued_in_the_same_direction(): void {
		$this->swap_parts['batch']  = 1;
		$this->verdicts             = array( 'home' );
		$this->swap_parts['verify'] = $this->verdicts();
		$this->register_type();
		$job    = $this->at_swap();
		$before = $this->site();
		$this->trace = $this->sandbox . '/trace';
		// Killed right after the reverse put the first table back: the direction is recorded, the file held.
		$this->killed_at( $job, 'table_back', 1 );
		$left = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 'reverse', $left->cursor['phase'] ?? '', 'the direction recorded by the killed run' );
		$this->assertSame( Job::SITE_CHANGING, $left->site_state );
		$this->assertTrue( ( new Maintenance( $this->abspath, (string) $left->cursor['mark'] ) )->is_held(), 'I1: held while the next run may rename' );
		$this->assertNotSame( $before, $this->site(), 'the control: the site is half put back' );
		$this->seams = array();
		$done        = $this->cli_run( $left );
		$this->assertSame( Job::FAILED, $done->status, (string) $done->last_error );
		$this->assertNotContains( 'committed', $this->seams, 'the next run never goes forward again' );
		$this->assertNotContains( 'reverse_recorded', $this->seams, 'and does not record the direction twice' );
		$this->assertContains( 'restored', $this->seams );
		$this->assertSame( $before, $this->site(), 'the site is as it was' );
		$this->assertFalse( Maintenance::held_in( $this->abspath ) );
		$this->trace = '';
	}
}
