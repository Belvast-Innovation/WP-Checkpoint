<?php
/**
 * PHPUnit bootstrap.
 *
 * Two suites share this file, selected by the WPCHECKPOINT_TEST_SUITE
 * environment variable (set by the Composer scripts):
 *
 * - unit: pure PHP, WordPress is never loaded.
 * - integration: loads the WordPress core test library (inside wp-env, or
 *   from wp-phpunit/wp-phpunit when WP_TESTS_DIR is not set) and the plugin.
 *
 * @package WPCheckpoint
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$wpcheckpoint_suite = getenv( 'WPCHECKPOINT_TEST_SUITE' ) ?: 'unit';

if ( 'integration' === $wpcheckpoint_suite ) {
	wpcheckpoint_bootstrap_integration();
	return;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wp-checkpoint-abspath/' );
}

/**
 * Load the WordPress core test library and the plugin.
 *
 * wp-env exports WP_TESTS_DIR (core test suite matching the installed
 * WordPress) and WP_PHPUNIT__TESTS_CONFIG (the tests config file). Outside
 * wp-env, wp-phpunit/wp-phpunit exports WP_PHPUNIT__DIR and the config file
 * must be provided through WP_PHPUNIT__TESTS_CONFIG.
 *
 * @return void
 */
function wpcheckpoint_bootstrap_integration() {
	$tests_dir = getenv( 'WP_TESTS_DIR' );
	if ( ! $tests_dir ) {
		$tests_dir = getenv( 'WP_PHPUNIT__DIR' );
	}

	if ( ! $tests_dir || ! is_readable( $tests_dir . '/includes/functions.php' ) ) {
		fwrite( STDERR, "WordPress test library not found. Set WP_TESTS_DIR or run the suite through wp-env: npm run test:integration\n" );
		exit( 1 );
	}

	if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) && is_readable( $tests_dir . '/wp-tests-config.php' ) ) {
		putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . $tests_dir . '/wp-tests-config.php' );
	}

	require_once $tests_dir . '/includes/functions.php';

	tests_add_filter(
		'muplugins_loaded',
		static function () {
			require dirname( __DIR__ ) . '/wp-checkpoint.php';
		}
	);

	require $tests_dir . '/includes/bootstrap.php';
}
