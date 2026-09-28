<?php
/**
 * No automatic update of this plugin while a restore is unfinished.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

use WPCheckpoint\Jobs\JobRepository;

defined( 'ABSPATH' ) || exit;

/**
 * A restore stages the running copy of this plugin and swaps it in: the
 * plugin must not become another version partway through a restore (an
 * update in between would be replaced by the older copy; the final check
 * then refuses the swap, and the restore has to start again).
 *
 * The filter (auto_update_plugin) answers for this plugin only: for any
 * other plugin it returns WordPress's answer unchanged, and themes and
 * WordPress itself have filters of their own it does not touch. For this
 * plugin it returns false only while a restore is in progress (queued,
 * running or paused, or failed with its work not yet marked expired, a
 * final failure too: JobRepository::expire_work() marks it once
 * WORK_RETENTION_SECONDS have passed, at the next maintenance, which runs
 * when a job ticks or the plugin's page is opened), or when
 * the jobs table cannot be read (no answer is no evidence that nothing is
 * in progress; that case is logged and recorded, the time, so the plugin's
 * page can say why the plugin was not updated). Otherwise it returns
 * WordPress's answer unchanged. A manual update is not held; the final
 * check catches it.
 */
final class AutoUpdateHold {

	/**
	 * When an update check last held the update because the jobs table could not be read.
	 */
	const OPTION = StoredNames::AUTO_UPDATE_UNCHECKED;

	/**
	 * Tests: function(): bool|null in place of JobRepository::restore_in_progress().
	 *
	 * @var callable|null
	 */
	private static $unfinished = null;

	/**
	 * Register the filter.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'auto_update_plugin', array( self::class, 'filter' ), 10, 2 );
	}

	/**
	 * Hold this plugin's automatic update while a restore is unfinished, or cannot be told not to be: false then,
	 * and for anything else (another plugin, or no restore in progress) the answer it was given.
	 *
	 * @param bool|null $update Whether to update, as decided so far.
	 * @param mixed     $item   The update offer (an object with the plugin's file).
	 * @return bool|null
	 */
	public static function filter( $update, $item ) {
		$plugin = is_object( $item ) && isset( $item->plugin ) ? (string) $item->plugin : '';
		if ( '' === $plugin || plugin_basename( WPCHECKPOINT_FILE ) !== $plugin ) {
			return $update;
		}
		$unfinished = self::unfinished();
		if ( null === $unfinished ) {
			Options::set( self::OPTION, time() );
			\WPCheckpoint\Plugin::instance()->directories()->log_event( 'The automatic update of WP Checkpoint was held: the job table could not be read, so an unfinished restore could not be ruled out.' );
			return false;
		}
		if ( false !== Options::get( self::OPTION, false ) ) {
			Options::delete( self::OPTION );
		}
		return $unfinished ? false : $update;
	}

	/**
	 * What the plugin's page says about it: null, "restore" (held while a restore is unfinished) or "unchecked"
	 * (held at the last check because the jobs table could not be read).
	 *
	 * @return string|null
	 */
	public static function state() {
		if ( true === self::unfinished() ) {
			return 'restore';
		}
		return false !== Options::get( self::OPTION, false ) ? 'unchecked' : null;
	}

	/**
	 * Tests: replace the question whether a restore is unfinished (null to ask the jobs table).
	 *
	 * @param callable|null $unfinished function(): bool|null.
	 * @return void
	 */
	public static function set_unfinished( $unfinished ): void {
		self::$unfinished = is_callable( $unfinished ) ? $unfinished : null;
	}

	/**
	 * Whether a restore is in progress (a failed one whose work files are kept included): true, false, or null when the
	 * jobs table could not be read.
	 *
	 * @return bool|null
	 */
	private static function unfinished() {
		return null === self::$unfinished ? JobRepository::restore_in_progress() : call_user_func( self::$unfinished );
	}
}
