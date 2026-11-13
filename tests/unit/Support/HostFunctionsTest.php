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

	public function test_the_user_of_the_process_and_the_owner_of_a_directory_are_told_where_posix_tells_them(): void {
		$dir   = \WPCheckpoint\Tests\Fixtures\Sandbox::make( 'users' );
		$users = HostFunctions::users_of( $dir );
		\WPCheckpoint\Tests\Fixtures\Sandbox::remove( $dir );
		if ( ! function_exists( 'posix_geteuid' ) ) {
			$this->assertSame( array( null, null, '', '' ), $users, 'without POSIX nothing is known (never "user 0 owns it")' );
			return;
		}
		$this->assertSame( posix_geteuid(), $users[0] );
		$this->assertSame( $users[0], $users[1], 'a directory this process made is its own' );
		$this->assertSame( $users[2], $users[3] );
		$this->assertSame( (string) ( posix_getpwuid( posix_geteuid() )['name'] ?? '' ), $users[2] );
		$this->assertSame( array( $users[0], null, $users[2], '' ), HostFunctions::users_of( $dir ), 'a directory that is not there: its owner is not known' );
	}
}
