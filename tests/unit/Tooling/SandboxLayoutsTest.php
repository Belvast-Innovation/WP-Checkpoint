<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use PHPUnit\Framework\SkippedTestError;
use WPCheckpoint\Tests\Fixtures\Junction;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Sandbox::refusal() on generated layouts: directories, files and links (to other directories of the layout, and out
 * of the temporary directory to the plugin), the working directory in one of them, and every spelling of each path a
 * test could give (as listed, with a trailing separator, doubled separators, other letter case, through a link, and on
 * Windows 8.3 short names). A model of the layout, independent of the class, says where each spelling leads.
 *
 * Invariants, for every spelling:
 * - I1 (safety): what is not refused is strictly inside the layout, not reached through a link out of it.
 * - I2 (safety): what is not refused is not the working directory, holds it not, and is not inside it.
 * - I3 (liveness): a path inside the layout and away from the working directory is not refused, however it is
 *   spelled, but for an 8.3 name of the entry itself (a link included: the link is removed, not what it leads to;
 *   one that is not there only when it is away from the working directory without regard to case).
 *
 * Only refusal() is called: nothing here deletes but the layout itself, through Sandbox::remove() once the working
 * directory is back. Size: WPCHECKPOINT_SANDBOX_LAYOUTS layouts from seed WPCHECKPOINT_SANDBOX_LAYOUTS_SEED (default
 * 150 from 1).
 */
final class SandboxLayoutsTest extends TestCase {

	/**
	 * Names: short ones, long ones that have 8.3 short names on Windows, a non-ASCII one, and (not on Windows) a name
	 * holding "\\" next to the name after it. Distinct without regard to case.
	 */
	const NAMES = array( 'ab', 'Cd', 'ef', 'Gh', 'a-long-directory', 'Another-Long-One', 'third-long-name', "caf\u{00E9}", 'x\\y', 'y' );

	/** Where a link out of the layout leads: a small directory of the plugin, never removed (refusal() only). */
	const OUT = 'tests/Fixtures/PHPStan';

	/** @var string The working directory to go back to. */
	private $cwd = '';

	/** @var string[] Layouts made. */
	private $made = array();

	protected function set_up(): void {
		parent::set_up();
		$this->cwd = (string) getcwd();
	}

	protected function tear_down(): void {
		if ( '' !== $this->cwd ) {
			chdir( $this->cwd );
		}
		foreach ( $this->made as $dir ) {
			Sandbox::remove( $dir );
		}
		parent::tear_down();
	}

