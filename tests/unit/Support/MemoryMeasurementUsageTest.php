<?php

namespace WPCheckpoint\Tests\Unit\Support;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Memory bounds in tests go through Tests\Fixtures\MemoryBudget. A bound
 * asserted on memory_get_peak_usage() depends on the PHP version (the peak
 * cannot be reset before 8.2) and on what ran before in the process: an
 * earlier test's peak makes it pass whatever the code uses, or fail code
 * that used nothing.
 */
final class MemoryMeasurementUsageTest extends TestCase {

	/**
	 * Allowed for good: the acceptance probe records each web request's own peak at its end (a request is a
	 * process of its own) and asserts nothing; this test names the function in its fixtures.
	 */
	const EXEMPT = array(
		'tests/acceptance/mu-plugin/wpcheckpoint-acceptance-probe.php',
		'tests/unit/Support/MemoryMeasurementUsageTest.php',
	);

	/**
	 * Still to be moved to MemoryBudget (a separate change). An entry that no longer uses the function fails
	 * the test, so the list only shrinks.
	 */
	const PENDING = array(
		'tests/integration/DatabaseExportStepTest.php',
		'tests/unit/Archive/IndexLineTest.php',
		'tests/unit/Archive/PackerTest.php',
		'tests/unit/Database/TableExporterTest.php',
		'tests/unit/Files/FileScannerTest.php',
		'tests/unit/Replace/EngineTest.php',
	);

	/**
	 * Every PHP file under tests/.
	 *
	 * @return array<string, string> Path relative to the plugin root => absolute path.
	 */
	private function test_files(): array {
		$root  = dirname( __DIR__, 3 );
		$files = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/tests', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[ str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) ) ] = $file->getPathname();
			}
		}
		ksort( $files );
		return $files;
	}

	/**
	 * Whether PHP code calls memory_get_peak_usage() or names it as a callback (comments do not count).
	 */
	private static function uses_peak( string $code ): bool {
		$kinds = array( T_STRING, T_CONSTANT_ENCAPSED_STRING );
		if ( defined( 'T_NAME_FULLY_QUALIFIED' ) ) {
			$kinds[] = T_NAME_FULLY_QUALIFIED; // "\\memory_get_peak_usage" is one token from PHP 8 on.
		}
		foreach ( token_get_all( $code ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], $kinds, true ) && 'memory_get_peak_usage' === strtolower( trim( $token[1], '\'"\\' ) ) ) {
				return true;
			}
		}
		return false;
	}

	public function test_no_test_measures_memory_with_the_peak(): void {
		foreach ( $this->test_files() as $relative => $path ) {
			if ( in_array( $relative, self::EXEMPT, true ) || in_array( $relative, self::PENDING, true ) ) {
				continue;
			}
			$this->assertFalse( self::uses_peak( (string) file_get_contents( $path ) ), "{$relative}: bound memory with Tests\\Fixtures\\MemoryBudget::within(), not memory_get_peak_usage()" );
		}
	}

	public function test_the_scan_finds_what_it_looks_for(): void {
		$this->assertTrue( self::uses_peak( '<?php $a = memory_get_peak_usage() - $b;' ) );
		$this->assertTrue( self::uses_peak( '<?php $a = \\memory_get_peak_usage( true );' ) );
		$this->assertTrue( self::uses_peak( "<?php \$f = 'memory_get_peak_usage';" ) );
		$this->assertFalse( self::uses_peak( '<?php // memory_get_peak_usage() in a comment' ) );
		$this->assertFalse( self::uses_peak( '<?php $a = memory_get_usage();' ) );
		$files = $this->test_files();
		foreach ( array_merge( self::EXEMPT, self::PENDING ) as $relative ) {
			$this->assertArrayHasKey( $relative, $files, 'listed and there' );
			if ( 'tests/unit/Support/MemoryMeasurementUsageTest.php' !== $relative ) {
				$this->assertTrue( self::uses_peak( (string) file_get_contents( $files[ $relative ] ) ), "{$relative} no longer uses it: take it off the list" );
			}
		}
	}
}
