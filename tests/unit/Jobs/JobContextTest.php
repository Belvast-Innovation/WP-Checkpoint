<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Support\Logger;
use WPCheckpoint\Support\Redactor;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class JobContextTest extends TestCase {

	/** @var float */
	private $now = 1000.0;

	/** @var int */
	private $memory = 10485760; // 10 MB

	private function context( array $cursor = array(), int $seconds = 10, int $memory_budget = 33554432, int $memory_limit = -1, $checkpoint = null ): JobContext {
		$job     = new Job();
		$job->id = 5;
		$logger  = new Logger( sys_get_temp_dir() . '/wpcheckpoint-context-' . bin2hex( random_bytes( 4 ) ) . '.log', new Redactor() );
		return new JobContext(
			$job,
			$cursor,
			new Budget( $seconds, $memory_budget, false ),
			$logger,
			function (): float {
				return $this->now;
			},
			function (): int {
				return $this->memory;
			},
			$this->now,
			$memory_limit,
			$checkpoint
		);
	}

	public function test_time_budget_is_wall_clock_since_the_given_start(): void {
		$ctx = $this->context( array(), 10 );
		$this->assertFalse( $ctx->should_stop() );
		$this->now += 9.5;
		$this->assertFalse( $ctx->should_stop() );
		$this->assertEqualsWithDelta( 0.5, $ctx->remaining_seconds(), 0.001 );
		$this->now += 0.5;
		$this->assertTrue( $ctx->should_stop() );
		$this->assertSame( JobContext::STOP_TIME, $ctx->stop_reason() );
		$this->assertSame( 0.0, $ctx->remaining_seconds() );
	}

	public function test_memory_budget_is_growth_since_the_start(): void {
		$ctx = $this->context( array(), 10, 32 * 1048576, -1 );
		$this->assertSame( 32 * 1048576, $ctx->remaining_memory() );
		$this->memory += 31 * 1048576;
		$this->assertFalse( $ctx->should_stop() );
		$this->memory += 1048576;
		$this->assertTrue( $ctx->should_stop() );
		$this->assertSame( JobContext::STOP_MEMORY, $ctx->stop_reason() );
		$this->memory -= 5 * 1048576;
		$this->assertFalse( $ctx->should_stop(), 'freed memory counts again (current usage, not the peak)' );
	}

	public function test_absolute_limit_applies_only_when_known(): void {
		// 10 MB used, limit 40 MB, headroom 8 MB: 22 MB left although the growth budget allows 32 MB.
		$ctx = $this->context( array(), 10, 32 * 1048576, 40 * 1048576 );
		$this->assertSame( 22 * 1048576, $ctx->remaining_memory() );
		$this->memory += 22 * 1048576;
		$this->assertTrue( $ctx->should_stop() );

		$this->memory = 10485760;
		$ctx          = $this->context( array(), 10, 32 * 1048576, -1 );
		$this->memory += 22 * 1048576;
		$this->assertFalse( $ctx->should_stop(), 'unlimited: only the growth budget counts' );
		$ctx = $this->context( array(), 10, 32 * 1048576, 0 );
		$this->assertFalse( $ctx->should_stop(), 'unknown: only the growth budget counts' );
	}

	public function test_reserved_keys_are_hidden_and_stripped(): void {
		$ctx = $this->context( array( 'i' => 1, '__runner' => array( 'retries' => 2 ), '__runner_x' => 1 ) );
		$this->assertSame( array( 'i' => 1 ), $ctx->cursor() );
		$this->assertSame( array( 'i' => 1 ), JobContext::strip_reserved( array( '__runner' => 1, 'i' => 1 ) ) );
		$this->assertSame( array( 0 => 'a' ), JobContext::strip_reserved( array( 0 => 'a' ) ), 'integer keys are kept' );
	}

	public function test_checkpoint_calls_back_without_reserved_keys(): void {
		$seen = null;
		$ctx  = $this->context( array(), 10, 32 * 1048576, -1, function ( array $cursor, int $percent, string $message ) use ( &$seen ): void {
			$seen = array( $cursor, $percent, $message );
		} );
		$ctx->checkpoint( array( 'i' => 2, '__runner' => array( 'retries' => 99 ) ), 130, 'half' );
		$this->assertSame( array( array( 'i' => 2 ), 100, 'half' ), $seen );
		$this->assertSame( array( 'i' => 2 ), $ctx->cursor(), 'the context follows the checkpoint' );

		$this->expectException( \LogicException::class );
		$this->context()->checkpoint( array(), 1 );
	}
}
