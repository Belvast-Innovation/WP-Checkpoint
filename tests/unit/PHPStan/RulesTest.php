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
	 * PHPStan's findings on the fixture: message => line.
	 *
	 * @return array<int, array{message: string, line: int, identifier: string}>
	 */
	private static function findings(): array {
		$root   = dirname( __DIR__, 3 );
		$config = tempnam( sys_get_temp_dir(), 'wpcheckpoint-phpstan-' ) . '.neon';
		file_put_contents(
			$config,
			"includes:\n    - " . $root . "/vendor/szepeviktor/phpstan-wordpress/extension.neon\n"
			. "parameters:\n    level: 0\n    phpVersion: 70400\n    paths:\n        - " . $root . "/tests/Fixtures/PHPStan/forbidden-calls.php\n"
			. "    bootstrapFiles:\n        - " . $root . "/tests/phpstan-bootstrap.php\n"
			. "rules:\n    - WPCheckpoint\\Tests\\PHPStan\\HostFunctionsRule\n    - WPCheckpoint\\Tests\\PHPStan\\NoUnserializeRule\n"
		);
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $root . '/vendor/phpstan/phpstan/phpstan' )
			. ' analyse --no-progress --no-ansi --error-format=json --memory-limit=1G -c ' . escapeshellarg( $config )
			. ' --autoload-file=' . escapeshellarg( $root . '/vendor/autoload.php' );
		$output  = (string) shell_exec( $command . ' 2>/dev/null' );
		unlink( $config );
		$report = json_decode( $output, true );
		self::assertIsArray( $report, 'PHPStan ran and reported: ' . substr( $output, 0, 500 ) );
		$found = array();
		foreach ( (array) ( $report['files'] ?? array() ) as $file ) {
			foreach ( (array) ( $file['messages'] ?? array() ) as $message ) {
				$found[] = array(
					'message'    => (string) $message['message'],
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
			$by_rule[ $finding['identifier'] ][] = $finding['line'];
		}
		// Every line of the fixture that names a forbidden function is expected once, by its rule.
		$expected = array(
			'wpcheckpoint.noUnserialize' => array(),
			'wpcheckpoint.hostFunction'  => array(),
		);
		foreach ( (array) file( dirname( __DIR__, 2 ) . '/Fixtures/PHPStan/forbidden-calls.php' ) as $number => $line ) {
			if ( false !== strpos( (string) $line, 'unserialize' ) && false === strpos( (string) $line, '*' ) ) {
				$expected['wpcheckpoint.noUnserialize'][] = $number + 1;
			} elseif ( false !== strpos( (string) $line, 'exec' ) && false === strpos( (string) $line, '*' ) ) {
				$expected['wpcheckpoint.hostFunction'][] = $number + 1;
			}
		}
		$this->assertCount( 3, $expected['wpcheckpoint.noUnserialize'], 'the fixture holds the forbidden calls' );
		$this->assertCount( 2, $expected['wpcheckpoint.hostFunction'] );
		$this->assertSame( $expected['wpcheckpoint.noUnserialize'], $by_rule['wpcheckpoint.noUnserialize'] ?? array(), 'unserialize(), maybe_unserialize() and a string callback' );
		$this->assertSame( $expected['wpcheckpoint.hostFunction'], $by_rule['wpcheckpoint.hostFunction'] ?? array(), 'exec() and a string callback' );
	}
}
