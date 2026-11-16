<?php

namespace WPCheckpoint\Tests\Unit\Admin;

use WPCheckpoint\Admin\PutBackNotice;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\RestoreJob;
use WPCheckpoint\Jobs\SwapStep;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The notice of a restore that ended with the site put back: every reason a swap is put back for is one it tells of
 * or one it does not; it reads the row's type and status and the cursor's phase and reason, nothing else; each
 * sentence says the site is as it was, and only the one of a visitor's put-back speaks of a visitor.
 */
final class PutBackNoticeTest extends TestCase {

	private static function job( string $reason, array $more = array() ): Job {
		$job         = new Job();
		$job->id     = 41;
		$job->type   = RestoreJob::ID;
		$job->status = Job::FAILED;
		$job->cursor = array(
			'phase'  => 'reverted',
			'mark'   => 'abc',
			'reason' => $reason,
		);
		foreach ( $more as $name => $value ) {
			$job->$name = $value;
		}
		return $job;
	}

	public function test_every_reason_a_swap_is_put_back_for_is_told_of_or_not(): void {
		$known = array_keys( SwapStep::REASONS );
		$sorted = array_keys( PutBackNotice::TELLS );
		sort( $known );
		sort( $sorted );
		$this->assertSame( $known, $sorted, 'a reason added to SwapStep::REASONS is put under PutBackNotice::TELLS as told of or not' );
		$this->assertGreaterThan( 2, count( $known ), 'the control: the reasons were read' );
		foreach ( PutBackNotice::TELLS as $reason => $tells ) {
			$this->assertIsBool( $tells, $reason );
			$sentence = PutBackNotice::sentence( $reason );
			if ( $tells ) {
				$this->assertNotSame( '', $sentence, $reason . ': a reason told of has its sentence' );
				$this->assertStringContainsString( 'as it was before the restore', $sentence, $reason );
				$this->assertSame( SwapStep::ABANDONED === $reason, false !== stripos( $sentence, 'visitor' ), $reason . ': only a visitor\'s put-back speaks of a visitor' );
				$this->assertStringNotContainsString( 'WP-CLI run', $sentence, $reason . ': who put the site back is not said' );
			} else {
				$this->assertSame( '', $sentence, $reason . ': no sentence for a reason not told of' );
			}
		}
		$this->assertSame( array( SwapStep::ASKED, SwapStep::PUT_BACK_REASON ), array_keys( PutBackNotice::TELLS, false, true ), 'what an administrator asked for is not told of' );
		$this->assertSame( '', PutBackNotice::sentence( 'some_new_reason' ) );
	}

	public function test_the_sentences_differ_from_one_another(): void {
		$sentences = array_filter( array_map( array( PutBackNotice::class, 'sentence' ), array_keys( PutBackNotice::TELLS ) ) );
		$this->assertCount( 7, $sentences );
		$this->assertCount( 7, array_unique( $sentences ) );
	}

	public function test_it_tells_of_a_failed_restore_put_back_for_a_reason_told_of_and_of_nothing_else(): void {
		foreach ( PutBackNotice::TELLS as $reason => $tells ) {
			$this->assertSame( $tells ? $reason : '', PutBackNotice::reason( self::job( $reason ) ), $reason );
			$this->assertSame( $tells, array() !== PutBackNotice::notice( self::job( $reason ) ), $reason );
		}
		$told = SwapStep::INTERRUPTED;
		$this->assertSame( $told, PutBackNotice::reason( self::job( $told ) ), 'the control' );
		$this->assertSame( '', PutBackNotice::reason( self::job( $told, array( 'type' => 'export' ) ) ), 'not a restore' );
		foreach ( array( Job::QUEUED, Job::RUNNING, Job::PAUSED, Job::COMPLETED, Job::CANCELLED ) as $status ) {
			$this->assertSame( '', PutBackNotice::reason( self::job( $told, array( 'status' => $status ) ) ), $status );
		}
		foreach ( array( 'rollback', 'reverse', 'restored', 'committed', 'done', '' ) as $phase ) {
			$job                  = self::job( $told );
			$job->cursor['phase'] = $phase;
			$this->assertSame( '', PutBackNotice::reason( $job ), 'phase "' . $phase . '"' );
		}
		$this->assertSame( '', PutBackNotice::reason( self::job( $told, array( 'cursor' => array() ) ) ), 'a cursor emptied by a retry' );
		$this->assertSame( '', PutBackNotice::reason( self::job( 'some_new_reason' ) ), 'a reason it does not know' );
		$job                   = self::job( $told );
		$job->cursor['reason'] = array( $told );
		$this->assertSame( '', PutBackNotice::reason( $job ), 'a reason that is no string' );
	}

	public function test_the_notice_holds_its_own_words_the_command_and_the_job_and_nothing_a_run_wrote(): void {
		$wrote  = 'Step "restore_swap": RetryFrom: something a run wrote, /srv/site/wp-content and example.org.';
		$job    = self::job(
			SwapStep::BUSY,
			array(
				'last_error'       => $wrote,
				'progress_message' => 'progress a run wrote',
			)
		);
		$notice = PutBackNotice::notice( $job );
		$this->assertSame( 'wp wpcheckpoint job retry 41', $notice['extra'] );
		$this->assertSame( '41', $notice['seen'], 'dismissed for this job' );
		$this->assertTrue( $notice['dismissible'] );
		$this->assertSame( PutBackNotice::sentence( SwapStep::BUSY ) . ' To try the restore again, run this in WP-CLI:', $notice['message'] );
		$all = implode( ' ', array_map( 'strval', $notice ) );
		foreach ( array( 'something a run wrote', '/srv/site', 'example.org', 'progress a run wrote', 'RetryFrom' ) as $not ) {
			$this->assertStringNotContainsString( $not, $all );
		}
		$this->assertStringContainsString( 'something a run wrote', (string) $job->last_error, 'the control: the row holds what is not shown' );
	}

	public function test_a_restore_whose_work_files_are_gone_is_not_said_to_be_retried(): void {
		$job    = self::job( SwapStep::REVERSED, array( 'work_expired_at' => 1700000000 ) );
		$notice = PutBackNotice::notice( $job );
		$this->assertSame( '', $notice['extra'] );
		$this->assertStringContainsString( 'cannot be tried again', $notice['message'] );
		$this->assertStringContainsString( PutBackNotice::sentence( SwapStep::REVERSED ), $notice['message'] );
		$this->assertStringContainsString( 'job retry', PutBackNotice::notice( self::job( SwapStep::REVERSED ) )['extra'], 'the control: while they are there, the command' );
	}
}
