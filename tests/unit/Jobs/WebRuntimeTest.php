<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\WebClasses;
use WPCheckpoint\Jobs\WebRuntime;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The runtime (WebRuntime): every class of WebClasses::ALL in one file, each after what it implements or extends; its
 * first statement returns false unless WPCHECKPOINT_WEB_ENTRY is defined (requested directly it says nothing); it
 * returns the SHA-256 of its body, which changes with any byte of a class file; a class file that uses __DIR__,
 * __FILE__ or __LINE__ outside a string stops it from being made.
 */
final class WebRuntimeTest extends TestCase {

	/**
	 * Sandbox.
	 *
	 * @var string
	 */
	private $sandbox = '';

	protected function set_up(): void {
		parent::set_up();
		$this->sandbox = Sandbox::make( 'web-runtime' );
	}

	protected function tear_down(): void {
		if ( '' !== $this->sandbox ) {
			Sandbox::remove( $this->sandbox );
		}
		parent::tear_down();
	}

	/**
	 * Run PHP in a process of its own: stdout, and the exit status.
	 *
	 * @param string $code PHP.
	 * @return array{0: string, 1: int, 2: string}
	 */
	private function php( string $code ): array {
		$file = $this->sandbox . '/run-' . bin2hex( random_bytes( 4 ) ) . '.php';
		file_put_contents( $file, $code );
		$pipes   = array();
		$process = proc_open( array( PHP_BINARY, '-d', 'display_errors=stderr', $file ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$out     = (string) stream_get_contents( $pipes[1] );
		$err     = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return array( $out, proc_close( $process ), $err );
	}

	public function test_the_runtime_holds_every_class_and_returns_its_hash_only_with_the_entrys_constant(): void {
		$built = WebRuntime::build( 'WP Checkpoint restore ' . str_repeat( 'ab', 16 ) );
		$this->assertSame( 1, preg_match( '/\A<\?php\n# (WP Checkpoint restore [0-9a-f]{32})\n/', $built['code'], $m ), 'it names the restore first' );
		$this->assertSame( 64, strlen( $built['hash'] ) );
		$path = $this->sandbox . '/runtime.php';
		file_put_contents( $path, $built['code'] );
		$list = var_export( WebClasses::ALL, true );
		// Directly: false, and nothing said. With the constant: its hash, and every class there (none autoloaded: there is
		// no autoloader in that process).
		list( $out, $status, $err ) = $this->php( '<?php define( "ABSPATH", "/tmp/" ); ob_start(); $r = include ' . var_export( $path, true ) . '; $said = ob_get_clean(); echo json_encode( array( $r, $said ) );' );
		$this->assertSame( 0, $status, $err );
		$this->assertSame( array( false, '' ), json_decode( $out, true ) );
		list( $out, $status, $err ) = $this->php( '<?php define( "ABSPATH", "/tmp/" ); define( "WPCHECKPOINT_WEB_ENTRY", true ); $r = include ' . var_export( $path, true ) . '; $missing = array(); foreach ( ' . $list . ' as $c ) { if ( ! class_exists( $c, false ) && ! interface_exists( $c, false ) && ! trait_exists( $c, false ) ) { $missing[] = $c; } } echo json_encode( array( $r, $missing ) );' );
		$this->assertSame( 0, $status, $err );
		$this->assertSame( array( $built['hash'], array() ), json_decode( $out, true ) );
	}

	public function test_a_class_is_declared_after_what_it_implements_or_extends(): void {
		mkdir( $this->sandbox . '/src/Fake', 0755, true );
		file_put_contents( $this->sandbox . '/src/Fake/Aaa.php', "<?php\nnamespace WPCheckpoint\\Fake;\n\nfinal class Aaa extends Bbb implements Ccc {\n}\n" );
		file_put_contents( $this->sandbox . '/src/Fake/Bbb.php', "<?php\nnamespace WPCheckpoint\\Fake;\n\nabstract class Bbb {\n}\n" );
		file_put_contents( $this->sandbox . '/src/Fake/Ccc.php', "<?php\nnamespace WPCheckpoint\\Fake;\n\ninterface Ccc {\n}\n" );
		spl_autoload_register(
			$load = function ( string $class ): void {
				if ( 0 === strpos( $class, 'WPCheckpoint\\Fake\\' ) ) {
					require $this->sandbox . '/src/Fake/' . substr( $class, strlen( 'WPCheckpoint\\Fake\\' ) ) . '.php';
				}
			}
		);
		try {
			$classes = array( 'WPCheckpoint\Fake\Aaa', 'WPCheckpoint\Fake\Bbb', 'WPCheckpoint\Fake\Ccc' );
			$order   = WebRuntime::order( $classes );
			$this->assertSame( 'WPCheckpoint\Fake\Aaa', end( $order ), 'Aaa sorts first, and goes after both: ' . implode( ', ', $order ) );
			$this->assertCount( 3, $order );
			$built = WebRuntime::build( 'WP Checkpoint restore ' . str_repeat( 'cd', 16 ), $classes, $this->sandbox . '/src' );
			file_put_contents( $this->sandbox . '/runtime.php', $built['code'] );
			list( $out, $status, $err ) = $this->php( '<?php define( "WPCHECKPOINT_WEB_ENTRY", true ); $r = include ' . var_export( $this->sandbox . '/runtime.php', true ) . '; echo json_encode( array( $r, class_exists( "WPCheckpoint\\\\Fake\\\\Aaa", false ) ) );' );
			$this->assertSame( 0, $status, $err );
			$this->assertSame( array( $built['hash'], true ), json_decode( $out, true ) );

			// Same classes, one byte more in one of them: another hash (a build of the same version can differ).
			file_put_contents( $this->sandbox . '/src/Fake/Bbb.php', "<?php\nnamespace WPCheckpoint\\Fake;\n\nabstract class Bbb {\n\n}\n" );
			$this->assertNotSame( $built['hash'], WebRuntime::build( 'WP Checkpoint restore ' . str_repeat( 'cd', 16 ), $classes, $this->sandbox . '/src' )['hash'] );
			// The mark is not part of the hash: the same classes for another restore say the same.
			$this->assertSame(
				WebRuntime::build( 'WP Checkpoint restore ' . str_repeat( 'cd', 16 ), $classes, $this->sandbox . '/src' )['hash'],
				WebRuntime::build( 'WP Checkpoint restore ' . str_repeat( 'ef', 16 ), $classes, $this->sandbox . '/src' )['hash']
			);
		} finally {
			spl_autoload_unregister( $load );
		}
	}

	public function test_a_class_file_with_windows_line_endings_is_taken(): void {
		mkdir( $this->sandbox . '/src/Fake', 0755, true );
		$file = $this->sandbox . '/src/Fake/Crlf.php';
		file_put_contents( $file, str_replace( "\n", "\r\n", "<?php\n/**\n * A file.\n */\n\nnamespace WPCheckpoint\\Fake;\n\nfinal class Crlf {\n}\n" ) );
		$this->assertStringContainsString( "\r\n", (string) file_get_contents( $file ), 'the control: the file has Windows line endings' );
		spl_autoload_register(
			$load = static function ( string $class ) use ( $file ): void {
				if ( 'WPCheckpoint\Fake\Crlf' === $class ) {
					require $file;
				}
			}
		);
		try {
			$built = WebRuntime::build( 'WP Checkpoint restore ' . str_repeat( 'ab', 16 ), array( 'WPCheckpoint\Fake\Crlf' ), $this->sandbox . '/src' );
			file_put_contents( $this->sandbox . '/runtime.php', $built['code'] );
			list( $out, $status, $err ) = $this->php( '<?php define( "WPCHECKPOINT_WEB_ENTRY", true ); $r = include ' . var_export( $this->sandbox . '/runtime.php', true ) . '; echo json_encode( array( $r, class_exists( "WPCheckpoint\\Fake\\Crlf", false ) ) );' );
			$this->assertSame( 0, $status, $err );
			$this->assertSame( array( $built['hash'], true ), json_decode( $out, true ) );
		} finally {
			spl_autoload_unregister( $load );
		}
	}

	/**
	 * Each of the three, outside a string.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function magic_constants(): array {
		return array(
			'__DIR__'  => array( '__DIR__' ),
			'__FILE__' => array( '__FILE__' ),
			'__LINE__' => array( '__LINE__' ),
		);
	}

	/**
	 * @dataProvider magic_constants
	 */
	public function test_a_class_file_that_uses_a_magic_constant_outside_a_string_stops_the_runtime( string $constant ): void {
		mkdir( $this->sandbox . '/src/Fake', 0755, true );
		$file = $this->sandbox . '/src/Fake/Uses.php';
		// The control: in a string (single, double quoted, heredoc), it is no use of it.
		file_put_contents( $file, "<?php\nnamespace WPCheckpoint\\Fake;\n\nfinal class Uses {\n\tconst A = '" . $constant . "';\n\tconst B = \"" . $constant . "\";\n\tconst C = <<<TXT\n" . $constant . "\nTXT;\n}\n" );
		$this->assertSame( '', WebRuntime::magic( (string) file_get_contents( $file ) ) );
		file_put_contents( $file, "<?php\nnamespace WPCheckpoint\\Fake;\n\nfinal class Uses {\n\tpublic function f() {\n\t\treturn " . $constant . ";\n\t}\n}\n" );
		$this->assertSame( $constant, WebRuntime::magic( (string) file_get_contents( $file ) ) );
		spl_autoload_register(
			$load = function ( string $class ) use ( $file ): void {
				if ( 'WPCheckpoint\Fake\Uses' === $class ) {
					require $file;
				}
			}
		);
		try {
			WebRuntime::build( 'WP Checkpoint restore ' . str_repeat( 'ab', 16 ), array( 'WPCheckpoint\Fake\Uses' ), $this->sandbox . '/src' );
			$this->fail( 'made with ' . $constant );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'uses ' . $constant . ' outside a string', $e->getMessage() );
		} finally {
			spl_autoload_unregister( $load );
		}
	}

	public function test_no_class_of_the_runtime_uses_a_magic_constant_outside_a_string(): void {
		$seen = 0;
		foreach ( WebClasses::ALL as $class ) {
			$source = (string) file_get_contents( ( new \ReflectionClass( $class ) )->getFileName() );
			$this->assertSame( '', WebRuntime::magic( $source ), $class );
			$seen += false !== strpos( $source, '__DIR__' ) ? 1 : 0;
		}
		$this->assertGreaterThan( 0, $seen, 'the control: a class of the runtime has the name in a string (Maintenance::CODE)' );
	}
}
