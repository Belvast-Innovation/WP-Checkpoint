<?php

namespace WPCheckpoint\Tests\Unit\Backups;

use WPCheckpoint\Archive\ArchiveVerifier;
use WPCheckpoint\Archive\VerificationResult;
use WPCheckpoint\Backups\VerifyRecord;
use WPCheckpoint\Jobs\VerifyJob;
use WPCheckpoint\Tests\Fixtures\Archive\ArchiveBuilder;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The record of the latest check: written from a finished result, read
 * back with a fail-closed parser (anything unexpected is "not verified").
 */
final class VerifyRecordTest extends TestCase {

	/** @var ArchiveBuilder|null */
	private static $builder;

	/** @var VerificationResult|null */
	private static $result;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		self::$builder = ( new ArchiveBuilder() )->typical()->build();
		self::$result  = ArchiveVerifier::open( self::$builder->manifest_path, self::$builder->work_dir(), ArchiveVerifier::DEPTH_FULL )->run();
	}

	public static function tear_down_after_class(): void {
		self::$builder->cleanup();
		self::$builder = null;
		self::$result  = null;
		parent::tear_down_after_class();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function record(): array {
		return VerifyRecord::from_result( ArchiveBuilder::BASE, str_repeat( 'ab', 32 ), 1758600000, ArchiveVerifier::DEPTH_FULL, self::$result, 12 );
	}

	public function test_a_record_round_trips(): void {
		$record = $this->record();
		$this->assertSame( VerificationResult::PASSED, $record['outcome'] );
		$this->assertNull( $record['cause'] );
		$this->assertSame( array( 'changed', 'corrupt', 'environment', 'malformed', 'missing', 'unsupported', 'unverified' ), array_keys( $record['findings_by_kind'] ) );
		$this->assertSame( $record, VerifyRecord::from_json( VerifyRecord::to_json( $record ), ArchiveBuilder::BASE ) );
	}

	public function test_a_record_for_another_backup_is_not_this_backups_record(): void {
		$this->assertNull( VerifyRecord::from_json( VerifyRecord::to_json( $this->record() ), 'other-20260918-100000-a1b2' ) );
	}

	/**
	 * @return array<string, array{0: callable}>
	 */
	public function damaged(): array {
		return array(
			'wrong format'        => array( static function ( array $r ): array { $r['format'] = 'x'; return $r; } ),
			'newer version'       => array( static function ( array $r ): array { $r['version'] = 2; return $r; } ),
			'version as string'   => array( static function ( array $r ): array { $r['version'] = '1'; return $r; } ),
			'short hash'          => array( static function ( array $r ): array { $r['manifest_sha256'] = 'abc'; return $r; } ),
			'upper-case hash'     => array( static function ( array $r ): array { $r['manifest_sha256'] = strtoupper( $r['manifest_sha256'] ); return $r; } ),
			'local time'          => array( static function ( array $r ): array { $r['verified_at'] = '2025-09-23 04:00:00'; return $r; } ),
			'unknown depth'       => array( static function ( array $r ): array { $r['depth'] = 'quick'; return $r; } ),
			'unknown outcome'     => array( static function ( array $r ): array { $r['outcome'] = 'intact'; return $r; } ),
			'complete as string'  => array( static function ( array $r ): array { $r['complete'] = 'true'; return $r; } ),
			'refused missing'     => array( static function ( array $r ): array { unset( $r['restore_refused'] ); return $r; } ),
			'cause with text'     => array( static function ( array $r ): array { $r['cause'] = 'disk is full'; return $r; } ),
			'negative total'      => array( static function ( array $r ): array { $r['findings_total'] = -1; return $r; } ),
			'job zero'            => array( static function ( array $r ): array { $r['job'] = 0; return $r; } ),
			'kind missing'        => array( static function ( array $r ): array { unset( $r['findings_by_kind']['corrupt'] ); return $r; } ),
			'unknown kind'        => array( static function ( array $r ): array { $r['findings_by_kind']['other'] = 1; return $r; } ),
			'kind count as float' => array( static function ( array $r ): array { $r['findings_by_kind']['corrupt'] = 1.5; return $r; } ),
		);
	}

	/**
	 * @dataProvider damaged
	 */
	public function test_anything_unexpected_reads_as_no_record( callable $damage ): void {
		$json = (string) json_encode( $damage( $this->record() ) );
		$this->assertNull( VerifyRecord::from_json( $json, ArchiveBuilder::BASE ) );
	}

	public function test_malformed_nested_or_oversized_json_reads_as_no_record(): void {
		$this->assertNull( VerifyRecord::from_json( '{"format":', ArchiveBuilder::BASE ) );
		$this->assertNull( VerifyRecord::from_json( '[1]', ArchiveBuilder::BASE ) );
		$deep                   = $this->record();
		$deep['findings_by_kind'] = array( 'corrupt' => array( array( 1 ) ) );
		$this->assertNull( VerifyRecord::from_json( (string) json_encode( $deep ), ArchiveBuilder::BASE ), 'nesting beyond the record is refused' );
		$this->assertNull( VerifyRecord::from_json( VerifyRecord::to_json( $this->record() ) . str_repeat( ' ', VerifyRecord::MAX_BYTES ), ArchiveBuilder::BASE ) );
	}

	public function test_a_record_that_does_not_fit_is_never_written_partially(): void {
		$record         = $this->record();
		$record['base'] = str_repeat( 'a', VerifyRecord::MAX_BYTES );
		$this->expectException( \RuntimeException::class );
		VerifyRecord::to_json( $record );
	}

	public function test_invalid_utf8_is_never_written_partially(): void {
		$record         = $this->record();
		$record['base'] = "\xff";
		$this->expectException( \RuntimeException::class );
		VerifyRecord::to_json( $record );
	}

	public function test_verify_options_accept_only_a_backup_name_and_a_known_depth(): void {
		$this->assertSame( array( 'base' => ArchiveBuilder::BASE, 'depth' => 'full' ), VerifyJob::options( array( 'base' => ArchiveBuilder::BASE ) ) );
		$this->assertSame( 'structure', VerifyJob::options( array( 'base' => ArchiveBuilder::BASE, 'depth' => 'structure' ) )['depth'] );
		foreach ( array( array(), array( 'base' => '../' . ArchiveBuilder::BASE ), array( 'base' => ArchiveBuilder::BASE . '.manifest.json' ), array( 'base' => array( ArchiveBuilder::BASE ) ), array( 'base' => ArchiveBuilder::BASE, 'depth' => 'quick' ), array( 'base' => ArchiveBuilder::BASE, 'depth' => true ) ) as $options ) {
			try {
				VerifyJob::options( $options );
				$this->fail( 'refused: ' . json_encode( $options ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}
}
