<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Support\CloneClassifier;

/**
 * Documents the premise of T005: PHP resolves symlinks in __FILE__, so a
 * "current -> releases/<n>" deployment gives WordPress a new ABSPATH on
 * every release.
 */
final class SymlinkAbspathTest extends WP_UnitTestCase {

	/** @var string */
	private $root;

	public function set_up(): void {
		parent::set_up();
		$this->root = sys_get_temp_dir() . '/wpcheckpoint-release-' . bin2hex( random_bytes( 4 ) );
		foreach ( array( '20260917', '20260918' ) as $release ) {
			mkdir( $this->root . '/releases/' . $release . '/wp-includes', 0755, true );
			file_put_contents(
				$this->root . '/releases/' . $release . '/wp-load-probe.php',
				"<?php\nreturn dirname( __FILE__ ) . '/';\n"
			);
		}
		if ( ! @symlink( $this->root . '/releases/20260917', $this->root . '/current' ) ) {
			$this->markTestSkipped( 'Symbolic links cannot be created here.' );
		}
	}

	public function tear_down(): void {
		@unlink( $this->root . '/current' );
		foreach ( array( '20260917', '20260918' ) as $release ) {
			@unlink( $this->root . '/releases/' . $release . '/wp-load-probe.php' );
			@rmdir( $this->root . '/releases/' . $release . '/wp-includes' );
			@rmdir( $this->root . '/releases/' . $release );
		}
		@rmdir( $this->root . '/releases' );
		@rmdir( $this->root );
		parent::tear_down();
	}

	public function test_abspath_follows_the_real_release_directory_across_deployments(): void {
		$first = require $this->root . '/current/wp-load-probe.php';
		$this->assertSame( $this->root . '/releases/20260917/', $first, '__FILE__ is the resolved path, not the symlink' );

		// Deploy: repoint "current" to the next release.
		unlink( $this->root . '/current' );
		symlink( $this->root . '/releases/20260918', $this->root . '/current' );
		$second = require $this->root . '/current/wp-load-probe.php';
		$this->assertSame( $this->root . '/releases/20260918/', $second );
		$this->assertNotSame( $first, $second, 'ABSPATH changes on every release' );

		$verdict = CloneClassifier::classify( $first, $second );
		$this->assertSame( CloneClassifier::DEPLOYMENT, $verdict['verdict'] );
		$this->assertTrue( $verdict['previous_exists'], 'the previous release is still on disk' );
		$this->assertSame( realpath( $this->root . '/releases' ), $verdict['deploy_root'] );
	}
}
