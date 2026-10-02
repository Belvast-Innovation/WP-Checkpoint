<?php
/**
 * A restore's first check: whether this version restores on this server at all.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Restore\PlatformUnsupported;

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
	 * @param callable|null $platform function(): string in place of PHP_OS_FAMILY (tests).
	 */
	public function __construct( $platform = null ) {
		$this->platform = $platform;
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
	 * Refuse on Windows; otherwise done.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws PlatformUnsupported On Windows.
	 * @throws \InvalidArgumentException When the options are not valid (an unattended restore that does not say what to
	 *                                   do with the tables another installation may use, among them).
	 */
	public function run( JobContext $context ): StepResult {
		RestoreJob::options( $context->options() ); // Before anything: options a later step would refuse stop the restore here.
		$family = null === $this->platform ? PHP_OS_FAMILY : (string) call_user_func( $this->platform );
		if ( 'Windows' === $family ) {
			throw new PlatformUnsupported( 'This version of WP Checkpoint cannot restore onto a Windows server yet. Backups and exports are not affected.' );
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
