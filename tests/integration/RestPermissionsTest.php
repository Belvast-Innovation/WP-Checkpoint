<?php
/**
 * Every route in the plugin namespace must reject anonymous (401) and
 * non-admin (403) requests.
 *
 * Core validates required arguments (400) and matches regex routes before the
 * permission callback runs, so each route needs a concrete example request.
 * A route without an entry in EXAMPLES fails the test on purpose: add one
 * whenever you register a route.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Tests\Integration;

use WP_REST_Request;
use WP_UnitTestCase;
use WPCheckpoint\Rest\Controller;

final class RestPermissionsTest extends WP_UnitTestCase {

	/**
	 * Route pattern (as returned by WP_REST_Server::get_routes()) => example.
	 *
	 * "path" is the concrete URL with regex parameters filled in.
	 * "params" are the request parameters (query for GET, body otherwise).
	 */
	private const EXAMPLES = array(
		'/wp-checkpoint/v1/status' => array(
			'path'   => '/wp-checkpoint/v1/status',
			'params' => array(),
		),
	);

	/** @var int */
	private static $subscriber;

	/** @var int */
	private static $admin;

	public static function wpSetUpBeforeClass( $factory ): void {
		self::$subscriber = $factory->user->create( array( 'role' => 'subscriber' ) );
		self::$admin      = $factory->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( self::$admin );
		}
	}

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = null;
		rest_get_server();
	}

	/**
	 * All (route, method) pairs registered under the plugin namespace,
	 * excluding the namespace index that core registers for discovery.
	 *
	 * @return array<string, array{string, string}>
	 */
	private function route_methods(): array {
		$routes = rest_get_server()->get_routes( Controller::ROUTE_NAMESPACE );
		$pairs  = array();
		foreach ( $routes as $pattern => $handlers ) {
			if ( '/' . Controller::ROUTE_NAMESPACE === $pattern ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				foreach ( array_keys( array_filter( $handler['methods'] ) ) as $method ) {
					$pairs[ $method . ' ' . $pattern ] = array( $pattern, $method );
				}
			}
		}
		return $pairs;
	}

	private function request( string $pattern, string $method ): WP_REST_Request {
		$this->assertArrayHasKey(
			$pattern,
			self::EXAMPLES,
			"Route {$pattern} has no example request in RestPermissionsTest::EXAMPLES. Add one so its permissions are covered."
		);
		$example = self::EXAMPLES[ $pattern ];
		$request = new WP_REST_Request( $method, $example['path'] );
		if ( 'GET' === $method ) {
			$request->set_query_params( $example['params'] );
		} else {
			$request->set_body_params( $example['params'] );
		}
		return $request;
	}

	public function test_namespace_has_routes(): void {
		$this->assertNotEmpty( $this->route_methods(), 'No routes registered under ' . Controller::ROUTE_NAMESPACE );
	}

	public function test_every_route_has_an_example(): void {
		foreach ( $this->route_methods() as list( $pattern ) ) {
			$this->assertArrayHasKey( $pattern, self::EXAMPLES, "Missing example request for {$pattern}" );
		}
	}

	public function test_anonymous_requests_get_401(): void {
		wp_set_current_user( 0 );
		foreach ( $this->route_methods() as $label => list( $pattern, $method ) ) {
			$response = rest_get_server()->dispatch( $this->request( $pattern, $method ) );
			$this->assertSame( 401, $response->get_status(), "{$label} must return 401 for anonymous users" );
		}
	}

	public function test_subscriber_requests_get_403(): void {
		wp_set_current_user( self::$subscriber );
		foreach ( $this->route_methods() as $label => list( $pattern, $method ) ) {
			$response = rest_get_server()->dispatch( $this->request( $pattern, $method ) );
			$this->assertSame( 403, $response->get_status(), "{$label} must return 403 for subscribers" );
		}
	}

	public function test_administrator_requests_pass_permission_check(): void {
		wp_set_current_user( self::$admin );
		foreach ( $this->route_methods() as $label => list( $pattern, $method ) ) {
			$response = rest_get_server()->dispatch( $this->request( $pattern, $method ) );
			$this->assertNotContains( $response->get_status(), array( 401, 403 ), "{$label} must not reject administrators" );
			$this->assertNotSame( 400, $response->get_status(), "{$label} example request is invalid (400); fix EXAMPLES" );
			$this->assertNotSame( 404, $response->get_status(), "{$label} example path does not match the route (404); fix EXAMPLES" );
		}
	}

	public function test_plugin_routes_only_live_in_the_plugin_namespace(): void {
		$prefix = '/' . Controller::ROUTE_NAMESPACE;
		foreach ( array_keys( rest_get_server()->get_routes() ) as $route ) {
			if ( false === stripos( $route, 'checkpoint' ) ) {
				continue;
			}
			$this->assertTrue(
				$route === $prefix || 0 === strpos( $route, $prefix . '/' ),
				"Route {$route} mentions the plugin but is outside " . Controller::ROUTE_NAMESPACE . ', so the permission sweep would not cover it'
			);
		}
	}

	public function test_status_returns_only_the_version(): void {
		wp_set_current_user( self::$admin );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp-checkpoint/v1/status' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'version' => WPCHECKPOINT_VERSION ), $response->get_data() );
	}
}
