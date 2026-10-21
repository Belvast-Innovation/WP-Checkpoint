<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Restore\SelfCheck;
use WPCheckpoint\Support\StoredNames;
use WPCheckpoint\Tests\Fixtures\ActivePlugin;
use WPCheckpoint\Tests\Fixtures\RealAbspath;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * The checks of the restored site, for real: through the test site's web server, behind the swap's maintenance file
 * in the real ABSPATH (RealAbspath, the registered exception), with the plugin active in the test site's tables
 * (ActivePlugin) so that the web server's WordPress answers the self-check route. A site that answers passes and is let
 * out; the self-check's answer from another one-time value fails at once and the swap is reversed; WordPress's fatal
 * error page twice fails and the swap is reversed; the route missing (the plugin not active there) cannot be told,
 * and the site is asked about.
 *
 * The web server's WordPress answers at its own address (its WP_HOME, read from its REST index), which is not reachable
 * from here, and redirects any other (even another port) there: the checks ask for its address, and the requests are
 * sent to the web server (WPCHECKPOINT_TEST_LOOPBACK_HOST) with that address's Host header. A broken restored site is stood in for by a page that calls wp_die() with HTTP 500 (the swap here changes the
 * test's sandbox, not the web server's site).
 */
final class SiteCheckHttpTest extends SwapTestCase {

	/** @var string The web server's address. */
	private $host = '';

	/** @var string The web server's WordPress address (its WP_HOME). */
	private $home = '';

	/** @var string The page the home check asks for ('' for the home page itself). */
	private $home_page = '';

	public function set_up(): void {
		parent::set_up();
		$this->host = rtrim( (string) getenv( 'WPCHECKPOINT_TEST_LOOPBACK_HOST' ), '/' );
		if ( '' === $this->host ) {
			$this->markTestSkipped( 'Needs the web server of the test site (WPCHECKPOINT_TEST_LOOPBACK_HOST).' );
		}
		$index      = wp_remote_get( $this->host . '/?rest_route=/', array( 'timeout' => 20 ) );
		$this->home = rtrim( (string) ( json_decode( (string) wp_remote_retrieve_body( $index ), true )['home'] ?? '' ), '/' );
		$this->assertMatchesRegularExpression( '#\Ahttps?://[^/]+\z#', $this->home, 'the web server\'s WordPress says its address' );
		$this->abspath               = RealAbspath::claim();
		$this->swap_parts['abspath'] = $this->abspath;
		$this->swap_parts['sleep']   = static function ( int $seconds ): void {
			unset( $seconds ); // The 15 seconds between two looks at a fatal error page are not waited here.
		};
		$this->swap_parts['urls']    = function (): array {
			$fresh = 'wpcheckpoint_check=' . bin2hex( random_bytes( 6 ) );
			return array(
				'home'  => $this->home . ( '' === $this->home_page ? '/' : $this->home_page ) . '?' . $fresh,
				'login' => $this->home . '/wp-login.php?' . $fresh,
				'self'  => $this->home . '/?rest_route=' . SelfCheck::PATH . '&' . $fresh,
			);
		};
		$this->swap_parts['http']    = function ( string $url, array $headers ): array {
			$this->assertStringStartsWith( $this->home . '/', $url, 'only the site\'s own address is asked for' );
			$host = (string) wp_parse_url( $this->home, PHP_URL_HOST ) . ( null !== wp_parse_url( $this->home, PHP_URL_PORT ) ? ':' . (int) wp_parse_url( $this->home, PHP_URL_PORT ) : '' );
			return ( new SwapStep() )->http( $this->host . substr( $url, strlen( $this->home ) ), $headers + array( 'Host' => $host ) );
		};
		$this->swap_parts['at']      = function ( string $point ): void {
			$this->seams[] = $point;
			$this->as_restore( $point );
		};
		$this->real_checks = true;
		$this->register_type();
		ActivePlugin::on();
	}

	/**
	 * While the swap checks the site, its job row says the restore type: the web server's WordPress answers the
	 * self-check route only for a restore (SelfCheckController::wanted()), and the test's job runs a type of its own
	 * (the swap with the test's parts). The tick under way has its steps already; the type goes back after the check.
	 *
	 * @param string $point The swap's seam.
	 * @return void
	 */
	private function as_restore( string $point ): void {
		global $wpdb;
		if ( 'self_check_written' === $point || 'verified' === $point ) {
			$wpdb->update( \WPCheckpoint\Jobs\JobRepository::table(), array( 'type' => 'self_check_written' === $point ? \WPCheckpoint\Jobs\RestoreJob::ID : $this->type ), array( 'id' => $this->swap_job->id ) );
			$wpdb->query( 'COMMIT' );
		}
	}

