<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Support\Paths;
use WPCheckpoint\Tests\Fixtures\Support\NormalizeInputs;

/**
 * A run made without WordPress normalises paths with Paths::as_wordpress() and compares them with those a WP-CLI run
 * recorded through wp_normalize_path() (the site's directories, a directory's live, stage and old names): the two give
 * the same bytes for the same input, here on the core the suite runs on. The inputs are NormalizeInputs and, from a
 * fixed seed, strings made of the pieces where the two could part (separators, drive letters, wrappers, "..").
 */
final class PathsNormalizeTest extends WP_UnitTestCase {

	public function test_both_give_the_same_bytes(): void {
		$inputs = array_map( 'strval', array_keys( NormalizeInputs::CASES ) );
		$pieces = array( '/', '\\', '//', '\\\\', 'c:', 'C:', 'php://', 'phar://', 'file://', 'nope://', ':', '..', '.', 'x', 'dir', 'ü', '' );
		mt_srand( 20261107 );
		for ( $n = 0; $n < 2000; $n++ ) {
			$path = '';
			for ( $k = mt_rand( 0, 6 ); $k > 0; $k-- ) {
				$path .= $pieces[ mt_rand( 0, count( $pieces ) - 1 ) ];
			}
			$inputs[] = $path;
		}
		$wrapped = 0;
		foreach ( $inputs as $input ) {
			$this->assertSame( wp_normalize_path( $input ), Paths::as_wordpress( $input ), json_encode( $input ) );
			if ( false !== strpos( wp_normalize_path( $input ), '://' ) ) {
				++$wrapped;
			}
		}
		$this->assertGreaterThan( 100, $wrapped, 'the control: inputs with a wrapper core keeps' );
	}
}
