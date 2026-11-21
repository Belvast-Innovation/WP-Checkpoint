<?php

namespace WPCheckpoint\Tests\Unit\Support;

use WPCheckpoint\Support\Paths;
use WPCheckpoint\Tests\Fixtures\Junction;
use WPCheckpoint\Tests\Fixtures\Permissions;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Paths::positively_gone() for a path spelled with "." and ".." segments (WP-CLI's --path may be: ABSPATH and
 * everything built on it then carry them).
 *
 * The rule: such a path is answered as its resolved spelling is, the one the file system itself would reach ("."
 * dropped; ".." is the parent of what the path before it resolves to, so after a link it is the parent of the link's
 * target, not of the link), or it is not told. Never is it easier to call gone than its resolved spelling.
 *
 * The generated part: trees of directories, files and links (to directories, to files, to nothing), some with a
 * directory closed to the process, and paths walked through them with dots; the resolved spelling comes from the
 * model of the tree, not from the code under test. For every path:
 * - G1 a path that is there is never gone;
 * - G2 a path with no resolved spelling (".." after something that is not there, or after a file) is not told;
 * - G3 a path called gone has a resolved spelling that is called gone too;
 * - G4 where nothing stands in the way (no closed directory, no link to nothing on the way), the answer is the
 *   resolved spelling's answer, so the dots cost nothing.
 * How often each case came up is counted and asserted, ".." right after a link among them.
 */
final class PathsDotsTest extends TestCase {

	/** @var string */
	private $root = '';

	/** @var string[] Directories closed to the process, to open again before removing. */
	private $closed = array();

	protected function set_up(): void {
		parent::set_up();
		$this->root   = Sandbox::make( 'dots' );
		$this->closed = array();
	}

