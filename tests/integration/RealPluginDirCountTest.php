<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Jobs\RestoreFilesPreflightStep;
use WPCheckpoint\Jobs\SwapCheckStep;

/**
 * The restore's walks of the running copy of this plugin, on the real running directory (WPCHECKPOINT_DIR), checked
 * against a count made independently (RecursiveDirectoryIterator, a link counted and not followed): the files
 * preflight's bytes and the final check's list of files. Every other test's restores read a stand-in (PluginStandIn);
 * no restore runs here. On the test site the directory is the working copy: past
 * RestoreFilesPreflightStep::MAX_PLUGIN_ENTRIES entries this fails, and bin/test-integration.sh warns before a run
 * (tests/Fixtures/plugin-entries.php).
 */
final class RealPluginDirCountTest extends WP_UnitTestCase {

	/** Declared to PluginStandIn: why this class reads the real running directory. */
	const USES_REAL_PLUGIN_DIR = 'it checks the restore\'s walks of the running plugin against an independent count, and runs no restore';

	/**
	 * The running directory as the restore takes it.
	 *
	 * @return string
	 */
	private static function root(): string {
		return rtrim( WPCHECKPOINT_DIR, '/\\' );
	}

	/**
	 * Counted independently: every entry (a link counted, not followed), the bytes and the sorted paths of the files.
	 *
	 * @return array{entries: int, bytes: float, files: string[], links: int}
	 */
	private static function independent(): array {
		$root  = self::root();
		$out   = array(
			'entries' => 0,
			'bytes'   => 0.0,
			'files'   => array(),
			'links'   => 0,
		);
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST,
			\RecursiveIteratorIterator::CATCH_GET_CHILD
		);
		foreach ( $items as $path => $item ) {
			++$out['entries'];
			if ( $item->isLink() ) {
				++$out['links'];
				continue;
			}
			if ( $item->isFile() ) {
				$out['files'][] = substr( (string) $path, strlen( $root ) + 1 );
				$out['bytes']  += (float) $item->getSize();
			}
		}
		sort( $out['files'], SORT_STRING );
		return $out;
	}

	public function test_the_restores_walks_of_the_running_plugin_match_an_independent_count(): void {
		$counted = self::independent();
		// The controls: the independent count sees the plugin itself, and directories besides files.
		$this->assertContains( 'wp-checkpoint.php', $counted['files'] );
		$this->assertContains( 'src/Jobs/RestoreFilesPreflightStep.php', $counted['files'] );
		$this->assertGreaterThan( count( $counted['files'] ), $counted['entries'] );
		$this->assertLessThanOrEqual(
			RestoreFilesPreflightStep::MAX_PLUGIN_ENTRIES,
			$counted['entries'],
			sprintf( 'The running plugin directory (%s) holds %d entries, past the restore\'s limit for its own copy: the walks below would refuse it. On the test site it is the working copy: its node_modules, vendor, .git and build count too.', self::root(), $counted['entries'] )
		);

		$bytes = new \ReflectionMethod( RestoreFilesPreflightStep::class, 'plugin_bytes' );
		$bytes->setAccessible( true );
		$this->assertSame( $counted['bytes'], $bytes->invoke( new RestoreFilesPreflightStep() ), sprintf( 'the files preflight\'s bytes (%d entries, %d links)', $counted['entries'], $counted['links'] ) );

		$files = new \ReflectionMethod( SwapCheckStep::class, 'files_under' );
		$files->setAccessible( true );
		$this->assertSame( $counted['files'], $files->invoke( null, self::root(), RestoreFilesPreflightStep::MAX_PLUGIN_ENTRIES ), 'the final check\'s files' );
		$this->assertNull( $files->invoke( null, self::root(), $counted['entries'] - 1 ), 'the control: one entry fewer allowed than counted, and the walk refuses' );
		$this->assertSame( $counted['files'], $files->invoke( null, self::root(), $counted['entries'] ), 'and with exactly as many, it does not' );
	}
}
