<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\WebEntry;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The entry: the request's host is a mask only when it is a domain name (a header anyone can send would otherwise
 * hide words of the notes); nothing the run says reaches the visitor (display_errors turned off, output dropped, its
 * error handler and output buffers gone afterwards), and a runtime of another build does nothing but say so.
 */
final class WebEntryTest extends TestCase {

	/**
	 * Hosts and what the entry makes of them.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function hosts(): array {
		return array(
			'a domain'          => array( 'Example.ORG', 'example.org' ),
			'with its port'     => array( 'shop.example.org:8443', 'shop.example.org' ),
			'a trailing dot'    => array( 'example.org.', 'example.org' ),
			'a word'            => array( 'job', '' ),
			'a number'          => array( '1', '' ),
			'an address'        => array( '10.0.0.1', '' ),
			'a path'            => array( 'example.org/x', '' ),
			'nothing'           => array( '', '' ),
			'an IPv6 address'   => array( '[::1]:80', '' ),
			'a label too long'  => array( str_repeat( 'a', 64 ) . '.org', '' ),
		);
	}

	/**
	 * @dataProvider hosts
	 */
	public function test_the_request_host_is_a_mask_only_when_it_is_a_domain_name( string $header, string $host ): void {
		$was                  = $_SERVER['HTTP_HOST'] ?? null;
		$_SERVER['HTTP_HOST'] = $header;
		try {
			$this->assertSame( $host, WebEntry::request_host() );
		} finally {
			if ( null === $was ) {
				unset( $_SERVER['HTTP_HOST'] );
			} else {
				$_SERVER['HTTP_HOST'] = $was;
			}
		}
	}

