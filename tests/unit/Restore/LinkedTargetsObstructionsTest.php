<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\LinkedTargets;
use WPCheckpoint\Support\Paths;
use WPCheckpoint\Tests\Fixtures\Restore\JudgeChild;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Whether a content group is asked about when what would tell it is in the way: every part of another
 * installation's root, core, front controller and the zone around them is obstructed in turn, alone and in pairs,
 * on a tree whose every directory carries its true owner. Obstructions: a directory that cannot be listed (0311),
 * searched (0600) or either (0000); a file that cannot be read (0000); an entry moved elsewhere and a link left in
 * its place, the link leading somewhere reachable, into a directory that cannot be searched, or out of open_basedir
 * (the cases run in a child process under it).
 *
 * Invariant (from the owners, not from the rule): a directory of another installation, or one whose owner cannot be
 * told, is never judged this site's, whatever is in the way. Questions about this site's own directories are
 * allowed (the safe side) and counted.
 *
 * The class of fault it holds off: a lookup that fails taken as "not there" (PR #83 reviews six and seven: names
 * looked up one by one on a thread-safe PHP and under open_basedir; a subdirectory check beside a front controller).
 */
final class LinkedTargetsObstructionsTest extends TestCase {

	const SITE  = 'site';
	const OTHER = 'other';
	const NONE  = 'unknown-owner';

	/** @var string */
	private $dir = '';

	/** @var int[] Restricted scenarios run, and those whose verdicts differ from the same tree judged here unrestricted. */
	private $restricted = array( 0, 0 );

	/** @var string[] Paths whose mode a scenario changed (given back before the sandbox goes). */
	private $changed = array();

