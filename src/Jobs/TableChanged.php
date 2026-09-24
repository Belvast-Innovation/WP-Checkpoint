<?php
/**
 * Thrown when a table's structure changed while it was being exported.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

/**
 * The table's definition (its CREATE TABLE, in its first chunk) is already
 * written, and the table now has another key than the one its chunks were
 * written for (TableExporter::bound_in()). Going on, even after
 * the structure is changed back, would give a backup whose table data does
 * not match its table definition, found only at restore. The runner records
 * the failure as final with Job::REASON_TABLE_CHANGED; a new backup reads the
 * table as it is now.
 */
final class TableChanged extends \RuntimeException {
}
