<?php

namespace WPCheckpoint\Tests\Unit\Standalone;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * In a web request, what wp-config.php does to the response (a redirect to
 * HTTPS, headers, a cookie, a session, output) does not reach the caller's
 * answer, also when wp-config.php ends the request. Runs PHP's built-in
 * server.
 */
final class ConfigLoaderHttpTest extends TestCase {

	/** @var string */
	private $root;

	/** @var resource|null */
	private $server;

	/** @var int */
	private $port = 0;

	protected function set_up(): void {
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$this->markTestSkipped( 'Starts PHP\'s built-in server through a POSIX shell.' );
		}
		$this->root = sys_get_temp_dir() . '/wpcheckpoint-config-http-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/site', 0700, true );
		$plugin = dirname( __DIR__, 3 );
		file_put_contents(
			$this->root . '/serve.php',
			"<?php\nini_set( 'zend.exception_ignore_args', '1' );\ndefine( 'ABSPATH', " . var_export( $plugin . '/src/Standalone/stub/', true ) . " );\nrequire " . var_export( $plugin . '/vendor/autoload.php', true ) . ";\n"
			. "use WPCheckpoint\\Standalone\\ConfigLoader;\nuse WPCheckpoint\\Standalone\\Failure;\n"
			. "header( 'X-Caller: kept' );\n"
			. "\$answer = static function ( array \$data ): void {\n\theader( 'Content-Type: application/json' );\n\techo json_encode( \$data );\n};\n"
			. "try {\n\t\$credentials = ConfigLoader::load( __DIR__ . '/site/wp-config.php', ConfigLoader::stub_dir(), static function ( Failure \$failure ) use ( \$answer ): void {\n\t\t\$answer( array( 'failure' => \$failure->reason() ) );\n\t} );\n\t\$answer( array( 'prefix' => \$credentials->get( 'prefix' ) ) );\n} catch ( Failure \$failure ) {\n\t\$answer( array( 'failure' => \$failure->reason() ) );\n}\n"
		);
		for ( $attempt = 0; $attempt < 5 && null === $this->server; $attempt++ ) {
			$port    = mt_rand( 20000, 40000 );
			$process = proc_open( 'exec ' . escapeshellarg( PHP_BINARY ) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg( $this->root ), array( 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ), $pipes );
			for ( $wait = 0; $wait < 50; $wait++ ) {
				$socket = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.1 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- waiting for the server.
				if ( is_resource( $socket ) ) {
					fclose( $socket );
					$this->server = $process;
					$this->port   = $port;
					break;
				}
				usleep( 100000 );
			}
			if ( null === $this->server && is_resource( $process ) ) {
				proc_terminate( $process );
			}
		}
		$this->assertIsResource( $this->server, 'the built-in server started' );
	}

	protected function tear_down(): void {
		if ( is_resource( $this->server ) ) {
			proc_terminate( $this->server );
			proc_close( $this->server );
		}
		if ( is_string( $this->root ) && is_dir( $this->root ) ) {
			foreach ( array( '/site/wp-config.php', '/serve.php' ) as $file ) {
				@unlink( $this->root . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- clean-up.
			}
			@rmdir( $this->root . '/site' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- clean-up.
			@rmdir( $this->root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- clean-up.
		}
	}

	/**
	 * The response to the caller's page.
	 *
	 * @return array{status: int, headers: string[], body: string}
	 */
	private function request(): array {
		$body = (string) @file_get_contents( 'http://127.0.0.1:' . $this->port . '/serve.php', false, stream_context_create( array( 'http' => array( 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 20 ) ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test server.
		$headers = isset( $http_response_header ) ? $http_response_header : array();
		preg_match( '#^HTTP/\S+ (\d+)#', (string) ( $headers[0] ?? '' ), $status );
		return array(
			'status'  => (int) ( $status[1] ?? 0 ),
			'headers' => $headers,
			'body'    => $body,
		);
	}

	const NOISE = "set_error_handler( function () { return true; } );\nheader( 'Location: https://example.test/', true, 301 );\nheader( 'X-Site: marker' );\nsetcookie( 'site_cookie', 'marker' );\nsession_start();\nob_start();\necho 'site output';\n";

	/**
	 * A wp-config.php with the database settings and more.
	 *
	 * @param string $more Lines.
	 * @return void
	 */
	private function config( string $more ): void {
		file_put_contents( $this->root . '/site/wp-config.php', "<?php\ndefine( 'DB_NAME', 'n' );\ndefine( 'DB_USER', 'u' );\ndefine( 'DB_PASSWORD', 'p' );\ndefine( 'DB_HOST', 'h' );\n\$table_prefix = 'wp_';\n" . $more . "require_once ABSPATH . 'wp-settings.php';\n" );
	}

	public function test_the_caller_answers_as_it_meant_to(): void {
		// The control: what wp-config.php does reaches a response when nothing undoes it.
		file_put_contents( $this->root . '/site/wp-config.php', "<?php\n" . self::NOISE );
		$raw = $this->request_file( 'site/wp-config.php' );
		$this->assertSame( 301, $raw['status'] );
		$this->assertStringContainsString( 'X-Site: marker', implode( "\n", $raw['headers'] ) );

		$this->config( self::NOISE );
		$response = $this->request();
		$this->assert_own_answer( $response );
		$this->assertSame( array( 'prefix' => 'wp_' ), json_decode( $response['body'], true ) );
	}

	public function test_the_caller_answers_as_it_meant_to_when_wp_config_ends_the_request(): void {
		$this->config( self::NOISE . "exit;\n" );
		$response = $this->request();
		$this->assert_own_answer( $response );
		$this->assertSame( array( 'failure' => 'config_stopped' ), json_decode( $response['body'], true ) );
	}

	/**
	 * The response is the caller's: 200, its header, JSON, nothing of the site's.
	 *
	 * @param array{status: int, headers: string[], body: string} $response Response.
	 * @return void
	 */
	private function assert_own_answer( array $response ): void {
		$headers = implode( "\n", $response['headers'] );
		$this->assertSame( 200, $response['status'], $headers );
		$this->assertStringContainsString( 'X-Caller: kept', $headers, 'the caller\'s own header' );
		$this->assertStringContainsString( 'Content-Type: application/json', $headers );
		foreach ( array( 'Location', 'X-Site', 'marker', 'Set-Cookie' ) as $site ) {
			$this->assertStringNotContainsString( $site, $headers );
		}
		$this->assertStringNotContainsString( 'site output', $response['body'] );
	}

	/**
	 * A file of the document root requested directly (for the control).
	 *
	 * @param string $path Path under the root.
	 * @return array{status: int, headers: string[], body: string}
	 */
	private function request_file( string $path ): array {
		$body = (string) @file_get_contents( 'http://127.0.0.1:' . $this->port . '/' . $path, false, stream_context_create( array( 'http' => array( 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 20 ) ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test server.
		$headers = isset( $http_response_header ) ? $http_response_header : array();
		preg_match( '#^HTTP/\S+ (\d+)#', (string) ( $headers[0] ?? '' ), $status );
		return array(
			'status'  => (int) ( $status[1] ?? 0 ),
			'headers' => $headers,
			'body'    => $body,
		);
	}
}
