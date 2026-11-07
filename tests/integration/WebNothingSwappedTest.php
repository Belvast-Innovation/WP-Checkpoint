<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * "Nothing was swapped" (SwapStep::NOTHING_SWAPPED) is decided only in the checkpoint that turns a forward cursor into
 * a rollback, from enter or from dirs before anything was renamed, by the units' own rules; a run without WordPress
 * that cannot flush then skips the flush and ends the job. What is there later never decides it (put back and never
 * swapped look the same); a unit that finds something to rename drops it, and the flush is not skipped.
 */
final class WebNothingSwappedTest extends SwapTestCase {

	/**
	 * A restore killed with its maintenance file up and nothing renamed (the enter phase).
	 *
	 * @return array{0: Job, 1: array<string, mixed>} The job, the site before the restore.
	 */
	private function entered(): array {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'maintenance' );
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 'enter', $now->cursor['phase'] ?? '', 'the control: killed in the enter phase' );
		$this->assertTrue( $this->site()['maintenance'], 'the control: its file up' );
		return array( $now, $before );
	}

	/**
	 * Write a job's cursor.
	 *
	 * @param Job                  $job    Job.
	 * @param array<string, mixed> $cursor Cursor.
	 * @return Job
	 */
	private function with_cursor( Job $job, array $cursor ): Job {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$wpdb->update( JobRepository::table(), array( 'cursor_json' => wp_json_encode( $cursor ) ), array( 'id' => $job->id ) );
		$wpdb->query( 'COMMIT' );
		return Plugin::instance()->jobs()->find( $job->id );
	}

	/**
	 * Assert a job ended by a run that could not flush, with nothing swapped recorded: the flush skipped, the site as
	 * before, the file down.
	 *
	 * @param Job                  $job    Job.
	 * @param array<string, mixed> $before The site before.
	 * @return void
	 */
	private function assert_ended_without_flush( Job $job, array $before ): void {
		$this->seams         = array();
		list( $done, $runs ) = $this->web_run( $job, array(), false );
		$last                = end( $runs );
		$this->assertNotSame( TickResult::LEFT, $last->status, 'not stopped before the flush: ' . $last->message );
		$this->assertSame( Job::FAILED, $done->status, $done->last_error );
		$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state );
		$this->assertNotContains( 'flushed', $this->seams, 'the flush skipped' );
		$this->assertContains( 'restored', $this->seams, 'recorded as put back' );
		$this->assertSame( $before, $this->site() );
		$this->assertStringContainsString( 'Nothing of the restored site was swapped in', Plugin::instance()->job_presenter()->log_tail( $done ) );
	}

	public function test_from_enter_it_is_recorded_and_the_flush_is_skipped(): void {
		list( $job, $before ) = $this->entered();
		$this->assert_ended_without_flush( $job, $before );
	}

	public function test_from_dirs_before_a_rename_it_is_recorded_and_the_flush_is_skipped(): void {
		list( $job, $before ) = $this->entered();
		// As the swap leaves it after its first checkpoint of the dirs phase, before its first rename.
		$job = $this->with_cursor(
			$job,
			array(
				'phase'   => 'dirs',
				'attempt' => $job->cursor['attempt'],
				'mark'    => $job->cursor['mark'],
				'i'       => 0,
				'step'    => 'a',
			)
		);
		$this->assert_ended_without_flush( $job, $before );
	}

	public function test_with_a_directory_already_renamed_it_is_not_recorded_and_the_run_stops_before_the_flush(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'dir_aside' );
		$this->seams         = array();
		list( $now, $runs )  = $this->web_run( Plugin::instance()->jobs()->find( $job->id ), array(), false );
		$this->assertSame( TickResult::LEFT, end( $runs )->status, end( $runs )->message );
		$this->assertContains( 'dir_back', $this->seams, 'the control: something was put back' );
		$this->assertArrayNotHasKey( SwapStep::NOTHING_SWAPPED, $now->cursor );
		$this->assertTrue( $this->site()['maintenance'], 'held, for WP-CLI to flush' );
		$done = $this->cli_run( $now );
		$this->assertSame( $before, $this->site(), $done->last_error );
	}

	public function test_a_rollback_put_back_and_stopped_before_its_flush_is_not_recorded_by_a_later_run(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'dir_in', 2 );
		list( $stopped, $runs ) = $this->web_run( Plugin::instance()->jobs()->find( $job->id ), array(), false );
		$this->assertSame( TickResult::LEFT, end( $runs )->status, 'the control: renamed back, stopped before the flush' );
		$site                = $this->site();
		$site['maintenance'] = false;
		$this->assertSame( $before, $site, 'the control: everything where it was (as if never swapped)' );
		// The next run finds every unit empty: put back looks like never swapped; it does not record it.
		$this->seams = array();
		$again       = $this->web_tick( $stopped, array(), false );
		$after       = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( TickResult::LEFT, $again->status, $again->message );
		$this->assertContains( 'dirs_back', $this->seams, 'the control: it went through the units again' );
		$this->assertArrayNotHasKey( SwapStep::NOTHING_SWAPPED, $after->cursor );
		$this->assertNotContains( 'restored', $this->seams );
		$this->assertTrue( $this->site()['maintenance'], 'still held' );
	}

	public function test_a_record_a_unit_contradicts_is_dropped_and_the_flush_is_not_skipped(): void {
		list( $job, $before ) = $this->entered();
		// Recorded as nothing swapped, and then a directory found moved aside: the units have something to rename.
		$job   = $this->with_cursor(
			$job,
			array(
				'phase'                   => 'rollback',
				'attempt'                 => $job->cursor['attempt'],
				'mark'                    => $job->cursor['mark'],
				'reason'                  => SwapStep::INTERRUPTED,
				SwapStep::NOTHING_SWAPPED => true,
			)
		);
		$entry = null;
		foreach ( $this->plan_of( $job )['dirs'] as $dir ) {
			if ( ! empty( $dir['had_live'] ) ) {
				$entry = $dir;
				break;
			}
		}
		$this->assertNotNull( $entry, 'the control: a directory the site had' );
		$this->assertTrue( rename( $entry['live'], $entry['old'] ) );
		$this->seams        = array();
		list( $now, $runs ) = $this->web_run( $job, array(), false );
		$this->assertContains( 'dir_back', $this->seams, 'the control: the unit renamed it back' );
		$this->assertSame( TickResult::LEFT, end( $runs )->status, 'stopped before the flush, not skipped: ' . end( $runs )->message );
		$this->assertArrayNotHasKey( SwapStep::NOTHING_SWAPPED, $now->cursor, 'the record dropped' );
		$this->assertStringContainsString( 'a unit found something to rename: the record is dropped', Plugin::instance()->job_presenter()->log_tail( $now ) );
		$done = $this->cli_run( $now );
		$this->assertContains( 'flushed', $this->seams, 'WP-CLI flushed' );
		$this->assertSame( $before, $this->site(), $done->last_error );
	}
}