	public function tear_down(): void {
		ActivePlugin::off();
		$job = null === $this->swap_job ? null : Plugin::instance()->jobs()->find( $this->swap_job->id );
		if ( null !== $job && '' !== (string) ( $job->cursor['mark'] ?? $job->site_mark ) ) {
			( new Maintenance( $this->abspath, (string) ( $job->cursor['mark'] ?? $job->site_mark ) ) )->remove();
		}
		parent::tear_down();
	}

	/**
	 * WP-CLI ticks through the waits until the job is no longer queued or running.
	 *
	 * @param Job $job Job.
	 * @return Job
	 */
	private function through( Job $job ): Job {
		for ( $i = 0; $i < 60; $i++ ) {
			$this->cli_tick( $job );
			$now = Plugin::instance()->jobs()->find( $job->id );
			if ( ! in_array( $now->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				return $now;
			}
		}
		$this->fail( 'the job did not stop' );
	}

	public function test_a_restored_site_that_answers_passes_its_checks_through_the_web_server(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$done   = $this->through( $job );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error . ' ' . wp_json_encode( $done->cursor['unverified'] ?? null ) );
		$this->assertContains( 'verdict_recorded', $this->seams );
		$this->assertNotContains( 'verify_failed', $this->seams );
		$this->assertRestored( $before );
		$this->assertSame( array(), RealAbspath::contents(), 'neither file is left' );
	}

	public function test_an_answer_from_another_one_time_value_fails_at_once_and_the_swap_is_reversed(): void {
		global $wpdb;
		$before = $this->site();
		$job    = $this->at_swap();
		$this->swap_parts['at'] = function ( string $point ) use ( $wpdb ): void {
			$this->seams[] = $point;
			$this->as_restore( $point );
			if ( 'self_check_written' === $point ) {
				// What another options table would answer from: the same job and key, another one-time value.
				$table = SelfCheck::table( $wpdb->base_prefix );
				$row   = SelfCheck::parse( (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$table} WHERE option_name = %s", StoredNames::SELF_CHECK ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the options table.
				$this->assertNotNull( $row, 'the control: the row was written' );
				$other = SelfCheck::value( $row['job'], str_repeat( '9', 64 ), $row['pass'] );
				$this->assertSame( 1, $wpdb->update( $table, array( 'option_value' => $other ), array( 'option_name' => StoredNames::SELF_CHECK ) ), 'changed: ' . $wpdb->last_error );
				$wpdb->query( 'COMMIT' );
				$this->assertSame( $other, (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$table} WHERE option_name = %s", StoredNames::SELF_CHECK ) ), 'read back' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the options table.
			}
		};
		$this->register_type();
		$done = $this->through( $job );
		$this->assertSame( Job::FAILED, $done->status, wp_json_encode( $done->cursor['unverified'] ?? null ) );
		$this->assertStringContainsString( SwapStep::CHECKS['self_wrong'], (string) $done->last_error );
		$this->assertSame( $before, $this->site(), 'put back' );
		$this->assertSame( array(), RealAbspath::contents() );
	}

	public function test_a_fatal_error_page_seen_twice_fails_and_the_swap_is_reversed(): void {
		$before          = $this->site();
		$job             = $this->at_swap();
		$this->home_page = '/wp-content/plugins/wp-checkpoint/tests/Fixtures/Restore/http/fatal.php';
		$done            = $this->through( $job );
		$this->assertSame( Job::FAILED, $done->status );
		$this->assertStringContainsString( SwapStep::CHECKS['home_fatal'], (string) $done->last_error );
		$this->assertSame( $before, $this->site(), 'put back, nothing of the restored site served' );
		$this->assertNotContains( 'unheld_commit', $this->seams, 'never let out' );
		$this->assertSame( array(), RealAbspath::contents() );
	}

	public function test_the_route_missing_cannot_be_told_and_the_site_is_asked_about(): void {
		ActivePlugin::off(); // The web server's WordPress does not load the plugin: no self-check route there.
		$job  = $this->at_swap();
		$done = $this->through( $job );
		$this->assertSame( Job::PAUSED, $done->status, (string) $done->last_error );
		$this->assertSame( SwapStep::UNVERIFIED_QUESTION, $done->questions[0]['id'] ?? null );
		$this->assertSame( array( 'check' => 'self', 'why' => 'no_route', 'status' => 404 ), $done->cursor['unverified'] ?? null );
		$this->assertSame( array(), RealAbspath::contents(), 'let out while it waits for the answer' );
		Plugin::instance()->job_actions()->answer( $job->id, array( SwapStep::UNVERIFIED_QUESTION => 'keep' ) );
		$this->assertSame( Job::COMPLETED, $this->through( $job )->status );
	}
}
