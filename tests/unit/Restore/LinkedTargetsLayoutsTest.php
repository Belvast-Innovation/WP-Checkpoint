<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Files\Links;
use WPCheckpoint\Files\ScanRoots;
use WPCheckpoint\Restore\LinkedTargets;
use WPCheckpoint\Support\Paths;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Whether a content group is asked about (LinkedTargets::reached_from_outside()), on generated paths in a real tree:
 * a deployment (current -> releases/5), its WordPress directory and a content directory beside it (Bedrock), a shared
 * directory, another installation, and links between them at every depth; paths with ".", "..", missing components,
 * doubled and trailing slashes. The expectation comes from a model of the tree the test built, walked with the
 * system's rules, not from the code under test.
 *
 * Invariants:
 * - L1 a path that passes no link leading outside the site (outside the WordPress directory and the trusted root) is
 *   never asked about, and neither is one whose only links lead into the site, into the trusted root, or (when more
 *   components follow) to a directory holding the WordPress directory (a deployment's link);
 * - L2 a path that passes a link leading outside the site, or ends on a link to a directory holding the WordPress
 *   directory, is asked about.
 *
 * Reverse validation: the two earlier rules (the directory itself only; the path as given differs from its resolved
 * form) and the rule without the deployment's exception each break an invariant on the generated paths.
 */
final class LinkedTargetsLayoutsTest extends TestCase {

	const SEED      = 1;
	const SEQUENCES = 400;

	/** @var string */
	private $dir = '';

	/**
	 * The model: directory (physical) => entry name => array( kind, physical target, category for links ).
	 *
	 * @var array<string, array<string, array{0: string, 1: string, 2: string}>>
	 */
	private $tree = array();

	/** @var string The WordPress directory, resolved. */
	private $abspath = '';

	protected function set_up(): void {
		parent::set_up();
		$this->dir = Sandbox::make( 'linked-layouts' );
		if ( ! @symlink( $this->dir, $this->dir . '/probe-link' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a probe.
			$this->markTestSkipped( 'This system does not let the test make symbolic links (Windows without the privilege); the rule is the same there.' );
		}
		Sandbox::remove( $this->dir . '/probe-link' );
		$this->dir = rtrim( Paths::normalize( (string) realpath( $this->dir ) ), '/' );
	}

	protected function tear_down(): void {
		if ( '' !== $this->dir ) {
			Sandbox::remove( $this->dir );
		}
		parent::tear_down();
	}

	/**
	 * Build the tree on disk and in the model. Links are declared with their category by hand.
	 *
	 * @param bool $trusted Whether the shared directory is the trusted deployment root.
	 */
	private function build( bool $trusted ): void {
		$d             = $this->dir;
		$release       = "{$d}/srv/app/releases/5";
		$this->abspath = "{$release}/web/wp";
		$dirs          = array(
			"{$d}/srv",
			"{$d}/srv/app",
			"{$d}/srv/app/releases",
			$release,
			"{$release}/web",
			"{$release}/web/wp",
			"{$release}/web/wp/wp-content",
			"{$release}/web/wp/wp-content/uploads",
			"{$release}/web/wp/wp-content/plugins",
			"{$release}/web/wp/wp-content/inside-target",
			"{$release}/web/app",
			"{$release}/web/app/uploads",
			"{$release}/web/app/plugins",
			"{$d}/srv/app/shared",
			"{$d}/srv/app/shared/uploads",
			"{$d}/srv/other",
			"{$d}/srv/other/wp-content",
			"{$d}/srv/other/wp-content/uploads",
			"{$d}/srv/other/wp-content/plugins",
		);
		$this->tree    = array( $d => array() );
		foreach ( $dirs as $path ) {
			if ( ! is_dir( $path ) ) {
				mkdir( $path, 0755, true );
			}
			$this->tree[ $path ]                              = $this->tree[ $path ] ?? array();
			$this->tree[ dirname( $path ) ][ basename( $path ) ] = array( 'dir', $path, '' );
		}
		$shared = $trusted ? 'trusted' : 'outside';
		$links  = array(
			array( "{$d}/srv/app/current", $release, 'holds' ),
			array( "{$release}/web/wp/wp-content/ln-inside", "{$release}/web/wp/wp-content/inside-target", 'inside' ),
			array( "{$release}/web/wp/wp-content/ln-shared", "{$d}/srv/app/shared/uploads", $shared ),
			array( "{$release}/web/wp/wp-content/ln-other", "{$d}/srv/other/wp-content", 'outside' ),
			array( "{$release}/web/wp/wp-content/ln-up", "{$d}/srv/app", 'holds' ),
			array( "{$release}/web/app/ln-shared", "{$d}/srv/app/shared", $shared ),
			array( "{$d}/srv/app/shared/ln-back", "{$release}/web/wp/wp-content", 'inside' ),
			array( "{$d}/srv/other/wp-content/ln-home", "{$release}/web/wp/wp-content/uploads", 'inside' ),
		);
		foreach ( $links as $link ) {
			if ( ! is_link( $link[0] ) ) {
				symlink( $link[1], $link[0] );
			}
			$this->tree[ dirname( $link[0] ) ][ basename( $link[0] ) ] = array( 'link', $link[1], $link[2] );
		}
	}

	/**
	 * A generated path and what the model says of it.
	 *
	 * @return array{path: string, asked: bool, kinds: string[]}
	 */
	private function generate(): array {
		$d        = $this->dir;
		$starts   = array(
			array( "{$d}/srv/app/current/web/wp/wp-content", "{$d}/srv/app/releases/5/web/wp/wp-content", array( 'holds' ) ),
			array( "{$d}/srv/app/releases/5/web/wp/wp-content", "{$d}/srv/app/releases/5/web/wp/wp-content", array() ),
			array( "{$d}/srv/app/current/web/app", "{$d}/srv/app/releases/5/web/app", array( 'holds' ) ),
			array( "{$d}/srv/app/releases/5/web/app", "{$d}/srv/app/releases/5/web/app", array() ),
		);
		$start    = $starts[ mt_rand( 0, count( $starts ) - 1 ) ];
		$path     = $start[0];
		$physical = $start[1];
		$passed   = array();
		foreach ( $start[2] as $category ) {
			$passed[] = array( $category, false );
		}
		$gone    = '';
		$climbed = false;
		$kinds   = array();
		$steps = mt_rand( 1, 5 );
		for ( $k = 0; $k < $steps; $k++ ) {
			$roll = mt_rand( 1, 10 );
			if ( 1 === $roll ) {
				$segment = '.';
			} elseif ( 2 === $roll && $physical !== $d ) {
				$segment = '..';
			} elseif ( 3 === $roll || '' !== $gone || ! isset( $this->tree[ $physical ] ) || array() === $this->tree[ $physical ] ) {
				$segment = 'nope' . $k;
			} elseif ( $climbed && array() !== $this->links_in( $physical ) ) {
				// Back above a component that was not there: a link next, so the walk must judge it again.
				$links   = $this->links_in( $physical );
				$segment = (string) $links[ mt_rand( 0, count( $links ) - 1 ) ];
				$kinds[] = 'link-after-climb';
			} else {
				$names   = array_keys( $this->tree[ $physical ] );
				$segment = (string) $names[ mt_rand( 0, count( $names ) - 1 ) ];
			}
			$path .= ( 1 === mt_rand( 1, 6 ) ? '//' : '/' ) . $segment;
			if ( '.' === $segment ) {
				$kinds[] = 'dot';
				continue;
			}
			if ( '..' === $segment ) {
				$kinds[]  = array() !== $passed && end( $passed )[1] ? 'up-after-link' : 'up';
				$physical = dirname( $physical );
				$climbed  = false;
				if ( '' !== $gone && 0 !== strpos( $physical . '/', $gone . '/' ) ) {
					$gone    = '';
					$climbed = true;
				}
				foreach ( $passed as $i => $entry ) {
					$passed[ $i ][1] = false; // Something follows each link passed so far.
				}
				continue;
			}
			foreach ( $passed as $i => $entry ) {
				$passed[ $i ][1] = false;
			}
			$climbed = false;
			if ( '' !== $gone || ! isset( $this->tree[ $physical ][ $segment ] ) ) {
				$kinds[]  = 'missing';
				$gone     = '' === $gone ? $physical . '/' . $segment : $gone;
				$physical = $physical . '/' . $segment;
				continue;
			}
			$entry = $this->tree[ $physical ][ $segment ];
			if ( 'link' === $entry[0] ) {
				$kinds[]  = 'link-' . $entry[2];
				$passed[] = array( $entry[2], true ); // The last component, until something follows.
			} else {
				$kinds[] = 'dir';
			}
			$physical = $entry[1];
		}
		if ( 1 === mt_rand( 1, 5 ) ) {
			$path .= '/';
		}
		$asked = false;
		foreach ( $passed as $entry ) {
			if ( 'outside' === $entry[0] || ( 'holds' === $entry[0] && $entry[1] ) ) {
				$asked = true;
			}
		}
		return array(
			'path'  => $path,
			'asked' => $asked,
			'kinds' => $kinds,
		);
	}

	/**
	 * The names of the links in a directory of the model.
	 *
	 * @return string[]
	 */
	private function links_in( string $dir ): array {
		$out = array();
		foreach ( $this->tree[ $dir ] ?? array() as $name => $entry ) {
			if ( 'link' === $entry[0] ) {
				$out[] = (string) $name;
			}
		}
		return $out;
	}

	/**
	 * The violations of a judge over the generated paths, and how often each kind of step and outcome occurred.
	 *
	 * @param callable $judge function( string $path, string $abspath, string $trusted ): bool.
	 * @return array{violations: string[], seen: array<string, int>}
	 */
	private function run_judge( callable $judge ): array {
		$violations = array();
		$seen       = array();
		foreach ( array( false, true ) as $trusted ) {
			$this->build( $trusted );
			$root = $trusted ? $this->dir . '/srv/app/shared' : '';
			mt_srand( self::SEED );
			for ( $n = 0; $n < self::SEQUENCES; $n++ ) {
				$case = $this->generate();
				clearstatcache( true );
				$got = (bool) call_user_func( $judge, $case['path'], $this->abspath, $root );
				foreach ( array_unique( $case['kinds'] ) as $kind ) {
					$seen[ $kind ] = ( $seen[ $kind ] ?? 0 ) + 1;
				}
				$seen[ $case['asked'] ? 'outcome-asked' : 'outcome-not-asked' ] = ( $seen[ $case['asked'] ? 'outcome-asked' : 'outcome-not-asked' ] ?? 0 ) + 1;
				if ( $got !== $case['asked'] ) {
					$violations[] = sprintf( '%s (trusted %s): %s, expected %s', $case['asked'] ? 'L2' : 'L1', $trusted ? 'yes' : 'no', $case['path'], $case['asked'] ? 'asked' : 'not asked' );
				}
			}
		}
		return array(
			'violations' => $violations,
			'seen'       => $seen,
		);
	}

	public function test_the_rule_holds_on_every_generated_path(): void {
		$result = $this->run_judge( array( LinkedTargets::class, 'reached_from_outside' ) );
		$this->assertSame( array(), array_slice( $result['violations'], 0, 10 ), count( $result['violations'] ) . ' violations' );
		// The control: every kind of step and both outcomes occurred.
		foreach ( array( 'dot', 'up', 'up-after-link', 'missing', 'link-after-climb', 'dir', 'link-inside', 'link-outside', 'link-holds', 'link-trusted', 'outcome-asked', 'outcome-not-asked' ) as $kind ) {
			$this->assertGreaterThan( 0, $result['seen'][ $kind ] ?? 0, 'the control: ' . $kind . ' occurred' );
		}
	}

	/**
	 * @return array<string, array{0: callable, 1: string}>
	 */
	public function earlier_rules(): array {
		$resolved = static function ( string $path ): string {
			return ScanRoots::resolved( rtrim( Paths::normalize( $path ), '/' ) );
		};
		return array(
			'the directory itself only (b92734e)'           => array(
				static function ( string $path, string $abspath, string $trusted ) use ( $resolved ): bool {
					$given = rtrim( Paths::normalize( $path ), '/' );
					return Links::LINK === Links::state( $given ) && LinkedTargets::outside( $resolved( $path ), $abspath, $trusted );
				},
				'L2',
			),
			'the path differs from its resolved form (13026b1)' => array(
				static function ( string $path, string $abspath, string $trusted ) use ( $resolved ): bool {
					$given = rtrim( Paths::normalize( $path ), '/' );
					return ( $given !== $resolved( $path ) || Links::LINK === Links::state( $given ) ) && LinkedTargets::outside( $resolved( $path ), $abspath, $trusted );
				},
				'L1',
			),
			'without the deployment\'s exception'           => array(
				static function ( string $path, string $abspath, string $trusted ): bool {
					// The rule, with every link that holds the WordPress directory taken as leading outside.
					return LinkedTargets::reached_from_outside( $path, $abspath, $trusted ) || false !== strpos( Paths::normalize( $path ), '/current/' ) || false !== strpos( Paths::normalize( $path ), '/ln-up/' );
				},
				'L1',
			),
		);
	}

	/**
	 * @dataProvider earlier_rules
	 */
	public function test_an_earlier_rule_breaks_an_invariant( callable $judge, string $invariant ): void {
		$result = $this->run_judge( $judge );
		$this->assertNotSame( array(), $result['violations'], 'the generator finds it' );
		$this->assertNotSame( array(), preg_grep( '/\A' . $invariant . ' /', $result['violations'] ), 'on ' . $invariant . ': ' . implode( ' | ', array_slice( $result['violations'], 0, 3 ) ) );
	}
}
