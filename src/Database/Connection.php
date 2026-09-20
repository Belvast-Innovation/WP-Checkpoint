<?php
/**
 * What the exporter needs from a database.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Database;

/**
 * A narrow interface over the database so the export logic stays pure
 * PHP: tests supply an in-memory implementation, WordPress supplies
 * WpdbConnection. Queries carry positional placeholders ("?") that the
 * implementation binds as strings; identifiers are quoted by the caller.
 */
interface Connection {

	/**
	 * Run a query and return its rows as numerically indexed arrays of
	 * strings (or null for SQL NULL), or null when the query failed (see
	 * last_error() / last_errno()).
	 *
	 * @param string   $sql  SQL with "?" placeholders.
	 * @param string[] $args One value per placeholder.
	 * @return array<int, array<int, string|null>>|null
	 */
	public function rows( string $sql, array $args = array() );

	/**
	 * The last error text, or '' when the last query succeeded.
	 *
	 * @return string
	 */
	public function last_error(): string;

	/**
	 * The last MySQL error number, 0 when the last query succeeded.
	 *
	 * @return int
	 */
	public function last_errno(): int;

	/**
	 * Connection character set as MySQL names it ("utf8mb4", "gbk", ...).
	 *
	 * @return string
	 */
	public function charset(): string;

	/**
	 * Server version string.
	 *
	 * @return string
	 */
	public function server_info(): string;
}
