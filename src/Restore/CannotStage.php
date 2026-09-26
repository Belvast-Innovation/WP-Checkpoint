<?php
/**
 * Raised when this site cannot take a restore's staged files as it is set up.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * Nothing about the backup: a directory the restore must write, rename or
 * swap in cannot be used that way here (permissions, another disk, too
 * little space, a layout the swap cannot undo). Raised before any staged
 * file is written. The message names the directory, why, and what can be
 * done; the job fails and can be retried once that is done.
 */
final class CannotStage extends \RuntimeException {
}
