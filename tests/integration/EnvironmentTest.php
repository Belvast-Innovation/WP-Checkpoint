<?php

namespace WPCheckpoint\Tests\Integration;

use WP_Error;
use WP_UnitTestCase;
use WPCheckpoint\Admin\EnvironmentActions;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Check;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Environment;
use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\Report;

final class EnvironmentTest extends WP_UnitTestCase {

	/** @var Directories */
	private $dirs;

	public function set_up(): void {
		parent::set_up();
		Options::delete( Directories::OPTION );
		Environment::invalidate();
		delete_site_transient( 'wpcheckpoint_lock_recheck' );
		delete_site_transient( 'wpcheckpoint_lock_verify' );
		$this->dirs = new Directories( array( 'is_web_request' => false, 'document_root' => '' ) );
		$this->assertNotSame( '', $this->dirs->base() );
	}

	public function tear_down(): void {
		$base = $this->dirs->state()['path'];
		if ( '' !== $base && is_dir( $base ) ) {
			Deleter::empty_directory( $base );
			@rmdir( $base );
		}
		Options::delete( Directories::OPTION );
		Environment::invalidate();
		delete_site_transient( 'wpcheckpoint_lock_recheck' );
		delete_site_transient( 'wpcheckpoint_lock_verify' );
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * A loopback probe that behaves like a real WordPress answering the route.
	 */
	private function echo_probe( array $overrides = array() ): callable {
		return static function ( string $challenge ) use ( $overrides ): array {
			$body = array_merge(
				array(
					'challenge'          => $challenge,
					'memory_limit'       => '40M',
					'memory_bytes'       => 41943040,
					'max_execution_time' => 30,
					'set_time_limit'     => false,
				),
				$overrides
			);
			return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( $body ), 'headers' => array(), 'cookies' => array(), 'filename' => null );
		};
	}

	private function find( array $checks, string $id ): Check {
		foreach ( $checks as $check ) {
			if ( $check->id === $id ) {
				return $check;
			}
		}
		$this->fail( "check {$id} missing" );
	}

	public function test_missing_zip_archive_is_a_warning_that_announces_tar(): void {
		$env    = new Environment( $this->dirs, array( 'class_exists' => '__return_false', 'loopback' => $this->echo_probe() ) );
		$check  = $this->find( $env->checks(), 'php.zip' );
		$this->assertSame( Check::WARNING, $check->status );
		$this->assertStringContainsString( 'tar', $check->message );

		$env   = new Environment( $this->dirs, array( 'class_exists' => '__return_true', 'loopback' => $this->echo_probe() ) );
		$this->assertSame( Check::OK, $this->find( $env->checks( true ), 'php.zip' )->status );
	}

	public function test_task_runtime_limits_come_from_the_probe_and_drive_thresholds(): void {
		$env    = new Environment( $this->dirs, array( 'loopback' => $this->echo_probe() ) );
		$checks = $env->checks();

		$this->assertSame( Check::ERROR, $this->find( $checks, 'limits.task_memory' )->status, '40M task memory is an error even though the admin page has more' );
		$this->assertSame( '40M', $this->find( $checks, 'limits.task_memory' )->value );
		$time = $this->find( $checks, 'limits.task_time' );
		$this->assertSame( Check::OK, $time->status );
		$this->assertStringContainsString( 'at most 15 seconds', $time->message );
		$this->assertStringContainsString( 'fastcgi_read_timeout', $time->message );
		$this->assertSame( Check::INFO, $this->find( $checks, 'limits.admin_memory' )->status );
		$this->assertSame( Check::OK, $this->find( $checks, 'loopback.rest' )->status );
	}

	/**
	 * @dataProvider loopback_outcomes
	 */
	public function test_loopback_outcomes( $response, string $outcome, string $status ): void {
		$probe = is_callable( $response ) ? $response : static function () use ( $response ) {
			return $response;
		};
		$env   = new Environment( $this->dirs, array( 'loopback' => $probe ) );
		$check = $this->find( $env->checks(), 'loopback.rest' );
		$this->assertStringStartsWith( $outcome, $check->value );
		$this->assertSame( $status, $check->status );
		if ( Check::OK !== $status ) {
			$this->assertStringContainsString( 'browser page open or by WP-CLI', $check->impact );
			$this->assertSame( 'unknown', $this->find( $env->checks(), 'limits.task_memory' )->value );
		}
	}

	public function loopback_outcomes(): array {
		$http = static function ( int $code, string $body ): array {
			return array( 'response' => array( 'code' => $code, 'message' => '' ), 'body' => $body, 'headers' => array(), 'cookies' => array(), 'filename' => null );
		};
		return array(
			'echo is reachable'        => array( $this->echo_probe(), 'reachable', Check::OK ),
			'wrong echo is altered'    => array( $http( 200, '{"challenge":"deadbeef"}' ), 'altered', Check::ERROR ),
			'cached page is altered'   => array( $http( 200, '<html>cached</html>' ), 'altered', Check::ERROR ),
			'401 is http auth warning' => array( $http( 401, '' ), 'http_auth', Check::WARNING ),
			'403 is blocked warning'   => array( $http( 403, '' ), 'blocked', Check::WARNING ),
			'500 is unreachable'       => array( $http( 500, '' ), 'unreachable', Check::ERROR ),
			'network error'            => array( new WP_Error( 'http_request_failed', 'timeout' ), 'unreachable', Check::ERROR ),
		);
	}

