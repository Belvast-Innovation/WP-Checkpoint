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
 * whose tables and directories a restore has half swapped. While a restore holds the site, as the two files in the
 * WordPress directory tell (Restore\Maintenance::held_evidence(): the file system, not the database, which may be the
 * one half swapped; a held data file is enough, whether .maintenance is still there or not; what cannot be told of
 * either file counts as held), every command is refused here but this plugin's own ("wpcheckpoint", which finishes
 * or puts back the restore, or takes its files down) and those that touch no site data ("help", "cli"). enforce()
 * runs when the plugin's file is loaded, before any other plugin's init or wp_loaded hooks and before WordPress's own
 * cron in this process.
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
	 * @param string[]|null               $args     The command's words (WP-CLI's runner arguments), null when they cannot be read.
	 * @param array{0: string, 1: string} $evidence Whether a restore holds the site (Maintenance::held_evidence()).
	 * @return string
	 */
	public static function refusal( $args, array $evidence ): string {
		if ( Maintenance::NONE === $evidence[0] ) {
			return '';
		}
		$first = null === $args ? null : strtolower( (string) ( $args[0] ?? '' ) );
		if ( null !== $first && ( '' === $first || in_array( $first, self::ALLOWED, true ) ) ) {
			return '';
		}
		// Not translated: this runs while the plugin's file loads, before WordPress may load translations.
		$until = ' Until the restore ends, WP-CLI runs no command here but wp wpcheckpoint (and wp help): wp wpcheckpoint job list shows the restore and what finishes it or puts the site back.';
		switch ( $evidence[1] ) {
			case Maintenance::BY_DATA:
				return 'A restore holds this site: its data file says so and does not lapse (.wpcheckpoint-maintenance.php in the WordPress directory, whether .maintenance is still there or not), and the site\'s tables and directories may be half swapped.' . $until . ' If no restore holds the site, remove .wpcheckpoint-maintenance.php (and .maintenance, when it is this plugin\'s) from the WordPress directory.';
			case Maintenance::BY_CODE:
				return 'A restore holds this site: this plugin\'s maintenance code is up with no data file next to it, and nothing lets it lapse (.maintenance in the WordPress directory); the site\'s tables and directories may be half swapped.' . $until . ' If no restore holds the site, remove .maintenance from the WordPress directory.';
			case Maintenance::UNTOLD_DATA:
				return 'Whether a restore holds this site cannot be told, and it is taken as held: .wpcheckpoint-maintenance.php in the WordPress directory cannot be read by the user running WP-CLI, or is a link. WP-CLI runs no command here but wp wpcheckpoint (and wp help) until it can be told: make the file readable to this user, or run WP-CLI as the user the site\'s files belong to. wp wpcheckpoint job list shows whether a restore holds the site; if none does, remove the file.';
			case Maintenance::UNTOLD_FILE:
				return 'Whether a restore holds this site cannot be told, and it is taken as held: .maintenance in the WordPress directory cannot be read by the user running WP-CLI, or is a link, and may be a restore\'s. WP-CLI runs no command here but wp wpcheckpoint (and wp help) until it can be told: make the file readable to this user, or run WP-CLI as the user the site\'s files belong to. wp wpcheckpoint job list shows whether a restore holds the site; if none does and the file is not wanted, remove it.';
		}
		return 'A restore holds this site: its maintenance file is up and does not lapse (.maintenance in the WordPress directory), and the site\'s tables and directories may be half swapped.' . $until . ' If no restore holds the site, remove .maintenance and .wpcheckpoint-maintenance.php from the WordPress directory.';
	}

	/**
	 * Refuse the command WP-CLI is about to run while the site is held (WP_CLI::error(): the process exits).
	 *
	 * @return void
	 */
	public static function enforce(): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}
		$evidence = Maintenance::held_evidence( (string) ABSPATH );
		if ( Maintenance::NONE === $evidence[0] ) {
			return;
		}
		try {
			$args = (array) \WP_CLI::get_runner()->arguments;
		} catch ( \Throwable $e ) {
			$args = null; // Not read: refused, as any command would be.
		}
		$why = self::refusal( $args, $evidence );
		if ( '' !== $why ) {
			\WP_CLI::error( $why );
		}
	}
}
