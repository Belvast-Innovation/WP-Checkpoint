<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\MemoryPace;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Jobs\TransientFailure;
use WPCheckpoint\Jobs\WebPace;
use WPCheckpoint\Jobs\WebRun;
use WPCheckpoint\Standalone\Credentials;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Runs without WordPress held up on a job come later and later (WebPace: 5 seconds, 30, 2 minutes, 5, then 5 each
 * time), and one that gets through starts it over; a job not due yet is not touched at all (WebRun opens nothing).
 */
final class WebPaceTest extends TestCase {

	public function test_the_waits_grow_and_then_stay_at_the_last(): void {
		$this->assertSame( array( 5, 30, 120, 300 ), WebPace::SCHEDULE );
		$pace  = new MemoryPace();
		$waits = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$waits[] = $pace->held_up( 7, 1000 );
		}
		$this->assertSame( array( 5, 30, 120, 300, 300, 300 ), $waits );
		$this->assertSame( 300, $pace->wait( 7, 1000 ) );
		$this->assertSame( 100, $pace->wait( 7, 1200 ) );
		$this->assertSame( 0, $pace->wait( 7, 1300 ) );
		$this->assertSame( 0, $pace->wait( 8, 1000 ), 'another job is not held up' );
	}

	public function test_a_run_that_gets_through_starts_it_over(): void {
		$pace = new MemoryPace();
		$pace->held_up( 7, 1000 );
		$pace->held_up( 7, 1000 );
		$pace->cleared( 7 );
		$this->assertSame( 0, $pace->wait( 7, 1000 ) );
		$this->assertSame( 5, $pace->held_up( 7, 1000 ), 'from the first wait again' );
	}

	/**
	 * A run over settings no database answers (port 1 on this machine refuses at once).
	 *
	 * @param WebPace $pace The pace.
	 * @return WebRun
	 */
	private static function web_run( WebPace $pace ): WebRun {
		return new WebRun(
			Credentials::from_values(
				array(
					'name'     => 'none',
					'user'     => 'none',
					'password' => 'none',
					'host'     => '127.0.0.1:1',
					'prefix'   => 'wp_',
				)
			),
			ABSPATH,
			false,
			'wp-checkpoint/wp-checkpoint.php',
			static function ( string $line ): void {
				unset( $line );
			},
			null,
			array(),
			$pace
		);
	}

	public function test_a_job_not_due_is_not_touched(): void {
		$pace = new MemoryPace();
		$pace->held_up( 7, time() );
		$result = self::web_run( $pace )->tick( 7 );
		$this->assertSame( TickResult::WAITING, $result->status );
		$this->assertGreaterThan( 0, $result->retry_after );
		$this->assertLessThanOrEqual( 5, $result->retry_after );
	}

	public function test_a_job_due_is_tried(): void {
		// The control of the test above: with no wait the run does open its connection (here refused).
		$this->expectException( TransientFailure::class );
		self::web_run( new MemoryPace() )->tick( 7 );
	}

	public function test_a_held_up_run_waits_longer_busy_says_nothing_and_anything_else_starts_over(): void {
		$pace = new MemoryPace();
		$run  = self::web_run( $pace );
		$held = static function (): TickResult {
			$result          = new TickResult( TickResult::LEFT, -1, null, 'busy' );
			$result->held_up = true;
			return $result;
		};
		$this->assertSame( 5, $run->paced( 7, $held(), 1000 )->retry_after );
		$this->assertSame( 30, $run->paced( 7, $held(), 1000 )->retry_after );
		$run->paced( 7, new TickResult( TickResult::BUSY, 5, null ), 1000 );
		$this->assertSame( 120, $run->paced( 7, $held(), 1000 )->retry_after, 'BUSY kept the count' );
		$run->paced( 7, new TickResult( TickResult::LEFT, -1, null, 'not held up' ), 1000 );
		$this->assertSame( 0, $pace->wait( 7, 1000 ), 'a run that got through started it over' );
		$this->assertSame( 5, $run->paced( 7, $held(), 1000 )->retry_after );
		$run->paced( 7, new TickResult( TickResult::MORE, 0, null ), 1000 );
		$this->assertSame( 0, $pace->wait( 7, 1000 ) );
	}
}
