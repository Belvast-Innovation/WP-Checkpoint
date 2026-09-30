<?php

namespace WPCheckpoint\Tests\Unit\Support;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The parts the Windows CI job runs in parallel (phpunit.xml.dist, unit-windows-*) are together exactly the unit suite:
 * no test file left out, none in two parts, and no part naming a file that is not there (a renamed test would
 * otherwise drop out of the Windows run without a failure).
 */
final class WindowsPartsTest extends TestCase {

	/**
	 * The test files a suite of a phpunit configuration selects: its directories (suffix Test.php) and files, less
	 * its exclusions; and the files and exclusions it names that are not there.
	 *
	 * @param string $xml  The configuration.
	 * @param string $name The suite.
	 * @param string $root The directory paths in it are relative to.
	 * @return array{files: string[], missing: string[]}
	 */
	public static function suite_files( string $xml, string $name, string $root ): array {
		$config  = new \SimpleXMLElement( $xml );
		$files   = array();
		$missing = array();
		foreach ( $config->testsuites->testsuite as $suite ) {
			if ( (string) $suite['name'] !== $name ) {
				continue;
			}
			foreach ( $suite->directory as $dir ) {
				$suffix = (string) ( $dir['suffix'] ?? 'Test.php' );
				$it     = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . (string) $dir, \FilesystemIterator::SKIP_DOTS ) );
				foreach ( $it as $file ) {
					if ( substr( $file->getFilename(), -strlen( $suffix ) ) === $suffix ) {
						$files[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
					}
				}
			}
			foreach ( $suite->file as $file ) {
				$files[] = (string) $file;
				if ( ! is_file( $root . '/' . (string) $file ) ) {
					$missing[] = (string) $file;
				}
			}
			foreach ( $suite->exclude as $exclude ) {
				if ( ! file_exists( $root . '/' . (string) $exclude ) ) {
					$missing[] = (string) $exclude;
				}
				$files = array_values( array_diff( $files, array( (string) $exclude ) ) );
			}
		}
		sort( $files );
		return array(
			'files'   => array_values( array_unique( $files ) ),
			'missing' => $missing,
		);
	}

	/**
	 * The names of the part suites in a configuration.
	 *
	 * @return string[]
	 */
	private static function parts( string $xml ): array {
		$names = array();
		foreach ( ( new \SimpleXMLElement( $xml ) )->testsuites->testsuite as $suite ) {
			if ( 0 === strpos( (string) $suite['name'], 'unit-windows-' ) ) {
				$names[] = (string) $suite['name'];
			}
		}
		return $names;
	}

	public function test_the_parts_are_exactly_the_unit_suite(): void {
		$root  = dirname( __DIR__, 3 );
		$xml   = (string) file_get_contents( $root . '/phpunit.xml.dist' );
		$unit  = self::suite_files( $xml, 'unit', $root );
		$parts = self::parts( $xml );
		$this->assertGreaterThanOrEqual( 2, count( $parts ), 'the parts are there' );
		$this->assertContains( 'tests/unit/Support/WindowsPartsTest.php', $unit['files'], 'the control: the unit suite is read' );

		$all = array();
		foreach ( $parts as $name ) {
			$part = self::suite_files( $xml, $name, $root );
			$this->assertSame( array(), $part['missing'], "{$name} names files that are not there" );
			$this->assertNotSame( array(), $part['files'], "{$name} is not empty" );
			$this->assertSame( array(), array_values( array_intersect( $all, $part['files'] ) ), "{$name} repeats files of another part" );
			$all = array_merge( $all, $part['files'] );
		}
		sort( $all );
		$this->assertSame( $unit['files'], $all, 'the parts together are the unit suite' );
	}

	public function test_a_part_naming_a_file_that_is_not_there_is_found(): void {
		$root = dirname( __DIR__, 3 );
		$xml  = '<phpunit><testsuites><testsuite name="unit-windows-1"><file>tests/unit/Support/NoSuchTest.php</file>'
			. '<file>tests/unit/Support/WindowsPartsTest.php</file></testsuite></testsuites></phpunit>';
		$part = self::suite_files( $xml, 'unit-windows-1', $root );
		$this->assertSame( array( 'tests/unit/Support/NoSuchTest.php' ), $part['missing'] );
		$this->assertContains( 'tests/unit/Support/WindowsPartsTest.php', $part['files'], 'the control: an existing file is selected' );
	}
}
