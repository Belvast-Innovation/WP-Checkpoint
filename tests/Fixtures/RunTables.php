<?php

namespace WPCheckpoint\Tests\Fixtures;

use WPCheckpoint\Jobs\UninstallFence;
use WPCheckpoint\Restore\SwapPlan;

/**
 * This plugin's run tables that an uninstall in a test drops and the test's class does not make again: the swap plan
 * and the uninstall fence (with its row, open). The jobs table is not among them: the classes that uninstall drop
 * and make it in their own set-up and tear-down.
 */
final class RunTables {

	/**
	 * Make the swap plan and the uninstall fence again when they are not there, and commit: under the core test case
	 * the session does not commit on its own, and the case's rollback would take the fence's row away again.
	 *
	 * @return void
	 */
	public static function put_back(): void {
		global $wpdb;
		$wpdb->query( SwapPlan::create_sql( $wpdb->base_prefix . SwapPlan::TABLE ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own statement.
		UninstallFence::create();
		$wpdb->query( 'COMMIT' );
	}
}
