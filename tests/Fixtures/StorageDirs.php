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
	 * Remove the storage directories that are there now and were not in $before (listing() then), other than the
	 * stored one: registered with the Deleter for this deletion only (one may have lost its owner marker).
	 *
	 * @param string[] $before listing() at the start.
	 * @return void
	 */
	public static function remove_made_since( array $before ): void {
		self::remove( array_values( array_diff( self::listing(), $before ) ) );
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
