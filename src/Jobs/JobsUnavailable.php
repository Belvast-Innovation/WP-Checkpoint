<?php
/**
 * Thrown when no job can be created right now.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

/**
 * The storage directory is unusable or the database schema comes from a
 * newer, incompatible plugin version. The message is meant for the user.
 */
final class JobsUnavailable extends \RuntimeException {
}