	public function test_challenge_is_revoked_after_the_probe(): void {
		$seen = array();
		$env  = new Environment( $this->dirs, array( 'loopback' => static function ( string $challenge ) use ( &$seen ) {
			$seen[] = $challenge;
			return new WP_Error( 'x', 'y' );
		} ) );
		$env->checks();
		$this->assertCount( 1, $seen );
		$this->assertFalse( get_site_transient( 'wpcheckpoint_probe_' . hash( 'sha256', $seen[0] ) ) );
	}

	public function test_probe_results_are_cached_installation_wide_until_refreshed(): void {
		$calls = 0;
		$probe = function ( string $challenge ) use ( &$calls ) {
			++$calls;
			return call_user_func( $this->echo_probe(), $challenge );
		};
		$env = new Environment( $this->dirs, array( 'loopback' => $probe ) );
		$env->checks();
		$env->checks();
		( new Environment( $this->dirs, array( 'loopback' => $probe ) ) )->checks();
		$this->assertSame( 1, $calls );
		$this->assertIsArray( get_site_transient( Environment::CACHE ) );
		$this->assertGreaterThan( 0, $env->checked_at() );

		$env->checks( true );
		$this->assertSame( 2, $calls );

		Environment::invalidate();
		$env->checks();
		$this->assertSame( 3, $calls );
	}

	public function test_database_size_uses_the_installation_prefix(): void {
		global $wpdb;
		$captured = '';
		add_filter( 'query', static function ( string $sql ) use ( &$captured ): string {
			if ( false !== strpos( $sql, 'information_schema.TABLES' ) ) {
				$captured = $sql;
			}
			return $sql;
		} );
		$size = Environment::query_database_size();
		$this->assertIsInt( $size );
		$this->assertGreaterThan( 0, $size );
		$expected_prefix = is_multisite() ? $wpdb->base_prefix : $wpdb->prefix;
		$this->assertStringContainsString( "LIKE '" . addcslashes( $wpdb->esc_like( $expected_prefix ), '\\' ) . "%'", $captured );
		$this->assertStringNotContainsString( "LIKE '" . $expected_prefix . "%'", $captured, 'underscore in the prefix is escaped' );
		$this->assertSame( $expected_prefix, Environment::table_prefix() );
	}

	public function test_real_loopback_request_targets_the_probe_route(): void {
		$seen = array();
		add_filter( 'pre_http_request', static function ( $pre, array $args, string $url ) use ( &$seen ) {
			$seen[] = array( $url, $args );
			return new WP_Error( 'blocked', 'test' );
		}, 10, 3 );
		Environment::request_probe( str_repeat( 'a', 32 ) );
		$this->assertCount( 1, $seen );
		$this->assertStringContainsString( 'wp-checkpoint/v1/probe', $seen[0][0] );
		$this->assertSame( 'POST', $seen[0][1]['method'] );
		$this->assertSame( str_repeat( 'a', 32 ), $seen[0][1]['body']['challenge'] );
		$this->assertSame( 5, $seen[0][1]['timeout'] );
	}

	public function test_report_contains_no_secrets_paths_or_site_url(): void {
		$env    = new Environment( $this->dirs, array( 'loopback' => $this->echo_probe() ) );
		$report = Report::text( $env->checks(), Plugin::instance()->redactor(), $env->report_paths(), array( 'Plugin' => WPCHECKPOINT_VERSION ) );
		$token  = (string) $this->dirs->state()['token'];

		$this->assertStringNotContainsString( DB_PASSWORD, $report );
		$this->assertStringNotContainsString( DB_NAME, $report );
		$this->assertStringNotContainsString( rtrim( ABSPATH, '/' ), $report );
		$this->assertStringNotContainsString( home_url(), $report );
		$this->assertStringNotContainsString( $token, $report );
		$this->assertStringContainsString( '{wp-content}/wp-checkpoint-', $report );
		$this->assertStringContainsString( '[storage]', $report );
		$this->assertStringContainsString( 'Plugin: ' . WPCHECKPOINT_VERSION, $report );
	}

	public function test_actions_are_rate_limited_per_installation(): void {
		$calls = 0;
		add_filter( 'pre_http_request', static function () use ( &$calls ) {
			++$calls;
			return new WP_Error( 'blocked', 'test' );
		} );
		$actions = new EnvironmentActions( $this->dirs );

		$this->assertSame( 'rechecked', $actions->run_recheck() );
		$this->assertSame( 'locked', $actions->run_recheck() );
		$this->assertGreaterThan( 0, EnvironmentActions::seconds_locked( 'recheck' ) );
		$this->assertLessThanOrEqual( 60, EnvironmentActions::seconds_locked( 'recheck' ) );

		$this->assertSame( 'verified', $actions->run_verify() );
		$this->assertSame( 'locked', $actions->run_verify() );
		$this->assertSame( 2, $calls, 'one loopback and one protection probe, the locked repeats made no request' );
		$this->assertNotFalse( get_site_transient( 'wpcheckpoint_lock_verify' ), 'locks are site transients' );
	}

	public function test_action_endpoints_require_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wpcheckpoint_' . EnvironmentActions::NONCE_RECHECK );
		try {
			$this->expectException( \WPDieException::class );
			( new EnvironmentActions( $this->dirs ) )->recheck();
		} finally {
			unset( $_REQUEST['_wpnonce'] );
		}
	}

	public function test_nonce_actions_are_distinct(): void {
		$this->assertNotSame( EnvironmentActions::NONCE_RECHECK, EnvironmentActions::NONCE_VERIFY );
		$this->assertNotSame( wp_create_nonce( 'wpcheckpoint_' . EnvironmentActions::NONCE_RECHECK ), wp_create_nonce( 'wpcheckpoint_' . EnvironmentActions::NONCE_VERIFY ) );
	}
}
