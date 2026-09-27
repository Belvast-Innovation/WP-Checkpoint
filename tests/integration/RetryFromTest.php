<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\RetryFrom;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Jobs\ClosureStep;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * A step that fails naming the step a retry starts at: the job fails as for
 * any failure, and a retry starts at that step, from its start.
 */
final class RetryFromTest extends JobTestCase {

	/** @var array<string, int> Runs of each step. */
	private $runs = array();

	/**
	 * A job of three steps: a (one unit), b (two units), c (two units; the first time, it fails with $fail at its
	 * second unit, so its cursor is not empty when it fails).
	 */
	private function job_failing_with( callable $fail ): int {
		$this->runs    = array(
			'a' => 0,
			'b' => 0,
			'c' => 0,
		);
		$runs          = &$this->runs;
		$step          = function ( string $id, int $units ) use ( &$runs ): ClosureStep {
			return new ClosureStep(
				$id,
				static function ( JobContext $ctx ) use ( $id, $units, &$runs ): StepResult {
					++$runs[ $id ];
					$n = (int) ( $ctx->cursor()['n'] ?? 0 ) + 1;
					return $n >= $units ? StepResult::done( $id . ' done' ) : StepResult::progress( array( 'n' => $n ), 50, $id );
				}
			);
		};
		$failed = false;
		$this->register(
			'retry_from_fixture',
			array(
				$step( 'a', 1 ),
				$step( 'b', 2 ),
				new ClosureStep(
					'c',
					static function ( JobContext $ctx ) use ( $fail, &$failed, &$runs ): StepResult {
						++$runs['c'];
						$n = (int) ( $ctx->cursor()['n'] ?? 0 ) + 1;
						if ( ! $failed && $n >= 2 ) {
							$failed = true;
							$fail( $ctx );
						}
						return $n >= 2 ? StepResult::done( 'c done' ) : StepResult::progress( array( 'n' => $n ), 50, 'c' );
					}
				),
			)
		);
		return $this->job_of( 'retry_from_fixture', false );
	}

