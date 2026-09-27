<?php

namespace WPCheckpoint\Tests\Unit\PHPStan;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The plugin's own PHPStan rules find what they forbid. `composer analyse`
 * reporting no error only means something if the rules report errors where
 * there are some: this runs PHPStan with them on a fixture of forbidden
 * calls and counts the findings per rule.
 *
 * @group phpstan
 */
final class RulesTest extends TestCase {

	/**
	 * PHPStan's findings on the fixtures: message, file, line.
	 *
	 * @return array<int, array{message: string, file: string, line: int, identifier: string}>
	 */
	private static function findings(): array {
		$root   = dirname( __DIR__, 3 );
		$base   = (string) tempnam( sys_get_temp_dir(), 'wpcheckpoint-phpstan-' );
		$config = $base . '.neon';
		file_put_contents(
			$config,
			"includes:\n    - " . $root . "/vendor/szepeviktor/phpstan-wordpress/extension.neon\n"
			. "parameters:\n    level: 0\n    phpVersion: 70400\n    paths:\n        - " . $root . "/tests/Fixtures/PHPStan/forbidden-calls.php\n        - " . $root . "/tests/Fixtures/PHPStan/stored-names.php\n"
			. "    bootstrapFiles:\n        - " . $root . "/tests/phpstan-bootstrap.php\n"
			. "rules:\n    - WPCheckpoint\\Tests\\PHPStan\\HostFunctionsRule\n    - WPCheckpoint\\Tests\\PHPStan\\NoUnserializeRule\n    - WPCheckpoint\\Tests\\PHPStan\\StoredNamesRule\n"
		);
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $root . '/vendor/phpstan/phpstan/phpstan' )
			. ' analyse --no-progress --no-ansi --error-format=json --memory-limit=1G -c ' . escapeshellarg( $config )
			. ' --autoload-file=' . escapeshellarg( $root . '/vendor/autoload.php' );
		$output  = (string) shell_exec( $command . ' 2>/dev/null' );
		unlink( $config );
		unlink( $base );
		$report = json_decode( $output, true );
		self::assertIsArray( $report, 'PHPStan ran and reported: ' . substr( $output, 0, 500 ) );
		$found = array();
		foreach ( (array) ( $report['files'] ?? array() ) as $path => $file ) {
			foreach ( (array) ( $file['messages'] ?? array() ) as $message ) {
				$found[] = array(
					'message'    => (string) $message['message'],
					'file'       => basename( (string) $path ),
					'line'       => (int) $message['line'],
					'identifier' => (string) ( $message['identifier'] ?? '' ),
				);
			}
		}
		return $found;
	}

	public function test_each_rule_reports_every_forbidden_call_in_the_fixture(): void {
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$this->markTestSkipped( 'Runs PHPStan through a POSIX shell.' );
		}
		$by_rule = array();
		foreach ( self::findings() as $finding ) {
			if ( 'forbidden-calls.php' === $finding['file'] ) {
				$by_rule[ $finding['identifier'] ][] = $finding['line'];
			}
		}
		// Every line of the fixture that names a forbidden function is expected once, by its rule.
		$expected = array(
			'wpcheckpoint.noUnserialize' => array(),
			'wpcheckpoint.hostFunction'  => array(),
		);
		foreach ( (array) file( dirname( __DIR__, 2 ) . '/Fixtures/PHPStan/forbidden-calls.php' ) as $number => $line ) {
			if ( ( false !== strpos( (string) $line, 'unserialize' ) || false !== strpos( (string) $line, 'session_decode' ) ) && false === strpos( (string) $line, '*' ) ) {
				$expected['wpcheckpoint.noUnserialize'][] = $number + 1;
			} elseif ( false !== strpos( (string) $line, 'exec' ) && false === strpos( (string) $line, '*' ) ) {
				$expected['wpcheckpoint.hostFunction'][] = $number + 1;
			}
		}
		$this->assertCount( 6, $expected['wpcheckpoint.noUnserialize'], 'the fixture holds the forbidden calls' );
		$this->assertCount( 2, $expected['wpcheckpoint.hostFunction'] );
		$this->assertSame( $expected['wpcheckpoint.noUnserialize'], $by_rule['wpcheckpoint.noUnserialize'] ?? array(), 'unserialize(), maybe_unserialize(), session_decode(), ->unserialize() and the names as strings' );
		$this->assertSame( $expected['wpcheckpoint.hostFunction'], $by_rule['wpcheckpoint.hostFunction'] ?? array(), 'exec() and a string callback' );
	}

	public function test_the_stored_names_rule_reports_every_name_that_does_not_come_from_the_registry(): void {
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$this->markTestSkipped( 'Runs PHPStan through a POSIX shell.' );
		}
		$found = array();
		foreach ( self::findings() as $finding ) {
			if ( 'stored-names.php' === $finding['file'] ) {
				$found[] = array( $finding['identifier'], $finding['line'] );
			}
		}
		$expected = array();
		$calls    = 0;
		foreach ( (array) file( dirname( __DIR__, 2 ) . '/Fixtures/PHPStan/stored-names.php' ) as $number => $line ) {
			if ( 1 === preg_match( '/\b(get|update|add|delete|set)_\w+\(|Options::/', (string) $line ) && false === strpos( (string) $line, '*' ) ) {
				++$calls;
			}
			if ( false !== strpos( (string) $line, '// reported' ) ) {
				$expected[] = array( 'wpcheckpoint.storedName', $number + 1 );
			}
		}
		$this->assertCount( 9, $expected, 'the fixture holds the calls to report' );
		$this->assertGreaterThan( count( $expected ) + 5, $calls, 'and calls to let pass' );
		$this->assertSame( $expected, $found, 'every name outside the registry, and nothing else' );
	}
}
