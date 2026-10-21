<?php
/**
 * Extract archives written by the plugin with other people's tools and
 * check what comes out: names byte for byte (non-ASCII included), content,
 * and on Unix that files are readable and not executable and directories
 * traversable. Only a real reader can tell whether a reader decodes the
 * names the way the writer meant; the plugin's own reader cannot.
 *
 * Usage: php tests/extractors/check.php TOOL [TOOL ...]
 * Tools: unzip-c-utf8, unzip-no-locale, 7z, bsdtar, python, ditto, expand-archive.
 * A tool that cannot be run is a failure: CI names the tools each runner has.
 *
 * @package WPCheckpoint
 */

// phpcs:ignoreFile -- development tooling, not part of the plugin.

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

use WPCheckpoint\Archive\Crc32;
use WPCheckpoint\Archive\ZipFormat;
use WPCheckpoint\Tests\Fixtures\Archive\ArchiveBuilder;

$tools = array_slice( $argv, 1 );
if ( array() === $tools ) {
	fwrite( STDERR, "Usage: php tests/extractors/check.php TOOL [TOOL ...]\n" );
	exit( 2 );
}
$windows = '\\' === DIRECTORY_SEPARATOR;

// Names a site can have; each is an NFC string (what the scanner stores).
$names = array(
	'plain.txt',
	'with space (1).txt',
	'café-ü.txt',
	'Ελληνικά.txt',
	'日本語のファイル.txt',
	'emoji-😀.txt',
	'nested/deeper/ключ.txt',
);

// 1. An archive through the real packer.
$builder = new ArchiveBuilder();
foreach ( $names as $name ) {
	$builder->file( 'wp-content/uploads/names/' . $name, 'content of ' . $name . "\n" );
}
$builder->table( 'wp_options', array( ArchiveBuilder::noise( 100 ) ) );
$builder->build();
$volumes = glob( dirname( $builder->manifest_path ) . '/*.wpcheckpoint.zip' );
if ( 1 !== count( $volumes ) ) {
	fwrite( STDERR, "Expected one volume.\n" );
	exit( 1 );
}

// 2. A directory entry, which the packer does not write today: built from the same encoders.
$root    = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wpcheckpoint-extractors-' . bin2hex( random_bytes( 4 ) );
mkdir( $root, 0700 );
$crafted = $root . DIRECTORY_SEPARATOR . 'directory.wpcheckpoint.zip';
$body    = '';
$central = '';
$entries = array(
	'files/empty-dir/'        => '',
	'files/empty-dir/x.txt'   => "inside\n",
);
foreach ( $entries as $name => $content ) {
	$offset   = strlen( $body );
	$crc      = Crc32::of( $content );
	$body    .= ZipFormat::local_header( $name, ZipFormat::METHOD_STORE, 1758196800, $crc, strlen( $content ), strlen( $content ), false ) . $content;
	$central .= ZipFormat::central_header( array( 'name' => $name, 'method' => ZipFormat::METHOD_STORE, 'mtime' => 1758196800, 'crc' => $crc, 'csize' => strlen( $content ), 'usize' => strlen( $content ), 'offset' => $offset ) );
}
file_put_contents( $crafted, $body . $central . ZipFormat::end_of_central_directory( count( $entries ), strlen( $central ), strlen( $body ) ) );

// 3. The same packer archive as the writer made it before host 3: host 0 (MS-DOS), no attributes. Informational:
// it shows which reader behaved differently before, so a change in a reader's result is attributed to the host.
$legacy = $root . DIRECTORY_SEPARATOR . 'legacy.wpcheckpoint.zip';
$bytes  = (string) file_get_contents( $volumes[0] );
$end    = ZipFormat::parse_end( substr( $bytes, -66000 ), max( 0, strlen( $bytes ) - 66000 ) );
for ( $at = (int) $end['cd_offset'], $n = 0; $n < (int) $end['entries']; $n++ ) {
	$header = ZipFormat::parse_central_header( substr( $bytes, $at, 65536 + 46 ) );
	$bytes  = substr_replace( $bytes, pack( 'v', $header['made_by'] & 0xFF ), $at + 4, 2 );
	$bytes  = substr_replace( $bytes, pack( 'V', 0 ), $at + 38, 4 );
	$at    += $header['length'];
}
file_put_contents( $legacy, $bytes );

