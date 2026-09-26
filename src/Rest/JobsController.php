<?php
/**
 * /wp-checkpoint/v1/jobs routes.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPCheckpoint\Jobs\InvalidTransition;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobActions;
use WPCheckpoint\Jobs\JobPresenter;
use WPCheckpoint\Jobs\JobsUnavailable;
use WPCheckpoint\Jobs\QuestionText;
use WPCheckpoint\Jobs\StaleJob;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Support\Directories;

defined( 'ABSPATH' ) || exit;

/**
 * GET /jobs, GET /jobs/{id}, POST /jobs/{id}/tick, /cancel, /retry,
 * GET /jobs/{id}/questions, POST /jobs/{id}/answer.
 *
 * Responses carry "result" (what the tick did: more, waiting, busy, ...)
 * next to "job" (whose "status" is the job's own state). Everything comes
 * out of JobPresenter: no storage path, no cursor, texts scrubbed,
 * redacted and masked.
 */
final class JobsController extends Controller {

	/**
	 * Actions.
	 *
	 * @var JobActions
	 */
	private $actions;

	/**
	 * Presenter.
	 *
	 * @var JobPresenter
	 */
	private $presenter;

	/**
	 * Storage directories.
	 *
	 * @var Directories
	 */
	private $directories;

	/**
	 * Constructor.
	 *
	 * @param JobActions   $actions     Actions.
	 * @param JobPresenter $presenter   Presenter.
	 * @param Directories  $directories Storage directories.
	 */
	public function __construct( JobActions $actions, JobPresenter $presenter, Directories $directories ) {
		parent::__construct();
		$this->rest_base   = 'jobs';
		$this->actions     = $actions;
		$this->presenter   = $presenter;
		$this->directories = $directories;
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$id = array(
			'id' => array(
				'type'              => 'integer',
				'minimum'           => 1,
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'status' => array(
							'type'  => 'array',
							'items' => array(
								'type' => 'string',
								'enum' => Job::statuses(),
							),
						),
						'limit'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 500,
							'default' => 50,
						),
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => $id,
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\\d+)/questions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'questions' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => $id,
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\\d+)/answer',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'answer' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array_merge(
						$id,
						array(
							'answers' => array(
								'type'     => 'object',
								'required' => true,
							),
						)
					),
				),
			)
		);
		foreach ( array( 'tick', 'cancel', 'retry' ) as $action ) {
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/(?P<id>\d+)/' . $action,
				array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, $action ),
						'permission_callback' => array( $this, 'permission_check' ),
						'args'                => $id,
					),
				)
			);
		}
	}

	/**
	 * GET /jobs
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$statuses = $request->get_param( 'status' );
		$jobs     = array();
		foreach ( $this->actions->list_user_jobs( is_array( $statuses ) ? $statuses : array(), (int) $request->get_param( 'limit' ) ) as $job ) {
			$jobs[] = $this->presenter->present( $job, false );
		}
		return $this->respond( array( 'jobs' => $jobs ) );
	}

	/**
	 * GET /jobs/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$job = $this->actions->find( (int) $request->get_param( 'id' ) );
		if ( null === $job ) {
			return $this->not_found();
		}
		return $this->respond( array( 'job' => $this->presenter->present( $job ) ) );
	}

	/**
	 * POST /jobs/{id}/tick
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function tick( WP_REST_Request $request ) {
		try {
			$result = $this->actions->web_tick( (int) $request->get_param( 'id' ), JobActions::started_at() );
		} catch ( JobsUnavailable $e ) {
			return $this->unavailable( $e );
		}
		if ( TickResult::MISSING === $result->status || null === $result->job ) {
			return $this->not_found();
		}
		return $this->respond(
			array(
				'result'      => $result->status,
				'retry_after' => $result->retry_after,
				'message'     => $this->presenter->clean( $result->message ),
				'job'         => $this->presenter->present( $result->job ),
			)
		);
	}

	/**
	 * POST /jobs/{id}/cancel
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		try {
			try {
				$outcome = $this->actions->cancel( $id );
			} catch ( StaleJob $e ) {
				// The row changed between load and write: reload once by trying again.
				$outcome = $this->actions->cancel( $id );
			}
		} catch ( InvalidTransition $e ) {
			return $this->conflict( __( 'This job is already finished.', 'wp-checkpoint' ) );
		} catch ( StaleJob $e ) {
			return $this->conflict( __( 'The job changed meanwhile; reload and try again.', 'wp-checkpoint' ) );
		}
		if ( null === $outcome ) {
			return $this->not_found();
		}
		return $this->respond(
			array(
				'result'  => 'cancelled',
				'cleaned' => $outcome['cleaned'],
				'message' => self::cancel_message( $outcome['reason'] ),
				'job'     => $this->presenter->present( $outcome['job'] ),
			)
		);
	}

	/**
	 * POST /jobs/{id}/retry
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function retry( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		try {
			try {
				$job = $this->actions->retry( $id );
			} catch ( StaleJob $e ) {
				$job = $this->actions->retry( $id );
			}
		} catch ( JobsUnavailable $e ) {
			return $this->unavailable( $e );
		} catch ( InvalidTransition $e ) {
			$current = $this->actions->find( $id );
			if ( null !== $current && Job::FAILED === $current->status && ! $current->can_retry() ) {
				return $this->conflict( JobPresenter::retry_note() );
			}
			return $this->conflict( __( 'Only a failed job can be retried.', 'wp-checkpoint' ) );
		} catch ( StaleJob $e ) {
			return $this->conflict( __( 'The job changed meanwhile; reload and try again.', 'wp-checkpoint' ) );
		}
		if ( null === $job ) {
			return $this->not_found();
		}
		return $this->respond(
			array(
				'result'  => 'queued',
				'message' => __( 'The job was queued again.', 'wp-checkpoint' ),
				'job'     => $this->presenter->present( $job ),
			)
		);
	}

	/**
	 * GET /jobs/{id}/questions
	 *
	 * The open questions of a paused job, with their texts and the listed
	 * items read from its work directory.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function questions( WP_REST_Request $request ) {
		$job = $this->actions->find( (int) $request->get_param( 'id' ) );
		if ( null === $job ) {
			return $this->not_found();
		}
		return $this->respond(
			array(
				'questions' => QuestionText::for_job( $job, $this->directories, array( $this->presenter, 'clean' ) ),
				'job'       => $this->presenter->present( $job, false ),
			)
		);
	}

	/**
	 * POST /jobs/{id}/answer
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function answer( WP_REST_Request $request ) {
		$answers = $request->get_param( 'answers' );
		try {
			$job = $this->actions->answer( (int) $request->get_param( 'id' ), is_array( $answers ) ? $answers : array() );
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'wpcheckpoint_invalid_answer', __( 'These answers do not fit the open questions; reload and answer again.', 'wp-checkpoint' ), array( 'status' => 400 ) );
		} catch ( JobsUnavailable $e ) {
			return $this->unavailable( $e );
		} catch ( InvalidTransition $e ) {
			return $this->conflict( __( 'This job is not waiting for an answer.', 'wp-checkpoint' ) );
		} catch ( StaleJob $e ) {
			return $this->conflict( __( 'The job changed meanwhile; reload and try again.', 'wp-checkpoint' ) );
		}
		if ( null === $job ) {
			return $this->not_found();
		}
		return $this->respond(
			array(
				'result'  => 'answered',
				'message' => __( 'The job continues with your answers.', 'wp-checkpoint' ),
				'job'     => $this->presenter->present( $job ),
			)
		);
	}

	/**
	 * Message for a cancel outcome.
	 *
	 * @param string $reason cleaned, holder or unavailable.
	 * @return string
	 */
	public static function cancel_message( string $reason ): string {
		switch ( $reason ) {
			case 'holder':
				return __( 'The job was cancelled; its temporary files are removed as soon as the current step stops.', 'wp-checkpoint' );
			case 'unavailable':
				return __( 'The job was cancelled. Its storage directory is not available from here, so its temporary files were not removed.', 'wp-checkpoint' );
		}
		return __( 'The job was cancelled.', 'wp-checkpoint' );
	}

	/**
	 * A response that must never be cached.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return WP_REST_Response
	 */
	private function respond( array $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * 404.
	 *
	 * @return WP_Error
	 */
	private function not_found(): WP_Error {
		return new WP_Error( 'wpcheckpoint_job_not_found', __( 'No such job.', 'wp-checkpoint' ), array( 'status' => 404 ) );
	}

	/**
	 * 409.
	 *
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private function conflict( string $message ): WP_Error {
		return new WP_Error( 'wpcheckpoint_job_conflict', $message, array( 'status' => 409 ) );
	}

	/**
	 * 503 with the user-facing reason.
	 *
	 * @param JobsUnavailable $e Exception.
	 * @return WP_Error
	 */
	private function unavailable( JobsUnavailable $e ): WP_Error {
		return new WP_Error( 'wpcheckpoint_jobs_unavailable', $this->presenter->clean( $e->getMessage() ), array( 'status' => 503 ) );
	}
}
