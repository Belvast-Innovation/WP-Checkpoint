<?php

namespace WPCheckpoint\Tests\Unit\Support;

use WPCheckpoint\Tests\Fixtures\MemoryBudget;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The bound the memory tests rely on stops a call that goes beyond it (in a
 * process of its own: the stop is a fatal error) and lets one within it run.
 */
final class MemoryBudgetTest extends TestCase {

	private function run_in_a_process( int $bound, int $allocate ): string {
		// A script file, not "php -r": Windows' command line would take the quotes out of the code.
		$script = tempnam( sys_get_temp_dir(), 'wpc-memory-' );
		file_put_contents(
			$script,
			sprintf(
				'<?php require %s; echo WPCheckpoint\Tests\Fixtures\MemoryBudget::within( %d, static function () { $s = str_repeat( "x", %d ); return strlen( $s ); } ), "\n";',
				var_export( dirname( __DIR__, 3 ) . '/vendor/autoload.php', true ),
				$bound,
				$allocate
			)
		);
		try {
			return (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -d memory_limit=-1 -d display_errors=1 ' . escapeshellarg( $script ) . ' 2>&1' );
		} finally {
			unlink( $script );
		}
	}

	public function test_a_call_beyond_the_bound_is_stopped_and_one_within_it_runs(): void {
		$this->assertStringContainsString( (string) ( 4 * 1048576 ), $this->run_in_a_process( 16 * 1048576, 4 * 1048576 ), 'within: it runs' );
		$this->assertStringContainsString( 'Allowed memory size', $this->run_in_a_process( 8 * 1048576, 16 * 1048576 ), 'beyond: stopped' );
	}

	public function test_a_stricter_limit_around_the_test_stays(): void {
		$limit = ini_get( 'memory_limit' );
		$outer = (string) ( memory_get_usage( true ) + 64 * 1048576 );
		ini_set( 'memory_limit', $outer );
		try {
			$inside = MemoryBudget::within( 512 * 1048576, static function () {
				return ini_get( 'memory_limit' );
			} );
			$this->assertSame( $outer, $inside, 'not raised to the larger bound' );
			$this->assertSame( $outer, ini_get( 'memory_limit' ), 'and put back' );
		} finally {
			ini_set( 'memory_limit', $limit );
		}
	}

	public function test_the_limit_is_put_back(): void {
		$limit = ini_get( 'memory_limit' );
		$this->assertSame( 3, MemoryBudget::within( 32 * 1048576, static function (): int {
			return 3;
		} ) );
		$this->assertSame( $limit, ini_get( 'memory_limit' ) );
	}
}
