<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\QuestionText;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Question texts come from a work file: a damaged one gives the generic
 * line, never a PHP error.
 */
final class QuestionTextTest extends TestCase {

	public function test_findings_of_the_wrong_shape_give_the_generic_line(): void {
		$findings = array(
			'heavy'      => array( array( 'bytes' => 5 ), array( 'p' => array( 'x' ), 'bytes' => 5 ) ),
			'oversize'   => array( array( 'table' => 'wp_posts' ), array( 'limit' => 3, 'count' => 1 ) ),
			'unreadable' => array( 'listed' => array( array( 'a' ), 'b.txt' ) ),
		);
		$this->assertSame( 'A decision is needed (large_dir).', QuestionText::describe( 'large_dir_0', array( 'kind' => 'large_dir' ), $findings ) );
		$this->assertSame( 'A decision is needed (large_dir).', QuestionText::describe( 'large_dir_1', array( 'kind' => 'large_dir' ), $findings ) );
		$this->assertSame( 'A decision is needed (oversize).', QuestionText::describe( 'oversize_0', array( 'kind' => 'oversize' ), $findings ) );
		$this->assertSame( 'A decision is needed (oversize).', QuestionText::describe( 'oversize_1', array( 'kind' => 'oversize' ), $findings ) );
		$this->assertSame( '2 files cannot be read and would not be in the backup (for example b.txt). Continue without them, or stop?', QuestionText::describe( 'unreadable', array( 'count' => 2 ), $findings ) );
	}

	public function test_well_formed_findings_are_described(): void {
		$findings = array(
			'heavy'    => array( array( 'p' => 'wp-content/node_modules', 'bytes' => 300 * 1048576 ) ),
			'oversize' => array( array( 'table' => 'wp_posts', 'count' => null, 'limit' => 1024 ) ),
		);
		$this->assertStringStartsWith( 'Directory wp-content/node_modules holds 300 MB', QuestionText::describe( 'large_dir_0', array(), $findings ) );
		$this->assertStringStartsWith( 'Table wp_posts may have rows larger than the single-row limit of 1024 bytes.', QuestionText::describe( 'oversize_0', array(), $findings ) );
	}
}
