<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Archive\VerificationResult;
use WPCheckpoint\Backups\VerifyRecord;
use WPCheckpoint\Jobs\LockLost;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Jobs\VerifyStep;
use WPCheckpoint\Tests\Fixtures\Archive\ArchiveBuilder;
use WPCheckpoint\Tests\Fixtures\Jobs\WorkContext;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The verify job's step against archives the real Packer built: the
 * manifest hash fixed before the first unit, the record fixed in the
 * cursor before it is written, the rename behind the lease, and the
 * measured-duration guard.
 */
final class VerifyStepTest extends TestCase {

	const VERIFIED_AT = 1758600000;

	/** @var ArchiveBuilder|null */
	private $builder;

	/** @var WorkContext */
	private $ctx;

	protected function set_up(): void {
		$this->ctx = new WorkContext( 'wpcheckpoint-verifystep-' );
	}

	protected function tear_down(): void {
		$this->ctx->remove();
		if ( null !== $this->builder ) {
			$this->builder->cleanup();
		}
		// PHPUnit keeps finished test objects: release the fixture's contents.
		$this->builder = null;
	}

	private function archive(): ArchiveBuilder {
		$this->builder      = ( new ArchiveBuilder() )->typical()->build();
		$this->ctx->options = array(
			'base'  => ArchiveBuilder::BASE,
			'depth' => 'full',
		);
		return $this->builder;
	}

	private function step(): VerifyStep {
		$dir = $this->builder->dir;
		return new VerifyStep(
			static function () use ( $dir ): string {
				return $dir;
			},
			static function ( string $text ): string {
				return $text;
			},
			static function (): int {
				return self::VERIFIED_AT;
			}
		);
	}

	private function record_path(): string {
		return $this->builder->dir . '/' . VerifyRecord::file_name( ArchiveBuilder::BASE );
	}

	/**
	 * Run ticks from the given cursor until the step is done.
	 *
	 * @param array<string, mixed> $cursor  Start cursor.
	 * @param int                  $seconds Time budget per tick.
	 * @return array{0: StepResult, 1: int} Last result and the number of ticks.
	 */
	private function run_to_done( array $cursor = array(), int $seconds = 20 ): array {
		for ( $ticks = 1; $ticks <= 200; $ticks++ ) {
			$result = $this->step()->run( $this->ctx->context( $cursor, $seconds ) );
			if ( StepResult::DONE === $result->kind ) {
				return array( $result, $ticks );
			}
			$this->assertSame( StepResult::PROGRESS, $result->kind );
			$this->assertNotSame( $cursor, $result->cursor, 'every tick advances the check' );
			$cursor = $result->cursor;
		}
		$this->fail( 'the check did not finish' );
	}

	public function test_an_intact_backup_gets_a_record_about_its_manifest(): void {
		$builder           = $this->archive();
		list( $result )    = $this->run_to_done();
		$this->assertSame( 'Archive is intact.', $result->message );
		$record = VerifyRecord::from_json( (string) file_get_contents( $this->record_path() ), ArchiveBuilder::BASE );
		$this->assertNotNull( $record );
		$this->assertSame( VerificationResult::PASSED, $record['outcome'] );
		$this->assertTrue( $record['complete'] );
		$this->assertFalse( $record['restore_refused'] );
		$this->assertSame( hash_file( 'sha256', $builder->manifest_path ), $record['manifest_sha256'] );
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', self::VERIFIED_AT ), $record['verified_at'] );
		$this->assertSame( $this->ctx->id, $record['job'] );
		$this->assertStringContainsString( 'Archive is intact.', $this->ctx->log(), 'the report goes to the job log' );
		$this->assertSame( array(), glob( $this->ctx->work() . '/' . VerifyStep::RECORD_TEMP ), 'the temporary record was moved' );
	}

	public function test_the_manifest_hash_is_fixed_before_the_first_unit(): void {
		$builder = $this->archive();
		$this->step()->run( $this->ctx->context() );
		$first = $this->ctx->checkpoints[0]['cursor'];
		$this->assertSame( 'verify', $first['phase'] );
		$this->assertSame( array(), $first['verifier'], 'no unit ran before the hash was checkpointed' );
		$this->assertSame( hash_file( 'sha256', $builder->manifest_path ), $first['manifest_sha256'] );
	}

