<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Jobs\TransientFailure;
use WPCheckpoint\Jobs\WebRun;
use WPCheckpoint\Plugin;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * What a run made without WordPress says (its notes, its result, the job's row and log) hides the site's hosts: those
 * every options table of the swap's plan holds under each of its names (live and staged: a backup from another domain
 * holds that one), and the host the entries give (the request's); the text around them stays.
 */
final class WebMaskTest extends SwapTestCase {

	public function test_the_sites_hosts_and_the_backups_are_masked_and_the_text_stays(): void {
		global $wpdb;
		$job  = $this->at_swap();
		$this->killed_at( $job, 'dir_in' ); // Directories swapped in, the tables not yet: each options table at its own name.
		$live = (string) wp_parse_url( get_option( 'siteurl' ), PHP_URL_HOST );
		$this->assertNotSame( '', $live );
		$staged = null;
		foreach ( $this->plan_of( Plugin::instance()->jobs()->find( $job->id ) )['tables'] as $table ) {
			if ( $wpdb->options === $table['live'] ) {
				$staged = $table['stage'];
			}
		}
		$this->assertNotNull( $staged, 'the control: the plan stages the options table' );
		$wpdb->query( $wpdb->prepare( "UPDATE `{$staged}` SET option_value = %s WHERE option_name = 'siteurl'", 'https://backup-domain.example.net/shop' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the staged table of the test's swap.
		$wpdb->query( 'COMMIT' );

		$said  = 'Could not reach https://' . $live . '/wp-admin, backup-domain.example.net or visitor-host.example.com: it failed';
		$parts = array(
			'at' => function ( string $point ) use ( $said ): void {
				$this->seams[] = $point;
				if ( 'rollback' === $point ) {
					throw new TransientFailure( $said ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the test's text.
				}
			},
		);
		$this->past_grace( $job->id );
		$notes  = array();
		$run    = new WebRun(
			Credentials::from_wordpress(),
			$this->abspath,
			is_multisite(),
			plugin_basename( WPCHECKPOINT_FILE ),
			static function ( string $line ) use ( &$notes ): void {
				$notes[] = $line;
			},
			null,
			array(
				'type'  => $this->type,
				'parts' => $parts + $this->swap_parts,
			),
			null,
			array( 'visitor-host.example.com' )
		);
		$result = $this->autocommit(
			static function () use ( $run, $job ): TickResult {
				return $run->tick( $job->id, microtime( true ) );
			}
		);
		$this->assertContains( 'rollback', $this->seams, 'the control: the failure was thrown' );
		$now = Plugin::instance()->jobs()->find( $job->id );
		$raw = (string) file_get_contents( $now->storage_path . '/' . $now->log_path ); // The log as written, not as shown.
		// The texts the run writes to the row (its other columns hold the restore's own options, written in WordPress).
		$row = implode( "\n", array_map( 'strval', (array) $wpdb->get_row( $wpdb->prepare( 'SELECT progress_message, last_error FROM ' . JobRepository::table() . ' WHERE id = %d', $job->id ), ARRAY_A ) ) );
		$all = array(
			'result' => (string) $result->message,
			'notes'  => implode( "\n", $notes ),
			'row'    => $row,
			'log'    => $raw,
		);
		$this->assertStringContainsString( 'it failed', $raw . $result->message, 'the control: the text is there: ' . $raw );
		foreach ( $all as $where => $text ) {
			foreach ( array( $live, 'backup-domain.example.net', 'visitor-host.example.com' ) as $host ) {
				$this->assertStringNotContainsString( $host, $text, $where . ' holds a host of the site: ' . $text );
			}
		}
		$this->assertStringContainsString( '{site-host}', $raw, 'masked, not dropped' );
	}
}
