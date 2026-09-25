<?php

namespace WPCheckpoint\Tests\Unit\Archive;

use WPCheckpoint\Archive\Limits;
use WPCheckpoint\Jobs\PackStep;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * What the export writes stays inside what the reader takes. The code relies
 * on it: PackStep::packer_options_for() holds the deflate cap to
 * Limits::INFLATE_BYTES whatever it is given, and an entry that does not
 * shrink is stored (PackerTest), so a deflated entry the export writes is
 * never larger than the reader and the verifier take.
 */
final class LimitsTest extends TestCase {

	public function test_the_exports_deflate_cap_never_passes_the_inflate_limit(): void {
		$this->assertLessThanOrEqual( Limits::INFLATE_BYTES, PackStep::packer_options_for( array( 'deflate_max_bytes' => PHP_INT_MAX ), PHP_INT_MAX )['deflate_max_bytes'], 'whatever cap and chunk it is given' );
		$this->assertLessThanOrEqual( Limits::INFLATE_BYTES, PackStep::packer_options_for( array(), Limits::CONTENT_CHUNK_BYTES )['deflate_max_bytes'], 'the one the export runs with' );
	}
}
