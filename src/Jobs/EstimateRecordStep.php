<?php
/**
 * Keep what the estimate job counted.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the scan summary the FileScanStep left in the work directory and
 * hands its counts to the recorder in one unit. Replaying it records the
 * same counts again.
 */
final class EstimateRecordStep implements Step {

	const ID = 'record';

	/**
	 * Recorder: function( int $files, int $bytes, int $job ): void.
	 *
	 * @var callable
	 */
	private $record;

	/**
	 * Constructor.
	 *
	 * @param callable $record function( int $files, int $bytes, int $job ): void.
	 */
	public function __construct( callable $record ) {
		$this->record = $record;
	}

	/**
	 * Step id.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Record the counts.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws \RuntimeException When the scan summary is missing or damaged.
	 */
	public function run( JobContext $context ): StepResult {
		$summary = ExportPlan::read( $context->work_path(), FileScanStep::SUMMARY );
		$counts  = isset( $summary['counts'] ) && is_array( $summary['counts'] ) ? $summary['counts'] : null;
		if ( null === $counts || ! isset( $counts['files'], $counts['bytes'] ) ) {
			throw new \RuntimeException( 'The scan summary has no counts.' );
		}
		// A cancelled estimate (an export or a restore started) records nothing: the lease is checked first.
		$context->confirm_lease();
		call_user_func( $this->record, (int) $counts['files'], (int) $counts['bytes'], $context->job()->id );
		return StepResult::done( __( 'Size estimated', 'wp-checkpoint' ) );
	}

	/**
	 * Nothing to clean: the scan's files live in the work directory, which the engine removes.
	 *
	 * @param JobContext $context Context.
	 * @return void
	 */
	public function cleanup( JobContext $context ): void {
		unset( $context );
	}
}
