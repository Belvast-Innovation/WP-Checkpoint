<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Tests\Fixtures\TableShapes;

/**
 * The control that the table-shape check runs at all: the listener registered in phpunit.xml.dist is watching this
 * test, and read the test site's tables when it started.
 */
final class TableShapesCheckTest extends \WP_UnitTestCase {

	public function test_the_check_is_watching_this_test_and_read_the_tables(): void {
		global $wpdb;
		$this->assertSame( self::class . '::' . __FUNCTION__, TableShapes::watching(), 'the listener in phpunit.xml.dist is on, and watching this test' );
		$this->assertGreaterThanOrEqual( 10, TableShapes::read(), 'it read the test site\'s tables (' . $wpdb->base_prefix . '*) when the test started' );
	}
}