	public function test_what_the_run_prints_or_php_says_never_reaches_the_visitor_and_only_what_is_not_silenced_is_noted(): void {
		$dir = Sandbox::make( 'web-entry-quietly' );
		try {
			$script = $dir . '/run.php';
			$what   = 'echo "LEAKED-OUTPUT"; @fopen( "/no/such/file", "r" ); trigger_error( "an unsilenced warning", E_USER_WARNING ); $x = array(); @$x["none"];';
			file_put_contents(
				$script,
				'<?php define( "ABSPATH", ' . var_export( $dir . '/', true ) . ' ); require ' . var_export( dirname( __DIR__, 3 ) . '/vendor/autoload.php', true ) . ';'
				. ' $notes = array(); $level = ob_get_level();'
				. ' if ( in_array( "--plain", $argv, true ) ) { ( static function () { ' . $what . ' } )(); exit( 0 ); }'
				. ' \WPCheckpoint\Jobs\WebEntry::quietly( static function ( string $line ) use ( &$notes ): void { $notes[] = $line; }, static function () { ' . $what . ' } );'
				. ' echo "JSON:" . json_encode( array( $notes, ini_get( "display_errors" ), ob_get_level() === $level, null === set_error_handler( null ) ) );'
			);
			$run = static function ( array $more ) use ( $script ): string {
				$pipes   = array();
				$process = proc_open( array_merge( array( PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', $script ), $more ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
				$out     = (string) stream_get_contents( $pipes[1] );
				stream_get_contents( $pipes[2] );
				fclose( $pipes[1] );
				fclose( $pipes[2] );
				proc_close( $process );
				return $out;
			};
			// The control: run plainly, the same code prints and PHP says it to the visitor.
			$plain = $run( array( '--plain' ) );
			$this->assertStringContainsString( 'LEAKED-OUTPUT', $plain );
			$this->assertStringContainsString( 'an unsilenced warning', $plain );
			$quiet = $run( array() );
			$this->assertSame( 0, strpos( $quiet, 'JSON:' ), 'nothing before what the script itself says: ' . $quiet );
			$said = json_decode( substr( $quiet, 5 ), true );
			$this->assertSame( array( 'PHP said: an unsilenced warning' ), $said[0], 'noted: the unsilenced warning only (the "@" ones are the code\'s own to handle)' );
			$this->assertSame( array( '0', true, true ), array_slice( $said, 1 ), 'display_errors off, the buffers as they were, no handler left' );
		} finally {
			Sandbox::remove( $dir );
		}
	}

	/**
	 * What ends the callable early: an exception (the handler and the buffer as they were), a flush from inside, and a
	 * fatal error (the request ends there: what it printed, and PHP's message, never reach the visitor).
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function endings(): array {
		return array(
			'an exception' => array( 'echo "LEAKED-OUTPUT"; throw new \\RuntimeException( "stopped" );', false ),
			'a flush'      => array( 'echo "LEAKED-OUTPUT"; ob_end_flush(); ob_start();', false ),
			'a fatal'      => array( 'echo "LEAKED-OUTPUT"; ini_set( "memory_limit", "8M" ); $x = str_repeat( "x", 64 * 1048576 );', true ),
		);
	}

	/**
	 * @dataProvider endings
	 */
	public function test_nothing_reaches_the_visitor_however_the_run_ends( string $what, bool $fatal ): void {
		$dir = Sandbox::make( 'web-entry-ending' );
		try {
			$script = $dir . '/run.php';
			file_put_contents(
				$script,
				'<?php define( "ABSPATH", ' . var_export( $dir . '/', true ) . ' ); require ' . var_export( dirname( __DIR__, 3 ) . '/vendor/autoload.php', true ) . ';'
				. ' $level = ob_get_level();'
				. ' try { \WPCheckpoint\Jobs\WebEntry::quietly( static function ( string $line ): void {}, static function () { ' . $what . ' } ); } catch ( \RuntimeException $e ) { echo "CAUGHT:"; }'
				. ' echo "AFTER:" . json_encode( array( ob_get_level() === $level, null === set_error_handler( null ) ) );'
			);
			$pipes   = array();
			$process = proc_open( array( PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', $script ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			$out     = (string) stream_get_contents( $pipes[1] );
			stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			proc_close( $process );
			$this->assertStringNotContainsString( 'LEAKED-OUTPUT', $out );
			$this->assertStringNotContainsString( 'Allowed memory size', $out, 'nor PHP\'s message' );
			if ( $fatal ) {
				$this->assertSame( '', $out, 'the request ended there, and gave nothing' );
				return;
			}
			$this->assertStringEndsWith( 'AFTER:[true,true]', $out, 'the handler and the buffers as they were: ' . $out );
		} finally {
			Sandbox::remove( $dir );
		}
	}

	public function test_nothing_the_run_says_reaches_the_visitor_and_it_leaves_no_handler_or_buffer(): void {
		$dir = Sandbox::make( 'web-entry' );
		try {
			$mark = 'WP Checkpoint restore ' . str_repeat( 'ab', 16 );
			( new Maintenance( $dir, $mark ) )->hold();
			file_put_contents( $dir . '/' . Maintenance::LOG, "<?php exit; ?>\n# " . $mark . "\n" );
			$script = $dir . '/run.php';
			file_put_contents(
				$script,
				'<?php define( "ABSPATH", ' . var_export( $dir . '/', true ) . ' ); require ' . var_export( dirname( __DIR__, 3 ) . '/vendor/autoload.php', true ) . ';'
				. ' ob_start(); $level = ob_get_level(); $_SERVER["HTTP_HOST"] = "visitor.example.org";'
				. ' \WPCheckpoint\Jobs\WebEntry::run( ' . var_export( $dir, true ) . ', ' . var_export( $mark, true ) . ', str_repeat( "a", 64 ), str_repeat( "b", 64 ) );'
				. ' $after = array( ini_get( "display_errors" ), ob_get_level() === $level, null === set_error_handler( null ) ); ob_end_clean(); echo json_encode( $after );'
			);
			$pipes   = array();
			$process = proc_open( array( PHP_BINARY, '-d', 'display_errors=1', $script ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			$out     = (string) stream_get_contents( $pipes[1] );
			$err     = (string) stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			proc_close( $process );
			$this->assertSame( array( '0', true, true ), json_decode( $out, true ), 'display_errors off, the buffers as they were, no handler left: ' . $out . $err );
			$this->assertStringContainsString( 'The runtime here is not the one the restore wrote', implode( "\n", Maintenance::log_lines( $dir . '/' . Maintenance::LOG ) ), 'the control: it ran and said so in the log' );
		} finally {
			Sandbox::remove( $dir );
		}
	}
}
