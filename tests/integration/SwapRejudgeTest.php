<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\RestorePreflightStep;
use WPCheckpoint\Jobs\SwapCheckStep;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * The swap judges the backup's tables once more before it changes anything (another installation's tables or users
 * may have appeared since the preflight), and once more, the live tables only, after it put up the maintenance file
 * and before the first rename. A judgement that differs starts the restore over at its preflight, which asks again;
 * the second takes the maintenance file down first, then records the site as untouched (never the rollback).
 */
final class SwapRejudgeTest extends SwapTestCase {

	/**
	 * The tables of another installation under "{prefix}swt_": the backup's swt_ tables become ones that may be
	 * either installation's.
	 *
	 * @return string[]
	 */
	private static function neighbour_sql(): array {
		global $wpdb;
		$out = array();
		foreach ( array( 'posts', 'postmeta', 'options', 'comments', 'terms', 'term_taxonomy', 'term_relationships' ) as $marker ) {
			$out[] = "CREATE TABLE `{$wpdb->prefix}swt_{$marker}` (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB";
		}
		return $out;
	}

	private function neighbour(): void {
		global $wpdb;
		foreach ( array( 'posts', 'postmeta', 'options', 'comments', 'terms', 'term_taxonomy', 'term_relationships' ) as $marker ) {
			$this->create( $wpdb->prefix . 'swt_' . $marker, '(id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB' );
		}
		$wpdb->query( 'COMMIT' );
	}

