<?php

namespace WPCheckpoint\Tests\Fixtures;

/**
 * For the tests of a base test case's tear-down: each test of the class leaves a row uncommitted, and whichever of
 * them runs later (the order is not the tests' to choose) must not see the row the earlier one left. The row is one
 * in the options table that is not the plugin's (the listener puts the plugin's own rows back, which would hide a
 * row the tear-down committed).
 */
trait UncommittedProbe {

	/** @var int How many tests of the class left their row. */
	private static $probes_left = 0;

	/**
	 * How many rows of the probe the site has.
	 */
	private static function probes(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'wpc_teardown_probe_' ) . '%' ) );
	}

	/**
	 * Take away the rows of the probe, for good.
	 */
	private static function remove_probes(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'wpc_teardown_probe_' ) . '%' ) );
		$wpdb->query( 'COMMIT' );
	}

	/**
	 * See that no test before this one left its row on the site, and leave this one's uncommitted.
	 *
	 * @return void
	 */
	protected function see_no_row_and_leave_one(): void {
		global $wpdb;
		$there = self::probes();
		if ( $there > 0 ) {
			self::remove_probes(); // Not left to the tests after this one, whatever this one says.
		}
		$this->assertSame( 0, $there, 'a row a test of this class wrote and never committed is on the site after its tear-down (' . self::$probes_left . ' of them ran before this one)' );
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => 'wpc_teardown_probe_' . self::$probes_left,
				'option_value' => static::class,
				'autoload'     => 'no',
			)
		);
		$this->assertSame( 1, self::probes(), 'the control: the row is seen while the test that wrote it runs' );
		$this->assertSame( '0', (string) $wpdb->get_var( 'SELECT @@autocommit' ), 'the control: the row is not committed by its own statement' );
		++self::$probes_left;
	}
}
