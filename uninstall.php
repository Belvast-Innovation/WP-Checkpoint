<?php
/**
 * Uninstall handler.
 *
 * Backups are user data: they are only deleted when the user explicitly opted in
 * (option wpcheckpoint_delete_data_on_uninstall). See T002.
 *
 * @package WPCheckpoint
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// T002: implement opt-in cleanup of options, tables and backup directories.
