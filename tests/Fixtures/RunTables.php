<?php

namespace WPCheckpoint\Tests\Fixtures;

use WPCheckpoint\Jobs\UninstallFence;
use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\Schema;

/**
 * This plugin's run tables as Schema::ensure() makes them (the jobs table, the swap plan, the uninstall fence with its
 * row open), put back at the end of a test whose tear-down (or uninstall) drops them: the table-shape check
 * (TableShapes) fails a test after which one of them is gone that was there when it started.
 */
final class RunTables {

	/**
	 * Every migration replayed from the start (the stored version and any recorded failed attempt deleted first, the
	 * request taken as one that may upgrade for this call only), so each table that is not there is made, and the
	 * fence's row; then committed: under the core test case the session does not commit on its own, and the case's
	 * rollback would take the stored version and the fence's row away again.
	 *
	 * @return void
	 * @throws \RuntimeException When the schema was not made (what Schema::ensure() said).
	 */
	public static function put_back(): void {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		Options::delete( Schema::OPTION );
		Options::delete( Schema::RETRY_OPTION );
		$context = new \ReflectionProperty( Schema::class, 'may_upgrade' );
		$context->setAccessible( true );
		$was = $context->getValue();
		Schema::set_upgrade_context( true );
		try {
			$result = Schema::ensure();
		} finally {
			Schema::set_upgrade_context( $was );
		}
		UninstallFence::create();
		$wpdb->query( 'COMMIT' );
		if ( ! in_array( $result['action'], array( 'created', 'migrated', 'repaired', 'none' ), true ) ) {
			throw new \RuntimeException( 'The plugin\'s tables were not put back: ' . wp_json_encode( $result ) );
		}
	}
}
