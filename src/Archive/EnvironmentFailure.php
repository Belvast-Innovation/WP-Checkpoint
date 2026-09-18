<?php
/**
 * Thrown when the server, not the archive, is the problem.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Archive;

/**
 * A target that cannot be created or written (missing directory, no
 * permission, disk full). Callers report it as "could not be checked on
 * this server", never as a damaged archive: a user who reads "damaged"
 * deletes a backup that may be fine.
 */
final class EnvironmentFailure extends \RuntimeException {
}
