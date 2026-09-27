<?php
/**
 * Raised when a backup's file does not read back as its manifest says, for a reason retrying cannot change.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * The backup file changed since the restore checked it, or the backup is
 * damaged (it was never read in full before). A final failure: the message
 * says which, and to start again from a backup that is intact and left
 * alone while it is restored.
 */
final class BackupUnusable extends \RuntimeException {
}
