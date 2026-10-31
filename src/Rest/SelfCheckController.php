<?php
/**
 * GET /wp-checkpoint/v1/self-check
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\RestoreJob;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Restore\SelfCheck;

defined( 'ABSPATH' ) || exit;

/**
 * The route a restore checks the site it swapped in by (Restore\SelfCheck). It exists only for a request to it while
 * a restore checks that site: registered when the request's REST route is this one, the main site's options table
 * holds the self-check row, and the row's job is a restore that holds the site swapped and is queued or running (a
 * failed or paused one checks nothing); at any other time it is not registered and is not in the index. It is authenticated by the pass key the row holds the
 * hash of, not by a login: without the key everyone gets 403, administrators included. This is the third and
 * last documented exception to permission_check().
 */
final class SelfCheckController extends Controller {

	/**
	 * Jobs.
	 *
	 * @var JobRepository
	 */
	private $jobs;

	/**
	 * Constructor.
	 *
	 * @param JobRepository $jobs Jobs.
	 */
	public function __construct( JobRepository $jobs ) {
		parent::__construct();
		$this->rest_base = SelfCheck::ROUTE;
		$this->jobs      = $jobs;
	}

	/**
	 * Register the route, while a restore checks this site and the request is for it.
	 *
	 * @return void
	 */
	public function register_routes() {
		if ( ! $this->wanted() ) {
			return;
		}
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'answer' ),
					'permission_callback' => array( $this, 'check_pass' ),
					'args'                => array(
						'challenge' => array(
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => static function ( $value ): bool {
								return is_string( $value ) && 1 === preg_match( SelfCheck::HEX, $value );
							},
						),
					),
				),
			)
		);
	}

	/**
	 * Whether the route is to be registered: this request's REST route is the route's, on the main site, and a
	 * restore that is queued or running wrote the self-check row while it holds the site swapped (the restored site's
	 * check), or while it holds the site put back by an undo after the restored site was let out, behind its held
	 * maintenance file (SwapStep::check_undone(): the cursor at "restored", undone).
	 *
	 * @return bool
	 */
	public function wanted(): bool {
		$route = isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->query_vars['rest_route'] ) && is_string( $GLOBALS['wp']->query_vars['rest_route'] ) ? $GLOBALS['wp']->query_vars['rest_route'] : '';
		if ( SelfCheck::PATH !== untrailingslashit( $route ) || ( is_multisite() && ! is_main_site() ) ) {
			return false;
		}
		$row = SelfCheck::stored();
		if ( null === $row ) {
			return false;
		}
		$job = $this->jobs->find( $row['job'] );
		if ( null === $job || RestoreJob::ID !== $job->type || ! in_array( $job->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
			return false;
		}
		return Job::SITE_SWAPPED === $job->site_state || ( Job::SITE_CHANGING === $job->site_state && SwapStep::checks_undone( $job->cursor ) );
	}

	/**
	 * Permission callback: the pass header is the key the row holds the hash of.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function check_pass( WP_REST_Request $request ) {
		return null === self::passed( $request ) ? self::forbidden() : true;
	}

	/**
	 * Route callback: the challenge back, and the answer to it, from the row as read now (checked against the pass
	 * header again: a row written anew since the permission check holds another key).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function answer( WP_REST_Request $request ) {
		$row = self::passed( $request );
		if ( null === $row ) {
			return self::forbidden();
		}
		$challenge = (string) $request->get_param( 'challenge' );
		$response  = new WP_REST_Response(
			array(
				'challenge' => $challenge,
				'answer'    => SelfCheck::answer( $row['nonce'], $challenge ),
			)
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * The row, read now, when the request's pass header is its key; null otherwise.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{job: int, nonce: string, pass: string}|null
	 */
	private static function passed( WP_REST_Request $request ) {
		$row    = SelfCheck::stored();
		$header = $request->get_header( SelfCheck::HEADER );
		return null !== $row && is_string( $header ) && SelfCheck::passes( $header, $row['pass'] ) ? $row : null;
	}

	/**
	 * The refusal.
	 *
	 * @return WP_Error
	 */
	private static function forbidden(): WP_Error {
		return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'wp-checkpoint' ), array( 'status' => 403 ) );
	}
}
