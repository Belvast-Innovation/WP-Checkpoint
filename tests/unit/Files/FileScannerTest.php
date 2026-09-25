<?php

namespace WPCheckpoint\Tests\Unit\Files;

use WPCheckpoint\Archive\IndexLine;
use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Archive\Packer;
use WPCheckpoint\Files\Exclusions;
use WPCheckpoint\Files\FileScanner;
use WPCheckpoint\Tests\Fixtures\MemoryBudget;
use WPCheckpoint\Tests\Fixtures\Permissions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class FileScannerTest extends TestCase {

	/** @var string */
	private $root;

	protected function set_up(): void {
		$this->root = sys_get_temp_dir() . '/wpcheckpoint-scan-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root, 0700, true );
	}

	protected function tear_down(): void {
		exec( 'chmod -R u+rwx ' . escapeshellarg( $this->root ) . ' 2>/dev/null; rm -rf ' . escapeshellarg( $this->root ) );
	}

	private function put( string $rel, string $content = 'x' ): void {
		$path = $this->root . '/' . $rel;
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0700, true );
		}
		file_put_contents( $path, $content );
	}

	/**
	 * Run a scanner to the end, one unit at a time, returning the lines and the final state.
	 *
	 * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>, 2: int}
	 */
	private function run_all( FileScanner $scanner, bool $roundtrip = false ): array {
		$lines = array();
		$state = FileScanner::initial_state();
		$units = 0;
		while ( empty( $state['done'] ) ) {
			$state = $scanner->scan_unit( $state, static function ( array $line ) use ( &$lines ): void {
				$lines[] = $line;
			} );
			++$units;
			if ( $roundtrip ) {
				$state = json_decode( (string) json_encode( $state ), true );
			}
			$this->assertLessThan( 100000, $units, 'the scan must terminate' );
		}
		return array( $lines, $state, $units );
	}

	private function paths( array $lines ): array {
		return array_map( static function ( array $line ): string {
			return $line['p'];
		}, $lines );
	}

	public function test_files_are_listed_in_a_stable_byte_order_with_size_and_mtime_and_no_directories(): void {
		$this->put( 'content/plugins/b/b.php', 'bb' );
		$this->put( 'content/plugins/a/a.php', 'a' );
		$this->put( 'content/plugins/10.txt' );
		$this->put( 'content/plugins/9.txt' );
		$this->put( 'content/plugins/Z.txt' );
		mkdir( $this->root . '/content/plugins/empty' );
		$scanner = new FileScanner( array( array( 'group' => 'plugins', 'path' => $this->root . '/content/plugins', 'prefix' => 'wp-content/plugins' ) ), new Exclusions( array(), array() ) );
		list( $lines, $state ) = $this->run_all( $scanner );
		$this->assertSame(
			array( 'wp-content/plugins/10.txt', 'wp-content/plugins/9.txt', 'wp-content/plugins/Z.txt', 'wp-content/plugins/a/a.php', 'wp-content/plugins/b/b.php' ),
			$this->paths( $lines ),
			'byte order: "10" before "9", uppercase before lowercase; a directory is entered when reached'
		);
		$this->assertSame( 2, $lines[4]['b'] );
		$this->assertSame( filemtime( $this->root . '/content/plugins/b/b.php' ), $lines[4]['m'] );
		$this->assertSame( 5, $state['counts']['files'] );
		$this->assertSame( 6, $state['counts']['bytes'] );
		$this->assertSame( 4, $state['counts']['directories'], 'the root, a, b and empty' );
		foreach ( $lines as $line ) {
			$this->assertArrayNotHasKey( 'h', $line );
			IndexLine::files( (string) json_encode( $line ), 1048576 ); // Every line is a valid files index line.
		}
	}

	public function test_heavy_directories_are_summed_up_by_their_outermost_occurrence(): void {
		$this->put( 'content/plugins/a/node_modules/x/big.js', str_repeat( 'x', 3000 ) );
		$this->put( 'content/plugins/a/node_modules/x/node_modules/y/nested.js', str_repeat( 'y', 500 ) );
		$this->put( 'content/plugins/a/index.php', 'x' );
		$this->put( 'content/plugins/b/.git/objects/ab', str_repeat( 'g', 200 ) );
		$this->put( 'content/plugins/b/vendor/lib.php', str_repeat( 'v', 700 ) );
		$roots = array( array( 'group' => 'plugins', 'path' => $this->root . '/content/plugins', 'prefix' => 'wp-content/plugins' ) );
		list( $lines, $state ) = $this->run_all( new FileScanner( $roots, new Exclusions( array(), array() ) ), true );
		$this->assertSame(
			array(
				'wp-content/plugins/a/node_modules' => 3500,
				'wp-content/plugins/b/.git'         => 200,
			),
			$state['lists']['heavy'],
			'nested node_modules count towards the outer one; vendor is not a heavy directory'
		);
		$this->assertSame( 2, $state['counts']['heavy'] );
		$this->assertCount( 5, $lines, 'nothing is excluded by the summing up' );
		$this->assertContains( 'wp-content/plugins/a/node_modules/x/node_modules/y/nested.js', $this->paths( $lines ) );
	}

	public function test_a_resumed_scan_produces_exactly_the_same_lines_as_an_uninterrupted_one(): void {
		for ( $i = 0; $i < 2500; $i++ ) {
			$this->put( sprintf( 'u/%02d/f%04d.txt', $i % 7, $i ), (string) $i );
		}
		$this->put( 'u/deep/' . str_repeat( 'd/', 60 ) . 'leaf.txt' );
		$roots   = array( array( 'group' => 'uploads', 'path' => $this->root . '/u', 'prefix' => 'wp-content/uploads' ) );
		$scanner = new FileScanner( $roots, new Exclusions( array(), array() ) );
		list( $straight ) = $this->run_all( $scanner );
		list( $resumed, $state, $units ) = $this->run_all( new FileScanner( $roots, new Exclusions( array(), array() ) ), true );
		$this->assertSame( $straight, $resumed, 'the state carries everything a fresh instance needs' );
		$this->assertCount( 2501, $straight );
		$this->assertGreaterThan( 2, $units, 'more than one unit of ' . FileScanner::UNIT_ENTRIES );
		$this->assertSame( 0, $state['counts']['collisions'] );
		$this->assertContains( 'wp-content/uploads/deep/' . str_repeat( 'd/', 60 ) . 'leaf.txt', $this->paths( $straight ) );
	}

	public function test_links_special_files_bad_names_and_excluded_paths_are_skipped_and_counted(): void {
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'symlinks need privileges on Windows' );
		}
		$this->put( 'c/keep.txt' );
		$this->put( 'c/cache/page.html' );
		$this->put( 'c/plugins/x/node_modules/lib.js', 'kept: node_modules is the user\'s' );
		$this->put( 'outside/secret.txt' );
		symlink( $this->root . '/outside', $this->root . '/c/link-dir' );
		symlink( $this->root . '/outside/secret.txt', $this->root . '/c/link-file' );
		$this->put( "c/bad\x01name.txt" );
		$this->put( "c/latin-\xE9.txt" );
		$this->put( 'c/storage/backups/old.zip' );
		if ( function_exists( 'posix_mkfifo' ) ) {
			posix_mkfifo( $this->root . '/c/pipe', 0600 );
		}
		$scanner = new FileScanner(
			array( array( 'group' => 'other-content', 'path' => $this->root . '/c', 'prefix' => 'wp-content', 'skip' => array( $this->root . '/c/storage' ) ) ),
			new Exclusions( array(), array( 'wp-content/cache' ) )
		);
		list( $lines, $state ) = $this->run_all( $scanner );
		$this->assertSame( array( 'wp-content/keep.txt', 'wp-content/plugins/x/node_modules/lib.js' ), $this->paths( $lines ) );
		$this->assertSame( 2, $state['counts']['links'], 'neither link was followed' );
		$this->assertSame( 2, $state['counts']['excluded'], 'cache by rule, storage by skip' );
		$this->assertSame( 2, $state['counts']['bad_names'], 'a control character and invalid UTF-8' );
		if ( function_exists( 'posix_mkfifo' ) ) {
			$this->assertSame( 1, $state['counts']['special'] );
		}
		$this->assertCount( 2, $state['warnings'] );
		foreach ( $state['warnings'] as $warning ) {
			$this->assertStringNotContainsString( $this->root, $warning, 'warnings carry relative paths only' );
			$this->assertSame( $warning, \WPCheckpoint\Support\Utf8::scrub( $warning ), 'warnings are valid UTF-8 even for invalid names' );
		}
	}

	public function test_unreadable_entries_are_listed_for_the_preflight_and_the_scan_goes_on(): void {
		Permissions::require_enforced();
		$this->put( 'c/a.txt' );
		$this->put( 'c/locked/inside.txt' );
		$this->put( 'c/secret.txt' );
		$this->put( 'c/z.txt' );
		chmod( $this->root . '/c/locked', 0000 );
		chmod( $this->root . '/c/secret.txt', 0000 );
		$scanner = new FileScanner( array( array( 'group' => 'other-content', 'path' => $this->root . '/c', 'prefix' => 'wp-content' ) ), new Exclusions( array(), array() ) );
		list( $lines, $state ) = $this->run_all( $scanner );
		$this->assertSame( array( 'wp-content/a.txt', 'wp-content/z.txt' ), $this->paths( $lines ) );
		$this->assertSame( 2, $state['counts']['unreadable'] );
		$this->assertSame( array( 'wp-content/locked', 'wp-content/secret.txt' ), $state['lists']['unreadable'] );
	}

	public function test_sibling_names_that_collide_by_case_or_unicode_form_are_reported_once(): void {
		$this->put( 'c/Readme.md' );
		$this->put( 'c/readme.md' );
		$this->put( "c/caf\u{00E9}.txt" );
		$this->put( "c/cafe\u{0301}.txt" );
		if ( count( scandir( $this->root . '/c' ) ) < 6 ) {
			// NTFS folds case and APFS folds Unicode forms: the colliding siblings merged into one file here.
			$this->markTestSkipped( 'the file system does not keep colliding names apart' );
		}
		$this->put( 'c/sub/readme.md', 'a different directory is not a collision' );
		for ( $i = 0; $i < 1500; $i++ ) {
			$this->put( sprintf( 'c/many/%04d', $i ) );
		}
		$scanner = new FileScanner( array( array( 'group' => 'other-content', 'path' => $this->root . '/c', 'prefix' => 'wp-content' ) ), new Exclusions( array(), array() ) );
		list( $lines, $state ) = $this->run_all( new FileScanner( array( array( 'group' => 'other-content', 'path' => $this->root . '/c', 'prefix' => 'wp-content' ) ), new Exclusions( array(), array() ) ), true );
		$expected = class_exists( 'Normalizer' ) ? 2 : 1;
		$this->assertSame( $expected, $state['counts']['collisions'] );
		$this->assertCount( $expected, $state['warnings'] );
		$this->assertStringContainsString( 'Readme.md, readme.md', $state['warnings'][0] );
		$this->assertCount( 1505, $lines, 'colliding files are still listed; the warning is for the user' );
	}

	public function test_size_bounds_use_the_platform_and_are_listed_with_a_cap(): void {
		$this->put( 'c/small.bin', 'small' );
		$scanner = new FileScanner( array( array( 'group' => 'other-content', 'path' => $this->root . '/c', 'prefix' => 'wp-content' ) ), new Exclusions( array(), array() ), 4 );
		// A sparse file well over 2 GiB costs no disk space.
		$h = fopen( $this->root . '/c/huge.bin', 'wb' );
		fseek( $h, 2147483647 + 10 );
		fwrite( $h, 'x' );
		fclose( $h );
		if ( filesize( $this->root . '/c/huge.bin' ) < 2147483647 ) {
			$this->markTestSkipped( 'no sparse files here' );
		}
		list( $lines, $state ) = $this->run_all( $scanner );
		$this->assertSame( 1, $state['counts']['too_large'], 'on 32-bit PHP the sparse file is too large' );
		$this->assertSame( array( 'wp-content/huge.bin' ), $state['lists']['too_large'] );
		$this->assertSame( 0, $state['counts']['over_volume'] );
		$this->assertCount( 2, $lines, 'still listed: the pre-flight decides' );
		list( $lines, $state ) = $this->run_all( new FileScanner( array( array( 'group' => 'other-content', 'path' => $this->root . '/c', 'prefix' => 'wp-content' ) ), new Exclusions( array(), array() ), 8 ) );
		$this->assertSame( 0, $state['counts']['too_large'], 'on 64-bit PHP it merely exceeds the volume threshold' );
		$this->assertSame( 1, $state['counts']['over_volume'] );
		$this->assertGreaterThan( Packer::VOLUME_BYTES, $lines[0]['b'] );
		$this->assertSame( Packer::VOLUME_BYTES, $state['limits']['volume_bytes'], 'the threshold it judged with, for the review to quote' );
		// The index line bounds the largest file too: with the smallest chunk the limit is about 15 GiB, a sparse 16 GiB file is over it.
		$h = fopen( $this->root . '/c/huge.bin', 'wb' );
		fseek( $h, IndexLine::max_indexable_bytes( Manifest::MIN_CHUNK ) + 10 );
		fwrite( $h, 'x' );
		fclose( $h );
		list( $lines, $state ) = $this->run_all( new FileScanner( array( array( 'group' => 'other-content', 'path' => $this->root . '/c', 'prefix' => 'wp-content' ) ), new Exclusions( array(), array() ), 8, Manifest::MIN_CHUNK ) );
		$this->assertSame( array( 'wp-content/huge.bin' ), $state['lists']['too_large'], 'larger than one index line can describe: listed before anything is packed' );
		list( $lines, $state ) = $this->run_all( new FileScanner( array( array( 'group' => 'other-content', 'path' => $this->root . '/c', 'prefix' => 'wp-content' ) ), new Exclusions( array(), array() ), 8, Manifest::DEFAULT_CHUNK ) );
		$this->assertSame( 0, $state['counts']['too_large'], 'with the default chunk the same file is indexable' );
	}

	public function test_a_missing_root_is_a_warning_and_the_next_root_is_scanned(): void {
		$this->put( 'themes/t/style.css' );
		$scanner = new FileScanner(
			array(
				array( 'group' => 'plugins', 'path' => $this->root . '/nope', 'prefix' => 'wp-content/plugins' ),
				array( 'group' => 'themes', 'path' => $this->root . '/themes', 'prefix' => 'wp-content/themes' ),
			),
			new Exclusions( array( '[' ), array() )
		);
		list( $lines, $state ) = $this->run_all( $scanner );
		$this->assertSame( array( 'wp-content/themes/t/style.css' ), $this->paths( $lines ) );
		$this->assertSame( 1, $state['counts']['unreadable'] );
		$this->assertSame( 0, $state['counts']['invalid_patterns'], 'a lone bracket is a literal in a glob' );
		$this->assertStringContainsString( 'wp-content/plugins', $state['warnings'][0] );
	}

	/**
	 * @group slow
	 */
	public function test_a_hundred_thousand_files_are_listed_within_the_step_memory_budget(): void {
		for ( $d = 0; $d < 100; $d++ ) {
			mkdir( $this->root . '/big/' . $d, 0700, true );
			for ( $f = 0; $f < 1000; $f++ ) {
				touch( $this->root . '/big/' . $d . '/' . $f );
			}
		}
		$scanner = new FileScanner( array( array( 'group' => 'uploads', 'path' => $this->root . '/big', 'prefix' => 'wp-content/uploads' ) ), new Exclusions() );
		$state   = FileScanner::initial_state();
		$count   = 0;
		$units   = 0;
		MemoryBudget::within(
			32 * 1048576, // The state and one listing at a time, never the whole tree.
			static function () use ( $scanner, &$state, &$count, &$units ): void {
				while ( empty( $state['done'] ) ) {
					$state = $scanner->scan_unit( $state, static function () use ( &$count ): void {
						++$count;
					} );
					++$units;
				}
			}
		);
		$this->assertSame( 100000, $count );
		$this->assertSame( 100000, $state['counts']['files'] );
		$this->assertGreaterThanOrEqual( 100, $units );
		$this->assertLessThan( 4096, strlen( (string) json_encode( $state ) ), 'the cursor stays small' );
	}

	public function test_a_byte_total_the_platform_cannot_count_stops_the_scan_with_the_reason(): void {
		$this->put( 'c/one.bin', str_repeat( 'x', 100 ) );
		$scanner                  = new FileScanner( array( array( 'group' => 'other-content', 'path' => $this->root . '/c', 'prefix' => 'wp-content' ) ), new Exclusions( array(), array() ) );
		$state                    = FileScanner::initial_state();
		$state['counts']['bytes'] = PHP_INT_MAX - 10; // As if the files before this one had added up to the platform's limit.
		try {
			while ( empty( $state['done'] ) ) {
				$state = $scanner->scan_unit( $state, static function ( array $line ): void {} );
			}
			$this->fail( 'the total must be refused at the scan, not at the manifest' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( sprintf( '%d-bit PHP can count', PHP_INT_SIZE * 8 ), $e->getMessage() );
		}
	}
}
