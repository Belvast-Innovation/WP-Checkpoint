<?php
/**
 * Thrown when the database refused to write a job's progress.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * The fenced write of a job's cursor failed as a statement (a lock wait, the
 * server gone, a value the table refuses), rather than finding the lock
 * taken: nothing of the cursor is stored and the run stops without further
 * writes, as for a lost lock, but it is said as what it is.
 */
final class WriteRefused extends StaleJob {
}