	protected function tear_down(): void {
		foreach ( $this->closed as $dir ) {
			@chmod( $dir, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be gone.
		}
		if ( '' !== $this->root ) {
			Sandbox::remove( $this->root );
		}
		parent::tear_down();
	}

	private function links(): void {
		$probe = $this->root . DIRECTORY_SEPARATOR . 'probe-link';
		if ( ! @symlink( $this->root, $probe ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- probing.
			$this->markTestSkipped( 'Symbolic links cannot be created here.' );
		}
		Sandbox::remove( $probe );
	}

	public function test_a_single_dot_segment_is_dropped(): void {
		mkdir( $this->root . '/base/sub', 0700, true );
		touch( $this->root . '/base/file.txt' );
		$this->assertTrue( Paths::positively_gone( $this->root . '/base/nothing' ), 'the control: gone as spelled plainly' );
		$this->assertTrue( Paths::positively_gone( $this->root . '/base/./nothing' ) );
		$this->assertTrue( Paths::positively_gone( $this->root . '/./base/./nothing/.' ) );
		$this->assertTrue( Paths::positively_gone( $this->root . '/base/./no-such/./deeper' ) );
		$this->assertFalse( Paths::positively_gone( $this->root . '/base/./sub' ), 'there' );
		$this->assertFalse( Paths::positively_gone( $this->root . '/base/./file.txt/.' ), 'the file is there, whatever the file system says of the dot after it' );
		$this->assertFalse( Paths::positively_gone( $this->root . '/base/file.txt/./x' ), 'a parent that is a file cannot be listed' );
		// A listing kept from before never makes what is there gone, under its resolved spelling either.
		$listings = array();
		$this->assertTrue( Paths::positively_gone( $this->root . '/base/later/.', $listings ) );
		touch( $this->root . '/base/later' );
		$this->assertTrue( Paths::positively_gone( $this->root . '/base/later-too', $listings ), 'the control: the listing is the one kept' );
		touch( $this->root . '/base/later-too' );
		$this->assertFalse( Paths::positively_gone( $this->root . '/base/later/.', $listings ) );
	}

	public function test_two_dots_take_the_parent_of_what_is_before_them(): void {
		mkdir( $this->root . '/base/sub', 0700, true );
		$this->assertTrue( Paths::positively_gone( $this->root . '/base/nothing' ), 'the control: gone as spelled plainly' );
		$this->assertTrue( Paths::positively_gone( $this->root . '/base/sub/../nothing' ) );
		$this->assertTrue( Paths::positively_gone( $this->root . '/base/sub/../../base/./sub/../nothing/deeper' ) );
		$this->assertFalse( Paths::positively_gone( $this->root . '/base/sub/../sub' ), 'there' );
		$this->assertFalse( Paths::positively_gone( $this->root . '/base/sub/..' ), 'there' );
		// What is before the dots is not there: the file system resolves no such path, and there is no spelling to
		// answer for.
		$this->assertFalse( Paths::positively_gone( $this->root . '/base/nothing/../gone' ) );
		$this->assertFalse( Paths::positively_gone( $this->root . '/base/nothing/../sub' ) );
		touch( $this->root . '/base/file.txt' );
		$this->assertFalse( Paths::positively_gone( $this->root . '/base/file.txt/../nothing' ), 'a file has no parent to go up from' );
	}

	public function test_two_dots_after_a_link_are_the_parent_of_its_target(): void {
		$this->links();
		if ( Paths::is_windows() ) {
			$this->markTestSkipped( 'Windows takes ".." off the spelling before it looks at links: see the Windows test.' );
		}
		mkdir( $this->root . '/site/content', 0700, true );
		mkdir( $this->root . '/shared/data/uploads', 0700, true );
		touch( $this->root . '/shared/data/kept' );
		touch( $this->root . '/site/content/beside-the-link' );
		symlink( $this->root . '/shared/data/uploads', $this->root . '/site/content/uploads' );

		// ".../uploads/.." is shared/data, not site/content.
		$this->assertFalse( Paths::positively_gone( $this->root . '/site/content/uploads/../kept' ), 'there, next to the link\'s target' );
		$this->assertTrue( Paths::positively_gone( $this->root . '/shared/data/beside-the-link' ), 'the control: gone as spelled plainly' );
		$this->assertTrue( Paths::positively_gone( $this->root . '/site/content/uploads/../beside-the-link' ), 'not next to the target, though a file of that name is next to the link' );
		$this->assertTrue( Paths::positively_gone( $this->root . '/site/content/uploads/../nothing' ) );
	}

	public function test_a_parent_that_cannot_be_listed_is_no_evidence_through_dots_either(): void {
		$this->links();
		Permissions::require_enforced();
		mkdir( $this->root . '/site', 0700, true );
		mkdir( $this->root . '/vault/inner', 0700, true );
		symlink( $this->root . '/vault/inner', $this->root . '/site/link' );
		$this->assertTrue( Paths::positively_gone( $this->root . '/site/link/../nothing' ), 'the control: told while the target\'s parent can be listed' );
		$this->closed[] = $this->root . '/vault';
		chmod( $this->root . '/vault', 0000 );
		clearstatcache( true );
		// Lexically this is site/nothing, which site's listing would call gone. It is vault/nothing, and vault says
		// nothing.
		$this->assertTrue( Paths::positively_gone( $this->root . '/site/nothing' ), 'the control: the lexical parent lists without it' );
		$this->assertFalse( Paths::positively_gone( $this->root . '/site/link/../nothing' ) );
	}

	public function test_the_resolved_spelling_is_looked_at_anew_each_time(): void {
		$this->links();
		if ( Paths::is_windows() ) {
			$this->markTestSkipped( 'Links are not gone through on Windows.' );
		}
		Permissions::require_enforced();
		mkdir( $this->root . '/a/inner', 0700, true );
		mkdir( $this->root . '/site', 0700, true );
		symlink( $this->root . '/a/inner', $this->root . '/site/link' );
		$this->assertTrue( Paths::positively_gone( $this->root . '/site/link/../nothing' ) );
		$this->assertNotFalse( realpath( $this->root . '/site/link' ), 'the resolved path is now in this process\'s cache' );
		// chmod() does not clear the cache of resolved paths (unlink() and symlink() would). The directory can still
		// be listed, so an answer from the resolved path remembered would be "gone"; it cannot be searched, so the
		// link resolves no more.
		$this->closed[] = $this->root . '/a';
		chmod( $this->root . '/a', 0400 );
		$this->assertIsArray( scandir( $this->root . '/a' ), 'the control: it lists' );
		$this->assertFalse( Paths::positively_gone( $this->root . '/site/link/../nothing' ), 'the link no longer resolves: not told' );
	}

	public function test_a_drive_path_with_dots(): void {
		if ( ! Paths::is_windows() ) {
			$this->assertFalse( Paths::positively_gone( 'C:/nope/../x' ), 'a drive letter is not absolute here' );
			$this->assertFalse( Paths::positively_gone( 'C:\\nope\\.\\x' ) );
			return;
		}
		mkdir( $this->root . '\\base\\sub', 0700, true );
		$drive = strtoupper( substr( $this->root, 0, 1 ) ) . ':/';
		$this->assertMatchesRegularExpression( '#^[A-Za-z]:[\\\\/]#', $this->root, 'the control: the sandbox is on a drive' );
		$this->assertTrue( Paths::positively_gone( $this->root . '\\base\\nothing' ), 'the control: gone as spelled plainly' );
		$this->assertTrue( Paths::positively_gone( $this->root . '\\base\\.\\nothing' ) );
		$this->assertTrue( Paths::positively_gone( $this->root . '\\base\\sub\\..\\nothing' ) );
		$this->assertTrue( Paths::positively_gone( str_replace( '\\', '/', $this->root ) . '/base/sub/../nothing' ) );
		$this->assertFalse( Paths::positively_gone( $this->root . '\\base\\sub\\..\\sub' ), 'there' );
		$this->assertFalse( Paths::positively_gone( $this->root . '\\base\\nothing\\..\\gone' ), 'nothing to go up from' );
		// Above the drive's root is the drive's root.
		$top  = 'no-such-top-' . bin2hex( random_bytes( 4 ) );
		$seen = array( $drive => array( '.', '..', 'windows' ) );
		$this->assertTrue( Paths::positively_gone( $drive . '../' . $top . '/x', $seen ) );
		$seen = array( $drive => array( '.', '..', strtolower( $top ) ) );
		$this->assertFalse( Paths::positively_gone( $drive . '../' . $top . '/x', $seen ), 'the control: the root listed with it' );
		// A share is not resolved.
		$this->assertFalse( Paths::positively_gone( '//server-that-is-not-there/share/a/../x' ) );
		// Windows takes ".." off the spelling before it looks at links, so after a link (a junction) the dots are
		// not gone through: not told.
		$junction = $this->root . '\\base\\junction';
		Junction::make( $this->root . '\\base\\sub', $junction );
		try {
			$this->assertFalse( Paths::positively_gone( $junction . '\\..\\nothing' ) );
			$this->assertTrue( Paths::positively_gone( $junction . '\\.\\nothing' ), 'a single dot after it changes nothing' );
		} finally {
			Junction::remove( $junction );
		}
	}

	public function test_under_open_basedir_what_cannot_be_resolved_is_not_told(): void {
		mkdir( $this->root . '/in/sub', 0700, true );
		mkdir( $this->root . '/out/sub', 0700, true );
		$paths = array(
			'in-plain'  => $this->root . '/in/nothing',
			'in-dots'   => $this->root . '/in/sub/../nothing',
			'in-dot'    => $this->root . '/in/./nothing',
			'in-there'  => $this->root . '/in/sub/../sub',
			'out-dots'  => $this->root . '/out/sub/../nothing',
			'out-dot'   => $this->root . '/out/./nothing',
			'out-climb' => $this->root . '/in/sub/../../out/nothing',
		);
		$free  = $this->child( $paths, '' );
		$this->assertSame(
			array(
				'in-plain'  => true,
				'in-dots'   => true,
				'in-dot'    => true,
				'in-there'  => false,
				'out-dots'  => true,
				'out-dot'   => true,
				'out-climb' => true,
			),
			$free,
			'the control: without the restriction every path that is gone is told'
		);
		$held = $this->child( $paths, $this->root . DIRECTORY_SEPARATOR . 'in' . DIRECTORY_SEPARATOR );
		$this->assertSame(
			array(
				'in-plain'  => true,
				'in-dots'   => true,
				'in-dot'    => true,
				'in-there'  => false,
				'out-dots'  => false,
				'out-dot'   => false,
				'out-climb' => false,
			),
			$held
		);
	}

	/**
	 * Paths::positively_gone() for each path, in a process of its own under an open_basedir restriction ('' for none).
	 *
	 * @param array<string, string> $paths   Paths by label.
	 * @param string                $basedir The restriction.
	 * @return array<string, bool>
	 */
	private function child( array $paths, string $basedir ): array {
		$file = $this->root . DIRECTORY_SEPARATOR . 'in' . DIRECTORY_SEPARATOR . 'cases.json';
		file_put_contents( $file, (string) json_encode( $paths ) );
		$output = array();
		$status = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__, 2 ) . '/Fixtures/Support/gone-child.php' ) . ' ' . escapeshellarg( $file ) . ( '' === $basedir ? '' : ' ' . escapeshellarg( $basedir ) ) . ' 2>&1', $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$result = json_decode( (string) end( $output ), true );
		$this->assertIsArray( $result, implode( "\n", $output ) );
		$this->assertSame( $basedir, $result['open_basedir'] ?? null, 'the child ran under the restriction given' );
		Sandbox::remove( $file );
		return $result['gone'];
	}

