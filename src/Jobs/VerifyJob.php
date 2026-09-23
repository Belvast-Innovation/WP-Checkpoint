<?php
/**
 * The verify job: check one backup and keep the result next to it.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Archive\ArchiveVerifier;
use WPCheckpoint\Support\Directories;

defined( 'ABSPATH' ) || exit;

/**
 * A check of a large backup does not fit one request, so it is a job like
 * the export; its result outlives the job in backups/{base}.verify.json
 * (Backups\VerifyRecord). Options: {base, depth}; the backups directory is
 * taken from the current storage directories when the steps are built,
 * never from the options.
 */
final class VerifyJob implements JobType {

	const ID = 'verify';

	/**
	 * Returns the current storage directories: function(): Directories.
	 *
	 * @var callable
	 */
	private $directories;

	/**
	 * Text cleaner (JobPresenter::clean()).
	 *
	 * @var callable
	 */
	private $clean;

	/**
	 * Constructor.
	 *
	 * @param callable $directories function(): Directories.
	 * @param callable $clean       Text cleaner.
	 */
	public function __construct( callable $directories, callable $clean ) {
		$this->directories = $directories;
		$this->clean       = $clean;
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
		return __( 'Verification', 'wp-checkpoint' );
	}

	/**
	 * Step ids in order.
	 *
	 * @return string[]
	 */
	public function step_ids(): array {
		return array( VerifyStep::ID );
	}

	/**
	 * The steps.
	 *
	 * @return Step[]
	 */
	public function steps(): array {
		$directories = $this->directories;
		return array(
			new VerifyStep(
				static function () use ( $directories ): string {
					$dirs = call_user_func( $directories );
					return $dirs instanceof Directories ? $dirs->backups() : '';
				},
				$this->clean
			),
		);
	}

	/**
	 * Validated options: a backup base name of the shape the export gives it,
	 * and a depth (full by default).
	 *
	 * @param array<string, mixed> $options Options.
	 * @return array{base: string, depth: string}
	 * @throws \InvalidArgumentException When they are not valid.
	 */
	public static function options( array $options ): array {
		$base  = isset( $options['base'] ) && is_string( $options['base'] ) ? $options['base'] : '';
		$depth = isset( $options['depth'] ) ? $options['depth'] : ArchiveVerifier::DEPTH_FULL;
		if ( 1 !== preg_match( PreflightStep::BASE_PATTERN, $base ) ) {
			throw new \InvalidArgumentException( 'Not the name of a backup.' );
		}
		if ( ! in_array( $depth, array( ArchiveVerifier::DEPTH_STRUCTURE, ArchiveVerifier::DEPTH_FULL ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown depth: structure or full.' );
		}
		return array(
			'base'  => $base,
			'depth' => (string) $depth,
		);
	}
}
