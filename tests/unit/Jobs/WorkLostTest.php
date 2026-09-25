<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\WorkLost;
use WPCheckpoint\Tests\Fixtures\Permissions;
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

	public function test_a_file_is_gone_only_when_a_listing_shows_it(): void {
		$dir = sys_get_temp_dir() . '/wpcheckpoint-worklost-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir );
		touch( $dir . '/plan.json' );
		try {
			$this->assertFalse( WorkLost::absent( $dir . '/plan.json' ), 'there' );
			$this->assertTrue( WorkLost::absent( $dir . '/index.jsonl' ), 'the directory lists without it' );
			$this->assertTrue( WorkLost::absent( $dir . '/database/chunk-1.sql' ), 'its directory is gone from a listing' );
		} finally {
			unlink( $dir . '/plan.json' );
			rmdir( $dir );
		}
		$this->assertTrue( WorkLost::absent( $dir . '/plan.json' ), 'the whole work directory was reclaimed: the level above lists without it' );
	}

	public function test_nothing_is_known_to_be_gone_when_no_directory_can_be_listed(): void {
		Permissions::require_enforced();
		$dir = sys_get_temp_dir() . '/wpcheckpoint-worklost-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir . '/work', 0700, true );
		touch( $dir . '/work/plan.json' );
		try {
			// The control: listed, the file is there and a missing one is gone.
			$this->assertFalse( WorkLost::absent( $dir . '/work/plan.json' ) );
			$this->assertTrue( WorkLost::absent( $dir . '/work/review.json' ) );
			chmod( $dir . '/work', 0000 );
			$this->assertFalse( WorkLost::absent( $dir . '/work/review.json' ), 'the directory is there but cannot be listed: unknown' );
			$this->assertNotInstanceOf( WorkLost::class, WorkLost::or_unreadable( $dir . '/work/review.json', 'missing', 'unreadable' ) );
		} finally {
			chmod( $dir . '/work', 0700 );
			unlink( $dir . '/work/plan.json' );
			rmdir( $dir . '/work' );
			rmdir( $dir );
		}
	}
}
