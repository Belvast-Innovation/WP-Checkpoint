<?php
/**
 * POST /wp-checkpoint/v1/jobs/{id}/loopback
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPCheckpoint\Jobs\JobActions;
use WPCheckpoint\Jobs\JobsUnavailable;
use WPCheckpoint\Jobs\Loopback;

defined( 'ABSPATH' ) || exit;

/**
 * The self-request hop of the loopback chain. Like the probe route, it is
 * authenticated by a one-time token instead of a login (the site has no
 * cookie for itself): the token is issued by Loopback::fire(), bound to the
 * job id, stored hashed for 120 seconds and deleted on first use. Without a
 * valid token everyone gets 403, administrators included. This is the
 * second and last documented exception to permission_check().
 */
final class LoopbackController extends Controller {

	/**
	 * Actions.
	 *
	 * @var JobActions
	 */
	private $actions;

	/**
	 * Constructor.
	 *
	 * @param JobActions $actions Actions.
	 */
	public function __construct( JobActions $actions ) {
		parent::__construct();
		$this->rest_base = 'jobs';
		$this->actions   = $actions;
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/' . Loopback::ROUTE_SUFFIX,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'hop' ),
					'permission_callback' => array( $this, 'check_token' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission callback: consume the token from the body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function check_token( WP_REST_Request $request ) {
		$body  = $request->get_body_params();
		$token = isset( $body['token'] ) && is_string( $body['token'] ) ? $body['token'] : '';
		if ( '' === $token || ! Loopback::consume_token( (int) $request->get_param( 'id' ), $token ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'wp-checkpoint' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Route callback: one tick; the next hop is fired by JobActions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function hop( WP_REST_Request $request ) {
		try {
			$result = $this->actions->tick( (int) $request->get_param( 'id' ), JobActions::started_at() );
		} catch ( JobsUnavailable $e ) {
			return new WP_Error( 'wpcheckpoint_jobs_unavailable', __( 'Jobs are unavailable right now.', 'wp-checkpoint' ), array( 'status' => 503 ) );
		}
		$response = new WP_REST_Response(
			array(
				'result'      => $result->status,
				'retry_after' => $result->retry_after,
			)
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
