<?php
/**
 * Another run of the restore claimed a table this run works on.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Jobs\TransientFailure;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by Ledger when this run is no longer a table's holder: another
 * run claimed it (a run that outlived its lease, in the moment between its
 * lease check and its claim). This run records nothing more on the table;
 * its open batch is rolled back. Being a TransientFailure, the job is
 * retried after the back-off with the lease released, and the retry claims
 * the table back and goes on from its record, while the other run stops at
 * its next lease check. The import step confirms the job's lease before
 * it lets ClaimLost out, so a run that no longer holds the job stops with
 * LockLost instead, and no retry is logged for it.
 */
final class ClaimLost extends TransientFailure {
}
