<?php
/**
 * Thrown when a restore's ledger was created by an older version of the plugin.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * The ledger table of a restore (Ledger) lacks columns this version reads
 * and writes: the restore was started by an older version, and the ledger
 * is only created, never changed. Retrying reads the same table, so the
 * runner records the failure as final; the restore has to be started again.
 */
final class LedgerOutdated extends \RuntimeException {
}
