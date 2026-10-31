<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use WPCheckpoint\Jobs\RestoreFilesPreflightStep;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * tests/Fixtures/plugin-entries.php, as bin/test-integration.sh runs it before a run: the entries of a directory,
 * counted as the restore counts its own copy (a link counted, not followed), said, and a warning from 90% of
 * RestoreFilesPreflightStep::MAX_PLUGIN_ENTRIES on.
 */
final class PluginEntriesTest extends TestCase {

	/** @var string */
	private $sandbox = '';

	protected function set_up(): void {
		parent::set_up();
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'The integration suite, and this count before it, run in the wp-env container.' );
		}
		$this->sandbox = Sandbox::make( 'plugin-entries' );
	}

	protected function tear_down(): void {
		if ( '' !== $this->sandbox ) {
			Sandbox::remove( $this->sandbox );
		}
		parent::tear_down();
	}

	/**
	 * Run the count.
	 *
	 * @param string[] $args Arguments.
	 * @return array{code: int, stderr: string}
	 */
	private function entries( array $args ): array {
		$pipes   = array();
		$process = proc_open( array_merge( array( PHP_BINARY, dirname( __DIR__, 2 ) . '/Fixtures/plugin-entries.php' ), $args ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $this->sandbox, array( 'PATH' => (string) getenv( 'PATH' ) ) );
		$this->assertIsResource( $process );
		stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return array(
			'code'   => proc_close( $process ),
			'stderr' => $stderr,
		);
	}

	/**
	 * Add empty files to a directory.
	 */
	private static function files( string $dir, int $from, int $count ): void {
		for ( $i = $from; $i < $from + $count; $i++ ) {
			touch( $dir . '/f' . $i );
		}
	}

	public function test_the_entries_are_said_and_a_warning_comes_from_ninety_percent_of_the_limit(): void {
		$limit = RestoreFilesPreflightStep::MAX_PLUGIN_ENTRIES;
		$warn  = (int) ceil( $limit * 0.9 );
		$root  = $this->sandbox . '/plugin';
		mkdir( $root . '/a/b', 0755, true );
		mkdir( $this->sandbox . '/outside' );
		self::files( $this->sandbox . '/outside', 0, 50 );
		symlink( $this->sandbox . '/outside', $root . '/link' ); // One entry: what it points to is not counted.
		// a, a/b, link: 3 entries; files in a/b up to one short of the warning.
		self::files( $root . '/a/b', 0, $warn - 4 );
		$out = $this->entries( array( $root ) );
		$this->assertSame( 0, $out['code'], $out['stderr'] );
		$this->assertStringContainsString( sprintf( 'The plugin directory holds %d entries; a restore takes at most %d', $warn - 1, $limit ), $out['stderr'] );
		$this->assertStringNotContainsString( 'Warning', $out['stderr'] );

		self::files( $root . '/a', 0, 1 );
		$out = $this->entries( array( $root ) );
		$this->assertSame( 0, $out['code'] );
		$this->assertStringContainsString( sprintf( 'Warning: The plugin directory holds %d entries', $warn ), $out['stderr'] );
		$this->assertStringContainsString( 'It is close to that', $out['stderr'] );

		self::files( $root . '/a', 1, $limit - $warn + 1 );
		$out = $this->entries( array( $root ) );
		$this->assertSame( 0, $out['code'], 'it only says' );
		$this->assertStringContainsString( sprintf( 'holds %d entries', $limit + 1 ), $out['stderr'] );
		$this->assertStringContainsString( 'It is past that', $out['stderr'] );
	}

	public function test_without_a_directory_it_says_how_to_use_it(): void {
		$out = $this->entries( array() );
		$this->assertSame( 1, $out['code'] );
		$this->assertStringContainsString( 'Usage: php plugin-entries.php <plugin directory>', $out['stderr'] );
		$this->assertSame( 1, $this->entries( array( $this->sandbox . '/none' ) )['code'] );
	}
}
