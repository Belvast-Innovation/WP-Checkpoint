<?php
/**
 * GET /wp-checkpoint/v1/status
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Reports the plugin version so clients can confirm the API is reachable.
 *
 * Deliberately returns nothing about the environment; that belongs to the
 * authenticated Tools tab (T004).
 */
final class StatusController extends Controller {

	/**
	 * Set the route base.
	 */
	public function __construct() {
		parent::__construct();
		$this->rest_base = 'status';
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
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Route callback.
	 *
	 * @param WP_REST_Request $request Incoming request (unused).
	 * @return WP_REST_Response
	 */
	public function get_status( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return new WP_REST_Response( array( 'version' => WPCHECKPOINT_VERSION ) );
	}

	/**
	 * Response schema.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wpcheckpoint-status',
			'type'       => 'object',
			'properties' => array(
				'version' => array(
					'description' => __( 'Installed plugin version.', 'wp-checkpoint' ),
					'type'        => 'string',
					'readonly'    => true,
				),
			),
		);
	}
}
