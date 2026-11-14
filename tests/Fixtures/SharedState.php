<?php

namespace WPCheckpoint\Tests\Fixtures;

use WPCheckpoint\Jobs\UninstallFence;

/**
 * The register of state every test shares and must leave at its baseline: what one test leaves committed off it, the
 * tests after it start from, and which of them meets it depends on the order of the run. The listener (Leftovers)
 * asks after each test (and each class): an item off its baseline fails that test by name, and is put back, so the
 * tests after it are not affected.
 *
 * An item: what it is about in the plugin ("subject": a class constant, by name), the method here that says how it is
 * off its baseline ("reads": '' when it is at it), and the method here that puts it back ("restores"). An item whose
 * subject or methods are no longer there is stale, and fails the run (stale()): a check of something that is gone
 * passes for ever.
 *
 * Read and written on the test site's connection itself (not through wpdb's filters) after the test's transaction is
 * over: what the next test would find. The plugin's own option rows are not in the register (OwnOptions puts them back and says them when the run
 * ends, without failing the test).
 */
final class SharedState {

	/**
	 * The items, by the name a failure says.
	 *
	 * The uninstall fence: its own table what the test site's connection reads (no temporary table of its name over
	 * it), its one row there and open. Left closed, every later swap is refused and every later uninstall finds it
	 * held.
	 */
	const ITEMS = array(
		'the uninstall fence' => array(
			'subject'  => UninstallFence::class . '::TABLE',
			'reads'    => 'fence',
			'restores' => 'open_fence',
		),
	);

	/** @var array<string, array<string, string>>|null The items a test put in the register's place (replace()). */
	private static $replaced = null;

	/**
	 * The items the listener asks about: ITEMS, or what a test of the listener put in their place.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function items(): array {
		return null === self::$replaced ? self::ITEMS : self::$replaced;
	}

	/**
	 * Put other items in the register's place (a test of the listener; null: the register again).
	 *
	 * @param array<string, array<string, string>>|null $items The items.
	 * @return void
	 */
	public static function replace( ?array $items ): void {
		self::$replaced = $items;
	}

	/**
	 * The items that are stale, each with why.
	 *
	 * @param array<string, array<string, string>>|null $items The items (null: items()).
	 * @return string[]
	 */
	public static function stale( ?array $items = null ): array {
		$items = null === $items ? self::items() : $items;
		$out   = array();
		foreach ( $items as $name => $item ) {
			$subject = (string) ( $item['subject'] ?? '' );
			if ( '' === $subject || ! defined( $subject ) ) {
				$out[] = sprintf( '%s: its subject %s is not there', $name, '' === $subject ? '(none)' : $subject );
			}
			foreach ( array( 'reads', 'restores' ) as $key ) {
				$method = (string) ( $item[ $key ] ?? '' );
				if ( '' === $method || ! is_callable( array( self::class, $method ) ) ) {
					$out[] = sprintf( '%s: its "%s" method %s is not there', $name, $key, '' === $method ? '(none)' : $method );
				}
			}
		}
		return $out;
	}

	/**
	 * The items off their baseline now: name => how.
	 *
	 * @param array<string, array<string, string>>|null $items The items (null: items()).
	 * @return array<string, string>
	 */
	public static function off_baseline( ?array $items = null ): array {
		$items = null === $items ? self::items() : $items;
		$out   = array();
		foreach ( $items as $name => $item ) {
			if ( ! is_callable( array( self::class, (string) ( $item['reads'] ?? '' ) ) ) ) {
				continue; // Stale: said by stale().
			}
			$how = (string) call_user_func( array( self::class, $item['reads'] ) );
			if ( '' !== $how ) {
				$out[ $name ] = $how;
			}
		}
		return $out;
	}

	/**
	 * Put the named items back at their baseline, committed.
	 *
	 * @param string[]                                  $names Names.
	 * @param array<string, array<string, string>>|null $items The items (null: items()).
	 * @return void
	 */
	public static function restore( array $names, ?array $items = null ): void {
		global $wpdb;
		$items = null === $items ? self::items() : $items;
		foreach ( $names as $name ) {
			if ( isset( $items[ $name ] ) && is_callable( array( self::class, (string) ( $items[ $name ]['restores'] ?? '' ) ) ) ) {
				call_user_func( array( self::class, $items[ $name ]['restores'] ) );
			}
		}
		$wpdb->query( 'COMMIT' );
	}

