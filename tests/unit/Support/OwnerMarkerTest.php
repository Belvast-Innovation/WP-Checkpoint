<?php

namespace WPCheckpoint\Tests\Unit\Support;

use WPCheckpoint\Support\OwnerMarker;
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
}
