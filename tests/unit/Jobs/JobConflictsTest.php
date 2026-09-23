<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobConflicts;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class JobConflictsTest extends TestCase {

	private static function job( int $id, string $type, string $base = '' ): Job {
		$job          = new Job();
		$job->id      = $id;
		$job->type    = $type;
		$job->options = '' === $base ? array() : array( 'base' => $base );
		return $job;
	}

	public function test_nothing_starts_while_a_restore_is_active(): void {
		$restore = array( self::job( 3, 'restore', 'site-20260923-120000-ab12' ) );
		foreach ( array( array( 'export', array() ), array( 'verify', array( 'base' => 'other-20260923-120000-cd34' ) ), array( 'restore', array( 'base' => 'other-20260923-120000-cd34' ) ) ) as list( $type, $options ) ) {
			$this->assertSame( 'A restore is in progress (job 3); nothing else can start until it has ended.', JobConflicts::conflict( $type, $options, $restore ), $type );
		}
	}

	public function test_an_export_blocks_a_restore_and_another_export_but_not_verifying_another_backup(): void {
		$export = array( self::job( 4, 'export' ) );
		$this->assertSame( 'A backup is being made (job 4); restore once it has ended.', JobConflicts::conflict( 'restore', array( 'base' => 'b-20260923-120000-ab12' ), $export ) );
		$this->assertSame( 'A backup is already being made (job 4).', JobConflicts::conflict( 'export', array(), $export ) );
		$this->assertSame( '', JobConflicts::conflict( 'verify', array( 'base' => 'b-20260923-120000-ab12' ), $export ), 'read-only and unrelated' );
	}

	public function test_a_backup_being_verified_is_not_verified_again_restored_or_deleted(): void {
		$verify = array( self::job( 5, 'verify', 'b-20260923-120000-ab12' ) );
		$this->assertSame( 'This backup is being verified (job 5); wait until the check has ended.', JobConflicts::conflict( 'verify', array( 'base' => 'b-20260923-120000-ab12' ), $verify ) );
		$this->assertSame( 'This backup is being verified (job 5); wait until the check has ended.', JobConflicts::conflict( 'restore', array( 'base' => 'b-20260923-120000-ab12' ), $verify ) );
		$this->assertSame( '', JobConflicts::conflict( 'verify', array( 'base' => 'c-20260923-120000-cd34' ), $verify ), 'another backup' );
		$this->assertSame( '', JobConflicts::conflict( 'export', array(), $verify ) );
		$this->assertSame( 'This backup is being verified (job 5).', JobConflicts::backup_in_use( 'b-20260923-120000-ab12', $verify ) );
		$this->assertSame( 'This backup is being restored (job 6).', JobConflicts::backup_in_use( 'b-20260923-120000-ab12', array( self::job( 6, 'restore', 'b-20260923-120000-ab12' ) ) ) );
		$this->assertSame( '', JobConflicts::backup_in_use( 'c-20260923-120000-cd34', $verify ) );
	}

	public function test_other_job_types_do_not_conflict(): void {
		$this->assertSame( '', JobConflicts::conflict( 'fixture', array(), array( self::job( 7, 'export' ) ) ) );
		$this->assertSame( '', JobConflicts::conflict( 'export', array(), array( self::job( 8, 'fixture' ) ) ) );
	}
}
