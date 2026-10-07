<?php
/**
 * The fence between an uninstall and a swap entering the site.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Database\SqlWriter;

/**
 * One row (id 1) in a table of its own, "open" or "closed", which both sides write: an uninstall closes it with one
 * UPDATE before anything it removes, and a swap enters the site (the job's site_state from untouched to changing,
 * JobRepository::save_progress()) with one UPDATE of the job's row and this row together, on the condition that it is
 * open. Both write the same row, so InnoDB lets one of them through at a time: a swap that wrote first holds the row
 * until it commits, and the uninstall's close waits for it and then sees the job hold the site; an uninstall that
 * closed first makes the swap's UPDATE (a locking read) find the row closed and change nothing. A missing table or
 * row lets no swap in.
 *
 * Network-wide on a multisite, as the jobs table. Older versions of the plugin do not know it: their swaps do not
 * write the row (a known boundary; the uninstall reads again before each step as a second line).
 */
final class UninstallFence {

	/**
	 * Table name without the prefix.
	 */
	const TABLE = 'wpcheckpoint_fence';

	/**
	 * The only row.
	 */
	const ROW = 1;

	const OPEN   = 'open';
	const CLOSED = 'closed';

	/**
	 * What close() found: DONE, this uninstall closed it; HELD, it was closed already (another uninstall on a site
	 * sharing this database, or one that did not finish): no swap enters, and this one must not open it again.
	 */
	const DONE   = 'closed';
	const HELD   = 'held';
	const ABSENT = 'absent';
	const FAILED = 'failed';

	/**
	 * A fence with no heartbeat (beat(): the uninstall that holds it beats before each step, and every BEAT_SECONDS
	 * while it deletes its storage) for longer than this is taken to be left by an uninstall that did not finish:
	 * Schema::ensure() opens it again where the request may upgrade (the page, activation, WP-CLI), logged.
	 */
	const STALE_SECONDS = 3600;

	/**
	 * How often, at most, the uninstall beats while it deletes its storage directory (before each deletion, once this
	 * long has passed since its last beat): far inside STALE_SECONDS.
	 */
	const BEAT_SECONDS = 30;

	/**
	 * The statement by which a job enters the site, with placeholders in order (%d and %s): the fence row's id and
	 * state "open" (the join's condition), the value of each column in $columns (with its format in $formats), the
	 * time, the job's id and its lock token. One multi-table UPDATE that writes the job's row and the fence row
	 * (JobRepository::entering_sql() fills it in; the server matrix runs the same text).
	 *
	 * @param string   $jobs    The jobs table.
	 * @param string   $fence   The fence table.
	 * @param string[] $columns Columns of the job's row.
	 * @param string[] $formats Their formats (%s, %d), in the same order.
	 * @return string
	 */
	public static function entering_template( string $jobs, string $fence, array $columns, array $formats ): string {
		$set = array();
		foreach ( array_values( $columns ) as $i => $column ) {
			$set[] = 'j.`' . str_replace( '`', '', $column ) . '` = ' . ( $formats[ $i ] ?? '%s' );
		}
		$set[] = 'f.entries = f.entries + 1';
		$set[] = 'f.entered_at = %d';
		return 'UPDATE ' . SqlWriter::identifier( $jobs ) . ' AS j INNER JOIN ' . SqlWriter::identifier( $fence ) . ' AS f ON f.id = %d AND f.state = %s SET ' . implode( ', ', $set ) . ' WHERE j.id = %d AND j.lock_token = %s';
	}

	/**
	 * The statement that closes the fence, with placeholders in order: state "closed", the uninstall's run (closed_by),
	 * the row's id, state "open". It changes the row only while it is open (one row affected: this uninstall closed
	 * it), and takes the time from the server (one clock for every installation sharing the database).
	 *
	 * @param string $fence The fence table.
	 * @return string
	 */
	public static function close_template( string $fence ): string {
		return 'UPDATE ' . SqlWriter::identifier( $fence ) . ' SET state = %s, closed_at = UNIX_TIMESTAMP(), closed_by = %s, beats = 0 WHERE id = %d AND state = %s';
	}

	/**
	 * The heartbeat of the uninstall that holds the fence, with placeholders in order: the row's id, state "closed",
	 * the uninstall's run. One statement both checks that the fence is still closed by this run and moves closed_at
	 * (what heal() measures staleness by) to now; beats changes every time, so one row affected means "still mine"
	 * even twice in one second.
	 *
	 * @param string $fence The fence table.
	 * @return string
	 */
	public static function beat_template( string $fence ): string {
		return 'UPDATE ' . SqlWriter::identifier( $fence ) . ' SET closed_at = UNIX_TIMESTAMP(), beats = beats + 1 WHERE id = %d AND state = %s AND closed_by = %s';
	}