	private function no_neighbour(): void {
		global $wpdb;
		foreach ( array( 'posts', 'postmeta', 'options', 'comments', 'terms', 'term_taxonomy', 'term_relationships' ) as $marker ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}swt_{$marker}`" );
		}
		$wpdb->query( 'COMMIT' );
	}

	/**
	 * The job failed with the site as it was, a retry starting at the preflight.
	 */
	private function assertStartsOver( Job $done, array $before, string $why ): void {
		$this->assertSame( Job::FAILED, $done->status, $why . ': ' . $done->last_error );
		$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state, $why );
		$this->assertSame( RestorePreflightStep::ID, $done->cursor[ JobRepository::RETRY_FROM_KEY ] ?? null, $why . ': a retry starts at the preflight' );
		$this->assertStringContainsString( 'judged otherwise than at the preflight', (string) $done->last_error, $why );
		$this->assertNotSame( Job::FAILURE_FINAL, $done->failure_kind, $why . ': not final' );
		$this->assertSame( $before, $this->site(), $why . ': the site is as it was' );
		$this->assertFileDoesNotExist( $this->abspath . '/.maintenance', $why . ': no maintenance file' );
	}

	public function test_the_same_judgement_lets_the_swap_go_on(): void {
		// The control for the tests below: nothing changed, the swap is made.
		$job    = $this->at_swap();
		$before = $this->site();
		$done   = $this->cli_run( $job );
		$this->assertSame( Job::COMPLETED, $done->status, $done->last_error );
		$this->assertContains( 'entered', $this->seams );
		$this->assertRestored( $before );
	}

	public function test_another_installation_that_appeared_since_the_preflight_starts_the_restore_over_there(): void {
		global $wpdb;
		$job = $this->at_swap();
		$old = array_values( $this->temporary_names( $job ) );
		$this->neighbour();
		$before = $this->site();
		$done   = $this->cli_run( $job );
		$this->assertStartsOver( $done, $before, 'a neighbour appeared' );
		$this->assertNotContains( 'entered', $this->seams, 'nothing was changed' );
		// The retry asks about the tables that may now be the other installation's, and the earlier attempt's
		// tables are gone before it does.
		Plugin::instance()->job_actions()->retry( $job->id );
		$wpdb->query( 'COMMIT' );
		$runner = Plugin::instance()->runner();
		for ( $i = 0; $i < 100; $i++ ) {
			$result = $runner->tick( $job->id, microtime( true ) );
			if ( TickResult::PAUSED === $result->status || ! in_array( $result->status, array( TickResult::MORE, TickResult::WAITING ), true ) ) {
				break;
			}
		}
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::PAUSED, $now->status, 'asked again' );
		$this->assertSame( RestorePreflightStep::ID, $now->step );
		$this->assertStringStartsWith( 'uncertain_tables_', (string) ( $now->questions[0]['id'] ?? '' ) );
		$this->assertNotSame( array(), $old, 'the control: the earlier attempt had tables' );
		foreach ( $old as $table ) {
			$this->assertSame( null, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), 'the earlier attempt\'s table is gone: ' . $table );
		}
	}

	public function test_a_plan_without_a_judgement_to_compare_with_starts_the_restore_over_at_the_preflight(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$path   = $this->work( $job ) . '/' . \WPCheckpoint\Restore\RestoreFiles::PLAN;
		$plan   = json_decode( (string) file_get_contents( $path ), true );
		$this->assertArrayHasKey( 'incoming', $plan, 'the control: the preflight recorded one' );
		unset( $plan['incoming'] ); // As a plan written by an earlier version.
		file_put_contents( $path, (string) wp_json_encode( $plan ) );
		$done = $this->cli_run( $job );
		$this->assertSame( Job::FAILED, $done->status, $done->last_error );
		$this->assertSame( RestorePreflightStep::ID, $done->cursor[ JobRepository::RETRY_FROM_KEY ] ?? null );
		$this->assertStringContainsString( 'recorded no judgement', (string) $done->last_error );
		$this->assertNotContains( 'entered', $this->seams );
		$this->assertSame( $before, $this->site() );
	}

	public function test_another_installations_user_that_appeared_since_the_preflight_starts_the_restore_over_there(): void {
		global $wpdb;
		$job    = $this->at_swap();
		$before = $this->site();
		$wpdb->insert(
			$wpdb->usermeta,
			array(
				'user_id'    => self::$admin_id,
				'meta_key'   => 'otherwp_capabilities', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test row.
				'meta_value' => 'a:0:{}', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- a test row.
			)
		);
		$wpdb->query( 'COMMIT' );
		try {
			$done = $this->cli_run( $job );
			$this->assertStartsOver( $done, $before, 'another installation\'s user appeared' );
			$this->assertNotContains( 'entered', $this->seams, 'nothing was changed' );
		} finally {
			$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => 'otherwp_capabilities' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test row.
			$wpdb->query( 'COMMIT' );
		}
	}

	public function test_a_neighbour_that_appears_once_the_maintenance_file_is_up_stops_the_swap_before_any_rename(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->retype(
			$job,
			array(
				'at' => function ( string $point ): void {
					$this->seams[] = $point;
					if ( 'maintenance' === $point ) {
						$this->neighbour();
					}
				},
			)
		);
		$done = $this->cli_run( $job );
		$this->assertStartsOver( $done, $before, 'a neighbour appeared once the file was up' );
		$this->assertStringContainsString( 'stopped before it renamed anything', (string) $done->last_error );
		$this->assertSame( array( 'entered', 'maintenance', 'judge_file_down', 'judge_recorded' ), array_values( array_intersect( $this->seams, array( 'entered', 'maintenance', 'judge_file_down', 'judge_recorded', 'dir_aside', 'rollback' ) ) ), 'the file down, then recorded; no rename, no rollback' );
	}

	public function test_killed_after_the_file_came_down_the_next_run_puts_the_site_back_and_a_retry_starts_over_at_the_preflight(): void {
		$job              = $this->at_swap();
		$before           = $this->site();
		$this->child_sql  = array(
			'seam' => 'maintenance',
			'sql'  => self::neighbour_sql(),
		);
		try {
			$this->killed_at( $job, 'judge_file_down', 1 );
			$left = Plugin::instance()->jobs()->find( $job->id );
			$this->assertSame( 'enter', $left->cursor['phase'] ?? '', 'not recorded yet' );
			$this->assertSame( Job::SITE_CHANGING, $left->site_state );
			$this->assertFileDoesNotExist( $this->abspath . '/.maintenance', 'the file came down first' );
			// The next run finds the site entering: it puts it back (nothing to rename) and ends with a retry at the check.
			$done = $this->cli_run( $job );
			$this->assertSame( Job::FAILED, $done->status, $done->last_error );
			$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state );
			$this->assertSame( SwapCheckStep::ID, $done->cursor[ JobRepository::RETRY_FROM_KEY ] ?? null );
			$this->assertFalse( Maintenance::held_in( $this->abspath ), 'no held file left' );
			$this->assertFileDoesNotExist( $this->abspath . '/.maintenance' );
			$this->assertSame( $before, $this->site() );
			// That retry judges again at the swap's start, and starts the restore over at the preflight.
			$retried = $this->retried( $job );
			$this->assertStartsOver( $retried, $before, 'after the retry' );
		} finally {
			$this->no_neighbour();
		}
	}

	public function test_killed_after_the_site_was_recorded_untouched_the_next_run_starts_the_restore_over_at_the_preflight(): void {
		$job             = $this->at_swap();
		$before          = $this->site();
		$this->child_sql = array(
			'seam' => 'maintenance',
			'sql'  => self::neighbour_sql(),
		);
		try {
			$this->killed_at( $job, 'judge_recorded', 1 );
			$left = Plugin::instance()->jobs()->find( $job->id );
			$this->assertSame( 'rejudged', $left->cursor['phase'] ?? '' );
			$this->assertSame( Job::SITE_UNTOUCHED, $left->site_state );
			$this->assertFileDoesNotExist( $this->abspath . '/.maintenance' );
			$this->assertStartsOver( $this->cli_run( $job ), $before, 'after the kill' );
		} finally {
			$this->no_neighbour();
		}
	}

	/**
	 * Retry a job that failed with a retry from the final check, then run it to its end (the check runs on any
	 * driver; the swap stops it for WP-CLI).
	 */
	private function retried( Job $job ): Job {
		Plugin::instance()->job_actions()->retry( $job->id );
		$GLOBALS['wpdb']->query( 'COMMIT' );
		$runner = Plugin::instance()->runner();
		for ( $i = 0; $i < 200; $i++ ) {
			$result = $this->autocommit(
				static function () use ( $runner, $job ) {
					return $runner->tick( $job->id, microtime( true ) );
				}
			);
			if ( TickResult::CLI === $result->status || ! in_array( Plugin::instance()->jobs()->find( $job->id )->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				break;
			}
		}
		return $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
	}
}
