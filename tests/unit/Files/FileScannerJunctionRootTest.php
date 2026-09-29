<?php

namespace WPCheckpoint\Tests\Unit\Files;

use WPCheckpoint\Files\Links;
use WPCheckpoint\Support\Paths;
use WPCheckpoint\Tests\Fixtures\Junction;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Windows: a content root that is a junction on a host without readlink(), and the spellings of one directory
 * (letter case, drive letter, 8.3 short names) that the root rules compare.
 */
final class FileScannerJunctionRootTest extends TestCase {

	/** @var string */
	private $base = '';

	protected function set_up(): void {
		parent::set_up();
		if ( 'Windows' !== PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'Windows only: junctions, drive letters and 8.3 short names.' );
		}
		$this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wpcheckpoint-jroot-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->base, 0700 );
	}

	protected function tear_down(): void {
		if ( '' !== $this->base && is_dir( $this->base ) ) {
			@rmdir( $this->base ); // Each fixture and test takes its own entries apart by name.
		}
		parent::tear_down();
	}

	/**
	 * Run the fixture without readlink().
	 *
	 * @return array<string, mixed>
	 */
	private function scan( string $mode ): array {
		$proc = proc_open(
			array( PHP_BINARY, '-d', 'disable_functions=readlink', dirname( __DIR__, 2 ) . '/Fixtures/Files/scan-junction-root.php', $this->base, $mode ),
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
		$this->assertFalse( $result['readlink'], 'the switch took effect: readlink() is disabled' );
		return $result;
	}

	public function test_a_junction_root_without_readlink_backs_up_every_file_by_comparing_with_the_resolved_root(): void {
		$every = array( 'wp-content/uploads/a.txt', 'wp-content/uploads/broken/b.txt', 'wp-content/uploads/first/c.txt' );

		// The control: compared with the junction's own path, the directories that can only be judged by where they
		// resolve would all be "elsewhere" and left out. The assertion below would catch that.
		$listed = $this->scan( 'listed' );
		$this->assertNotEquals( $every, $listed['lines'], 'with the link path as the base, files are lost' );
		$this->assertNotContains( 'wp-content/uploads/broken/b.txt', $listed['lines'] );
		$this->assertNotContains( 'wp-content/uploads/first/c.txt', $listed['lines'] );

		// As the scanner does it: compared with the resolved root, every file of the root is backed up, and nothing
		// behind the junctions inside it.
		$real = $this->scan( 'real' );
		$this->assertSame( $every, $real['lines'] );
		$this->assertGreaterThan( 0, $real['undecided'], 'the fallback was used' );
		$this->assertSame( 1, $real['links'], 'the working junction inside is still a link' );
	}

	public function test_one_directory_in_another_letter_case_drive_letter_or_short_name_is_the_same_key(): void {
		$dir = $this->base . DIRECTORY_SEPARATOR . 'Some Dir';
		mkdir( $dir );
		try {
			// Letter case and the drive letter.
			$lower = strtolower( $dir );
			$drive = strtolower( substr( $dir, 0, 1 ) ) . substr( $dir, 1 );
			$this->assertNotSame( $dir, $lower, 'the control: another spelling' );
			$this->assertSame( Links::key( $dir ), Links::key( $lower ) );
			$this->assertSame( Links::key( $dir ), Links::key( $drive ) );
			$this->assertSame( Links::key( $dir ), Links::key( str_replace( '\\', '/', $dir ) ) );
			$this->assertTrue( Paths::is_same_or_inside( $lower, $dir . DIRECTORY_SEPARATOR ) );

			// 8.3 short names: the temporary directory of the CI runner is spelled with one (RUNNER~1). A junction made
			// with that spelling resolves to it, where the directory itself resolves to its long names.
			if ( false === strpos( $this->base, '~' ) ) {
				$this->markTestIncomplete( 'No 8.3 short name in the temporary directory here (' . $this->base . '): the short-name half was not checked.' );
			}
			$long = (string) Paths::real( $dir );
			$link = $this->base . DIRECTORY_SEPARATOR . 'short-link';
			Junction::make( $dir, $link );
			try {
				$raw = (string) realpath( $link );
				$this->assertStringContainsString( '~', $raw, 'the control: realpath() of the junction keeps the short name' );
				$this->assertNotSame( strtolower( $raw ), strtolower( $long ), 'the control: two spellings of one directory' );
				$this->assertSame( Links::key( $long ), Links::key( $link ), 'one key for both' );
				$this->assertTrue( Paths::is_same_or_inside( $long, $link ) );
				$this->assertSame( Links::fingerprint( $long ), Links::fingerprint( $link ) );
				// Where it matters: a junction spelled short that leads to the site is recognised as leading there.
				mkdir( $dir . DIRECTORY_SEPARATOR . 'site' );
				$this->assertSame( Links::HOLDS_SITE, Links::root_refusal( $link, $long . DIRECTORY_SEPARATOR . 'site' ) );
				rmdir( $dir . DIRECTORY_SEPARATOR . 'site' );
			} finally {
				Junction::remove( $link );
			}
		} finally {
			@rmdir( $dir );
		}
	}
}