/**
 * The libarchive version behind a bsdtar, from its --version line ("bsdtar 3.8.9 - libarchive 3.8.9 ..."), or
 * null when it cannot be read.
 */
function bsdtar_libarchive_version( bool $windows ): ?string {
	list( $code, $out ) = run_command( array( $windows ? 'tar' : 'bsdtar', '--version' ), null );
	return 0 === $code && 1 === preg_match( '/libarchive (\d+\.\d+\.\d+)/', $out, $m ) ? $m[1] : null;
}

/**
 * Whether a UTF-8 name can be represented in a Windows code page: converted there and back unchanged (a lossy
 * conversion substitutes a default character, which does not come back). Null when it cannot be told.
 */
function representable_in_code_page( string $name, int $code_page ): ?bool {
	if ( ! function_exists( 'sapi_windows_cp_conv' ) ) {
		return null;
	}
	$there = @sapi_windows_cp_conv( 65001, $code_page, $name );
	$back  = null === $there ? null : @sapi_windows_cp_conv( $code_page, 65001, $there );
	return null === $back ? false : $back === $name;
}

/**
 * The known difference of Windows's own tar (bsdtar) since libarchive 3.8.6, as it is on this runner, or null
 * when it does not apply (another platform or tool, a bsdtar before 3.8.6, nothing unrepresentable, or a reading
 * that failed, which keeps the strict comparison).
 *
 * libarchive 3.8.6 (PR #2856, March 2026) stopped converting a UTF-8 entry name lossily to the current code
 * page; a name the ANSI code page cannot represent now has no narrow pathname, and bsdtar's read loop skips the
 * entry ("Archive entry has empty or unreadable filename ... skipping", exit code 1). Windows 11 ships that tar
 * since its September 2026 update (3.8.8). Upstream: issue #3063. The difference is held to exactly this shape:
 * the unrepresentable names skipped with one line each, the "Error exit delayed" line, exit code 1, and every
 * representable name byte for byte; a bsdtar at or past 3.8.6 that extracts an unrepresentable name correctly is
 * reported as the difference no longer occurring, so that this exception is removed.
 *
 * @param string[] $names The names the archive holds.
 * @return array{version: string, code_page: int, skipped: string[]}|null
 */
function windows_bsdtar_difference( string $tool, bool $windows, array $names ): ?array {
	if ( ! $windows || 'bsdtar' !== $tool ) {
		return null;
	}
	$version = bsdtar_libarchive_version( $windows );
	if ( null === $version || version_compare( $version, '3.8.6', '<' ) ) {
		return null;
	}
	$code_page = function_exists( 'sapi_windows_cp_get' ) ? (int) sapi_windows_cp_get( 'ansi' ) : 0;
	if ( $code_page <= 0 || 65001 === $code_page ) {
		return null;
	}
	$skipped = array();
	foreach ( $names as $name ) {
		if ( false === representable_in_code_page( $name, $code_page ) ) {
			$skipped[] = $name;
		}
	}
	return array() === $skipped ? null : array( 'version' => $version, 'code_page' => $code_page, 'skipped' => $skipped );
}

/**
 * The problems with a bsdtar run under the known difference: the output must be exactly one skip line per
 * unrepresentable name and the delayed-error line, the exit code 1, and the tree exactly the representable names.
 *
 * @param array{version: string, code_page: int, skipped: string[]} $difference
 * @param array<string, string> $expected Every name => content.
 * @return array{0: string[], 1: string[]} Problems, notes.
 */
