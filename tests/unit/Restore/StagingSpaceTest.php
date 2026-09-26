<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\StagingSpace;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The staged bytes plus 5 %, and at least 100 MiB, per file system.
 */
final class StagingSpaceTest extends TestCase {

	public function test_the_margin_is_five_percent_and_at_least_100_mib(): void {
		$this->assertSame( 104857600.0, StagingSpace::required( 0 ) );
		$this->assertSame( 1.0 + 104857600, StagingSpace::required( 1 ) );
		// 5 % reaches 100 MiB at 2000 MiB.
		$this->assertSame( 2097152000.0 + 104857600, StagingSpace::required( 2097152000 ) );
		$this->assertSame( 4194304000.0 + 209715200, StagingSpace::required( 4194304000 ) );
		$this->assertSame( 4194304001.0 + 209715201, StagingSpace::required( 4194304001 ), 'rounded up' );
	}

	public function test_each_file_system_is_judged_on_its_own(): void {
		$check = StagingSpace::check(
			array(
				'1' => 10,
				'2' => 10,
				'3' => 10,
			),
			array(
				'1' => StagingSpace::required( 10 ),
				'2' => StagingSpace::required( 10 ) - 1,
				'3' => null,
			)
		);
		$this->assertSame(
			array(
				'2' => array(
					'need' => StagingSpace::required( 10 ),
					'free' => StagingSpace::required( 10 ) - 1,
				),
			),
			$check['short'],
			'exactly enough is enough; one byte less is not'
		);
		$this->assertSame( array( '3' => StagingSpace::required( 10 ) ), $check['unknown'] );
	}

	public function test_sums_past_the_integer_range_of_32_bit_php_are_exact(): void {
		// 3 GiB: past PHP_INT_MAX on 32-bit PHP, where an integer sum would turn into a float mid-way.
		$this->assertSame( 3221225472.0 + 161061274.0, StagingSpace::required( 3221225472.0 ) );
		$this->assertSame( 1e18 + 5e16, StagingSpace::required( 1e18 ) );
		// Five percent of it past PHP_INT_MAX even on 64-bit: what 32-bit PHP meets at 430 MB.
		$this->assertSame( 2e18 + 1e17, StagingSpace::required( 2e18 ) );
		$check = StagingSpace::check( array( 'a' => 3221225472.0 ), array( 'a' => 3221225472.0 ) );
		$this->assertSame( 3221225472.0 + 161061274.0, $check['short']['a']['need'] );
	}
}
