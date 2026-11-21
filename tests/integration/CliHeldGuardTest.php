<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Tests\Fixtures\ActivePlugin;
use WPCheckpoint\Tests\Fixtures\RealAbspath;
use WP_UnitTestCase;

/**
 * While a restore holds the site (a held maintenance file of this plugin's in the WordPress directory, or its held data
 * file alone once .maintenance was deleted), another WP-CLI process runs no command but this plugin's own and help:
 * "wp cron event run --due-now" is refused and runs no event. With neither file the same command runs the event (the
 * control).
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

	/**
	 * Run wp on the test site with its --path spelled as given: exit status, standard output, standard error.
	 *
	 * @param string $path    The WordPress directory, as WP-CLI is told it.
	 * @param string ...$args Arguments.
	 * @return array{0: int, 1: string, 2: string}
	 */
	private function wp_at( string $path, string ...$args ): array {
		$pipes   = array();
		$process = proc_open( array_merge( array( $this->wp, '--path=' . $path ), $args ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		$out = (string) stream_get_contents( $pipes[1] );
		$err = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return array( proc_close( $process ), $out, $err );
	}

	public function test_a_wordpress_directory_spelled_with_dots_is_not_taken_for_one_that_cannot_be_told(): void {
		$site    = rtrim( ABSPATH, '/' );
		$spelled = array(
			'a "." segment'  => dirname( $site ) . '/./' . basename( $site ) . '/',
			'a ".." segment' => $site . '/wp-includes/../',
		);
		ActivePlugin::on();
		try {
			foreach ( $spelled as $what => $dotted ) {
				$this->assertSame( realpath( ABSPATH ), realpath( $dotted ), $what . ': the control: the same directory' );
				list( $status, $out, $err ) = $this->wp_at( $dotted, 'eval', 'echo ABSPATH;' );
				$this->assertSame( 0, $status, "{$what}: no restore holds the site: the command runs.\nstdout: {$out}\nstderr: {$err}" );
				$this->assertSame( $dotted, $out, $what . ': the control: WP-CLI kept the spelling in ABSPATH, the case looked for' );
				$this->assertSame( array( Maintenance::NONE, '' ), Maintenance::held_evidence( $dotted ), $what );
				// The control: the guard is loaded in a process with this path, and judges there. With the held data
				// file it refuses the same command, for the data file.
				RealAbspath::write( Maintenance::DATA, ( new Maintenance( ABSPATH, Maintenance::new_mark() ) )->data( Maintenance::held() ) );
				try {
					list( $status, $out, $err ) = $this->wp_at( $dotted, 'eval', 'echo ABSPATH;' );
					$this->assertSame( 1, $status, "{$what}: held: refused.\nstdout: {$out}\nstderr: {$err}" );
					$this->assertSame( '', $out, $what . ': nothing ran' );
					$this->assertStringContainsString( 'A restore holds this site: its data file says so', $err, $what );
				} finally {
					RealAbspath::remove( Maintenance::DATA );
				}
			}
		} finally {
			ActivePlugin::off();
		}
	}

	public function test_a_file_that_cannot_be_read_is_taken_as_held_and_said_so(): void {
		if ( ! function_exists( 'posix_geteuid' ) || 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Needs a user for whom a file can be made unreadable (not root).' );
		}
		$file = new Maintenance( ABSPATH, Maintenance::new_mark() );
		$path = rtrim( ABSPATH, '/' ) . '/' . Maintenance::DATA;
		$none = static function (): array {
			return array();
		};
		ActivePlugin::on();
		try {
			RealAbspath::write( Maintenance::DATA, $file->data( 1800000000 ) ); // Not a held time: read, it holds nothing.
			$this->assertSame( array( Maintenance::NONE, '' ), Maintenance::held_evidence( ABSPATH ), 'the control: read, positively not held' );
			list( $status, $out, $err ) = $this->wp( 'eval', 'echo "ran";' );
			$this->assertSame( 0, $status, "the control: it runs.\nstdout: {$out}\nstderr: {$err}" );
			$this->assertTrue( chmod( $path, 0 ) );
			clearstatcache();
			$this->assertSame( array( Maintenance::UNKNOWN, Maintenance::UNTOLD_DATA ), Maintenance::held_evidence( ABSPATH ) );
			list( $status, $out, $err ) = $this->wp( 'eval', 'echo "ran";' );
			$this->assertSame( 1, $status, "refused.\nstdout: {$out}\nstderr: {$err}" );
			$this->assertSame( '', $out );
			$this->assertStringContainsString( 'Whether a restore holds this site cannot be told, and it is taken as held: .wpcheckpoint-maintenance.php', $err );
			list( $status, $out, $err ) = $this->wp( 'wpcheckpoint', 'job', 'list', '--format=json' );
			$this->assertSame( 0, $status, "this plugin's commands run.\nstdout: {$out}\nstderr: {$err}" );
			$said = implode( "\n", Plugin::instance()->half_swapped_warnings( $none ) );
			$this->assertStringContainsString( 'Whether a restore holds this site cannot be told: .wpcheckpoint-maintenance.php in the WordPress directory cannot be read', $said );
		} finally {
			if ( file_exists( $path ) ) {
				chmod( $path, 0644 );
			}
			foreach ( RealAbspath::listing() as $left ) {
				RealAbspath::remove( basename( $left ) );
			}
			ActivePlugin::off();
		}
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

			// .maintenance deleted (by a person, a deployment, a cleaner), the held data file still there: the site's
			// tables and directories may be half swapped all the same, and the command is refused as before.
			RealAbspath::remove( Maintenance::FILE );
			$this->assertSame( array( Maintenance::HELD, Maintenance::BY_DATA ), Maintenance::held_evidence( ABSPATH ), 'the control: only the held data file is there' );
			list( $status, $out, $err ) = $this->wp( $hook, 'cron', 'event', 'run', '--due-now' );
			$this->assertSame( 1, $status, "refused with .maintenance deleted.\nstdout: {$out}\nstderr: {$err}" );
			$this->assertSame( '', $out, 'nothing ran' );
			$this->assertStringContainsString( 'A restore holds this site: its data file says so', $err, 'refused for the data file, and said so' );
			$this->assertStringContainsString( 'remove .wpcheckpoint-maintenance.php', $err, 'with what ends it' );
			$this->assertFalse( $this->ran(), 'no event ran' );
			list( $status, $out, $err ) = $this->wp( 'wpcheckpoint', 'job', 'list', '--format=json' );
			$this->assertSame( 0, $status, "this plugin's commands run.\nstdout: {$out}\nstderr: {$err}" );

			// The plugin's warnings say it in the same words of what holds the site, and offer no release (there is no
			// maintenance file of a job to take down).
			$none = static function (): array {
				return array();
			};
			$said = implode( "\n", Plugin::instance()->half_swapped_warnings( $none ) );
			$this->assertStringContainsString( 'A restore\'s data file says the site is held', $said );
			$this->assertStringContainsString( 'No restore holds the site now; check with wp wpcheckpoint job list, and if none does, remove .wpcheckpoint-maintenance.php.', $said );
			$this->assertStringNotContainsString( 'job release', $said );
			$this->assertStringNotContainsString( 'maintenance file is up', $said, 'not the words of a .maintenance in place' );
			$one  = static function (): array {
				return array( PHP_INT_MAX ); // Listed as holding the site (and not found: nothing is said of the job itself).
			};
			$this->assertStringContainsString( 'until the restore above ends', implode( "\n", Plugin::instance()->half_swapped_warnings( $one ) ) );
			$fail = static function (): array {
				throw new \RuntimeException( 'not read' );
			};
			$this->assertStringContainsString( 'The jobs could not be read: do not remove .wpcheckpoint-maintenance.php until', implode( "\n", Plugin::instance()->half_swapped_warnings( $fail ) ) );

			// The control: the same command once the site is not held runs the event.
			RealAbspath::remove( Maintenance::DATA );
			$this->assertSame( array(), Plugin::instance()->half_swapped_warnings( $none ), 'the control: nothing to say with neither file' );
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
