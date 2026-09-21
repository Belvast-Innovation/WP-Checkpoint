<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\InvalidTransition;
use WPCheckpoint\Jobs\Job;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class JobTest extends TestCase {

	public function test_every_status_pair_is_classified(): void {
		$allowed = array(
			'queued->running', 'queued->failed', 'queued->cancelled',
			'running->completed', 'running->failed', 'running->paused', 'running->cancelled',
			'paused->running', 'paused->failed', 'paused->cancelled',
			'failed->queued',
		);
		foreach ( Job::statuses() as $from ) {
			foreach ( Job::statuses() as $to ) {
				$expected = in_array( $from . '->' . $to, $allowed, true );
				$this->assertSame( $expected, Job::allows( $from, $to ), $from . '->' . $to );
			}
		}
	}

	public function test_transition_moves_or_throws(): void {
		$job         = new Job();
		$job->id     = 7;
		$job->status = Job::QUEUED;
		$this->assertSame( Job::RUNNING, $job->transition( Job::RUNNING )->status );
		$this->assertSame( Job::PAUSED, $job->transition( Job::PAUSED )->status );
		$this->assertSame( Job::RUNNING, $job->transition( Job::RUNNING )->status );
		$this->assertSame( Job::FAILED, $job->transition( Job::FAILED )->status );
		$this->assertSame( Job::QUEUED, $job->transition( Job::QUEUED )->status, 'retry' );

		$job->status = Job::COMPLETED;
		$this->assertTrue( $job->is_terminal() );
		$this->expectException( InvalidTransition::class );
		$this->expectExceptionMessage( 'Job 7 cannot go from completed to running.' );
		$job->transition( Job::RUNNING );
	}

	public function test_terminal_statuses(): void {
		foreach ( array( Job::COMPLETED, Job::CANCELLED ) as $status ) {
			$job         = new Job();
			$job->status = $status;
			$this->assertTrue( $job->is_terminal(), $status );
		}
		foreach ( array( Job::QUEUED, Job::RUNNING, Job::PAUSED, Job::FAILED ) as $status ) {
			$job         = new Job();
			$job->status = $status;
			$this->assertFalse( $job->is_terminal(), $status );
		}
	}

	public function test_unknown_status_is_neither_terminal_nor_movable(): void {
		$job         = new Job();
		$job->id     = 3;
		$job->status = 'garbage';
		$this->assertFalse( $job->is_terminal() );
		foreach ( Job::statuses() as $to ) {
			$this->assertFalse( $job->can_transition( $to ), $to );
		}
		$this->expectException( InvalidTransition::class );
		$job->transition( Job::CANCELLED );
	}

	public function test_lock_validity(): void {
		$job = new Job();
		$this->assertFalse( $job->is_locked( 1000 ) );
		$job->lock_token   = 'abc';
		$job->locked_until = 1100;
		$this->assertTrue( $job->is_locked( 1000 ) );
		$this->assertFalse( $job->is_locked( 1100 ) );
	}

	public function test_only_a_failed_job_with_its_work_files_can_be_retried(): void {
		$job         = new Job();
		$job->status = Job::FAILED;
		$this->assertTrue( $job->can_retry() );
		$job->work_expired_at = 1700000000;
		$this->assertFalse( $job->can_retry(), 'work files reclaimed after retention' );
		$job->work_expired_at = 0;
		foreach ( array( Job::QUEUED, Job::RUNNING, Job::PAUSED, Job::COMPLETED, Job::CANCELLED ) as $status ) {
			$job->status = $status;
			$this->assertFalse( $job->can_retry(), $status );
		}
	}
}
