<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Plugin;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * A run made without WordPress writes its decision to put the site back, and why, before it renames anything: killed
 * in the middle, the WP-CLI run that comes after goes on putting it back, as that run decided, never forward by what
 * is there. Two cases where WP-CLI on its own would have gone forward or kept the restored site: a commit recorded with
 * the site not yet let out (reversed as abandoned), and every rename gone through with no commit recorded (rolled back
 * as abandoned).
 */
final class WebKilledTest extends SwapTestCase {

	/**
	 * Assert the job ended put back as abandoned, by WP-CLI.
	 *
	 * @param Job                  $done   The job at its end.
	 * @param array<string, mixed> $before The site before the restore.
	 * @return void
	 */
	private function assert_put_back_as_abandoned( Job $done, array $before ): void {
		$this->assertSame( Job::FAILED, $done->status, (string) $done->last_error );
		$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state );
		$this->assertSame( $before, $this->site(), 'the site as it was before the restore, not the restored one' );
		$this->assertSame( SwapStep::ABANDONED, $done->cursor['reason'] ?? '', 'the reason the run without WordPress wrote' );
		$this->assertStringNotContainsString( 'as no WP-CLI run went on with the swap.', (string) $done->last_error, 'WP-CLI ended it (no visitor\'s sentence)' );
	}

	public function test_a_reverse_of_a_commit_killed_in_its_middle_is_gone_on_with_by_wp_cli(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'committed' );
		$committed = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 'committed', $committed->cursor['phase'] ?? '', 'the control: the commit recorded' );
		$this->assertSame( 'cache', $committed->cursor['post'] ?? '', 'the control: the site not let out yet' );
		$this->assertNotSame( $before, $this->site(), 'the control: the restored site swapped in' );

		$this->killed_at( $committed, 'table_back', 1, true );
		$half = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 'reverse', $half->cursor['phase'] ?? '', 'the decision written before the first rename' );
		$this->assertSame( SwapStep::ABANDONED, $half->cursor['reason'] ?? '' );

		$this->seams = array();
		$done        = $this->cli_run( $half );
		$this->assertContains( 'table_back', $this->seams, 'WP-CLI went on putting it back' );
		$this->assert_put_back_as_abandoned( $done, $before );
	}

	public function test_a_rollback_of_every_rename_without_a_commit_killed_in_its_middle_is_gone_on_with_by_wp_cli(): void {
		$this->swap_parts['batch'] = 1;
		$this->register_type();
		$job    = $this->at_swap();
		$before = $this->site();
		$tables = count( $this->plan_of( $job )['tables'] );
		$this->killed_at( $job, 'batch_sent', $tables );
		$renamed = Plugin::instance()->jobs()->find( $job->id );
		$this->assertNotSame( 'committed', $renamed->cursor['phase'] ?? '', 'the control: no commit recorded' );

		$this->killed_at( $renamed, 'table_back', 1, true );
		$half = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 'rollback', $half->cursor['phase'] ?? '', 'the decision written before the first rename' );
		$this->assertSame( SwapStep::ABANDONED, $half->cursor['reason'] ?? '', 'every rename gone through and no commit: abandoned' );

		$this->seams = array();
		$done        = $this->cli_run( $half );
		$this->assertContains( 'table_back', $this->seams, 'WP-CLI went on putting it back, not forward' );
		$this->assertNotContains( 'committed', $this->seams );
		$this->assert_put_back_as_abandoned( $done, $before );
	}
}
