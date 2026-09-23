<?php
/**
 * Thrown when the server, not the archive, is the problem.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Archive;

/**
 * The server, not the archive: a target that cannot be created or written
 * (missing directory, no permission, disk full), an archive file that is
 * there but cannot be read, compressed entries without zlib. Callers report
 * it as "could not be checked on this server", never as a damaged archive:
 * a user who reads "damaged" deletes a backup that may be fine. Every catch
 * of \RuntimeException on a read path rethrows it first.
 */
final class EnvironmentFailure extends \RuntimeException {

	/**
	 * The plugin's own work files: the work directory is gone, full or read-only.
	 */
	const STORAGE = 'storage';

	/**
	 * The archive's files are there but this server cannot read them (permissions, a storage error).
	 */
	const ACCESS = 'access';

	/**
	 * The archive has compressed entries and this server's PHP has no zlib extension.
	 */
	const ZLIB = 'zlib';

	/**
	 * Cause.
	 *
	 * @var string
	 */
	private $cause;

	/**
	 * Constructor.
	 *
	 * @param string $message Message.
	 * @param string $cause   One of the constants: what the user can do about it differs.
	 */
	public function __construct( string $message, string $cause = self::STORAGE ) {
		parent::__construct( $message );
		$this->cause = $cause;
	}

	/**
	 * Cause.
	 *
	 * @return string
	 */
	public function cause(): string {
		return $this->cause;
	}
}
