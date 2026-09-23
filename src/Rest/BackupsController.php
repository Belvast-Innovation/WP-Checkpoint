<?php
/**
 * /wp-checkpoint/v1/backups routes.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPCheckpoint\Admin\DownloadHandler;
use WPCheckpoint\Backups\BackupStore;
use WPCheckpoint\Jobs\JobActions;
use WPCheckpoint\Jobs\JobConflict;
use WPCheckpoint\Jobs\JobConflicts;
use WPCheckpoint\Jobs\JobPresenter;
use WPCheckpoint\Jobs\JobsUnavailable;
use WPCheckpoint\Jobs\VerifyJob;
use WPCheckpoint\Support\Directories;

defined( 'ABSPATH' ) || exit;

/**
 * GET /backups, GET /backups/{base}, DELETE /backups/{base},
 * POST /backups/{base}/verify.
 *
 * The base name in the route is matched by the same pattern the export
 * gives it, so it never carries a path. Text taken from a manifest
 * (warnings, exclusions) goes through JobPresenter::clean() like every
 * other text leaving the engine; storage paths never appear.
 */
final class BackupsController extends Controller {

	/**
	 * Route pattern of a base name (PreflightStep::BASE_PATTERN without anchors).
	 */
	const BASE_ROUTE = '(?P<base>[a-z0-9][a-z0-9-]{0,39}-[0-9]{8}-[0-9]{6}-[0-9a-f]{4})';

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
		$this->rest_base   = 'backups';
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
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'page'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => BackupStore::MAX_PER_PAGE,
							'default' => 20,
						),
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/' . self::BASE_ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/' . self::BASE_ROUTE . '/verify',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'verify' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'depth' => array(
							'type'    => 'string',
							'enum'    => array( 'structure', 'full' ),
							'default' => 'full',
						),
					),
				),
			)
		);
	}

	/**
	 * GET /backups
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$store = $this->store();
		if ( null === $store ) {
			return $this->unavailable();
		}
		$page  = $store->page( (int) $request->get_param( 'page' ), (int) $request->get_param( 'per_page' ), $this->actions->active() );
		$items = array();
		foreach ( $page['items'] as $item ) {
			$items[] = $this->clean_summary( $item );
		}
		return $this->respond(
			array(
				'total'   => $page['total'],
				'backups' => $items,
			)
		);
	}

	/**
	 * GET /backups/{base}
	 *
	 * Download links are left out while the backup is being restored (the
	 * download handler refuses them too).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$store = $this->store();
		if ( null === $store ) {
			return $this->unavailable();
		}
		$base    = (string) $request->get_param( 'base' );
		$active  = $this->actions->active();
		$details = $store->details( $base, $active );
		if ( null === $details ) {
			return $this->not_found();
		}
		$details   = $this->clean_summary( $details );
		$restoring = JobConflicts::restoring( $base, $active );
		$links     = '' === $restoring;
		if ( isset( $details['volume_files'] ) && is_array( $details['volume_files'] ) ) {
			foreach ( $details['volume_files'] as $i => $volume ) {
				$details['volume_files'][ $i ]['download'] = $links && $volume['present'] ? DownloadHandler::url( 'backups/' . $volume['name'] ) : '';
			}
		}
		if ( isset( $details['manifest_file'] ) && is_array( $details['manifest_file'] ) ) {
			$details['manifest_file']['download'] = $links ? DownloadHandler::url( 'backups/' . $details['manifest_file']['name'] ) : '';
		}
		$details['downloads_note'] = '' === $restoring
			? __( 'Download every file listed here and keep them together in one directory: the backup can only be restored with all of them. Check that each downloaded file has the size shown, and the SHA-256 where one is shown; large volumes are checked block by block when the backup is verified or uploaded.', 'wp-checkpoint' )
			: $this->presenter->clean( $restoring );
		return $this->respond( array( 'backup' => $details ) );
	}

	/**
	 * DELETE /backups/{base}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$store = $this->store();
		if ( null === $store ) {
			return $this->unavailable();
		}
		$base = (string) $request->get_param( 'base' );
		if ( ! $store->exists( $base ) ) {
			return $this->not_found();
		}
		$active = $this->actions->active();
		$reason = JobConflicts::backup_in_use( $base, $active );
		if ( '' !== $reason ) {
			return $this->conflict( $this->presenter->clean( $reason ) );
		}
		try {
			$deleted = $store->delete( $base, $active );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'wpcheckpoint_backup_delete_failed', $this->presenter->clean( $e->getMessage() ), array( 'status' => 500 ) );
		}
		return $this->respond(
			array(
				'result'  => 'deleted',
				'deleted' => $deleted,
			)
		);
	}

	/**
	 * POST /backups/{base}/verify
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function verify( WP_REST_Request $request ) {
		$store = $this->store();
		if ( null === $store ) {
			return $this->unavailable();
		}
		$base = (string) $request->get_param( 'base' );
		if ( ! $store->exists( $base ) ) {
			return $this->not_found();
		}
		try {
			$options = VerifyJob::options(
				array(
					'base'  => $base,
					'depth' => (string) $request->get_param( 'depth' ),
				)
			);
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'wpcheckpoint_invalid_request', $this->presenter->clean( $e->getMessage() ), array( 'status' => 400 ) );
		}
		try {
			$job = $this->actions->start( VerifyJob::ID, get_current_user_id(), $options );
		} catch ( JobConflict $e ) {
			return $this->conflict( $this->presenter->clean( $e->getMessage() ) );
		} catch ( JobsUnavailable $e ) {
			return new WP_Error( 'wpcheckpoint_jobs_unavailable', $this->presenter->clean( $e->getMessage() ), array( 'status' => 503 ) );
		}
		$response = $this->respond(
			array(
				'result' => 'queued',
				'job'    => $this->presenter->present( $job, false ),
			)
		);
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * The store for the current backups directory, or null when there is none.
	 *
	 * @return BackupStore|null
	 */
	private function store() {
		$dir = $this->directories->backups();
		return '' === $dir ? null : new BackupStore( $dir );
	}

	/**
	 * Clean the texts of a summary or details: manifest text is user data.
	 *
	 * @param array<string, mixed> $item Summary or details.
	 * @return array<string, mixed>
	 */
	private function clean_summary( array $item ): array {
		$item['in_use'] = $this->presenter->clean( (string) $item['in_use'] );
		foreach ( array( 'warnings', 'exclusions' ) as $key ) { // In details, warnings is the list; in a summary, a count.
			if ( isset( $item[ $key ] ) && is_array( $item[ $key ] ) ) {
				$item[ $key ] = $this->clean_texts( $item[ $key ] );
			}
		}
		return $item;
	}

	/**
	 * Clean every string in a nested list.
	 *
	 * @param array<mixed> $values Values.
	 * @return array<mixed>
	 */
	private function clean_texts( array $values ): array {
		foreach ( $values as $key => $value ) {
			if ( is_string( $value ) ) {
				$values[ $key ] = $this->presenter->clean( $value );
			} elseif ( is_array( $value ) ) {
				$values[ $key ] = $this->clean_texts( $value );
			}
		}
		return $values;
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
		return new WP_Error( 'wpcheckpoint_backup_not_found', __( 'No such backup.', 'wp-checkpoint' ), array( 'status' => 404 ) );
	}

	/**
	 * 409.
	 *
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private function conflict( string $message ): WP_Error {
		return new WP_Error( 'wpcheckpoint_backup_in_use', $message, array( 'status' => 409 ) );
	}

	/**
	 * 503: no storage directory.
	 *
	 * @return WP_Error
	 */
	private function unavailable(): WP_Error {
		return new WP_Error( 'wpcheckpoint_storage_unavailable', __( 'The storage directory is not available.', 'wp-checkpoint' ), array( 'status' => 503 ) );
	}
}