	private function run_job( int $id ): Job {
		for ( $i = 0; $i < 50; $i++ ) {
			$job = Plugin::instance()->jobs()->find( $id );
			if ( ! in_array( $job->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				return $job;
			}
			Plugin::instance()->runner()->tick( $id, microtime( true ) );
		}
		$this->fail( 'the job did not end' );
	}

	/**
	 * UPDATE statements on the jobs table while $call runs.
	 *
	 * @return string[]
	 */
	private function job_updates( callable $call ): array {
		global $wpdb;
		$table   = $wpdb->base_prefix . Schema::JOBS_TABLE;
		$updates = array();
		$filter  = static function ( string $sql ) use ( $table, &$updates ): string {
			if ( 0 === stripos( ltrim( $sql ), 'UPDATE' ) && false !== strpos( $sql, $table ) ) {
				$updates[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $filter );
		try {
			$call();
		} finally {
			remove_filter( 'query', $filter );
		}
		return $updates;
	}

	public function test_a_retry_starts_at_the_step_the_failure_named_from_its_start(): void {
		$id  = $this->job_failing_with(
			static function (): void {
				throw new RetryFrom( 'What b found no longer holds.', 'b' );
			}
		);
		$job = $this->run_job( $id );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'What b found no longer holds.', (string) $job->last_error );
		$this->assertSame( 'c', $job->step, 'the failed job is still at the failing step' );
		$this->assertSame( 'b', $job->cursor[ JobRepository::RETRY_FROM_KEY ] );
		$this->assertSame( '', $job->failure_kind, 'a failure a retry can get past' );
		$this->assertSame( array( 1, 2, 2 ), array_values( $this->runs ) );
		$this->assertSame( 1, $job->cursor['n'], 'the control: the failing step\'s cursor is not empty' );

		$updates = $this->job_updates(
			static function () use ( $id ): void {
				Plugin::instance()->job_actions()->retry( $id );
			}
		);
		$this->assertCount( 1, $updates, 'status, step and cursor in one statement' );
		$queued = Plugin::instance()->jobs()->find( $id );
		$this->assertSame( array( Job::QUEUED, 'b', array() ), array( $queued->status, $queued->step, $queued->cursor ) );

		$job = $this->run_job( $id );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$this->assertSame( array( 1, 4, 4 ), array_values( $this->runs ), 'b ran again from its first unit, a did not' );
	}

	public function test_a_retry_can_start_the_failing_step_itself_over(): void {
		$id = $this->job_failing_with(
			static function (): void {
				throw new RetryFrom( 'Start this step over.', 'c' );
			}
		);
		$this->run_job( $id );
		Plugin::instance()->job_actions()->retry( $id );
		$this->assertSame( array( 'c', array() ), array( Plugin::instance()->jobs()->find( $id )->step, Plugin::instance()->jobs()->find( $id )->cursor ) );
		$this->assertSame( Job::COMPLETED, $this->run_job( $id )->status );
		$this->assertSame( array( 1, 2, 4 ), array_values( $this->runs ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function named_wrongly(): array {
		return array(
			'no such step' => array( 'nowhere' ),
			'empty'        => array( '' ),
		);
	}

	/**
	 * @dataProvider named_wrongly
	 */
	public function test_a_name_that_is_no_step_of_the_job_is_not_recorded( string $name ): void {
		$id  = $this->job_failing_with(
			static function () use ( $name ): void {
				throw new RetryFrom( 'Named wrongly.', $name );
			}
		);
		$job = $this->run_job( $id );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertArrayNotHasKey( JobRepository::RETRY_FROM_KEY, $job->cursor );
		Plugin::instance()->job_actions()->retry( $id );
		$this->assertSame( 'c', Plugin::instance()->jobs()->find( $id )->step, 'the retry continues the failing step, as after any failure' );
	}

	public function test_a_later_step_of_the_job_is_not_a_step_to_retry_from(): void {
		$this->runs = array(
			'a' => 0,
			'b' => 0,
		);
		$failed     = false;
		$this->register(
			'retry_from_later',
			array(
				new ClosureStep(
					'a',
					static function () use ( &$failed ): StepResult {
						if ( ! $failed ) {
							$failed = true;
							throw new RetryFrom( 'Names the step after it.', 'b' );
						}
						return StepResult::done( 'a done' );
					}
				),
				new ClosureStep(
					'b',
					static function (): StepResult {
						return StepResult::done( 'b done' );
					}
				),
			)
		);
		$id  = $this->job_of( 'retry_from_later', false );
		$job = $this->run_job( $id );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertArrayNotHasKey( JobRepository::RETRY_FROM_KEY, $job->cursor, 'b is a step of the job, but after the failing one' );
		Plugin::instance()->job_actions()->retry( $id );
		$this->assertNotSame( 'b', Plugin::instance()->jobs()->find( $id )->step );
		$this->assertSame( Job::COMPLETED, $this->run_job( $id )->status, 'the retry ran a again, then b' );
	}

	public function test_any_other_failure_is_retried_where_it_stopped_with_its_cursor(): void {
		$id  = $this->job_failing_with(
			static function (): void {
				throw new \RuntimeException( 'An ordinary failure.' );
			}
		);
		$job = $this->run_job( $id );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertArrayNotHasKey( JobRepository::RETRY_FROM_KEY, $job->cursor );
		Plugin::instance()->job_actions()->retry( $id );
		$this->assertSame( 'c', Plugin::instance()->jobs()->find( $id )->step );
		$this->assertSame( Job::COMPLETED, $this->run_job( $id )->status );
		$this->assertSame( array( 1, 2, 3 ), array_values( $this->runs ), 'only c ran again, from where it was' );
	}

	public function test_the_failure_and_the_step_to_retry_from_are_one_write(): void {
		$id      = $this->job_failing_with(
			static function (): void {
				throw new RetryFrom( 'Retry from b.', 'b' );
			}
		);
		$updates = array();
		for ( $i = 0; $i < 50 && Job::FAILED !== Plugin::instance()->jobs()->find( $id )->status; $i++ ) {
			$updates = $this->job_updates(
				static function () use ( $id ): void {
					Plugin::instance()->runner()->tick( $id, microtime( true ) );
				}
			);
		}
		$failing = array_values( preg_grep( "/status` = 'failed'|status = 'failed'/", $updates ) );
		$this->assertCount( 1, $failing, 'the control: the failing tick\'s write that marks the job failed' );
		$this->assertStringContainsString( JobRepository::RETRY_FROM_KEY, $failing[0], 'and the step to retry from is in it' );
	}
}
