<?php

namespace WPCheckpoint\Tests\Fixtures;

/**
 * The transaction the WordPress test case keeps open over a test, ended in a tear-down before a statement that would
 * commit it: a COMMIT, or a statement the server commits before (DROP TABLE and the other DDL). Without this, whatever
 * the test wrote and left uncommitted is committed with it, onto the site the tests after it run on.
 */
final class OpenTransaction {

	/**
	 * Roll back what the test left uncommitted. The object cache goes with it: it still holds the values that were
	 * rolled back, and the tear-down's own reads and writes of options would go by them.
	 *
	 * @return void
	 */
	public static function roll_back(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();
	}
}
