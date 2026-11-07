<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use WPCheckpoint\Tests\Fixtures\ExpectedPath;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use WPCheckpoint\Tests\Fixtures\UploadsLeftovers;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The names the tests make in the uploads directory are read from their code; an entry under one of them stops a run
 * before it starts (leftovers-precheck.php, status 77), listed; the entries the leftover check compares are the uploads
 * directory's top-level ones but WordPress's own.
 */
final class UploadsLeftoversTest extends TestCase {

	/**
	 * The sandbox.
	 *
	 * @var string
	 */
	private $sandbox = '';

	public function set_up(): void {
		parent::set_up();
		$this->sandbox = Sandbox::make( 'uploads-leftovers' );
	}

	public function tear_down(): void {
		if ( '' !== $this->sandbox ) {
			Sandbox::remove( $this->sandbox );
		}
		parent::tear_down();
	}

	/**
	 * The names the tests make in the uploads directory now: a test that makes a new one changes this list too.
	 */
	const NAMES = array( 'wpccron', 'wpcexport', 'wpcnobudget', 'wpcpipe-uploads', 'wpcptest-preflight', 'wpcreplay-uploads' );

	public function test_the_names_are_read_from_the_tests_code(): void {
		$this->assertSame( self::NAMES, UploadsLeftovers::names() );
	}

	/**
	 * A tree of tests of its own, one file.
	 *
	 * @param string $code The file's code.
	 * @return string The tree.
	 */
	private function tree( string $code ): string {
		$root = $this->sandbox . '/tree-' . bin2hex( random_bytes( 3 ) );
		mkdir( $root . '/tests/integration', 0755, true );
		file_put_contents( $root . '/tests/integration/AnyTest.php', $code );
		return $root;
	}

	public function test_a_name_in_a_comment_is_not_read_and_one_in_code_is(): void {
		$commented = $this->tree( "<?php\n// \$x = wp_upload_dir()['basedir'] . '/wpcincomment';\n/** wp_upload_dir()['basedir'] . '/wpcindoc' */\n\$s = \"wp_upload_dir()['basedir'] . '/wpcinstring'\";\n" );
		$this->assertSame( array(), UploadsLeftovers::names( $commented, array() ) );
		$coded = $this->tree( "<?php\n\$x = wp_upload_dir( null, false )[ 'basedir' ] . '/wpcincode-dir';\n" );
		$this->assertSame( array( 'wpcincode-dir' ), UploadsLeftovers::names( $coded, array() ), 'the control: in code it is read' );
	}

	public function test_a_use_it_cannot_read_fails_it_unless_registered(): void {
		foreach ( array(
			'a variable'           => "\$d = wp_upload_dir()['basedir'] . \$name;",
			'double quotes'        => "\$d = wp_upload_dir()['basedir'] . \"/wpcquoted\";",
			'trailingslashit'      => "\$d = trailingslashit( wp_upload_dir()['basedir'] ) . 'wpcslash';",
			'another key'          => "\$d = wp_upload_dir()['path'] . '/wpcpath';",
			'the directory itself' => "\$d = wp_upload_dir()['basedir'];",
		) as $what => $line ) {
			$root = $this->tree( "<?php\n" . $line . "\n" );
			try {
				UploadsLeftovers::names( $root, array() );
				$this->fail( $what . ': not refused' );
			} catch ( \LogicException $e ) {
				$this->assertStringContainsString( 'tests/integration/AnyTest.php: 1 use(s)', $e->getMessage(), $what );
			}
			$this->assertSame( array(), UploadsLeftovers::names( $root, array( 'tests/integration/AnyTest.php' => array( 1, 'the test\'s' ) ) ), $what . ': registered, it passes' );
		}
		try {
			UploadsLeftovers::names( $this->tree( "<?php\n" ), array( 'tests/integration/AnyTest.php' => array( 1, 'gone' ) ) );
			$this->fail( 'an out-of-date registration not refused' );
		} catch ( \LogicException $e ) {
			$this->assertStringContainsString( 'registration out of date', $e->getMessage() );
		}
	}

	public function test_the_entries_leave_out_wordpresss_own(): void {
		foreach ( array( '2026', 'sites', 'wpcexport-left', 'someone' ) as $entry ) {
			mkdir( $this->sandbox . '/' . $entry );
		}
		file_put_contents( $this->sandbox . '/a-file.txt', 'x' );
		$this->assertSame(
			array( ExpectedPath::slashed( $this->sandbox, 'a-file.txt' ), ExpectedPath::slashed( $this->sandbox, 'someone' ), ExpectedPath::slashed( $this->sandbox, 'wpcexport-left' ) ),
			UploadsLeftovers::entries( $this->sandbox )
		);
		$this->assertSame( array( ExpectedPath::slashed( $this->sandbox, 'wpcexport-left' ) ), UploadsLeftovers::left( $this->sandbox, array( 'wpcexport' ) ) );
	}

	/**
	 * The precheck over a directory.
	 *
	 * @param string $dir The uploads directory.
	 * @return array{0: int, 1: string} Its exit status and standard error.
	 */
	private static function precheck( string $dir ): array {
		$pipes   = array();
		$process = proc_open(
			array( PHP_BINARY, dirname( __DIR__, 2 ) . '/Fixtures/leftovers-precheck.php', $dir, $dir . '/no-mu-plugins' ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		$out    = (string) stream_get_contents( $pipes[1] );
		$err    = (string) stream_get_contents( $pipes[2] );
		$status = proc_close( $process );
		unset( $out );
		return array( $status, $err );
	}

	public function test_an_entry_under_a_tests_name_stops_the_run_listed(): void {
		mkdir( $this->sandbox . '/someone-elses' );
		list( $status, $err ) = self::precheck( $this->sandbox );
		$this->assertSame( 0, $status, 'the control: an entry no test makes does not stop it: ' . $err );
		mkdir( $this->sandbox . '/wpcreplay-uploads' );
		list( $status, $err ) = self::precheck( $this->sandbox );
		$this->assertSame( 77, $status, $err );
		$this->assertStringContainsString( ExpectedPath::slashed( $this->sandbox, 'wpcreplay-uploads' ), $err );
		$this->assertStringNotContainsString( 'someone-elses', $err );
		$this->assertDirectoryExists( $this->sandbox . '/wpcreplay-uploads', 'nothing removed' );
	}
}
