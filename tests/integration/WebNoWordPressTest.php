<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * The whole path of a run made without WordPress, in a process that never loads it (web-child.php: this plugin's own
 * loader, ABSPATH and the database settings only): Runner, its storage, the claim and the rollback, from the claim to
 * the site put back, for each kind of row the eligibility table puts back: rolled back from enter, dirs, carry and
 * rename, reversed from a commit, gone on with, finished. No WordPress function, constant or $wpdb at its start or end,
 * and no fatal error on the way; the site's files and tables back as they were (a run without WordPress cannot flush:
 * it stops there with the file held, and WP-CLI ends the job, unless nothing was swapped).
 */
final class WebNoWordPressTest extends SwapTestCase {

	/**
	 * The child's runs over a job.
	 *
	 * @param Job $job Job.
	 * @param int $max Most runs.
	 * @return array{wordpress_at_start: string[], wordpress_at_end: string[], results: array<int, array<string, mixed>>, notes: string[]}
	 */
	private function child( Job $job, int $max = 40 ): array {
		global $table_prefix, $wpdb;
		$this->past_grace( $job->id ); // The last run in WordPress stopped longer ago than the grace period.
		$wpdb->query( 'COMMIT' );
		$config = $this->sandbox . '/web-child-' . bin2hex( random_bytes( 4 ) ) . '.json';
		file_put_contents(
			$config,
			(string) wp_json_encode(
				array(
					'abspath'   => $this->abspath,
					'db'        => array(
						'name'     => DB_NAME,
						'user'     => DB_USER,
						'password' => DB_PASSWORD,
						'host'     => DB_HOST,
						'prefix'   => $table_prefix,
					),
					'multisite' => is_multisite(),
					'plugin'    => plugin_basename( WPCHECKPOINT_FILE ),
					'type'      => $this->type,
					'job'       => $job->id,
					'max'       => $max,
				)
			)
		);
		$pipes   = array();
		$process = proc_open(
			array( PHP_BINARY, dirname( __DIR__ ) . '/Fixtures/Restore/web-child.php', $config ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		$this->assertIsResource( $process );
		$out    = (string) stream_get_contents( $pipes[1] );
		$err    = (string) stream_get_contents( $pipes[2] );
		$status = proc_close( $process ); // The config stays in the sandbox, removed with it.
		$said = "exit {$status}\nstdout:\n{$out}\nstderr:\n{$err}";
		$this->assertSame( 0, $status, $said );
		$this->assertStringNotContainsString( 'WEB-CHILD-FATAL', $out, $said );
		$lines  = array_values( array_filter( explode( "\n", trim( $out ) ) ) );
		$report = json_decode( (string) end( $lines ), true );
		$this->assertIsArray( $report, $said );
		$this->assertSame( array(), $report['wordpress_at_start'], 'WordPress not loaded at the start: ' . $said );
		$this->assertSame( array(), $report['wordpress_at_end'], 'nor at the end: ' . $said );
		$this->assertNotSame( array(), $report['results'], $said );
		$this->assertNotSame( TickResult::BUSY, $report['results'][0]['status'], 'the claim took it: ' . $said );
		return $report;
	}

	/**
	 * Assert the child put the site back: stopped before the flush, the file held, then WP-CLI ended the job; or,
	 * nothing having been swapped, ended it itself.
	 *
	 * @param Job                  $job     Job.
	 * @param array<string, mixed> $before  The site before the restore.
	 * @param bool                 $nothing Whether nothing was swapped (the child ends the job).
	 * @return void
	 */
	private function assert_put_back_without_wordpress( Job $job, array $before, bool $nothing = false ): void {
		$report = $this->child( $job );
		$last   = end( $report['results'] );
		$now    = Plugin::instance()->jobs()->find( $job->id );
		if ( $nothing ) {
			$this->assertSame( Job::FAILED, $now->status, wp_json_encode( $report ) . ' ' . $now->last_error );
			$this->assertSame( $before, $this->site() );
			return;
		}
		$this->assertSame( TickResult::LEFT, $last['status'], wp_json_encode( $report ) );
		$this->assertStringContainsString( 'object cache can only be flushed by WP-CLI', (string) $last['message'] );
		$site = $this->site();
		$this->assertTrue( $site['maintenance'], 'held' );
		$site['maintenance'] = false;
		$this->assertSame( $before, $site, 'the files and the tables put back without WordPress' );
		$done = $this->cli_run( $now );
		$this->assertSame( Job::FAILED, $done->status, (string) $done->last_error );
		$this->assertSame( $before, $this->site() );
	}

	public function test_rolled_back_from_enter_nothing_swapped_and_ended(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'maintenance' );
		$this->assert_put_back_without_wordpress( Plugin::instance()->jobs()->find( $job->id ), $before, true );
	}

	public function test_rolled_back_from_dirs(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'dir_in', 2 );
		$this->assert_put_back_without_wordpress( Plugin::instance()->jobs()->find( $job->id ), $before );
	}

	public function test_rolled_back_from_carry(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'carried' );
		$this->assertSame( 'carry', Plugin::instance()->jobs()->find( $job->id )->cursor['phase'] ?? '', 'the control' );
		$this->assert_put_back_without_wordpress( Plugin::instance()->jobs()->find( $job->id ), $before );
	}

	public function test_rolled_back_from_rename(): void {
		$this->swap_parts['batch'] = 1;
		$this->register_type();
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'batch_sent', 2 );
		$this->assert_put_back_without_wordpress( Plugin::instance()->jobs()->find( $job->id ), $before );
	}

	public function test_reversed_from_a_commit(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'committed' );
		$this->assert_put_back_without_wordpress( Plugin::instance()->jobs()->find( $job->id ), $before );
	}

	public function test_a_rollback_gone_on_with(): void {
		$this->swap_parts['batch'] = 1;
		$this->register_type();
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'batch_sent', 2 );
		$this->killed_at( Plugin::instance()->jobs()->find( $job->id ), 'table_back', 1 );
		$this->assertSame( 'rollback', Plugin::instance()->jobs()->find( $job->id )->cursor['phase'] ?? '', 'the control: a rollback under way' );
		$this->assert_put_back_without_wordpress( Plugin::instance()->jobs()->find( $job->id ), $before );
	}

	public function test_a_rollback_recorded_as_put_back_finished(): void {
		$this->swap_parts['batch'] = 1;
		$this->register_type();
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'batch_sent', 2 );
		$this->killed_at( Plugin::instance()->jobs()->find( $job->id ), 'restored' );
		$this->assertSame( 'restored', Plugin::instance()->jobs()->find( $job->id )->cursor['phase'] ?? '', 'the control: recorded as put back' );
		$report = $this->child( Plugin::instance()->jobs()->find( $job->id ) );
		$done   = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::FAILED, $done->status, wp_json_encode( $report ) . ' ' . $done->last_error );
		$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state );
		$this->assertSame( $before, $this->site(), 'ended, its file down' );
	}
}
