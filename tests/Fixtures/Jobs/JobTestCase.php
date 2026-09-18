<?php

namespace WPCheckpoint\Tests\Fixtures\Jobs;

use WP_UnitTestCase;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Directories;
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

	public static function wpSetUpBeforeClass( $factory ): void {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( self::$admin_id );
		}
	}

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::jobs_table() );
		Options::delete( Schema::OPTION );
		Options::delete( Directories::OPTION );
		delete_site_transient( 'wpcheckpoint_jobs_reaped' );
		delete_site_transient( 'wpcheckpoint_jobs_purged' );
		delete_site_transient( 'wpcheckpoint_environment' );
		Plugin::instance()->reset_directories();
		Plugin::instance()->directories()->base();
		Schema::ensure();
		wp_set_current_user( self::$admin_id );
		global $wp_rest_server;
		$wp_rest_server = null;
		rest_get_server();
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::jobs_table() );
		foreach ( glob( WP_CONTENT_DIR . '/wp-checkpoint-*' ) ?: array() as $dir ) {
			Deleter::empty_directory( $dir );
			@rmdir( $dir );
		}
		Options::delete( Schema::OPTION );
		Options::delete( Directories::OPTION );
		delete_site_transient( 'wpcheckpoint_environment' );
		Plugin::instance()->reset_directories();
		_set_cron_array( array() );
		parent::tear_down();
	}

	/**
	 * Register a fixture type on the plugin's registry.
	 */
	protected function register( string $id, array $steps ): void {
		Plugin::instance()->job_types()->add( new FixtureJobType( $id, $steps ) );
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
