<?php

namespace WPCheckpoint\Tests\Integration;

use WP_Ajax_UnitTestCase;
use WPAjaxDieContinueException;
use WPCheckpoint\Support\Guard;

/**
 * require_ajax() must stop the request with a JSON error.
 *
 * @group ajax
 */
final class AjaxGuardTest extends WP_Ajax_UnitTestCase {

	public function tear_down(): void {
		unset( $_REQUEST['nonce'] );
		parent::tear_down();
	}

	/**
	 * Assert that require_ajax() stops the request with a JSON error carrying
	 * the verdict's message, and that the verdict maps to the expected status.
	 *
	 * The HTTP status itself cannot be observed here: wp_send_json() only
	 * calls status_header() when no output was sent, and PHPUnit has already
	 * printed. Guard::send_ajax_error() passes Guard::status( $error ), which
	 * GuardTest covers.
	 */
	private function assert_rejected( int $expected_status ): void {
		$verdict = Guard::check_ajax( 'export' );
		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( $expected_status, Guard::status( $verdict ) );

		// Mirror WP_Ajax_UnitTestCase::_handleAjax(): the die handler collects the buffer into _last_response.
		$stopped = false;
		ob_start();
		try {
			Guard::require_ajax( 'export' );
		} catch ( WPAjaxDieContinueException $e ) {
			$stopped = true;
		}
		if ( ! $stopped ) {
			ob_end_clean();
		}
		$this->assertTrue( $stopped, 'require_ajax() must stop the request' );

		$response = (array) json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'] );
		$this->assertSame( $verdict->get_error_message(), $response['data']['message'] );
	}

	public function test_anonymous_gets_401_json_error(): void {
		wp_set_current_user( 0 );
		$_REQUEST['nonce'] = 'irrelevant';

		$this->assert_rejected( 401 );
	}

	public function test_subscriber_with_valid_nonce_gets_403_json_error(): void {
		$this->_setRole( 'subscriber' );
		$_REQUEST['nonce'] = Guard::nonce( 'export' );

		$this->assert_rejected( 403 );
	}

	public function test_wrong_nonce_gets_403_json_error(): void {
		$this->_setRole( 'administrator' );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}
		$_REQUEST['nonce'] = 'wrong';

		$this->assert_rejected( 403 );
	}
}
