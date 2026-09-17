<?php

namespace WPCheckpoint\Tests\Integration;

use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;
use WPCheckpoint\Support\Guard;

final class GuardTest extends WP_UnitTestCase {

	/** @var int */
	private static $subscriber;

	/** @var int */
	private static $admin;

	public static function wpSetUpBeforeClass( $factory ): void {
		self::$subscriber = $factory->user->create( array( 'role' => 'subscriber' ) );
		self::$admin      = self::create_plugin_admin( $factory );
	}

	public function tear_down(): void {
		unset( $_REQUEST['nonce'], $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	private function status( $result ): int {
		$this->assertInstanceOf( WP_Error::class, $result );
		return Guard::status( $result );
	}

	private static function create_plugin_admin( $factory ): int {
		$id = $factory->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $id );
		}
		return $id;
	}

	public function test_capability_is_manage_options_on_single_site(): void {
		if ( is_multisite() ) {
			$this->assertSame( 'manage_network_options', Guard::capability() );
		} else {
			$this->assertSame( 'manage_options', Guard::capability() );
		}
	}

	public function test_rest_check(): void {
		$request = new WP_REST_Request( 'GET', '/wp-checkpoint/v1/status' );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->status( Guard::check_rest( $request ) ) );

		wp_set_current_user( self::$subscriber );
		$this->assertSame( 403, $this->status( Guard::check_rest( $request ) ) );

		wp_set_current_user( self::$admin );
		$this->assertTrue( Guard::check_rest( $request ) );
	}

	public function test_ajax_check_rejects_missing_or_wrong_nonce(): void {
		wp_set_current_user( self::$admin );

		$this->assertSame( 403, $this->status( Guard::check_ajax( 'export' ) ) );

		$_REQUEST['nonce'] = 'not-a-nonce';
		$this->assertSame( 403, $this->status( Guard::check_ajax( 'export' ) ) );

		$_REQUEST['nonce'] = Guard::nonce( 'other-action' );
		$this->assertSame( 403, $this->status( Guard::check_ajax( 'export' ) ) );
	}

	public function test_ajax_check_accepts_admin_with_valid_nonce(): void {
		wp_set_current_user( self::$admin );
		$_REQUEST['nonce'] = Guard::nonce( 'export' );
		$this->assertNull( Guard::check_ajax( 'export' ) );
	}

	public function test_ajax_check_rejects_subscriber_even_with_valid_nonce(): void {
		wp_set_current_user( self::$subscriber );
		$_REQUEST['nonce'] = Guard::nonce( 'export' );
		$this->assertSame( 403, $this->status( Guard::check_ajax( 'export' ) ) );
	}

	public function test_ajax_check_rejects_anonymous_with_401(): void {
		wp_set_current_user( 0 );
		$_REQUEST['nonce'] = Guard::nonce( 'export' );
		$this->assertSame( 401, $this->status( Guard::check_ajax( 'export' ) ) );
	}

	public function test_admin_post_check_uses_wpnonce_field(): void {
		wp_set_current_user( self::$admin );
		$this->assertSame( 403, $this->status( Guard::check_admin_post( 'delete-backup' ) ) );

		$_REQUEST['_wpnonce'] = Guard::nonce( 'delete-backup' );
		$this->assertNull( Guard::check_admin_post( 'delete-backup' ) );
	}

	public function test_require_admin_post_dies_on_failure_and_returns_on_success(): void {
		wp_set_current_user( self::$admin );
		$_REQUEST['_wpnonce'] = Guard::nonce( 'delete-backup' );
		Guard::require_admin_post( 'delete-backup' );
		$this->assertTrue( true, 'require_admin_post() returned normally with a valid request' );

		$_REQUEST['_wpnonce'] = 'wrong';
		$this->expectException( \WPDieException::class );
		Guard::require_admin_post( 'delete-backup' );
	}

	public function test_require_ajax_returns_on_success(): void {
		wp_set_current_user( self::$admin );
		$_REQUEST['nonce'] = Guard::nonce( 'export' );
		Guard::require_ajax( 'export' );
		$this->assertTrue( true, 'require_ajax() returned normally with a valid request' );
	}

	public function test_status_defaults_to_403(): void {
		$this->assertSame( 403, Guard::status( new WP_Error( 'x', 'y' ) ) );
		$this->assertSame( 401, Guard::status( new WP_Error( 'x', 'y', array( 'status' => 401 ) ) ) );
	}
}
