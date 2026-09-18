<?php
/**
 * Minimal WP-CLI declarations for PHPStan. Never loaded at runtime.
 *
 * Why not php-stubs/wp-cli-stubs: its dependency constraint pulls
 * php-stubs/wordpress-stubs down from the current major (7.x) to 6.9, which
 * would make the static analysis run against an older WordPress API than
 * the one the plugin targets. Once that package accepts wordpress-stubs 7.x,
 * replace this file with the package. Until then this file declares only
 * the members the plugin actually calls; do not let it grow into a general
 * stub.
 *
 * @package WPCheckpoint
 */

// phpcs:ignoreFile

namespace {
	class WP_CLI {

		/**
		 * @param string $message
		 * @return void
		 */
		public static function line( $message = '' ) {}

		/**
		 * @param string|\WP_Error|\Exception|\Throwable $message
		 * @param bool|int                               $exit
		 * @return never
		 */
		public static function error( $message, $exit = true ) {
			exit( 1 );
		}

		/**
		 * @param string $message
		 * @return void
		 */
		public static function success( $message ) {}

		/**
		 * @param string $message
		 * @return void
		 */
		public static function warning( $message ) {}

		/**
		 * @param int $return_code
		 * @return never
		 */
		public static function halt( $return_code ) {
			exit( $return_code );
		}

		/**
		 * @param string                      $name
		 * @param callable|object|class-string $callable
		 * @param array<string, mixed>        $args
		 * @return bool
		 */
		public static function add_command( $name, $callable, $args = array() ) {
			return true;
		}
	}
}

namespace WP_CLI\Utils {
	/**
	 * @param string                           $format
	 * @param array<int, array<string, mixed>> $items
	 * @param array<int, string>|string        $fields
	 * @return void
	 */
	function format_items( $format, $items, $fields ) {}
}