	/**
	 * Build a layout: the model (relative path => "dir", "file", "link:{relative target}" or "link:out") and where
	 * the working directory is (a directory of the layout, relative).
	 *
	 * @param int $seed Seed.
	 * @return array{root: string, nodes: array<string, string>, cwd: string, fold: bool, short: array<string, string>}
	 */
	private function build( int $seed ): array {
		mt_srand( $seed );
		$root         = Sandbox::make( 'sbx-layout' );
		$this->made[] = $root;
		$nodes        = array( '' => 'dir' );
		$dirs         = array( '' );
		for ( $i = 0, $n = mt_rand( 2, 7 ); $i < $n; $i++ ) {
			$parent = $dirs[ mt_rand( 0, count( $dirs ) - 1 ) ];
			if ( substr_count( $parent, '/' ) >= 2 ) {
				continue;
			}
			$name = self::NAMES[ mt_rand( 0, count( self::NAMES ) - 1 ) ];
			if ( 'Windows' === PHP_OS_FAMILY && false !== strpos( $name, '\\' ) ) {
				continue; // A separator there.
			}
			$rel  = ltrim( $parent . '/' . $name, '/' );
			if ( isset( $nodes[ $rel ] ) ) {
				continue;
			}
			mkdir( $root . '/' . $rel );
			$nodes[ $rel ] = 'dir';
			$dirs[]        = $rel;
			if ( 0 === mt_rand( 0, 1 ) ) {
				file_put_contents( $root . '/' . $rel . '/f.txt', 'x' );
				$nodes[ $rel . '/f.txt' ] = 'file';
			}
		}
		// Links: to a directory of the layout, or out of it to the plugin.
		for ( $i = 0, $n = mt_rand( 0, 3 ); $i < $n; $i++ ) {
			$parent = $dirs[ mt_rand( 0, count( $dirs ) - 1 ) ];
			$rel    = ltrim( $parent . '/ln' . $i, '/' );
			$out    = 0 === mt_rand( 0, 3 );
			$target = $out ? '' : $dirs[ mt_rand( 1, max( 1, count( $dirs ) - 1 ) ) ] ?? '';
			if ( ! $out && ( '' === $target || 0 === strpos( $rel . '/', $target . '/' ) ) ) {
				continue; // No link into itself.
			}
			$to = $out ? dirname( __DIR__, 3 ) . '/' . self::OUT : $root . '/' . $target;
			if ( 'Windows' === PHP_OS_FAMILY ) {
				try {
					Junction::make( $to, $root . '/' . $rel );
				} catch ( SkippedTestError $e ) {
					continue; // No junction on this host: the layout goes without it.
				}
			} elseif ( ! symlink( $to, $root . '/' . $rel ) ) {
				continue;
			}
			$nodes[ $rel ] = 'link:' . ( $out ? 'out' : $target );
		}
		$cwd = $dirs[ mt_rand( 0, count( $dirs ) - 1 ) ];
		// Whether this file system folds letter case, and the 8.3 names of the long-named directories (Windows).
		$fold  = self::folds( $root );
		$short = array();
		if ( 'Windows' === PHP_OS_FAMILY ) {
			foreach ( $dirs as $rel ) {
				if ( '' !== $rel && strlen( basename( $rel ) ) > 8 ) {
					$out = array();
					exec( 'cmd /c for %I in ("' . str_replace( '/', '\\', $root . '/' . $rel ) . '") do @echo %~sI', $out );
					$alias = basename( str_replace( '\\', '/', trim( (string) end( $out ) ) ) );
					if ( false !== strpos( $alias, '~' ) ) {
						$short[ $rel ] = $alias;
					}
				}
			}
		}
		return array(
			'root'  => $root,
			'nodes' => $nodes,
			'cwd'   => $cwd,
			'fold'  => $fold,
			'short' => $short,
		);
	}

	/**
	 * Whether the file system under $dir folds letter case (a probe file is left in it, outside the model).
	 */
	private static function folds( string $dir ): bool {
		file_put_contents( $dir . '/case-probe', 'x' );
		clearstatcache();
		return file_exists( $dir . '/CASE-PROBE' );
	}

	/**
	 * Every spelling of every path of a layout, with what the model says it names: "at" (the relative location of
	 * the entry named, its directory resolved and its own name as listed), "out" (reached through a link out of the
	 * layout), "listed" (spelled as listed throughout, no link on the way), "there" and "alias" (the entry itself
	 * named by an 8.3 name).
	 *
	 * @param array $layout Layout.
	 * @return array<int, array{path: string, at: string, out: bool, listed: bool, there: bool}>
	 */
	private static function spellings( array $layout ): array {
		$out = array();
		foreach ( array_keys( $layout['nodes'] ) as $rel ) {
			if ( '' === $rel ) {
				continue;
			}
			$parts    = explode( '/', $rel );
			$variants = array( $parts );
			// Other letter case, of the last name and of the first.
			$variants[] = array_merge( array_slice( $parts, 0, -1 ), array( strtoupper( end( $parts ) ) ) );
			$variants[] = array_merge( array( strtoupper( $parts[0] ) ), array_slice( $parts, 1 ) );
			// 8.3 names, of each long-named directory on the way.
			foreach ( $layout['short'] as $dir => $alias ) {
				if ( 0 === strpos( $rel . '/', $dir . '/' ) ) {
					$v                                  = $parts;
					$v[ substr_count( $dir, '/' ) ] = $alias;
					$variants[]                         = $v;
				}
			}
			foreach ( $variants as $v ) {
				foreach ( array( '', '/' ) as $end ) {
					$out[] = self::judge( $layout, implode( '/', $v ), $end );
				}
				$out[] = self::judge( $layout, implode( '//', $v ), '' );
			}
			// Through a link: its target's entries by the link's name, and a name not there.
			if ( 0 === strpos( $layout['nodes'][ $rel ], 'link:' ) ) {
				$target = substr( $layout['nodes'][ $rel ], 5 );
				$inner  = 'out' === $target ? array_values( array_diff( (array) scandir( dirname( __DIR__, 3 ) . '/' . self::OUT ), array( '.', '..' ) ) ) : array();
				foreach ( array_keys( $layout['nodes'] ) as $other ) {
					if ( 'out' !== $target && '' !== $other && 0 === strpos( $other, $target . '/' ) ) {
						$inner[] = substr( $other, strlen( $target ) + 1 );
					}
				}
				foreach ( $inner as $name ) {
					$out[] = self::judge( $layout, $rel . '/' . $name, '' );
				}
			}
			if ( 'dir' === $layout['nodes'][ $rel ] ) {
				$out[] = self::judge( $layout, $rel . '/not-there', '' );
			}
		}
		return $out;
	}

