<?php
/**
 * Verify one backup and record the result next to it.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Archive\ArchiveVerifier;
use WPCheckpoint\Archive\ChunkHasher;
use WPCheckpoint\Archive\VerificationResult;
use WPCheckpoint\Backups\VerifyRecord;

defined( 'ABSPATH' ) || exit;

/**
 * Two phases:
 *
 * 1. verify: ArchiveVerifier in bounded units, its state in the cursor.
 *    The hash of the standalone manifest is fixed before the first unit
 *    (the record is about that manifest); at the checkpoint where the
 *    verifier finishes, the whole record, verified_at included, is fixed
 *    in the cursor, and every finding's text goes to the job log.
 * 2. record: the record's bytes come from the cursor only; written to the
 *    work directory, the lease confirmed, renamed into backups/ (one unit;
 *    a replay writes the same bytes, a crash before the rename leaves a
 *    file the work directory's reclaim removes).
 *
 * A unit's duration depends on the disk (up to 256 MiB of a volume at full
 * depth): the first unit of a tick always runs, then the tick ends when
 * the time left is under 1.5 times the slowest unit so far, and a unit
 * slower than the whole budget fails with the reason.
 */
final class VerifyStep implements Step {

	const ID          = 'verify';
	const VERIFY_DIR  = 'verify';
	const RECORD_TEMP = 'verify.record.json';

	/**
	 * Slowest unit times this must fit in what is left of the budget.
	 */
	const TIME_MARGIN = 1.5;

	/**
	 * Returns the backups directory: function(): string.
	 *
	 * @var callable
	 */
	private $backups;

	/**
	 * Text cleaner for the report written to the job log.
	 *
	 * @var callable
	 */
	private $clean;

