<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\RuntimeLog;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Jobs\WebRun;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * The files of a run without WordPress and the swap: written before the maintenance file (the runtime, then the visitor
 * file, each with a checkpoint after it, then the data file and the code), taken down after it (the code, the data file,
 * the visitor file, the runtime, the runtime log last, its lines added to the job's log first). A WP-CLI run killed at
 * each point in between leaves a state the next run goes on from, to a site as it was and no file left; a take-down does
 * not wait on the job's log; two runs without WordPress at once (no lock between them) take the job one at a time.
 */
final class WebFilesSwapTest extends SwapTestCase {

	/**
	 * The files of the swap in the WordPress directory, in WEB order.
	 *
	 * @return string[]
	 */
	private function swap_files(): array {
		return array_values(
			array_filter(
				array( Maintenance::FILE, Maintenance::DATA, Maintenance::VISITOR, Maintenance::RUNTIME, Maintenance::LOG ),
				function ( string $name ): bool {
					clearstatcache();
					return file_exists( $this->abspath . '/' . $name );
				}
			)
		);
	}

	/**
	 * A job's row as stored.
	 *
	 * @param int $id Job id.
	 * @return array<string, string|null>
	 */
	private static function row( int $id ): array {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . JobRepository::table() . ' WHERE id = %d', $id ), ARRAY_A );
	}

	/**
	 * Where a WP-CLI run is killed on its way in, and what is there then.
	 *
	 * @return array<string, array{0: string, 1: string[]}>
	 */
	public function on_the_way_in(): array {
		return array(
			'runtime written'  => array( 'runtime_written', array( Maintenance::RUNTIME ) ),
			'runtime recorded' => array( 'runtime_recorded', array( Maintenance::RUNTIME ) ),
			'visitor written'  => array( 'visitor_written', array( Maintenance::VISITOR, Maintenance::RUNTIME ) ),
			'visitor recorded' => array( 'visitor_recorded', array( Maintenance::VISITOR, Maintenance::RUNTIME ) ),
			'log written'      => array( 'log_written', array( Maintenance::VISITOR, Maintenance::RUNTIME, Maintenance::LOG ) ),
			'log recorded'     => array( 'log_recorded', array( Maintenance::VISITOR, Maintenance::RUNTIME, Maintenance::LOG ) ),
			'maintenance up'   => array( 'maintenance', array( Maintenance::FILE, Maintenance::DATA, Maintenance::VISITOR, Maintenance::RUNTIME, Maintenance::LOG ) ),
		);
	}

	/**
	 * @dataProvider on_the_way_in
	 */
	public function test_a_run_killed_on_its_way_in_leaves_what_the_next_takes_down( string $seam, array $there ): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, $seam );
		$this->assertSame( $there, $this->swap_files(), 'what the killed run left' );
		$killed = Plugin::instance()->jobs()->find( $job->id );
		if ( ! in_array( Maintenance::DATA, $there, true ) ) {
			// No maintenance file of this job yet: a run without WordPress does not take it, and writes nothing.
			$row  = self::row( $job->id );
			$left = $this->tick_without_wordpress( $killed );
			$this->assertSame( TickResult::LEFT, $left->status, $left->message );
			$this->assertStringContainsString( 'The maintenance file here is not this job\'s.', implode( ' | ', $this->web_notes ) );
			$this->assertSame( $row, self::row( $job->id ), 'nothing written' );
		}
		$done = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::FAILED, $done->status, (string) $done->last_error );
		$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state );
		$this->assertSame( array(), $this->swap_files(), 'the next run took down every file' );
		$this->assertSame( $before, $this->site() );
	}

	/**
	 * The deletions of a take-down, in order.
	 *
	 * @return array<string, array{0: int, 1: string[]}>
	 */
	public function take_down_points(): array {
		$all = array( Maintenance::FILE, Maintenance::DATA, Maintenance::VISITOR, Maintenance::RUNTIME, Maintenance::LOG );
		$out = array();
		foreach ( $all as $i => $name ) {
			$out[ 'before ' . $name ] = array( $i + 1, array_slice( $all, $i ) );
		}
		return $out;
	}

	/**
	 * @dataProvider take_down_points
	 */
	public function test_a_run_killed_in_its_take_down_leaves_the_rest_to_the_next( int $nth, array $there ): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'dir_in' );
		$mark = (string) Plugin::instance()->jobs()->find( $job->id )->cursor['mark'];
		$this->assertTrue( ( new RuntimeLog( $this->abspath, $mark ) )->write( 'Job ' . $job->id . ' is not taken on here: a refusal of a run without WordPress.', time() ), 'the control: written while held' );
		// The next WP-CLI run rolls back and takes the files down; killed right before its nth deletion.
		$this->killed_at( Plugin::instance()->jobs()->find( $job->id ), 'take_down', $nth );
		$this->assertSame( $there, $this->swap_files(), 'deleted in order up to the kill' );
		$done = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::FAILED, $done->status, (string) $done->last_error );
		$this->assertSame( array(), $this->swap_files() );
		$this->assertSame( $before, $this->site() );
		if ( in_array( Maintenance::LOG, $there, true ) ) {
			$this->assertStringContainsString( 'a refusal of a run without WordPress', Plugin::instance()->job_presenter()->log_tail( $done ), 'the runtime log is in the job\'s log' );
		}
	}

	public function test_a_take_down_does_not_wait_on_a_jobs_log_it_cannot_write(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'dir_in' );
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertTrue( ( new RuntimeLog( $this->abspath, (string) $now->cursor['mark'] ) )->write( 'a line to add', time() ) );
		$log  = $now->storage_path . '/' . $now->log_path;
		$logs = dirname( $log );
		$this->assertFileExists( $log, 'the control: the job has its log' );
		chmod( $log, 0444 );
		chmod( $logs, 0555 );
		try {
			clearstatcache();
			$this->assertFalse( is_writable( $log ), 'the control: the job\'s log cannot be written' );
			$done = $this->cli_run( $now );
		} finally {
			chmod( $logs, 0755 );
			chmod( $log, 0644 );
		}
		$this->assertSame( Job::FAILED, $done->status, (string) $done->last_error );
		$this->assertSame( array(), $this->swap_files(), 'every file taken down, the runtime log with them' );
		$this->assertSame( $before, $this->site() );
	}

	public function test_two_runs_without_wordpress_at_once_take_the_job_one_at_a_time(): void {
		$job = $this->at_swap();
		$this->killed_at( $job, 'dir_in', 2 );
		$this->past_grace( $job->id );
		$inner = null;
		$notes = array();
		// The second request's run, made while the first holds the job: the visitor file's lock is not between them (it
		// is the maintenance file's code's, and these runs go past it), the database lease is.
		$second = function () use ( $job, &$inner, &$notes ): void {
			if ( null !== $inner ) {
				return;
			}
			$GLOBALS['wpdb']->query( 'COMMIT' );
			$run   = new WebRun(
				Credentials::from_wordpress(),
				$this->abspath,
				is_multisite(),
				plugin_basename( WPCHECKPOINT_FILE ),
				static function ( string $line ) use ( &$notes ): void {
					$notes[] = $line;
				},
				null,
				array( 'type' => $this->type )
			);
			$inner = $run->tick( $job->id, microtime( true ) );
		};
		$this->grace_over = false;
		$first            = $this->tick_without_wordpress(
			Plugin::instance()->jobs()->find( $job->id ),
			array(
				'at' => function ( string $point ) use ( $second ): void {
					$this->seams[] = $point;
					if ( 'rollback' === $point ) {
						$second();
					}
				},
			)
		);
		$this->assertContains( 'rollback', $this->seams, 'the control: the first run got as far' );
		$this->assertNotNull( $inner );
		$this->assertSame( TickResult::BUSY, $inner->status, 'the second run did not take the job: ' . $inner->message . ' ' . implode( ' | ', $notes ) );
		$this->assertNotContains( $first->status, array( TickResult::BUSY, TickResult::LEFT ), 'the first did: ' . $first->message );
	}
}
