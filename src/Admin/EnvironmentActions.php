<?php
/**
 * Buttons on the Tools tab: re-check the environment, re-verify protection.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Admin;

use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Environment;
use WPCheckpoint\Support\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Both actions make loopback requests, so each is limited to one run per
 * minute installation-wide (site transient lock). Distinct nonce actions.
 */
final class EnvironmentActions {

	const ACTION_RECHECK = 'wpcheckpoint_recheck_environment';
	const ACTION_VERIFY  = 'wpcheckpoint_verify_protection';
	const NONCE_RECHECK  = 'recheck-environment';
	const NONCE_VERIFY   = 'verify-protection';
	const LOCK_SECONDS   = 60;
	const RESULT_PARAM   = 'wpcheckpoint_result';

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
		add_action( 'admin_post_' . self::ACTION_RECHECK, array( $this, 'recheck' ) );
		add_action( 'admin_post_' . self::ACTION_VERIFY, array( $this, 'verify' ) );
	}

	/**
	 * Re-run the cached probes.
	 *
	 * @return void
	 */
	public function recheck(): void {
		Guard::require_admin_post( self::NONCE_RECHECK );
		$this->redirect( $this->run_recheck() );
		exit;
	}

	/**
	 * Re-run the storage protection verification.
	 *
	 * @return void
	 */
	public function verify(): void {
		Guard::require_admin_post( self::NONCE_VERIFY );
		$this->redirect( $this->run_verify() );
		exit;
	}

	/**
	 * Perform the re-check unless locked. Separated for tests.
	 *
	 * @return string Result key.
	 */
	public function run_recheck(): string {
		if ( ! self::acquire_lock( 'recheck' ) ) {
			return 'locked';
		}
		Environment::invalidate();
		( new Environment( $this->directories ) )->checks( true );
		return 'rechecked';
	}

	/**
	 * Perform the verification unless locked. Separated for tests.
	 *
	 * @return string Result key.
	 */
	public function run_verify(): string {
		if ( ! self::acquire_lock( 'verify' ) ) {
			return 'locked';
		}
		$this->directories->verify_protection();
		return 'verified';
	}

	/**
	 * Seconds until an action may run again (0 = now).
	 *
	 * @param string $which recheck|verify.
	 * @return int
	 */
	public static function seconds_locked( string $which ): int {
		$until = (int) get_site_transient( self::lock_key( $which ) );
		return max( 0, $until - time() );
	}

	/**
	 * Take the lock for an action.
	 *
	 * @param string $which recheck|verify.
	 * @return bool False when still locked.
	 */
	private static function acquire_lock( string $which ): bool {
		if ( self::seconds_locked( $which ) > 0 ) {
			return false;
		}
		set_site_transient( self::lock_key( $which ), time() + self::LOCK_SECONDS, self::LOCK_SECONDS );
		return true;
	}

	/**
	 * Lock transient name.
	 *
	 * @param string $which recheck|verify.
	 * @return string
	 */
	private static function lock_key( string $which ): string {
		return 'wpcheckpoint_lock_' . sanitize_key( $which );
	}

	/**
	 * Back to the Tools tab with a result flag.
	 *
	 * @param string $result Result key.
	 * @return void
	 */
	private function redirect( string $result ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => Page::SLUG,
					'tab'              => 'tools',
					self::RESULT_PARAM => $result,
				),
				admin_url( 'admin.php' )
			)
		);
	}
}
