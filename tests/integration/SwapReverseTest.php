<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
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

	public function test_a_restored_site_that_fails_its_checks_is_swapped_back_out_and_the_job_fails_with_a_retry(): void {
		$this->swap_parts['verify'] = static function (): bool {
			return false;
		};
		$this->register_type();
		$job    = $this->at_swap();
		$before = $this->site();
		$done   = $this->cli_run( $job );
		$this->assertSame( Job::FAILED, $done->status, (string) $done->last_error );
		$this->assertTrue( $done->retry_useful(), 'a retry swaps again from the final check' );
		$this->assertStringContainsString( 'The restored site was swapped back out after the swap was made', (string) $done->last_error );
		$this->assertStringContainsString( 'The swap was reversed: the site is as it was before the restore.', (string) $done->last_error );
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
		$this->assertStringContainsString( 'The restored site was swapped back out after the swap was made', $log );

		// The way on: a retry swaps again, and with the checks passing the restore completes.
		$this->retype( $job, array( 'verify' => static function (): bool {
			return true;
		} ) );
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
		$this->assertNotContains( 'reverse_recorded', $this->seams );
		$this->assertStringNotContainsString( 'reversed', $this->log_of( $done ) );
	}

	public function test_a_run_that_dies_in_the_reverse_is_continued_in_the_same_direction(): void {
		$this->swap_parts['batch']  = 1;
		$this->swap_parts['verify'] = static function (): bool {
			return false;
		};
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
