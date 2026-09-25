<?php

namespace WPCheckpoint\Tests\Unit\Archive;

use WPCheckpoint\Archive\ArchiveVerifier;
use WPCheckpoint\Archive\Packer;
use WPCheckpoint\Archive\VerificationResult;
use WPCheckpoint\Tests\Fixtures\MemoryBudget;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The structure depth's entry walk at the writer's own limit: a volume of
 * about Packer::MAX_VOLUME_ENTRIES entries is walked one ENTRIES_PER_UNIT
 * batch per unit, every local header read, with a memory increase far
 * inside the 32 MB a unit may use.
 */
final class LayoutWalkLimitTest extends TestCase {

	/** @var string */
	private $root = '';

	protected function tear_down(): void {
		if ( '' !== $this->root && is_dir( $this->root ) ) {
			self::rm( $this->root );
		}
	}

	public function test_a_volume_at_the_entry_limit_is_walked_in_bounded_units(): void {
		$files = Packer::MAX_VOLUME_ENTRIES - 10; // Room for the table chunk and the summaries.
		// Built in a process of its own: the fixture holds every entry in memory, the verifier must not.
		$out = shell_exec( escapeshellarg( PHP_BINARY ) . ' -d memory_limit=-1 ' . escapeshellarg( dirname( __DIR__, 2 ) . '/Fixtures/Archive/build-many-entries.php' ) . ' ' . $files );
		$built = json_decode( (string) $out, true );
		$this->assertIsArray( $built, (string) $out );
		$this->root = dirname( (string) $built['work'], 2 );
		$verifier   = ArchiveVerifier::open( (string) $built['manifest'], (string) $built['work'], ArchiveVerifier::DEPTH_STRUCTURE );
		$units      = 0;
		MemoryBudget::within(
			32 * 1048576, // A unit stays inside the step memory increase, the whole walk too.
			function () use ( $verifier, &$units ): void {
				while ( $verifier->step() ) {
					++$units;
					$this->assertLessThan( 5000, $units );
				}
			}
		);
		$result = $verifier->result();
		$this->assertSame( VerificationResult::PASSED_PARTIAL, $result->outcome(), $result->to_text( static function ( string $t ): string {
			return $t;
		} ) );
		$this->assertSame( $files + 1, $result->counts()['headers_checked'], 'every entry\'s local header was read' );
		$this->assertGreaterThanOrEqual( (int) ceil( ( $files + 1 ) / ArchiveVerifier::ENTRIES_PER_UNIT ), $units, 'at most ENTRIES_PER_UNIT entries per unit' );
	}

	/**
	 * Recursive delete.
	 */
	private static function rm( string $dir ): void {
		foreach ( scandir( $dir ) ?: array() as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::rm( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}
}
