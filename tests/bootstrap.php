<?php
/**
 * Test bootstrap.
 *
 * Unit tests run without WordPress. Integration tests (T001) must load the
 * WordPress test library from wp-phpunit inside wp-env and then load the plugin.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$wpcheckpoint_suite = getenv( 'WPCHECKPOINT_TEST_SUITE' ) ?: 'unit';

if ( 'integration' === $wpcheckpoint_suite ) {
	// T001: bootstrap WordPress test library here.
	return;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wp-checkpoint-abspath/' );
}
