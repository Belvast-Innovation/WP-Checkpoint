<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\Loopback;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Jobs\ClosureStep;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * A restore replaces the options table, and with it the cron option: the
 * backup's copy holds the fallback tick events of the jobs that were
 * running when the backup was made (the export that made it, at least),
 * by the ids of the site the backup came from, while the jobs table is
 * this installation's and is never swapped. When WP-Cron runs those
 * events, each one reaches the engine's gate with an id that is gone,
 * finished, waiting for an answer or someone else's live job. None of
 * them may create, change or revive a job, or leave a file behind, and
 * the events of jobs nobody drives must be gone after they ran.
 */
final class RestoredCronTest extends JobTestCase {

	public function test_the_backups_tick_events_touch_no_job_they_do_not_drive_and_are_gone_after_running(): void {
		$this->register( 'plain', array( $this->counting_step( 'p', 1 ) ) );
		$this->register( 'asks', array( new ClosureStep( 'q', static function ( JobContext $ctx ): StepResult {
			return empty( $ctx->options()['answers']['x'] ) ? StepResult::ask( array(), array( array( 'id' => 'x', 'kind' => 'x', 'choices' => array( 'go' ) ) ), 'decide' ) : StepResult::done();
		} ) ) );
		$repo     = Plugin::instance()->jobs();
		$actions  = Plugin::instance()->job_actions();
		$finished = $repo->create( 'plain' );
		$actions->tick( $finished->id, microtime( true ), false );
		$waiting = $repo->create( 'asks' );
		$actions->tick( $waiting->id, microtime( true ), false );
		$queued = $repo->create( 'plain' );
		$this->assertSame( Job::COMPLETED, $repo->find( $finished->id )->status );
		$this->assertSame( Job::PAUSED, $repo->find( $waiting->id )->status );
		$this->assertSame( Job::QUEUED, $repo->find( $queued->id )->status );
		$gone = $queued->id + 1000;

		// The swap: the cron option is now the backup's, with one due tick event per id it knew.
		$due    = time() - 30;
		$backup = array(
			$due => array(
				Loopback::HOOK => array(),
				'unrelated_hook' => array( md5( serialize( array() ) ) => array( 'schedule' => false, 'args' => array() ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WP-Cron's own key.
			),
			'version' => 2,
		);
		foreach ( array( $gone, $finished->id, $waiting->id, $queued->id ) as $id ) {
			$backup[ $due ][ Loopback::HOOK ][ md5( serialize( array( $id ) ) ) ] = array( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WP-Cron's own key.
				'schedule' => false,
				'args'     => array( $id ),
			);
		}
		_set_cron_array( $backup );
		$rows_before = $this->rows();
		$tmp         = trailingslashit( Plugin::instance()->directories()->base() ) . 'tmp';
		$files       = $this->files( $tmp );

		$this->run_due_events_as_wp_cron_does();

		$rows_after = $this->rows();
		// The control: the same comparison sees a change, on the one job an event legitimately drives.
		$this->assertNotSame( $rows_before[ $queued->id ], $rows_after[ $queued->id ], 'the queued job was ticked by its event' );
		$this->assertSame( Job::COMPLETED, $repo->find( $queued->id )->status );
		unset( $rows_before[ $queued->id ], $rows_after[ $queued->id ] );
		$this->assertSame( $rows_before, $rows_after, 'no other row changed and none was added (the gone id did not become a job)' );
		$this->assertArrayNotHasKey( $gone, $rows_after );
		$this->assertSame( $files, $this->files( $tmp ), 'no lock file, work directory or log for any of these ids' );

		foreach ( array( $gone, $finished->id, $waiting->id, $queued->id ) as $id ) {
			$this->assertSame( array(), $this->events( $id ), 'no tick event is left for job ' . $id );
		}
		$this->assertNotFalse( wp_next_scheduled( 'unrelated_hook' ), 'the positive control: the scan finds events, and other events stay' );
	}

	/**
	 * What wp-cron.php does with each due event: take it off the schedule, then run its hook.
	 */
	private function run_due_events_as_wp_cron_does(): void {
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			if ( ! is_int( $timestamp ) || $timestamp > time() ) {
				continue;
			}
			foreach ( (array) ( $hooks[ Loopback::HOOK ] ?? array() ) as $event ) {
				wp_unschedule_event( $timestamp, Loopback::HOOK, $event['args'] );
				do_action_ref_array( Loopback::HOOK, $event['args'] );
			}
		}
	}

	/**
	 * Every job row, by id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(): array {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . Schema::jobs_table() . ' ORDER BY id', ARRAY_A ) as $row ) {
			$out[ (int) $row['id'] ] = $row;
		}
		return $out;
	}

	/**
	 * Every path below a directory.
	 *
	 * @return string[]
	 */
	private function files( string $dir ): array {
		$out = array();
		if ( ! is_dir( $dir ) ) {
			return $out;
		}
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST ) as $path => $info ) {
			$out[] = substr( (string) $path, strlen( $dir ) );
		}
		sort( $out );
		return $out;
	}

	/**
	 * The times of a job's tick events.
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
}
