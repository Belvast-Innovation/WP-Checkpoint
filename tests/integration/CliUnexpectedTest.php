<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Cli\Unexpected;
use WPCheckpoint\Plugin;
use WP_UnitTestCase;

/**
 * Every command body runs through Unexpected::guard(): an exception nobody
 * expected becomes one cleaned line, never PHP's default report with the
 * file, the line and a stack trace.
 */
final class CliUnexpectedTest extends WP_UnitTestCase {

	public function test_an_unexpected_exception_becomes_one_cleaned_line(): void {
		$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$clean = array( Plugin::instance()->job_presenter(), 'clean' );
		foreach ( array(
			new \RuntimeException( 'Could not open ' . ABSPATH . 'wp-content/uploads/x.jpg for ' . $host ),
			new \TypeError( 'call_user_func(): Argument #1 must be a valid callback, in ' . ABSPATH . 'wp-content/plugins/x.php' ),
			new \LogicException( 'Job 5 cannot move from cancelled to queued.' ),
		) as $thrown ) {
			$said = array();
			Unexpected::guard(
				static function () use ( $thrown ): void {
					throw $thrown;
				},
				$clean,
				static function ( string $line ) use ( &$said ): void {
					$said[] = $line;
				}
			);
			$this->assertCount( 1, $said );
			$short = substr( get_class( $thrown ), (int) strrpos( '\\' . get_class( $thrown ), '\\' ) );
			$this->assertStringStartsWith( 'Unexpected error (' . ltrim( $short, '\\' ) . '): ', $said[0] );
			$this->assertStringNotContainsString( ABSPATH, $said[0] );
			$this->assertStringNotContainsString( $host, $said[0] );
			$this->assertStringNotContainsString( '.php:', $said[0], 'no file and line' );
		}
	}

	public function test_a_body_that_finishes_is_left_alone(): void {
		$ran = false;
		Unexpected::guard(
			static function () use ( &$ran ): void {
				$ran = true;
			},
			'strval',
			function (): void {
				$this->fail( 'nothing to report' );
			}
		);
		$this->assertTrue( $ran );
	}
}