	public function test_generated_trees_and_dotted_paths(): void {
		$this->links();
		if ( Paths::is_windows() ) {
			$this->markTestSkipped( 'The model resolves ".." as a POSIX file system does.' );
		}
		$modes = true;
		try {
			Permissions::require_enforced();
		} catch ( \RuntimeException $e ) {
			$modes = false; // Root: closed directories are left out, the rest runs.
		}
		$seen = array(
			'paths'              => 0,
			'gone'               => 0,
			'there'              => 0,
			'unresolved'         => 0,
			'dots gone'          => 0,
			'up after link'      => 0,
			'up after link gone' => 0,
			'equal checked'      => 0,
			'closed in the way'  => 0,
			'gone past closed'   => 0,
		);
		for ( $seed = 1; $seed <= 120; $seed++ ) {
			mt_srand( 7100 + $seed );
			$base  = $this->root . '/t' . $seed;
			$model = $this->tree( $base, $modes && 0 === $seed % 3 );
			clearstatcache( true );
			for ( $n = 0; $n < 40; $n++ ) {
				$walk = $this->walk( $model );
				$path = $base . '/' . implode( '/', $walk['segments'] );
				$got  = Paths::positively_gone( $path );
				$say  = 'seed ' . $seed . ': ' . implode( '/', $walk['segments'] ) . ' resolves to ' . var_export( $walk['resolved'], true );
				++$seen['paths'];
				$seen['gone']          += $got ? 1 : 0;
				$seen['there']         += $walk['there'] ? 1 : 0;
				$seen['up after link'] += $walk['up_after_link'] ? 1 : 0;
				if ( $walk['there'] ) {
					$this->assertFalse( $got, 'G1 ' . $say );
				}
				if ( null === $walk['resolved'] ) {
					++$seen['unresolved'];
					$this->assertFalse( $got, 'G2 ' . $say );
					continue;
				}
				$want = Paths::positively_gone( '' === $walk['resolved'] ? $base : $base . '/' . $walk['resolved'] );
				if ( $got ) {
					$this->assertTrue( $want, 'G3 ' . $say );
					$seen['dots gone']          += $walk['dots'] ? 1 : 0;
					$seen['up after link gone'] += $walk['up_after_link'] ? 1 : 0;
					$seen['gone past closed']   += '' !== $model['closed'] ? 1 : 0;
				}
				if ( '' !== $model['closed'] ) {
					++$seen['closed in the way'];
				} elseif ( ! $walk['dangling'] ) {
					++$seen['equal checked'];
					$this->assertSame( $want, $got, 'G4 ' . $say );
				}
			}
			if ( '' !== $model['closed'] ) {
				chmod( $model['closed'], 0700 );
			}
		}
		foreach ( $seen as $what => $times ) {
			if ( ! $modes && in_array( $what, array( 'closed in the way', 'gone past closed' ), true ) ) {
				continue;
			}
			$this->assertGreaterThan( 20, $times, 'the control: ' . $what . ' came up (' . json_encode( $seen ) . ')' );
		}
	}

