<?php

namespace WPCheckpoint\Tests\Unit\Support;

use WPCheckpoint\Support\HostFunctions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class HostFunctionsTest extends TestCase {

	/**
	 * Run the fixture in a PHP process of its own with the functions disabled.
	 *
	 * @return array{0: int, 1: string}
	 */
	private function run_disabled( array $disabled ): array {
		$proc = proc_open(
			array( PHP_BINARY, '-d', 'disable_functions=' . implode( ',', $disabled ), dirname( __DIR__, 2 ) . '/Fixtures/Host/disabled-functions.php' ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			null,
			array(
				'WPCHECKPOINT_TEST_SUITE' => 'unit',
				'PATH'                    => (string) getenv( 'PATH' ),
			)
		);
		$this->assertIsResource( $proc );
		$out  = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		$code = proc_close( $proc );
		return array( $code, (string) $out );
	}

	public function test_the_packer_and_the_pack_step_work_when_the_host_disables_the_functions(): void {
		// putenv stays: the test harness's autoloader (wp-phpunit) calls it; the plugin does not.
		list( $code, $out ) = $this->run_disabled( array_merge( array_diff( HostFunctions::GOVERNED, array( 'putenv' ) ), array( 'posix_getpwuid', 'posix_geteuid' ) ) );
		$this->assertSame( 0, $code, $out );
		$result = json_decode( $out, true );
		$this->assertIsArray( $result, $out );
		self::remove( (string) $result['root'] );
		unset( $result['root'] );
		$this->assertSame(
			array(
				'disk_free_space'   => false,
				'disk_total_space'  => false,
				'set_time_limit'    => false,
				'ignore_user_abort' => false,
				'ini_set'           => false,
				'readlink'          => false,
				'apache_setenv'     => false,
				'process_user_home' => '',
				'stream_isatty'     => false,
				'can_deflate'       => false,
				'gzdeflate'         => false,
				'gzinflate'         => false,
				'packer_entries'    => 1,
				'pack_step'         => 'done',
			),
			$result,
			'every wrapper answers "unknown"; free space unknown does not block; without zlib entries are stored'
		);
	}

	public function test_available_reads_disable_functions_case_insensitively(): void {
		$this->assertTrue( HostFunctions::available( 'strlen' ) );
		$this->assertFalse( HostFunctions::available( 'no_such_function_here' ) );
		$this->assertTrue( HostFunctions::governs( '\\Disk_Free_Space' ) );
		$this->assertTrue( HostFunctions::governs( 'proc_open' ) );
		$this->assertFalse( HostFunctions::governs( 'fopen' ) );
	}

	private static function remove( string $path ): void {
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			foreach ( scandir( $path ) ?: array() as $name ) {
				if ( '.' !== $name && '..' !== $name ) {
					self::remove( $path . DIRECTORY_SEPARATOR . $name );
				}
			}
			rmdir( $path );
			return;
		}
		unlink( $path );
	}
}
