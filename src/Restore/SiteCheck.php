<?php
/**
 * The checks of the restored site, made through the web server before the site is let out.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * Three requests, each with the pass header the maintenance file lets through (Maintenance::PASS_HEADER) and a
 * parameter no cache has seen: the home page, wp-login.php and the plugin's self-check route (SelfCheck: the
 * challenge back and HMAC(one-time value, challenge), from the restored options table). Redirects to the same host
 * (scheme, host and port) are followed, at most MAX_REDIRECTS; one to another host ends the request.
 *
 * - The home page: 2xx passes; WordPress's fatal error page (HTTP 500 with the structure of wp_die()'s page,
 *   id="error-page", whatever its language) fails if it is seen again CONFIRM_SECONDS later, and is "unknown" if the
 *   page is answered otherwise then (a database connection error page has the same form, and may pass); anything
 *   else is "unknown" (404, a 500 without that structure, 401, 403, 502, 503, 504, no answer, another host).
 * - wp-login.php gives evidence of a failure only (the same fatal page, seen twice); whatever else it answers
 *   (2xx, 404, 401, 403, a redirect) neither fails nor holds back a pass: hardened sites block it.
 * - The self-check route: the challenge back with the right answer passes; with a wrong answer fails at once (the
 *   request reached this PHP, which read another options table than the one swapped in); anything else (another
 *   challenge: an answer cached; no such route: the plugin not active or another version) is "unknown".
 *
 * The verdict: failed when any check failed; passed when the home page and the self-check route passed and
 * wp-login.php did not fail; "unknown" otherwise. What it found is said by a code, a check id and an HTTP status
 * (no URL, no text of the site's): a cursor may keep it.
 */
final class SiteCheck {

	const PASSED  = 'passed';
	const FAILED  = 'failed';
	const UNKNOWN = 'unknown';

	/**
	 * Seconds between the two sightings of a fatal error page.
	 */
	const CONFIRM_SECONDS = 15;

	/**
	 * Most redirects to the same host followed.
	 */
	const MAX_REDIRECTS = 5;

	/**
	 * Requests: function( string $url, array $headers ): array{status: int, body: string, location: string} (status
	 * 0 when no answer came).
	 *
	 * @var callable
	 */
	private $get;

	/**
	 * Waits: function( int $seconds ): void.
	 *
	 * @var callable
	 */
	private $sleep;

	/**
	 * Constructor.
	 *
	 * @param callable $get   Requests.
	 * @param callable $sleep Waits.
	 */
	public function __construct( callable $get, callable $sleep ) {
		$this->get   = $get;
		$this->sleep = $sleep;
	}

	/**
	 * Check the site.
	 *
	 * @param array{home: string, login: string, self: string} $urls      The home page, wp-login.php, and the
	 *                                                                    self-check route (?rest_route=…).
	 * @param string                                           $key       The pass key.
	 * @param string                                           $nonce     The one-time value the self-check row holds.
	 * @param string                                           $challenge A new challenge (64 lowercase hex).
	 * @return array{verdict: string, check: string, why: string, status: int} check: the check that failed, or the
	 *                                                                      first that could not be told; why: a code.
	 */
	public function check( array $urls, string $key, string $nonce, string $challenge ): array {
		$self = $this->self_check( $urls['self'], $key, $nonce, $challenge );
		if ( self::FAILED === $self['state'] ) {
			return self::verdict( self::FAILED, 'self', $self );
		}
		$home  = $this->page( $urls['home'], $key );
		$login = $this->page( $urls['login'], $key );
		$fatal = array();
		foreach ( array(
			'home'  => $home,
			'login' => $login,
		) as $check => $seen ) {
			if ( 'fatal' === $seen['state'] ) {
				$fatal[] = $check;
			}
		}
		if ( array() !== $fatal ) {
			call_user_func( $this->sleep, self::CONFIRM_SECONDS );
			foreach ( $fatal as $check ) {
				$again = $this->page( $urls[ $check ], $key );
				if ( 'fatal' === $again['state'] ) {
					return self::verdict( self::FAILED, $check, $again );
				}
				// Answered otherwise the second time: what it was the first time cannot be told.
				$again['state'] = 'once';
				$again['why']   = 'fatal_once';
				if ( 'home' === $check ) {
					$home = $again;
				} else {
					$login = $again;
				}
			}
		}
		if ( 'pass' === $home['state'] && 'pass' === $self['state'] ) {
			return self::verdict( self::PASSED, '', $home );
		}
		foreach ( array(
			'home' => $home,
			'self' => $self,
		) as $check => $seen ) {
			if ( 'pass' !== $seen['state'] ) {
				return self::verdict( self::UNKNOWN, $check, $seen );
			}
		}
		return self::verdict( self::UNKNOWN, 'home', $home ); // Not reached: both passed above.
	}

	/**
	 * Whether an answer is WordPress's fatal error page: HTTP 500 with the structure of wp_die()'s page.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Body.
	 * @return bool
	 */
	public static function fatal( int $status, string $body ): bool {
		return 500 === $status && 1 === preg_match( '/<body[^>]*\bid=["\']error-page["\']/i', $body );
	}