	/**
	 * A tree under $base and its model: 'nodes' by path relative to the base ('' is the base) => 'dir', 'file', or
	 * array( 'link', target relative path, what the target is: 'dir' | 'file' | 'none' ); 'closed' the directory
	 * closed to the process (absolute), or ''.
	 *
	 * @param string $base  Directory to make.
	 * @param bool   $close Whether to close one directory.
	 * @return array{nodes: array<string, mixed>, closed: string, base: string}
	 */
	private function tree( string $base, bool $close ): array {
		mkdir( $base, 0700 );
		$nodes = array( '' => 'dir' );
		$dirs  = array( '' );
		$files = array();
		$count = mt_rand( 4, 8 );
		for ( $i = 1; $i <= $count; $i++ ) {
			$parent = $dirs[ mt_rand( 0, count( $dirs ) - 1 ) ];
			if ( substr_count( $parent, '/' ) >= 2 ) {
				$parent = '';
			}
			$rel = ltrim( $parent . '/d' . $i, '/' );
			mkdir( $base . '/' . $rel, 0700 );
			$nodes[ $rel ] = 'dir';
			$dirs[]        = $rel;
		}
		$count = mt_rand( 2, 4 );
		for ( $i = 1; $i <= $count; $i++ ) {
			$rel = ltrim( $dirs[ mt_rand( 0, count( $dirs ) - 1 ) ] . '/f' . $i, '/' );
			touch( $base . '/' . $rel );
			$nodes[ $rel ] = 'file';
			$files[]       = $rel;
		}
		$count = mt_rand( 2, 5 );
		for ( $i = 1; $i <= $count; $i++ ) {
			$rel  = ltrim( $dirs[ mt_rand( 0, count( $dirs ) - 1 ) ] . '/l' . $i, '/' );
			$roll = mt_rand( 1, 100 );
			if ( $roll <= 70 ) {
				$target = $dirs[ mt_rand( 1, count( $dirs ) - 1 ) ];
				$kind   = 'dir';
			} elseif ( $roll <= 85 ) {
				$target = $files[ mt_rand( 0, count( $files ) - 1 ) ];
				$kind   = 'file';
			} else {
				$target = ltrim( $dirs[ mt_rand( 0, count( $dirs ) - 1 ) ] . '/nowhere' . $i, '/' );
				$kind   = 'none';
			}
			symlink( $base . '/' . $target, $base . '/' . $rel );
			$nodes[ $rel ] = array( 'link', $target, $kind );
		}
		$closed = '';
		if ( $close ) {
			$closed         = $base . '/' . $dirs[ mt_rand( 1, count( $dirs ) - 1 ) ];
			$this->closed[] = $closed;
			chmod( $closed, 0000 );
		}
		return array(
			'nodes'  => $nodes,
			'closed' => $closed,
			'base'   => $base,
		);
	}

