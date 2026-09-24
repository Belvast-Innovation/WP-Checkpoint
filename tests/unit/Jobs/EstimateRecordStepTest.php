<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\EstimateRecordStep;
use WPCheckpoint\Jobs\ExportPlan;
use WPCheckpoint\Jobs\FileScanStep;
use WPCheckpoint\Jobs\LockLost;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Tests\Fixtures\Jobs\WorkContext;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The estimate's last step keeps what the scan counted, unless the job was
 * cancelled meanwhile (an export or a restore started).
 */
final class EstimateRecordStepTest extends TestCase {

	/** @var WorkContext */
	private $ctx;

	/** @var array<int, array<int, int>> */
	private $recorded = array();

	protected function set_up(): void {
		$this->ctx = new WorkContext( 'wpcheckpoint-estimate-' );
		ExportPlan::write( $this->ctx->work(), FileScanStep::SUMMARY, array( 'counts' => array( 'files' => 12, 'bytes' => 3456 ) ) );
	}

	protected function tear_down(): void {
		$this->ctx->remove();
	}

	private function step(): EstimateRecordStep {
		return new EstimateRecordStep(
			function ( int $files, int $bytes, int $job ): void {
				$this->recorded[] = array( $files, $bytes, $job );
			}
		);
	}

	public function test_the_counts_are_recorded(): void {
		$this->assertSame( StepResult::DONE, $this->step()->run( $this->ctx->context() )->kind );
		$this->assertSame( array( array( 12, 3456, $this->ctx->id ) ), $this->recorded );
	}

	public function test_a_cancelled_estimate_records_nothing(): void {
		$this->ctx->lease = static function ( bool $force ): void {
			if ( $force ) {
				throw new LockLost( 'cancelled (test)' );
			}
		};
		try {
			$this->step()->run( $this->ctx->context() );
			$this->fail( 'the lease is checked before recording' );
		} catch ( LockLost $e ) {
			$this->assertSame( array(), $this->recorded );
		}
	}
}