	/**
	 * Clock: function(): int.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Constructor.
	 *
	 * @param callable      $backups function(): string, the backups directory.
	 * @param callable      $clean   Text cleaner (JobPresenter::clean()).
	 * @param callable|null $now     Clock (tests).
	 */
	public function __construct( callable $backups, callable $clean, $now = null ) {
		$this->backups = $backups;
		$this->clean   = $clean;
		$this->now     = is_callable( $now ) ? $now : 'time';
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
	 * Verify, then record.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws TransientFailure When a work or record file cannot be written.
	 * @throws \RuntimeException When the options are invalid, the manifest is gone or reading is too slow here.
	 */
	public function run( JobContext $context ): StepResult {
		$options = VerifyJob::options( $context->options() );
		$base    = $options['base'];
		$cursor  = array_merge(
			array(
				'phase'           => 'verify',
				'manifest_sha256' => '',
				'verifier'        => array(),
				'record'          => null,
			),
			$context->cursor()
		);
		$backups = (string) call_user_func( $this->backups );
		if ( 'verify' === $cursor['phase'] ) {
			$result = $this->verify( $context, $cursor, $backups . DIRECTORY_SEPARATOR . $base . '.manifest.json', $options['depth'] );
			if ( null !== $result ) {
				return $result;
			}
		}
		$work = $context->work_path();
		$temp = $work . DIRECTORY_SEPARATOR . self::RECORD_TEMP;
		if ( false === @file_put_contents( $temp, VerifyRecord::to_json( (array) $cursor['record'] ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a warning would put the path into the error log; failure is thrown.
			throw new TransientFailure( 'The verification record could not be written.' );
		}
		$context->confirm_lease(); // Nothing between the lease check and the rename.
		if ( ! @rename( $temp, $backups . DIRECTORY_SEPARATOR . VerifyRecord::file_name( $base ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- see above.
			throw new TransientFailure( 'The verification record could not be stored next to the backup.' );
		}
		return StepResult::done( VerificationResult::headline( (string) $cursor['record']['outcome'] ) );
	}

	/**
	 * The verify phase; null when it is done (the record is in the cursor, checkpointed).
	 *
	 * @param JobContext           $context  Context.
	 * @param array<string, mixed> $cursor   Cursor (updated).
	 * @param string               $manifest Standalone manifest path.
	 * @param string               $depth    Depth.
	 * @return StepResult|null
	 * @throws TransientFailure When the work directory cannot be created.
	 * @throws \RuntimeException When the manifest is gone or a unit is slower than the whole budget.
	 */
	private function verify( JobContext $context, array &$cursor, string $manifest, string $depth ) {
		if ( '' === $cursor['manifest_sha256'] ) {
			if ( ! is_file( $manifest ) ) {
				throw new \RuntimeException( 'This backup has no manifest in the backups directory.' );
			}
			// Fixed before the first unit: the record is about this manifest, whatever happens to it later.
			$cursor['manifest_sha256'] = ChunkHasher::hash_file( $manifest );
			$context->checkpoint( $cursor, 0, __( 'Verifying the backup', 'wp-checkpoint' ) );
		}
		$dir = $context->work_path() . DIRECTORY_SEPARATOR . self::VERIFY_DIR;
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700 ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- a warning would put the path into the error log.
			throw new TransientFailure( 'The verification work directory could not be created.' );
		}
		$verifier = ArchiveVerifier::open( $manifest, $dir, $depth, is_array( $cursor['verifier'] ) ? $cursor['verifier'] : array() );
		$budget   = (float) $context->budget()->seconds;
		$first    = true;
		$slowest  = 0.0;
		while ( true ) {
			if ( ! $first && $context->remaining_seconds() < $slowest * self::TIME_MARGIN ) {
				// The next unit would not fit what is left: end the tick here rather than be cut off mid-unit.
				$context->checkpoint( $cursor, self::percent( $verifier ), __( 'Verifying the backup', 'wp-checkpoint' ) );
				return StepResult::progress( $cursor, self::percent( $verifier ), __( 'Verifying the backup', 'wp-checkpoint' ) );
			}
			$started            = $context->elapsed();
			$more               = $verifier->step();
			$cost               = $context->elapsed() - $started;
			$first              = false;
			$slowest            = max( $slowest, $cost );
			$cursor['verifier'] = $verifier->state();
			if ( $cost > $budget ) {
				throw new \RuntimeException( sprintf( 'Reading this backup is too slow on this server: one step of the check took %d seconds, more than the %d-second time budget of a single run.', (int) ceil( $cost ), (int) $budget ) );
			}
			if ( ! $more ) {
				break;
			}
			if ( $context->should_stop() ) {
				return StepResult::progress( $cursor, self::percent( $verifier ), __( 'Verifying the backup', 'wp-checkpoint' ) );
			}
			if ( $context->should_checkpoint( 0 ) ) {
				$context->checkpoint( $cursor, self::percent( $verifier ), __( 'Verifying the backup', 'wp-checkpoint' ) );
			}
		}
		$result = $verifier->result();
		$context->logger()->info( 'Verification finished', array( 'report' => $result->to_text( $this->clean ) ) );
		// The whole record, the time of the check included, is fixed here; the record phase only writes it.
		$cursor['record']   = VerifyRecord::from_result(
			(string) $context->options()['base'],
			(string) $cursor['manifest_sha256'],
			(int) call_user_func( $this->now ),
			$depth,
			$result,
			$context->job()->id
		);
		$cursor['phase']    = 'record';
		$cursor['verifier'] = array();
		$context->checkpoint( $cursor, 95, __( 'Recording the result', 'wp-checkpoint' ) );
		return null;
	}

	/**
	 * Rough progress of the check (0–90).
	 *
	 * @param ArchiveVerifier $verifier Verifier.
	 * @return int
	 */
	private static function percent( ArchiveVerifier $verifier ): int {
		$progress = $verifier->progress();
		return 0 === (int) $progress['total'] ? 5 : (int) min( 90, 5 + floor( 85 * (int) $progress['done'] / (int) $progress['total'] ) );
	}

	/**
	 * Nothing to do: the verifier's files and the temporary record live in
	 * the work directory, which the engine removes.
	 *
	 * @param JobContext $context Context.
	 * @return void
	 */
	public function cleanup( JobContext $context ): void {
		unset( $context );
	}
}
