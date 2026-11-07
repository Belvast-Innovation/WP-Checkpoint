<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\RestoreJob;
use WPCheckpoint\Jobs\Step;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Jobs\SwapHost;
use WPCheckpoint\Jobs\WebRestoreJob;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The restore's step ids are one list (RestoreJob::STEPS): the steps RestoreJob makes have them, one by one and in
 * their order, and so has the restore a run without WordPress sees (WebRestoreJob), which takes them from the list
 * (not from RestoreJob::steps(), which makes every step).
 */
final class RestoreJobStepsTest extends TestCase {

	/**
	 * The ids of steps.
	 *
	 * @param Step[] $steps Steps.
	 * @return string[]
	 */
	private static function ids( array $steps ): array {
		return array_map(
			static function ( Step $step ): string {
				return $step->id();
			},
			$steps
		);
	}

	public function test_the_list_is_the_steps_restore_job_makes_in_their_order(): void {
		$job = new RestoreJob(
			static function () {
				return null;
			},
			static function ( string $text ): string {
				return $text;
			}
		);
		$this->assertCount( 9, RestoreJob::STEPS, 'the control: the swap and the eight before it' );
		$this->assertSame( RestoreJob::STEPS, self::ids( $job->steps() ) );
		$this->assertSame( array_slice( RestoreJob::STEPS, 0, -1 ), $job->step_ids(), 'step_ids(): all but the swap, as before' );
	}

	public function test_a_run_without_wordpress_has_the_same_steps(): void {
		$web = new WebRestoreJob( new SwapStep( null, array(), SwapHost::without_wordpress( 'wp_', false, 0, 'wp-checkpoint/wp-checkpoint.php' ) ) );
		$this->assertSame( RestoreJob::STEPS, self::ids( $web->steps() ) );
		$this->assertSame( array_slice( RestoreJob::STEPS, 0, -1 ), $web->step_ids() );
		$this->assertSame( RestoreJob::ID, $web->id() );
		$this->assertSame( array( SwapStep::ID ), array_slice( RestoreJob::STEPS, -1 ) );
	}
}
