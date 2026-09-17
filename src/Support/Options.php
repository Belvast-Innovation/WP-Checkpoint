<?php
/**
 * Plugin options that follow the installation, not the site.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

defined( 'ABSPATH' ) || exit;

/**
 * On multisite the plugin is network-only and the filesystem is shared, so
 * installation-wide state lives in network options.
 */
final class Options {

	/**
	 * Read an installation option.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $fallback Value when unset.
	 * @return mixed
	 */
	public static function get( string $name, $fallback = false ) {
		return is_multisite() ? get_site_option( $name, $fallback ) : get_option( $name, $fallback );
	}

	/**
	 * Write an installation option (never autoloaded).
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	public static function set( string $name, $value ): bool {
		if ( is_multisite() ) {
			return (bool) update_site_option( $name, $value );
		}
		if ( false === get_option( $name ) ) {
			return (bool) add_option( $name, $value, '', false );
		}
		return (bool) update_option( $name, $value, false );
	}

	/**
	 * Delete an option from both scopes.
	 *
	 * @param string $name Option name.
	 * @return void
	 */
	public static function delete( string $name ): void {
		delete_option( $name );
		if ( is_multisite() ) {
			delete_site_option( $name );
		}
	}
}
