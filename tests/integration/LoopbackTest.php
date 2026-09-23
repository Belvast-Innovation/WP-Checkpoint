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
		$again = wp_next_scheduled( Loopback::HOOK, array( $job->id ) );
		$this->assertGreaterThanOrEqual( $at, $again, 'a wait sets its own time again' );
		$this->assertLessThanOrEqual( time() + 30, $again );
		$this->assertCount( 1, $this->events( $job->id ), 'not scheduled twice' );
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

	/**
	 * The three web entry points (REST tick, loopback hop, cron callback) keep running when the client goes
	 * away and send the client nothing: PHP notices a gone client only when it writes, so a tick that never
	 * writes cannot be killed that way, and a stray notice is captured instead of written.
	 */
	public function test_web_ticks_ignore_a_gone_client_and_send_it_nothing(): void {
		$noisy = false;
		$this->register(
			'quiet',
			array(
				new ClosureStep(
					'q',
					static function ( JobContext $ctx ) use ( &$noisy ): StepResult {
						if ( $noisy ) {
							echo 'Notice: something printed during a tick'; // As display_errors would.
						}
						$n = isset( $ctx->cursor()['n'] ) ? (int) $ctx->cursor()['n'] : 0;
						return $n >= 5 ? StepResult::done() : StepResult::progress( array( 'n' => $n + 1 ), 10 );
					}
				),
			)
		);
		$this->expectOutputString( '', 'nothing a tick printed reached the client' );
		$check = function ( string $entry ): void {
			$this->assertSame( 1, ignore_user_abort(), "{$entry}: the request keeps running without its client" );
			$this->assertSame( 0, JobActions::last_output_bytes(), "{$entry}: the tick printed nothing" );
		};
		ignore_user_abort( false );
		$job = Plugin::instance()->jobs()->create( 'quiet' );
		$this->rest( 'POST', 'jobs/' . $job->id . '/tick' );
		$check( 'REST tick' );
		ignore_user_abort( false );
		$token = Loopback::issue_token( $job->id );
		wp_set_current_user( 0 );
		$this->assertSame( 200, rest_get_server()->dispatch( $this->hop_request( $job->id, $token ) )->get_status() );
		$check( 'loopback hop' );
		wp_set_current_user( self::$admin_id );
		ignore_user_abort( false );
		Plugin::instance()->cron_tick( $job->id );
		$check( 'cron callback' );
		// A tick that prints: captured and discarded, its size in the storage log.
		$noisy = true;
		$loud  = Plugin::instance()->jobs()->create( 'quiet' );
		$this->rest( 'POST', 'jobs/' . $loud->id . '/tick' );
		$this->assertGreaterThan( 0, JobActions::last_output_bytes() );
		$log = (string) file_get_contents( Plugin::instance()->directories()->base() . '/logs/storage.log' );
		$this->assertStringContainsString( 'nothing was sent to the client', $log );
	}

	private function hop_request( int $id, string $token ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/wp-checkpoint/v1/jobs/' . $id . '/loopback' );
		$request->set_body_params( array( 'token' => $token ) );
		return $request;
	}

	/**
	 * Times of the cron events for a job.
	 *
	 * @return int[]
	 */
	private function events( int $id ): array {
		$times = array();
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			foreach ( (array) ( $hooks[ Loopback::HOOK ] ?? array() ) as $event ) {
				if ( array( $id ) === $event['args'] ) {
					$times[] = (int) $timestamp;
				}
			}
		}
		return $times;
	}

	/**
	 * Actions whose runner has a zero-second budget: every tick does one unit and returns "more".
	 */
	private function one_unit_actions( bool $enabled ): JobActions {
		$runner  = new \WPCheckpoint\Jobs\Runner( Plugin::instance()->jobs(), Plugin::instance()->job_types(), Plugin::instance()->redactor(), array( 'budget' => new \WPCheckpoint\Jobs\Budget( 0, 32 * 1048576, false ), 'memory_limit' => -1 ) );
		$actions = $this->actions( $enabled );
		$this->setRunner( $actions, $runner );
		return $actions;
	}

	public function test_a_tick_that_leaves_work_schedules_one_fallback_event_with_or_without_a_hop(): void {
		$this->register( 'long', array( $this->counting_step( 'l', 50 ) ) );
		foreach ( array( false, true ) as $enabled ) {
			$job     = Plugin::instance()->jobs()->create( 'long' );
			$actions = $this->one_unit_actions( $enabled );
			$sent    = count( $this->sent );
			$before  = time();
			for ( $i = 0; $i < 5; $i++ ) {
				$this->assertSame( 'more', $actions->tick( $job->id, microtime( true ) )->status );
				$events = $this->events( $job->id );
				$this->assertCount( 1, $events, 'one event per job, however many ticks: ' . ( $enabled ? 'with' : 'without' ) . ' a chain' );
				$this->assertGreaterThanOrEqual( $before + Loopback::FALLBACK_SECONDS, $events[0] );
				$this->assertLessThanOrEqual( time() + Loopback::FALLBACK_SECONDS, $events[0] );
			}
			$this->assertSame( $enabled ? 5 : 0, count( $this->sent ) - $sent, 'the hop is sent only when the chain is enabled; the event either way' );
		}
	}

	public function test_while_a_tick_runs_a_next_event_already_exists(): void {
		// A tick the server kills never reaches its follow-up; if cron started it, that event is used up.
		$seen = array();
		$this->register( 'watched', array( new ClosureStep( 'w', static function ( JobContext $ctx ) use ( &$seen ): StepResult {
			$seen[] = wp_next_scheduled( Loopback::HOOK, array( $ctx->job()->id ) );
			return StepResult::done();
		} ) ) );
		$job = Plugin::instance()->jobs()->create( 'watched' );
		$this->assertSame( array(), $this->events( $job->id ) );
		Plugin::instance()->cron_tick( $job->id ); // What WP-Cron runs, after removing the event it came from.
		$this->assertCount( 1, $seen, 'the step ran' );
		$this->assertNotFalse( $seen[0], 'during the tick, the job had an event to come back to' );
		$this->assertSame( Job::COMPLETED, Plugin::instance()->jobs()->find( $job->id )->status );
		$this->assertSame( array(), $this->events( $job->id ), 'and it is gone once the job is finished' );
	}

	public function test_a_busy_job_is_retried_one_interval_later_not_every_few_seconds(): void {
		$this->register( 'held', array( $this->counting_step( 'h', 5 ) ) );
		$job = Plugin::instance()->jobs()->create( 'held' );
		$this->assertNotFalse( Plugin::instance()->jobs()->acquire( $job->id ), 'another driver holds the lease' );
		$before = time();
		$result = $this->actions( false )->tick( $job->id, microtime( true ) );
		$this->assertSame( 'busy', $result->status );
		$events = $this->events( $job->id );
		$this->assertCount( 1, $events );
		$this->assertGreaterThanOrEqual( $before + Loopback::FALLBACK_SECONDS, $events[0] );
	}

	public function test_an_earlier_time_replaces_a_later_one_and_a_wait_sets_its_own(): void {
		Loopback::schedule( 901, 300 );
		Loopback::schedule( 901, Loopback::FALLBACK_SECONDS );
		$this->assertCount( 1, $this->events( 901 ) );
		$this->assertLessThanOrEqual( time() + Loopback::FALLBACK_SECONDS, $this->events( 901 )[0], 'the earlier time wins' );
		Loopback::schedule( 901, 300 );
		$this->assertLessThanOrEqual( time() + Loopback::FALLBACK_SECONDS, $this->events( 901 )[0], 'a later time does not push it back' );
		Loopback::schedule( 901, 300, Loopback::REPLACE );
		$this->assertCount( 1, $this->events( 901 ) );
		$this->assertGreaterThanOrEqual( time() + 299, $this->events( 901 )[0], 'a tick\'s own time replaces it' );
		Loopback::schedule( 901, Loopback::FALLBACK_SECONDS, Loopback::KEEP );
		$this->assertGreaterThanOrEqual( time() + 299, $this->events( 901 )[0], 'keep only makes sure one exists' );
		Loopback::unschedule( 901 );
		Loopback::schedule( 901, Loopback::FALLBACK_SECONDS, Loopback::KEEP );
		$this->assertCount( 1, $this->events( 901 ), 'and creates one when there is none' );
		Loopback::unschedule( 901 );
	}

	public function test_a_busy_tick_does_not_cut_short_a_wait_but_work_to_do_does(): void {
		$this->register( 'held', array( $this->counting_step( 'h', 50 ) ) );
		$job = Plugin::instance()->jobs()->create( 'held' );
		Loopback::schedule( $job->id, 300, Loopback::REPLACE ); // The holder has just set a wait.
		$this->assertNotFalse( Plugin::instance()->jobs()->acquire( $job->id ) );
		$this->assertSame( 'busy', $this->actions( false )->tick( $job->id, microtime( true ) )->status );
		$this->assertGreaterThanOrEqual( time() + 299, $this->events( $job->id )[0], 'neither the pre-tick event nor the busy follow-up moved the wait' );

		$other = Plugin::instance()->jobs()->create( 'held' );
		Loopback::schedule( $other->id, 300, Loopback::REPLACE );
		$this->assertSame( 'more', $this->one_unit_actions( false )->tick( $other->id, microtime( true ) )->status );
		$this->assertLessThanOrEqual( time() + Loopback::FALLBACK_SECONDS, $this->events( $other->id )[0], 'a tick that left work to do brings the event forward' );
	}

	public function test_a_driver_that_lost_the_job_to_a_takeover_leaves_the_new_holders_event_and_token(): void {
		$taken = function ( JobContext $ctx ): StepResult {
			global $wpdb;
			// Another driver took the job over after this tick's lease ran out: its lock, its event, its hop token.
			$wpdb->update( \WPCheckpoint\Support\Schema::jobs_table(), array( 'lock_token' => str_repeat( 'b', 32 ), 'locked_until' => time() + 120 ), array( 'id' => $ctx->job()->id ) );
			Loopback::schedule( $ctx->job()->id, Loopback::FALLBACK_SECONDS );
			Loopback::issue_token( $ctx->job()->id );
			$ctx->checkpoint( array( 'n' => 1 ), 10 ); // The fenced write fails: this driver has lost the job.
			return StepResult::done();
		};
		$this->register( 'taken', array( new ClosureStep( 't', $taken ) ) );
		$job    = Plugin::instance()->jobs()->create( 'taken' );
		$result = $this->actions( false )->tick( $job->id, microtime( true ) );
		$this->assertSame( 'lost', $result->status );
		$this->assertSame( Job::RUNNING, $result->job->status, 'taken over, not cancelled' );
		$this->assertCount( 1, $this->events( $job->id ), 'the new holder\'s event stays' );
		$this->assertNotFalse( get_site_transient( Loopback::JOB_PREFIX . $job->id ), 'and so does its hop token' );

		// Cancelled instead: then both go.
		Plugin::instance()->job_actions()->cancel( $job->id );
		$this->assertSame( array(), $this->events( $job->id ) );
		$this->assertFalse( get_site_transient( Loopback::JOB_PREFIX . $job->id ) );
	}

	public function test_a_cron_callback_that_starts_late_in_its_request_hands_the_job_to_the_next_one(): void {
		$this->register( 'plain', array( $this->counting_step( 'p', 3 ) ) );
		$job = Plugin::instance()->jobs()->create( 'plain' );
		// wp-cron.php ran other events first: the request is already 10 s old.
		$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true ) - 10;
		Plugin::instance()->cron_tick( $job->id );
		$this->assertSame( 0, Plugin::instance()->jobs()->find( $job->id )->attempts, 'not ticked' );
		$this->assertCount( 1, $this->events( $job->id ), 'but set again for the next cron request' );
		$this->assertLessThanOrEqual( time() + 1, $this->events( $job->id )[0] );

		$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true ); // A fresh request does tick it.
		Plugin::instance()->cron_tick( $job->id );
		$this->assertSame( 1, Plugin::instance()->jobs()->find( $job->id )->attempts, 'ticked' );
	}

	public function test_the_sweep_keeps_events_when_the_jobs_table_cannot_be_read(): void {
		$this->register( 'plain', array( $this->counting_step( 'p', 1 ) ) );
		$job  = Plugin::instance()->jobs()->create( 'plain' );
		$gone = 999999;
		Loopback::schedule( $job->id, Loopback::FALLBACK_SECONDS );
		Loopback::schedule( $gone, Loopback::FALLBACK_SECONDS );
		$actions = $this->actions( false );
		$sweep   = new \ReflectionMethod( JobActions::class, 'sweep_events' );
		$sweep->setAccessible( true );
		$table = \WPCheckpoint\Support\Schema::jobs_table();
		$break = static function ( string $sql ) use ( $table ): string {
			return 0 === strpos( $sql, "SELECT * FROM {$table} WHERE id = " ) ? str_replace( $table, $table . '_unreadable', $sql ) : $sql;
		};

		add_filter( 'query', $break );
		delete_site_transient( JobActions::SWEPT );
		$sweep->invoke( $actions );
		remove_filter( 'query', $break );
		$this->assertCount( 1, $this->events( $job->id ), 'a failed read proves nothing: the live job keeps its event' );
		$this->assertCount( 1, $this->events( $gone ), 'and so does every other, until the table can be read' );

		delete_site_transient( JobActions::SWEPT );
		$sweep->invoke( $actions );
		$this->assertSame( array(), $this->events( $gone ), 'with the table readable, the same sweep removes the event of a job that is gone' );
		$this->assertCount( 1, $this->events( $job->id ) );
	}

	public function test_each_job_has_its_own_event(): void {
		$this->register( 'long', array( $this->counting_step( 'l', 50 ) ) );
		$a       = Plugin::instance()->jobs()->create( 'long' );
		$b       = Plugin::instance()->jobs()->create( 'long' );
		$actions = $this->one_unit_actions( false );
		$actions->tick( $a->id, microtime( true ) );
		$actions->tick( $b->id, microtime( true ) );
		$this->assertCount( 1, $this->events( $a->id ) );
		$this->assertCount( 1, $this->events( $b->id ), 'the second job is not left out by the first one\'s event' );
	}

	public function test_starting_answering_and_retrying_hand_the_job_to_cron(): void {
		$this->register( 'plain', array( $this->counting_step( 'p', 2 ) ) );
		$started = Plugin::instance()->job_actions()->start( 'plain', self::$admin_id, array() );
		$this->assertCount( 1, $this->events( $started->id ), 'started, and the page closed at once' );

		$this->register( 'asks', array( new ClosureStep( 'q', static function ( JobContext $ctx ): StepResult {
			return empty( $ctx->options()['answers']['x'] ) ? StepResult::ask( array(), array( array( 'id' => 'x', 'kind' => 'x', 'choices' => array( 'go' ) ) ), 'decide' ) : StepResult::done();
		} ) ) );
		$asked = Plugin::instance()->jobs()->create( 'asks' );
		$this->assertSame( 'paused', $this->actions( false )->tick( $asked->id, microtime( true ) )->status );
		$this->assertSame( array(), $this->events( $asked->id ), 'nothing ticks a job that waits for an answer' );
		Plugin::instance()->job_actions()->answer( $asked->id, array( 'x' => 'go' ) );
		$this->assertCount( 1, $this->events( $asked->id ), 'answered, and the page closed at once' );

		$this->register( 'boom', array( new ClosureStep( 'b', static function (): StepResult {
			throw new \RuntimeException( 'broken' );
		} ) ) );
		$failed = Plugin::instance()->jobs()->create( 'boom' );
		$this->assertSame( 'failed', $this->actions( false )->tick( $failed->id, microtime( true ) )->status );
		$this->assertSame( array(), $this->events( $failed->id ) );
		Plugin::instance()->job_actions()->retry( $failed->id );
		$this->assertCount( 1, $this->events( $failed->id ), 'queued again, and the page closed at once' );
	}

	public function test_the_sweep_removes_events_of_jobs_no_driver_ticks_and_keeps_the_others(): void {
		$this->register( 'plain', array( $this->counting_step( 'p', 1 ) ) );
		$this->register( 'asks', array( new ClosureStep( 'q', static function ( JobContext $ctx ): StepResult {
			return empty( $ctx->options()['answers']['x'] ) ? StepResult::ask( array(), array( array( 'id' => 'x', 'kind' => 'x', 'choices' => array( 'go' ) ) ), 'decide' ) : StepResult::done();
		} ) ) );
		$repo     = Plugin::instance()->jobs();
		$actions  = $this->actions( false );
		$finished = $repo->create( 'plain' );
		$this->assertSame( 'completed', $actions->tick( $finished->id, microtime( true ), false )->status, 'a WP-CLI run: no follow-up' );
		$waiting = $repo->create( 'asks' );
		$actions->tick( $waiting->id, microtime( true ), false );
		$answered = $repo->create( 'asks' );
		$actions->tick( $answered->id, microtime( true ), false );
		$repo->answer( $repo->find( $answered->id ), array( 'x' => 'go' ) );
		$queued = $repo->create( 'plain' );
		$gone   = 999999;
		foreach ( array( $finished->id, $waiting->id, $answered->id, $queued->id, $gone ) as $id ) {
			Loopback::schedule( $id, Loopback::FALLBACK_SECONDS );
			$this->assertCount( 1, $this->events( $id ), 'the event is there to be swept: ' . $id );
		}
		delete_site_transient( JobActions::SWEPT );
		$actions->tick( 888888, microtime( true ) ); // Any followed-up tick runs the maintenance.
		$this->assertSame( array(), $this->events( $finished->id ), 'finished without a follow-up' );
		$this->assertSame( array(), $this->events( $waiting->id ), 'waiting for an answer' );
		$this->assertSame( array(), $this->events( $gone ), 'purged' );
		$this->assertCount( 1, $this->events( $answered->id ), 'answered, not yet taken by a tick: still driven' );
		$this->assertCount( 1, $this->events( $queued->id ), 'queued: still driven' );
		$this->assertSame( array(), $this->events( 888888 ), 'the tick of a missing job leaves nothing behind' );

		Loopback::schedule( $finished->id, Loopback::FALLBACK_SECONDS );
		$actions->tick( 888888, microtime( true ) );
		$this->assertCount( 1, $this->events( $finished->id ), 'throttled like the reap' );
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
