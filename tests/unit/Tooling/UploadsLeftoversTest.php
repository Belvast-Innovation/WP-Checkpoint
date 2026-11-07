<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use WPCheckpoint\Tests\Fixtures\ExpectedPath;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use WPCheckpoint\Tests\Fixtures\UploadsLeftovers;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The names the tests make in the uploads directory are read from their code; an entry under one of them stops a run
 * before it starts (uploads-precheck.php, status 77), listed; the entries the leftover check compares are the uploads
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

	public function test_the_names_are_read_from_the_tests(): void {
		$names = UploadsLeftovers::names();
		foreach ( array( 'wpccron', 'wpcexport', 'wpcnobudget', 'wpcpipe-uploads', 'wpcptest-preflight', 'wpcreplay-uploads' ) as $known ) {
			$this->assertContains( $known, $names, 'a name a test makes in the uploads directory, when written' );
		}
		// The control of the reading: a tree of its own gives its own names, and none from a file without one.
		mkdir( $this->sandbox . '/tests/integration', 0755, true );
		mkdir( $this->sandbox . '/tests/Fixtures', 0755, true );
		file_put_contents( $this->sandbox . '/tests/integration/AnyTest.php', "<?php\n\$x = wp_upload_dir( null, false )['basedir'] . '/wpcmine-dir';\n\$y = wp_upload_dir()[ 'basedir' ] . '/other';\n" );
		file_put_contents( $this->sandbox . '/tests/Fixtures/None.php', "<?php\n\$z = WP_CONTENT_DIR . '/notuploads';\n" );
		$this->assertSame( array( 'other', 'wpcmine-dir' ), UploadsLeftovers::names( $this->sandbox ) );
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
			array( PHP_BINARY, dirname( __DIR__, 2 ) . '/Fixtures/uploads-precheck.php', $dir ),
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
