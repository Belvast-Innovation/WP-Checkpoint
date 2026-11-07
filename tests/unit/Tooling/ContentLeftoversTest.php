<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use WPCheckpoint\Restore\StagingLayout;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Tests\Fixtures\ContentLeftovers;
use WPCheckpoint\Tests\Fixtures\ExpectedPath;
use WPCheckpoint\Tests\Fixtures\ProbeDir;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The wp-content entries the leftover check looks at are every top-level one but those registered by name (with why);
 * the must-use plugins directory's are all looked at; a restore's loader probe there, named as the product names it,
 * stops a run before it starts (leftovers-precheck.php, status 77).
 */
final class ContentLeftoversTest extends TestCase {

	/**
	 * The sandbox.
	 *
	 * @var string
	 */
	private $sandbox = '';

	public function set_up(): void {
		parent::set_up();
		$this->sandbox = Sandbox::make( 'content-leftovers' );
	}

	public function tear_down(): void {
		if ( '' !== $this->sandbox ) {
			Sandbox::remove( $this->sandbox );
		}
		parent::tear_down();
	}

	public function test_every_registered_name_says_why(): void {
		foreach ( array_merge( ContentLeftovers::OWN, ContentLeftovers::OWN_PREFIXES ) as $name => $why ) {
			$this->assertNotSame( '', trim( (string) $why ), (string) $name );
		}
		$this->assertArrayHasKey( Directories::DIR_PREFIX, ContentLeftovers::OWN_PREFIXES );
		$this->assertArrayHasKey( ProbeDir::PREFIX, ContentLeftovers::OWN_PREFIXES );
	}

	public function test_the_entries_are_all_but_the_registered(): void {
		foreach ( array( 'plugins', 'themes', 'uploads', 'mu-plugins', 'upgrade', Directories::DIR_PREFIX . 'abc', ProbeDir::PREFIX . 'run', 'wpc-uploads-abc', 'languages' ) as $entry ) {
			mkdir( $this->sandbox . '/' . $entry );
		}
		file_put_contents( $this->sandbox . '/index.php', '<?php' );
		$this->assertSame(
			array( ExpectedPath::slashed( $this->sandbox, 'languages' ), ExpectedPath::slashed( $this->sandbox, 'wpc-uploads-abc' ) ),
			ContentLeftovers::entries( $this->sandbox ),
			'a test\'s wpc-* directory, and an entry nobody registered'
		);
	}

	/**
	 * A loader probe's name, as a restore makes it.
	 *
	 * @return string
	 */
	private static function probe_name(): string {
		$groups = array();
		foreach ( StagingLayout::GROUPS as $group ) {
			$groups[ $group ] = '/site/wp-content/' . $group;
		}
		return ( new StagingLayout( $groups, 'abcdef012345', 7, str_repeat( 'a', 32 ) ) )->probe_name( '.php' );
	}

	public function test_a_loader_probe_is_known_by_the_products_name_for_it(): void {
		file_put_contents( $this->sandbox . '/' . self::probe_name(), '<?php' );
		file_put_contents( $this->sandbox . '/someone-elses.php', '<?php' );
		$this->assertCount( 2, ContentLeftovers::mu_entries( $this->sandbox ), 'every entry of the must-use plugins directory is looked at' );
		$this->assertSame( array( ExpectedPath::slashed( $this->sandbox, self::probe_name_in( $this->sandbox ) ) ), ContentLeftovers::probes( $this->sandbox ) );
		$this->assertFalse( ContentLeftovers::is_probe( 'wp-checkpoint-probe-notours.php' ) );
	}

	/**
	 * The probe's name in a directory.
	 *
	 * @param string $dir Directory.
	 * @return string
	 */
	private static function probe_name_in( string $dir ): string {
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( 0 === strpos( (string) $entry, StagingLayout::PROBE_PREFIX ) ) {
				return (string) $entry;
			}
		}
		return '';
	}

	public function test_a_loader_probe_left_stops_the_run_listed(): void {
		mkdir( $this->sandbox . '/uploads' );
		mkdir( $this->sandbox . '/mu-plugins' );
		file_put_contents( $this->sandbox . '/mu-plugins/someone-elses.php', '<?php' );
		$run = function (): array {
			$pipes   = array();
			$process = proc_open( array( PHP_BINARY, dirname( __DIR__, 2 ) . '/Fixtures/leftovers-precheck.php', $this->sandbox . '/uploads', $this->sandbox . '/mu-plugins' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			stream_get_contents( $pipes[1] );
			$err = (string) stream_get_contents( $pipes[2] );
			return array( proc_close( $process ), $err );
		};
		list( $status, $err ) = $run();
		$this->assertSame( 0, $status, 'the control: a must-use plugin of someone else\'s does not stop it: ' . $err );
		file_put_contents( $this->sandbox . '/mu-plugins/' . self::probe_name(), '<?php' );
		list( $status, $err ) = $run();
		$this->assertSame( 77, $status, $err );
		$this->assertStringContainsString( StagingLayout::PROBE_PREFIX, $err );
		$this->assertStringNotContainsString( 'someone-elses', $err );
	}
}
