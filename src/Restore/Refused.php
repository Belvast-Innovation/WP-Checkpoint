<?php
/**
 * Raised when a backup's database content is not something the restore runs.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * The SQL of a chunk is outside what the importer executes (a statement of
 * another kind or for another table, a value that is not a literal, a table
 * definition with features the restore does not carry), or does not agree
 * with itself (columns, row sizes). Raised before the statement runs, so
 * nothing of it reached the database. Not an environment problem and not
 * necessarily damage: an archive written by other tools is refused the
 * same way. The message is fixed English with offsets and names from the
 * archive (table and column names), never row data.
 */
final class Refused extends \RuntimeException {
}
