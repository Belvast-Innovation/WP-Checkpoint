<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\ExportPlan;
use WPCheckpoint\Jobs\PackStep;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Jobs\StoreStep;
use WPCheckpoint\Jobs\TransientFailure;
use WPCheckpoint\Tests\Fixtures\Jobs\WorkContext;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The move into backups/: every file in one of four states on replay,
 * the collision check once and remembered, nothing ever overwritten.
 */
final class StoreStepTest extends TestCase {

	const BASE = 'site-20260922-100000-ab12';

	/** @var WorkContext */
	private $ctx;

	/** @var string[] */
	private $names;

	protected function set_up(): void {
		$this->ctx = new WorkContext( 'wpcheckpoint-store-' );
		mkdir( $this->ctx->work() . '/' . PackStep::VOLUMES );
		ExportPlan::write( $this->ctx->work(), ExportPlan::PLAN, array( 'base' => self::BASE, 'tables' => array(), 'groups' => array(), 'exclusions' => array() ) );
		$this->names = array( self::BASE . '.part001.wpcheckpoint.zip', self::BASE . '.part002.wpcheckpoint.zip', self::BASE . '.manifest.json' );
		$base        = json_decode( (string) file_get_contents( __DIR__ . '/../../Fixtures/Manifest/valid/base.json' ), true );
		$volumes     = array();
		foreach ( array_slice( $this->names, 0, 2 ) as $name ) {
			$this->put( $name, 'zip ' . $name );
			$volumes[] = array( 'path' => $name, 'bytes' => strlen( 'zip ' . $name ), 'sha256' => hash( 'sha256', 'zip ' . $name ) );
		}
		$base['volumes'] = $volumes;
		$this->put( self::BASE . '.manifest.json', (string) json_encode( $base ) );
	}

	protected function tear_down(): void {
		$this->ctx->remove();
	}

	private function put( string $name, string $content ): void {
		file_put_contents( $this->ctx->work() . '/' . PackStep::VOLUMES . '/' . $name, $content );
	}

	private function in_work( string $name ): bool {
		return is_file( $this->ctx->work() . '/' . PackStep::VOLUMES . '/' . $name );
	}

	private function in_backups( string $name ): bool {
		return is_file( $this->ctx->backups() . '/' . $name );
	}

	private function step(): StoreStep {
		return new StoreStep( $this->ctx->backups() );
	}

	public function test_files_move_in_order_and_the_manifest_last(): void {
		$result = $this->step()->run( $this->ctx->context() );
		$this->assertSame( StepResult::DONE, $result->kind );
		foreach ( $this->names as $name ) {
			$this->assertTrue( $this->in_backups( $name ), $name );
			$this->assertFalse( $this->in_work( $name ), $name );
		}
		$cursors = array_column( $this->ctx->checkpoints, 'cursor' );
		$this->assertSame( array( 'checked' => true, 'moved' => 0 ), $cursors[0], 'the collision check is recorded before the first move' );
		$this->assertSame( 3, $cursors[ count( $cursors ) - 1 ]['moved'] );
		$this->assertSame( 'zip ' . $this->names[0], (string) file_get_contents( $this->ctx->backups() . '/' . $this->names[0] ) );
	}

	public function test_a_taken_name_fails_before_any_move_and_nothing_is_overwritten(): void {
		file_put_contents( $this->ctx->backups() . '/' . $this->names[1], 'someone else' );
		try {
			$this->step()->run( $this->ctx->context() );
			$this->fail();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'already exists in the backups directory; nothing was overwritten', $e->getMessage() );
		}
		$this->assertTrue( $this->in_work( $this->names[0] ), 'the first volume was not moved either' );
		$this->assertSame( 'someone else', (string) file_get_contents( $this->ctx->backups() . '/' . $this->names[1] ) );
	}

	public function test_replay_after_a_rename_without_its_checkpoint_does_not_see_its_own_file_as_a_collision(): void {
		// The first run checked and moved volume 1, then died before the checkpoint that records moved = 1.
		$first = $this->step()->run( $this->ctx->context( array(), 20 ) );
		$this->assertSame( StepResult::DONE, $first->kind );
		// Put things back as they were right after that crash: checked, moved = 0, volume 1 already in backups.
		rename( $this->ctx->backups() . '/' . $this->names[1], $this->ctx->work() . '/' . PackStep::VOLUMES . '/' . $this->names[1] );
		rename( $this->ctx->backups() . '/' . $this->names[2], $this->ctx->work() . '/' . PackStep::VOLUMES . '/' . $this->names[2] );
		$this->ctx->checkpoints = array();
		$result                 = $this->step()->run( $this->ctx->context( array( 'checked' => true, 'moved' => 0 ) ) );
		$this->assertSame( StepResult::DONE, $result->kind, 'volume 1 in backups only counts as moved' );
		foreach ( $this->names as $name ) {
			$this->assertTrue( $this->in_backups( $name ) );
		}
		$this->assertSame( array( 'checked' => true, 'moved' => 3 ), $this->ctx->last_cursor() );
	}

	public function test_a_file_in_both_places_or_in_neither_fails_without_renaming(): void {
		// In both: a copy of volume 2 appeared in backups after the check (tampering, or another job's name).
		file_put_contents( $this->ctx->backups() . '/' . $this->names[1], 'other' );
		try {
			$this->step()->run( $this->ctx->context( array( 'checked' => true, 'moved' => 1 ) ) );
			$this->fail();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'both in the work directory and in the backups directory; nothing was overwritten', $e->getMessage() );
		}
		$this->assertSame( 'other', (string) file_get_contents( $this->ctx->backups() . '/' . $this->names[1] ) );
		$this->assertTrue( $this->in_work( $this->names[1] ) );
		// In neither: the work directory lost a volume.
		unlink( $this->ctx->backups() . '/' . $this->names[1] );
		unlink( $this->ctx->work() . '/' . PackStep::VOLUMES . '/' . $this->names[1] );
		try {
			$this->step()->run( $this->ctx->context( array( 'checked' => true, 'moved' => 1 ) ) );
			$this->fail();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'is missing; the work directory was lost or changed', $e->getMessage() );
		}
		$this->assertTrue( $this->in_work( $this->names[2] ), 'the manifest stays until every volume is in place' );
	}

	public function test_the_manifest_is_read_from_backups_when_it_was_the_last_thing_moved(): void {
		$this->step()->run( $this->ctx->context() );
		$this->ctx->checkpoints = array();
		$again                  = $this->step()->run( $this->ctx->context( array( 'checked' => true, 'moved' => 2 ) ) );
		$this->assertSame( StepResult::DONE, $again->kind, 'a replay after the manifest moved finds the names in backups' );
	}

	public function test_a_failed_rename_is_transient(): void {
		$failing = new StoreStep( $this->ctx->root . '/no-such-dir' );
		try {
			$failing->run( $this->ctx->context() );
			$this->fail();
		} catch ( TransientFailure $e ) {
			$this->assertStringContainsString( 'could not be moved', $e->getMessage() );
		}
	}
}