function compare_under_difference( array $difference, int $code, string $output, string $dir, array $expected, bool $windows ): array {
	$problems = array();
	$skipped  = count( $difference['skipped'] );
	$lines    = array_values( array_filter( array_map( 'trim', explode( "
", str_replace( "", '', $output ) ) ), 'strlen' ) );
	$expect   = array_fill( 0, $skipped, 'tar: Archive entry has empty or unreadable filename ... skipping' );
	$expect[] = 'tar: Error exit delayed from previous errors';
	if ( 0 === $code ) {
		$problems[] = sprintf( 'the known difference no longer occurs: bsdtar (libarchive %s) exited 0 on names outside code page %d; remove the exception in check.php', $difference['version'], $difference['code_page'] );
	} elseif ( 1 !== $code ) {
		$problems[] = sprintf( 'exit code %d, not the known difference\'s 1: %s', $code, trim( $output ) );
	}
	if ( $lines !== $expect ) {
		$problems[] = sprintf( 'output is not exactly %d skip lines and the delayed-error line: %s', $skipped, implode( ' | ', $lines ) );
	}
	$representable = array_diff_key( $expected, array_flip( $difference['skipped'] ) );
	list( $tree_problems, $notes ) = compare_tree( $dir, $representable, $windows );
	foreach ( $tree_problems as $problem ) {
		$problems[] = 0 === strpos( $problem, 'unexpected name: ' ) && in_array( substr( $problem, strlen( 'unexpected name: ' ), strpos( $problem, ' (bytes' ) - strlen( 'unexpected name: ' ) ), $difference['skipped'], true )
			? 'the known difference no longer occurs: ' . $problem . ' was extracted although code page ' . $difference['code_page'] . ' cannot represent it; remove the exception in check.php'
			: $problem;
	}
	return array( $problems, $notes );
}

/**
 * The command for a tool, and its environment (null: inherited).
 *
 * @return array{0: string[], 1: array<string, string>|null}|null Null when the tool is unknown.
 */
function tool_command( string $tool, string $zip, string $dest, bool $windows ): ?array {
	$base = getenv();
	switch ( $tool ) {
		case 'unzip-c-utf8':
			return array( array( 'unzip', '-qq', $zip, '-d', $dest ), array_merge( $base, array( 'LANG' => 'C.UTF-8', 'LC_ALL' => 'C.UTF-8' ) ) );
		case 'unzip-no-locale':
			return array( array( 'unzip', '-qq', $zip, '-d', $dest ), array( 'PATH' => (string) getenv( 'PATH' ) ) );
		case '7z':
			$exe = $windows ? 'C:\\Program Files\\7-Zip\\7z.exe' : ( '' !== trim( (string) shell_exec( 'command -v 7zz' ) ) ? '7zz' : '7z' );
			return array( array( $exe, 'x', '-y', '-bso0', '-bsp0', '-o' . $dest, $zip ), null );
		case 'bsdtar':
			return array( array( $windows ? 'tar' : 'bsdtar', '-xf', $zip, '-C', $dest ), null );
		case 'python':
			return array( array( $windows ? 'python' : 'python3', '-m', 'zipfile', '-e', $zip, $dest ), null );
		case 'ditto':
			return array( array( 'ditto', '-x', '-k', $zip, $dest ), null );
		case 'expand-archive':
			return array( array( 'powershell', '-NoProfile', '-NonInteractive', '-Command', "Expand-Archive -LiteralPath '" . str_replace( "'", "''", $zip ) . "' -DestinationPath '" . str_replace( "'", "''", $dest ) . "'" ), null );
	}
	return null;
}

/**
 * Run a command; returns [exit code, output].
 *
 * @param string[]                   $argv
 * @param array<string, string>|null $env
 * @return array{0: int, 1: string}
 */
function run_command( array $argv, ?array $env ): array {
	$proc = @proc_open( $argv, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, $env );
	if ( ! is_resource( $proc ) ) {
		return array( 127, 'cannot start ' . $argv[0] );
	}
	fclose( $pipes[0] );
	$out = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
	return array( proc_close( $proc ), (string) $out );
}

/**
 * Problems with the files under $dir, which must be exactly $expected (relative name => content). On macOS a
 * name that equals an expected one after NFC normalization matches, with a note: the platform's own tools
 * (libarchive) store decomposed names, and APFS finds a file under either form.
 *
 * @return array{0: string[], 1: string[]} Problems, notes.
 */
function compare_tree( string $dir, array $expected, bool $windows ): array {
	$notes = array();
	$problems = array();
	$found    = array();
	if ( ! is_dir( $dir ) ) {
		return array( array( 'nothing extracted at ' . basename( $dir ) ), $notes );
	}
	// CATCH_GET_CHILD: a directory that cannot be opened is reported by its mode below, not thrown.
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD );
	foreach ( $it as $f ) {
		$rel = str_replace( '\\', '/', substr( $f->getPathname(), strlen( $dir ) + 1 ) );
		if ( $f->isDir() ) {
			if ( ! $windows && 0700 !== ( $f->getPerms() & 0700 ) ) {
				$problems[] = sprintf( 'directory %s has mode %o: not traversable by its owner', $rel, $f->getPerms() & 07777 );
			}
			continue;
		}
		if ( ! isset( $expected[ $rel ] ) && 'Darwin' === PHP_OS_FAMILY && class_exists( 'Normalizer' ) ) {
			$nfc = (string) Normalizer::normalize( $rel, Normalizer::FORM_C );
			if ( $nfc !== $rel && isset( $expected[ $nfc ] ) ) {
				$notes[] = 'stored decomposed (NFD), equal after NFC: ' . $nfc;
				$rel     = $nfc;
			}
		}
		$found[ $rel ] = true;
		if ( ! isset( $expected[ $rel ] ) ) {
			$problems[] = 'unexpected name: ' . $rel . ' (bytes ' . bin2hex( $rel ) . ')';
			continue;
		}
		if ( @file_get_contents( $f->getPathname() ) !== $expected[ $rel ] ) {
			$problems[] = 'content differs: ' . $rel;
		}
		if ( ! $windows ) {
			$mode = $f->getPerms() & 07777;
			if ( 0600 !== ( $mode & 0600 ) || 0 !== ( $mode & 0111 ) ) {
				$problems[] = sprintf( 'file %s has mode %o: expected readable and writable by its owner, not executable', $rel, $mode );
			}
		}
	}
	foreach ( array_keys( $expected ) as $rel ) {
		if ( ! isset( $found[ $rel ] ) ) {
			$problems[] = 'missing: ' . $rel;
		}
	}
	return array( $problems, $notes );
}

$expected_names = array();
foreach ( $names as $name ) {
	$expected_names[ $name ] = 'content of ' . $name . "\n";
}
$failed = false;
foreach ( $tools as $tool ) {
	foreach ( array( 'packer' => $volumes[0], 'directory entry' => $crafted, 'legacy host 0' => $legacy ) as $label => $zip ) {
		$dest = $root . DIRECTORY_SEPARATOR . $tool . '-' . str_replace( ' ', '-', $label );
		mkdir( $dest, 0700 );
		$command = tool_command( $tool, $zip, $dest, $windows );
		if ( null === $command ) {
			fwrite( STDERR, "Unknown tool {$tool}.\n" );
			exit( 2 );
		}
		list( $code, $output ) = run_command( $command[0], $command[1] );
		$notes      = array();
		$difference = 'directory entry' === $label ? null : windows_bsdtar_difference( $tool, $windows, $names );
		if ( null !== $difference ) {
			list( $problems, $notes ) = compare_under_difference( $difference, $code, $output, $dest . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'names', $expected_names, $windows );
			$notes[] = sprintf( 'known difference: libarchive %s skips the %d names code page %d cannot represent', $difference['version'], count( $difference['skipped'] ), $difference['code_page'] );
		} elseif ( 0 !== $code ) {
			$problems = array( sprintf( 'exit code %d: %s', $code, trim( $output ) ) );
		} elseif ( 'directory entry' === $label ) {
			list( $problems, $notes ) = compare_tree( $dest . DIRECTORY_SEPARATOR . 'files', array( 'empty-dir/x.txt' => "inside\n" ), $windows );
		} else {
			list( $problems, $notes ) = compare_tree( $dest . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'names', $expected_names, $windows );
		}
		$informational = 'legacy host 0' === $label;
		printf( "%-16s %-16s %s\n", $tool, $label, array() === $problems ? 'ok' : ( $informational ? 'differs (informational: the previous writer)' : 'FAILED' ) );
		foreach ( array_merge( $problems, $notes ) as $line ) {
			printf( "    %s\n", $line );
		}
		$failed = $failed || ( ! $informational && array() !== $problems );
	}
}
$builder->cleanup();
/** Remove a tree, making each directory usable before entering it (an extractor may have left it mode 0). */
function remove_tree( string $path ): void {
	if ( is_dir( $path ) && ! is_link( $path ) ) {
		@chmod( $path, 0700 );
		foreach ( scandir( $path ) ?: array() as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				remove_tree( $path . DIRECTORY_SEPARATOR . $name );
			}
		}
		@rmdir( $path );
		return;
	}
	@chmod( $path, 0600 );
	@unlink( $path );
}
remove_tree( $root );
exit( $failed ? 1 : 0 );
