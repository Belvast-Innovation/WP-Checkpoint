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
	 * What fold_case() answers in a test instead of the server (null: the server). Set only by tests, through
	 * reflection: nothing in the plugin sets it (FoldCaseUsageTest).
	 *
	 * @var bool|null
	 */
	private static $fold_case_in_tests = null; // @phpstan-ignore property.unusedType (set by tests only, through reflection)

	/**
	 * Whether the server compares table names without case (lower_case_table_names <> 0): the one place it is read.
	 *
	 * @return bool
	 * @throws TransientFailure When it cannot be read: a restore must not go on as if names kept their case.
	 */
	public static function fold_case(): bool {
		global $wpdb;
		if ( null !== self::$fold_case_in_tests ) {
			return self::$fold_case_in_tests;
		}
		$value = $wpdb->get_var( 'SELECT @@lower_case_table_names' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- a server setting.
		if ( '' !== (string) $wpdb->last_error || null === $value || ! is_numeric( $value ) ) {
			throw new TransientFailure( 'Whether the database server compares table names without letter case could not be read.' );
		}
		return (int) $value > 0;
	}

	/**
	 * One window of the walk over the usermeta table this site uses: the keys ending in "_capabilities" of the rows
	 * whose umeta_id is above $after and at most $after + $window (the window alone bounds what is read, through the
	 * primary key), and where the next window starts: past a gap, at the next id there is; null when none is left.
	 *
	 * @param int $after  The last umeta_id of the windows before.
	 * @param int $window The width of a window, in ids.
	 * @return array{keys: string[], after: int|null}
	 * @throws TransientFailure When the table cannot be read: whether another installation uses it cannot be told.
	 */
	public static function capability_keys( int $after, int $window ): array {
		global $wpdb;
		$table = SqlWriter::identifier( self::usermeta() );
		$end   = $after + $window;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching -- the table name quoted; bounded reads through the primary key.
		$keys = $wpdb->get_col( $wpdb->prepare( 'SELECT meta_key FROM ' . $table . ' WHERE umeta_id > %d AND umeta_id <= %d AND meta_key LIKE %s', $after, $end, '%' . $wpdb->esc_like( IncomingTables::CAPABILITIES ) ) );
		$fail = '' !== (string) $wpdb->last_error || ! is_array( $keys );
		$next = $fail ? null : $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(umeta_id) FROM ' . $table . ' WHERE umeta_id > %d', $end ) );
		// phpcs:enable
		if ( $fail || '' !== (string) $wpdb->last_error ) {
			throw new TransientFailure( 'The user meta table of this site could not be read, so whether another installation in the same database uses it cannot be told.' );
		}
		return array(
			'keys'  => array_values( array_map( 'strval', (array) $keys ) ),
			'after' => null === $next ? null : max( $end, (int) $next - 1 ),
		);
	}

	/**
	 * The highest umeta_id of the usermeta table this site uses (0 when it is empty): how far the walk goes, for its
	 * progress.
	 *
	 * @return int
	 * @throws TransientFailure When the table cannot be read.
	 */
	public static function last_meta_id(): int {
		global $wpdb;
		$value = $wpdb->get_var( 'SELECT MAX(umeta_id) FROM ' . SqlWriter::identifier( self::usermeta() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching -- the table name quoted.
		if ( '' !== (string) $wpdb->last_error ) {
			throw new TransientFailure( 'The user meta table of this site could not be read, so whether another installation in the same database uses it cannot be told.' );
		}
		return (int) $value;
	}

	/**
	 * Whether this network has a site of that blog_id (its blogs table has the row).
	 *
	 * @param int $blog_id Blog id.
	 * @return bool
	 * @throws TransientFailure When the blogs table cannot be read.
	 */
	public static function blog_exists( int $blog_id ): bool {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SELECT blog_id FROM ' . SqlWriter::identifier( (string) $wpdb->blogs ) . ' WHERE blog_id = %d', $blog_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching -- the table name quoted.
		if ( '' !== (string) $wpdb->last_error ) {
			throw new TransientFailure( 'The sites of this network could not be read.' );
		}
		return null !== $found;
	}
}
