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

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation stand-in for the unit suite: steps label their progress
	 * with translatable strings and are otherwise pure PHP.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain (ignored).
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string { // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText,Universal.Files.SeparateFunctionsFromOO.Mixed -- test stand-in.
		unset( $domain );
		return $text;
	}

	/**
	 * Plural translation stand-in for the unit suite (English rule).
	 *
	 * @param string $single Singular.
	 * @param string $plural Plural.
	 * @param int    $number Number.
	 * @param string $domain Text domain (ignored).
	 * @return string
	 */
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string { // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralSingle,WordPress.WP.I18n.NonSingularStringLiteralPlural,Universal.Files.SeparateFunctionsFromOO.Mixed -- test stand-in.
		unset( $domain );
		return 1 === $number ? $single : $plural;
	}

	/**
	 * Number formatting stand-in for the unit suite (English separators).
	 *
	 * @param float|int $number   Number.
	 * @param int       $decimals Decimals.
	 * @return string
	 */
	function number_format_i18n( $number, int $decimals = 0 ): string { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- test stand-in.
		return number_format( (float) $number, $decimals );
	}
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

	// The core test library reads the WP_TESTS_MULTISITE constant; allow enabling it from the environment.
	if ( '1' === getenv( 'WP_TESTS_MULTISITE' ) && ! defined( 'WP_TESTS_MULTISITE' ) ) {
		define( 'WP_TESTS_MULTISITE', true );
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
