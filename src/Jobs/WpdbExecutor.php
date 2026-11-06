<?php
/**
 * JobStore's statements through WordPress's wpdb.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table; every template is prepared here, with JobStore's values.

/**
 * As the plugin always sent them: a template through wpdb::prepare() and wpdb::query() / get_results(), an update
 * through wpdb::update() (which checks each value against its column first and refuses the statement when one does
 * not fit).
 */
final class WpdbExecutor implements StoreExecutor {

	/**
	 * Run one statement that changes rows.
	 *
	 * @param string            $template Template.
	 * @param array<int, mixed> $params   Values.
	 * @return int|null
	 */
	public function execute( string $template, array $params ) {
		global $wpdb;
		$affected = $wpdb->query( self::prepared( $template, $params ) );
		return false === $affected ? null : (int) $affected;
	}

	/**
	 * Update rows of a table.
	 *
	 * @param string               $table         Table.
	 * @param array<string, mixed> $data          Column => value.
	 * @param string[]             $formats       Their formats.
	 * @param array<string, mixed> $where         Column => value.
	 * @param string[]             $where_formats Their formats.
	 * @return int|null
	 */
	public function update( string $table, array $data, array $formats, array $where, array $where_formats ) {
		global $wpdb;
		$affected = $wpdb->update( $table, $data, $where, $formats, $where_formats );
		return false === $affected ? null : (int) $affected;
	}

	/**
	 * Run one query.
	 *
	 * @param string            $template Template.
	 * @param array<int, mixed> $params   Values.
	 * @return array<int, array<string, string|null>>|null
	 */
	public function select( string $template, array $params ) {
		global $wpdb;
		$rows = $wpdb->get_results( self::prepared( $template, $params ), ARRAY_A ); // query() clears last_error first.
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			return null;
		}
		return $rows;
	}

	/**
	 * The statement, prepared (a template without placeholders is sent as it is: wpdb::prepare() wants one).
	 *
	 * @param string            $template Template.
	 * @param array<int, mixed> $params   Values.
	 * @return string
	 * @throws \InvalidArgumentException When a template without values holds a percent sign (it would mean something to prepare()).
	 */
	private static function prepared( string $template, array $params ): string {
		global $wpdb;
		if ( array() === $params ) {
			if ( false !== strpos( $template, '%' ) ) {
				throw new \InvalidArgumentException( 'A template without values holds a percent sign.' );
			}
			return $template;
		}
		return (string) $wpdb->prepare( $template, array_values( $params ) );
	}
}
