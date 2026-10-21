<?php
/**
 * Make this plugin active in the test site's own tables, for processes other than the suite's.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Tests\Fixtures;

/**
 * The suite loads the plugin itself; another process (WP-CLI, a request through the web server, a child that loads
 * WordPress) loads only what the test site's tables say is active. on() makes it active there, committed so that other
 * processes read it: in the main site's active plugins, and on a network network-wide too (the test site's own
 * wp-config.php is a single site's whatever the suite runs as, so its web server and WP-CLI read the main site's
 * list); off() puts back what was there.
 */
final class ActivePlugin {

	const PLUGIN = 'wp-checkpoint/wp-checkpoint.php';

	/** @var mixed What was active before on() (null: on() not called). */
	private static $before = null;

	/** @var mixed What was active network-wide before on() (null: not a network, or on() not called). */
	private static $network = null;

	/**
	 * Make the plugin active.
	 *
	 * @return void
	 */
	public static function on(): void {
		global $wpdb;
		if ( is_multisite() ) {
			self::$network = get_site_option( 'active_sitewide_plugins', array() );
			update_site_option( 'active_sitewide_plugins', array( self::PLUGIN => time() ) + (array) self::$network );
		}
		self::$before = get_option( 'active_plugins', array() );
		update_option( 'active_plugins', array_values( array_unique( array_merge( (array) self::$before, array( self::PLUGIN ) ) ) ) );
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
		if ( null !== self::$network ) {
			update_site_option( 'active_sitewide_plugins', self::$network );
		}
		update_option( 'active_plugins', self::$before );
		self::$before  = null;
		self::$network = null;
		$wpdb->query( 'COMMIT' );
	}
}
