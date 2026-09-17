<?php
/**
 * Capability and nonce checks shared by every entry point.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Single place that decides who may call the plugin.
 *
 * AJAX and admin-post handlers must call require_ajax() / require_admin_post()
 * as their first statement; those stop the request on failure. The check_*
 * methods only return the verdict and exist for the require_* wrappers, the
 * REST controller base class and tests.
 */
final class Guard {

	/**
	 * Prefix for all nonce actions created by the plugin.
	 */
	const NONCE_PREFIX = 'wpcheckpoint_';

	/**
	 * Capability required for every plugin action.
	 *
	 * @return string
	 */
	public static function capability(): string {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * Whether the current user may use the plugin.
	 *
	 * @return bool
	 */
	public static function current_user_can(): bool {
		return current_user_can( self::capability() );
	}

	/**
	 * Permission callback for REST routes.
	 *
	 * Cookie nonces are validated by core before this runs; an invalid
	 * X-WP-Nonce already yields a 403 from core.
	 *
	 * @param WP_REST_Request $request Incoming request (unused, kept for the callback signature).
	 * @return true|WP_Error
	 */
	public static function check_rest( WP_REST_Request $request ) {
		unset( $request );

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to do that.', 'wp-checkpoint' ),
				array( 'status' => 401 )
			);
		}

		if ( ! self::current_user_can() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to do that.', 'wp-checkpoint' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Guard an AJAX handler: login, nonce, capability. Stops the request with a
	 * JSON error (401 or 403) on failure, returns normally on success.
	 *
	 * @param string $action Nonce action without the plugin prefix.
	 * @param string $field  Request field carrying the nonce.
	 * @return void
	 */
	public static function require_ajax( string $action, string $field = 'nonce' ): void {
		$error = self::check_ajax( $action, $field );
		if ( null !== $error ) {
			self::send_ajax_error( $error );
			// wp_send_json_error() ends the request through wp_die(); another plugin can
			// swap the wp_die_ajax_handler for one that returns, so never fall through.
			exit;
		}
	}

	/**
	 * Guard an admin-post handler: login, nonce, capability. Stops the request
	 * with wp_die() (401 or 403) on failure, returns normally on success.
	 *
	 * @param string $action Nonce action without the plugin prefix.
	 * @param string $field  Request field carrying the nonce.
	 * @return void
	 */
	public static function require_admin_post( string $action, string $field = '_wpnonce' ): void {
		$error = self::check_admin_post( $action, $field );
		if ( null !== $error ) {
			self::die_admin_post( $error );
			// wp_die() can be replaced through the wp_die_handler filter with a handler
			// that returns, so never fall through into the protected handler.
			exit;
		}
	}

	/**
	 * Verdict for an AJAX request. Handlers must use require_ajax() instead.
	 *
	 * @param string $action Nonce action without the plugin prefix.
	 * @param string $field  Request field carrying the nonce.
	 * @return WP_Error|null Null when the request may proceed.
	 */
	public static function check_ajax( string $action, string $field = 'nonce' ) {
		return self::check_request( $action, self::request_field( $field ) );
	}

	/**
	 * Verdict for an admin-post request. Handlers must use require_admin_post() instead.
	 *
	 * @param string $action Nonce action without the plugin prefix.
	 * @param string $field  Request field carrying the nonce.
	 * @return WP_Error|null Null when the request may proceed.
	 */
	public static function check_admin_post( string $action, string $field = '_wpnonce' ) {
		return self::check_request( $action, self::request_field( $field ) );
	}

	/**
	 * Validate a nonce value against an action, then the capability.
	 *
	 * @param string      $action Nonce action without the plugin prefix.
	 * @param string|null $nonce  Nonce value from the request.
	 * @return WP_Error|null Null when the request may proceed.
	 */
	public static function check_request( string $action, $nonce ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'wpcheckpoint_not_logged_in',
				__( 'You must be logged in to do that.', 'wp-checkpoint' ),
				array( 'status' => 401 )
			);
		}

		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, self::NONCE_PREFIX . $action ) ) {
			return new WP_Error(
				'wpcheckpoint_invalid_nonce',
				__( 'The link you followed has expired. Please try again.', 'wp-checkpoint' ),
				array( 'status' => 403 )
			);
		}

		if ( ! self::current_user_can() ) {
			return new WP_Error(
				'wpcheckpoint_forbidden',
				__( 'Sorry, you are not allowed to do that.', 'wp-checkpoint' ),
				array( 'status' => 403 )
			);
		}

		return null;
	}

	/**
	 * Create a nonce for a plugin action.
	 *
	 * @param string $action Nonce action without the plugin prefix.
	 * @return string
	 */
	public static function nonce( string $action ): string {
		return wp_create_nonce( self::NONCE_PREFIX . $action );
	}

	/**
	 * Nonce for REST requests made with cookie authentication.
	 *
	 * @return string
	 */
	public static function rest_nonce(): string {
		return wp_create_nonce( 'wp_rest' );
	}

	/**
	 * Respond to a failed AJAX check and stop.
	 *
	 * @param WP_Error $error Failed verdict.
	 * @return void
	 */
	public static function send_ajax_error( WP_Error $error ): void {
		wp_send_json_error( array( 'message' => $error->get_error_message() ), self::status( $error ) );
	}

	/**
	 * Respond to a failed admin-post check and stop.
	 *
	 * @param WP_Error $error Failed verdict.
	 * @return void
	 */
	public static function die_admin_post( WP_Error $error ): void {
		$status = self::status( $error );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- integer HTTP status, message is escaped.
		wp_die( esc_html( $error->get_error_message() ), '', array( 'response' => $status ) );
	}

	/**
	 * HTTP status stored in an error, defaulting to 403.
	 *
	 * @param WP_Error $error Error with a "status" data key.
	 * @return int
	 */
	public static function status( WP_Error $error ): int {
		$data = $error->get_error_data();
		if ( is_array( $data ) && isset( $data['status'] ) ) {
			return (int) $data['status'];
		}
		return 403;
	}

	/**
	 * Read a nonce field from the request without further processing.
	 *
	 * @param string $field Field name.
	 * @return string|null
	 */
	private static function request_field( string $field ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- this is the nonce itself, verified by the caller.
		if ( ! isset( $_REQUEST[ $field ] ) || ! is_string( $_REQUEST[ $field ] ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		return sanitize_text_field( wp_unslash( $_REQUEST[ $field ] ) );
	}
}
