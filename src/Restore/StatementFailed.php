<?php
/**
 * Raised when the database refuses a statement of the restore.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * Not transient: the same statement would be refused again (a syntax the
 * server does not know, a type or collation it lacks, a duplicate key). The
 * code is the server's error number.
 */
final class StatementFailed extends \RuntimeException {
}
