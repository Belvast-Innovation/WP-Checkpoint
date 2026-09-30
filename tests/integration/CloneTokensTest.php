<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Sandbox;

/**
 * Whose jobs a copy of the site runs, on generated sequences of what happens to a copy: requests, its operator
 * emptying the copied storage directory, acknowledging the clone notice ("keep the new directory"), moving the custom
 * directory or pointing it back at the original's, and the copy starting restores of its own.
 *
 * Invariants, after every action, as the copy's next request sees them:
 * - I1: a job the original started (one that holds the site changed, and one that does not) is never let through
 *   by the gate nor taken by acquire() on the copy;
 * - I2: a job that holds the site, started by the copy, is let through and taken.
 *
 * The rule under test (Directories): the tokens held when a clone is detected are the original's, and none of them is
 * ever this installation's again. Size: WPCHECKPOINT_CLONE_SEQUENCES sequences from seed
 * WPCHECKPOINT_CLONE_SEQUENCES_SEED (default 300 from 1).
 */
final class CloneTokensTest extends WP_UnitTestCase {

	/** @var string The test's directory; '' before set_up() made it. */
	private $root = '';

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::jobs_table() );
		Options::delete( Schema::OPTION );
		Options::delete( Directories::OPTION );
		$this->root = Sandbox::make( 'clone-tokens' );
	}

	public function tear_down(): void {
		global $wpdb;
		// Before the DROP TABLE, which commits them.
		Options::delete( Schema::OPTION );
		Options::delete( Directories::OPTION );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::jobs_table() );
		if ( '' !== $this->root ) {
			Sandbox::remove( $this->root );
		}
		parent::tear_down();
	}

	/**
	 * The directories of an installation at an ABSPATH.
	 */
	private function dirs( string $site, string $custom ): Directories {
		return new Directories(
			array(
				'is_web_request' => false,
				'document_root'  => '',
				'abspath'        => $this->root . '/' . $site . '/',
				'content_dir'    => $this->root . '/content',
				'custom_dir'     => $custom,
			)
		);
	}

	private static function repo( Directories $dirs ): JobRepository {
		return new JobRepository( $dirs, null, static function (): int {
			return time();
		} );
	}

	private static function hold( int $id ): void {
		global $wpdb;
		$wpdb->update( Schema::jobs_table(), array( 'status' => Job::RUNNING, 'site_state' => Job::SITE_CHANGING ), array( 'id' => $id ) );
	}

	/**
	 * Whether the copy's next request lets a job through and takes it (the lock given back after).
	 */
	private function runs_on_copy( int $id, string $site, string $custom ): bool {
		global $wpdb;
		$repo = self::repo( $this->dirs( $site, $custom ) );
		$job  = $repo->find( $id );
		$let  = null !== $job && $repo->gate( $job )['allowed'];
		$took = null !== $repo->acquire( $id );
		$wpdb->update( Schema::jobs_table(), array( 'lock_token' => '', 'locked_until' => 0 ), array( 'id' => $id ) );
		return $let || $took;
	}

	/**
	 * Run one sequence; the invariants it breaks.
	 *
	 * @return string[]
	 */
	private function sequence( int $seed ): array {
		global $wpdb;
		mt_srand( $seed );
		$custom_mode = 1 === mt_rand( 0, 1 );
		$tag         = 'seq' . $seed;
		foreach ( array( 'a', 'b' ) as $site ) {
			mkdir( $this->root . '/' . $tag . '/' . $site . '/wp-includes', 0755, true );
		}
		$store  = $custom_mode ? $this->root . '/' . $tag . '/store' : '';
		$custom = $store;
		Options::delete( Directories::OPTION );
		$wpdb->query( 'DELETE FROM ' . Schema::jobs_table() );

		// The original: its directory, a job that holds the site and one that does not.
		$original = new Directories( array( 'is_web_request' => false, 'document_root' => '', 'abspath' => $this->root . '/' . $tag . '/a/', 'content_dir' => $this->root . '/content', 'custom_dir' => $store ) );
		if ( '' === $original->base() ) {
			return array( 'setup: the original has no directory: ' . $original->last_error() );
		}
		$repo     = self::repo( $original );
		$held     = $repo->create( 'plain' );
		$plain    = $repo->create( 'plain' );
		self::hold( $held->id );
		$wpdb->update( Schema::jobs_table(), array( 'status' => Job::RUNNING ), array( 'id' => $plain->id ) );
		$theirs   = array( $held->id => 'the original\'s job that holds the site', $plain->id => 'the original\'s job' );
		$ours     = array();
		$found    = array();
		$actions  = $custom_mode ? array( 'request', 'empty', 'acknowledge', 'move', 'back', 'start', 'start' ) : array( 'request', 'acknowledge', 'start', 'start' );
		$steps    = array();
		$site_dir = $tag . '/b';
		for ( $i = 0, $n = mt_rand( 1, 8 ); $i < $n; $i++ ) {
			$action  = $actions[ mt_rand( 0, count( $actions ) - 1 ) ];
			$steps[] = $action;
			$copy    = $this->dirs( $site_dir, $custom );
			switch ( $action ) {
				case 'request':
					$copy->base();
					break;
				case 'empty': // The copy's operator empties the directory the notice said is another installation's.
					if ( is_dir( $store ) ) {
						Sandbox::remove( $store );
						mkdir( $store );
					}
					break;
				case 'acknowledge':
					$copy->acknowledge_clone();
					break;
				case 'move':
					$custom = $this->root . '/' . $tag . '/store-' . $i;
					break;
				case 'back':
					$custom = $store;
					break;
				case 'start':
					if ( '' !== $copy->base() ) {
						$job = self::repo( $copy )->create( 'plain' );
						self::hold( $job->id );
						$ours[ $job->id ] = 'the copy\'s job started after "' . implode( ', ', $steps ) . '"';
					}
					break;
			}
			foreach ( $theirs as $id => $what ) {
				if ( $this->runs_on_copy( $id, $site_dir, $custom ) ) {
					$found[] = sprintf( 'I1 (seed %d, %s, after %s): %s runs on the copy', $seed, $custom_mode ? 'custom' : 'default', implode( ', ', $steps ), $what );
				}
			}
			foreach ( $ours as $id => $what ) {
				if ( ! $this->runs_on_copy( $id, $site_dir, $custom ) ) {
					$found[] = sprintf( 'I2 (seed %d, %s, after %s): %s does not run on the copy', $seed, $custom_mode ? 'custom' : 'default', implode( ', ', $steps ), $what );
				}
			}
		}
		return $found;
	}

	public function test_a_copy_never_takes_back_a_copied_restores_token(): void {
		global $wpdb;
		Schema::ensure();
		foreach ( array( 'a', 'b' ) as $site ) {
			mkdir( $this->root . '/fixed/' . $site . '/wp-includes', 0755, true );
		}
		$store    = $this->root . '/fixed/store';
		$original = new Directories( array( 'is_web_request' => false, 'document_root' => '', 'abspath' => $this->root . '/fixed/a/', 'content_dir' => $this->root . '/content', 'custom_dir' => $store ) );
		$this->assertSame( $store, $original->base(), $original->last_error() );
		$copied = (string) $original->state()['token'];
		$plain  = self::repo( $original )->create( 'plain' );
		$wpdb->update( Schema::jobs_table(), array( 'status' => Job::RUNNING ), array( 'id' => $plain->id ) );
		// A restore of the original's, copied with the database, keeps its files in the original's directory.
		$restores = static function () use ( $store, $copied ): array {
			return array(
				array(
					'path'  => $store,
					'token' => $copied,
				),
			);
		};
		$copy_at = function ( string $custom ) use ( $restores ): Directories {
			return new Directories( array( 'is_web_request' => false, 'document_root' => '', 'abspath' => $this->root . '/fixed/b/', 'content_dir' => $this->root . '/content', 'custom_dir' => $custom, 'restores' => $restores ) );
		};
		// The copy's operator removes the copied directory (a restore's files there keep the copy from moving while it
		// is there), moves the copy elsewhere, then makes the directory again, empty, and names it.
		Sandbox::remove( $store );
		$elsewhere = $this->root . '/fixed/store-2';
		$moved     = $copy_at( $elsewhere );
		$this->assertSame( $elsewhere, $moved->base(), 'the control: the copy has a directory of its own: ' . $moved->last_error() );
		mkdir( $store );
		$copy = $copy_at( $store );
		// Not taken back with the restore's token, the original's: the directory of a restore not this
		// installation's is not used (the refusal of a restore's directory it cannot confirm as its own).
		$this->assertSame( '', $copy->base() );
		$this->assertStringContainsString( 'A restore is in progress in a storage directory', $copy->last_error() );
		$this->assertNotSame( $copied, (string) $copy->state()['token'], 'the restore\'s token, the original\'s, is not taken back' );
		$this->assertFalse( $this->runs_on_copy( $plain->id, 'fixed/b', $store ), 'nor the original\'s job run' );
	}

	public function test_a_copy_never_runs_the_originals_jobs_and_always_runs_its_own(): void {
		Schema::ensure();
		$count  = max( 1, (int) ( getenv( 'WPCHECKPOINT_CLONE_SEQUENCES' ) ?: 300 ) );
		$first  = (int) ( getenv( 'WPCHECKPOINT_CLONE_SEQUENCES_SEED' ) ?: 1 );
		$found  = array();
		$starts = 0;
		for ( $seed = $first; $seed < $first + $count; $seed++ ) {
			$found = array_merge( $found, $this->sequence( $seed ) );
			global $wpdb;
			$starts += (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::jobs_table() ) - 2;
		}
		$this->assertSame( array(), array_slice( $found, 0, 10 ), count( $found ) . ' violations' );
		$this->assertGreaterThan( $count / 4, $starts, 'the control: the copies started jobs of their own to check' );
	}
}
