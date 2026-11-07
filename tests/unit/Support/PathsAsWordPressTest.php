<?php

namespace WPCheckpoint\Tests\Unit\Support;

use WPCheckpoint\Support\Paths;
use WPCheckpoint\Tests\Fixtures\Support\NormalizeInputs;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Without WordPress, Paths normalises as wp_normalize_path() does (Paths::as_wordpress()): the bytes core gave for
 * each input when written (NormalizeInputs). That core gives them still is PathsNormalizeTest (integration).
 */
final class PathsAsWordPressTest extends TestCase {

	public function test_each_input_gives_what_core_gave(): void {
		$this->assertContains( 'phar', stream_get_wrappers(), 'the control: a wrapper PHP has registered' );
		foreach ( NormalizeInputs::CASES as $input => $core ) {
			$this->assertSame( $core, Paths::as_wordpress( (string) $input ), json_encode( (string) $input ) );
		}
	}

	public function test_without_wordpress_normalize_is_as_wordpress(): void {
		$this->assertFalse( function_exists( 'wp_normalize_path' ), 'the control: core is not loaded here' );
		foreach ( array_keys( NormalizeInputs::CASES ) as $input ) {
			$this->assertSame( Paths::as_wordpress( (string) $input ), Paths::normalize( (string) $input ) );
		}
	}
}
