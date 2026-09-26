<?php
/**
 * The statements the restore ledger sends.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * What Ledger needs from a connection (ImportSession implements it): a
 * statement without results, a query with results, and a statement that
 * changes rows, with values in place of "?".
 */
interface Queries {

	/**
	 * Run one statement that returns no rows.
	 *
	 * @param string $sql SQL.
	 * @return int Affected rows.
	 */
	public function run( string $sql ): int;

	/**
	 * Run one query; its rows, each a list of column values.
	 *
	 * @param string   $sql    SQL with ? placeholders.
	 * @param string[] $params Values.
	 * @return array<int, array<int, string|null>>
	 */
	public function rows( string $sql, array $params = array() ): array;

	/**
	 * Run one statement that changes rows, with values in place of "?".
	 *
	 * @param string   $sql    SQL with ? placeholders.
	 * @param string[] $params Values.
	 * @return int Affected rows.
	 */
	public function write( string $sql, array $params ): int;
}
