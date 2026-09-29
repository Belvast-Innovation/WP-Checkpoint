<?php

namespace WPCheckpoint\Tests\Unit\Files;

use WPCheckpoint\Tests\Fixtures\Files\Layouts;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The content-root rules over generated site layouts (Tests\Fixtures\Files\Layouts): the invariants hold for
 * every layout, and the check itself catches known violations injected into the roots or the pack step.
 *
 * Size: WPCHECKPOINT_LAYOUTS layouts from seed WPCHECKPOINT_LAYOUTS_SEED (default 500 from 1, about a minute);
 * the full run is a manual workflow. A failure names the seed: build( seed ) reproduces the layout.
 */
final class ScanLayoutsTest extends TestCase {

	/** @var Layouts|null */
	private $layouts;

	protected function set_up(): void {
		parent::set_up();
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'The layouts are made of symbolic links, which need privileges on Windows; the junction tests of FileScannerTest run there.' );
		}
		$this->layouts = new Layouts();
	}

	protected function tear_down(): void {
		if ( null !== $this->layouts ) {
			$this->layouts->remove();
		}
		parent::tear_down();
	}

	public function test_generated_layouts_keep_the_invariants(): void {
		$count = max( 1, (int) ( getenv( 'WPCHECKPOINT_LAYOUTS' ) ?: 500 ) );
		$first = (int) ( getenv( 'WPCHECKPOINT_LAYOUTS_SEED' ) ?: 1 );
		$found = array();
		$kinds = array();
		$modes = array();
		$runs  = 0;
		for ( $seed = $first; $seed < $first + $count; $seed++ ) {
			$layout = $this->layouts->build( $seed );
			foreach ( $layout['kinds'] as $kind ) {
				$kinds[ $kind ] = true;
			}
			$modes[ $layout['mode'] . ( $layout['readlink'] ? '' : ', no readlink' ) ] = true;
			foreach ( Layouts::chosen_sets( $seed ) as $chosen ) {
				++$runs;
				foreach ( Layouts::check( $layout, $chosen ) as $violation ) {
					$found[] = sprintf( 'seed %d, chosen %s: %s', $seed, implode( ',', $chosen ), $violation );
				}
			}
			Layouts::remove_tree( (string) $layout['base'], dirname( (string) $layout['base'] ) );
		}
		// The control: the generator made every kind of group and every content layout, with and without readlink().
		$this->assertSame( array(), array_diff( Layouts::KINDS, array_keys( $kinds ) ), 'every kind of group was generated' );
		if ( $count >= 100 ) {
			$this->assertCount( 6, $modes, 'every content layout, with and without readlink(): ' . implode( '; ', array_keys( $modes ) ) );
		}
		$this->assertGreaterThan( $count, $runs );
		$this->assertSame( array(), array_slice( $found, 0, 20 ), count( $found ) . ' violations in ' . $runs . ' runs' );
	}

	/**
	 * Tampered checks: each must find its kind of violation in some layout that is clean untampered.
	 *
	 * @return array<string, array{0: string, 1: callable|null, 2: callable|null}>
	 */
	public function tampered(): array {
		return array(
			'the other roots not skipped: a file twice'          => array(
				'I1: file listed twice',
				static function ( array $roots ): array {
					foreach ( $roots as $i => $root ) {
						$roots[ $i ]['also_skip'] = array();
					}
					return $roots;
				},
				null,
			),
			'the groups not chosen not skipped'                 => array(
				'I3: file of a group not chosen listed',
				static function ( array $roots ): array {
					foreach ( $roots as $i => $root ) {
						$roots[ $i ]['also_skip'] = array();
					}
					return $roots;
				},
				null,
			),
			'a directory at another root\'s path not left out'  => array(
				'I1: archive path listed twice',
				static function ( array $roots ): array {
					foreach ( $roots as $i => $root ) {
						$roots[ $i ]['collide'] = array();
					}
					return $roots;
				},
				null,
			),
			'the storage not skipped'                           => array(
				'I3: storage listed',
				static function ( array $roots ): array {
					foreach ( $roots as $i => $root ) {
						$roots[ $i ]['skip'] = array();
					}
					return $roots;
				},
				null,
			),
			'a root left out without a word'                    => array(
				'I2: not listed and nothing asked',
				static function ( array $roots ): array {
					array_shift( $roots );
					return $roots;
				},
				null,
			),
			'the pack step takes the shortest prefix'           => array(
				'I4: packed from another file',
				null,
				static function ( array $roots, array $ids, string $p ): ?string {
					$best = null;
					foreach ( $roots as $root ) {
						if ( 0 === strpos( $p, $root['prefix'] . '/' ) && ( null === $best || strlen( $root['prefix'] ) < strlen( $best['prefix'] ) ) ) {
							$best = $root;
						}
					}
					return null === $best ? null : $best['path'] . substr( $p, strlen( $best['prefix'] ) );
				},
			),
			'the refused root takes a shared path at pack time' => array(
				'I4: packed from another file',
				null,
				static function ( array $roots, array $ids, string $p, string $abspath ): ?string {
					$best = null;
					foreach ( $roots as $root ) {
						if ( 0 === strpos( $p, $root['prefix'] . '/' ) && ( null === $best || strlen( $root['prefix'] ) > strlen( $best['prefix'] ) ) ) {
							$best = $root; // The first of equal prefixes, refused or not.
						}
					}
					return null === $best ? null : $best['path'] . substr( $p, strlen( $best['prefix'] ) );
				},
			),
		);
	}

	/**
	 * @dataProvider tampered
	 *
	 * @param string        $expected Violation the check must report.
	 * @param callable|null $tamper   Tampering of the roots.
	 * @param callable|null $pick     Tampered pack step.
	 */
	public function test_the_check_catches_a_known_violation( string $expected, $tamper, $pick ): void {
		for ( $seed = 1; $seed <= 300; $seed++ ) {
			$layout = $this->layouts->build( $seed );
			foreach ( Layouts::chosen_sets( $seed ) as $chosen ) {
				if ( array() !== Layouts::check( $layout, $chosen ) ) {
					continue; // Only a layout the untampered check passes proves anything.
				}
				foreach ( Layouts::check( $layout, $chosen, $tamper, $pick ) as $violation ) {
					if ( 0 === strpos( $violation, $expected ) ) {
						$this->assertStringStartsWith( $expected, $violation );
						return;
					}
				}
			}
			Layouts::remove_tree( (string) $layout['base'], dirname( (string) $layout['base'] ) );
		}
		$this->fail( 'The check never reported "' . $expected . '" for the tampered roots in 300 layouts.' );
	}
}
