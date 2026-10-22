<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Tests\Fixtures\ActivePlugin;
use WPCheckpoint\Tests\Fixtures\RealAbspath;
use WP_UnitTestCase;

/**
 * While a restore holds the site (a held maintenance file of this plugin's in the WordPress directory), another WP-CLI
 * process runs no command but this plugin's own and help: "wp cron event run --due-now" is refused and runs no event.
 * Without the held file the same command runs the event (the control).
 *
 * Real WP-CLI processes on the test site: the plugin is made active in its tables for the test (and put back after),
 * and the maintenance file is put in the real ABSPATH through RealAbspath (the registered exception).
 */
final class CliHeldGuardTest extends WP_UnitTestCase {

	const HOOK = 'wpcheckpoint_test_held_guard';
	const RAN  = 'wpcheckpoint_test_held_guard_ran';

	/** @var string The wp command ('' when there is none). */
	private $wp = '';

	public function set_up(): void {
		parent::set_up();
		RealAbspath::claim();
		$this->wp = trim( (string) shell_exec( 'command -v wp' ) );
		if ( '' === $this->wp ) {
			$this->markTestSkipped( 'Needs WP-CLI (wp) on the PATH.' );
		}
	}

	/**
	 * Run wp on the test site: exit status, standard output, standard error (read apart).
	 *
	 * @param string ...$args Arguments.
	 * @return array{0: int, 1: string, 2: string}
	 */
	private function wp( string ...$args ): array {
		$pipes   = array();
		$process = proc_open( array_merge( array( $this->wp, '--path=' . ABSPATH ), $args ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		$out = (string) stream_get_contents( $pipes[1] );
		$err = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return array( proc_close( $process ), $out, $err );
	}

	/**
	 * Whether the event's callback ran in the other process (the option it writes, read past any cache).
	 *
	 * @return bool
	 */
	private function ran(): bool {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		return 'yes' === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::RAN ) );
	}

	public function test_while_the_site_is_held_another_wp_cli_process_runs_no_command_but_the_plugins_and_no_event(): void {
		global $wpdb;
		$hook = '--exec=WP_CLI::add_wp_hook( ' . var_export( self::HOOK, true ) . ', static function () { update_option( ' . var_export( self::RAN, true ) . ', "yes" ); } );';
		$file = new Maintenance( ABSPATH, Maintenance::new_mark() );
		ActivePlugin::on();
		try {
			wp_schedule_single_event( time() - 60, self::HOOK );
			$wpdb->query( 'COMMIT' );
			RealAbspath::write( Maintenance::DATA, $file->data( Maintenance::held() ) );
			RealAbspath::write( Maintenance::FILE, Maintenance::CODE );
			$this->assertTrue( Maintenance::held_in( ABSPATH ), 'the control: the site is held' );

			list( $status, $out, $err ) = $this->wp( $hook, 'cron', 'event', 'run', '--due-now' );
			$this->assertSame( 1, $status, "refused.\nstdout: {$out}\nstderr: {$err}" );
			$this->assertSame( '', $out, 'nothing ran' );
			$this->assertStringContainsString( 'A restore holds this site', $err, 'refused by this plugin, which the other process loaded' );
			$this->assertFalse( $this->ran(), 'no event ran' );

			list( $status, $out, $err ) = $this->wp( 'wpcheckpoint', 'job', 'list', '--format=json' );
			$this->assertSame( 0, $status, "this plugin's commands run.\nstdout: {$out}\nstderr: {$err}" );
			$this->assertIsArray( json_decode( $out, true ), $out );
			list( $status, $out, $err ) = $this->wp( 'help', 'cron' );
			$this->assertSame( 0, $status, "help runs.\nstdout: {$out}\nstderr: {$err}" );
			$this->assertFalse( $this->ran(), 'and still no event ran' );

			// The control: the same command once the site is not held runs the event.
			RealAbspath::remove( Maintenance::FILE );
			RealAbspath::remove( Maintenance::DATA );
			list( $status, $out, $err ) = $this->wp( $hook, 'cron', 'event', 'run', '--due-now' );
			$this->assertSame( 0, $status, "not held: it runs.\nstdout: {$out}\nstderr: {$err}" );
			$this->assertTrue( $this->ran(), 'the event ran' );
		} finally {
			foreach ( RealAbspath::listing() as $left ) {
				RealAbspath::remove( basename( $left ) );
			}
			wp_clear_scheduled_hook( self::HOOK );
			delete_option( self::RAN );
			ActivePlugin::off();
		}
	}
}
