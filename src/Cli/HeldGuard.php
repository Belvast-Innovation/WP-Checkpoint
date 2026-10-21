<?php
/**
 * WP-CLI refuses to run commands while a restore holds the site.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Cli;

use WPCheckpoint\Restore\Maintenance;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI does not stop for a maintenance file (it answers enable_maintenance_mode with false): another process's
 * "wp cron event run" (a system cron), "wp action-scheduler run" or an administrator's own command would run on a site
 * whose tables and directories a restore has half swapped. While a held maintenance file of this plugin's is in the
 * WordPress directory (Restore\Maintenance::held_in(): the file system, not the database, which may be the one half
 * swapped), every command is refused here but this plugin's own ("wpcheckpoint", which finishes or puts back the
 * restore) and those that touch no site data ("help", "cli"). enforce() runs when the plugin's file is loaded, before
 * any other plugin's init or wp_loaded hooks and before WordPress's own cron in this process.
 *
 * Known limits: a command run with --skip-plugins (or with this plugin not active in the tables WordPress loads) does
 * not load this check; what plugins loaded before this one do while they load is not stopped.
 */
final class HeldGuard {

	/**
	 * The first words of the commands that run while the site is held.
	 */
	const ALLOWED = array( 'wpcheckpoint', 'help', 'cli' );

	/**
	 * Why a command is refused, or '' when it runs.
	 *
	 * @param string[]|null $args The command's words (WP-CLI's runner arguments), null when they cannot be read.
	 * @param bool          $held Whether a held maintenance file of this plugin's is in the WordPress directory.
	 * @return string
	 */
	public static function refusal( $args, bool $held ): string {
		if ( ! $held ) {
			return '';
		}
		$first = null === $args ? null : strtolower( (string) ( $args[0] ?? '' ) );
		if ( null !== $first && ( '' === $first || in_array( $first, self::ALLOWED, true ) ) ) {
			return '';
		}
		return __( 'A restore holds this site: its maintenance file is up and does not lapse (.maintenance in the WordPress directory), and the site\'s tables and directories may be half swapped. Until the restore ends, WP-CLI runs no command here but wp wpcheckpoint (and wp help): wp wpcheckpoint job list shows the restore and what finishes it. If no restore holds the site, remove .maintenance and .wpcheckpoint-maintenance.php from the WordPress directory.', 'wp-checkpoint' );
	}

	/**
	 * Refuse the command WP-CLI is about to run while the site is held (WP_CLI::error(): the process exits).
	 *
	 * @return void
	 */
	public static function enforce(): void {
		if ( ! class_exists( '\WP_CLI' ) || ! Maintenance::held_in( (string) ABSPATH ) ) {
			return;
		}
		try {
			$args = (array) \WP_CLI::get_runner()->arguments;
		} catch ( \Throwable $e ) {
			$args = null; // Not read: refused, as any command would be.
		}
		$why = self::refusal( $args, true );
		if ( '' !== $why ) {
			\WP_CLI::error( $why );
		}
	}
}
