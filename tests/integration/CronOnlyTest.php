<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Backups\VerifyRecord;
use WPCheckpoint\Cli\ExportCommand;
use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\ExportJob;
use WPCheckpoint\Jobs\ExportOptions;
use WPCheckpoint\Jobs\ExportPlan;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\Loopback;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Jobs\VerifyJob;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Environment;
use WPCheckpoint\Tests\Fixtures\Archive\ArchiveBuilder;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * Jobs started the way a page starts them and then left alone: no browser
 * tick, nothing but the cron events the plugin schedules, run one at a
 * time the way WP-Cron runs them (the event is removed, then its callback
 * runs in a fresh request). The clock runs fast, so each tick does about
 * one unit: the jobs cross many ticks and every one of them must leave the
 * next event behind. Event times are not waited for: only whether the
 * next event exists matters here, not when it is due.
 *
 * Covers the host whose loopback probe failed (no chain at all), the host
 * whose chain is cut after the probe passed (hops refused, as by a
 * firewall or HTTP authentication added later), and two jobs at once.
 */
final class CronOnlyTest extends JobTestCase {

	/** @var string */
	private $uploads;

	/** @var ArchiveBuilder|null */
	private $builder;

	/** @var int Loopback hops the "firewall" refused. */
	private $refused = 0;

	public function set_up(): void {
		parent::set_up();
		$this->uploads = wp_upload_dir()['basedir'] . '/wpccron';
		wp_mkdir_p( $this->uploads );
		file_put_contents( $this->uploads . '/a.txt', str_repeat( 'cron only ', 300 ) );
		file_put_contents( $this->uploads . '/b.bin', random_bytes( 5000 ) );
		// A slow host: every clock reading moves 8 s, so a unit measures about 8 s (inside the 20 s budget) and
		// the measured-duration guard ends the tick after it. The jobs cross many ticks.
		$readings = 0;
		$clock    = static function () use ( &$readings ): float {
			return microtime( true ) + 8 * ( ++$readings );
		};
		$runner   = new Runner( Plugin::instance()->jobs(), Plugin::instance()->job_types(), Plugin::instance()->redactor(), array( 'budget' => new Budget( 20, 32 * 1048576, false ), 'memory_limit' => -1, 'clock' => $clock ) );
		$this->replace_internal( Plugin::instance()->job_actions(), 'runner', $runner );
	}

