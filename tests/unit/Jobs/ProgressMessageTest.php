<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Archive\ArchiveVerifier;
use WPCheckpoint\Jobs\DatabaseExportStep;
use WPCheckpoint\Jobs\FileScanStep;
use WPCheckpoint\Jobs\ManifestStep;
use WPCheckpoint\Jobs\PackStep;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Counts in the progress line read like the counts elsewhere on the
 * screen: with thousands separators ("11,763 files").
 */
final class ProgressMessageTest extends TestCase {

	/**
	 * A step's private message method, called on an instance built without its constructor.
	 *
	 * @param string       $class  Step class.
	 * @param string       $method Method.
	 * @param array<mixed> $args   Arguments.
	 * @return string
	 */
	private static function message( string $class, string $method, array $args ): string {
		$reflection = new \ReflectionMethod( $class, $method );
		$reflection->setAccessible( true );
		$object = $reflection->isStatic() ? null : ( new \ReflectionClass( $class ) )->newInstanceWithoutConstructor();
		return (string) $reflection->invokeArgs( $object, $args );
	}

	public function test_counts_have_thousands_separators(): void {
		$this->assertSame( 'Packed 1,200 database chunks and 34,567 files', self::message( PackStep::class, 'message', array( array( 'entries' => 1200, 'files' => 34567 ) ) ) );
		$this->assertSame( 'Listed 11,763 files in 1,024 directories', self::message( FileScanStep::class, 'message', array( array( 'counts' => array( 'files' => 11763, 'directories' => 1024 ) ) ) ) );
		$this->assertSame( 'Exported 1,000 of 1,500 tables', self::message( DatabaseExportStep::class, 'message', array( array( 'index' => 1000 ), 1500 ) ) );
		$this->assertSame( 'Checking the written archive: 12,000 of 34,000 entries', self::message( ManifestStep::class, 'check_message', array( array( 'phase' => ArchiveVerifier::PHASE_CONTENTS, 'done' => 12000, 'total' => 34000, 'seconds_left' => null, 'slow' => false ) ) ) );
		$this->assertSame( 'Packed 12 database chunks and 7 files', self::message( PackStep::class, 'message', array( array( 'entries' => 12, 'files' => 7 ) ) ), 'small counts unchanged' );
	}
}
