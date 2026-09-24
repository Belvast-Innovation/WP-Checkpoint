<?php
/**
 * Thrown when a table's structure changed while it was being exported.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

/**
 * The table's definition (its CREATE TABLE, in its first chunk) is already
 * written, and the table's columns (name, full type and NULL, in order) or
 * its primary key are no longer the ones it was written with:
 * TableExporter::step() compares a fingerprint taken with the first chunk,
 * and bound_in() the key of a chunk's bound (for an export begun before the
 * fingerprint existed: there only a key of another length, or one gained or
 * lost, is seen). Not covered: a column's character set or collation, and
 * indexes other than the primary key. Going on, even after
 * the structure is changed back, would give a backup whose table data does
 * not match its table definition, found only at restore. The runner records
 * the failure as final with Job::REASON_TABLE_CHANGED; a new backup reads the
 * table as it is now.
 */
final class TableChanged extends \RuntimeException {
}
