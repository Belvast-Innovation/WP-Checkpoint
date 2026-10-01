<?php
/**
 * The administrator's answer to whether this is the site that chose the storage directory.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Admin;

use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Guard;
use WPCheckpoint\Support\StoredNames;

defined( 'ABSPATH' ) || exit;

/**
 * The admin-post handler for Directories::identity_question(): two answers, "copy" (a copy of the site, or moved
 * here) and "same" (the same site), neither preselected. The request carries the answer and the id of the question it
 * answers; an answer to another question than this request would ask is refused.
 */
final class SiteIdentityActions {

	const ACTION   = 'wpcheckpoint_site_identity';
	const NONCE    = 'site-identity';
	const FIELD    = 'wpcheckpoint_answer';
	const QUESTION = 'wpcheckpoint_question';

	/**
	 * Storage directories.
	 *
	 * @var Directories
	 */
	private $directories;

	/**
	 * Constructor.
	 *
	 * @param Directories $directories Storage directories.
	 */
	public function __construct( Directories $directories ) {
		$this->directories = $directories;
	}

	/**
	 * Hook the admin-post action.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'answer' ) );
	}

	/**
	 * POST handler.
	 *
	 * @return void
	 */
	public function answer(): void {
		Guard::require_admin_post( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by require_admin_post() above.
		$answer   = isset( $_POST[ self::FIELD ] ) && is_string( $_POST[ self::FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::FIELD ] ) ) : '';
		$question = isset( $_POST[ self::QUESTION ] ) && is_string( $_POST[ self::QUESTION ] ) ? sanitize_key( wp_unslash( $_POST[ self::QUESTION ] ) ) : '';
		// phpcs:enable
		$result = $this->run( $answer, $question );
		set_site_transient( StoredNames::reclaim_message( get_current_user_id() ), $result['message'], 60 );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                           => Page::SLUG,
					'tab'                            => 'tools',
					EnvironmentActions::RESULT_PARAM => $result['ok'] ? 'identity_answered' : 'identity_failed',
				),
				Page::base_url()
			)
		);
		exit;
	}

	/**
	 * Record the answer and settle the jobs it decides about. Separated for tests.
	 *
	 * @param string $answer   Directories::ANSWER_COPY or ANSWER_SAME.
	 * @param string $question The id of the question shown with the answer.
	 * @return array{ok: bool, message: string}
	 */
	public function run( string $answer, string $question ): array {
		$result = $this->directories->answer_identity( $answer, $question );
		if ( $result['ok'] ) {
			// Jobs whose storage is decided now not to be this site's can never continue.
			( new JobRepository( $this->directories ) )->settle_storage();
		}
		return $result;
	}
}
