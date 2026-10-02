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
	 * Whether the server compares table names without case (lower_case_table_names <> 0): the one place it is read.
	 *
	 * @return bool
	 */
	public static function fold_case(): bool {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT @@lower_case_table_names' ) > 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- a server setting.
	}

	/**
	 * Which of the given meta keys the usermeta table this site uses holds. The keys are matched as the column
	 * compares them (WordPress's tables: without case); the given spelling is returned.
	 *
	 * @param string[] $keys Meta keys (IncomingTables::role_keys()).
	 * @return string[]
	 * @throws TransientFailure When the table cannot be read: whether another installation uses it cannot be told.
	 */
	public static function meta_keys_present( array $keys ): array {
		global $wpdb;
		$keys = array_values( array_unique( array_map( 'strval', $keys ) ) );
		if ( array() === $keys ) {
			return array();
		}
		// The table $wpdb queries (wp-config.php's prefix or CUSTOM_USER_META_TABLE), quoted as an identifier.
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT meta_key FROM ' . SqlWriter::identifier( self::usermeta() ) . ' WHERE meta_key IN (' . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ')', $keys ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching -- the table name quoted, one placeholder per key.
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			throw new TransientFailure( 'The user meta table of this site could not be read, so whether another installation in the same database uses it cannot be told.' );
		}
		$held = array_fill_keys( array_map( 'strtolower', array_map( 'strval', $rows ) ), true );
		return array_values(
			array_filter(
				$keys,
				static function ( string $key ) use ( $held ): bool {
					return isset( $held[ strtolower( $key ) ] );
				}
			)
		);
	}
}