	protected function set_up(): void {
		parent::set_up();
		$this->dir = Sandbox::make( 'obstructions' );
		if ( '\\' === DIRECTORY_SEPARATOR || ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) ) {
			$this->markTestSkipped( 'Needs permissions that hold for this user (not on Windows, not as root).' );
		}
		$this->dir = rtrim( Paths::normalize( (string) realpath( $this->dir ) ), '/' );
	}

	protected function tear_down(): void {
		$this->give_back();
		if ( '' !== $this->dir ) {
			Sandbox::remove( $this->dir );
		}
		parent::tear_down();
	}

	private function give_back(): void {
		foreach ( array_reverse( $this->changed ) as $path ) {
			@chmod( $path, is_dir( $path ) ? 0755 : 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- gone already: nothing to give back.
		}
		$this->changed = array();
	}

	private static function mk( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
	}

	/**
	 * A layout under a base of its own: its targets with their owners, its zones, and the points that can be in the way.
	 *
	 * @return array{targets: array<int, array{0: string, 1: string}>, zones: string[], points: array<int, array{0: string, 1: string}>}
	 */
	private function build( string $layout, string $base ): array {
		if ( 'nested' === $layout ) {
			$site = "{$base}/site";
			self::mk( "{$site}/wp-content/uploads" );
			file_put_contents( "{$site}/wp-load.php", '<?php' );
			file_put_contents( "{$site}/wp-content/index.php", "<?php\n// Silence is golden.\n" );
			self::mk( "{$site}/staging/wp-content/uploads" );
			file_put_contents( "{$site}/staging/wp-config.php", '<?php' );
			self::mk( "{$site}/blog/wp" );
			self::mk( "{$site}/blog/wp-content/uploads" );
			file_put_contents( "{$site}/blog/index.php", "<?php\nrequire __DIR__ . '/wp/wp-blog-header.php';\n" );
			file_put_contents( "{$site}/blog/wp/wp-load.php", '<?php' );
			return array(
				'targets' => array(
					array( "{$site}/wp-content/uploads", self::SITE ),
					array( "{$site}/staging/wp-content/uploads", self::OTHER ),
					array( "{$site}/blog/wp-content/uploads", self::OTHER ),
				),
				'zones'   => array( $site, '', '' ),
				'points'  => array(
					array( "{$site}/staging", 'dir' ),
					array( "{$site}/staging/wp-config.php", 'file' ),
					array( "{$site}/blog", 'dir' ),
					array( "{$site}/blog/index.php", 'file' ),
					array( "{$site}/blog/wp", 'dir' ),
					array( "{$site}/blog/wp/wp-load.php", 'file' ),
				),
			);
		}
		// zone: the directory of wp-config.php holds this site's WordPress directory, its content, another site and a
		// directory that site shares.
		$web = "{$base}/web";
		self::mk( "{$web}/wp" );
		self::mk( "{$web}/app/uploads" );
		self::mk( "{$web}/other/wp-content/uploads" );
		self::mk( "{$web}/shared/uploads" );
		file_put_contents( "{$web}/wp-config.php", '<?php' );
		file_put_contents( "{$web}/wp/wp-load.php", '<?php' );
		file_put_contents( "{$web}/other/wp-load.php", '<?php' );
		return array(
			'targets' => array(
				array( "{$web}/app/uploads", self::SITE ),
				array( "{$web}/shared/uploads", self::NONE ),
				array( "{$web}/other/wp-content/uploads", self::OTHER ),
			),
			'zones'   => array( "{$web}/wp", '', "{$web}" ), // The zone of wp-config.php: zone_stands() decides.
			'points'  => array(
				array( "{$web}/other", 'dir' ),
				array( "{$web}/other/wp-load.php", 'file' ),
			),
		);
	}

	/**
	 * Put an obstruction in the way at a point. Returns whether the case needs open_basedir.
	 */
	private function obstruct( string $base, string $point, string $kind ): bool {
		static $n = 0;
		++$n;
		switch ( $kind ) {
			case '0311':
			case '0600':
			case '0000':
				chmod( $point, octdec( $kind ) );
				$this->changed[] = $point;
				return false;
			case 'link':
				// Nothing in the way but a link: the entry moved elsewhere, reachable, a link left in its place.
				$moved = "{$base}-moved{$n}";
				self::mk( $moved );
				rename( $point, $moved . '/' . basename( $point ) );
				symlink( $moved . '/' . basename( $point ), $point );
				return false;
			case 'link-closed':
				// The entry moved into a directory that cannot be searched, a link left in its place.
				$closed = "{$base}-closed{$n}";
				self::mk( $closed );
				rename( $point, $closed . '/' . basename( $point ) );
				symlink( $closed . '/' . basename( $point ), $point );
				chmod( $closed, 0600 );
				$this->changed[] = $closed;
				return false;
			default: // link-out: moved out of the open_basedir the case runs under, a link left in its place.
				$out = "{$base}-out{$n}";
				self::mk( $out );
				rename( $point, $out . '/' . basename( $point ) );
				symlink( $out . '/' . basename( $point ), $point );
				return true;
		}
	}

	/**
	 * The verdicts of the targets, in this process or in a child under open_basedir (the base and the repository).
	 *
	 * @return string[]
	 */
	private function verdicts( array $layout, string $base, bool $restricted ): array {
		$zones = LinkedTargets::zones( $layout['zones'][0], $layout['zones'][1], $layout['zones'][2], static function (): bool {
			return false;
		} );
		$cases = array();
		foreach ( $layout['targets'] as $target ) {
			$cases[] = array(
				'given'     => $target[0],
				'zones'     => $zones,
				'zone_args' => $layout['zones'],
			);
		}
		clearstatcache( true );
		$here = array_map(
			static function ( array $case ): string {
				return LinkedTargets::judge( $case['given'], $case['zones'] )['verdict'];
			},
			$cases
		);
		if ( ! $restricted ) {
			return $here;
		}
		// Under the restriction the zones are found there too: zone_stands() reads the zone's entries.
		$cases = array_map(
			static function ( array $case ): array {
				unset( $case['zones'] );
				return $case;
			},
			$cases
		);
		$repo  = dirname( __DIR__, 3 );
		file_put_contents( $base . '/cases.json', (string) json_encode( $cases ) );
		$there = JudgeChild::verdicts( $base . '/cases.json', $base . PATH_SEPARATOR . $repo );
		++$this->restricted[0];
		if ( $there !== $here ) {
			++$this->restricted[1];
		}
		return $there;
	}

	public function test_no_directory_of_another_installation_is_judged_this_sites_whatever_is_in_the_way(): void {
		$kinds      = array(
			'dir'  => array( '0311', '0600', '0000', 'link', 'link-closed', 'link-out' ),
			'file' => array( '0000', 'link', 'link-out' ),
		);
		$violations = array();
		$allowed    = 0;
		$scenarios  = 0;
		$changed    = array(); // The control: per obstruction kind, how often it changed a verdict about another's directory.
		foreach ( array( 'nested', 'zone' ) as $name ) {
			$baseline = array();
			// The obstructions: each point and kind alone, and every pair of two points.
			$plain  = $this->build( $name, $this->dir . '/probe-' . $name );
			$single = array();
			foreach ( $plain['points'] as $i => $point ) {
				foreach ( $kinds[ $point[1] ] as $kind ) {
					$single[] = array( array( $i, $kind ) );
				}
			}
			$pairs = array();
			foreach ( $single as $a ) {
				foreach ( $single as $b ) {
					if ( $a[0][0] < $b[0][0] ) {
						$pairs[] = array( $a[0], $b[0] );
					}
				}
			}
			foreach ( array_merge( array( array() ), $single, $pairs ) as $k => $scenario ) {
				++$scenarios;
				$base       = $this->dir . "/{$name}-{$k}";
				$layout     = $this->build( $name, $base );
				$restricted = false;
				// Deeper points first, so that moving or closing one does not take the next one out of reach.
				usort(
					$scenario,
					static function ( array $x, array $y ) use ( $layout ): int {
						return strlen( $layout['points'][ $y[0] ][0] ) <=> strlen( $layout['points'][ $x[0] ][0] );
					}
				);
				foreach ( $scenario as $step ) {
					$restricted = $this->obstruct( $base, $layout['points'][ $step[0] ][0], $step[1] ) || $restricted;
				}
				$verdicts = $this->verdicts( $layout, $base, $restricted );
				$label    = $name . ' ' . ( array() === $scenario ? '(nothing in the way)' : implode( ' + ', array_map( static function ( array $s ) use ( $layout ): string {
					return basename( $layout['points'][ $s[0] ][0] ) . ':' . $s[1];
				}, $scenario ) ) );
				foreach ( $layout['targets'] as $t => $target ) {
					if ( self::SITE !== $target[1] && LinkedTargets::SITE === $verdicts[ $t ] ) {
						$violations[] = $label . ': ' . basename( dirname( dirname( $target[0] ) ) ) . '/' . basename( dirname( $target[0] ) ) . '/' . basename( $target[0] ) . ' judged this site\'s';
					} elseif ( self::SITE === $target[1] && LinkedTargets::SITE !== $verdicts[ $t ] ) {
						++$allowed;
					}
					if ( self::SITE !== $target[1] && 1 === count( $scenario ) && $baseline[ $t ] !== $verdicts[ $t ] ) {
						$changed[ $scenario[0][1] ] = ( $changed[ $scenario[0][1] ] ?? 0 ) + 1;
					}
				}
				if ( array() === $scenario ) {
					$baseline = $verdicts;
				}
				$this->give_back();
			}
		}
		$this->assertSame( array(), array_slice( $violations, 0, 10 ), count( $violations ) . ' violations in ' . $scenarios . ' scenarios' );
		// The control: every kind of obstruction was in effect where it could hide another installation (it changed a
		// verdict about another's directory from the one with nothing in the way).
		foreach ( array( '0311', '0600', '0000', 'link', 'link-closed', 'link-out' ) as $kind ) {
			$this->assertGreaterThan( 0, $changed[ $kind ] ?? 0, 'the control: ' . $kind . ' was in the way' );
		}
		$this->assertGreaterThan( 50, $scenarios, 'the control: alone and in pairs' );
		$this->assertGreaterThan( 0, $this->restricted[1], 'the control: ' . $this->restricted[0] . ' scenarios under open_basedir, and the restriction changed what was told' );
		fwrite( STDERR, sprintf( "\nObstruction scenarios: %d; questions about this site's own directories (allowed): %d\n", $scenarios, $allowed ) ); // phpcs:ignore -- reported.
	}
}
