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

/** @return string[] Problems with the entries under $dir/$sub, which must be exactly $expected (relative name => content). */
function compare_tree( string $dir, array $expected, bool $windows ): array {
	$problems = array();
	$found    = array();
	if ( ! is_dir( $dir ) ) {
		return array( 'nothing extracted at ' . basename( $dir ) );
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
	return $problems;
}

$expected_names = array();
foreach ( $names as $name ) {
	$expected_names[ $name ] = 'content of ' . $name . "\n";
}
$failed = false;
foreach ( $tools as $tool ) {
	foreach ( array( 'packer' => $volumes[0], 'directory entry' => $crafted ) as $label => $zip ) {
		$dest = $root . DIRECTORY_SEPARATOR . $tool . '-' . str_replace( ' ', '-', $label );
		mkdir( $dest, 0700 );
		$command = tool_command( $tool, $zip, $dest, $windows );
		if ( null === $command ) {
			fwrite( STDERR, "Unknown tool {$tool}.\n" );
			exit( 2 );
		}
		list( $code, $output ) = run_command( $command[0], $command[1] );
		if ( 0 !== $code ) {
			$problems = array( sprintf( 'exit code %d: %s', $code, trim( $output ) ) );
		} elseif ( 'packer' === $label ) {
			$problems = compare_tree( $dest . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'names', $expected_names, $windows );
		} else {
			$problems = compare_tree( $dest . DIRECTORY_SEPARATOR . 'files', array( 'empty-dir/x.txt' => "inside\n" ), $windows );
		}
		printf( "%-16s %-16s %s\n", $tool, $label, array() === $problems ? 'ok' : 'FAILED' );
		foreach ( $problems as $problem ) {
			printf( "    %s\n", $problem );
		}
		$failed = $failed || array() !== $problems;
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
