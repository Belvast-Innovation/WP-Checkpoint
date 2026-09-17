<?php

namespace WPCheckpoint\Tests\Unit;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class SmokeTest extends TestCase {

	public function test_php_version_is_supported(): void {
		$this->assertTrue( version_compare( PHP_VERSION, '7.4', '>=' ) );
	}
}
