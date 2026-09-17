<?php

namespace WPCheckpoint\Tests\Integration;

use WP_REST_Request;
use WP_UnitTestCase;
use WPCheckpoint\Rest\ProbeController;

final class ProbeTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = null;
		rest_get_server();
		wp_set_current_user( 0 );
	}

	private function post( $challenge ) {
		$request = new WP_REST_Request( 'POST', '/wp-checkpoint/v1/probe' );
		if ( null !== $challenge ) {
			$request->set_body_params( array( 'challenge' => $challenge ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_without_challenge_is_forbidden(): void {
		$this->assertSame( 403, $this->post( null )->get_status() );
		$this->assertSame( 403, $this->post( '' )->get_status() );
	}

	public function test_wrong_or_malformed_challenge_is_forbidden(): void {
		ProbeController::issue_challenge();
		$this->assertSame( 403, $this->post( str_repeat( 'a', 32 ) )->get_status() );
		$this->assertSame( 403, $this->post( 'not-hex' )->get_status() );
		$this->assertSame( 403, $this->post( array( 'x' ) )->get_status() );
	}

	public function test_valid_challenge_is_echoed_once_with_runtime_limits_only(): void {
		$challenge = ProbeController::issue_challenge();

		$response = $this->post( $challenge );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( $challenge, $data['challenge'] );
		$this->assertSame( array( 'challenge', 'memory_limit', 'memory_bytes', 'max_execution_time', 'set_time_limit' ), array_keys( $data ) );
		$this->assertIsInt( $data['memory_bytes'] );
		$this->assertIsInt( $data['max_execution_time'] );
		$this->assertIsBool( $data['set_time_limit'] );
		$encoded = wp_json_encode( $data );
		$this->assertStringNotContainsString( ABSPATH, $encoded );
		$this->assertStringNotContainsString( '/', str_replace( array( '"' ), '', $data['memory_limit'] ), 'no paths in the memory value' );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );

		$this->assertSame( 403, $this->post( $challenge )->get_status(), 'a challenge is single use' );
	}

	public function test_challenge_in_the_query_string_is_ignored(): void {
		$challenge = ProbeController::issue_challenge();
		$request   = new WP_REST_Request( 'POST', '/wp-checkpoint/v1/probe' );
		$request->set_query_params( array( 'challenge' => $challenge ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );

		$this->assertSame( 200, $this->post( $challenge )->get_status(), 'the unused challenge still works from the body' );
	}

	public function test_revoked_and_expired_challenges_are_rejected(): void {
		$challenge = ProbeController::issue_challenge();
		ProbeController::revoke_challenge( $challenge );
		$this->assertSame( 403, $this->post( $challenge )->get_status() );

		$expired = ProbeController::issue_challenge();
		delete_site_transient( 'wpcheckpoint_probe_' . hash( 'sha256', $expired ) );
		$this->assertSame( 403, $this->post( $expired )->get_status() );
	}

	public function test_logged_in_administrator_still_needs_the_challenge(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		wp_set_current_user( $admin );
		$this->assertSame( 403, $this->post( null )->get_status() );
		$this->assertSame( 200, $this->post( ProbeController::issue_challenge() )->get_status() );
	}
}
