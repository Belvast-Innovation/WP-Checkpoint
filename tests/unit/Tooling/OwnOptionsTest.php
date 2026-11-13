<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;
use WPCheckpoint\Tests\Fixtures\OwnOptions;

/**
 * What differs between the plugin's option rows before and after a test (the part of OwnOptions that asks no database;
 * the reading and the putting back are checked on the test site, LeftoversCheckTest).
 */
final class OwnOptionsTest extends TestCase {

	public function test_rows_added_changed_and_removed_are_each_named_and_equal_notes_differ_in_nothing(): void {
		$before = array(
			'o:wpcheckpoint_a' => array( '1', 'yes' ),
			'o:wpcheckpoint_b' => array( '2', 'no' ),
			'm:wpcheckpoint_c' => array( '3', '' ),
		);
		$this->assertSame( array(), OwnOptions::differences( $before, $before ) );
		$now = array(
			'o:wpcheckpoint_a' => array( '1', 'no' ),
			'm:wpcheckpoint_c' => array( '3', '' ),
			'm:wpcheckpoint_d' => array( '4', '' ),
		);
		$this->assertSame( array( 'added wpcheckpoint_d', 'changed wpcheckpoint_a', 'removed wpcheckpoint_b' ), OwnOptions::differences( $before, $now ) );
	}
}
