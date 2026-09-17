<?php

namespace WPCheckpoint\Tests\Unit\Support;

use WPCheckpoint\Support\Logger;
use WPCheckpoint\Support\Redactor;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class LoggerTest extends TestCase {

	/** @var string */
	private $dir;

	protected function set_up(): void {
		$this->dir = sys_get_temp_dir() . '/wpcheckpoint-logger-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir, 0700, true );
	}

	protected function tear_down(): void {
		foreach ( glob( $this->dir . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->dir );
	}

	public function test_lines_are_rendered_and_redacted(): void {
		$logger = Logger::for_job( $this->dir, 'Export #12', new Redactor( array( 'hunter2!' ) ) );
		$logger->info( 'Connecting as admin@example.com with hunter2!', array( 'note' => 'pw is hunter2!', 'url' => 'https://a/b' ) );
		$logger->error( "multi\nline", array( 'password' => 'x' ) );

		$this->assertMatchesRegularExpression( '#[/\\\\]job-export-12-[0-9a-f]{8}\.log$#', $logger->path() );
		$lines = file( $logger->path(), FILE_IGNORE_NEW_LINES );
		$this->assertCount( 2, $lines );
		$this->assertMatchesRegularExpression( '/^\[\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\] INFO /', $lines[0] );
		$this->assertStringNotContainsString( 'hunter2!', $lines[0] );
		$this->assertStringContainsString( 'a***@example.com', $lines[0] );
		$this->assertStringContainsString( '"url":"https://a/b"', $lines[0], 'slashes are not escaped' );
		$this->assertStringContainsString( 'ERROR multi line', $lines[1] );
		$this->assertStringContainsString( '"password":"[redacted]"', $lines[1] );
	}

	public function test_unencodable_context_falls_back_to_key_list(): void {
		$logger = new Logger( $this->dir . '/x.log', new Redactor() );
		$logger->info( 'bad', array( 'blob' => "\xB1\x31", 'ok' => 1 ) );
		$line = file_get_contents( $logger->path() );
		$this->assertStringContainsString( '"ok":1', $line );
		$this->assertStringNotContainsString( "\xB1\x31", $line );
	}

	public function test_size_cap_writes_marker_and_stops(): void {
		$logger = new Logger( $this->dir . '/cap.log', new Redactor(), 300 );
		for ( $i = 0; $i < 20; $i++ ) {
			$logger->info( str_repeat( 'x', 50 ) );
		}
		$content = file_get_contents( $logger->path() );
		$this->assertLessThanOrEqual( 300 + 120, strlen( $content ) );
		$this->assertStringContainsString( 'Log size limit reached', $content );
		$this->assertSame( 1, substr_count( $content, 'Log size limit reached' ) );
	}

	public function test_logger_has_a_single_write_path_after_redaction(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Support/Logger.php' );
		$this->assertSame( 1, preg_match_all( '/\bfwrite\s*\(/', $source ), 'exactly one fwrite() in Logger' );
		$this->assertSame( 0, preg_match_all( '/\bfile_put_contents\s*\(/', $source ) );
		$redact_pos = strpos( $source, '$this->redactor->redact(' );
		$write_pos  = strpos( $source, 'fwrite(' );
		$this->assertNotFalse( $redact_pos );
		$this->assertLessThan( $write_pos, $redact_pos, 'redaction happens before the write' );
		$this->assertSame( 1, preg_match_all( '/private function write\(/', $source ), 'write() is private' );
	}
}
