<?php

namespace WPCheckpoint\Tests\Unit\Files;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The link rules of the scan on a real host without readlink(): a PHP
 * process of its own, started with and without -d disable_functions,
 * scans a content root that is a link (a junction on Windows) to a
 * directory holding a file and an empty directory.
 */
final class FileScannerLinksTest extends TestCase {

	/** @var string */
	private $base = '';

	protected function set_up(): void {
		parent::set_up();
		$this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wpcheckpoint-links-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->base, 0700 );
	}

	protected function tear_down(): void {
		if ( '' !== $this->base && is_dir( $this->base ) ) {
			rmdir( $this->base ); // The fixture takes its sandbox apart by name; what is left here is its own directory.
		}
		parent::tear_down();
	}

	/**
	 * Run the fixture; returns its JSON output.
	 *
	 * @param string $disabled disable_functions for the process.
	 * @return array<string, mixed>
	 */
	private function scan( string $disabled ): array {
		$proc = proc_open(
			array( PHP_BINARY, '-d', 'disable_functions=' . $disabled, dirname( __DIR__, 2 ) . '/Fixtures/Files/scan-links.php', $this->base ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			null,
			array(
				'WPCHECKPOINT_TEST_SUITE' => 'unit',
				'PATH'                    => (string) getenv( 'PATH' ),
				'SystemRoot'              => (string) getenv( 'SystemRoot' ),
			)
		);
		$this->assertIsResource( $proc );
		$out  = stream_get_contents( $pipes[1] );
		$err  = stream_get_contents( $pipes[2] );
		$code = proc_close( $proc );
		$this->assertSame( 0, $code, $out . $err );
		$result = json_decode( (string) $out, true );
		$this->assertIsArray( $result, $out . $err );
		return $result;
	}

	public function test_a_root_that_is_a_link_is_followed_and_what_cannot_be_decided_without_readlink_is_left_out_and_listed(): void {
		$with = $this->scan( '' );
		$this->assertTrue( $with['readlink'], 'the control: readlink() is there in the first run' );
		$this->assertSame( array( 'wp-content/uploads/photo.jpg' ), $with['lines'], 'the root that is a link is followed' );
		$this->assertSame( 0, $with['undecided'], 'with readlink() every directory is decided' );
		$this->assertSame( array(), $with['unreadable'] );

		$without = $this->scan( 'readlink' );
		$this->assertFalse( $without['readlink'], 'the switch took effect: readlink() is disabled in the second run' );
		$this->assertSame( array( 'wp-content/uploads/photo.jpg' ), $without['lines'], 'a link with something in it is still recognised, and followed at the root' );
		if ( 'Windows' === PHP_OS_FAMILY ) {
			// A junction is told apart through a child; an empty directory has none to probe through.
			$this->assertSame( 1, $without['undecided'] );
			$this->assertSame( array( 'wp-content/uploads/empty' ), $without['unreadable'], 'left out, and listed for the pre-flight to ask about' );
		} else {
			// is_link() answers on POSIX: nothing depends on readlink().
			$this->assertSame( 0, $without['undecided'] );
			$this->assertSame( array(), $without['unreadable'] );
		}
	}
}
