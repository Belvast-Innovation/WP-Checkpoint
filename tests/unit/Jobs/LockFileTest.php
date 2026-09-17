<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\LockFile;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class LockFileTest extends TestCase {

	/** @var string */
	private $base;

	protected function set_up(): void {
		$this->base = sys_get_temp_dir() . '/wpcheckpoint-lockfile-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->base . '/tmp', 0700, true );
	}

	protected function tear_down(): void {
		foreach ( glob( $this->base . '/tmp/*' ) ?: array() as $f ) {
			unlink( $f );
		}
		rmdir( $this->base . '/tmp' );
		rmdir( $this->base );
	}

	public function test_write_read_and_ownership_use_a_hash_not_the_token(): void {
		$token = bin2hex( random_bytes( 16 ) );
		$this->assertTrue( LockFile::write( $this->base, 12, $token, 2000 ) );
		$path = LockFile::path( $this->base, 12 );
		$this->assertSame( $this->base . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'job-12.lock', $path );

		$raw = (string) file_get_contents( $path );
		$this->assertStringNotContainsString( $token, $raw, 'the token never lands on disk' );
		$this->assertStringContainsString( 'token_sha256:' . hash( 'sha256', $token ), $raw );

		$data = LockFile::read( $path );
		$this->assertSame( 12, $data['job'] );
		$this->assertSame( 2000, $data['locked_until'] );
		$this->assertGreaterThan( 0, $data['written'] );
		$this->assertTrue( LockFile::is_owned_by( $path, $token ) );
		$this->assertFalse( LockFile::is_owned_by( $path, 'other' ) );
		$this->assertSame( 12, LockFile::job_id_from_path( $path ) );
		$this->assertSame( 0, LockFile::job_id_from_path( $this->base . '/tmp/.reclaim.lock' ) );
	}

	public function test_staleness_uses_the_recorded_expiry_plus_grace(): void {
		LockFile::write( $this->base, 3, 'tok', 1000 );
		$path = LockFile::path( $this->base, 3 );
		$this->assertFalse( LockFile::is_stale( $path, 1000, 600 ) );
		$this->assertFalse( LockFile::is_stale( $path, 1600, 600 ) );
		$this->assertTrue( LockFile::is_stale( $path, 1601, 600 ) );
		file_put_contents( $path, "garbage\n" );
		$this->assertTrue( LockFile::is_stale( $path, 0, 600 ), 'malformed files are stale' );
		$this->assertNull( LockFile::read( $path ) );
	}

	public function test_remove_is_quiet_and_write_needs_the_tmp_directory(): void {
		LockFile::remove( $this->base, 99 );
		$this->assertFalse( LockFile::write( $this->base . '/missing', 1, 'tok', 1 ) );
		LockFile::write( $this->base, 5, 'tok', 1 );
		LockFile::remove( $this->base, 5 );
		$this->assertFileDoesNotExist( LockFile::path( $this->base, 5 ) );
	}
}
