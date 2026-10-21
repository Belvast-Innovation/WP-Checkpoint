<?php
/**
 * Make this plugin active in the test site's own tables, for processes other than the suite's.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Tests\Fixtures;

/**
 * The suite loads the plugin itself; another process (WP-CLI, a request through the web server, a child that loads
 * WordPress) loads only what the test site's tables say is active. on() makes it active there (network-wide on a
 * network), committed so that other processes read it; off() puts back what was there.
 */
final class ActivePlugin {

	const PLUGIN = 'wp-checkpoint/wp-checkpoint.php';

	/** @var mixed What was active before on() (null: on() not called). */
	private static $before = null;

	/**
	 * Make the plugin active.
	 *
	 * @return void
	 */
	public static function on(): void {
		global $wpdb;
		if ( is_multisite() ) {
			self::$before = get_site_option( 'active_sitewide_plugins', array() );
			update_site_option( 'active_sitewide_plugins', array( self::PLUGIN => time() ) + (array) self::$before );
		} else {
			self::$before = get_option( 'active_plugins', array() );
			update_option( 'active_plugins', array_values( array_unique( array_merge( (array) self::$before, array( self::PLUGIN ) ) ) ) );
		}
		$wpdb->query( 'COMMIT' );
	}

	/**
	 * Put back what was active before on().
	 *
	 * @return void
	 */
	public static function off(): void {
		global $wpdb;
		if ( null === self::$before ) {
			return;
		}
		if ( is_multisite() ) {
			update_site_option( 'active_sitewide_plugins', self::$before );
		} else {
			update_option( 'active_plugins', self::$before );
		}
		self::$before = null;
		$wpdb->query( 'COMMIT' );
	}
}