	/**
	 * What the model says a spelling names.
	 *
	 * @param array  $layout Layout.
	 * @param string $spelt  Relative path as spelt ("/" or "//" between names).
	 * @param string $end    What follows it.
	 * @return array{path: string, at: string, out: bool, listed: bool, there: bool}
	 */
	private static function judge( array $layout, string $spelt, string $end ): array {
		$names  = array_values( array_filter( explode( '/', $spelt ), 'strlen' ) );
		$at     = '';
		$listed = true;
		$there  = true;
		$alias  = false; // The entry itself named by an 8.3 name.
		foreach ( $names as $i => $name ) {
			$last  = count( $names ) - 1 === $i;
			$found = null;
			foreach ( array_keys( $layout['nodes'] ) as $rel ) {
				if ( '' === $rel || dirname( $rel ) !== ( '' === $at ? '.' : $at ) ) {
					continue; // Not an entry of the directory reached so far.
				}
				$own = basename( $rel );
				if ( $own === $name || ( $layout['fold'] && 0 === strcasecmp( $own, $name ) ) || ( $layout['short'][ $rel ] ?? '' ) === $name ) {
					$found  = $rel;
					$listed = $listed && $own === $name;
					$alias  = $last && ( $layout['short'][ $rel ] ?? '' ) === $name && $own !== $name;
					break;
				}
			}
			if ( null === $found ) {
				$there  = false;
				$listed = false; // Not there: nothing to remove, so no liveness to ask of it.
				$at    = ltrim( $at . '/' . implode( '/', array_slice( $names, $i ) ), '/' );
				break;
			}
			$kind = $layout['nodes'][ $found ];
			if ( ! $last && 0 === strpos( $kind, 'link:' ) ) {
				$listed = false;
				if ( 'link:out' === $kind ) {
					return array( 'path' => $layout['root'] . '/' . $spelt . $end, 'at' => '', 'out' => true, 'listed' => false, 'there' => true, 'alias' => false );
				}
				$at = substr( $kind, 5 );
				continue;
			}
			$at = $found;
		}
		return array( 'path' => $layout['root'] . '/' . $spelt . $end, 'at' => $at, 'out' => false, 'listed' => $listed, 'there' => $there, 'alias' => $alias );
	}

	/**
	 * The invariants a judgement breaks on a layout, as "I1: ..." texts.
	 *
	 * @param array    $layout    Layout.
	 * @param string[] $withdrawn Rules withdrawn from Sandbox::judged().
	 * @return string[]
	 */
	private function violations( array $layout, array $withdrawn = array() ): array {
		$found = array();
		chdir( $layout['root'] . ( '' === $layout['cwd'] ? '' : '/' . $layout['cwd'] ) );
		try {
			foreach ( self::spellings( $layout ) as $one ) {
				$why     = Sandbox::judged( $one['path'], $withdrawn );
				$cwd     = $layout['cwd'];
				$related = self::related( $one['at'], $cwd );
				// Compared without regard to case, a path that is not there may be refused near the working directory.
				$near = self::related( strtolower( $one['at'] ), strtolower( $cwd ) );
				if ( '' === $why && $one['out'] ) {
					$found[] = 'I1: through a link out of the layout, not refused: ' . $one['path'];
				}
				if ( '' === $why && ! $one['out'] && $one['there'] && $related ) {
					$found[] = 'I2: the working directory (' . $cwd . '), holding it or inside it, not refused: ' . $one['path'];
				}
				if ( '' !== $why && ! $one['out'] && ! $one['alias'] && ( $one['there'] ? ! $related : ! $near ) ) {
					$found[] = 'I3: away from the working directory and not an 8.3 name for it, refused (' . $why . '): ' . $one['path'];
				}
			}
		} finally {
			chdir( $this->cwd );
		}
		return $found;
	}

