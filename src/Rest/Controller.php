<?php
/**
 * Base class for the plugin's REST controllers.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Rest;

use WP_REST_Controller;
use WP_REST_Request;
use WPCheckpoint\Support\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Fixes the namespace and provides the shared permission callback. Subclasses
 * must override register_routes(); the parent implementation only warns.
 *
 * Every route registered by a subclass must use permission_check(); it is
 * final so subclasses cannot weaken it. The integration suite asserts that
 * anonymous and non-admin requests are rejected on all routes in the
 * namespace and that no plugin route lives outside it.
 */
abstract class Controller extends WP_REST_Controller {

	/**
	 * REST namespace shared by all plugin routes.
	 */
	const ROUTE_NAMESPACE = 'wp-checkpoint/v1';

	/**
	 * Set the namespace.
	 */
	public function __construct() {
		$this->namespace = self::ROUTE_NAMESPACE;
	}

	/**
	 * Default permission callback: logged in and allowed to manage the plugin.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|\WP_Error
	 */
	final public function permission_check( WP_REST_Request $request ) {
		return Guard::check_rest( $request );
	}
}
