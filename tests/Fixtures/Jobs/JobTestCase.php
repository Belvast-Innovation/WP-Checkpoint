<?php

namespace WPCheckpoint\Tests\Fixtures\Jobs;

use WP_UnitTestCase;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Environment;
use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\Schema;

/**
 * Shared set-up for driver tests: a real jobs table, the plugin's own
 * directories rebuilt per test, and fixture job types registered on the
 * plugin's registry.
 */
abstract class JobTestCase extends WP_UnitTestCase {

	/** @var int */
	protected static $admin_id;

	/** @var array<string, \WPCheckpoint\Jobs\JobType>|null The plugin's registered job types before the test, put back in tear_down(). */
	private $types;

	/** @var array<int, array{0: object, 1: string, 2: mixed}> Private properties replace_internal() set, with their values before. */
	private $internals = array();

	public static function wpSetUpBeforeClass( $factory ): void {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( self::$admin_id );
		}
	}

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		// Whatever the test registers (a fixture under a real type's id such as export, or a new id) is gone
		// afterwards: later tests would run the fixture instead of the job.
		$this->types = self::registered_types();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::jobs_table() );
		Options::delete( Schema::OPTION );
		Options::delete( Schema::RETRY_OPTION );
		Schema::set_upgrade_context( true );
		Options::delete( Directories::OPTION );
		// Kept across tests otherwise: the jobs table's DDL commits the test transaction.
		Options::delete( \WPCheckpoint\Backups\Estimate::OPTION );
		Options::delete( \WPCheckpoint\Backups\Estimate::RATE_OPTION );
		Options::delete( \WPCheckpoint\Backups\ExportResults::OPTION );
		delete_site_transient( 'wpcheckpoint_jobs_reaped' );
		delete_site_transient( 'wpcheckpoint_jobs_purged' );
		delete_site_transient( 'wpcheckpoint_jobs_swept' );
		delete_site_transient( 'wpcheckpoint_foreign_tables' );
		delete_site_transient( 'wpcheckpoint_environment' );
		Plugin::instance()->reset_directories();
		Plugin::instance()->directories()->base();
		// A REST tick counts its time budget from the request start; in phpunit that is the process start,
		// so a suite that has run longer than the budget would end every tick after one unit. Each test is
		// its own "request". Likewise, with no probe cached the budget assumes a 64 MB memory limit, which a
		// phpunit process exceeds on its own; a cached probe with real numbers keeps ticks deterministic.
		$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );
		set_site_transient(
			Environment::CACHE,
			array(
				'checked_at' => time(),
				'db'         => array( 'server_info' => '8.0.0', 'size' => 1024 ),
				'loopback'   => array( 'outcome' => 'blocked', 'code' => 403, 'message' => '', 'runtime' => array( 'memory_bytes' => 268435456, 'max_execution_time' => 60 ) ),
			),
			60
		);
		Schema::ensure();
		// The table's DDL commits the test transaction: cron events an earlier test scheduled for the same job
		// ids (they restart at 1 with the table) would survive its rollback.
		_set_cron_array( array() );
		wp_set_current_user( self::$admin_id );
		global $wp_rest_server;
		$wp_rest_server = null;
		rest_get_server();
	}

	public function tear_down(): void {
		global $wpdb;
		$this->restore_plugin();
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::jobs_table() );
		foreach ( glob( WP_CONTENT_DIR . '/wp-checkpoint-*' ) ?: array() as $dir ) {
			Deleter::empty_directory( $dir );
			@rmdir( $dir );
		}
		Options::delete( Schema::OPTION );
		Options::delete( Schema::RETRY_OPTION );
		Schema::set_upgrade_context( true );
		Options::delete( Directories::OPTION );
		delete_site_transient( 'wpcheckpoint_environment' );
		Plugin::instance()->reset_directories();
		_set_cron_array( array() );
		parent::tear_down();
	}

	/**
	 * Register a fixture type on the plugin's registry, for this test only.
	 */
	protected function register( string $id, array $steps ): void {
		Plugin::instance()->job_types()->add( new FixtureJobType( $id, $steps ) );
	}

	/**
	 * A job of a registered type as the drivers usually find one: it has run one tick (running, its first unit
	 * done, no lock between ticks). Pass $ran = false for a job that never ran (queued), and say why in the
	 * test: a job that never ran hides what differs for one that did (the lock between ticks, the attempts).
	 *
	 * @param string               $type    Job type.
	 * @param bool                 $ran     Whether it has run one tick.
	 * @param array<string, mixed> $options Options.
	 * @return int Job id.
	 */
	protected function job_of( string $type, bool $ran = true, array $options = array() ): int {
		$id = Plugin::instance()->jobs()->create( $type, self::$admin_id, array(), $options )->id;
		if ( $ran ) {
			// No follow-up: no event or hop that the test did not ask for.
			Plugin::instance()->job_actions()->tick( $id, \WPCheckpoint\Jobs\JobActions::NO_TIME_LEFT, false );
			$job = Plugin::instance()->jobs()->find( $id );
			$this->assertSame( \WPCheckpoint\Jobs\Job::RUNNING, $job->status, 'the fixture job ended or stopped in its first tick; pass $ran = false' );
			$this->assertSame( '', $job->lock_token );
		}
		return $id;
	}

	/**
	 * Replace a private property of one of the plugin's objects (its runner inside the job actions, say) for
	 * this test only: tear_down() puts the value from before back.
	 *
	 * @param object $owner    The object.
	 * @param string $property Property name.
	 * @param mixed  $value    Value for this test.
	 */
	protected function replace_internal( $owner, string $property, $value ): void {
		$reflection = new \ReflectionProperty( get_class( $owner ), $property );
		$reflection->setAccessible( true );
		$this->internals[] = array( $owner, $property, $reflection->getValue( $owner ) );
		$reflection->setValue( $owner, $value );
	}

	/**
	 * Put back what register() and replace_internal() changed (tear_down() does; a test may call it earlier).
	 */
	protected function restore_plugin(): void {
		// In reverse order, so a property replaced twice ends with its value from before the test.
		foreach ( array_reverse( $this->internals ) as list( $owner, $property, $value ) ) {
			$reflection = new \ReflectionProperty( get_class( $owner ), $property );
			$reflection->setAccessible( true );
			$reflection->setValue( $owner, $value );
		}
		$this->internals = array();
		if ( null !== $this->types ) {
			self::registered_types( $this->types );
		}
	}

	/**
	 * The plugin's registered job types; with $types, set them to that first.
	 *
	 * @param array<string, \WPCheckpoint\Jobs\JobType>|null $types Types to set.
	 * @return array<string, \WPCheckpoint\Jobs\JobType>
	 */
	private static function registered_types( $types = null ): array {
		$reflection = new \ReflectionProperty( \WPCheckpoint\Jobs\JobTypes::class, 'builtin' );
		$reflection->setAccessible( true );
		if ( null !== $types ) {
			$reflection->setValue( Plugin::instance()->job_types(), $types );
		}
		return $reflection->getValue( Plugin::instance()->job_types() );
	}

	/**
	 * A step that needs $units calls of one unit each and stops after every unit (so each tick does one unit
	 * when the budget is tiny, or all when it is large).
	 */
	protected function counting_step( string $id, int $units, $on_unit = null ): ClosureStep {
		return new ClosureStep( $id, static function ( JobContext $ctx ) use ( $units, $on_unit ): StepResult {
			$n = isset( $ctx->cursor()['n'] ) ? (int) $ctx->cursor()['n'] : 0;
			if ( $n >= $units ) {
				return StepResult::done( 'all done' );
			}
			++$n;
			if ( is_callable( $on_unit ) ) {
				$on_unit( $n, $ctx );
			}
			return $n >= $units ? StepResult::done( 'all done' ) : StepResult::progress( array( 'n' => $n ), (int) ( $n / $units * 100 ), 'unit ' . $n );
		} );
	}

	/**
	 * Dispatch a REST request as the admin.
	 *
	 * @return \WP_REST_Response
	 */
	protected function rest( string $method, string $path, array $body = array() ) {
		$request = new \WP_REST_Request( $method, '/wp-checkpoint/v1/' . ltrim( $path, '/' ) );
		if ( array() !== $body ) {
			$request->set_body_params( $body );
		}
		return rest_get_server()->dispatch( $request );
	}
}
