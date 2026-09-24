<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\WorkLost;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * A work file that could not be opened is lost work only when it is not there.
 */
final class WorkLostTest extends TestCase {

	public function test_a_file_that_is_there_is_not_lost_work(): void {
		$path = tempnam( sys_get_temp_dir(), 'wpcheckpoint-worklost-' );
		try {
			$e = WorkLost::or_unreadable( $path, 'missing', 'unreadable' );
			$this->assertNotInstanceOf( WorkLost::class, $e, 'there but not opened: permissions, open files, storage; may pass' );
			$this->assertSame( 'unreadable', $e->getMessage() );
		} finally {
			unlink( $path );
		}
		// The same path once removed: now it is lost work.
		$e = WorkLost::or_unreadable( $path, 'missing', 'unreadable' );
		$this->assertInstanceOf( WorkLost::class, $e );
		$this->assertSame( 'missing', $e->getMessage() );
	}
}
