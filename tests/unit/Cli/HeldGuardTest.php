<?php

namespace WPCheckpoint\Tests\Unit\Cli;

use WPCheckpoint\Cli\HeldGuard;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Which WP-CLI commands run while a restore holds the site: this plugin's own, help and cli; none other, and none when
 * the command cannot be read. Nothing is refused while the site is not held.
 */
final class HeldGuardTest extends TestCase {

	public function test_only_this_plugins_commands_and_those_that_touch_no_site_data_run_while_the_site_is_held(): void {
		foreach ( array(
			'wpcheckpoint job list'  => array( array( 'wpcheckpoint', 'job', 'list' ), true ),
			'wpcheckpoint job run 3' => array( array( 'wpcheckpoint', 'job', 'run', '3' ), true ),
			'help'                   => array( array( 'help' ), true ),
			'help cron'              => array( array( 'help', 'cron' ), true ),
			'cli version'            => array( array( 'cli', 'version' ), true ),
			'no command (the help)'  => array( array(), true ),
			'WPCHECKPOINT in capitals' => array( array( 'WPCHECKPOINT', 'job', 'list' ), true ),
			'cron event run'         => array( array( 'cron', 'event', 'run', '--due-now' ), false ),
			'action-scheduler run'   => array( array( 'action-scheduler', 'run' ), false ),
			'eval'                   => array( array( 'eval', 'echo 1;' ), false ),
			'option get'             => array( array( 'option', 'get', 'home' ), false ),
			'plugin deactivate'      => array( array( 'plugin', 'deactivate', 'wp-checkpoint' ), false ),
			'a word like ours'       => array( array( 'wpcheckpoints' ), false ),
			'cannot be read'         => array( null, false ),
		) as $what => list( $args, $runs ) ) {
			$why = HeldGuard::refusal( $args, true );
			$this->assertSame( $runs, '' === $why, $what );
			if ( ! $runs ) {
				$this->assertStringContainsString( 'A restore holds this site', $why, $what );
				$this->assertStringContainsString( 'wp wpcheckpoint job list', $why, $what );
			}
			$this->assertSame( '', HeldGuard::refusal( $args, false ), $what . ': not held, it runs' );
		}
	}
}
