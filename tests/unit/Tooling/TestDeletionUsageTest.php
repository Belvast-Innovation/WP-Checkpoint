<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests delete only through the Deleter or Tests\Fixtures\Sandbox (only under the temporary directory, never
 * following a link): no unlink() or rmdir() of their own, as a call or a callback, and no shell command that removes
 * files. A test whose path is empty or wrong is then refused before anything is touched, wherever it runs.
 */
final class TestDeletionUsageTest extends TestCase {

	/**
	 * Allowed for good: the sandbox helper itself; this test, which names the functions in its fixtures.
	 */
	const EXEMPT = array(
		'tests/Fixtures/Sandbox.php',
		'tests/unit/Tooling/TestDeletionUsageTest.php',
	);

	/**
	 * Files still deleting on their own, with how many places: a file's number must be what the scan finds, so the
	 * list can only shrink (a file changed to use the Sandbox or the Deleter lowers or loses its entry); a new file,
	 * or a new place in a listed one, uses the Sandbox or the Deleter instead.
	 */
	const PENDING = array(
		'tests/Fixtures/Archive/ArchiveBuilder.php' => 2,
		'tests/Fixtures/Files/Layouts.php' => 3,
		'tests/Fixtures/Files/scan-junction-root.php' => 9,
		'tests/Fixtures/Files/scan-links.php' => 6,
		'tests/Fixtures/Jobs/JobTestCase.php' => 1,
		'tests/Fixtures/Jobs/WorkContext.php' => 1,
		'tests/Fixtures/Junction.php' => 1,
		'tests/Fixtures/Permissions.php' => 1,
		'tests/Fixtures/Support/CountingStream.php' => 1,
		'tests/acceptance/acceptance.php' => 4,
		'tests/acceptance/generate.php' => 1,
		'tests/acceptance/manual-restore/build.php' => 1,
		'tests/acceptance/mu-plugin/wpcheckpoint-acceptance-probe.php' => 1,
		'tests/extractors/check.php' => 2,
		'tests/integration/AskStepTest.php' => 1,
		'tests/integration/BackupsControllerTest.php' => 2,
		'tests/integration/BackupsTabTest.php' => 1,
		'tests/integration/CronOnlyTest.php' => 1,
		'tests/integration/DatabaseExportStepTest.php' => 3,
		'tests/integration/DeleterRefusalHandlingTest.php' => 3,
		'tests/integration/DownloadTest.php' => 2,
		'tests/integration/EnvironmentTest.php' => 1,
		'tests/integration/ExportJobTest.php' => 2,
		'tests/integration/ExportPipelineTest.php' => 2,
		'tests/integration/ExportPreflightTest.php' => 2,
		'tests/integration/ExportReplayTest.php' => 4,
		'tests/integration/FileScanStepTest.php' => 4,
		'tests/integration/JobRepositoryTest.php' => 2,
		'tests/integration/JobsControllerTest.php' => 2,
		'tests/integration/NoBudgetLeftTest.php' => 1,
		'tests/integration/ReclaimTest.php' => 6,
		'tests/integration/RestoreFilesPreflightTest.php' => 2,
		'tests/integration/RestoreOwnTablesTest.php' => 1,
		'tests/integration/RestoreStagingTest.php' => 11,
		'tests/integration/RestoreStorageTest.php' => 4,
		'tests/integration/RunnerTest.php' => 1,
		'tests/integration/SchemaColumnsTest.php' => 1,
		'tests/integration/StagingResidueTest.php' => 2,
		'tests/integration/StandaloneConfigHttpTest.php' => 1,
		'tests/integration/StorageTest.php' => 3,
		'tests/integration/SwapCheckTest.php' => 4,
		'tests/integration/SymlinkAbspathTest.php' => 7,
		'tests/integration/ToolsTabTest.php' => 1,
		'tests/integration/UninstallSettingTest.php' => 1,
		'tests/unit/Acceptance/ProbeTest.php' => 2,
		'tests/unit/Archive/ArchiveVerifierTest.php' => 7,
		'tests/unit/Archive/ChunkHasherTest.php' => 2,
		'tests/unit/Archive/IndexAuditTest.php' => 1,
		'tests/unit/Archive/LayoutWalkLimitTest.php' => 2,
		'tests/unit/Archive/LocalHeaderCheckTest.php' => 1,
		'tests/unit/Archive/PackerTest.php' => 3,
		'tests/unit/Archive/ZipReaderTest.php' => 1,
		'tests/unit/Backups/BackupStoreTest.php' => 1,
		'tests/unit/Database/RowSizeCheckTest.php' => 2,
		'tests/unit/Database/TableExporterTest.php' => 1,
		'tests/unit/Files/FileScannerJunctionRootTest.php' => 3,
		'tests/unit/Files/FileScannerLinksTest.php' => 1,
		'tests/unit/Files/FileScannerTest.php' => 2,
		'tests/unit/Jobs/AtomicWriteTest.php' => 2,
		'tests/unit/Jobs/ExportPlanTest.php' => 1,
		'tests/unit/Jobs/JobContextTest.php' => 1,
		'tests/unit/Jobs/LockFileTest.php' => 3,
		'tests/unit/Jobs/LogTailTest.php' => 1,
		'tests/unit/Jobs/PackStepTest.php' => 8,
		'tests/unit/Jobs/ResidueTest.php' => 1,
		'tests/unit/Jobs/ReviewStepTest.php' => 1,
		'tests/unit/Jobs/StoreStepTest.php' => 2,
		'tests/unit/Jobs/VerifyStepTest.php' => 5,
		'tests/unit/Jobs/WorkLostTest.php' => 6,
		'tests/unit/PHPStan/RulesTest.php' => 2,
		'tests/unit/Restore/ChunkHashesTest.php' => 1,
		'tests/unit/Restore/ChunkReaderTest.php' => 1,
		'tests/unit/Restore/DirectoryProbeTest.php' => 9,
		'tests/unit/Restore/LoaderProbeTest.php' => 6,
		'tests/unit/Restore/StagedWriterTest.php' => 2,
		'tests/unit/Standalone/ConfigLoaderHttpTest.php' => 3,
		'tests/unit/Standalone/ConfigLoaderTest.php' => 4,
		'tests/unit/Support/AtomicFileTest.php' => 6,
		'tests/unit/Support/DeleterTest.php' => 5,
		'tests/unit/Support/FileStreamerTest.php' => 1,
		'tests/unit/Support/HostFunctionsTest.php' => 2,
		'tests/unit/Support/LoggerTest.php' => 2,
		'tests/unit/Support/MemoryBudgetTest.php' => 1,
		'tests/unit/Support/PathsTest.php' => 5,
		'tests/unit/Support/StorageLocationTest.php' => 3,
	);

