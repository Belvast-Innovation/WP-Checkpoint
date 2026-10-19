<?php
/**
 * A restore's first check: whether this version restores on this server at all.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Restore\PlatformUnsupported;
use WPCheckpoint\Support\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a fixed job error; the presenter cleans it.

/**
 * Restoring onto a Windows server is not supported in this version: it is
 * refused here, before anything of the restore is read, probed or written
 * (the first step; the work directory is not even created). The judgement
 * is the running PHP's own (PHP_OS_FAMILY), never the shape of a path.
 * Backups and exports on Windows are not affected. The name rules and
 * probes already written for Windows file systems (TargetNames,
 * DirectoryProbe) stay; restoring on Windows needs the integration tests
 * to run on a Windows CI job first.
 */
final class RestorePlatformStep implements Step {

	const ID = 'restore_platform';

	/**
	 * The operating system family (tests: function(): string); PHP_OS_FAMILY by default.
	 *
	 * @var callable|null
	 */
	private $platform;

	/**
	 * Constructor.
	 *
	 * @param callable|null $platform    function(): string in place of PHP_OS_FAMILY (tests).
	 * @param callable|null $jobs_engine function(): string|null in place of Schema::jobs_table_engine() (tests).
	 */
	public function __construct( $platform = null, $jobs_engine = null ) {
		$this->platform    = $platform;
		$this->jobs_engine = $jobs_engine;
	}

	/**
	 * The jobs table's engine (tests).
	 *
	 * @var callable|null
	 */
	private $jobs_engine;

	/**
	 * Step id.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Refuse on Windows; otherwise done.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws PlatformUnsupported On Windows.
	 */
	public function run( JobContext $context ): StepResult {
		unset( $context );
		$family = null === $this->platform ? PHP_OS_FAMILY : (string) call_user_func( $this->platform );
		if ( 'Windows' === $family ) {
			throw new PlatformUnsupported( 'This version of WP Checkpoint cannot restore onto a Windows server yet. Backups and exports are not affected.' );
		}
		// The swap's writes are fenced on the job row (Restore\JobFence), which a locked read holds only on InnoDB:
		// refused here, before anything is made, not at the first fenced write after the whole import.
		$engine = null === $this->jobs_engine ? Schema::jobs_table_engine() : call_user_func( $this->jobs_engine );
		if ( null === $engine ) {
			throw new TransientFailure( 'The engine of the jobs table could not be read; the restore is tried again.' );
		}
		if ( 'innodb' !== $engine ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the engine name from the server; the presenter cleans it.
			throw new PlatformUnsupported( sprintf( 'The jobs table is %s, not InnoDB: a restore cannot lock its job row before it writes. Convert the table to InnoDB (ALTER TABLE ... ENGINE=InnoDB) and retry.', $engine ) );
		}
		return StepResult::done( __( 'This server can run a restore', 'wp-checkpoint' ) );
	}

	/**
	 * Nothing to do: nothing was created.
	 *
	 * @param JobContext $context Context.
	 * @return void
	 */
	public function cleanup( JobContext $context ): void {
		unset( $context );
	}
}
