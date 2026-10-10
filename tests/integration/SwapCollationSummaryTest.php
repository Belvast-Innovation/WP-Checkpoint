<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Plugin;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * The swap's last word about the collations the restore's check wrote under other names: taken from what the check
 * recorded on the job, said only when the restored site is in place and the count is there, never after a rollback.
 */
final class SwapCollationSummaryTest extends SwapTestCase {

	const ELEVEN_MINUTES = 660;

	/**
	 * Write a count on the job's row as the collation check records it.
	 */
	private function record( Job $job, $count ): void {
		global $wpdb;
		$options = $job->options;
		if ( null === $count ) {
			unset( $options['recorded'] );
		} else {
			$options['recorded'] = array( 'collations_mapped' => $count );
		}
		$wpdb->update( JobRepository::table(), array( 'options_json' => wp_json_encode( $options ) ), array( 'id' => $job->id ) );
		$wpdb->query( 'COMMIT' );
	}

	public function test_a_completed_swap_says_how_many_collations_were_written_under_other_names(): void {
		$job = $this->at_swap();
		$this->record( $job, 2 );
		$done = $this->cli_run( $job );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$this->assertSame( 'The restored site is in place 2 collations the backup uses were written under names this server knows; the job log says which.', $done->progress_message );
	}

	public function test_without_a_count_or_with_none_mapped_the_swap_says_only_that_the_site_is_in_place(): void {
		$job = $this->at_swap();
		$this->record( $job, 0 );
		$done = $this->cli_run( $job );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$this->assertSame( 'The restored site is in place', $done->progress_message, 'none mapped: nothing said' );
		$this->assertSame( 0, Plugin::instance()->jobs()->find( $job->id )->options['recorded']['collations_mapped'], 'the control: the count was on the row' );
	}

	public function test_a_swap_that_put_the_site_back_does_not_say_it(): void {
		$this->clock_offset = -self::ELEVEN_MINUTES;
		$job                = $this->at_swap();
		$this->record( $job, 2 );
		$this->killed_at( $job, 'dir_in', 2 );
		$done = $this->cli_run( $job );
		$this->assertSame( Job::FAILED, $done->status, (string) $done->last_error );
		$this->assertSame( 2, Plugin::instance()->jobs()->find( $job->id )->options['recorded']['collations_mapped'], 'the control: the count is still on the row' );
		$this->assertStringNotContainsString( 'collation', $done->progress_message );
		$this->assertStringNotContainsString( 'collation', (string) $done->last_error );
	}
}
