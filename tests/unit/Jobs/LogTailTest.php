<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\LogTail;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class LogTailTest extends TestCase {

	/** @var string */
	private $file;

	protected function set_up(): void {
		$this->file = sys_get_temp_dir() . '/wpcheckpoint-tail-' . bin2hex( random_bytes( 4 ) ) . '.log';
	}

	protected function tear_down(): void {
		if ( is_file( $this->file ) ) {
			unlink( $this->file );
		}
	}

	public function test_missing_file_is_distinguished_from_a_failed_read(): void {
		$this->assertSame( array( 'exists' => false, 'ok' => true, 'text' => '' ), LogTail::read( $this->file ) );
		$this->assertSame( array( 'exists' => false, 'ok' => true, 'text' => '' ), LogTail::read( '' ) );
	}

	public function test_reads_only_the_end_and_drops_the_cut_first_line(): void {
		$lines = array();
		for ( $i = 1; $i <= 2000; $i++ ) {
			$lines[] = sprintf( 'line %04d %s', $i, str_repeat( 'x', 40 ) );
		}
		file_put_contents( $this->file, implode( "\n", $lines ) . "\n" );
		$tail = LogTail::read( $this->file, 1024, 10 );
		$this->assertTrue( $tail['exists'] );
		$this->assertTrue( $tail['ok'] );
		$got = explode( "\n", $tail['text'] );
		$this->assertCount( 10, $got );
		$this->assertSame( 'line 1991 ' . str_repeat( 'x', 40 ), $got[0] );
		$this->assertSame( 'line 2000 ' . str_repeat( 'x', 40 ), $got[9] );

		$tail = LogTail::read( $this->file, 200, 10 );
		foreach ( explode( "\n", $tail['text'] ) as $line ) {
			$this->assertMatchesRegularExpression( '/^line \d{4} x{40}$/', $line, 'no partial line' );
		}
	}

	public function test_small_files_and_crlf(): void {
		file_put_contents( $this->file, "a\r\nb\r\nc\r\n" );
		$this->assertSame( "a\nb\nc", LogTail::read( $this->file )['text'] );
		file_put_contents( $this->file, '' );
		$this->assertSame( '', LogTail::read( $this->file )['text'] );
	}
}
