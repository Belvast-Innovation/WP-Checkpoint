<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobPresenter;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The words the screen uses for a job: its state, its step, what a
 * failure means, and the failure without the runner's frame.
 */
final class JobPresenterTextTest extends TestCase {

	private static function job( string $status, string $kind = '', array $questions = array() ): Job {
		$job               = new Job();
		$job->type         = 'export';
		$job->status       = $status;
		$job->failure_kind = $kind;
		$job->questions    = $questions;
		return $job;
	}

	public function test_the_detail_keeps_the_message_and_drops_the_step_and_the_class(): void {
		$this->assertSame( 'The file preflight.json of this job is missing.', JobPresenter::error_detail( 'Step "review": RuntimeException: The file preflight.json of this job is missing.' ) );
		$this->assertSame( 'A chunk file could not be written.', JobPresenter::error_detail( 'Step "pack" failed 6 times: TransientFailure: A chunk file could not be written.' ) );
		$this->assertSame( 'No progress for 24 hours; the job was given up.', JobPresenter::error_detail( 'No progress for 24 hours; the job was given up.' ), 'other texts are left as they are' );
		$this->assertSame( 'Boom.', JobPresenter::error_detail( 'Step "a": WPCheckpoint\\Jobs\\Oops: Boom.' ), 'a namespaced class' );
		$this->assertSame( 'Boom.', JobPresenter::error_detail( 'Step "a": Http2Error: Boom.' ), 'a class with digits' );
		$this->assertSame( 'Could not make progress within the budget (3 attempts).', JobPresenter::error_detail( 'Step "pack" could not make progress within the budget (3 attempts).' ) );
	}

	public function test_the_detail_is_empty_when_the_pattern_fails(): void {
		// The control: the same frame on valid text is taken off.
		$this->assertSame( 'Kept.', JobPresenter::strip_frame( 'Step "a": RuntimeException: Kept.' ) );
		// Invalid UTF-8 makes the pattern fail: nothing is shown, not the text with its frame.
		$this->assertSame( '', JobPresenter::strip_frame( "Step \"a\": RuntimeException: Kept.\xC3\x28" ) );
		// error_detail() scrubs first, so such text still has its detail.
		$this->assertStringStartsWith( 'Kept.', JobPresenter::error_detail( "Step \"a\": RuntimeException: Kept.\xC3\x28" ) );
	}

	public function test_a_job_waiting_for_an_answer_needs_a_decision_it_is_not_paused(): void {
		$this->assertSame( 'Needs your decision', JobPresenter::status_text( self::job( Job::PAUSED, '', array( array( 'id' => 'q' ) ) ) ) );
		$this->assertSame( 'Continuing', JobPresenter::status_text( self::job( Job::PAUSED ) ), 'answered, not yet picked up' );
		$this->assertSame( 'Queued', JobPresenter::status_text( self::job( Job::QUEUED ) ) );
	}

	public function test_steps_are_named_in_words(): void {
		$this->assertSame( 'Exporting the database', JobPresenter::step_label( 'database' ) );
		$this->assertSame( 'Reviewing what to back up', JobPresenter::step_label( 'review' ) );
		$this->assertSame( 'custom', JobPresenter::step_label( 'custom' ), 'a step of another plugin keeps its id' );
	}

	public function test_a_failure_says_whether_retrying_can_help(): void {
		$this->assertStringContainsString( 'Retry when it is solved', JobPresenter::failure_text( self::job( Job::FAILED, Job::FAILURE_TEMPORARY ) ) );
		$this->assertStringContainsString( 'retrying would fail the same way. Create a new backup.', JobPresenter::failure_text( self::job( Job::FAILED, Job::FAILURE_FINAL ) ) );
		$this->assertTrue( self::job( Job::FAILED, Job::FAILURE_TEMPORARY )->retry_useful() );
		$this->assertFalse( self::job( Job::FAILED, Job::FAILURE_FINAL )->retry_useful() );
		$this->assertTrue( self::job( Job::FAILED )->retry_useful(), 'no kind (any other cause, or failed before kinds were recorded): offered' );
		$this->assertSame( 'The backup could not be finished. Retry goes on where it stopped; if it fails the same way again, create a new backup.', JobPresenter::failure_text( self::job( Job::FAILED ) ) );
		$changed                 = self::job( Job::FAILED, Job::FAILURE_FINAL );
		$changed->failure_reason = Job::REASON_TABLE_CHANGED;
		$this->assertSame( 'The backup was stopped because the structure of a table changed while it was being exported. Going on would give a backup whose table data does not match its table definition. Create a new backup.', JobPresenter::failure_text( $changed ) );
		$this->assertFalse( $changed->retry_useful() );
		$expired                  = self::job( Job::FAILED, Job::FAILURE_TEMPORARY );
		$expired->work_expired_at = 1;
		$this->assertFalse( $expired->retry_useful() );
		$this->assertStringContainsString( 'its work files have been removed', JobPresenter::failure_text( $expired ) );
	}
}