	/**
	 * A path walked through the model with dots, and what the model says of it: 'resolved' its resolved spelling
	 * relative to the base (null when it has none), 'there' whether something is there under the resolved spelling,
	 * 'dots' whether it has a dot segment, 'up_after_link' whether a ".." comes right after a link to a directory,
	 * 'dangling' whether a link to nothing is on the way.
	 *
	 * @param array{nodes: array<string, mixed>, closed: string, base: string} $model Model.
	 * @return array{segments: string[], resolved: string|null, there: bool, dots: bool, up_after_link: bool, dangling: bool}
	 */
	private function walk( array $model ): array {
		$nodes    = $model['nodes'];
		$segments = array();
		$at       = '';      // What the walk has reached, relative to the base: it is there.
		$kind     = 'dir';   // What that is: 'dir', 'file', or 'nothing' once the walk left what is there.
		$via      = null;    // The link the path ends on so far (not followed unless something comes after it).
		$tail     = array(); // The names after $at.
		$out      = array(
			'dots'          => false,
			'up_after_link' => false,
			'dangling'      => false,
		);
		$length   = mt_rand( 2, 7 );
		for ( $i = 0; $i < $length; $i++ ) {
			$children = array();
			if ( 'dir' === $kind ) {
				foreach ( array_keys( $nodes ) as $rel ) {
					if ( '' !== $rel && ltrim( $at . '/' . basename( $rel ), '/' ) === $rel ) {
						$children[] = basename( $rel );
					}
				}
			}
			$roll = mt_rand( 1, 100 );
			if ( $roll <= 14 ) {
				$segment = '.';
			} elseif ( $roll <= 40 && ( '' !== $at || 'dir' !== $kind ) ) {
				$segment = '..';
			} elseif ( $roll <= 82 && array() !== $children ) {
				$segment = $children[ mt_rand( 0, count( $children ) - 1 ) ];
			} else {
				$segment = 'x' . mt_rand( 1, 3 );
			}
			$segments[] = $segment;
			if ( '.' === $segment ) {
				$out['dots'] = true;
				$via         = null; // A link before it is followed.
				continue;
			}
			if ( '..' === $segment ) {
				$out['dots'] = true;
				if ( 'dir' !== $kind ) {
					return array_merge(
						$out,
						array(
							'segments' => $segments,
							'resolved' => null,
							'there'    => false,
						)
					);
				}
				$out['up_after_link'] = $out['up_after_link'] || null !== $via;
				$via                  = null;
				$at                   = false === strpos( $at, '/' ) ? '' : substr( $at, 0, (int) strrpos( $at, '/' ) );
				continue;
			}
			$via = null;
			if ( 'dir' !== $kind ) {
				$tail[] = $segment;
				continue;
			}
			$rel  = ltrim( $at . '/' . $segment, '/' );
			$node = $nodes[ $rel ] ?? null;
			if ( null === $node ) {
				$kind   = 'nothing';
				$tail[] = $segment;
			} elseif ( is_string( $node ) ) {
				$at   = $rel;
				$kind = $node;
			} else {
				$via = $rel;
				if ( 'none' === $node[2] ) {
					$out['dangling'] = true;
					$kind            = 'nothing';
					$at              = false === strpos( $node[1], '/' ) ? '' : substr( $node[1], 0, (int) strrpos( $node[1], '/' ) );
					$tail[]          = basename( $node[1] );
				} else {
					$at   = $node[1];
					$kind = $node[2];
				}
			}
		}
		// A link the path ends on is not followed: the path is the link, and it is there.
		$resolved = null !== $via ? $via : ltrim( $at . ( array() === $tail ? '' : '/' . implode( '/', $tail ) ), '/' );
		return array_merge(
			$out,
			array(
				'segments' => $segments,
				'resolved' => $resolved,
				'there'    => null !== $via || array() === $tail,
			)
		);
	}
}