	/**
	 * Every PHP file under tests/.
	 *
	 * @return array<string, string> Path relative to the plugin root => absolute path.
	 */
	private static function test_files(): array {
		$root  = dirname( __DIR__, 3 );
		$files = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/tests', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[ str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) ) ] = $file->getPathname();
			}
		}
		ksort( $files );
		return $files;
	}

	/**
	 * The places PHP code deletes on its own: a call to unlink() or rmdir() (not a method of that name), either named
	 * as a callback, and a string holding a shell command that removes (rm -r, rm -f, rmdir /s, rd /s, del).
	 * Comments do not count.
	 *
	 * @param string $code PHP code.
	 * @return string[] What was found, one per place.
	 */
	public static function deletions( string $code ): array {
		$found  = array();
		$tokens = array_values(
			array_filter(
				token_get_all( $code ),
				static function ( $token ): bool {
					return ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
				}
			)
		);
		$names  = array( T_STRING );
		if ( defined( 'T_NAME_FULLY_QUALIFIED' ) ) {
			$names[] = T_NAME_FULLY_QUALIFIED; // "\\unlink" is one token from PHP 8 on.
		}
		foreach ( $tokens as $i => $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}
			if ( in_array( $token[0], $names, true ) && in_array( strtolower( ltrim( $token[1], '\\' ) ), array( 'unlink', 'rmdir' ), true ) ) {
				$at     = $i - 1;
				$before = $tokens[ $at ] ?? null;
				if ( is_array( $before ) && T_NS_SEPARATOR === $before[0] ) {
					$before = $tokens[ --$at ] ?? null; // "\\unlink" before PHP 8.
				}
				$after  = $tokens[ $i + 1 ] ?? null;
				$method = is_array( $before ) && in_array( $before[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true );
				// "use function unlink as remove;" imports it under another name: that counts.
				$import = is_array( $before ) && T_FUNCTION === $before[0] && is_array( $tokens[ $at - 1 ] ?? null ) && T_USE === $tokens[ $at - 1 ][0];
				if ( $import ) {
					$found[] = 'use function ' . $token[1];
					continue;
				}
				if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && is_array( $before ) && T_NULLSAFE_OBJECT_OPERATOR === $before[0] ) {
					$method = true;
				}
				if ( ! $method && '(' === $after ) {
					$found[] = $token[1] . '()';
				}
				continue;
			}
			if ( in_array( $token[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) ) {
				$text = trim( $token[1], '\'"' );
				if ( in_array( strtolower( ltrim( $text, '\\' ) ), array( 'unlink', 'rmdir' ), true ) ) {
					$found[] = "'" . $text . "' as a callback";
				} elseif ( 1 === preg_match( '#(?:\A|[\s;&|(\'"])(?:rm\s+-[A-Za-z]*[rRf]|rm\s+--(?:recursive|force)|rmdir\s+/s|rd\s+/s|del\s+/[fsq])|(?:\A|\s)-delete\b#i', $text ) ) {
					$found[] = 'shell: ' . $text;
				}
			}
		}
		return $found;
	}

	public function test_tests_delete_only_through_the_sandbox_or_the_deleter(): void {
		$counts = array();
		foreach ( self::test_files() as $relative => $path ) {
			if ( in_array( $relative, self::EXEMPT, true ) ) {
				continue;
			}
			$found = self::deletions( (string) file_get_contents( $path ) );
			if ( array() !== $found ) {
				$counts[ $relative ] = count( $found );
			}
			$this->assertSame( self::PENDING[ $relative ] ?? 0, count( $found ), "{$relative}: delete through Tests\\Fixtures\\Sandbox::remove() or the Deleter (found: " . ( array() === $found ? 'none' : implode( ', ', array_unique( $found ) ) ) . '); a file on the PENDING list lowers its number as it is changed' );
		}
		$this->assertSame( self::PENDING, $counts, 'the PENDING list is what the scan finds' );
	}

	public function test_the_scan_finds_what_it_looks_for(): void {
		$code = <<<'PHP'
<?php
unlink( $a );
@unlink( $a );
\unlink( $a );
rmdir( $b );
array_map( 'unlink', $files );
$f = "rmdir";
shell_exec( 'rm -rf ' . escapeshellarg( $dir ) );
exec( "rm -r {$dir}" );
exec( 'cmd /c rmdir /s /q ' . $dir );
exec( 'cmd /c rd /S ' . $dir );
exec( 'del /f ' . $file );
exec( 'rm -f x' );
exec( 'rm --recursive ' . $dir );
exec( "find {$dir} -type f -delete" );
use function unlink as remove_file;
use function \\rmdir as remove_dir;
PHP;
		$this->assertCount( 16, self::deletions( $code ), implode( ' | ', self::deletions( $code ) ) );
		$code = <<<'PHP'
<?php
// unlink( $a ) in a comment
/* rmdir( $b ) in another */
Sandbox::remove( $a );
$fs->rmdir( $b );
$fs?->unlink( $b );
Deleter::delete_tree( $a, $b );
function unlink_all() {}
$this->unlinked = true;
$s = 'the file was unlinked';
$t = 'form -rf';
$u = 'arm -r';
$v = 'rm is not run';
$w = 'find the file, then delete it';
use function Sandbox\\remove;
PHP;
		$this->assertSame( array(), self::deletions( $code ) );
		$files = self::test_files();
		foreach ( array_merge( self::EXEMPT, array_keys( self::PENDING ) ) as $relative ) {
			$this->assertArrayHasKey( $relative, $files, 'listed and there' );
		}
		$this->assertNotSame( array(), self::deletions( (string) file_get_contents( $files['tests/Fixtures/Sandbox.php'] ) ), 'the control: the scan finds the deleting the helper does' );
	}
}
