<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Jobs\Stopped;
use WPCheckpoint\Plugin;
use WPCheckpoint\Tests\Fixtures\Jobs\ClosureStep;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * An answer that stops the job goes with the failure: a retry asks that
 * question again, and the answers that let the job go on stay.
 */
final class StoppedTest extends JobTestCase {

	/**
	 * A job whose one step asks "big" (exclude / stop) and "odd" (continue / stop), and stops naming every "stop".
	 */
	private function job(): int {
		$this->register(
			'stopped_by_answer',
			array(
				new ClosureStep(
					'review',
					static function ( JobContext $ctx ): StepResult {
						$answers   = (array) ( $ctx->options()['answers'] ?? array() );
						$questions = array();
						$stops     = array();
						foreach ( array( 'big' => array( 'exclude', 'stop' ), 'odd' => array( 'continue', 'stop' ) ) as $id => $choices ) {
							if ( ! isset( $answers[ $id ] ) ) {
								$questions[] = array(
									'id'      => $id,
									'kind'    => $id,
									'count'   => 1,
									'choices' => $choices,
								);
							} elseif ( 'stop' === $answers[ $id ] ) {
								$stops[] = $id;
							}
						}
						if ( array() !== $stops ) {
							throw new Stopped( 'Stopped at ' . implode( ', ', $stops ) . '.', $stops );
						}
						return array() === $questions ? StepResult::done( 'reviewed' ) : StepResult::ask( array(), $questions, 'asking' );
					}
				),
			)
		);
		return $this->job_of( 'stopped_by_answer', false, array( 'policy' => array( 'odd' => 'ask' ) ) );
	}

	private function drive( int $id ): Job {
		for ( $i = 0; $i < 20; $i++ ) {
			$job = Plugin::instance()->jobs()->find( $id );
			$answered = Job::PAUSED === $job->status && array() === $job->questions; // Resumes at the next tick.
			if ( ! $answered && ! in_array( $job->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				return $job;
			}
			Plugin::instance()->runner()->tick( $id, microtime( true ) );
		}
		$this->fail( 'the job did not stop' );
	}

	/**
	 * @param string[] $expected Ids of the questions asked.
	 */
	private function assert_asks( Job $job, array $expected ): void {
		$this->assertSame( Job::PAUSED, $job->status, (string) $job->last_error );
		$this->assertSame( $expected, array_column( $job->questions, 'id' ) );
	}

	public function test_a_retry_after_a_stop_asks_that_question_again_and_keeps_the_other_answers(): void {
		$id = $this->job();
		$this->assert_asks( $this->drive( $id ), array( 'big', 'odd' ) );
		Plugin::instance()->job_actions()->answer(
			$id,
			array(
				'big' => 'exclude',
				'odd' => 'stop',
			)
		);
		$job = $this->drive( $id );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'Stopped at odd.', (string) $job->last_error );
		$this->assertSame( array( 'big' => 'exclude' ), $job->options['answers'], 'the stop is gone with the failure, the exclusion stays' );
		$this->assertSame( array( 'odd' => 'ask' ), $job->options['policy'], 'and the other options' );

		Plugin::instance()->job_actions()->retry( $id );
		$job = $this->drive( $id );
		$this->assert_asks( $job, array( 'odd' ) );
		Plugin::instance()->job_actions()->answer( $id, array( 'odd' => 'continue' ) );
		$job = $this->drive( $id );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$this->assertSame(
			array(
				'big' => 'exclude',
				'odd' => 'continue',
			),
			$job->options['answers']
		);
	}

	public function test_the_only_answer_a_stop_removes_is_its_own(): void {
		$id = $this->job();
		$this->drive( $id );
		Plugin::instance()->job_actions()->answer(
			$id,
			array(
				'big' => 'stop',
				'odd' => 'continue',
			)
		);
		$job = $this->drive( $id );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertSame( array( 'odd' => 'continue' ), $job->options['answers'] );
		Plugin::instance()->job_actions()->retry( $id );
		$this->assert_asks( $this->drive( $id ), array( 'big' ) );
	}

	public function test_two_stops_are_both_asked_again_by_one_retry(): void {
		$id = $this->job();
		$this->drive( $id );
		Plugin::instance()->job_actions()->answer(
			$id,
			array(
				'big' => 'stop',
				'odd' => 'stop',
			)
		);
		$job = $this->drive( $id );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertArrayNotHasKey( 'answers', $job->options, 'both answers went with the failure' );
		Plugin::instance()->job_actions()->retry( $id );
		$this->assert_asks( $this->drive( $id ), array( 'big', 'odd' ) );
	}

	public function test_any_other_failure_keeps_every_answer(): void {
		$failed = false;
		$this->register(
			'other_failure',
			array(
				new ClosureStep(
					'review',
					static function ( JobContext $ctx ) use ( &$failed ): StepResult {
						if ( ! isset( $ctx->options()['answers']['odd'] ) ) {
							return StepResult::ask(
								array(),
								array(
									array(
										'id'      => 'odd',
										'kind'    => 'odd',
										'count'   => 1,
										'choices' => array( 'continue', 'stop' ),
									),
								),
								'asking'
							);
						}
						if ( ! $failed ) {
							$failed = true;
							throw new \RuntimeException( 'Something else went wrong.' );
						}
						return StepResult::done( 'done' );
					}
				),
			)
		);
		$id = $this->job_of( 'other_failure', false );
		$this->drive( $id );
		Plugin::instance()->job_actions()->answer( $id, array( 'odd' => 'continue' ) );
		$job = $this->drive( $id );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertSame( array( 'odd' => 'continue' ), $job->options['answers'] );
		Plugin::instance()->job_actions()->retry( $id );
		$this->assertSame( Job::COMPLETED, $this->drive( $id )->status, 'the retry did not ask again' );
	}
}
