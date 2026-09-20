<?php
/**
 * The Connection over WordPress's $wpdb.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Binds "?" placeholders through $wpdb->prepare() (every value as %s: MySQL
 * converts a quoted number back for numeric columns and still uses the
 * index) and reads the MySQL error number from the underlying mysqli
 * handle, which the exporter needs to tell a lost connection from a
 * broken query.
 */
final class WpdbConnection implements Connection {

	/**
	 * Rows of a query.
	 *
	 * @param string   $sql  SQL with "?" placeholders.
	 * @param string[] $args Values.
	 * @return array<int, array<int, string|null>>|null
	 * @throws \InvalidArgumentException When the placeholder count does not match the arguments.
	 */
	public function rows( string $sql, array $args = array() ) {
		global $wpdb;
		$wpdb->flush();
		if ( array() !== $args ) {
			$parts = explode( '?', $sql );
			if ( count( $parts ) !== count( $args ) + 1 ) {
				throw new \InvalidArgumentException( 'Placeholder count does not match the arguments.' );
			}
			$prepared = '';
			foreach ( $parts as $i => $part ) {
				$prepared .= str_replace( '%', '%%', $part );
				if ( $i < count( $args ) ) {
					$prepared .= '%s';
				}
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders were rewritten to %s above.
			$sql = $wpdb->prepare( $prepared, array_map( 'strval', array_values( $args ) ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- export of arbitrary tables; identifiers are quoted by the exporter.
		$rows = $wpdb->get_results( $sql, ARRAY_N );
		if ( '' !== (string) $wpdb->last_error ) {
			return null;
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Last error text, with the database name replaced: the server names
	 * it in "table 'db.t' doesn't exist" and similar messages, and it is
	 * often the hosting account name, which has no place in a job error
	 * a user copies into a support request.
	 *
	 * @return string
	 */
	public function last_error(): string {
		global $wpdb;
		$error = (string) $wpdb->last_error;
		$name  = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
		if ( '' !== $name && '' !== $error ) {
			$error = str_replace( $name, '[database]', $error );
		}
		return $error;
	}

	/**
	 * Last MySQL error number from the mysqli handle.
	 *
	 * @return int
	 */
	public function last_errno(): int {
		global $wpdb;
		if ( '' === (string) $wpdb->last_error ) {
			return 0;
		}
		return $wpdb->dbh instanceof \mysqli ? (int) mysqli_errno( $wpdb->dbh ) : 0; // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_errno -- $wpdb does not expose the error number, and the exporter needs it to tell a lost connection from a broken query.
	}

	/**
	 * Connection charset.
	 *
	 * @return string
	 */
	public function charset(): string {
		global $wpdb;
		return strtolower( (string) $wpdb->charset );
	}

	/**
	 * Server version.
	 *
	 * @return string
	 */
	public function server_info(): string {
		global $wpdb;
		return (string) $wpdb->db_server_info();
	}

	/**
	 * Base tables (not views) whose names start with a prefix, in byte order.
	 *
	 * @param string $prefix Table prefix.
	 * @return array{tables: string[], views: string[]}
	 */
	public function tables_with_prefix( string $prefix ): array {
		global $wpdb;
		$rows   = $this->rows( 'SHOW FULL TABLES LIKE ?', array( $wpdb->esc_like( $prefix ) . '%' ) );
		$tables = array();
		$views  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( 'VIEW' === strtoupper( (string) $row[1] ) ) {
				$views[] = (string) $row[0];
			} else {
				$tables[] = (string) $row[0];
			}
		}
		sort( $tables, SORT_STRING );
		sort( $views, SORT_STRING );
		return array(
			'tables' => $tables,
			'views'  => $views,
		);
	}
}
