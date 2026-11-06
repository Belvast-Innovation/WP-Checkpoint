<?php
/**
 * How JobStore's statements reach the database.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * JobStore writes each statement once; an executor sends it. WpdbExecutor sends it through WordPress's wpdb (as the
 * plugin always did), SessionExecutor through a connection made without WordPress (Restore\ImportSession), converting
 * the template (Placeholders). A refused statement is null, never an exception: the store says what it means.
 */
interface StoreExecutor {

	/**
	 * Run one statement that changes rows: a template in wpdb::prepare()'s form, one value per placeholder.
	 *
	 * @param string            $template Template.
	 * @param array<int, mixed> $params   Values.
	 * @return int|null Affected rows; null when the statement was refused.
	 */
	public function execute( string $template, array $params );

	/**
	 * Update rows of a table: the columns to set and the conditions, each with its format ("%s" or "%d"), joined as
	 * wpdb::update() joins them (UpdateSql).
	 *
	 * @param string               $table         Table.
	 * @param array<string, mixed> $data          Column => value.
	 * @param string[]             $formats       Their formats, in order.
	 * @param array<string, mixed> $where         Column => value, all equal.
	 * @param string[]             $where_formats Their formats, in order.
	 * @return int|null Affected rows; null when the statement was refused.
	 */
	public function update( string $table, array $data, array $formats, array $where, array $where_formats );

	/**
	 * Run one query: a template in wpdb::prepare()'s form; its rows, each column name => value (a string, or null).
	 *
	 * @param string            $template Template.
	 * @param array<int, mixed> $params   Values.
	 * @return array<int, array<string, string|null>>|null Rows; null when the query failed.
	 */
	public function select( string $template, array $params );
}
