<?php
/**
 * The estimate job: how much a full backup of this site would hold.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Files\Exclusions;
use WPCheckpoint\Files\ScanRoots;
use WPCheckpoint\Support\Directories;

defined( 'ABSPATH' ) || exit;

/**
 * Scans the files a full backup would take (every content group, the
 * default exclusions, the storage directory left out), with the export's
 * own FileScanStep and under the same engine rules as every job; then keeps
 * the counts (Backups\Estimate). It is ours, not the user's: it is not
 * listed with the user's jobs, a failure shows nothing but the database
 * size, and an export or a restore that starts cancels it silently.
 */
final class EstimateJob implements JobType {

	const ID = 'estimate';

	/**
	 * Returns the current storage directories: function(): Directories.
	 *
	 * @var callable
	 */
	private $directories;

	/**
	 * Keeps the result: function( int $files, int $bytes, int $job ): void.
	 *
	 * @var callable
	 */
	private $record;

	/**
	 * Constructor.
	 *
	 * @param callable $directories function(): Directories.
	 * @param callable $record      function( int $files, int $bytes, int $job ): void.
	 */
	public function __construct( callable $directories, callable $record ) {
		$this->directories = $directories;
		$this->record      = $record;
	}

	/**
	 * Type id.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Size estimate', 'wp-checkpoint' );
	}

	/**
	 * Step ids in order.
	 *
	 * @return string[]
	 */
	public function step_ids(): array {
		return array( FileScanStep::ID, EstimateRecordStep::ID );
	}

	/**
	 * The steps.
	 *
	 * @return Step[]
	 * @throws \LogicException When the storage directories are not available.
	 */
	public function steps(): array {
		$directories = call_user_func( $this->directories );
		if ( ! $directories instanceof Directories ) {
			throw new \LogicException( 'The storage directories are not available.' );
		}
		$resolved = ScanRoots::resolve( ScanRoots::GROUPS, $directories->base() );
		return array(
			new FileScanStep( $resolved['roots'], new Exclusions(), $resolved['warnings'] ),
			new EstimateRecordStep( $this->record ),
		);
	}
}
