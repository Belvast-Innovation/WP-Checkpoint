<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobActions;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\Loopback;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Environment;
use WPCheckpoint\Tests\Fixtures\Jobs\ClosureStep;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

final class LoopbackTest extends JobTestCase {

	/** @var array<int, array{url: string, token: string, lock_token: string, cursor: array}> */
	private $sent = array();

	/**
	 * Actions with a loopback whose sender records the request together with the job row as it is at that moment.
	 */
	private function actions( $enabled = true ): JobActions {
		$plugin = Plugin::instance();
		$sender = function ( string $url, string $token ): void {
			global $wpdb;
			$row          = $wpdb->get_row( $wpdb->prepare( 'SELECT lock_token, cursor_json FROM ' . \WPCheckpoint\Support\Schema::jobs_table() . ' WHERE id = %d', (int) basename( dirname( $url ) ) ), ARRAY_A );
			$this->sent[] = array(
				'url'        => $url,
				'token'      => $token,
				'lock_token' => (string) $row['lock_token'],
				'cursor'     => (array) json_decode( (string) $row['cursor_json'], true ),
			);
		};
		return new JobActions( $plugin->jobs(), $plugin->runner(), new Loopback( $enabled, $sender ) );
	}

	public function test_the_next_hop_is_sent_after_the_lock_is_released_and_the_cursor_committed(): void {
		$this->register( 'chained', array( new ClosureStep( 'c', static function ( JobContext $ctx ): StepResult {
			$n = isset( $ctx->cursor()['n'] ) ? (int) $ctx->cursor()['n'] : 0;
			return $n >= 2 ? StepResult::done() : StepResult::progress( array( 'n' => $n + 1 ), 50 * ( $n + 1 ) );
		} ) ) );
		$job = Plugin::instance()->jobs()->create( 'chained' );
		// A zero-second budget: every tick does one unit and returns "more", so each tick sends one hop.
		$runner  = new \WPCheckpoint\Jobs\Runner( Plugin::instance()->jobs(), Plugin::instance()->job_types(), Plugin::instance()->redactor(), array( 'budget' => new \WPCheckpoint\Jobs\Budget( 0, 32 * 1048576, false ), 'memory_limit' => -1 ) );
		$actions = $this->actions();
		$this->setRunner( $actions, $runner );

		$result = $actions->tick( $job->id, microtime( true ) );
		$this->assertSame( 'more', $result->status );
		$this->assertCount( 1, $this->sent, 'one hop per tick' );
		$hop = $this->sent[0];
		$this->assertStringEndsWith( '/wp-checkpoint/v1/jobs/' . $job->id . '/loopback', $hop['url'] );
		$this->assertStringNotContainsString( $hop['token'], $hop['url'], 'the token never travels in the URL' );
		$this->assertSame( '', $hop['lock_token'], 'the lock was released before the hop was sent' );
		$this->assertSame( 1, $hop['cursor']['n'], 'the cursor was committed before the hop was sent' );

		// Deliver the hop through the REST route: the token works once, for this job only.
		$request = new \WP_REST_Request( 'POST', '/wp-checkpoint/v1/jobs/' . ( $job->id + 1 ) . '/loopback' );
		$request->set_body_params( array( 'token' => $hop['token'] ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status(), 'bound to the job id' );
		$this->assertSame( 403, rest_get_server()->dispatch( $this->hop_request( $job->id, $hop['token'] ) )->get_status(), 'a token is consumed on its first use, even a failed one' );

		$second = $actions->tick( $job->id, microtime( true ) );
		$this->assertSame( 'more', $second->status );
		$token = $this->sent[1]['token'];
		wp_set_current_user( 0 );
		$response = rest_get_server()->dispatch( $this->hop_request( $job->id, $token ) );
		$this->assertSame( 200, $response->get_status(), 'an anonymous self-request with a valid token ticks' );
		$this->assertSame( 'completed', $response->get_data()['result'] );
		$this->assertArrayNotHasKey( 'job', $response->get_data(), 'the hop response carries no job data' );
		$this->assertSame( 403, rest_get_server()->dispatch( $this->hop_request( $job->id, $token ) )->get_status(), 'used once' );
		$this->assertSame( Job::COMPLETED, Plugin::instance()->jobs()->find( $job->id )->status );
		$this->assertFalse( wp_next_scheduled( Loopback::HOOK, array( $job->id ) ), 'nothing scheduled for a finished job' );
	}

	public function test_no_hop_without_a_reachable_probe_result(): void {
		$this->register( 'plain', array( $this->counting_step( 'p', 5 ) ) );
		$loopback = new Loopback( null, function ( string $url, string $token ): void {
			$this->sent[] = array( 'url' => $url, 'token' => $token, 'lock_token' => '', 'cursor' => array() );
		} );
		$this->assertFalse( $loopback->enabled(), 'no probe cached' );
		$db = array( 'server_info' => '8.0.0', 'size' => 1024 );
		set_site_transient( Environment::CACHE, array( 'checked_at' => time(), 'db' => $db, 'loopback' => array( 'outcome' => 'blocked', 'code' => 403, 'message' => '', 'runtime' => array() ) ), 60 );
		$this->assertFalse( $loopback->enabled(), 'blocked' );
		set_site_transient( Environment::CACHE, array( 'checked_at' => time(), 'db' => $db, 'loopback' => array( 'outcome' => 'reachable', 'code' => 200, 'message' => '', 'runtime' => array( 'memory_bytes' => 134217728, 'max_execution_time' => 30 ) ) ), 60 );
		$this->assertTrue( $loopback->enabled(), 'reachable' );
		$this->assertTrue( Plugin::instance()->loopback()->enabled(), 'the plugin instance reads the same cache' );
	}

	public function test_waits_schedule_one_cron_event_that_cancel_clears(): void {
		$this->register( 'patient', array( new ClosureStep( 'w', static function ( JobContext $ctx ): StepResult {
			return StepResult::wait( 30, $ctx->cursor(), 'remote busy' );
		} ) ) );
		$job     = Plugin::instance()->jobs()->create( 'patient' );
		$actions = $this->actions();
		$before  = time();
		$result  = $actions->tick( $job->id, microtime( true ) );
		$this->assertSame( 'waiting', $result->status );
		$this->assertSame( 30, $result->retry_after );
		$this->assertSame( array(), $this->sent, 'no self-request for a wait' );
		$at = wp_next_scheduled( Loopback::HOOK, array( $job->id ) );
		$this->assertNotFalse( $at );
		$this->assertGreaterThanOrEqual( $before + 30, $at );

		$actions->tick( $job->id, microtime( true ) );
		$this->assertSame( $at, wp_next_scheduled( Loopback::HOOK, array( $job->id ) ), 'not scheduled twice' );
		$this->assertCount( 1, array_filter( _get_cron_array(), static function ( array $hooks ): bool {
			return isset( $hooks[ Loopback::HOOK ] );
		} ) );

		$actions->cancel( $job->id );
		$this->assertFalse( wp_next_scheduled( Loopback::HOOK, array( $job->id ) ), 'cancel clears the event' );
		\WPCheckpoint\Support\Uninstaller::clear_transient_state();
	}

	public function test_a_waiting_job_is_advanced_by_the_next_tick_the_watchdog_sends(): void {
		$calls = 0;
		$this->register( 'once', array( new ClosureStep( 'w', static function ( JobContext $ctx ) use ( &$calls ): StepResult {
			++$calls;
			return 1 === $calls ? StepResult::wait( 30, array(), 'not yet' ) : StepResult::done();
		} ) ) );
		$job = Plugin::instance()->jobs()->create( 'once' );
		$this->assertSame( 'waiting', $this->rest( 'POST', 'jobs/' . $job->id . '/tick' )->get_data()['result'] );
		$this->assertSame( 'completed', $this->rest( 'POST', 'jobs/' . $job->id . '/tick' )->get_data()['result'], 'the browser watchdog POSTs a tick and the job continues' );
	}

	public function test_cron_callback_ticks_and_counts_the_budget_from_now_under_wp_cli(): void {
		$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true ) - 3600;
		$this->assertEqualsWithDelta( microtime( true ), JobActions::started_at( true ), 1.0, 'WP-CLI: from now' );
		$this->assertEqualsWithDelta( $_SERVER['REQUEST_TIME_FLOAT'], JobActions::started_at( false ), 0.001, 'web: from the request start' );
		unset( $_SERVER['REQUEST_TIME_FLOAT'] );
		$this->assertEqualsWithDelta( microtime( true ), JobActions::started_at( false ), 1.0, 'no REQUEST_TIME_FLOAT: from now' );

		$this->register( 'plain', array( $this->counting_step( 'p', 2 ) ) );
		$job = Plugin::instance()->jobs()->create( 'plain' );
		// The plugin's own runner assumes 64 MB when no probe is cached; inside phpunit that leaves a tiny memory budget, so allow several ticks.
		for ( $i = 0; $i < 5 && Job::COMPLETED !== Plugin::instance()->jobs()->find( $job->id )->status; $i++ ) {
			Plugin::instance()->cron_tick( $job->id );
		}
		$this->assertSame( Job::COMPLETED, Plugin::instance()->jobs()->find( $job->id )->status, 'the cron callback ticks outside the admin' );
		$this->assertNotFalse( has_action( Loopback::HOOK, array( Plugin::instance(), 'cron_tick' ) ), 'registered on boot, not only in the admin' );
	}

	private function hop_request( int $id, string $token ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/wp-checkpoint/v1/jobs/' . $id . '/loopback' );
		$request->set_body_params( array( 'token' => $token ) );
		return $request;
	}

	/**
	 * Swap the runner inside an actions instance (private property) so a test can use a one-unit budget.
	 */
	private function setRunner( JobActions $actions, \WPCheckpoint\Jobs\Runner $runner ): void {
		$property = new \ReflectionProperty( JobActions::class, 'runner' );
		$property->setAccessible( true );
		$property->setValue( $actions, $runner );
	}
}