	/**
	 * A page (the home page, wp-login.php): pass (2xx), fatal, or unknown, with a code.
	 *
	 * @param string $url URL.
	 * @param string $key Pass key.
	 * @return array{state: string, why: string, status: int}
	 */
	private function page( string $url, string $key ): array {
		$seen = $this->fetch( $url, $key );
		if ( '' !== $seen['why'] ) {
			return array( 'state' => 'unknown' ) + $seen;
		}
		if ( $seen['status'] >= 200 && $seen['status'] < 300 ) {
			return array(
				'state'  => 'pass',
				'why'    => 'ok',
				'status' => $seen['status'],
			);
		}
		if ( self::fatal( $seen['status'], $seen['body'] ) ) {
			return array(
				'state'  => 'fatal',
				'why'    => 'fatal',
				'status' => $seen['status'],
			);
		}
		return array(
			'state'  => 'unknown',
			'why'    => 'status',
			'status' => $seen['status'],
		);
	}

	/**
	 * The self-check route: pass, failed, or unknown, with a code.
	 *
	 * @param string $url       URL (the route, without the challenge).
	 * @param string $key       Pass key.
	 * @param string $nonce     One-time value.
	 * @param string $challenge Challenge.
	 * @return array{state: string, why: string, status: int}
	 */
	private function self_check( string $url, string $key, string $nonce, string $challenge ): array {
		$seen = $this->fetch( $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . 'challenge=' . $challenge, $key );
		if ( '' !== $seen['why'] ) {
			return array( 'state' => 'unknown' ) + $seen;
		}
		$body = json_decode( $seen['body'], true );
		if ( 200 !== $seen['status'] || ! is_array( $body ) || ! is_string( $body['challenge'] ?? null ) || ! is_string( $body['answer'] ?? null ) ) {
			return array(
				'state'  => 'unknown',
				'why'    => 404 === $seen['status'] ? 'no_route' : 'status',
				'status' => $seen['status'],
			);
		}
		if ( ! hash_equals( $challenge, $body['challenge'] ) ) {
			return array(
				'state'  => 'unknown',
				'why'    => 'old_answer',
				'status' => $seen['status'],
			);
		}
		$right = hash_equals( SelfCheck::answer( $nonce, $challenge ), $body['answer'] );
		return array(
			'state'  => $right ? 'pass' : self::FAILED,
			'why'    => $right ? 'ok' : 'wrong_answer',
			'status' => $seen['status'],
		);
	}

	/**
	 * A request, following redirects to the same host: the last answer, or why there is none to judge (why: "none",
	 * "other_host", "redirects"; '' when there is one).
	 *
	 * @param string $url URL.
	 * @param string $key Pass key.
	 * @return array{status: int, body: string, why: string}
	 */
	private function fetch( string $url, string $key ): array {
		$origin = self::origin( $url );
		for ( $i = 0; $i <= self::MAX_REDIRECTS; $i++ ) {
			$answer = (array) call_user_func( $this->get, $url, array( Maintenance::PASS_HEADER => $key ) );
			$status = (int) ( $answer['status'] ?? 0 );
			if ( 0 === $status ) {
				return array(
					'status' => 0,
					'body'   => '',
					'why'    => 'none',
				);
			}
			$location = (string) ( $answer['location'] ?? '' );
			if ( $status < 300 || $status >= 400 || '' === $location ) {
				return array(
					'status' => $status,
					'body'   => (string) ( $answer['body'] ?? '' ),
					'why'    => '',
				);
			}
			$next = self::resolve( $url, $location );
			if ( self::origin( $next ) !== $origin ) {
				return array(
					'status' => $status,
					'body'   => '',
					'why'    => 'other_host',
				);
			}
			$url = $next;
		}
		return array(
			'status' => $status,
			'body'   => '',
			'why'    => 'redirects',
		);
	}

	/**
	 * Scheme, host and port of a URL (the port the scheme's when none is given), lower-case.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function origin( string $url ): string {
		$parts  = (array) parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure PHP, also unit-tested without WordPress.
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$port   = (int) ( $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 ) );
		return $scheme . '://' . strtolower( (string) ( $parts['host'] ?? '' ) ) . ':' . $port;
	}

	/**
	 * A redirect's Location against the URL it answered.
	 *
	 * @param string $base     URL.
	 * @param string $location Location.
	 * @return string
	 */
	private static function resolve( string $base, string $location ): string {
		if ( 1 === preg_match( '#\A[a-z][a-z0-9+.-]*://#i', $location ) ) {
			return $location;
		}
		$parts = (array) parse_url( $base ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- as above.
		$root  = (string) ( $parts['scheme'] ?? 'http' ) . '://' . (string) ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
		if ( 0 === strpos( $location, '//' ) ) {
			return (string) ( $parts['scheme'] ?? 'http' ) . ':' . $location;
		}
		if ( 0 === strpos( $location, '/' ) ) {
			return $root . $location;
		}
		$path = (string) ( $parts['path'] ?? '/' );
		return $root . substr( $path, 0, (int) strrpos( $path, '/' ) + 1 ) . $location;
	}

	/**
	 * Shape the verdict.
	 *
	 * @param string                                          $verdict Verdict.
	 * @param string                                          $check   Check id ('' when passed).
	 * @param array{state?: string, why: string, status: int} $seen    What was seen.
	 * @return array{verdict: string, check: string, why: string, status: int}
	 */
	private static function verdict( string $verdict, string $check, array $seen ): array {
		return array(
			'verdict' => $verdict,
			'check'   => $check,
			'why'     => $seen['why'],
			'status'  => $seen['status'],
		);
	}
}
