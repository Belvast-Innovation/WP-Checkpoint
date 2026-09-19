<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\Residue;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Everything the engine leaves under the storage tmp/ directory or in the
 * database must be registered in Residue::KINDS with an orphan rule, or
 * the reaper never learns about it. This test turns "forgot to register
 * it" into a red build: a source file that builds a tmp/ path or a
 * temporary table name must be one of the files Residue::SOURCES names.
 */
final class ResidueUsageTest extends TestCase {

	/**
	 * Every shipped PHP file: src/ plus the two root entry files.
	 *
	 * @return array<string, string> Path relative to the plugin root => absolute path.
	 */
	private function source_files(): array {
		$root  = dirname( __DIR__, 3 );
		$files = array(
			'wp-checkpoint.php' => $root . '/wp-checkpoint.php',
			'uninstall.php'     => $root . '/uninstall.php',
		);
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[ str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) ) ] = $file->getPathname();
			}
		}
		ksort( $files );
		return $files;
	}

	public function test_tmp_paths_and_temporary_table_names_are_only_built_by_registered_sources(): void {
		$patterns = array(
			"'tmp'"      => 'a tmp/ path segment',
			"'tmp/"      => 'a tmp/ path',
			'/tmp/'      => 'a tmp/ path',
			"'wcptmp"    => 'a temporary table prefix',
			"'verify-'"  => 'a verification directory name',
			"'.partial'" => 'a packer partial file suffix',
			"'.cdr'"     => 'a packer record file suffix',
		);
		foreach ( $this->source_files() as $relative => $path ) {
			$source = (string) file_get_contents( $path );
			// Comments may mention any of these; only code counts.
			$code = (string) preg_replace( '#(/\*.*?\*/)|(//[^\n]*)#s', '', $source );
			foreach ( $patterns as $needle => $meaning ) {
				if ( false === strpos( $code, $needle ) ) {
					continue;
				}
				$this->assertContains( $relative, array_merge( Residue::SOURCES, self::PACKER_SOURCES ), "{$relative} builds {$meaning} ({$needle}); register the leftover it can create in Residue::KINDS and the file in Residue::SOURCES" );
			}
		}
	}

	/**
	 * The packer names its own in-progress files; they live inside a work
	 * directory and are reaped with it (Residue::WORK_DIR).
	 */
	const PACKER_SOURCES = array( 'src/Archive/Packer.php' );

	public function test_the_catalogue_is_complete_and_its_sources_exist(): void {
		$files = $this->source_files();
		foreach ( Residue::SOURCES as $relative ) {
			$this->assertArrayHasKey( $relative, $files, "Residue::SOURCES names a file that does not exist: {$relative}" );
		}
		$this->assertSame( array( Residue::WORK_DIR, Residue::TEMP_TABLE, Residue::VERIFY_DIR, Residue::STRAY ), Residue::KINDS );
		$this->assertArrayHasKey( 'src/Jobs/JobContext.php', $files );
		$this->assertStringContainsString( 'Residue::work_dir(', (string) file_get_contents( $files['src/Jobs/JobContext.php'] ), 'the work path comes from the catalogue' );
		$this->assertStringContainsString( 'Residue::new_verify_dir(', (string) file_get_contents( $files['src/Cli/VerifyCommand.php'] ) );
	}
}