	public function tear_down(): void {
		Deleter::empty_directory( $this->uploads );
		@rmdir( $this->uploads );
		if ( null !== $this->builder ) {
			$this->builder->cleanup();
			$this->builder = null;
		}
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	private function start_export(): Job {
		$options = ExportOptions::normalize( array_merge( ExportCommand::options( array( 'yes' => true ) ), array( 'contents' => array( 'files' => array( 'uploads' ) ) ) ) );
		return Plugin::instance()->job_actions()->start( ExportJob::ID, self::$admin_id, $options );
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
	 * Run the plugin's cron events, earliest first, until every job has finished.
	 *
	 * @param int[] $ids Jobs.
	 * @return int Events run.
	 */
	private function run_cron_until_finished( array $ids ): int {
		$run = 0;
		for ( $i = 0; $i < 3000; $i++ ) {
			$open = array();
			foreach ( $ids as $id ) {
				$status = Plugin::instance()->jobs()->find( $id )->status;
				if ( ! in_array( $status, array( Job::COMPLETED, Job::FAILED, Job::CANCELLED ), true ) ) {
					$open[] = $id;
				}
				$this->assertLessThanOrEqual( 1, count( $this->events( $id ) ), 'never more than one event per job' );
			}
			if ( array() === $open ) {
				return $run;
			}
			$next = null;
			foreach ( $open as $id ) {
				$events = $this->events( $id );
				$this->assertNotSame( array(), $events, sprintf( 'job %d (%s) is not finished and nothing will tick it', $id, Plugin::instance()->jobs()->find( $id )->status ) );
				if ( null === $next || $events[0] < $next[0] ) {
					$next = array( $events[0], $id );
				}
			}
			// What wp-cron.php does: remove the event, then run its callback in a new request.
			wp_unschedule_event( $next[0], Loopback::HOOK, array( $next[1] ) );
			$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );
			do_action_ref_array( Loopback::HOOK, array( $next[1] ) );
			++$run;
		}
		$this->fail( 'the jobs did not finish within 3000 cron runs' );
	}

	private function assert_backed_up( Job $job ): void {
		$job = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$base = (string) ExportPlan::read( Residue::work_dir( $job->storage_path, $job->id ), ExportPlan::PLAN )['base'];
		$this->assertFileExists( Plugin::instance()->directories()->backups() . '/' . $base . '.manifest.json' );
		$this->assertSame( array(), $this->events( $job->id ), 'no event is left for the finished job' );
	}

	public function test_without_a_chain_cron_alone_finishes_an_export(): void {
		$this->assertFalse( Plugin::instance()->loopback()->enabled(), 'this host has no chain (the probe did not reach the site)' );
		$job = $this->start_export();
		$run = $this->run_cron_until_finished( array( $job->id ) );
		$this->assertGreaterThan( 10, $run, 'the export crossed many ticks, each leaving the next event' );
		$this->assert_backed_up( $job );
	}

	private function copy_fixture_backup(): string {
		$this->builder = ( new ArchiveBuilder() )->typical()->build();
		$backups       = Plugin::instance()->directories()->backups();
		foreach ( array_merge( $this->builder->volumes, array( $this->builder->manifest_path ) ) as $file ) {
			copy( $file, $backups . '/' . basename( $file ) );
		}
		return $backups;
	}

	public function test_when_the_chain_is_cut_after_the_probe_cron_finishes_the_job(): void {
		// A check, not an export: each hop writes a token row into the options table, and an export that does
		// one unit per tick while the chain is on never reaches that table's end (separate fix, not this one).
		$backups                      = $this->copy_fixture_backup();
		$cache                        = get_site_transient( Environment::CACHE );
		$cache['loopback']['outcome'] = 'reachable';
		set_site_transient( Environment::CACHE, $cache, 60 );
		$this->assertTrue( Plugin::instance()->loopback()->enabled(), 'the probe passed' );
		add_filter(
			'pre_http_request',
			function ( $response, array $args, string $url ) {
				if ( false !== strpos( $url, '/' . Loopback::ROUTE_SUFFIX ) ) {
					++$this->refused;
					return new \WP_Error( 'http_request_failed', 'Forbidden by a firewall added after the probe.' );
				}
				return $response;
			},
			10,
			3
		);
		$job = Plugin::instance()->job_actions()->start( VerifyJob::ID, self::$admin_id, VerifyJob::options( array( 'base' => ArchiveBuilder::BASE ) ) );
		$run = $this->run_cron_until_finished( array( $job->id ) );
		$this->assertGreaterThan( 5, $run, 'the check crossed many ticks' );
		$this->assertSame( $run, $this->refused + 1, 'every tick but the last sent a hop, and every hop was refused' );
		$this->assertSame( Job::COMPLETED, Plugin::instance()->jobs()->find( $job->id )->status );
		$this->assertFileExists( $backups . '/' . VerifyRecord::file_name( ArchiveBuilder::BASE ) );
		$this->assertSame( array(), $this->events( $job->id ) );
	}

	public function test_an_export_finishes_while_every_hop_writes_a_new_token_row(): void {
		// With the chain on, each tick replaces the job's hop token: a new row in the options (or sitemeta) table per tick.
		// One unit per tick used to chase that table's end forever; its bound, fixed when it starts, ends it.
		$cache                        = get_site_transient( Environment::CACHE );
		$cache['loopback']['outcome'] = 'reachable';
		set_site_transient( Environment::CACHE, $cache, 60 );
		add_filter(
			'pre_http_request',
			function ( $response, array $args, string $url ) {
				if ( false !== strpos( $url, '/' . Loopback::ROUTE_SUFFIX ) ) {
					++$this->refused;
					return new \WP_Error( 'http_request_failed', 'Forbidden by a firewall added after the probe.' );
				}
				return $response;
			},
			10,
			3
		);
		global $wpdb;
		// Site transients live in the options table, or in sitemeta on multisite: the table that grows.
		$probe_id = static function (): int {
			global $wpdb;
			if ( is_multisite() ) {
				add_site_option( 'wpcheckpoint_test_probe', '1' );
				$id = (int) $wpdb->get_var( "SELECT meta_id FROM {$wpdb->sitemeta} WHERE meta_key = 'wpcheckpoint_test_probe'" );
				delete_site_option( 'wpcheckpoint_test_probe' );
				return $id;
			}
			add_option( 'wpcheckpoint_test_probe', '1', '', 'no' );
			$id = (int) $wpdb->get_var( "SELECT option_id FROM {$wpdb->options} WHERE option_name = 'wpcheckpoint_test_probe'" );
			delete_option( 'wpcheckpoint_test_probe' );
			return $id;
		};
		$before = $probe_id();
		$job    = $this->start_export();
		$this->run_cron_until_finished( array( $job->id ) );
		$this->assertGreaterThan( 10, $this->refused, 'a token for every hop' );
		// Token rows are deleted as they are replaced: the auto-increment shows how many were added meanwhile.
		$this->assertGreaterThan( $before + 10, $probe_id(), 'the table holding the tokens grew while it was exported' );
		$this->assert_backed_up( $job );
	}

	public function test_an_export_and_a_check_of_another_backup_both_finish_on_cron_alone(): void {
		$backups = $this->copy_fixture_backup();
		$export  = $this->start_export();
		$verify = Plugin::instance()->job_actions()->start( VerifyJob::ID, self::$admin_id, VerifyJob::options( array( 'base' => ArchiveBuilder::BASE ) ) );
		$this->assertCount( 1, $this->events( $export->id ) );
		$this->assertCount( 1, $this->events( $verify->id ), 'each job has its own event' );
		$this->run_cron_until_finished( array( $export->id, $verify->id ) );
		$this->assert_backed_up( $export );
		$this->assertSame( Job::COMPLETED, Plugin::instance()->jobs()->find( $verify->id )->status );
		$this->assertFileExists( $backups . '/' . VerifyRecord::file_name( ArchiveBuilder::BASE ) );
		$this->assertSame( array(), $this->events( $verify->id ) );
	}
}
