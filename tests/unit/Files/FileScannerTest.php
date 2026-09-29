<?php

namespace WPCheckpoint\Tests\Unit\Files;

use WPCheckpoint\Archive\IndexLine;
use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Archive\Packer;
use WPCheckpoint\Files\Exclusions;
use WPCheckpoint\Files\FileScanner;
use WPCheckpoint\Files\Links;
use WPCheckpoint\Tests\Fixtures\MemoryBudget;
use WPCheckpoint\Tests\Fixtures\Permissions;
use WPCheckpoint\Tests\Fixtures\Junction;
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

	public function test_a_junction_is_counted_as_a_link_and_not_followed(): void {
		$this->put( 'c/keep.txt' );
		$this->put( 'outside/secret.txt' );
		Junction::make( $this->root . '/outside', $this->root . '/c/junction-dir' );
		try {
			$this->assertFileExists( $this->root . '/c/junction-dir/secret.txt', 'the control: the junction leads outside' );
			$scanner = new FileScanner(
				array( array( 'group' => 'other-content', 'path' => $this->root . '/c', 'prefix' => 'wp-content', 'skip' => array() ) ),
				new Exclusions( array(), array() )
			);
			list( $lines, $state ) = $this->run_all( $scanner );
			$this->assertSame( array( 'wp-content/keep.txt' ), $this->paths( $lines ), 'nothing behind the junction is listed' );
			$this->assertSame( 1, $state['counts']['links'], 'the junction is counted as a link' );
		} finally {
			Junction::remove( $this->root . '/c/junction-dir' );
		}
	}

	public function test_a_content_root_that_is_a_junction_is_followed_and_reported(): void {
		// The Windows version of the symbolic link test below: a junction as the uploads directory (moved to another
		// drive) is scanned through; the report says so. A junction below a root stays a link that is not entered.
		$this->put( 'elsewhere/photo.jpg' );
		$this->put( 'outside/secret.txt' );
		mkdir( $this->root . '/site' );
		Junction::make( $this->root . '/elsewhere', $this->root . '/uploads' );
		Junction::make( $this->root . '/outside', $this->root . '/elsewhere/inner' );
		try {
			list( $lines, $state ) = $this->run_all( $this->link_scanner( $this->root . '/uploads' ) );
			$this->assertSame( array( 'wp-content/uploads/photo.jpg' ), $this->paths( $lines ) );
			$this->assertSame( 1, $state['counts']['links'], 'the junction inside the root is not entered' );
			$this->assertSame( 0, $state['counts']['unreadable'] );
			$this->assertSame( array( 'The "uploads" content directory is a link; the directory it leads to was backed up (wp-content/uploads -> {root}/elsewhere).' ), $state['warnings'] );
		} finally {
			Junction::remove( $this->root . '/elsewhere/inner' );
			Junction::remove( $this->root . '/uploads' );
		}
	}

	public function test_a_content_root_junction_to_the_site_is_refused(): void {
		$this->put( 'site/wp-config.php' );
		Junction::make( $this->root . '/site', $this->root . '/uploads' );
		try {
			$this->assertFileExists( $this->root . '/uploads/wp-config.php', 'the control: the junction leads to the site' );
			list( $lines, $state ) = $this->run_all( $this->link_scanner( $this->root . '/uploads' ) );
			$this->assertSame( array(), $lines );
			$this->assertSame( 1, $state['counts']['unreadable'] );
			$this->assertSame( array( 'The "uploads" content directory is a link and was not scanned: it leads to the WordPress directory or a directory that holds it (wp-content/uploads).' ), $state['warnings'] );
		} finally {
			Junction::remove( $this->root . '/uploads' );
		}
	}

	/**
	 * A scanner over one uploads root, with the WordPress directory at {root}/site and the target shown relative to
	 * the sandbox (as the presenter masks it).
	 *
	 * @param string                    $path       The root.
	 * @param callable|null             $link_state Link test to inject.
	 * @param array<int, array<string, mixed>> $more More roots after it.
	 */
	private function link_scanner( string $path, $link_state = null, array $more = array() ): FileScanner {
		$root    = $this->root;
		$options = array(
			'abspath' => $this->root . '/site',
			'mask'    => static function ( string $target ) use ( $root ): string {
				// One separator throughout: on Windows the sandbox is spelled with both (sys_get_temp_dir() . '/...').
				$slashed = static function ( string $path ): string {
					return str_replace( '\\', '/', $path );
				};
				return str_replace( array( $slashed( (string) realpath( $root ) ), $slashed( $root ) ), '{root}', $slashed( $target ) );
			},
		);
		if ( null !== $link_state ) {
			$options['link_state'] = $link_state;
		}
		return new FileScanner(
			array_merge( array( array( 'group' => 'uploads', 'path' => $path, 'prefix' => 'wp-content/uploads' ) ), $more ),
			new Exclusions( array(), array() ),
			PHP_INT_SIZE,
			Manifest::DEFAULT_CHUNK,
			$options
		);
	}

	private function require_symlinks(): void {
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'symlinks need privileges on Windows; the junction versions of these tests run there' );
		}
	}

	public function test_a_content_root_that_is_a_symbolic_link_is_followed_only_at_the_root_and_reported(): void {
		$this->require_symlinks();
		$this->put( 'shared/uploads/2026/photo.jpg' );
		$this->put( 'outside/secret.txt' );
		mkdir( $this->root . '/site' );
		symlink( $this->root . '/outside', $this->root . '/shared/uploads/inner' );
		symlink( $this->root . '/shared/uploads', $this->root . '/uploads' );
		list( $lines, $state ) = $this->run_all( $this->link_scanner( $this->root . '/uploads' ) );
		$this->assertSame( array( 'wp-content/uploads/2026/photo.jpg' ), $this->paths( $lines ), 'under the canonical prefix, nothing behind the inner link' );
		$this->assertSame( 1, $state['counts']['links'], 'the link inside the root is counted and not entered' );
		$this->assertSame( 0, $state['counts']['unreadable'], 'a followed root is not a finding the pre-flight asks about' );
		$this->assertSame( array( 'The "uploads" content directory is a link; the directory it leads to was backed up (wp-content/uploads -> {root}/shared/uploads).' ), $state['warnings'] );
		$this->assertSame( array( 'wp-content/uploads' => Links::fingerprint( $this->root . '/shared/uploads' ) ), $state['root_ids'], 'where the root led, for the pack step' );

		// Where it leads is shown only through the mask: without one it is not shown at all.
		$plain                 = new FileScanner( array( array( 'group' => 'uploads', 'path' => $this->root . '/uploads', 'prefix' => 'wp-content/uploads' ) ), new Exclusions( array(), array() ), PHP_INT_SIZE, Manifest::DEFAULT_CHUNK, array( 'abspath' => $this->root . '/site' ) );
		list( $again, $state ) = $this->run_all( $plain );
		$this->assertSame( $this->paths( $lines ), $this->paths( $again ) );
		$this->assertStringContainsString( '(not shown)', $state['warnings'][0] );
		$this->assertStringNotContainsString( $this->root, $state['warnings'][0] );
	}

	public function test_a_content_root_link_to_a_file_to_the_site_or_above_it_is_refused_with_the_reason(): void {
		$this->require_symlinks();
		$this->put( 'site/wp-config.php' );
		$this->put( 'file.txt' );
		$this->put( 'themes/t/style.css' );
		symlink( $this->root . '/file.txt', $this->root . '/to-file' );
		symlink( $this->root . '/site', $this->root . '/to-site' );
		symlink( $this->root, $this->root . '/to-parent' );
		$scanner = $this->link_scanner(
			$this->root . '/to-file',
			null,
			array(
				array( 'group' => 'plugins', 'path' => $this->root . '/to-site', 'prefix' => 'wp-content/plugins' ),
				array( 'group' => 'mu-plugins', 'path' => $this->root . '/to-parent', 'prefix' => 'wp-content/mu-plugins' ),
				array( 'group' => 'themes', 'path' => $this->root . '/themes', 'prefix' => 'wp-content/themes' ),
			)
		);
		list( $lines, $state ) = $this->run_all( $scanner );
		$this->assertSame( array( 'wp-content/themes/t/style.css' ), $this->paths( $lines ), 'the control: a plain root after them is scanned' );
		$this->assertSame( 3, $state['counts']['unreadable'], 'each refused root is a finding the pre-flight asks about' );
		$this->assertSame(
			array(
				'The "uploads" content directory is a link and was not scanned: it does not lead to a directory (wp-content/uploads).',
				'The "plugins" content directory is a link and was not scanned: it leads to the WordPress directory or a directory that holds it (wp-content/plugins).',
				'The "mu-plugins" content directory is a link and was not scanned: it leads to the WordPress directory or a directory that holds it (wp-content/mu-plugins).',
			),
			$state['warnings']
		);
	}

	public function test_a_content_root_link_is_refused_when_the_site_directory_is_unknown(): void {
		$this->require_symlinks();
		$this->put( 'shared/uploads/photo.jpg' );
		symlink( $this->root . '/shared/uploads', $this->root . '/uploads' );
		foreach ( array( '', $this->root . '/no-such-site' ) as $abspath ) {
			$scanner               = new FileScanner( array( array( 'group' => 'uploads', 'path' => $this->root . '/uploads', 'prefix' => 'wp-content/uploads' ) ), new Exclusions( array(), array() ), PHP_INT_SIZE, Manifest::DEFAULT_CHUNK, array( 'abspath' => $abspath ) );
			list( $lines, $state ) = $this->run_all( $scanner );
			$this->assertSame( array(), $lines, $abspath );
			$this->assertStringContainsString( 'the WordPress directory could not be located', $state['warnings'][0] );
		}
	}

	public function test_a_directory_that_cannot_be_told_apart_from_a_link_is_entered_only_where_it_resolves_in_place(): void {
		$this->require_symlinks();
		$this->put( 'uploads/2026/a.jpg' );
		$this->put( 'uploads/in-place/x.jpg' );
		$this->put( 'outside/secret.txt' );
		$this->put( 'uploads/2026/nested/n.jpg' );
		$this->put( 'themes/t/style.css' );
		mkdir( $this->root . '/site' );
		// Links the test for links cannot see (as on Windows without readlink()): one out of the root, one into it.
		symlink( $this->root . '/outside', $this->root . '/uploads/away' );
		symlink( $this->root . '/uploads/2026', $this->root . '/uploads/again' );
		$themes = array( array( 'group' => 'themes', 'path' => $this->root . '/themes', 'prefix' => 'wp-content/themes' ) );

		// The control: with the real link test, both links are seen and neither is entered.
		list( $lines, $state ) = $this->run_all( $this->link_scanner( $this->root . '/uploads', null, $themes ) );
		$expected              = array( 'wp-content/uploads/2026/a.jpg', 'wp-content/uploads/2026/nested/n.jpg', 'wp-content/uploads/in-place/x.jpg', 'wp-content/themes/t/style.css' );
		$this->assertSame( $expected, $this->paths( $lines ) );
		$this->assertSame( 2, $state['counts']['links'] );

		$root    = $this->root;
		$unknown = static function ( string $path ) use ( $root ): string {
			return is_dir( $path ) && $root . '/uploads' !== $path ? Links::UNKNOWN : Links::state( $path );
		};
		list( $lines, $state ) = $this->run_all( $this->link_scanner( $this->root . '/uploads', $unknown, $themes ) );
		$this->assertSame( $expected, $this->paths( $lines ), 'every directory in place is scanned, neither link is followed, nothing twice' );
		$this->assertSame( 2, $state['counts']['links'], 'the two that resolve elsewhere count as links' );
		$this->assertSame( 0, $state['counts']['unreadable'], 'nothing for the pre-flight to ask' );
		$this->assertSame( array(), $state['lists']['unreadable'] );
		$this->assertGreaterThan( 2, $state['counts']['undecided'] );
		$fallback = array_values( array_filter( $state['warnings'], static function ( string $w ): bool {
			return false !== strpos( $w, 'link detection fell back to a containment check' );
		} ) );
		$this->assertCount( 1, $fallback, 'said once, however many directories' );
		$this->assertContains( 'A directory that resolves to another place than where it is listed was left out: wp-content/uploads/again', $state['warnings'] );
		$this->assertContains( 'A directory that resolves to another place than where it is listed was left out: wp-content/uploads/away', $state['warnings'] );
	}

	public function test_a_directory_judged_a_link_is_kept_a_link_unless_the_answer_came_only_from_its_entries(): void {
		// Windows answers "link" for a volume mount point through readlink(), which realpath() does not resolve (it
		// looks in place); only an answer from probing the entries may be corrected by where the directory resolves.
		$this->put( 'uploads/a.jpg' );
		$this->put( 'uploads/mount/m.jpg' );
		mkdir( $this->root . '/site' );
		$root = $this->root;
		$link = static function ( string $path ) use ( $root ): string {
			return $root . '/uploads/mount' === $path ? Links::LINK : Links::state( $path );
		};
		foreach ( array( 'readlink said so' => false, 'only the entries said so' => true ) as $case => $probed ) {
			$scanner               = new FileScanner(
				array( array( 'group' => 'uploads', 'path' => $this->root . '/uploads', 'prefix' => 'wp-content/uploads' ) ),
				new Exclusions( array(), array() ),
				PHP_INT_SIZE,
				Manifest::DEFAULT_CHUNK,
				array(
					'abspath'    => $this->root . '/site',
					'link_state' => $link,
					'probed'     => static function () use ( $probed ): bool {
						return $probed;
					},
				)
			);
			list( $lines, $state ) = $this->run_all( $scanner );
			if ( $probed ) {
				$this->assertSame( array( 'wp-content/uploads/a.jpg', 'wp-content/uploads/mount/m.jpg' ), $this->paths( $lines ), $case . ': in place, so a plain directory' );
				$this->assertSame( 0, $state['counts']['links'], $case );
			} else {
				$this->assertSame( array( 'wp-content/uploads/a.jpg' ), $this->paths( $lines ), $case . ': stays a link' );
				$this->assertSame( 1, $state['counts']['links'], $case );
			}
		}
	}

	public function test_an_undecidable_directory_that_does_not_resolve_at_all_is_asked_about_not_taken_for_a_link(): void {
		$this->require_symlinks();
		$this->put( 'uploads/a.jpg' );
		mkdir( $this->root . '/site' );
		symlink( $this->root . '/nowhere', $this->root . '/uploads/gone' );
		// The control: the real test for links sees the broken link and counts it.
		list( , $state ) = $this->run_all( $this->link_scanner( $this->root . '/uploads' ) );
		$this->assertSame( 1, $state['counts']['links'] );
		$this->assertSame( array(), $state['lists']['unreadable'] );

		$root    = $this->root;
		$unknown = static function ( string $path ) use ( $root ): string {
			return $root . '/uploads/gone' === $path ? Links::UNKNOWN : Links::state( $path );
		};
		list( $lines, $state ) = $this->run_all( $this->link_scanner( $this->root . '/uploads', $unknown ) );
		$this->assertSame( array( 'wp-content/uploads/a.jpg' ), $this->paths( $lines ) );
		$this->assertSame( 0, $state['counts']['links'], 'no evidence of a link' );
		$this->assertSame( array( 'wp-content/uploads/gone' ), $state['lists']['unreadable'], 'the pre-flight asks' );
	}

	public function test_a_content_directory_behind_a_link_skips_the_storage_and_the_other_groups_by_real_path(): void {
		$this->require_symlinks();
		// wp-content is a link to where the content really is; the plugins directory and the plugin's storage are
		// configured with their real spelling. The other-content root lists its entries under the link's spelling.
		mkdir( $this->root . '/site' );
		$this->put( 'data/content/index.php' );
		$this->put( 'data/content/plugins/p/a.php' );
		$this->put( 'data/content/store/backups/old.zip' );
		symlink( $this->root . '/data/content', $this->root . '/site/wp-content' );
		$real  = $this->root . '/data/content';
		$roots = array(
			array( 'group' => 'plugins', 'path' => $real . '/plugins', 'prefix' => 'wp-content/plugins', 'skip' => array( $real . '/store' ) ),
			array( 'group' => 'other-content', 'path' => $this->root . '/site/wp-content', 'prefix' => 'wp-content', 'skip' => array( $real . '/plugins', $real . '/store' ) ),
		);
		$scanner               = new FileScanner( $roots, new Exclusions( array(), array() ), PHP_INT_SIZE, Manifest::DEFAULT_CHUNK, array( 'abspath' => $this->root . '/site' ) );
		list( $lines, $state ) = $this->run_all( $scanner );
		$this->assertSame( array( 'wp-content/plugins/p/a.php', 'wp-content/index.php' ), $this->paths( $lines ), 'the plugins once, the storage never, the rest of the content through the link' );
		$this->assertSame( 2, $state['counts']['excluded'], 'plugins and storage skipped under the other-content root' );

		// The other spelling the same way: the skipped directories written through the link, the root resolved.
		$roots = array(
			array( 'group' => 'other-content', 'path' => $real, 'prefix' => 'wp-content', 'skip' => array( $this->root . '/site/wp-content/plugins', $this->root . '/site/wp-content/store' ) ),
		);
		list( $lines ) = $this->run_all( new FileScanner( $roots, new Exclusions( array(), array() ), PHP_INT_SIZE, Manifest::DEFAULT_CHUNK, array( 'abspath' => $this->root . '/site' ) ) );
		$this->assertSame( array( 'wp-content/index.php' ), $this->paths( $lines ) );
	}

	public function test_a_content_root_link_into_a_directory_it_skips_is_refused(): void {
		$this->require_symlinks();
		mkdir( $this->root . '/site' );
		$this->put( 'store/backups/old.zip' );
		symlink( $this->root . '/store/backups', $this->root . '/uploads' );
		$scanner               = new FileScanner(
			array( array( 'group' => 'uploads', 'path' => $this->root . '/uploads', 'prefix' => 'wp-content/uploads', 'skip' => array( $this->root . '/store' ) ) ),
			new Exclusions( array(), array() ),
			PHP_INT_SIZE,
			Manifest::DEFAULT_CHUNK,
			array( 'abspath' => $this->root . '/site' )
		);
		list( $lines, $state ) = $this->run_all( $scanner );
		$this->assertSame( array(), $lines );
		$this->assertSame( array( 'The "uploads" content directory is a link and was not scanned: it leads into the plugin\'s storage directory (wp-content/uploads).' ), $state['warnings'] );
	}

	public function test_what_happened_to_a_root_is_reported_however_many_warnings_came_before(): void {
		$this->require_symlinks();
		mkdir( $this->root . '/site' );
		for ( $i = 0; $i < FileScanner::MAX_LISTED + 5; $i++ ) {
			$this->put( sprintf( "themes/bad\x01%02d.txt", $i ) );
		}
		$this->put( 'shared/photo.jpg' );
		symlink( $this->root . '/shared', $this->root . '/uploads' );
		$scanner               = $this->link_scanner( $this->root . '/themes', null, array( array( 'group' => 'plugins', 'path' => $this->root . '/uploads', 'prefix' => 'wp-content/plugins' ), array( 'group' => 'mu-plugins', 'path' => $this->root . '/missing', 'prefix' => 'wp-content/mu-plugins' ) ) );
		list( $lines, $state ) = $this->run_all( $scanner );
		$this->assertSame( FileScanner::MAX_LISTED + 5, $state['counts']['bad_names'], 'the control: more per-entry warnings than the list keeps' );
		$this->assertCount( FileScanner::MAX_LISTED + 2, $state['warnings'] );
		$this->assertStringContainsString( 'The "plugins" content directory is a link; the directory it leads to was backed up', $state['warnings'][ FileScanner::MAX_LISTED ] );
		$this->assertSame( 'A content directory is missing and was not scanned: wp-content/mu-plugins', $state['warnings'][ FileScanner::MAX_LISTED + 1 ] );
	}

	public function test_the_verdict_on_a_root_is_one_for_the_scan_and_the_pack_step(): void {
		$this->require_symlinks();
		$site = $this->root . '/site';
		mkdir( $site );
		$this->put( 'shared/a.txt' );
		$this->put( 'shared/inner/c.txt' );
		$this->put( 'store/b.txt' );
		symlink( $this->root . '/shared', $this->root . '/uploads' );
		symlink( $this->root . '/store', $this->root . '/into-store' );
		$unknown = static function (): string {
			return Links::UNKNOWN;
		};
		$this->assertSame( array( 'link' => false, 'refusal' => '', 'unknown' => true ), Links::root_verdict( $this->root . '/shared', $site, array(), $unknown ), 'unknown, held to the limits by its real path' );
		$this->assertSame( array( 'link' => false, 'refusal' => Links::HOLDS_SITE, 'unknown' => true ), Links::root_verdict( $this->root, $site, array(), $unknown ), 'unknown and holding the site' );
		$this->assertSame( array( 'link' => false, 'refusal' => '', 'unknown' => false ), Links::root_verdict( $this->root . '/shared', $site ), 'the control: a plain directory' );
		$this->assertSame( array( 'link' => true, 'refusal' => '', 'unknown' => false ), Links::root_verdict( $this->root . '/uploads', $site, array( $this->root . '/store' ) ) );
		$this->assertSame( array( 'link' => true, 'refusal' => Links::INTO_SKIPPED, 'unknown' => false ), Links::root_verdict( $this->root . '/into-store', $site, array( $this->root . '/store' ) ) );
		$this->assertSame( array( 'link' => true, 'refusal' => Links::HOLDS_GROUP, 'unknown' => false ), Links::root_verdict( $this->root . '/uploads', $site, array(), null, array( $this->root . '/shared' ) ), 'leads to a group directory' );
		$this->assertSame( array( 'link' => true, 'refusal' => Links::HOLDS_GROUP, 'unknown' => false ), Links::root_verdict( $this->root . '/uploads', $site, array(), null, array( $this->root . '/shared/inner' ) ), 'leads above one' );
		$this->assertSame( array( 'link' => true, 'refusal' => '', 'unknown' => false ), Links::root_verdict( $this->root . '/uploads', $site, array(), null, array( $this->root . '/store' ) ), 'the control: a group elsewhere' );
		$this->assertSame( array( 'link' => true, 'refusal' => Links::HOLDS_SITE, 'unknown' => false ), Links::root_verdict( $this->root . '/uploads', $this->root . '/shared' ), 'the refusals of root_refusal() carry through' );

		// Where a root leads, as a hash: the same through either spelling, different once the link is re-pointed.
		$before = Links::fingerprint( $this->root . '/uploads' );
		$this->assertSame( $before, Links::fingerprint( $this->root . '/shared' ) );
		$this->assertMatchesRegularExpression( '/\A[0-9a-f]{64}\z/', $before, 'a hash, not a path' );
		unlink( $this->root . '/uploads' );
		symlink( $this->root . '/store', $this->root . '/uploads' );
		$this->assertNotSame( $before, Links::fingerprint( $this->root . '/uploads' ) );
		$this->assertSame( '', Links::fingerprint( $this->root . '/missing' ) );
	}

	public function test_a_skipped_directory_spelled_in_another_case_is_skipped_on_windows(): void {
		if ( 'Windows' !== PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'Windows only: paths there compare without regard to case' );
		}
		$this->put( 'c/keep.txt' );
		$this->put( 'c/Store/old.zip' );
		$skip    = strtoupper( $this->root . '/c/store' );
		$scanner = new FileScanner( array( array( 'group' => 'other-content', 'path' => $this->root . '/c', 'prefix' => 'wp-content', 'skip' => array( $skip ) ) ), new Exclusions( array(), array() ) );
		list( $lines, $state ) = $this->run_all( $scanner );
		$this->assertSame( array( 'wp-content/keep.txt' ), $this->paths( $lines ) );
		$this->assertSame( 1, $state['counts']['excluded'] );
	}

	public function test_a_link_to_the_root_of_the_file_system_or_to_nothing_is_never_followed(): void {
		$site = $this->root . '/site';
		mkdir( $site );
		$fs_root = 'Windows' === PHP_OS_FAMILY ? substr( (string) realpath( sys_get_temp_dir() ), 0, 3 ) : '/';
		$this->assertSame( Links::FILESYSTEM_ROOT, Links::root_refusal( $fs_root, $site ) );
		$this->assertSame( Links::NOT_A_DIRECTORY, Links::root_refusal( $this->root . '/missing', $site ) );
		$this->put( 'elsewhere/a.txt' );
		$this->assertSame( '', Links::root_refusal( $this->root . '/elsewhere', $site ), 'the control: a directory beside the site may be followed' );
		$this->assertSame( Links::HOLDS_SITE, Links::root_refusal( $site, $site ) );
		$this->assertSame( Links::HOLDS_SITE, Links::root_refusal( $this->root, $site ) );
		$this->assertSame( Links::SITE_UNKNOWN, Links::root_refusal( $this->root . '/elsewhere', '' ) );
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
