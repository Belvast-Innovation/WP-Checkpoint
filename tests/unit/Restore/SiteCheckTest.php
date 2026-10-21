<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Restore\SelfCheck;
use WPCheckpoint\Restore\SiteCheck;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The checks of the restored site and their verdict: what passes, what fails (a fatal error page seen twice, 15
 * seconds apart; the self-check's wrong answer at once), and what cannot be told (everything else), with the code
 * of what was seen; same-host redirects followed, another host's not.
 */
final class SiteCheckTest extends TestCase {

	const HOME  = 'http://site.test/';
	const LOGIN = 'http://site.test/wp-login.php';
	const SELF  = 'http://site.test/?rest_route=/wp-checkpoint/v1/self-check';

	/** @var array<int, array{0: string, 1: array<string, string>}> The requests made: URL, headers. */
	private $requests = array();

	/** @var int[] The waits. */
	private $slept = array();

	/**
	 * WordPress's fatal error page (wp_die()), in a language.
	 *
	 * @param string $text Its text.
	 * @return array{status: int, body: string}
	 */
	private static function fatal( string $text = 'There has been a critical error on this website.' ): array {
		return array(
			'status' => 500,
			'body'   => "<!DOCTYPE html>\n<html lang=\"de-DE\">\n<head><title>WordPress &rsaquo; Error</title></head>\n<body id=\"error-page\">\n\t<div class=\"wp-die-message\"><p>{$text}</p></div></body>\n</html>",
		);
	}

	/**
	 * Run the checks on a web that answers each URL (by prefix, longest first) with its answers in turn (the last
	 * one again when they run out).
	 *
	 * @param array<string, array<int, array<string, mixed>>> $web Answers by URL prefix.
	 * @return array{verdict: string, check: string, why: string, status: int}
	 */
	private function checked( array $web ): array {
		$this->requests = array();
		$this->slept    = array();
		$key            = str_repeat( 'ab', 32 );
		$nonce          = str_repeat( 'cd', 32 );
		$challenge      = str_repeat( 'ef', 32 );
		$web           += array(
			self::SELF  => array(
				array(
					'status' => 200,
					'body'   => json_encode(
						array(
							'challenge' => $challenge,
							'answer'    => SelfCheck::answer( $nonce, $challenge ),
						)
					),
				),
			),
			self::LOGIN => array( array( 'status' => 200 ) ),
			self::HOME  => array( array( 'status' => 200 ) ),
		);
		uksort(
			$web,
			static function ( string $a, string $b ): int {
				return strlen( $b ) - strlen( $a );
			}
		);
		$check = new SiteCheck(
			function ( string $url, array $headers ) use ( &$web ): array {
				$this->requests[] = array( $url, $headers );
				foreach ( $web as $prefix => &$answers ) {
					if ( 0 === strpos( $url, $prefix ) ) {
						return count( $answers ) > 1 ? array_shift( $answers ) : $answers[0];
					}
				}
				return array( 'status' => 0 );
			},
			function ( int $seconds ): void {
				$this->slept[] = $seconds;
			}
		);
		return $check->check(
			array(
				'home'  => self::HOME,
				'login' => self::LOGIN,
				'self'  => self::SELF,
			),
			$key,
			$nonce,
			$challenge
		);
	}

	private function assertVerdict( string $verdict, string $check, string $why, array $result, string $what ): void {
		$this->assertSame( array( $verdict, $check, $why ), array( $result['verdict'], $result['check'], $result['why'] ), $what );
	}

	public function test_a_site_that_answers_passes_and_every_request_carries_the_pass(): void {
		$result = $this->checked( array() );
		$this->assertVerdict( SiteCheck::PASSED, '', 'ok', $result, 'home 2xx, the right answer, login 2xx' );
		$this->assertSame( array(), $this->slept );
		$this->assertCount( 3, $this->requests );
		foreach ( $this->requests as list( $url, $headers ) ) {
			$this->assertSame( array( Maintenance::PASS_HEADER => str_repeat( 'ab', 32 ) ), $headers, $url );
		}
		$this->assertStringContainsString( 'challenge=' . str_repeat( 'ef', 32 ), $this->requests[0][0], 'a new challenge' );
	}

	public function test_a_fatal_error_page_seen_twice_fails_and_seen_once_cannot_be_told(): void {
		foreach ( array( 'English' => self::fatal(), 'German' => self::fatal( 'Es gab einen kritischen Fehler auf deiner Website.' ) ) as $language => $page ) {
			$result = $this->checked( array( self::HOME => array( $page, $page ) ) );
			$this->assertVerdict( SiteCheck::FAILED, 'home', 'fatal', $result, $language . ': the fatal page twice' );
			$this->assertSame( 500, $result['status'] );
			$this->assertSame( array( 15 ), $this->slept, 'seen again fifteen seconds on' );
		}
		$result = $this->checked( array( self::HOME => array( self::fatal(), array( 'status' => 200 ) ) ) );
		$this->assertVerdict( SiteCheck::UNKNOWN, 'home', 'fatal_once', $result, 'once, then the page: a database connection error may pass' );
		$result = $this->checked( array( self::LOGIN => array( self::fatal(), self::fatal() ) ) );
		$this->assertVerdict( SiteCheck::FAILED, 'login', 'fatal', $result, 'wp-login.php: the fatal page twice fails' );
		$result = $this->checked( array( self::LOGIN => array( self::fatal(), array( 'status' => 200 ) ) ) );
		$this->assertVerdict( SiteCheck::PASSED, '', 'ok', $result, 'wp-login.php: once, then answered: no evidence' );
	}

	public function test_anything_else_from_the_home_page_cannot_be_told(): void {
		foreach ( array(
			'404'                     => array( array( 'status' => 404 ), 'status', 404 ),
			'a 500 that is not wp_die' => array( array( 'status' => 500, 'body' => '' ), 'status', 500 ),
			'401'                     => array( array( 'status' => 401 ), 'status', 401 ),
			'403'                     => array( array( 'status' => 403 ), 'status', 403 ),
			'502'                     => array( array( 'status' => 502 ), 'status', 502 ),
			'503 (maintenance)'       => array( array( 'status' => 503, 'body' => '<body id="error-page">' ), 'status', 503 ),
			'504'                     => array( array( 'status' => 504 ), 'status', 504 ),
			'no answer'               => array( array( 'status' => 0 ), 'none', 0 ),
			'another host'            => array( array( 'status' => 301, 'location' => 'https://elsewhere.test/' ), 'other_host', 301 ),
			'another scheme'          => array( array( 'status' => 302, 'location' => 'https://site.test/' ), 'other_host', 302 ),
		) as $what => list( $answer, $why, $status ) ) {
			$result = $this->checked( array( self::HOME => array( $answer ) ) );
			$this->assertVerdict( SiteCheck::UNKNOWN, 'home', $why, $result, $what );
			$this->assertSame( $status, $result['status'], $what );
			$this->assertSame( array(), $this->slept, $what . ': no second look' );
		}
	}

	public function test_redirects_to_the_same_host_are_followed_a_few_times(): void {
		$result = $this->checked( array( self::HOME => array( array( 'status' => 301, 'location' => '/home/' ), array( 'status' => 200 ) ) ) );
		$this->assertVerdict( SiteCheck::PASSED, '', 'ok', $result, 'the same host' );
		$this->assertContains( 'http://site.test/home/', array_column( $this->requests, 0 ), 'followed' );
		$loop   = array_fill( 0, 10, array( 'status' => 302, 'location' => 'http://site.test/again' ) );
		$result = $this->checked( array( self::HOME => $loop ) );
		$this->assertVerdict( SiteCheck::UNKNOWN, 'home', 'redirects', $result, 'too many' );
		$this->assertSame( 1 + 1 + 1 + SiteCheck::MAX_REDIRECTS, count( $this->requests ), 'the route, wp-login.php, and the home page with its redirects' );
	}

	public function test_wp_login_gives_evidence_of_a_failure_only(): void {
		foreach ( array(
			'404'                 => array( 'status' => 404 ),
			'401'                 => array( 'status' => 401 ),
			'403'                 => array( 'status' => 403 ),
			'a same-host redirect' => array( 'status' => 302, 'location' => '/?blocked' ),
			'no answer'           => array( 'status' => 0 ),
		) as $what => $answer ) {
			$result = $this->checked( array( self::LOGIN => array( $answer ) ) );
			$this->assertVerdict( SiteCheck::PASSED, '', 'ok', $result, $what . ': neither a failure nor holding a pass back' );
		}
	}

	public function test_the_self_check_fails_on_a_wrong_answer_at_once_and_cannot_be_told_otherwise(): void {
		$challenge = str_repeat( 'ef', 32 );
		$result    = $this->checked( array( self::SELF => array( array( 'status' => 200, 'body' => json_encode( array( 'challenge' => $challenge, 'answer' => str_repeat( '0', 64 ) ) ) ) ) ) );
		$this->assertVerdict( SiteCheck::FAILED, 'self', 'wrong_answer', $result, 'another options table answered' );
		$this->assertCount( 1, $this->requests, 'at once: nothing else is asked' );
		foreach ( array(
			'an answer cached'  => array( array( 'status' => 200, 'body' => json_encode( array( 'challenge' => str_repeat( '11', 32 ), 'answer' => 'x' ) ) ), 'old_answer' ),
			'no such route'     => array( array( 'status' => 404, 'body' => '{"code":"rest_no_route"}' ), 'no_route' ),
			'refused'           => array( array( 'status' => 403, 'body' => '{"code":"rest_forbidden"}' ), 'status' ),
			'not JSON'          => array( array( 'status' => 200, 'body' => '<html>' ), 'status' ),
			'no answer'         => array( array( 'status' => 0 ), 'none' ),
		) as $what => list( $answer, $why ) ) {
			$result = $this->checked( array( self::SELF => array( $answer ) ) );
			$this->assertVerdict( SiteCheck::UNKNOWN, 'self', $why, $result, $what );
		}
	}

	public function test_a_failure_wins_over_what_cannot_be_told(): void {
		$result = $this->checked(
			array(
				self::HOME  => array( array( 'status' => 404 ) ),
				self::LOGIN => array( self::fatal(), self::fatal() ),
			)
		);
		$this->assertVerdict( SiteCheck::FAILED, 'login', 'fatal', $result, 'the home page not told, wp-login.php failed' );
	}

	public function test_only_wp_dies_structure_with_500_is_the_fatal_page(): void {
		$this->assertTrue( SiteCheck::fatal( 500, self::fatal()['body'] ) );
		$this->assertTrue( SiteCheck::fatal( 500, "<body class='x' id='error-page'>" ) );
		$this->assertFalse( SiteCheck::fatal( 200, self::fatal()['body'] ), 'not a 500' );
		$this->assertFalse( SiteCheck::fatal( 503, self::fatal()['body'] ), 'the maintenance page has it too' );
		$this->assertFalse( SiteCheck::fatal( 500, '<p>There has been a critical error on this website.</p>' ), 'the words alone are not the structure' );
		$this->assertFalse( SiteCheck::fatal( 500, '' ) );
	}
}
