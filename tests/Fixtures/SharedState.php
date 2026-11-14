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
 * Read and written through the test site's connection after the test's transaction is over: what the next test would
 * find. The plugin's own option rows are not in the register (OwnOptions puts them back and says them when the run
 * ends, without failing the test).
 */
final class SharedState {

	/**
	 * The items, by the name a failure says.
	 *
	 * The uninstall fence: its table there, its one row there and open. Left closed, every later swap is refused and
	 * every later uninstall finds it held.
	 */
	const ITEMS = array(
		'the uninstall fence' => array(
			'subject'  => UninstallFence::class . '::TABLE',
			'reads'    => 'fence',
			'restores' => 'open_fence',
		),
	);

	/**
	 * The items that are stale, each with why.
	 *
	 * @param array<string, array<string, string>> $items The items.
	 * @return string[]
	 */
	public static function stale( array $items = self::ITEMS ): array {
		$out = array();
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
	 * @param array<string, array<string, string>> $items The items.
	 * @return array<string, string>
	 */
	public static function off_baseline( array $items = self::ITEMS ): array {
		$out = array();
		foreach ( $items as $name => $item ) {
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
	 * @param string[]                             $names Names.
	 * @param array<string, array<string, string>> $items The items.
	 * @return void
	 */
	public static function restore( array $names, array $items = self::ITEMS ): void {
		global $wpdb;
		foreach ( $names as $name ) {
			if ( isset( $items[ $name ] ) ) {
				call_user_func( array( self::class, $items[ $name ]['restores'] ) );
			}
		}
		$wpdb->query( 'COMMIT' );
	}

	/**
	 * How the uninstall fence is off its baseline, '' when its row is there and open.
	 *
	 * @return string
	 */
	public static function fence(): string {
		global $wpdb;
		$quiet            = $wpdb->suppress_errors( true );
		$wpdb->last_error = '';
		$rows             = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . str_replace( '`', '``', UninstallFence::name() ) . '` WHERE id = %d', UninstallFence::ROW ), ARRAY_A ); // phpcs:ignore -- a fixture's own statement.
		$error            = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $quiet );
		if ( '' !== $error || ! is_array( $rows ) ) {
			return 'its row cannot be read (' . ( '' === $error ? 'no result' : $error ) . ')';
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
	 * The uninstall fence's table and row there, the row open.
	 *
	 * @return void
	 */
	public static function open_fence(): void {
		UninstallFence::create();
		UninstallFence::open();
	}
}
