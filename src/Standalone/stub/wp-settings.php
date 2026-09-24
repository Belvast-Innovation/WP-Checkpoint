<?php
/**
 * Stands in for WordPress's wp-settings.php while Standalone\ConfigLoader
 * runs wp-config.php: ABSPATH points at this directory, so the
 * "require_once ABSPATH . 'wp-settings.php'" at the end of wp-config.php
 * loads this empty file instead of WordPress.
 *
 * @package WPCheckpoint
 */

defined( 'ABSPATH' ) || exit;
