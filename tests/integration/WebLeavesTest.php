<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * What a run made without WordPress leaves to WP-CLI as it is: a job taken over MAX_TAKEOVERS times at the same position
 * (not failed: the site stays held, and failing would keep it so), and every case in which WP-CLI would ask (it never
 * writes a cursor of a phase that asks, nor questions, nor pauses the job).
 */
final class WebLeavesTest extends SwapTestCase {

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

	public function test_taken_over_too_often_the_job_is_left_to_wp_cli_not_failed(): void {
		global $wpdb;
		$this->swap_parts['batch'] = 1;
		$this->register_type();
		$job = $this->at_swap();
		$this->killed_at( $job, 'batch_sent', 1 ); // Its lease left behind, run out.
		// Taken over at this position once short of the limit: the next claim reaches it.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . JobRepository::table() . " SET takeovers = %d, takeover_mark = MD5(CONCAT(step, '|', COALESCE(cursor_json, ''))) WHERE id = %d", Runner::MAX_TAKEOVERS - 1, $job->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the jobs table.
		$wpdb->query( 'COMMIT' );
		$before      = self::row( $job->id );
		$this->assertNotSame( '', $before['lock_token'], 'the control: the killed run\'s lease is there' );
		$this->seams = array();
		$result      = $this->web_tick( Plugin::instance()->jobs()->find( $job->id ) );
		$after       = self::row( $job->id );
		$this->assertSame( TickResult::LEFT, $result->status, $result->message );
		$this->assertSame( (string) Runner::MAX_TAKEOVERS, $after['takeovers'], 'the control: the claim counted the limit' );
		$this->assertSame( array(), $this->seams, 'nothing of the swap ran' );
		$claim = array_flip( array( 'lock_token', 'locked_until', 'updated_at', 'takeovers', 'takeover_mark' ) );
		$this->assertSame( array_diff_key( $before, $claim ), array_diff_key( $after, $claim ), 'the row as it was but its lease and the claim\'s count' );
		$this->assertNotSame( Job::FAILED, $after['status'] );
		$this->assertSame( '', $after['lock_token'], 'the lease let go' );
		$this->assertNotSame( array(), $this->maintenance_files(), 'the site still held' );
	}

	public function test_where_wp_cli_would_ask_a_run_without_wordpress_never_writes_a_question(): void {
		global $wpdb;
		$job = $this->at_swap();
		$this->killed_at( $job, 'committed' );
		$committed = Plugin::instance()->jobs()->find( $job->id );
		$base      = array(
			'attempt' => $committed->cursor['attempt'],
			'mark'    => $committed->cursor['mark'],
		);
		// The cases in which WP-CLI asks (carry_asked(), ask_after_undo(), redo_asked(), the check that could not tell):
		// the cursor as it stands just before the question.
		$cases = array(
			'an undo after the site was let out' => array(
				'phase'  => 'rollback',
				'reason' => SwapStep::UNDONE,
				'out'    => true,
			) + $base,
			'a redo of an undone restore'        => array(
				'phase'     => 'rollback',
				'reason'    => SwapStep::INTERRUPTED,
				'undone_at' => time() - 60,
			) + $base,
			'a check that could not tell'        => array(
				'phase'      => 'committed',
				'post'       => 'verify',
				'unverified' => array( 'why' => 'none' ),
			) + $base,
		);
		$asking = array( 'carry_ask', 'undone_ask', 'redo_ask' );
		foreach ( $cases as $case => $cursor ) {
			$wpdb->query( 'COMMIT' );
			$wpdb->update(
				JobRepository::table(),
				array(
					'cursor_json'    => wp_json_encode( $cursor ),
					'status'         => Job::RUNNING,
					'questions_json' => null,
					'lock_token'     => '',
					'locked_until'   => 0,
				),
				array( 'id' => $job->id )
			);
			$wpdb->query( 'COMMIT' );
			list( $now ) = $this->web_run( Plugin::instance()->jobs()->find( $job->id ) );
			$row         = self::row( $job->id );
			$written     = json_decode( (string) $row['cursor_json'], true );
			$this->assertNotContains( $written['phase'] ?? '', $asking, $case );
			$this->assertNotSame( 'ask', $written['post'] ?? '', $case );
			$this->assertContains( $row['questions_json'], array( null, '', '[]' ), $case );
			$this->assertNotSame( Job::PAUSED, $now->status, $case );
		}
	}
}
