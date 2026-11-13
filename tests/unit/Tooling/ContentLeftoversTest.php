<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use WPCheckpoint\Restore\Loader;
use WPCheckpoint\Restore\StagingLayout;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Tests\Fixtures\ContentLeftovers;
use WPCheckpoint\Tests\Fixtures\ExpectedPath;
use WPCheckpoint\Tests\Fixtures\ProbeDir;
use WPCheckpoint\Tests\Fixtures\RealAdvancedCache;
use WPCheckpoint\Tests\Fixtures\RealMuPlugins;
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
		foreach ( array_merge( ContentLeftovers::OWN, ContentLeftovers::OWN_PREFIXES, ContentLeftovers::MAY_BE_ABSENT ) as $name => $why ) {
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

	/**
	 * What is under the loader's name, each stopping the run with nothing removed.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function loaders_left(): array {
		return array(
			'the loader itself'            => array( Loader::NAME, Loader::CODE, "(this plugin's)" ),
			'another file under its name'  => array( Loader::NAME, "<?php // someone else's\n", "(not this plugin's)" ),
			'a temporary name of a write'  => array( Loader::NAME . '.0123456789abcdef.tmp', 'half', "(this plugin's)" ),
		);
	}

	/**
	 * @dataProvider loaders_left
	 */
	public function test_a_file_under_the_loaders_name_stops_the_run_and_nothing_is_removed( string $name, string $contents, string $said ): void {
		mkdir( $this->sandbox . '/uploads' );
		mkdir( $this->sandbox . '/mu-plugins' );
		file_put_contents( $this->sandbox . '/mu-plugins/wpcheckpoint-runtime.php', Loader::CODE ); // Not its name: someone else's.
		$run = function (): array {
			$pipes   = array();
			$process = proc_open( array( PHP_BINARY, dirname( __DIR__, 2 ) . '/Fixtures/leftovers-precheck.php', $this->sandbox . '/uploads', $this->sandbox . '/mu-plugins' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			stream_get_contents( $pipes[1] );
			$err = (string) stream_get_contents( $pipes[2] );
			return array( proc_close( $process ), $err );
		};
		list( $status, $err ) = $run();
		$this->assertSame( 0, $status, 'the control: without it the run starts: ' . $err );
		file_put_contents( $this->sandbox . '/mu-plugins/' . $name, $contents );
		list( $status, $err ) = $run();
		$this->assertSame( 77, $status, $err );
		$this->assertStringContainsString( $name . ' ' . $said, $err );
		$this->assertSame( $contents, file_get_contents( $this->sandbox . '/mu-plugins/' . $name ), 'nothing removed' );
	}

	/**
	 * What is under the page cache drop-in's name, each stopping the run with nothing removed.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function drop_ins_left(): array {
		return array(
			'the tests\' own'              => array( RealAdvancedCache::NAME, RealAdvancedCache::CODE, "(the tests' own)" ),
			'a site\'s page cache'         => array( RealAdvancedCache::NAME, "<?php // someone else's\n", "(not the tests')" ),
			'a temporary name of a write' => array( RealAdvancedCache::NAME . '.0123456789abcdef.tmp', 'half', "(the tests' own)" ),
		);
	}

	/**
	 * @dataProvider drop_ins_left
	 */
	public function test_a_page_cache_drop_in_stops_the_run_and_nothing_is_removed( string $name, string $contents, string $said ): void {
		mkdir( $this->sandbox . '/uploads' );
		mkdir( $this->sandbox . '/mu-plugins' );
		file_put_contents( $this->sandbox . '/object-cache.php', '<?php' ); // Another drop-in: not looked at here.
		$run = function (): array {
			$pipes   = array();
			$process = proc_open( array( PHP_BINARY, dirname( __DIR__, 2 ) . '/Fixtures/leftovers-precheck.php', $this->sandbox . '/uploads', $this->sandbox . '/mu-plugins', $this->sandbox ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			stream_get_contents( $pipes[1] );
			$err = (string) stream_get_contents( $pipes[2] );
			return array( proc_close( $process ), $err );
		};
		list( $status, $err ) = $run();
		$this->assertSame( 0, $status, 'the control: without it the run starts: ' . $err );
		file_put_contents( $this->sandbox . '/' . $name, $contents );
		list( $status, $err ) = $run();
		$this->assertSame( 77, $status, $err );
		$this->assertStringContainsString( $name . ' ' . $said, $err );
		$this->assertSame( $contents, file_get_contents( $this->sandbox . '/' . $name ), 'nothing removed' );
	}

	public function test_only_the_tests_own_page_cache_drop_in_is_cleared_and_only_in_the_directory_given(): void {
		mkdir( $this->sandbox . '/elsewhere' );
		Deleter::allow( $this->sandbox );
		$path = $this->sandbox . '/' . RealAdvancedCache::NAME;
		file_put_contents( $path, "<?php // someone else's\n" );
		$this->assertFalse( RealAdvancedCache::ours( $path ) );
		RealAdvancedCache::clear( $path, $this->sandbox );
		$this->assertFileExists( $path, 'not the tests\' own: kept' );
		file_put_contents( $this->sandbox . '/elsewhere/' . RealAdvancedCache::NAME, RealAdvancedCache::CODE );
		RealAdvancedCache::clear( $this->sandbox . '/elsewhere/' . RealAdvancedCache::NAME, $this->sandbox );
		$this->assertFileExists( $this->sandbox . '/elsewhere/' . RealAdvancedCache::NAME, 'not in the directory: kept' );
		file_put_contents( $path, RealAdvancedCache::CODE );
		file_put_contents( $path . '.0123456789abcdef.tmp', 'half' );
		$this->assertTrue( RealAdvancedCache::ours( $path ) );
		foreach ( RealAdvancedCache::listing( $this->sandbox ) as $item ) {
			RealAdvancedCache::clear( $item, $this->sandbox );
		}
		$this->assertSame( array(), RealAdvancedCache::listing( $this->sandbox ), 'the control: the clearing removes' );
		$this->assertFalse( RealAdvancedCache::is_name( 'advanced-cache.php.bak' ) );
		$this->assertStringContainsString( RealAdvancedCache::PAGE, RealAdvancedCache::CODE );
	}

	public function test_only_a_loader_this_plugin_wrote_is_cleared_and_only_in_the_directory_given(): void {
		$mu = $this->sandbox . '/mu-plugins';
		mkdir( $mu );
		mkdir( $this->sandbox . '/elsewhere' );
		Deleter::allow( $this->sandbox );
		$path = $mu . '/' . Loader::NAME;
		// Someone else's file under the name: listed, said, kept.
		file_put_contents( $path, "<?php // someone else's\n" );
		$this->assertSame( array( ExpectedPath::slashed( $mu, Loader::NAME ) ), RealMuPlugins::listing( $mu ) );
		$this->assertFalse( RealMuPlugins::ours( $path ) );
		RealMuPlugins::clear( $path, $mu );
		$this->assertFileExists( $path, 'not this plugin\'s: kept' );
		// The loader in another directory than the one given: kept.
		file_put_contents( $this->sandbox . '/elsewhere/' . Loader::NAME, Loader::CODE );
		RealMuPlugins::clear( $this->sandbox . '/elsewhere/' . Loader::NAME, $mu );
		$this->assertFileExists( $this->sandbox . '/elsewhere/' . Loader::NAME, 'not in the directory: kept' );
		// The loader, and a temporary name: removed (the control: the clearing removes).
		file_put_contents( $path, Loader::CODE );
		file_put_contents( $path . '.0123456789abcdef.tmp', 'half' );
		$this->assertTrue( RealMuPlugins::ours( $path ) );
		foreach ( RealMuPlugins::listing( $mu ) as $item ) {
			RealMuPlugins::clear( $item, $mu );
		}
		$this->assertSame( array(), RealMuPlugins::listing( $mu ) );
		$this->assertFalse( RealMuPlugins::is_loader( 'wpcheckpoint-runtime.php' ) );
	}
}
