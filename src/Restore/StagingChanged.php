<?php
/**
 * Raised when something other than this restore changed what it staged.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * A directory level that is a link or not a directory, a staged file that
 * is not a regular file, not the file that was opened, or shorter than the
 * restore recorded: the staging root is random and this restore's alone,
 * so this is never guessed around. The job step turns it into a final
 * failure (the staging directory was changed).
 */
final class StagingChanged extends \RuntimeException {
}
