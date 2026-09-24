<?php
/**
 * Confirmation actions for reclaiming the original storage directory.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Admin;

use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * The admin-post handlers: reclaim (after the administrator confirmed), stop
 * trusting the deployment root, and dismiss the automatic take-over notice.
 * The directory is always the one recorded in the state; requests carry no
 * paths.
 */
final class ReclaimActions {

	const ACTION_RECLAIM = 'wpcheckpoint_reclaim_storage';
	const ACTION_UNTRUST = 'wpcheckpoint_untrust_deploy_root';
	const NONCE_RECLAIM  = 'reclaim-storage';
	const NONCE_UNTRUST  = 'untrust-deploy-root';
	const QUERY_FLAG     = 'wpcheckpoint_reclaim';

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
	 * Hook the admin-post actions.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_RECLAIM, array( $this, 'reclaim' ) );
		add_action( 'admin_post_' . self::ACTION_UNTRUST, array( $this, 'untrust' ) );
	}

	/**
	 * URL of the confirmation page.
	 *
	 * @return string
	 */
	public static function confirmation_url(): string {
		return add_query_arg(
			array(
				'page'           => Page::SLUG,
				'tab'            => 'tools',
				self::QUERY_FLAG => '1',
			),
			Page::base_url()
		);
	}

	/**
	 * POST handler for the confirmation form.
	 *
	 * @return void
	 */
	public function reclaim(): void {
		Guard::require_admin_post( self::NONCE_RECLAIM );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by require_admin_post() above.
		$confirmed  = isset( $_POST['wpcheckpoint_confirm'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['wpcheckpoint_confirm'] ) );
		$token      = isset( $_POST['wpcheckpoint_token'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['wpcheckpoint_token'] ) ) ) : '';
		$trust_root = isset( $_POST['wpcheckpoint_trust_root'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['wpcheckpoint_trust_root'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = $this->run_reclaim( $confirmed, $token, $trust_root );
		$this->redirect( $result['ok'] ? 'reclaimed' : 'reclaim_failed', $result['message'] );
		exit;
	}

	/**
	 * Validate the confirmation and perform the take-over. Separated for tests.
	 *
	 * @param bool   $confirmed  Checkbox state.
	 * @param string $token      Directory token typed by the administrator.
	 * @param bool   $trust_root Remember the deployment root.
	 * @return array{ok: bool, message: string}
	 */
	public function run_reclaim( bool $confirmed, string $token, bool $trust_root ): array {
		$reclaim = $this->directories->reclaim();
		$target  = $reclaim->target();
		if ( '' === $target ) {
			return array(
				'ok'      => false,
				'message' => __( 'There is no directory to reclaim.', 'wp-checkpoint' ),
			);
		}
		if ( ! $confirmed ) {
			return array(
				'ok'      => false,
				'message' => __( 'Please confirm that the other copy of this site will lose access to these backups.', 'wp-checkpoint' ),
			);
		}
		$expected = self::expected_token( $target );
		if ( '' === $expected || ! hash_equals( $expected, $token ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'The directory name you typed does not match.', 'wp-checkpoint' ),
			);
		}

		$result = $reclaim->reclaim( $trust_root );
		if ( ! $result['ok'] ) {
			return array(
				'ok'      => false,
				'message' => $result['message'],
			);
		}
		$this->directories->finish_reclaim( $result['trusted_root'] );
		// Jobs created for the replacement directory while the clone notice was pending can never continue.
		( new \WPCheckpoint\Jobs\JobRepository( $this->directories ) )->settle_storage();
		return array(
			'ok'      => true,
			'message' => $result['message'],
		);
	}

	/**
	 * Token the administrator has to type: the random part of the directory name.
	 *
	 * @param string $dir Directory.
	 * @return string
	 */
	public static function expected_token( string $dir ): string {
		$name = basename( $dir );
		if ( 0 === strpos( $name, Directories::DIR_PREFIX ) ) {
			$token = substr( $name, strlen( Directories::DIR_PREFIX ) );
			return Directories::is_valid_token( $token ) ? $token : '';
		}
		return strtolower( $name );
	}

	/**
	 * POST handler that forgets the trusted deployment root.
	 *
	 * @return void
	 */
	public function untrust(): void {
		Guard::require_admin_post( self::NONCE_UNTRUST );
		$this->directories->untrust_deploy_root();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => Page::SLUG,
					'tab'  => 'settings',
				),
				Page::base_url()
			)
		);
		exit;
	}

	/**
	 * Back to the Tools tab with a result flag and message.
	 *
	 * @param string $result  Result key.
	 * @param string $message Message shown once.
	 * @return void
	 */
	private function redirect( string $result, string $message ): void {
		set_site_transient( 'wpcheckpoint_reclaim_message_' . get_current_user_id(), $message, 60 );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                           => Page::SLUG,
					'tab'                            => 'tools',
					EnvironmentActions::RESULT_PARAM => $result,
				),
				Page::base_url()
			)
		);
	}
}