	/**
	 * Whether a location and the working directory (relative, '' the layout's root) are one, or one holds the other.
	 */
	private static function related( string $at, string $cwd ): bool {
		return '' === $cwd || $at === $cwd || 0 === strpos( $cwd . '/', $at . '/' ) || 0 === strpos( $at . '/', $cwd . '/' );
	}

	public function test_generated_layouts_keep_the_invariants(): void {
		$count  = max( 1, (int) ( getenv( 'WPCHECKPOINT_SANDBOX_LAYOUTS' ) ?: 150 ) );
		$first  = (int) ( getenv( 'WPCHECKPOINT_SANDBOX_LAYOUTS_SEED' ) ?: 1 );
		$judged = 0;
		for ( $seed = $first; $seed < $first + $count; $seed++ ) {
			$layout = $this->build( $seed );
			$judged += count( self::spellings( $layout ) );
			$this->assertSame( array(), $this->violations( $layout ), 'seed ' . $seed . ': ' . (string) json_encode( $layout ) );
			chdir( $this->cwd );
			Sandbox::remove( $layout['root'] );
			array_pop( $this->made );
		}
		$this->assertGreaterThan( $count * 5, $judged, 'the control: the layouts have spellings to judge' );
	}

	/**
	 * Each rule withdrawn, and the rounds before the rules: each must break an invariant in some generated layout.
	 *
	 * @return array<string, array{0: string[], 1: string, 2: bool}>
	 */
	public function withdrawn(): array {
		return array(
			'the directory judged as written (a path through a link)'      => array( array( 'parent' ), 'I1:', false ),
			'nothing inside the working directory refused'                 => array( array( 'inside' ), 'I2:', false ),
			'an entry judged by where it leads (a link to it refused)'     => array( array( 'resolve' ), 'I3:', false ),
			'an entry judged by the name given (an 8.3 name of it let by)' => array( array( 'listed' ), 'I2:', true ),
			'"\\" a separator everywhere (another entry judged)'             => array( array( 'backslash' ), 'I2:', false ),
		);
	}

	/**
	 * @dataProvider withdrawn
	 *
	 * @param string[] $rules   Rules withdrawn.
	 * @param string   $breaks  The invariant that must break.
	 * @param bool     $windows Whether only Windows (8.3 names) can show it.
	 */
	public function test_the_layouts_catch_each_rule_withdrawn( array $rules, string $breaks, bool $windows ): void {
		if ( $windows && 'Windows' !== PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'Windows only: 8.3 short names.' );
		}
		if ( in_array( 'backslash', $rules, true ) && 'Windows' === PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'Not on Windows: "\\" is a separator there.' );
		}
		$short = false;
		for ( $seed = 1; $seed <= 1000; $seed++ ) {
			$layout = $this->build( $seed );
			$short  = $short || array() !== $layout['short'];
			$this->assertSame( array(), $this->violations( $layout ), 'the control: seed ' . $seed . ' is clean with every rule' );
			$found = $this->violations( $layout, $rules );
			chdir( $this->cwd );
			Sandbox::remove( $layout['root'] );
			array_pop( $this->made );
			foreach ( $found as $violation ) {
				if ( 0 === strpos( $violation, $breaks ) ) {
					$this->addToAssertionCount( 1 );
					return;
				}
			}
		}
		if ( $windows && ! $short ) {
			$this->markTestSkipped( 'This volume makes no 8.3 names.' );
		}
		$this->fail( 'No layout breaks ' . $breaks . ' with ' . implode( ', ', $rules ) . ' withdrawn' );
	}
}