	/**
	 * The fence table's name.
	 *
	 * @return string
	 */
	public static function name(): string {
		global $wpdb;
		return $wpdb->base_prefix . self::TABLE;
	}

	/**
	 * The statement that creates the table, when it is not there.
	 *
	 * @param string $name Table name.
	 * @return string
	 */
	public static function create_sql( string $name ): string {
		return 'CREATE TABLE IF NOT EXISTS ' . SqlWriter::identifier( $name ) . " (id tinyint(3) unsigned NOT NULL, state varchar(8) NOT NULL DEFAULT 'open', entries bigint(20) unsigned NOT NULL DEFAULT 0, entered_at bigint(20) unsigned NOT NULL DEFAULT 0, closed_at bigint(20) unsigned NOT NULL DEFAULT 0, closed_by varchar(32) NOT NULL DEFAULT '', beats bigint(20) unsigned NOT NULL DEFAULT 0, PRIMARY KEY (id)) ENGINE=InnoDB";
	}

	/**
	 * The columns schema version 13 adds to a table version 12 made (closed_by, beats), when they are missing; the
	 * table a later create_sql() makes has them already. Run by the migration, again on every check of the schema
	 * (Schema::ensure( true )) and by the uninstall before it closes the fence, so that one refused ALTER is not
	 * final: true when the table has both columns afterwards, read from the table; false when it does not, or its
	 * columns cannot be read (also when the table is not there).
	 *
	 * @return bool
	 */
	public static function upgrade(): bool {
		global $wpdb;
		$name    = SqlWriter::identifier( self::name() );
		$quiet   = $wpdb->suppress_errors( true );
		$columns = static function () use ( $wpdb, $name ): array {
			$have = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $name ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- the fence table.
			return is_array( $have ) ? array_map( 'strval', $have ) : array();
		};
		$added   = array(
			'closed_by' => "varchar(32) NOT NULL DEFAULT ''",
			'beats'     => 'bigint(20) unsigned NOT NULL DEFAULT 0',
		);
		$have    = $columns();
		foreach ( $added as $column => $definition ) {
			if ( array() !== $have && ! in_array( $column, $have, true ) ) {
				$wpdb->query( 'ALTER TABLE ' . $name . ' ADD COLUMN ' . $column . ' ' . $definition ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- the fence table and a constant definition.
				$have = $columns();
			}
		}
		$wpdb->suppress_errors( $quiet );
		return array() === array_diff( array_keys( $added ), $have );
	}

	/**
	 * The table and its row (open), when they are not there; a row that is there is left as it is.
	 *
	 * @return void
	 */
	public static function create(): void {
		global $wpdb;
		$name = self::name();
		$wpdb->query( self::create_sql( $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- table name from the prefix and a constant.
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . SqlWriter::identifier( $name ) . ' (id, state) VALUES (%d, %s)', self::ROW, self::OPEN ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- table name from the prefix.
	}

	/**
	 * Close the fence for this uninstall's run ($by: a random value of its own, closed_by): one UPDATE of its row while
	 * it is open (it waits while a swap's entering UPDATE holds the row).
	 * DONE when this closed it; HELD when it was closed already (read after: no swap enters, and the one who closed it
	 * opens it); ABSENT when the table is positively not there (a schema older than the fence: no swap of this version
	 * can enter without it); FAILED when the statement failed (it changed nothing), the row is missing, or it cannot be
	 * told.
	 *
	 * @param string $by This uninstall's run.
	 * @return string
	 */
	public static function close( string $by ): string {
		global $wpdb;
		$name             = self::name();
		$quiet            = $wpdb->suppress_errors( true );
		$wpdb->last_error = '';
		$done             = $wpdb->query( $wpdb->prepare( self::close_template( $name ), self::CLOSED, $by, self::ROW, self::OPEN ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- the fence table.
		$failed           = false === $done || '' !== JobRepository::db_error();
		$state            = $failed || 1 === (int) $done ? null : $wpdb->get_var( $wpdb->prepare( 'SELECT state FROM ' . SqlWriter::identifier( $name ) . ' WHERE id = %d', self::ROW ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- table name from the prefix.
		$failed           = $failed || '' !== JobRepository::db_error();
		$absent           = $failed && array() === self::listed( $name );
		$wpdb->suppress_errors( $quiet );
		if ( $absent ) {
			return self::ABSENT;
		}
		if ( $failed ) {
			return self::FAILED;
		}
		if ( 1 === (int) $done ) {
			return self::DONE;
		}
		return self::CLOSED === $state ? self::HELD : self::FAILED;
	}

	/**
	 * Open the fence again (an uninstall that stopped, or that kept the data: only a fence its run closed, $by; '' for
	 * any, for repair and tests): true when the row is open now. A fence another uninstall closed since stays closed.
	 *
	 * @param string $by The run that closed it ('': any).
	 * @return bool
	 */
	public static function open( string $by = '' ): bool {
		global $wpdb;
		$name  = self::name();
		$quiet = $wpdb->suppress_errors( true );
		if ( '' === $by ) {
			// Whoever closed it (repair and tests; an uninstall opens only its own).
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . SqlWriter::identifier( $name ) . ' SET state = %s WHERE id = %d', self::OPEN, self::ROW ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- table name from the prefix.
		} else {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . SqlWriter::identifier( $name ) . ' SET state = %s WHERE id = %d AND closed_by = %s', self::OPEN, self::ROW, $by ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- table name from the prefix.
		}
		$state = $wpdb->get_var( $wpdb->prepare( 'SELECT state FROM ' . SqlWriter::identifier( $name ) . ' WHERE id = %d', self::ROW ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- table name from the prefix.
		$wpdb->suppress_errors( $quiet );
		return self::OPEN === $state;
	}

	/**
	 * The heartbeat (beat_template()): true when the fence is still closed by this run, and its closed_at is now;
	 * false when it was opened (healed as stale, or by hand) or closed by another since, or the statement failed, or
	 * $by is empty.
	 *
	 * @param string $by This uninstall's run.
	 * @return bool
	 */
	public static function beat( string $by ): bool {
		global $wpdb;
		if ( '' === $by ) {
			return false; // No run: a fence closed by version 12 (closed_by '') is no one's to beat.
		}
		$quiet = $wpdb->suppress_errors( true );
		$done  = $wpdb->query( $wpdb->prepare( self::beat_template( self::name() ), self::ROW, self::CLOSED, $by ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- the fence table.
		$wpdb->suppress_errors( $quiet );
		return 1 === (int) $done;
	}

	/**
	 * The row as stored: state and closed_at; null when it cannot be read or is not there.
	 *
	 * @return array{state: string, closed_at: int}|null
	 */
	public static function row() {
		global $wpdb;
		$quiet            = $wpdb->suppress_errors( true );
		$wpdb->last_error = '';
		$row              = $wpdb->get_row( $wpdb->prepare( 'SELECT state, closed_at FROM ' . SqlWriter::identifier( self::name() ) . ' WHERE id = %d', self::ROW ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- table name from the prefix.
		$failed           = '' !== JobRepository::db_error() || ! is_array( $row );
		$wpdb->suppress_errors( $quiet );
		return $failed ? null : array(
			'state'     => (string) $row['state'],
			'closed_at' => (int) $row['closed_at'],
		);
	}

	/**
	 * Open a fence closed with no heartbeat (beat()) for longer than STALE_SECONDS (an uninstall whose process died
	 * before it opened it again):
	 * one UPDATE on the row as it is, by the server's clock (as close() writes it), so an uninstall that closes it
	 * meanwhile is not undone. Its owner is cleared with it: an uninstall of version 12, which writes none, may close
	 * it next, and the run it was healed under must not take that for its own.
	 *
	 * @return bool Whether a stale fence was opened.
	 */
	public static function heal(): bool {
		global $wpdb;
		$quiet = $wpdb->suppress_errors( true );
		$done  = $wpdb->query( $wpdb->prepare( 'UPDATE ' . SqlWriter::identifier( self::name() ) . ' SET state = %s, closed_by = %s WHERE id = %d AND state = %s AND closed_at < UNIX_TIMESTAMP() - %d', self::OPEN, '', self::ROW, self::CLOSED, self::STALE_SECONDS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- table name from the prefix.
		$wpdb->suppress_errors( $quiet );
		return 1 === (int) $done;
	}

	/**
	 * Whether the fence table is positively not there (listed, and not found); false when it is there or the listing
	 * failed.
	 *
	 * @return bool
	 */
	public static function absent(): bool {
		global $wpdb;
		$quiet  = $wpdb->suppress_errors( true );
		$listed = self::listed( self::name() );
		$wpdb->suppress_errors( $quiet );
		return array() === $listed;
	}

	/**
	 * The tables of that name listed (the table there or not); null when the listing failed.
	 *
	 * @param string $name Table name.
	 * @return string[]|null
	 */
	private static function listed( string $name ) {
		global $wpdb;
		$wpdb->last_error = '';
		$names            = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
		return '' !== JobRepository::db_error() || ! is_array( $names ) ? null : array_map( 'strval', $names );
	}
}