	public function test_a_damaged_volume_is_recorded_as_damaged_and_refused(): void {
		$builder = $this->archive();
		$volume  = $builder->volumes[0];
		ArchiveBuilder::flip( $volume, ArchiveBuilder::locate( $volume, 'database/wp_posts.0002.sql' )['offset'] + 1000 );
		list( $result ) = $this->run_to_done();
		$this->assertSame( 'Archive is damaged.', $result->message );
		$record = VerifyRecord::from_json( (string) file_get_contents( $this->record_path() ), ArchiveBuilder::BASE );
		$this->assertSame( VerificationResult::FAILED, $record['outcome'] );
		$this->assertTrue( $record['restore_refused'] );
		$this->assertGreaterThan( 0, $record['findings_total'] );
		$this->assertGreaterThan( 0, $record['findings_by_kind']['corrupt'] );
	}

	public function test_a_replay_of_the_record_phase_writes_the_same_bytes(): void {
		$this->archive();
		$this->run_to_done();
		$bytes  = (string) file_get_contents( $this->record_path() );
		$record = null;
		foreach ( $this->ctx->checkpoints as $checkpoint ) {
			if ( 'record' === $checkpoint['cursor']['phase'] ) {
				$record = $checkpoint['cursor'];
			}
		}
		$this->assertNotNull( $record, 'the record is checkpointed before it is written' );
		unlink( $this->record_path() );
		// A tick that died after the rename, or before it: the same bytes either way.
		foreach ( array( false, true ) as $renamed ) {
			if ( $renamed ) {
				file_put_contents( $this->record_path(), $bytes );
			}
			$result = $this->step()->run( $this->ctx->context( $record ) );
			$this->assertSame( StepResult::DONE, $result->kind );
			$this->assertSame( $bytes, (string) file_get_contents( $this->record_path() ) );
		}
	}

	public function test_a_lost_lease_stops_before_the_rename(): void {
		$this->archive();
		$this->ctx->lease = static function ( bool $force ): void {
			if ( $force ) {
				throw new LockLost( 'the lease is held by another driver (test)' );
			}
		};
		$cursor = array();
		try {
			for ( $i = 0; $i < 200; $i++ ) {
				$cursor = $this->step()->run( $this->ctx->context( $cursor ) )->cursor;
			}
			$this->fail( 'the record must not be stored without the lease' );
		} catch ( LockLost $e ) {
			$this->assertFileDoesNotExist( $this->record_path() );
		}
	}

	public function test_a_missing_manifest_fails_with_the_reason(): void {
		$builder = $this->archive();
		unlink( $builder->manifest_path );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'This backup has no manifest in the backups directory.' );
		$this->step()->run( $this->ctx->context() );
	}

	public function test_after_a_slow_unit_the_tick_ends_instead_of_starting_one_that_would_not_fit(): void {
		$this->archive();
		// Every clock reading moves 5 s, so a unit measures 5 s and, with the
		// readings around it (should_stop, should_checkpoint, the checkpoint),
		// the first unit leaves no room for a second: each tick must end at
		// the time check after its first unit, 35 s on this clock (7 readings).
		// A second unit would end the tick at the next should_stop, 40 s.
		$this->ctx->tick = 5.0;
		$cursor          = array();
		for ( $ticks = 1; $ticks <= 200; $ticks++ ) {
			$context = $this->ctx->context( $cursor );
			$start   = $this->ctx->now;
			$result  = $this->step()->run( $context );
			$this->assertLessThanOrEqual( 35.0, $this->ctx->now - $start, 'no unit starts once the time left is under 1.5 times the slowest one' );
			if ( StepResult::DONE === $result->kind ) {
				break;
			}
			$this->assertNotSame( $cursor, $result->cursor, 'every tick runs its first unit' );
			$cursor = $result->cursor;
		}
		$this->assertSame( 'Archive is intact.', $result->message );
	}

	public function test_a_unit_slower_than_the_whole_budget_fails_with_the_reason(): void {
		$this->archive();
		$this->ctx->tick = 25.0;
		try {
			$this->step()->run( $this->ctx->context() );
			$this->fail( 'a unit longer than the budget must fail the job' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringStartsWith( 'Reading this backup is too slow on this server: one step of the check took 25 seconds, more than the 20-second time budget', $e->getMessage() );
		}
	}

	public function test_invalid_options_are_refused(): void {
		$this->archive();
		$this->ctx->options = array(
			'base'  => '../' . ArchiveBuilder::BASE,
			'depth' => 'full',
		);
		$this->expectException( \InvalidArgumentException::class );
		$this->step()->run( $this->ctx->context() );
	}
}