	/**
	 * A statement on the test site's connection itself: through wpdb a test's CREATE TABLE and DROP TABLE become
	 * those of a temporary table (the core test case's filters), and the fence's base table would not be reached.
	 *
	 * @param string $sql Statement.
	 * @return array<int, array<string, string|null>>|string Its rows (none for a statement without a result), or the error.
	 */
	private static function on_connection( string $sql ) {
		global $wpdb;
		try {
			$done = mysqli_query( $wpdb->dbh, $sql );
		} catch ( \mysqli_sql_exception $e ) {
			return '' === $e->getMessage() ? 'failed' : $e->getMessage();
		}
		if ( false === $done ) {
			$error = (string) mysqli_error( $wpdb->dbh );
			return '' === $error ? 'failed' : $error;
		}
		if ( true === $done ) {
			return array();
		}
		$rows = mysqli_fetch_all( $done, MYSQLI_ASSOC );
		mysqli_free_result( $done );
		return $rows;
	}

	/**
	 * Whether a temporary table of the fence's name is on the test site's connection (it hides the fence's own table
	 * from every statement of that connection); null when it cannot be told (no table of the name at all).
	 *
	 * @return bool|null
	 */
	private static function fence_hidden(): ?bool {
		$shown = self::on_connection( 'SHOW CREATE TABLE `' . str_replace( '`', '``', UninstallFence::name() ) . '`' );
		if ( ! is_array( $shown ) || ! isset( $shown[0]['Create Table'] ) ) {
			return null;
		}
		return 0 === strpos( (string) $shown[0]['Create Table'], 'CREATE TEMPORARY TABLE' );
	}

	/**
	 * How the uninstall fence is off its baseline, '' when its own table is what the connection reads, and its row is
	 * there and open.
	 *
	 * @return string
	 */
	public static function fence(): string {
		if ( true === self::fence_hidden() ) {
			return 'a temporary table of its name hides it on the test site\'s connection';
		}
		$rows = self::on_connection( 'SELECT * FROM `' . str_replace( '`', '``', UninstallFence::name() ) . '` WHERE id = ' . (int) UninstallFence::ROW );
		if ( ! is_array( $rows ) ) {
			return 'its row cannot be read (' . $rows . ')';
		}
		if ( array() === $rows ) {
			return 'its row is not there';
		}
		$state = (string) ( $rows[0]['state'] ?? '' );
		if ( UninstallFence::OPEN === $state ) {
			return '';
		}
		return sprintf( 'left "%s" (closed_by "%s")', $state, (string) ( $rows[0]['closed_by'] ?? '' ) );
	}

	/**
	 * The uninstall fence's own table and row there, the row open and no one's; temporary tables of its name gone.
	 *
	 * @return void
	 */
	public static function open_fence(): void {
		$name = '`' . str_replace( '`', '``', UninstallFence::name() ) . '`';
		for ( $i = 0; $i < 8 && true === self::fence_hidden(); $i++ ) {
			self::on_connection( 'DROP TEMPORARY TABLE ' . $name );
		}
		if ( ! is_array( self::on_connection( 'SELECT id FROM ' . $name . ' LIMIT 1' ) ) ) {
			// Not there (or not readable): made again. Not otherwise: the statement commits and waits for the table.
			self::on_connection( UninstallFence::create_sql( UninstallFence::name() ) );
		}
		self::on_connection( 'INSERT IGNORE INTO ' . $name . ' (id, state) VALUES (' . (int) UninstallFence::ROW . ", '" . UninstallFence::OPEN . "')" );
		if ( ! is_array( self::on_connection( 'UPDATE ' . $name . " SET state = '" . UninstallFence::OPEN . "', closed_by = '' WHERE id = " . (int) UninstallFence::ROW ) ) ) {
			// A table as version 12 made it has no owner to clear (its shape is the table-shape check's to say).
			self::on_connection( 'UPDATE ' . $name . " SET state = '" . UninstallFence::OPEN . "' WHERE id = " . (int) UninstallFence::ROW );
		}
	}
}
