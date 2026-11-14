<?php

namespace WPCheckpoint\Tests\Fixtures;

use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\DeletionRefused;
use WPCheckpoint\Support\Directories;

/**
 * Storage directories in the test site's wp-content (wp-checkpoint-*): a test that makes a Directories of its own (a
 * new token, a new directory) leaves one there, whose stored state its transaction's rollback takes away. One rule for
 * the leftover check (Leftovers) and for the tests that clean up after themselves: every such directory other than
 * the one the stored state names (the plugin's own, kept from test to test).
 */
final class StorageDirs {

	/**
	 * The storage directories in wp-content other than the stored one.
	 *
	 * @return string[]
	 */
	public static function listing(): array {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return array();
		}
		$stored = self::stored_path();
		$out    = array();
		foreach ( glob( WP_CONTENT_DIR . '/' . Directories::DIR_PREFIX . '*', GLOB_ONLYDIR ) ?: array() as $dir ) {
			if ( $dir !== $stored ) {
				$out[] = $dir;
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * The storage directories in wp-content a test may remove after itself: every one this run made (the plugin's,
	 * which the tests make and leave from test to test), none that was there when the run started (Leftovers::before_run():
	 * an earlier run's, which no test made; the leftover check fails a test after which one is gone).
	 *
	 * @return string[]
	 */
	public static function removable(): array {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return array();
		}
		$all = glob( WP_CONTENT_DIR . '/' . Directories::DIR_PREFIX . '*', GLOB_ONLYDIR ) ?: array();
		sort( $all );
		return array_values( array_diff( $all, Leftovers::before_run() ) );
	}

	/**
	 * The storage directory the stored state names, read from the database itself ('' for none): not through the
	 * object cache, which still holds a test's state after its transaction was rolled back, until the next test's
	 * set-up flushes it. The value's "path" is read from its serialized text by its length (nothing is unserialized).
	 *
	 * @return string
	 */
	public static function stored_path(): string {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return '';
		}
		$raw = is_multisite()
			? $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key = %s AND site_id = %d", Directories::OPTION, get_current_network_id() ) )
			: $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Directories::OPTION ) );
		return self::path_in( (string) $raw );
	}

	/**
	 * The stored state's row as the database has it now (its serialized value; on a single site its autoload too), or
	 * null for none.
	 *
	 * @return array{value: string, autoload: string}|null
	 */
	public static function stored_row(): ?array {
		global $wpdb;
		$row = is_multisite()
			? $wpdb->get_row( $wpdb->prepare( "SELECT meta_value AS value, '' AS autoload FROM {$wpdb->sitemeta} WHERE meta_key = %s AND site_id = %d", Directories::OPTION, get_current_network_id() ), ARRAY_A )
			: $wpdb->get_row( $wpdb->prepare( "SELECT option_value AS value, autoload FROM {$wpdb->options} WHERE option_name = %s", Directories::OPTION ), ARRAY_A );
		return is_array( $row ) ? array(
			'value'    => (string) $row['value'],
			'autoload' => (string) $row['autoload'],
		) : null;
	}

	/**
	 * Before a test, outside its transaction: the plugin's own storage directory is there and the stored state,
	 * committed, names it; made now when not (the first test of a run; after a test that removed it). Without this the
	 * test that first needs the directory makes it inside its transaction: the state is rolled back with the test,
	 * and the directory is counted as left behind by it (which test that is depends on the order of the run).
	 *
	 * @return array{value: string, autoload: string}|null The stored state's row as the test finds it.
	 */
	public static function settle(): ?array {
		global $wpdb;
		// The plugin's own objects go: the Directories a test before this one left in memory may name a directory that
		// test's transaction made and its rollback forgot, and the first use of it here would make that directory again.
		\WPCheckpoint\Plugin::instance()->reset_directories();
		$path = self::stored_path();
		if ( '' === $path || ! is_dir( $path ) ) {
			wp_cache_flush(); // The cache may still hold the state of a test whose transaction was rolled back.
			\WPCheckpoint\Plugin::instance()->directories()->base();
			$wpdb->query( 'COMMIT' ); // The core test case leaves autocommit off between tests.
			wp_cache_flush();
		}
		return self::stored_row();
	}

	/**
	 * After a test (its transaction over): the stored state's row is put back as the test found it when the test
	 * left another, or none, committed (a DROP TABLE after the option was deleted commits the deletion) and the
	 * directory it named is still there; when the test removed that directory, the row the test left goes instead (a
	 * state written for a stand-in WordPress directory would be a copy's to the tests after it, each of which then
	 * makes a directory of its own) and settle() makes the next test's. A directory the test made and left under
	 * another state is then listed, and counted as left behind by it.
	 *
	 * @param array{value: string, autoload: string}|null $row settle()'s.
	 * @return void
	 */
	public static function put_back( ?array $row ): void {
		global $wpdb;
		if ( null === $row || self::stored_row() === $row ) {
			return;
		}
		$there = is_dir( self::path_in( $row['value'] ) );
		if ( is_multisite() ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key = %s AND site_id = %d", Directories::OPTION, get_current_network_id() ) );
			if ( $there ) {
				$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->sitemeta} (site_id, meta_key, meta_value) VALUES (%d, %s, %s)", get_current_network_id(), Directories::OPTION, $row['value'] ) );
			}
		} else {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", Directories::OPTION ) );
			if ( $there ) {
				$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", Directories::OPTION, $row['value'], $row['autoload'] ) );
			}
		}
		$wpdb->query( 'COMMIT' );
		wp_cache_flush();
	}

	/**
	 * What a failure over a storage directory left behind is told with: the stored state the test found and the one
	 * there now, and of the directory the state named and of each one left, whether it is there, its owner marker and
	 * the end of its storage log. Read before anything is removed.
	 *
	 * @param array{value: string, autoload: string}|null $row  settle()'s.
	 * @param string[]                                    $left What the test left ("storage:<path>" among it).
	 * @return string '' when no storage directory is among it.
	 */
	public static function evidence( ?array $row, array $left ): string {
		$dirs = array();
		foreach ( $left as $item ) {
			if ( 0 === strpos( $item, 'storage:' ) ) {
				$dirs[] = substr( $item, 8 );
			}
		}
		if ( array() === $dirs ) {
			return '';
		}
		$now   = self::stored_row();
		$lines = array(
			'stored state before the test: ' . ( null === $row ? '(no row)' : $row['value'] ),
			'stored state now: ' . ( null === $now ? '(no row)' : ( $now === $row ? '(the same)' : $now['value'] ) ),
		);
		$named = null === $row ? '' : self::path_in( $row['value'] );
		foreach ( array_merge( '' === $named ? array() : array( $named ), $dirs ) as $dir ) {
			clearstatcache( true );
			$marker  = $dir . '/' . \WPCheckpoint\Support\OwnerMarker::FILENAME;
			$log     = $dir . '/logs/storage.log';
			$lines[] = $dir . ( $dir === $named ? ' (named by the state before)' : ' (left)' ) . ': ' . ( is_dir( $dir ) ? 'a directory' : 'not a directory' );
			$lines[] = '  marker: ' . ( is_file( $marker ) ? trim( (string) file_get_contents( $marker ) ) : '(none)' );
			$lines[] = '  storage log, last lines: ' . ( is_file( $log ) ? "\n    " . implode( "\n    ", array_slice( (array) file( $log, FILE_IGNORE_NEW_LINES ), -6 ) ) : '(none)' );
		}
		return implode( "\n  ", $lines );
	}

	/**
	 * The "path" of a serialized state, '' when it has none; read by the string's length, nothing unserialized.
	 *
	 * @param string $raw Serialized state.
	 * @return string
	 */
	public static function path_in( string $raw ): string {
		if ( 1 !== preg_match( '/s:4:"path";s:(\d+):"/', $raw, $m, PREG_OFFSET_CAPTURE ) ) {
			return '';
		}
		$start = (int) $m[0][1] + strlen( $m[0][0] );
		return rtrim( substr( $raw, $start, (int) $m[1][0] ), '/' );
	}

	/**
	 * Remove the storage directories a test made: those there now that were not in $before (listing() then) and are
	 * not the one the run settled for the test (Leftovers::before_run(): a test's listing() leaves the stored one out,
	 * so that after the test deleted the state the settled directory would count as made by it). The one the stored
	 * state names goes too when the test made it, with the state: a state the test wrote and left committed (its
	 * deletion rolled back with the test's transaction) names a directory of the test's, and the tests after it would
	 * read it. Registered with the Deleter for this deletion only (one may have lost its owner marker).
	 *
	 * @param string[] $before listing() at the start.
	 * @return void
	 */
	public static function remove_made_since( array $before ): void {
		global $wpdb;
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return;
		}
		$all    = glob( WP_CONTENT_DIR . '/' . Directories::DIR_PREFIX . '*', GLOB_ONLYDIR ) ?: array();
		$made   = array_values( array_diff( $all, $before, Leftovers::before_run() ) );
		$stored = self::stored_path();
		if ( '' !== $stored && in_array( $stored, $made, true ) ) {
			if ( is_multisite() ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key = %s AND site_id = %d", Directories::OPTION, get_current_network_id() ) );
			} else {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", Directories::OPTION ) );
			}
			$wpdb->query( 'COMMIT' );
			wp_cache_flush();
		}
		self::remove( $made );
	}

	/**
	 * Remove these storage directories (Deleter, each registered for its deletion only).
	 *
	 * @param string[] $dirs Directories in wp-content named wp-checkpoint-*.
	 * @return void
	 */
	public static function remove( array $dirs ): void {
		foreach ( $dirs as $dir ) {
			if ( dirname( $dir ) !== WP_CONTENT_DIR || 0 !== strpos( basename( $dir ), Directories::DIR_PREFIX ) ) {
				fwrite( STDERR, "\nNot a storage directory in wp-content, left alone: {$dir}\n" );
				continue;
			}
			$roots = Deleter::replace_roots( array() );
			Deleter::replace_roots( $roots );
			try {
				Deleter::allow( $dir );
				Deleter::delete_tree( dirname( $dir ), $dir );
			} catch ( DeletionRefused $e ) {
				fwrite( STDERR, "\n" . $e->getMessage() . "\n" );
			} finally {
				Deleter::replace_roots( $roots );
			}
		}
	}
}
