<?php

namespace WPCheckpoint\Tests\Integration;

use WP_REST_Request;
use WP_UnitTestCase;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\RestoreJob;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\SelfCheck;
use WPCheckpoint\Rest\Controller;
use WPCheckpoint\Rest\SelfCheckController;
use WPCheckpoint\Support\StoredNames;
use WPCheckpoint\Tests\Fixtures\StorageDirs;

/**
 * The self-check route (Rest\SelfCheckController): registered only for a request to it while a restore that holds
 * the site swapped has written the self-check row, and only answering a request that carries the pass key.
 */
final class SelfCheckRouteTest extends WP_UnitTestCase {

	/** @var int */
	private static $subscriber;

	/** @var int */
	private static $admin;

	/** @var string The pass key of the row written (key()). */
	private $key = '';

	/** @var string The one-time value of the row written. */
	private $nonce = '';

	/** @var mixed What the request's REST route was before the test. */
	private $route_before = null;

	/** @var string[]|null The storage directories in wp-content when the test started (StorageDirs); null before set_up(). */
	private $storage_before = null;

	public static function wpSetUpBeforeClass( $factory ): void {
		self::$subscriber = $factory->user->create( array( 'role' => 'subscriber' ) );
		self::$admin      = $factory->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( self::$admin );
		}
	}

	public function set_up(): void {
		parent::set_up();
		$this->route_before = $GLOBALS['wp']->query_vars['rest_route'] ?? null;
		// The jobs this test makes resolve the storage directory: one it makes (its stored state rolled back with the
		// test's transaction) goes with it, whichever test ran before.
		$this->storage_before = StorageDirs::listing();
		Plugin::instance()->reset_directories();
	}

	public function tear_down(): void {
		if ( null === $this->route_before ) {
			unset( $GLOBALS['wp']->query_vars['rest_route'] );
		} else {
			$GLOBALS['wp']->query_vars['rest_route'] = $this->route_before;
		}
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
		Plugin::instance()->reset_directories();
		if ( null !== $this->storage_before ) {
			StorageDirs::remove_made_since( $this->storage_before );
		}
	}

	/**
	 * A restore job with these columns, and the self-check row naming it (with a new key and one-time value).
	 *
	 * @param array<string, mixed> $columns Job columns.
	 * @param string               $type    Job type.
	 * @return Job
	 */
	private function checking( array $columns = array(), string $type = RestoreJob::ID ): Job {
		global $wpdb;
		$job = Plugin::instance()->jobs()->create( $type, self::$admin );
		$wpdb->update(
			JobRepository::table(),
			$columns + array(
				'status'     => Job::RUNNING,
				'site_state' => Job::SITE_SWAPPED,
			),
			array( 'id' => $job->id )
		);
		$this->key   = SelfCheck::random();
		$this->nonce = SelfCheck::random();
		$this->row( SelfCheck::value( $job->id, $this->nonce, hash( 'sha256', $this->key ) ) );
		return Plugin::instance()->jobs()->find( $job->id );
	}

	/**
	 * Write the self-check row's value as it is (or delete the row: null).
	 *
	 * @param string|null $value Value.
	 * @return void
	 */
	private function row( $value ): void {
		global $wpdb;
		$table = SelfCheck::table( $wpdb->base_prefix );
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE option_name = %s", StoredNames::SELF_CHECK ) );
		if ( null !== $value ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO `{$table}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')", StoredNames::SELF_CHECK, $value ) );
		}
	}

	/**
	 * The plugin's routes, as a request for this REST route registers them.
	 *
	 * @param string $route The request's REST route.
	 * @return string[]
	 */
	private function routes_for( string $route ): array {
		$GLOBALS['wp']->query_vars['rest_route'] = $route;
		$GLOBALS['wp_rest_server']               = null;
		return array_keys( rest_get_server()->get_routes( Controller::ROUTE_NAMESPACE ) );
	}

	/**
	 * A request to the route.
	 *
	 * @param string|null $key       The pass header (null: none).
	 * @param string      $challenge The challenge.
	 * @return \WP_REST_Response
	 */
	private function ask( $key, string $challenge ): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', SelfCheck::PATH );
		$request->set_query_params( array( 'challenge' => $challenge ) );
		if ( null !== $key ) {
			$request->set_header( SelfCheck::HEADER, $key );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_the_route_is_registered_only_for_a_request_to_it_while_a_restore_that_holds_the_site_swapped_checks_it(): void {
		$job = $this->checking();
		$this->assertContains( SelfCheck::PATH, $this->routes_for( SelfCheck::PATH ), 'the control: a request for it while the restore checks the site' );
		global $wpdb;
		$wpdb->update( JobRepository::table(), array( 'status' => Job::QUEUED ), array( 'id' => $job->id ) );
		$this->assertContains( SelfCheck::PATH, $this->routes_for( SelfCheck::PATH ), 'queued between two ticks of the check' );
		$wpdb->update( JobRepository::table(), array( 'status' => Job::RUNNING ), array( 'id' => $job->id ) );
		$this->assertContains( SelfCheck::PATH, $this->routes_for( SelfCheck::PATH . '/' ), 'with a trailing slash too' );
		$this->assertNotContains( SelfCheck::PATH, $this->routes_for( '/' ), 'a request for the index' );
		$index = rest_get_server()->get_index( new WP_REST_Request( 'GET', '/' ) )->get_data();
		$this->assertArrayHasKey( '/wp-checkpoint/v1/status', $index['routes'], 'the control: the index lists the plugin\'s routes' );
		$this->assertArrayNotHasKey( SelfCheck::PATH, $index['routes'], 'and not this one' );
		$this->assertNotContains( SelfCheck::PATH, $this->routes_for( '/wp-checkpoint/v1/status' ), 'a request for another route' );
		$this->assertNotContains( SelfCheck::PATH, $this->routes_for( '' ), 'not a REST request' );
		foreach ( array(
			'the job completed'          => array( 'status' => Job::COMPLETED ),
			'the job failed'             => array( 'status' => Job::FAILED ),
			'the job paused'             => array( 'status' => Job::PAUSED ),
			'the job cancelled'          => array( 'status' => Job::CANCELLED ),
			'the swap is not made'       => array( 'site_state' => Job::SITE_CHANGING ),
			'the site is not changed'    => array( 'site_state' => Job::SITE_UNTOUCHED ),
		) as $case => $columns ) {
			$wpdb->update( JobRepository::table(), $columns, array( 'id' => $job->id ) );
			$this->assertNotContains( SelfCheck::PATH, $this->routes_for( SelfCheck::PATH ), $case );
			$wpdb->update(
				JobRepository::table(),
				array(
					'status'     => Job::RUNNING,
					'site_state' => Job::SITE_SWAPPED,
				),
				array( 'id' => $job->id )
			);
			$this->assertContains( SelfCheck::PATH, $this->routes_for( SelfCheck::PATH ), $case . ': the control, put back' );
		}
		$value = SelfCheck::value( $job->id, $this->nonce, hash( 'sha256', $this->key ) );
		foreach ( array(
			'no row'                  => null,
			'a row of no job'         => SelfCheck::value( $job->id + 1000, $this->nonce, hash( 'sha256', $this->key ) ),
			'a row not of its form'   => $value . 'x',
		) as $case => $row ) {
			$this->row( $row );
			$this->assertNotContains( SelfCheck::PATH, $this->routes_for( SelfCheck::PATH ), $case );
		}
		$this->row( $value );
		$this->assertContains( SelfCheck::PATH, $this->routes_for( SelfCheck::PATH ), 'the control: the row put back' );
		$this->checking( array(), 'export' );
		$this->assertNotContains( SelfCheck::PATH, $this->routes_for( SelfCheck::PATH ), 'the row names a job that is not a restore' );
		if ( is_multisite() ) {
			$this->checking();
			$this->assertContains( SelfCheck::PATH, $this->routes_for( SelfCheck::PATH ), 'the control: on the main site' );
			$sub = self::factory()->blog->create();
			switch_to_blog( $sub );
			try {
				$this->assertNotContains( SelfCheck::PATH, $this->routes_for( SelfCheck::PATH ), 'a request to another site of the network' );
			} finally {
				restore_current_blog();
			}
		}
	}

	public function test_the_route_is_registered_while_the_site_put_back_by_an_undo_is_checked_and_not_otherwise_while_it_changes(): void {
		global $wpdb;
		$undo = array(
			'phase'  => 'restored',
			'mark'   => 'm',
			'reason' => SwapStep::ASKED,
			'check'  => '',
			'out'    => true,
		);
		$job  = $this->checking(
			array(
				'site_state'  => Job::SITE_CHANGING,
				'cursor_json' => wp_json_encode( $undo ),
			)
		);
		$this->assertContains( SelfCheck::PATH, $this->routes_for( SelfCheck::PATH ), 'the site put back by an undo, checked behind the held file' );
		$answered = array( 'reason' => SwapStep::UNDONE ) + $undo;
		foreach ( array(
			'an undo answered after the release' => array( $answered, true ),
			'not let out (a reverse after a failed check)' => array( array( 'out' => false ) + $undo, false ),
			'a reverse after a failed check'     => array( array( 'reason' => SwapStep::REVERSED ) + $undo, false ),
			'a rollback'                         => array( array( 'reason' => SwapStep::INTERRUPTED ) + $undo, false ),
			'its verdict recorded'               => array( $undo + array( 'after_undo' => 'passed' ), false ),
			'still being put back'               => array( array( 'phase' => 'reverse' ) + $undo, false ),
			'recorded as untouched'              => array( array( 'phase' => 'reverted' ) + $undo, false ),
		) as $case => $how ) {
			$wpdb->update( JobRepository::table(), array( 'cursor_json' => wp_json_encode( $how[0] ) ), array( 'id' => $job->id ) );
			if ( $how[1] ) {
				$this->assertContains( SelfCheck::PATH, $this->routes_for( SelfCheck::PATH ), $case );
			} else {
				$this->assertNotContains( SelfCheck::PATH, $this->routes_for( SelfCheck::PATH ), $case );
			}
		}
		$wpdb->update( JobRepository::table(), array( 'cursor_json' => wp_json_encode( $undo ) ), array( 'id' => $job->id ) );
		$this->assertContains( SelfCheck::PATH, $this->routes_for( SelfCheck::PATH ), 'the control, put back' );
		foreach ( array(
			'the site recorded as untouched' => array( 'site_state' => Job::SITE_UNTOUCHED ),
			'the job paused'                 => array( 'status' => Job::PAUSED ),
		) as $case => $columns ) {
			$wpdb->update( JobRepository::table(), $columns, array( 'id' => $job->id ) );
			$this->assertNotContains( SelfCheck::PATH, $this->routes_for( SelfCheck::PATH ), $case );
			$wpdb->update(
				JobRepository::table(),
				array(
					'status'     => Job::RUNNING,
					'site_state' => Job::SITE_CHANGING,
				),
				array( 'id' => $job->id )
			);
			$this->assertContains( SelfCheck::PATH, $this->routes_for( SelfCheck::PATH ), $case . ': the control, put back' );
		}
	}

	public function test_without_its_pass_key_everyone_gets_403_and_with_it_the_challenge_is_answered(): void {
		$this->checking();
		$this->routes_for( SelfCheck::PATH );
		$challenge = SelfCheck::random();
		foreach ( array(
			'anonymous'     => array( 0, null ),
			'a subscriber'  => array( self::$subscriber, null ),
			'an administrator without the key' => array( self::$admin, null ),
			'another key'   => array( 0, SelfCheck::random() ),
			'the key in capitals' => array( 0, strtoupper( $this->key ) ),
			'the hash, not the key' => array( 0, hash( 'sha256', $this->key ) ),
			'the key cut short' => array( 0, substr( $this->key, 0, 63 ) ),
			'the key and more' => array( 0, $this->key . '0' ),
			'empty'         => array( 0, '' ),
		) as $case => list( $user, $key ) ) {
			wp_set_current_user( $user );
			$response = $this->ask( $key, $challenge );
			$this->assertSame( 403, $response->get_status(), $case );
			$this->assertSame( 'rest_forbidden', $response->get_data()['code'] ?? '', $case );
			$this->assertArrayNotHasKey( 'answer', (array) $response->get_data(), $case );
		}
		wp_set_current_user( 0 );
		$response = $this->ask( $this->key, $challenge );
		$this->assertSame( 200, $response->get_status(), 'the control: with the key, anonymous' );
		$this->assertSame(
			array(
				'challenge' => $challenge,
				'answer'    => hash_hmac( 'sha256', $challenge, $this->nonce ),
			),
			$response->get_data()
		);
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] ?? '' );
		foreach ( array( 'short' => substr( $challenge, 1 ), 'capitals' => strtoupper( $challenge ), 'none' => '' ) as $case => $bad ) {
			$this->assertSame( 400, $this->ask( $this->key, $bad )->get_status(), 'a challenge not of its form: ' . $case );
		}
	}

	public function test_a_row_written_anew_after_the_permission_check_is_not_answered_from_with_the_old_key(): void {
		$job        = $this->checking();
		$controller = new SelfCheckController( Plugin::instance()->jobs() );
		$request    = new WP_REST_Request( 'GET', SelfCheck::PATH );
		$request->set_query_params( array( 'challenge' => SelfCheck::random() ) );
		$request->set_header( SelfCheck::HEADER, $this->key );
		$this->assertTrue( $controller->check_pass( $request ) );
		$this->assertInstanceOf( \WP_REST_Response::class, $controller->answer( $request ), 'the control: answered while the row is the same' );
		// A new tick of the check writes a new key's hash and a new one-time value.
		$this->row( SelfCheck::value( $job->id, SelfCheck::random(), hash( 'sha256', SelfCheck::random() ) ) );
		$answer = $controller->answer( $request );
		$this->assertInstanceOf( \WP_Error::class, $answer );
		$this->assertSame( 403, $answer->get_error_data()['status'] );
	}
}
