<?php

namespace WPCheckpoint\Tests\Unit\Support;

use WPCheckpoint\Support\OwnerMarker;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class OwnerMarkerTest extends TestCase {

	public function test_marker_matches_same_install_only(): void {
		$id       = OwnerMarker::generate_id();
		$contents = OwnerMarker::build( $id, '/srv/site/' );

		$this->assertSame( 32, strlen( $id ) );
		$this->assertTrue( OwnerMarker::matches( $contents, $id, '/srv/site/' ) );
		$this->assertTrue( OwnerMarker::matches( $contents, $id, '/srv/site' ), 'trailing slash is irrelevant' );
		$this->assertFalse( OwnerMarker::matches( $contents, $id, '/srv/clone/' ), 'same options, different ABSPATH' );
		$this->assertFalse( OwnerMarker::matches( $contents, OwnerMarker::generate_id(), '/srv/site/' ) );
		$this->assertFalse( OwnerMarker::matches( '', $id, '/srv/site/' ) );
		$this->assertFalse( OwnerMarker::matches( "garbage\n", $id, '/srv/site/' ) );
		$this->assertFalse( OwnerMarker::matches( $contents, '', '/srv/site/' ) );
	}

	public function test_marker_does_not_contain_the_path(): void {
		$contents = OwnerMarker::build( 'id', '/home/user/public_html/' );
		$this->assertStringNotContainsString( 'public_html', $contents );
	}

	/**
	 * A marker as the version before wrote it: the hash of ABSPATH as spelled (computed here, not by the code under test).
	 */
	private static function old_marker( string $id, string $abspath ): string {
		$spelled = rtrim( str_replace( '\\', '/', $abspath ), '/' );
		return $id . "\n" . hash( 'sha256', 'Windows' === PHP_OS_FAMILY ? strtolower( $spelled ) : $spelled ) . "\n";
	}

	public function test_a_marker_matches_under_the_spelling_it_was_written_with_and_where_it_resolves(): void {
		$root = Sandbox::make( 'owner-marker' );
		try {
			mkdir( $root . '/releases/1', 0755, true );
			mkdir( $root . '/releases/2', 0755, true );
			mkdir( $root . '/copy', 0755, true );
			if ( ! @symlink( $root . '/releases/1', $root . '/current' ) ) {
				$this->markTestSkipped( 'Links cannot be made here.' );
			}
			$id   = OwnerMarker::generate_id();
			$real = (string) realpath( $root . '/releases/1' );

			// Written now, under the link: the directory it resolves to, matched under either spelling.
			$new = OwnerMarker::build( $id, $root . '/current/' );
			$this->assertTrue( OwnerMarker::matches( $new, $id, $root . '/current/' ) );
			$this->assertTrue( OwnerMarker::matches( $new, $id, $real . '/' ) );
			$this->assertFalse( OwnerMarker::matches( $new, $id, $root . '/copy/' ), 'a copy elsewhere is not it' );

			// Written before, under the link as spelled: still matched under that spelling, for good.
			$old = self::old_marker( $id, $root . '/current/' );
			$this->assertTrue( OwnerMarker::matches( $old, $id, $root . '/current/' ) );
			$this->assertTrue( OwnerMarker::matches( self::old_marker( $id, $real . '/' ), $id, $real ), 'the resolved spelling too' );
			$this->assertFalse( OwnerMarker::matches( $old, $id, $root . '/copy/' ) );
			$this->assertTrue( OwnerMarker::is_unfinished( substr( $old, 0, 40 ), $id, $root . '/current/' ), 'nor is an unfinished one of either form refused' );
			$this->assertTrue( OwnerMarker::is_unfinished( substr( $new, 0, 40 ), $id, $root . '/current/' ) );

			// A path that cannot be resolved here has its spelling alone.
			$this->assertSame( array( OwnerMarker::hash_spelling( $root . '/gone/' ) ), OwnerMarker::hashes( $root . '/gone/' ) );
			$this->assertCount( 2, OwnerMarker::hashes( $root . '/current/' ), 'the control: a link has two' );
		} finally {
			Sandbox::remove( $root );
		}
	}
}
