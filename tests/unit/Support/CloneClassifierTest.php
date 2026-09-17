<?php

namespace WPCheckpoint\Tests\Unit\Support;

use WPCheckpoint\Support\CloneClassifier;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class CloneClassifierTest extends TestCase {

	/**
	 * Fake filesystem: directories that exist, and a realpath that only knows them.
	 */
	private function probes( array $dirs ): array {
		$dirs   = array_map( static function ( string $d ): string {
			return rtrim( $d, '/' );
		}, $dirs );
		$is_dir = static function ( string $path ) use ( $dirs ): bool {
			return in_array( rtrim( $path, '/' ), $dirs, true );
		};
		$real   = static function ( string $path ) use ( $dirs ) {
			$path = rtrim( $path, '/' );
			return in_array( $path, $dirs, true ) ? $path : false;
		};
		return array( $is_dir, $real );
	}

	public function test_release_siblings_are_a_deployment_even_when_the_old_release_still_exists(): void {
		list( $is_dir, $real ) = $this->probes( array( '/srv/app/releases', '/srv/app/releases/20260917', '/srv/app/releases/20260917/wp-includes', '/srv/app/releases/20260918', '/srv/app/releases/20260918/wp-includes' ) );
		$r = CloneClassifier::classify( '/srv/app/releases/20260917/', '/srv/app/releases/20260918/', $is_dir, $real );

		$this->assertSame( CloneClassifier::DEPLOYMENT, $r['verdict'] );
		$this->assertSame( CloneClassifier::RECOMMEND_ORIGINAL, $r['recommendation'] );
		$this->assertTrue( $r['siblings'] );
		$this->assertTrue( $r['previous_exists'], 'old releases are commonly kept' );
		$this->assertSame( '/srv/app/releases', $r['deploy_root'] );
	}

	public function test_release_siblings_after_the_old_release_was_pruned(): void {
		list( $is_dir, $real ) = $this->probes( array( '/srv/app/releases', '/srv/app/releases/20260918', '/srv/app/releases/20260918/wp-includes' ) );
		$r = CloneClassifier::classify( '/srv/app/releases/20260917/', '/srv/app/releases/20260918/', $is_dir, $real );

		$this->assertSame( CloneClassifier::DEPLOYMENT, $r['verdict'] );
		$this->assertFalse( $r['previous_exists'] );
		$this->assertSame( '/srv/app/releases', $r['deploy_root'] );
	}

	public function test_missing_previous_directory_elsewhere_is_a_move(): void {
		list( $is_dir, $real ) = $this->probes( array( '/var/www/new', '/var/www/new/wp-includes', '/home/old' ) );
		$r = CloneClassifier::classify( '/home/old/public_html/', '/var/www/new/', $is_dir, $real );

		$this->assertSame( CloneClassifier::MOVED, $r['verdict'] );
		$this->assertSame( CloneClassifier::RECOMMEND_ORIGINAL, $r['recommendation'] );
		$this->assertFalse( $r['siblings'] );
		$this->assertSame( '', $r['deploy_root'] );
	}

	public function test_previous_directory_without_wordpress_is_a_move(): void {
		list( $is_dir, $real ) = $this->probes( array( '/home/old/public_html', '/var/www', '/var/www/new', '/home/old' ) );
		$r = CloneClassifier::classify( '/home/old/public_html/', '/var/www/new/', $is_dir, $real );
		$this->assertSame( CloneClassifier::MOVED, $r['verdict'] );
		$this->assertTrue( $r['previous_exists'] );
		$this->assertFalse( $r['previous_is_wordpress'] );
	}

	public function test_live_previous_wordpress_elsewhere_is_a_clone(): void {
		list( $is_dir, $real ) = $this->probes( array( '/home/old/public_html', '/home/old/public_html/wp-includes', '/home/old', '/home/copy', '/home/copy/public_html', '/home/copy/public_html/wp-includes' ) );
		$r = CloneClassifier::classify( '/home/old/public_html/', '/home/copy/public_html/', $is_dir, $real );

		$this->assertSame( CloneClassifier::CLONE, $r['verdict'] );
		$this->assertSame( CloneClassifier::RECOMMEND_NEW, $r['recommendation'] );
		$this->assertFalse( $r['siblings'] );
	}

	public function test_same_parent_but_identical_path_is_not_siblings(): void {
		list( $is_dir, $real ) = $this->probes( array( '/srv/app', '/srv/app/wp', '/srv/app/wp/wp-includes' ) );
		$r = CloneClassifier::classify( '/srv/app/wp/', '/srv/app/wp', $is_dir, $real );
		$this->assertFalse( $r['siblings'] );
		$this->assertSame( CloneClassifier::CLONE, $r['verdict'] );
	}

	public function test_windows_style_paths(): void {
		list( $is_dir, $real ) = $this->probes( array( 'C:/sites/releases', 'C:/sites/releases/b', 'C:/sites/releases/b/wp-includes' ) );
		$r = CloneClassifier::classify( 'C:\\sites\\releases\\a\\', 'C:\\sites\\releases\\b\\', $is_dir, $real );
		$this->assertSame( CloneClassifier::DEPLOYMENT, $r['verdict'] );
		$this->assertSame( 'C:/sites/releases', $r['deploy_root'] );
	}
}
