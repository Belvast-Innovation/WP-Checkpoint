<?php

namespace WPCheckpoint\Tests\Fixtures;

use WPCheckpoint\Support\Directories;

/**
 * This plugin's own rows in the test site's options (and, on a network, its network options) as a test found them,
 * put back after it: a test's transaction is rolled back, but a statement that commits (a DROP TABLE in a tear-down, a
 * COMMIT a test needs for another connection to see its rows) makes what the test wrote until then the site's, and the
 * tests after it start from it (the setting to delete data on uninstall left on; a row a later test takes for the
 * site's state before its restore). Which test meets it depends on the order of the run.
 *
 * Read and written by statements of its own, after the test's transaction is over (Leftovers, the listener). The
 * stored storage state is not among them: StorageDirs puts it back by its own rule (its directory must still be
 * there). Rows are compared and written as stored; nothing is unserialized.
 */
final class OwnOptions {

	/** @var array<string, string[]> Test => what was put back after it (the run's summary). */
	private static $put_back = array();

	/**
	 * The plugin's rows now: "o:<name>" => array( value, autoload ) for options, "m:<key>" => array( value, '' ) for
	 * the network's.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function note(): array {
		global $wpdb;
		$out  = array();
		$like = "(%1\$s LIKE 'wpcheckpoint\\_%%' OR %1\$s LIKE '\\_transient\\_wpcheckpoint\\_%%' OR %1\$s LIKE '\\_transient\\_timeout\\_wpcheckpoint\\_%%' OR %1\$s LIKE '\\_site\\_transient\\_wpcheckpoint\\_%%' OR %1\$s LIKE '\\_site\\_transient\\_timeout\\_wpcheckpoint\\_%%')";
		$rows = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM `{$wpdb->base_prefix}options` WHERE " . sprintf( $like, 'option_name' ), ARRAY_A ); // phpcs:ignore -- a fixture's own statement.
		foreach ( (array) $rows as $row ) {
			if ( Directories::OPTION !== $row['option_name'] ) {
				$out[ 'o:' . $row['option_name'] ] = array( (string) $row['option_value'], (string) $row['autoload'] );
			}
		}
		if ( is_multisite() ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND " . sprintf( $like, 'meta_key' ), get_current_network_id() ), ARRAY_A ); // phpcs:ignore -- a fixture's own statement.
			foreach ( (array) $rows as $row ) {
				if ( Directories::OPTION !== $row['meta_key'] ) {
					$out[ 'm:' . $row['meta_key'] ] = array( (string) $row['meta_value'], '' );
				}
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * What differs between two notes: the names, each said as added, removed or changed.
	 *
	 * @param array<string, array{0: string, 1: string}> $before Before.
	 * @param array<string, array{0: string, 1: string}> $now    Now.
	 * @return string[]
	 */
	public static function differences( array $before, array $now ): array {
		$out = array();
		foreach ( $now as $key => $row ) {
			if ( ! isset( $before[ $key ] ) ) {
				$out[] = 'added ' . substr( $key, 2 );
			} elseif ( $before[ $key ] !== $row ) {
				$out[] = 'changed ' . substr( $key, 2 );
			}
		}
		foreach ( array_keys( $before ) as $key ) {
			if ( ! isset( $now[ $key ] ) ) {
				$out[] = 'removed ' . substr( $key, 2 );
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Put the rows back as noted, and remember what was put back after which test.
	 *
	 * @param array<string, array{0: string, 1: string}> $before note() when the test started.
	 * @param string                                     $test   The test's name.
	 * @return string[] differences() that were put back.
	 */
	public static function put_back( array $before, string $test ): array {
		global $wpdb;
		$now   = self::note();
		$diffs = self::differences( $before, $now );
		if ( array() === $diffs ) {
			return array();
		}
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $now ) ) ) as $key ) {
			if ( ( $before[ $key ] ?? null ) === ( $now[ $key ] ?? null ) ) {
				continue;
			}
			$name = substr( $key, 2 );
			if ( 'o' === $key[0] ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->base_prefix}options` WHERE option_name = %s", $name ) ); // phpcs:ignore -- a fixture's own statement.
				if ( isset( $before[ $key ] ) ) {
					$wpdb->query( $wpdb->prepare( "INSERT INTO `{$wpdb->base_prefix}options` (option_name, option_value, autoload) VALUES (%s, %s, %s)", $name, $before[ $key ][0], $before[ $key ][1] ) ); // phpcs:ignore -- as above.
				}
			} else {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s", get_current_network_id(), $name ) ); // phpcs:ignore -- as above.
				if ( isset( $before[ $key ] ) ) {
					$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->sitemeta} (site_id, meta_key, meta_value) VALUES (%d, %s, %s)", get_current_network_id(), $name, $before[ $key ][0] ) ); // phpcs:ignore -- as above.
				}
			}
		}
		$wpdb->query( 'COMMIT' );
		wp_cache_flush();
		self::$put_back[ $test ] = $diffs;
		return $diffs;
	}

	/**
	 * What was put back in this run, test by test.
	 *
	 * @return array<string, string[]>
	 */
	public static function summary(): array {
		return self::$put_back;
	}
}
