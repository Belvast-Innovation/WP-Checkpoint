<?php

namespace WPCheckpoint\Tests\Fixtures;

use WPCheckpoint\Jobs\UninstallFence;
use WPCheckpoint\Restore\SwapPlan;
use WPCheckpoint\Support\Schema;

/**
 * This plugin's run tables (the jobs table, the swap plan, the uninstall fence) as a test found them, put back at the
 * end of a test whose tear-down (or uninstall) drops them: the table-shape check (TableShapes) fails a test after
 * which one of them is gone that was there when it started.
 *
 * Noted when the test starts (note(), right after the parent's set-up, before the test's own set-up drops them) and
 * made again from the noted statements (put_back()), through a connection of its own: the test's query filters (the
 * core test case's temporary tables, a test's refusals) and its transaction do not reach it, and nothing else is
 * touched (no option, log or directory; the rows are not put back, the fence's row is made again open).
 */
final class RunTables {

	/** @var array<string, string> Table => its statement when the running test started. */
	private static $noted = array();

	/** @var \mysqli|null The connection of its own. */
	private static $db = null;

	/**
	 * The run tables' names.
	 *
	 * @return string[]
	 */
	private static function names(): array {
		global $wpdb;
		return array( Schema::jobs_table(), $wpdb->base_prefix . SwapPlan::TABLE, UninstallFence::name() );
	}

	/**
	 * The connection of its own, opened once.
	 *
	 * @return \mysqli
	 */
	private static function db(): \mysqli {
		if ( null === self::$db ) {
			$host = (string) DB_HOST;
			$port = null;
			if ( 1 === preg_match( '/\A(.*):([0-9]+)\z/', $host, $parts ) ) {
				$host = $parts[1];
				$port = (int) $parts[2];
			}
			$db = mysqli_init();
			if ( false === $db || ! @mysqli_real_connect( $db, $host, (string) DB_USER, (string) DB_PASSWORD, (string) DB_NAME, $port ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the failure is thrown.
				throw new \RuntimeException( 'The run tables could not be read: no connection to the tests database.' );
			}
			mysqli_set_charset( $db, 'utf8mb4' );
			self::$db = $db;
		}
		return self::$db;
	}

	/**
	 * Note the run tables that are there, and their statements (when a test starts).
	 *
	 * @return void
	 */
	public static function note(): void {
		self::$noted = array();
		foreach ( self::names() as $table ) {
			$shown = mysqli_query( self::db(), 'SHOW CREATE TABLE `' . str_replace( '`', '``', $table ) . '`' );
			if ( $shown instanceof \mysqli_result ) {
				$row = mysqli_fetch_row( $shown );
				mysqli_free_result( $shown );
				if ( is_array( $row ) && isset( $row[1] ) ) {
					self::$noted[ $table ] = TableShapes::shape( (string) $row[1] );
				}
			}
		}
	}

	/**
	 * Make again the noted run tables that are not there (when a test ends), the fence with its row open.
	 *
	 * @return void
	 * @throws \RuntimeException When one could not be made, with why.
	 */
	public static function put_back(): void {
		$db = self::db();
		foreach ( self::$noted as $table => $statement ) {
			$like  = str_replace( array( '\\', '_', '%' ), array( '\\\\', '\\_', '\\%' ), $table );
			$there = mysqli_query( $db, "SHOW FULL TABLES LIKE '" . mysqli_real_escape_string( $db, $like ) . "'" );
			if ( $there instanceof \mysqli_result && mysqli_num_rows( $there ) > 0 ) {
				continue;
			}
			if ( ! mysqli_query( $db, $statement ) ) {
				throw new \RuntimeException( 'The run table ' . $table . ' could not be made again: ' . mysqli_error( $db ) );
			}
			if ( UninstallFence::name() === $table ) {
				mysqli_query( $db, 'INSERT IGNORE INTO `' . str_replace( '`', '``', $table ) . '` (id, state) VALUES (' . (int) UninstallFence::ROW . ", '" . mysqli_real_escape_string( $db, UninstallFence::OPEN ) . "')" );
			}
		}
		self::$noted = array();
	}
}
