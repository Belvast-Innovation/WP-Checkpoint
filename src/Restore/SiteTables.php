<?php
/**
 * This site's WordPress tables as WordPress names them, and the role keys in the usermeta table it uses.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Database\SqlWriter;
use WPCheckpoint\Jobs\TransientFailure;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- reads of the live site, once per restore.

/**
 * The WordPress side of IncomingTables and TableMoves: what this site uses, read from $wpdb, which follows
 * CUSTOM_USER_TABLE and CUSTOM_USER_META_TABLE.
 */
final class SiteTables {

	/**
	 * This site's WordPress tables (a network's global tables and its main site's), as WordPress names them: the main
	 * site's on a network, where a tick may run in a sub-site's context (the sub-sites' tables are recognised by their
	 * naming rule, TableSelection). The users and usermeta tables are the ones $wpdb queries.
	 *
	 * @return string[]
	 */
	public static function core(): array {
		global $wpdb;
		// With the users tables $wpdb queries: the ones WordPress uses, whatever names their constants gave them.
		return array_values( array_unique( array_merge( array_map( 'strval', $wpdb->tables( 'all', true, is_multisite() ? get_main_site_id() : 0 ) ), array( self::users(), self::usermeta() ) ) ) );
	}

	/**
	 * The users table this site uses.
	 *
	 * @return string
	 */
	public static function users(): string {
		global $wpdb;
		return (string) $wpdb->users;
	}

	/**
	 * The usermeta table this site uses.
	 *
	 * @return string
	 */
	public static function usermeta(): string {
		global $wpdb;
		return (string) $wpdb->usermeta;
	}

	/**
	 * Which of the given meta keys the usermeta table this site uses holds.
	 *
	 * @param string[] $keys Meta keys (IncomingTables::role_keys()).
	 * @return string[]
	 * @throws TransientFailure When the table cannot be read: whether another installation uses it cannot be told.
	 * @throws \UnexpectedValueException When the usermeta table is not one WordPress names (a retry would not change that).
	 */
	public static function meta_keys_present( array $keys ): array {
		global $wpdb;
		$keys = array_values( array_unique( array_map( 'strval', $keys ) ) );
		if ( array() === $keys ) {
			return array();
		}
		$table = self::usermeta();
		if ( ! in_array( $table, self::core(), true ) ) {
			throw new \UnexpectedValueException( 'The user meta table of this site is not among the tables WordPress names, so whether another installation in the same database uses it cannot be told.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a job error; the presenter cleans it.
		}
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT meta_key FROM ' . SqlWriter::identifier( $table ) . ' WHERE meta_key IN (' . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ')', $keys ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching -- a table WordPress names (checked above), one placeholder per key.
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			throw new TransientFailure( 'The user meta table of this site could not be read, so whether another installation in the same database uses it cannot be told.' );
		}
		return array_values( array_intersect( $keys, array_map( 'strval', $rows ) ) );
	}
}
