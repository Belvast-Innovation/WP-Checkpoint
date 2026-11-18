<?php

namespace WPCheckpoint\Tests\Unit\Cli;

use WPCheckpoint\Cli\HeldGuard;
use WPCheckpoint\Restore\Maintenance;
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
			$why = HeldGuard::refusal( $args, array( Maintenance::HELD, Maintenance::BY_SINGLE ) );
			$this->assertSame( $runs, '' === $why, $what );
			if ( ! $runs ) {
				$this->assertStringContainsString( 'A restore holds this site', $why, $what );
				$this->assertStringContainsString( 'wp wpcheckpoint job list', $why, $what );
			}
			$this->assertSame( '', HeldGuard::refusal( $args, array( Maintenance::NONE, '' ) ), $what . ': not held, it runs' );
			// What cannot be told is refused as what is held is, and lets the same commands run.
			foreach ( array( Maintenance::UNTOLD_DATA, Maintenance::UNTOLD_FILE ) as $untold ) {
				$this->assertSame( $runs, '' === HeldGuard::refusal( $args, array( Maintenance::UNKNOWN, $untold ) ), $what . ', ' . $untold );
			}
		}
	}

	public function test_the_refusal_says_what_holds_the_site_and_what_ends_it(): void {
		$args = array( 'cron', 'event', 'run' );
		$said = array();
		foreach ( array(
			Maintenance::BY_DATA     => array( Maintenance::HELD, 'A restore holds this site', array( '.wpcheckpoint-maintenance.php', 'whether .maintenance is still there or not', 'remove .wpcheckpoint-maintenance.php' ) ),
			Maintenance::BY_CODE     => array( Maintenance::HELD, 'A restore holds this site', array( 'no data file next to it', 'remove .maintenance' ) ),
			Maintenance::BY_SINGLE   => array( Maintenance::HELD, 'A restore holds this site', array( 'its maintenance file is up', 'remove .maintenance' ) ),
			Maintenance::UNTOLD_DATA => array( Maintenance::UNKNOWN, 'Whether a restore holds this site cannot be told', array( '.wpcheckpoint-maintenance.php in the WordPress directory cannot be read', 'make the file readable', 'remove the file' ) ),
			Maintenance::UNTOLD_FILE => array( Maintenance::UNKNOWN, 'Whether a restore holds this site cannot be told', array( '.maintenance in the WordPress directory cannot be read', 'make the file readable', 'remove it' ) ),
		) as $why => list( $state, $begins, $names ) ) {
			$text = HeldGuard::refusal( $args, array( $state, $why ) );
			$this->assertStringStartsWith( $begins, $text, $why );
			foreach ( array_merge( $names, array( 'wp wpcheckpoint job list', 'no command here but wp wpcheckpoint (and wp help)' ) ) as $part ) {
				$this->assertStringContainsString( $part, $text, $why . ': ' . $part );
			}
			$said[] = $text;
		}
		$this->assertCount( 5, array_unique( $said ), 'each its own words' );
	}
}
