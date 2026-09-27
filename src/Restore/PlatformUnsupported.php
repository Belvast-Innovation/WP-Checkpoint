<?php
/**
 * Raised when this version cannot restore onto the server it runs on.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * Nothing about the backup or the site's setup: the restore does not run on
 * this server's operating system in this version. A final failure (retrying
 * cannot change it).
 */
final class PlatformUnsupported extends \RuntimeException {
}
