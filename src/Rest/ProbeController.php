<?php
/**
 * POST /wp-checkpoint/v1/probe
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPCheckpoint\Support\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Answers the plugin's own loopback probe with the limits of the request
 * that served it. Authenticated by a one-time challenge instead of a login:
 * the value is issued moments earlier by the environment check, stored as a
 * short-lived transient and deleted on first use. Without a valid challenge
 * the route returns 403 for everyone, logged in or not. The challenge is
 * read from the request body only, never from the query string, so it does
 * not end up in access logs. The response never includes paths or other
 * sensitive data.
 */
final class ProbeController extends Controller {

	const ROUTE            = 'probe';
	const CHALLENGE_TTL    = 120;
	const TRANSIENT_PREFIX = 'wpcheckpoint_probe_';

	/**
	 * Set the route base.
	 */
	public function __construct() {
		parent::__construct();
		$this->rest_base = self::ROUTE;
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'answer' ),
					'permission_callback' => array( $this, 'check_challenge' ),
					'args'                => array(
						'challenge' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Create a challenge that answer() will accept exactly once.
	 *
	 * @return string
	 */
	public static function issue_challenge(): string {
		$challenge = bin2hex( random_bytes( 16 ) );
		set_site_transient( self::transient_key( $challenge ), 1, self::CHALLENGE_TTL );
		return $challenge;
	}

	/**
	 * Delete a challenge whether or not it was used.
	 *
	 * @param string $challenge Challenge value.
	 * @return void
	 */
	public static function revoke_challenge( string $challenge ): void {
		delete_site_transient( self::transient_key( $challenge ) );
	}

	/**
	 * Permission callback: consume the challenge.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function check_challenge( WP_REST_Request $request ) {
		$challenge = self::body_challenge( $request );
		if ( '' === $challenge ) {
			return $this->forbidden();
		}
		$key = self::transient_key( $challenge );
		if ( 1 !== (int) get_site_transient( $key ) ) {
			return $this->forbidden();
		}
		delete_site_transient( $key );
		return true;
	}

	/**
	 * Route callback: limits of this request plus the challenge.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function answer( WP_REST_Request $request ): WP_REST_Response {
		$runtime  = Environment::runtime_values();
		$response = new WP_REST_Response(
			array(
				'challenge'          => self::body_challenge( $request ),
				'memory_limit'       => $runtime['memory_limit'],
				'memory_bytes'       => $runtime['memory_bytes'],
				'max_execution_time' => $runtime['max_execution_time'],
				'set_time_limit'     => $runtime['set_time_limit'],
			)
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Challenge from the request body only; empty when absent or malformed.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return string
	 */
	private static function body_challenge( WP_REST_Request $request ): string {
		$body = $request->get_body_params();
		if ( ! isset( $body['challenge'] ) || ! is_string( $body['challenge'] ) ) {
			return '';
		}
		return 1 === preg_match( '/^[a-f0-9]{32}$/', $body['challenge'] ) ? $body['challenge'] : '';
	}

	/**
	 * 403 error used for every rejection.
	 *
	 * @return WP_Error
	 */
	private function forbidden(): WP_Error {
		return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'wp-checkpoint' ), array( 'status' => 403 ) );
	}

	/**
	 * Transient name for a challenge (hashed so the value is not stored).
	 *
	 * @param string $challenge Challenge value.
	 * @return string
	 */
	private static function transient_key( string $challenge ): string {
		return self::TRANSIENT_PREFIX . hash( 'sha256', $challenge );
	}
}
