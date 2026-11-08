<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\WebClasses;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The classes the runtime holds (WebClasses::ALL, which WebRuntime writes into it) are the ones the scan of a run
 * without WordPress loads (WebPathScanner's "loaded": every class its code names, and their parents, interfaces and
 * traits), item for item: the list is written by bin/web-classes.php from the scan, never by hand, and this test fails
 * while the file is not what the tool writes.
 */
final class WebClassesTest extends TestCase {

	public function test_the_runtimes_classes_are_the_ones_the_scan_loads(): void {
		$loaded = WebPathScanTest::scanner()->run()['loaded'];
		$this->assertGreaterThan( 90, count( $loaded ), 'the control: the scan loads the run\'s classes' );
		$this->assertContains( 'WPCheckpoint\Jobs\WebEntry', $loaded );
		$this->assertContains( 'WPCheckpoint\Jobs\RunnerRepository', $loaded, 'an interface a loaded class implements' );
		$this->assertSame( $loaded, WebClasses::ALL, 'run php bin/web-classes.php' );
		$this->assertSame( self::source( $loaded ), str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Jobs/WebClasses.php' ) ), 'the file is the tool\'s, byte for byte (but the line endings a Windows checkout gives it)' );
	}

	/**
	 * The file bin/web-classes.php writes for a list.
	 *
	 * @param string[] $classes Classes, sorted.
	 * @return string
	 */
	public static function source( array $classes ): string {
		$lines = '';
		foreach ( $classes as $class ) {
			$lines .= "\t\t'" . str_replace( '\\', '\\\\', $class ) . "',\n";
		}
		return "<?php\n/**\n * The classes of a run without WordPress.\n *\n * @package WPCheckpoint\n */\n\nnamespace WPCheckpoint\\Jobs;\n\ndefined( 'ABSPATH' ) || exit;\n\n"
			. "/**\n * The classes the runtime holds (WebRuntime): those a run without WordPress loads, as the scan of its code finds them\n"
			. " * (tests/Fixtures/WebPathScanner.php). Written by bin/web-classes.php, not by hand; WebClassesTest checks it.\n */\n"
			. "final class WebClasses {\n\n\t/**\n\t * The classes, sorted.\n\t */\n\tconst ALL = array(\n" . $lines . "\t);\n}\n";
	}
}
