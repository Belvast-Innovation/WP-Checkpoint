<?php
/**
 * Keeps a job moving without a browser: non-blocking self-requests and a
 * best-effort cron event for delayed re-ticks.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Rest\Controller;
use WPCheckpoint\Support\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * After a tick that ended with "more", the site posts to its own
 * /jobs/{id}/loopback route with a one-time token (body, never the URL:
 * self-request URLs end up in access logs) and does not wait for the
 * answer. The route ticks once and, if there is more, fires the next
 * request: a chain. The chain is only started when the environment probe
 * found the site able to reach itself; every other outcome leaves the
 * browser polling and WP-CLI as drivers.
 *
 * A non-blocking request gives no error signal: a site that gained HTTP
 * authentication or a firewall rule after the probe breaks the chain
 * silently. The browser watchdog (assets/admin/jobs.js) re-ticks when a
 * running job stops changing, and a single cron event per job (deduplicated,
 * cleared on terminal states and cancel) re-ticks after waits. Neither is
 * relied on to be punctual.
 */
final class Loopback {

	const HOOK         = 'wpcheckpoint_job_tick';
	const TOKEN_PREFIX = 'wpcheckpoint_loopback_';
	const JOB_PREFIX   = 'wpcheckpoint_loopback_job_';
	const TOKEN_TTL    = 120;
	const ROUTE_SUFFIX = 'loopback';

	/**
	 * Forced value, or null to detect.
	 *
	 * @var bool|null
	 */
	private $enabled;

	/**
	 * Sender: function( string $url, string $token ): void.
	 *
	 * @var callable
	 */
	private $sender;

	/**
	 * Constructor.
	 *
	 * @param bool|null     $enabled Force on/off (tests); null detects.
	 * @param callable|null $sender  Replacement sender (tests).
	 */
	public function __construct( $enabled = null, $sender = null ) {
		$this->enabled = is_bool( $enabled ) ? $enabled : null;
		$this->sender  = is_callable( $sender ) ? $sender : array( __CLASS__, 'send' );
	}

	/**
	 * Whether self-requests are used: not disabled by the constant, and the
	 * cached environment probe found the site reachable by itself.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		if ( null !== $this->enabled ) {
			return $this->enabled;
		}
		if ( defined( 'WPCHECKPOINT_DISABLE_LOOPBACK' ) && WPCHECKPOINT_DISABLE_LOOPBACK ) {
			return false;
		}
		$cache = get_site_transient( Environment::CACHE );
		return is_array( $cache ) && isset( $cache['loopback']['outcome'] ) && 'reachable' === $cache['loopback']['outcome'];
	}

	/**
	 * What to do after a tick. Called after Runner::tick() returned, so the
	 * lock is released and the cursor committed before the next hop starts.
	 *
	 * @param TickResult $result Tick result.
	 * @return void
	 */
	public function after_tick( TickResult $result ): void {
		if ( null === $result->job ) {
			return;
		}
		$id = $result->job->id;
		switch ( $result->status ) {
			case TickResult::MORE:
				if ( $this->enabled() ) {
					$this->fire( $id );
				}
				break;
			case TickResult::WAITING:
			case TickResult::BLOCKED:
			case TickResult::BUSY:
				if ( $result->retry_after > 0 ) {
					self::schedule( $id, $result->retry_after );
				}
				break;
			default:
				self::unschedule( $id );
				self::revoke_tokens( $id );
		}
	}

	/**
	 * Send the next hop.
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	public function fire( int $job_id ): void {
		$token = self::issue_token( $job_id );
		$url   = rest_url( Controller::ROUTE_NAMESPACE . '/jobs/' . $job_id . '/' . self::ROUTE_SUFFIX );
		call_user_func( $this->sender, $url, $token );
	}

	/**
	 * The real non-blocking POST; the token travels in the body.
	 *
	 * @param string $url   Route URL.
	 * @param string $token One-time token.
	 * @return void
	 */
	public static function send( string $url, string $token ): void {
		wp_remote_post(
			$url,
			array(
				'blocking'    => false,
				'timeout'     => 0.01,
				'redirection' => 0,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false, $url ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, as used by WP_Site_Health.
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
				'body'        => array( 'token' => $token ),
			)
		);
	}

	/**
	 * Create a one-time token for a job. One outstanding token per job: a
	 * new one replaces the previous. Stored hashed, network-wide.
	 *
	 * @param int $job_id Job id.
	 * @return string
	 */
	public static function issue_token( int $job_id ): string {
		self::revoke_tokens( $job_id );
		$token = bin2hex( random_bytes( 16 ) );
		$hash  = hash( 'sha256', $token );
		set_site_transient( self::TOKEN_PREFIX . $hash, $job_id, self::TOKEN_TTL );
		set_site_transient( self::JOB_PREFIX . $job_id, $hash, self::TOKEN_TTL );
		return $token;
	}

	/**
	 * Accept a token exactly once for the given job.
	 *
	 * @param int    $job_id Job id from the route.
	 * @param string $token  Token from the body.
	 * @return bool
	 */
	public static function consume_token( int $job_id, string $token ): bool {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return false;
		}
		$hash  = hash( 'sha256', $token );
		$found = get_site_transient( self::TOKEN_PREFIX . $hash );
		delete_site_transient( self::TOKEN_PREFIX . $hash );
		if ( false === $found || (int) $found !== $job_id ) {
			return false;
		}
		delete_site_transient( self::JOB_PREFIX . $job_id );
		return true;
	}

	/**
	 * Forget the outstanding token of a job.
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	public static function revoke_tokens( int $job_id ): void {
		$hash = get_site_transient( self::JOB_PREFIX . $job_id );
		if ( is_string( $hash ) && '' !== $hash ) {
			delete_site_transient( self::TOKEN_PREFIX . $hash );
		}
		delete_site_transient( self::JOB_PREFIX . $job_id );
	}

	/**
	 * One delayed re-tick per job through WP-Cron (best effort, never relied
	 * on to be punctual).
	 *
	 * @param int $job_id  Job id.
	 * @param int $seconds Delay.
	 * @return void
	 */
	public static function schedule( int $job_id, int $seconds ): void {
		if ( false !== wp_next_scheduled( self::HOOK, array( $job_id ) ) ) {
			return;
		}
		wp_schedule_single_event( time() + max( 1, $seconds ), self::HOOK, array( $job_id ) );
	}

	/**
	 * Drop the cron event of a job.
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	public static function unschedule( int $job_id ): void {
		wp_clear_scheduled_hook( self::HOOK, array( $job_id ) );
	}

	/**
	 * Drop every job cron event (uninstall).
	 *
	 * @return void
	 */
	public static function unschedule_all(): void {
		if ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( self::HOOK );
		}
	}
}
