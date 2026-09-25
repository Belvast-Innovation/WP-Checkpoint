<?php

namespace WPCheckpoint\Tests\Unit\Archive;

use WPCheckpoint\Archive\Limits;
use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Jobs\PackStep;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * What the export writes stays inside what the reader takes. The code relies
 * on it: a deflated entry is at most the export's cap (larger ones, and ones
 * that do not shrink, are stored), and the reader and the verifier refuse a
 * deflated entry larger than Limits::INFLATE_BYTES.
 */
final class LimitsTest extends TestCase {

	public function test_the_exports_deflate_cap_is_below_the_inflate_limit(): void {
		$this->assertLessThan( Limits::INFLATE_BYTES, PackStep::packer_options_for( array(), Manifest::DEFAULT_CHUNK )['deflate_max_bytes'], 'the cap the export runs with' );
	}
}
