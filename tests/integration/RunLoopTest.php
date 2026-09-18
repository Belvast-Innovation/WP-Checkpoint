<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Cli\RunLoop;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobActions;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\Loopback;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Jobs\TransientFailure;
use WPCheckpoint\Plugin;
use WPCheckpoint\Tests\Fixtures\Jobs\ClosureStep;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

final class RunLoopTest extends JobTestCase {

	/** @var int[] */
	private $slept = array();

	/** @var string[] */
	private $lines = array();

	/** @var int */
	private $hops = 0;

	private function loop(): RunLoop {
		$plugin  = Plugin::instance();
		$actions = new JobActions( $plugin->jobs(), $plugin->runner(), new Loopback( true, function (): void {
			++$this->hops;
		} ) );
		return new RunLoop(
			$actions,
			$plugin->job_presenter(),
			function ( int $seconds ): void {
				$this->slept[] = $seconds;
			},
			function ( string $line ): void {
				$this->lines[] = $line;
			}
		);
	}

	public function test_runs_to_completion_and_prints_progress(): void {
		$this->register( 'plain', array( $this->counting_step( 'p', 3 ) ) );
		$job = Plugin::instance()->jobs()->create( 'plain' );
		$this->assertSame( RunLoop::EXIT_COMPLETED, $this->loop()->run( $job->id, false ) );
		$this->assertSame( array(), $this->slept );
		$this->assertStringStartsWith( 'completed', $this->lines[ count( $this->lines ) - 1 ] );
		$this->assertSame( RunLoop::EXIT_COMPLETED, $this->loop()->run( $job->id, false ), 'finished jobs report their outcome' );
		$this->assertSame( RunLoop::EXIT_WAITING, $this->loop()->run( 424242, false ) );
		$this->assertContains( 'No such job.', $this->lines );
	}

	public function test_waits_exit_without_wait_and_sleep_with_it(): void {
		$calls = 0;
		$this->register( 'patient', array( new ClosureStep( 'w', static function ( JobContext $ctx ) use ( &$calls ): StepResult {
			++$calls;
			return $calls < 3 ? StepResult::wait( 7, array(), 'remote busy' ) : StepResult::done();
		} ) ) );
		$job = Plugin::instance()->jobs()->create( 'patient' );
		$this->assertSame( RunLoop::EXIT_WAITING, $this->loop()->run( $job->id, false ) );
		$this->assertStringContainsString( '--wait', implode( "\n", $this->lines ) );
		$this->assertSame( RunLoop::EXIT_COMPLETED, $this->loop()->run( $job->id, true ) );
		$this->assertSame( array( 7 ), $this->slept, 'one more wait, then done' );
	}

	public function test_failed_cancelled_and_lost_exit_codes(): void {
		$this->register( 'broken', array( new ClosureStep( 'b', static function (): StepResult {
			throw new \RuntimeException( 'boom' );
		} ) ) );
		$job = Plugin::instance()->jobs()->create( 'broken' );
		$this->assertSame( RunLoop::EXIT_FAILED, $this->loop()->run( $job->id, false ) );
		$this->assertStringContainsString( 'error: Step "b": RuntimeException: boom', $this->lines[ count( $this->lines ) - 1 ] );

		$this->register( 'cancelled-mid-step', array( new ClosureStep( 'c', function ( JobContext $ctx ): StepResult {
			$ctx->checkpoint( array( 'i' => 1 ), 10 );
			$this->rest( 'POST', 'jobs/' . $ctx->job()->id . '/cancel' );
			$ctx->checkpoint( array( 'i' => 2 ), 20 );
			return StepResult::done();
		} ) ) );
		$job = Plugin::instance()->jobs()->create( 'cancelled-mid-step' );
		$this->assertSame( RunLoop::EXIT_CANCELLED, $this->loop()->run( $job->id, false ) );

		$this->register( 'taken-over', array( new ClosureStep( 't', function ( JobContext $ctx ): StepResult {
			$ctx->checkpoint( array( 'i' => 1 ), 10 );
			// Another driver takes the lock over (as after an expired lease) and keeps working.
			global $wpdb;
			$wpdb->update( \WPCheckpoint\Support\Schema::jobs_table(), array( 'lock_token' => 'someone-else' ), array( 'id' => $ctx->job()->id ) );
			$ctx->checkpoint( array( 'i' => 2 ), 20 );
			return StepResult::done();
		} ) ) );
		$job = Plugin::instance()->jobs()->create( 'taken-over' );
		$this->assertSame( RunLoop::EXIT_LOST, $this->loop()->run( $job->id, false ) );
		$this->assertSame( Job::RUNNING, Plugin::instance()->jobs()->find( $job->id )->status );
	}

