<?php

namespace WPCheckpoint\Tests\Unit\Support;

use WPCheckpoint\Support\FileStreamer;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class FileStreamerTest extends TestCase {

	private function plan( $range, $if_range = null, int $size = 1000 ): array {
		return FileStreamer::plan( $size, $range, '"etag"', $if_range );
	}

	public function test_no_range_is_full_response(): void {
		$this->assertSame( array( 'status' => 200, 'start' => 0, 'end' => 999, 'length' => 1000 ), $this->plan( null ) );
		$this->assertSame( 200, $this->plan( '' )['status'] );
	}

	public function test_single_ranges(): void {
		$this->assertSame( array( 'status' => 206, 'start' => 0, 'end' => 99, 'length' => 100 ), $this->plan( 'bytes=0-99' ) );
		$this->assertSame( array( 'status' => 206, 'start' => 500, 'end' => 999, 'length' => 500 ), $this->plan( 'bytes=500-' ) );
		$this->assertSame( array( 'status' => 206, 'start' => 900, 'end' => 999, 'length' => 100 ), $this->plan( 'bytes=-100' ) );
		$this->assertSame( array( 'status' => 206, 'start' => 0, 'end' => 999, 'length' => 1000 ), $this->plan( 'bytes=-5000' ), 'suffix longer than file' );
		$this->assertSame( array( 'status' => 206, 'start' => 990, 'end' => 999, 'length' => 10 ), $this->plan( 'bytes=990-5000' ), 'end clamped' );
		$this->assertSame( 206, $this->plan( ' bytes = 1-2 ' )['status'] );
	}

	public function test_unsatisfiable_ranges_get_416(): void {
		foreach ( array( 'bytes=1000-', 'bytes=1000-1100', 'bytes=50-10', 'bytes=-0', 'bytes=-', 'bytes=a-b' ) as $range ) {
			$plan = $this->plan( $range );
			$this->assertSame( 416, $plan['status'], $range );
			$this->assertSame( 0, $plan['length'], $range );
		}
		$this->assertSame( 416, $this->plan( 'bytes=0-0', null, 0 )['status'], 'empty file' );
	}

	public function test_multi_range_and_other_units_fall_back_to_full(): void {
		$this->assertSame( 200, $this->plan( 'bytes=0-1,5-6' )['status'] );
		$this->assertSame( 200, $this->plan( 'items=0-1' )['status'] );
	}

	public function test_if_range_mismatch_falls_back_to_full(): void {
		$this->assertSame( 206, $this->plan( 'bytes=0-1', '"etag"' )['status'] );
		$this->assertSame( 200, $this->plan( 'bytes=0-1', '"other"' )['status'] );
	}

	public function test_safe_filename(): void {
		$this->assertSame( 'a.log', FileStreamer::safe_filename( '/var/x/a.log' ) );
		$this->assertSame( 'a.log', FileStreamer::safe_filename( 'C:\\x\\a.log' ) );
		$this->assertSame( 'ab.zip', FileStreamer::safe_filename( "a\"\r\n\x00b.zip" ) );
		$this->assertSame( 'download', FileStreamer::safe_filename( '"' ) );
		$this->assertSame( 'download', FileStreamer::safe_filename( '..' ) );
	}

	public function test_stream_emits_headers_and_bytes(): void {
		$path = tempnam( sys_get_temp_dir(), 'wpc' );
		file_put_contents( $path, 'ABCDEFGHIJ' );
		try {
			foreach ( array( array( 'bytes=2-5', 'CDEF', 206 ), array( null, 'ABCDEFGHIJ', 200 ) ) as list( $range, $expected, $status ) ) {
				$plan    = FileStreamer::plan( 10, $range, FileStreamer::etag( $path ) );
				$headers = array();
				$out     = fopen( 'php://memory', 'w+' );
				$ok      = FileStreamer::stream( $path, 'my "file".zip', $plan, 'GET', static function ( string $h, $code ) use ( &$headers ) {
					$headers[] = null === $code ? $h : $h . ' [' . $code . ']';
				}, $out );
				rewind( $out );
				$this->assertTrue( $ok );
				$this->assertSame( $expected, stream_get_contents( $out ) );
				$this->assertContains( 'Content-Type: application/octet-stream [' . $status . ']', $headers );
				$this->assertContains( 'Content-Length: ' . strlen( $expected ), $headers );
				$this->assertContains( 'Content-Disposition: attachment; filename="my file.zip"', $headers );
				$this->assertContains( 'X-Content-Type-Options: nosniff', $headers );
				$this->assertContains( 'Accept-Ranges: bytes', $headers );
				if ( 206 === $status ) {
					$this->assertContains( 'Content-Range: bytes 2-5/10', $headers );
				}
			}

			$headers = array();
			$out     = fopen( 'php://memory', 'w+' );
			FileStreamer::stream( $path, 'f', FileStreamer::plan( 10, 'bytes=0-3', '"e"' ), 'HEAD', static function ( string $h, $code ) use ( &$headers ) {
				$headers[] = $h;
			}, $out );
			rewind( $out );
			$this->assertSame( '', stream_get_contents( $out ), 'HEAD sends no body' );
			$this->assertContains( 'Content-Length: 4', $headers );

			$headers = array();
			FileStreamer::stream( $path, 'f', FileStreamer::plan( 10, 'bytes=50-', '"e"' ), 'GET', static function ( string $h, $code ) use ( &$headers ) {
				$headers[] = $h . ' [' . var_export( $code, true ) . ']';
			}, fopen( 'php://memory', 'w+' ) );
			$this->assertContains( 'Content-Range: bytes */10 [416]', $headers );
		} finally {
			unlink( $path );
		}
	}
}
