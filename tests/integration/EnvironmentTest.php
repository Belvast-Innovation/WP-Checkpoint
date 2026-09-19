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
use WPCheckpoint\Support\Schema;

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
		// The plugin singleton caches a redactor seeded with the storage token; this test made a new one.
		Plugin::instance()->reset_directories();
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
		if ( 'redirected' === $outcome ) {
			$this->assertStringContainsString( 'WordPress Address and Site Address', $check->message );
			$expected_target = false !== strpos( $check->value, '301' ) ? 'https://www.example.org/wp-json/wp-checkpoint/v1/probe' : '(no Location header)';
			$this->assertStringContainsString( $expected_target, $check->message );
		}
	}

	public function loopback_outcomes(): array {
		$http = static function ( int $code, string $body, array $headers = array() ): array {
			return array( 'response' => array( 'code' => $code, 'message' => '' ), 'body' => $body, 'headers' => $headers, 'cookies' => array(), 'filename' => null );
		};
		return array(
			'echo is reachable'        => array( $this->echo_probe(), 'reachable', Check::OK ),
			'301 is redirected'        => array( $http( 301, '', array( 'location' => 'https://www.example.org/wp-json/wp-checkpoint/v1/probe' ) ), 'redirected', Check::WARNING ),
			'302 is redirected'        => array( $http( 302, '' ), 'redirected', Check::WARNING ),
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

	public function test_a_missing_job_table_is_reported_without_a_failed_query(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::jobs_table() );
		$wpdb->last_error = '';
		$env   = new Environment( $this->dirs, array( 'loopback' => $this->echo_probe() ) );
		$check = $this->find( $env->checks( true ), 'database.jobs' );
		$this->assertSame( Check::ERROR, $check->status );
		$this->assertSame( 'missing', $check->value );
		$this->assertSame( '', $wpdb->last_error, 'nothing queried the absent table; expected errors would hide real ones in the test log' );
		$repo = new \WPCheckpoint\Jobs\JobRepository( $this->dirs );
		$this->assertSame( array_fill_keys( \WPCheckpoint\Jobs\Job::statuses(), 0 ), $repo->counts() );
		$this->assertNull( $repo->find( 1 ) );
		$this->assertSame( array(), $repo->list_jobs() );
		$this->assertSame( 0, $repo->settle_storage(), 'storage settlement before the table exists is a no-op, not an error' );
		$this->assertSame( '', $wpdb->last_error );
	}

	public function test_thirty_two_bit_php_is_a_warning_that_names_the_size_in_both_directions(): void {
		$env   = new Environment( $this->dirs, array( 'loopback' => $this->echo_probe(), 'int_size' => static function (): int {
			return 4;
		} ) );
		$check = $this->find( $env->checks(), 'limits.int_size' );
		$this->assertSame( Check::WARNING, $check->status, 'the plugin still works; the operations that hit the bound refuse individually' );
		$this->assertSame( '32-bit', $check->value );
		$this->assertStringContainsString( 'files larger than 2 GB cannot be backed up', $check->message );
		$this->assertStringContainsString( 'archives larger than 2 GB cannot be restored here', $check->message );
		$this->assertStringContainsString( '64-bit PHP', $check->message );
		$this->assertStringNotContainsString( 'PHP_INT_SIZE', $check->message );

		$env   = new Environment( $this->dirs, array( 'loopback' => $this->echo_probe(), 'int_size' => static function (): int {
			return 8;
		} ) );
		$check = $this->find( $env->checks(), 'limits.int_size' );
		$this->assertSame( Check::OK, $check->status );
		$this->assertSame( '64-bit', $check->value );
		$this->assertSame( '', $check->message );
		// The real platform of the test runner is 64-bit.
		Environment::invalidate();
		$this->assertSame( Check::OK, $this->find( ( new Environment( $this->dirs, array( 'loopback' => $this->echo_probe() ) ) )->checks(), 'limits.int_size' )->status );
	}

	public function test_report_contains_no_secrets_paths_or_site_url(): void {
		$env    = new Environment( $this->dirs, array( 'loopback' => $this->echo_probe() ) );
		$report = Report::text( $env->checks(), Plugin::instance()->redactor(), $env->report_paths(), array( 'Plugin' => WPCHECKPOINT_VERSION ), Environment::report_hosts() );
		$token  = (string) $this->dirs->state()['token'];

		$this->assertSame( array( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ), Environment::report_hosts(), 'hosts never carry a port' );
		$this->assertStringNotContainsStringIgnoringCase( (string) wp_parse_url( home_url(), PHP_URL_HOST ), $report );
		$this->assertStringNotContainsString( DB_PASSWORD, $report );
		$this->assertStringNotContainsString( DB_NAME, $report );
		$this->assertStringNotContainsString( rtrim( ABSPATH, '/' ), $report );
		$this->assertStringNotContainsString( home_url(), $report );
		$this->assertStringNotContainsString( $token, $report );
		$this->assertStringContainsString( '{wp-content}/wp-checkpoint-', $report );
		$this->assertStringContainsString( '[storage]', $report );
		$this->assertStringContainsString( 'Plugin: ' . WPCHECKPOINT_VERSION, $report );
	}

	public function test_coarse_decision(): void {
		$this->assertFalse( Environment::use_coarse_site_paths( false, false, 10000 ), 'single site' );
		$this->assertFalse( Environment::use_coarse_site_paths( true, true, 10000 ), 'sub-domain network' );
		$this->assertFalse( Environment::use_coarse_site_paths( true, false, Environment::SITE_PATH_LIMIT ), 'at the limit' );
		$this->assertTrue( Environment::use_coarse_site_paths( true, false, Environment::SITE_PATH_LIMIT + 1 ) );
		$this->assertSame( 50, Environment::SITE_PATH_LIMIT );
	}

	public function test_single_site_report_site_paths_shape(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single site only.' );
		}
		$result = Environment::report_site_paths();
		$this->assertSame( array( 'paths' => array(), 'coarse' => false, 'network_root' => '' ), $result );
	}

	public function test_multisite_site_paths_are_collected_in_batches_or_coarse_above_the_limit(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$domain = get_network()->domain;
		foreach ( array( 'alpha', 'beta', 'gamma', 'delta', 'epsilon' ) as $name ) {
			self::factory()->blog->create( array( 'domain' => $domain, 'path' => '/' . $name . '/' ) );
		}

		$result = Environment::report_site_paths( 50, 2 );
		$this->assertFalse( $result['coarse'] );
		foreach ( array( '/alpha', '/beta', '/gamma', '/delta', '/epsilon' ) as $path ) {
			$this->assertContains( $path, $result['paths'], 'collected across pages of 2' );
		}
		$this->assertSame( '', $result['network_root'], 'network at /' );

		$coarse = Environment::report_site_paths( 50, 500, 51 );
		$this->assertTrue( $coarse['coarse'] );
		$this->assertNotContains( '/alpha', $coarse['paths'], 'no enumeration above the limit' );

		$checks = array( new Check( 'x', 'loopback', 'Loopback', 'redirected', Check::WARNING, 'to https://' . $domain . '/zeta/wp-json/ and https://' . $domain . '/wp-json/' ) );
		$report = Report::text( $checks, Plugin::instance()->redactor(), array(), array(), Environment::report_hosts(), $coarse['paths'], array( 'coarse_site_paths' => true, 'network_root' => $coarse['network_root'] ) );
		$masked = '{site-host}' . ( false !== strpos( $domain, ':' ) ? substr( $domain, strpos( $domain, ':' ) ) : '' );
		$this->assertStringNotContainsString( 'zeta', $report, 'even a site that was never enumerated is masked' );
		$this->assertStringContainsString( 'https://' . $masked . '/{site-path}/wp-json/ and https://' . $masked . '/wp-json/', $report );
		$this->assertStringContainsString( 'Site paths: coarse (large network)', $report );
	}

	public function test_single_site_in_a_subdirectory_masks_its_path(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single site only.' );
		}
		// Priority 20 beats _config_wp_home() when the test config defines WP_HOME / WP_SITEURL.
		add_filter( 'pre_option_home', static function () {
			return 'http://example.org/shop';
		}, 20 );
		add_filter( 'pre_option_siteurl', static function () {
			return 'http://example.org/shop/wp';
		}, 20 );
		$this->assertSame( 'http://example.org/shop', home_url() );
		$this->assertSame( array( '/shop', '/shop/wp' ), Environment::report_site_paths()['paths'] );

		$checks = array( new Check( 'x', 'loopback', 'Loopback', 'redirected', Check::WARNING, 'redirected to https://example.org/shop/wp/wp-json/ and https://example.org/shopping/' ) );
		$report = Report::text( $checks, Plugin::instance()->redactor(), array(), array(), Environment::report_hosts(), Environment::report_site_paths()['paths'] );
		$this->assertStringContainsString( 'https://{site-host}/{site-path}/wp-json/ and https://{site-host}/shopping/', $report );
		$this->assertStringNotContainsString( '/shop/', $report );
	}

	public function test_multisite_subdirectory_site_paths_never_appear_in_the_report(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$network_domain = get_network()->domain;
		$masked_host    = '{site-host}' . ( false !== strpos( $network_domain, ':' ) ? substr( $network_domain, strpos( $network_domain, ':' ) ) : '' );
		$clienta        = self::factory()->blog->create( array( 'domain' => $network_domain, 'path' => '/clienta/' ) );
		self::factory()->blog->create( array( 'domain' => $network_domain, 'path' => '/client/' ) );

		switch_to_blog( $clienta );
		try {
			$paths = Environment::report_site_paths()['paths'];
			$this->assertContains( '/clienta', $paths, 'current site path' );
			$this->assertContains( '/client', $paths, 'every site of the network' );

			$checks = array( new Check( 'x', 'loopback', 'Loopback', 'redirected', Check::WARNING, 'redirected to https://' . $network_domain . '/clienta/wp-json/ and https://' . $network_domain . '/client/x and https://' . $network_domain . '/clientab/y' ) );
			$report = Report::text( $checks, Plugin::instance()->redactor(), array(), array(), Environment::report_hosts(), $paths );
		} finally {
			restore_current_blog();
		}

		$this->assertStringNotContainsString( 'clienta/', $report );
		$this->assertStringContainsString( 'https://' . $masked_host . '/{site-path}/wp-json/ and https://' . $masked_host . '/{site-path}/x and https://' . $masked_host . '/clientab/y', $report );
	}

	public function test_multisite_subsite_hosts_never_appear_in_the_report(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$network_domain = get_network()->domain;
		$network_host   = (string) preg_replace( '/:\d+$/', '', $network_domain );
		$masked_host    = '{site-host}' . substr( $network_domain, strlen( $network_host ) );
		$this->assertContains( $network_host, Environment::report_hosts() );

		$subsite_host = 'clientb.' . $network_domain;
		self::factory()->blog->create( array( 'domain' => $subsite_host, 'path' => '/' ) );
		$checks = array( new \WPCheckpoint\Support\Check( 'x', 'loopback', 'Loopback', 'redirected', \WPCheckpoint\Support\Check::WARNING, 'redirected to https://' . $subsite_host . '/wp-json/ and to http://' . $network_domain . '/' ) );
		$report = Report::text( $checks, Plugin::instance()->redactor(), array(), array(), Environment::report_hosts() );

		$this->assertStringNotContainsString( 'clientb', $report );
		$this->assertStringNotContainsStringIgnoringCase( $network_host, $report );
		$this->assertStringContainsString( 'https://{subdomain}.' . $masked_host . '/wp-json/ and to http://' . $masked_host . '/', $report );
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