	public function test_busy_gives_up_after_the_limit(): void {
		$this->register( 'plain', array( $this->counting_step( 'p', 1 ) ) );
		$job  = Plugin::instance()->jobs()->create( 'plain' );
		$held = Plugin::instance()->jobs()->acquire( $job->id, 100000 );
		$this->assertNotNull( $held );
		$this->assertSame( RunLoop::EXIT_BUSY, $this->loop()->run( $job->id, true ) );
		$this->assertCount( RunLoop::MAX_BUSY, $this->slept, 'sleeps after each refused attempt, gives up on the next' );
		$this->assertSame( 5, $this->slept[0] );
		$this->assertStringContainsString( 'Another driver holds this job', $this->lines[ count( $this->lines ) - 1 ] );
	}

	public function test_the_loop_never_starts_a_chain_or_a_cron_event_and_follows_up_once_when_it_stops_early(): void {
		$http = 0;
		add_filter( 'pre_http_request', static function () use ( &$http ) {
			++$http;
			return new \WP_Error( 'blocked', 'no self-requests during the CLI loop' );
		} );
		$runner = new \WPCheckpoint\Jobs\Runner( Plugin::instance()->jobs(), Plugin::instance()->job_types(), Plugin::instance()->redactor(), array( 'budget' => new \WPCheckpoint\Jobs\Budget( 0, 32 * 1048576, false ), 'memory_limit' => -1 ) );
		$actions = new JobActions( Plugin::instance()->jobs(), $runner, new Loopback( true, function (): void {
			++$this->hops;
		} ) );
		$loop = new RunLoop( $actions, Plugin::instance()->job_presenter(), function ( int $s ): void {
			$this->slept[] = $s;
		}, function ( string $l ): void {
			$this->lines[] = $l;
		} );

		// Many "more" results in a row: no hop, no cron event.
		$this->register( 'long', array( $this->counting_step( 'c', 8 ) ) );
		$job = Plugin::instance()->jobs()->create( 'long' );
		$this->assertSame( RunLoop::EXIT_COMPLETED, $loop->run( $job->id, false ) );
		$this->assertSame( 0, $this->hops, 'the loop is its own follow-up' );
		$this->assertSame( 0, $http );
		$this->assertFalse( wp_next_scheduled( Loopback::HOOK, array( $job->id ) ) );

		// Leaving on a wait without --wait: exactly one follow-up (the cron event) so other drivers take over.
		$this->register( 'patient', array( new ClosureStep( 'w', static function ( JobContext $ctx ): StepResult {
			return StepResult::wait( 30, $ctx->cursor(), 'remote busy' );
		} ) ) );
		$job = Plugin::instance()->jobs()->create( 'patient' );
		$this->assertSame( RunLoop::EXIT_WAITING, $loop->run( $job->id, false ) );
		$this->assertNotFalse( wp_next_scheduled( Loopback::HOOK, array( $job->id ) ), 'handed back through the cron event' );
		$this->assertSame( 0, $this->hops );
		$this->assertCount( 1, array_filter( _get_cron_array(), static function ( array $hooks ): bool {
			return isset( $hooks[ Loopback::HOOK ] );
		} ), 'one event' );

		// Giving up on busy: one follow-up as well.
		$this->register( 'plain', array( $this->counting_step( 'p', 1 ) ) );
		$job  = Plugin::instance()->jobs()->create( 'plain' );
		$held = Plugin::instance()->jobs()->acquire( $job->id, 100000 );
		$this->assertSame( RunLoop::EXIT_BUSY, $loop->run( $job->id, true ) );
		$this->assertNotFalse( wp_next_scheduled( Loopback::HOOK, array( $job->id ) ) );
		$this->assertSame( 0, $this->hops );
		remove_all_filters( 'pre_http_request' );
	}

	public function test_transient_failures_are_waited_out(): void {
		$calls = 0;
		$this->register( 'flaky', array( new ClosureStep( 'f', static function () use ( &$calls ): StepResult {
			++$calls;
			if ( $calls <= 2 ) {
				throw new TransientFailure( 'timeout' );
			}
			return StepResult::done();
		} ) ) );
		$job = Plugin::instance()->jobs()->create( 'flaky' );
		$this->assertSame( RunLoop::EXIT_COMPLETED, $this->loop()->run( $job->id, true ) );
		$this->assertSame( array( 5, 15 ), $this->slept );
	}
}
