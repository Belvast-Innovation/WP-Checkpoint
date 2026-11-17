<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Admin\Notices;
use WPCheckpoint\Admin\PutBackNotice;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\RestoreJob;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\ImportSession;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * The notice that the latest restore ended with the site put back, on rows real put-backs left: one for each reason
 * told of, by WP-CLI and by runs without WordPress; on every admin page, for those who may manage the plugin, with at
 * most one statement; gone once the restore is retried or a newer one is there, and not for a neighbour's; dismissed
 * for one job and one user.
 *
 * The rows are those the test's restores leave. Their type is the test's own (its swap works in a sandbox), so each
 * is given the plugin's restore type while the notice is read, and its own back after.
 */
final class PutBackNoticeTest extends SwapTestCase {

	/** @var int[] Rows the test wrote beside the restore's own. */
	private $rows = array();

	/** @var int[] Users the test made. */
	private $users = array();

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' ); // What the test left uncommitted is not committed with the removals.
		foreach ( $this->rows as $id ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . JobRepository::table() . ' WHERE id = %d', $id ) );
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->usermeta}` WHERE meta_key = %s", Notices::USER_META ) );
		foreach ( $this->users as $id ) {
			if ( is_multisite() ) {
				revoke_super_admin( $id ); // Whether or not the test granted it: the network's list names no user that is gone.
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->usermeta}` WHERE user_id = %d", $id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->users}` WHERE ID = %d", $id ) );
		}
		$wpdb->query( 'COMMIT' );
		wp_cache_flush();
		$this->rows  = array();
		$this->users = array();
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * What the admin shows on a screen, to the current user.
	 *
	 * @param string $screen Screen id.
	 * @return string
	 */
	private function shown( string $screen = 'dashboard' ): string {
		wp_cache_flush(); // Rows written past the object cache (the dismissals removed, a user's meta).
		set_current_screen( $screen );
		ob_start();
		( new Notices( $this->installation() ) )->render();
		return (string) ob_get_clean();
	}

	/**
	 * Do something while the job's row has the plugin's restore type.
	 *
	 * @param Job      $job  Job.
	 * @param callable $work function(): mixed.
	 * @return mixed
	 */
	private function as_restore( Job $job, callable $work ) {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . JobRepository::table() . ' SET type = %s WHERE id = %d', RestoreJob::ID, $job->id ) );
		$wpdb->query( 'COMMIT' );
		try {
			return $work();
		} finally {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . JobRepository::table() . ' SET type = %s WHERE id = %d', $job->type, $job->id ) );
			$wpdb->query( 'COMMIT' );
		}
	}

	/**
	 * A row beside the job's own: the job's, with the given columns otherwise.
	 *
	 * @param Job                  $job Job.
	 * @param array<string, mixed> $set Columns.
	 * @return int Its id.
	 */
	private function row_like( Job $job, array $set ): int {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$row = (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . JobRepository::table() . ' WHERE id = %d', $job->id ), ARRAY_A );
		unset( $row['id'] );
		$this->assertNotFalse( $wpdb->insert( JobRepository::table(), array_merge( $row, $set ) ), (string) $wpdb->last_error );
		$id           = (int) $wpdb->insert_id;
		$this->rows[] = $id;
		$wpdb->query( 'COMMIT' );
		return $id;
	}

	/**
	 * Assert the notice of the job's put-back is shown for the reason, with the command, and with nothing of the row's
	 * error.
	 *
	 * @param Job    $done   The job at its end.
	 * @param string $reason The reason its run recorded.
	 * @param string $what   The case.
	 * @return void
	 */
	private function assert_told( Job $done, string $reason, string $what ): void {
		$this->assertSame( Job::FAILED, $done->status, $what . ': ' . $done->last_error );
		$this->assertSame( 'reverted', $done->cursor['phase'] ?? '', $what . ': the control, put back to its end' );
		$this->assertSame( $reason, $done->cursor['reason'] ?? '', $what . ': the control, the reason the run recorded' );
		$this->assertStringContainsString( 'Step "', (string) $done->last_error, $what . ': the control, the row has an error text, of the kind looked for below' );
		$this->assertMatchesRegularExpression( '/The swap was (rolled back|reversed)/', (string) $done->last_error, $what . ': the control, and says how the swap ended, in the words looked for below' );
		wp_set_current_user( self::$admin_id );
		$out = $this->as_restore(
			$done,
			function (): string {
				return $this->shown();
			}
		);
		$this->assertStringContainsString( esc_html( PutBackNotice::sentence( $reason ) ), $out, $what );
		$this->assertStringContainsString( 'as it was before the restore', $out, $what );
		$this->assertStringContainsString( 'wp wpcheckpoint job retry ' . $done->id . '<', $out, $what . ': the one command' );
		$this->assertSame( 1, substr_count( $out, '<div class="notice ' ), $what . ': one notice, no other on a page that is not the plugin\'s' );
		$this->assertStringNotContainsString( esc_html( (string) $done->last_error ), $out, $what . ': not the row\'s error' );
		foreach ( array( 'Step &quot;', 'Step "', 'RetryFrom', 'as no WP-CLI run went on', 'The swap was rolled back', 'The swap was reversed', 'What failed' ) as $not ) {
			$this->assertStringNotContainsString( $not, $out, $what . ': nothing of the row\'s error' );
		}
		// What is read on the page (the link to dismiss it is the admin's own address, as every link there).
		$text = wp_strip_all_tags( $out );
		$this->assertStringContainsString( 'job retry ' . $done->id, $text, $what . ': the control, the text as read' );
		$this->assertStringNotContainsString( $this->sandbox, $text, $what );
		$this->assertStringNotContainsString( (string) wp_parse_url( home_url(), PHP_URL_HOST ), $text, $what );
		$this->assertSame( '', $this->shown(), $what . ': the control, with the row under the test\'s own type again there is no notice' );
	}

	/**
	 * A restore killed once two of its table batches were renamed.
	 *
	 * @return Job
	 */
	private function half(): Job {
		$this->swap_parts['batch'] = 1;
		$this->register_type();
		$job = $this->at_swap();
		$this->killed_at( $job, 'batch_sent', 2 );
		$half = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::SITE_CHANGING, $half->site_state, 'the control: the swap half made' );
		return $half;
	}

	/**
	 * WP-CLI ticks, through the waits, until the job is no longer queued or running.
	 *
	 * @param Job $job Job.
	 * @return Job
	 */
	private function through( Job $job ): Job {
		for ( $i = 0; $i < 80; $i++ ) {
			$this->cli_tick( $job );
			$now = Plugin::instance()->jobs()->find( $job->id );
			if ( ! in_array( $now->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				return $now;
			}
		}
		$this->fail( 'the job did not stop' );
	}

	public function test_a_swap_interrupted_is_told_of_put_back_by_wp_cli_or_by_a_run_without_wordpress(): void {
		$done = $this->cli_run( $this->half() );
		$this->assertStringNotContainsString( 'as no WP-CLI run went on', (string) $done->last_error, 'the control: WP-CLI put it back' );
		$this->assert_told( $done, SwapStep::INTERRUPTED, 'by WP-CLI' );

		$this->tear_down_swap();
		$this->set_up_swap();
		list( $done, $runs ) = $this->run_without_wordpress( $this->half() );
		$this->assertNotSame( TickResult::LEFT, end( $runs )->status, 'not left to WP-CLI: ' . end( $runs )->message );
		$this->assertStringContainsString( 'as no WP-CLI run went on', (string) $done->last_error, 'the control: a run without WordPress put it back' );
		$this->assert_told( $done, SwapStep::INTERRUPTED, 'by a run without WordPress' );
	}

	public function test_a_swap_no_wp_cli_run_went_on_with_is_told_of_put_back_by_a_run_without_wordpress_or_ended_by_wp_cli(): void {
		$job = $this->at_swap();
		$this->killed_at( $job, 'committed' );
		$committed = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 'committed', $committed->cursor['phase'] ?? '', 'the control: the commit recorded' );
		list( $done, $runs ) = $this->run_without_wordpress( $committed );
		$this->assertNotSame( TickResult::LEFT, end( $runs )->status, 'not left to WP-CLI: ' . end( $runs )->message );
		$this->assert_told( $done, SwapStep::ABANDONED, 'by a run without WordPress' );
		$out = $this->as_restore(
			$done,
			function (): string {
				return $this->shown();
			}
		);
		$this->assertStringContainsString( 'visitor', $out, 'this one says a visitor\'s request did it' );

		$this->tear_down_swap();
		$this->set_up_swap();
		$job = $this->at_swap();
		$this->killed_at( $job, 'committed' );
		$this->killed_at( Plugin::instance()->jobs()->find( $job->id ), 'table_back', 1, true );
		$half = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 'reverse', $half->cursor['phase'] ?? '', 'the control: a run without WordPress began to put it back' );
		$done = $this->cli_run( $half );
		$this->assertStringNotContainsString( 'as no WP-CLI run went on', (string) $done->last_error, 'the control: WP-CLI ended it' );
		$this->assert_told( $done, SwapStep::ABANDONED, 'ended by WP-CLI' );
	}

	public function test_a_restored_site_that_failed_its_check_is_told_of(): void {
		$this->verdicts             = array( 'home' );
		$this->swap_parts['verify'] = $this->verdicts();
		$this->register_type();
		$done = $this->cli_run( $this->at_swap() );
		$this->assert_told( $done, SwapStep::REVERSED, 'reversed' );
	}

	public function test_a_swap_undone_as_chosen_when_the_site_could_not_be_checked_is_told_of(): void {
		$job                        = $this->at_swap( array( 'policy' => array( 'unverified' => 'undo' ) ) );
		$this->swap_parts['verify'] = static function (): array {
			return array(
				'verdict' => 'unknown',
				'check'   => 'home',
				'why'     => 'status',
				'status'  => 404,
			);
		};
		$this->retype( $job );
		$done = $this->through( $job );
		$this->assert_told( $done, SwapStep::UNDONE, 'undone' );
	}

	public function test_a_swap_that_stopped_is_told_of(): void {
		$job  = $this->at_swap();
		$plan = $this->plan_of( $job );
		$last = $plan['dirs'][ count( $plan['dirs'] ) - 1 ];
		// The last unit's staged copy is gone: its rename fails after every other unit was swapped.
		rename( $last['stage'], $last['stage'] . '-away' );
		try {
			$done = $this->cli_run( $job );
		} finally {
			rename( $last['stage'] . '-away', $last['stage'] );
		}
		$this->assert_told( $done, SwapStep::STOPPED, 'stopped' );
	}

	public function test_a_swap_the_database_was_too_busy_for_is_told_of(): void {
		global $wpdb;
		$job                       = $this->at_swap();
		$this->swap_parts['sleep'] = static function ( int $seconds ): void {}; // The waits between the tries: none.
		$this->retype( $job );
		// Another connection holds a lock on a live table the swap renames, until the swap gave up.
		$other = ImportSession::open( Credentials::from_wordpress() );
		$other->run( 'LOCK TABLES `' . $wpdb->prefix . 'swt_keep` READ' );
		try {
			$now = $this->cli_run( $job );
		} finally {
			$other->run( 'UNLOCK TABLES' );
			$other->close();
		}
		$this->assertSame( SwapStep::BUSY, $now->cursor['reason'] ?? '', 'the control: given up as busy: ' . $now->status . ' ' . $now->last_error );
		$done = in_array( $now->status, array( Job::QUEUED, Job::RUNNING ), true ) ? $this->through( $now ) : $now;
		$this->assert_told( $done, SwapStep::BUSY, 'busy' );
	}

	public function test_a_swap_whose_tables_were_not_all_in_place_is_told_of(): void {
		global $wpdb;
		$live  = $wpdb->prefix . 'swt_keep';
		$away  = $wpdb->prefix . 'swt_keep_taken';
		$moved = false;
		// Once every rename went through, another connection takes one of the restored tables from its place.
		$this->swap_parts['at'] = function ( string $point ) use ( $live, $away, &$moved ): void {
			$this->seams[] = $point;
			if ( 'batch_sent' === $point && ! $moved ) {
				$other = ImportSession::open( Credentials::from_wordpress() );
				$other->run( "RENAME TABLE `{$live}` TO `{$away}`" );
				$other->close();
				$moved = true;
			}
		};
		$this->register_type();
		$job = $this->at_swap();
		try {
			$done = $this->cli_run( $job );
		} finally {
			$other = ImportSession::open( Credentials::from_wordpress() );
			$other->run( "DROP TABLE IF EXISTS `{$away}`" );
			$other->close();
		}
		$this->assertTrue( $moved, 'the control: the table was taken' );
		$this->assertSame( 1, count( array_keys( $this->seams, 'batch_sent', true ) ), 'the control: one batch, so it was taken after every rename' );
		$this->assert_told( $done, SwapStep::INCOMPLETE, 'incomplete' );
	}

	public function test_it_is_on_every_admin_page_for_those_who_may_manage_the_plugin_with_one_statement_at_most(): void {
		global $wpdb;
		$done          = $this->cli_run( $this->half() );
		$subscriber    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) ); // On a network: of a site, not of the network.
		$this->users   = array( $subscriber, $administrator );
		$wpdb->query( 'COMMIT' );
		$asked = array();
		$count = static function ( $sql ) use ( &$asked ) {
			$asked[] = (string) $sql;
			return $sql;
		};
		$this->as_restore(
			$done,
			function () use ( $done, $subscriber, $administrator, $count, &$asked ): void {
				$sentence = esc_html( PutBackNotice::sentence( SwapStep::INTERRUPTED ) );
				wp_set_current_user( self::$admin_id );
				foreach ( array( 'dashboard', 'plugins', 'edit-post', 'toplevel_page_wp-checkpoint' ) as $screen ) {
					$this->assertStringContainsString( $sentence, $this->shown( $screen ), $screen );
				}
				if ( is_multisite() ) {
					foreach ( array( 'dashboard-network', 'sites-network', 'toplevel_page_wp-checkpoint-network' ) as $screen ) {
						$this->assertStringContainsString( $sentence, $this->shown( $screen ), $screen );
					}
				}

				// The statements of one page that is not the plugin's, for one who may manage it: one, on the jobs table.
				$this->shown(); // Whatever is read once and kept (the user, the options) is read.
				set_current_screen( 'dashboard' );
				add_filter( 'query', $count );
				ob_start();
				( new Notices( $this->installation() ) )->render();
				$out = (string) ob_get_clean();
				remove_filter( 'query', $count );
				$this->assertStringContainsString( $sentence, $out, 'the control: shown by the page whose statements were counted' );
				$this->assertCount( 1, $asked, "one statement:\n" . implode( "\n", $asked ) );
				$this->assertStringContainsString( JobRepository::table(), $asked[0], 'the control: the count sees the statement on the jobs table' );
				$this->assertStringContainsString( ' ORDER BY id DESC LIMIT 1', $asked[0] );
				$this->assertStringContainsString( "type = 'restore' AND " . Job::MANAGING_SQL . ' IN (', $asked[0], 'who manages it is part of the statement' );
				// Which index the server takes on a table of a few rows says little: only that the one on the type is there for it.
				$plan = $GLOBALS['wpdb']->get_results( 'EXPLAIN ' . $asked[0], ARRAY_A );
				$this->assertContains( 'type', array_map( 'trim', explode( ',', (string) ( $plan[0]['possible_keys'] ?? '' ) ) ), 'the index on the type serves it: ' . wp_json_encode( $plan ) );

				// One who may not manage the plugin: no notice, and nothing read.
				foreach ( array( $subscriber, is_multisite() ? $administrator : 0, 0 ) as $user ) {
					if ( is_multisite() || $user !== $administrator ) {
						wp_set_current_user( $user );
						get_userdata( $user ); // Read before the count, as for the one who may.
						current_user_can( 'manage_options' );
						$asked = array();
						set_current_screen( 'dashboard' );
						add_filter( 'query', $count );
						ob_start();
						( new Notices( $this->installation() ) )->render();
						$none = (string) ob_get_clean();
						remove_filter( 'query', $count );
						$this->assertSame( '', $none, 'user ' . $user );
						$this->assertSame( array(), $asked, 'user ' . $user . ': nothing is read' );
						$this->assertSame( '', $this->shown( 'toplevel_page_wp-checkpoint' ), 'user ' . $user . ': nor on the plugin\'s page' );
					}
				}
				if ( ! is_multisite() ) {
					wp_set_current_user( $administrator );
					$this->assertStringContainsString( $sentence, $this->shown(), 'the control: another administrator of a single site is shown it' );
				}
				wp_set_current_user( self::$admin_id );
			}
		);
	}

	public function test_a_page_that_is_not_the_plugins_does_not_resolve_the_storage_directories_to_tell(): void {
		$done = $this->cli_run( $this->half() );
		wp_set_current_user( self::$admin_id );
		$this->as_restore(
			$done,
			function (): void {
				$dirs     = new \WPCheckpoint\Support\Directories();
				$resolved = static function () use ( $dirs ): bool {
					$base = new \ReflectionProperty( $dirs, 'base' );
					$base->setAccessible( true );
					return null !== $base->getValue( $dirs );
				};
				$notices  = new Notices( $dirs );
				wp_cache_flush();
				set_current_screen( 'dashboard' );
				ob_start();
				$notices->render();
				$out = (string) ob_get_clean();
				$this->assertStringContainsString( esc_html( PutBackNotice::sentence( SwapStep::INTERRUPTED ) ), $out, 'the control: the notice was shown' );
				$this->assertFalse( $resolved(), 'the dashboard did not resolve the storage directories' );
				set_current_screen( 'toplevel_page_wp-checkpoint' );
				ob_start();
				$notices->render();
				ob_get_clean();
				$this->assertTrue( $resolved(), 'the control: the plugin\'s page does, and that is seen' );
			}
		);
	}

	public function test_it_is_not_shown_on_a_site_whose_stored_state_was_taken_up_in_another_wordpress_directory(): void {
		$done = $this->cli_run( $this->half() );
		wp_set_current_user( self::$admin_id );
		$this->as_restore(
			$done,
			function (): void {
				$option = \WPCheckpoint\Support\Directories::OPTION;
				$state  = (array) \WPCheckpoint\Support\Options::get( $option, array() );
				$this->assertNotSame( '', (string) ( $state['abspath'] ?? '' ), 'the control: the state records where it was taken up' );
				$this->assertNotSame( '', $this->shown(), 'the control: shown' );
				$said = function (): array {
					return ( new Notices( $this->installation() ) )->everywhere();
				};
				$else = $this->sandbox . '/another-wordpress';
				$loop = $this->sandbox . '/loop';
				mkdir( $else );
				try {
					// A copied site no request has detected yet: the original's state, tokens and job rows.
					\WPCheckpoint\Support\Options::set(
						$option,
						array(
							'abspath'      => $else . '/',
							'abspath_real' => (string) realpath( $else ),
						) + $state
					);
					$this->assertSame( array(), $said(), 'the state of another WordPress directory: its jobs are not said to be this site\'s' );
					\WPCheckpoint\Support\Options::set(
						$option,
						array(
							'abspath'      => $this->sandbox . '/gone/',
							'abspath_real' => '',
						) + $state
					);
					$this->assertSame( array(), $said(), 'a recorded directory that is not there' );
					// A recorded directory that cannot be looked at from here (through a link to itself: it does not resolve,
					// and is not shown to be gone): whether it is this one cannot be told.
					$this->assertTrue( symlink( 'loop', $loop ) );
					$this->assertFalse( realpath( $loop . '/wordpress' ), 'the control: it does not resolve' );
					$this->assertFalse( \WPCheckpoint\Support\Paths::positively_gone( $loop . '/wordpress' ), 'the control: nor is it shown to be gone' );
					\WPCheckpoint\Support\Options::set(
						$option,
						array(
							'abspath'      => $loop . '/wordpress/',
							'abspath_real' => '',
						) + $state
					);
					$this->assertSame( array(), $said(), 'what cannot be told is not taken as this directory' );
					\WPCheckpoint\Support\Options::set( $option, array( 'abspath' => '' ) + $state );
					$this->assertSame( array(), $said(), 'state that never took a directory up' );
					// This directory under another spelling is this directory.
					\WPCheckpoint\Support\Options::set(
						$option,
						array(
							'abspath'      => rtrim( ABSPATH, '/' ) . '/wp-includes/../',
							'abspath_real' => '',
						) + $state
					);
					$this->assertNotSame( array(), $said(), 'the same directory, spelled otherwise' );
					// Recorded under a spelling that says nothing here, with the directory it resolved to then: this one.
					\WPCheckpoint\Support\Options::set(
						$option,
						array(
							'abspath'      => $this->sandbox . '/gone/',
							'abspath_real' => (string) realpath( ABSPATH ),
						) + $state
					);
					$this->assertNotSame( array(), $said(), 'the directory it resolved to when recorded is this one' );
				} finally {
					\WPCheckpoint\Support\Options::set( $option, $state );
					\WPCheckpoint\Tests\Fixtures\Sandbox::remove( $else );
					\WPCheckpoint\Tests\Fixtures\Sandbox::remove( $loop );
				}
				$this->assertNotSame( array(), $said(), 'the control: shown again with the state as it was' );
			}
		);
	}

	public function test_it_is_not_shown_while_a_detected_copy_is_not_resolved(): void {
		$done = $this->cli_run( $this->half() );
		wp_set_current_user( self::$admin_id );
		$this->as_restore(
			$done,
			function (): void {
				$this->assertNotSame( '', $this->shown(), 'the control: shown' );
				$state = \WPCheckpoint\Support\Options::get( \WPCheckpoint\Support\Directories::OPTION, array() );
				\WPCheckpoint\Support\Options::set( \WPCheckpoint\Support\Directories::OPTION, array( 'clone_detected' => true ) + (array) $state );
				try {
					$this->assertSame( array(), ( new Notices( $this->installation() ) )->everywhere(), 'whose the jobs are is not known: nothing is said' );
				} finally {
					\WPCheckpoint\Support\Options::set( \WPCheckpoint\Support\Directories::OPTION, $state );
				}
			}
		);
	}

	public function test_it_is_gone_once_the_restore_is_retried_or_a_newer_one_is_there_and_a_neighbours_does_not_hide_it(): void {
		$done     = $this->cli_run( $this->half() );
		$sentence = esc_html( PutBackNotice::sentence( SwapStep::INTERRUPTED ) );
		wp_set_current_user( self::$admin_id );
		$show = function (): string {
			return $this->shown();
		};
		$this->assertStringContainsString( $sentence, $this->as_restore( $done, $show ), 'the control: shown' );

		// A newer restore of an installation that shares the database (another storage token): not this one's.
		$this->row_like(
			$done,
			array(
				'type'          => RestoreJob::ID,
				'status'        => Job::COMPLETED,
				'storage_token' => str_repeat( 'b', 32 ),
				'held_by'       => '',
				'cursor_json'   => '[]',
			)
		);
		$this->assertStringContainsString( $sentence, $this->as_restore( $done, $show ), 'a neighbour\'s newer restore does not hide it' );
		// One this installation took over from another (held_by) is its own: the newest, and not put back.
		$taken = $this->row_like(
			$done,
			array(
				'type'          => RestoreJob::ID,
				'status'        => Job::QUEUED,
				'storage_token' => str_repeat( 'c', 32 ),
				'held_by'       => $done->storage_token,
				'cursor_json'   => '[]',
			)
		);
		$this->assertSame( '', $this->as_restore( $done, $show ), 'a newer restore this installation manages: no longer the latest' );
		$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'DELETE FROM ' . JobRepository::table() . ' WHERE id = %d', $taken ) );
		$this->assertStringContainsString( $sentence, $this->as_restore( $done, $show ), 'the control: shown again without it' );

		// What an administrator asked for is not told of: the same row, its reason otherwise.
		foreach ( array( SwapStep::ASKED, SwapStep::PUT_BACK_REASON ) as $reason ) {
			$asked = $this->row_like(
				$done,
				array(
					'type'        => RestoreJob::ID,
					'cursor_json' => (string) wp_json_encode( array( 'reason' => $reason ) + $done->cursor ),
				)
			);
			$this->assertSame( '', $this->shown(), $reason . ': not told of' );
			$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'UPDATE ' . JobRepository::table() . ' SET cursor_json = %s WHERE id = %d', (string) wp_json_encode( array( 'reason' => SwapStep::BUSY ) + $done->cursor ), $asked ) );
			$this->assertStringContainsString( esc_html( PutBackNotice::sentence( SwapStep::BUSY ) ), $this->shown(), $reason . ': the control, the same row with a reason told of' );
			$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'DELETE FROM ' . JobRepository::table() . ' WHERE id = %d', $asked ) );
		}

		// A put-back after the restored site was let out, or of an undone restore swapped in again: an undo, not told of.
		foreach ( array( 'out' => true, 'undone_at' => 1700000000 ) as $key => $value ) {
			$undo = $this->row_like(
				$done,
				array(
					'type'        => RestoreJob::ID,
					'cursor_json' => (string) wp_json_encode( array( $key => $value, 'reason' => SwapStep::UNDONE ) + $done->cursor ),
				)
			);
			$this->assertSame( '', $this->shown(), $key . ': not told of' );
			$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'UPDATE ' . JobRepository::table() . ' SET cursor_json = %s WHERE id = %d', (string) wp_json_encode( array( 'reason' => SwapStep::UNDONE ) + $done->cursor ), $undo ) );
			$this->assertStringContainsString( esc_html( PutBackNotice::sentence( SwapStep::UNDONE ) ), $this->shown(), $key . ': the control, the same row without it' );
			$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'DELETE FROM ' . JobRepository::table() . ' WHERE id = %d', $undo ) );
		}

		// A restore that failed while it was still being put back: the site is not said to be as it was.
		$mid = $this->row_like(
			$done,
			array(
				'type'        => RestoreJob::ID,
				'cursor_json' => (string) wp_json_encode( array( 'phase' => 'rollback' ) + $done->cursor ),
			)
		);
		$this->assertSame( '', $this->shown(), 'not put back to its end: not told of' );
		$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'UPDATE ' . JobRepository::table() . ' SET cursor_json = %s WHERE id = %d', (string) wp_json_encode( $done->cursor ), $mid ) );
		$this->assertStringContainsString( $sentence, $this->shown(), 'the control: the same row at the end of its put-back' );
		$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'DELETE FROM ' . JobRepository::table() . ' WHERE id = %d', $mid ) );

		// Retried: gone while it runs, and once it succeeded.
		$this->assertNotNull( Plugin::instance()->job_actions()->retry( $done->id ) );
		$queued = Plugin::instance()->jobs()->find( $done->id );
		$this->assertSame( Job::QUEUED, $queued->status, 'the control: retried' );
		$this->assertSame( '', $this->as_restore( $queued, $show ), 'retried: gone' );
		$again = $this->cli_run( $queued );
		$this->assertSame( Job::COMPLETED, $again->status, 'the control: the retry succeeded: ' . $again->last_error );
		$this->assertSame( '', $this->as_restore( $again, $show ), 'the restore succeeded: gone' );
	}

	public function test_it_is_dismissed_for_one_failure_of_a_job_and_one_user_under_one_key(): void {
		$done     = $this->cli_run( $this->half() );
		$sentence = esc_html( PutBackNotice::sentence( SwapStep::INTERRUPTED ) );
		$other    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $other );
		}
		$this->users = array( $other );
		$GLOBALS['wpdb']->query( 'COMMIT' );
		$this->as_restore(
			$done,
			function () use ( $done, $sentence, $other ): void {
				wp_set_current_user( self::$admin_id );
				$notices = new Notices( $this->installation() );
				$this->assertStringContainsString( $sentence, $this->shown(), 'the control: shown' );
				$notices->record_dismissal( PutBackNotice::ID );
				$this->assertGreaterThan( 0, $done->finished_at, 'the control: the failure has a time' );
				$this->assertSame( array( PutBackNotice::ID => $done->id . ':' . $done->finished_at ), get_user_meta( self::$admin_id, Notices::USER_META, true ), 'one key, the job and the time its failure ended as its value' );
				$this->assertSame( '', $this->shown(), 'dismissed: not shown for the same failure' );
				$this->assertStringNotContainsString( $sentence, $this->shown( 'toplevel_page_wp-checkpoint' ), 'nor on the plugin\'s page' );

				wp_set_current_user( $other );
				$this->assertStringContainsString( $sentence, $this->shown(), 'another user is still shown it' );

				// The next restore put back: shown again to the one who dismissed the first, and dismissed under the same key.
				wp_set_current_user( self::$admin_id );
				$next = $this->row_like( $done, array( 'cursor_json' => (string) wp_json_encode( array( 'reason' => SwapStep::REVERSED ) + $done->cursor ) ) );
				$this->assertStringContainsString( esc_html( PutBackNotice::sentence( SwapStep::REVERSED ) ), $this->shown(), 'the next job put back is shown' );
				$this->assertStringContainsString( 'wp wpcheckpoint job retry ' . $next . '<', $this->shown() );
				$notices->record_dismissal( PutBackNotice::ID );
				$this->assertSame( array( PutBackNotice::ID => $next . ':' . $done->finished_at ), get_user_meta( self::$admin_id, Notices::USER_META, true ), 'the same key, no key more' );
				$this->assertSame( '', $this->shown() );
				$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'DELETE FROM ' . JobRepository::table() . ' WHERE id = %d', $next ) );
				$GLOBALS['wpdb']->query( 'COMMIT' );
				$this->assertStringContainsString( $sentence, $this->shown(), 'the control: the first job\'s is the latest again, and its dismissal was written over' );
				$notices->record_dismissal( PutBackNotice::ID );
				$this->assertSame( '', $this->shown(), 'the control: dismissed for the first job\'s failure again' );
			}
		);

		// Retried, and put back once more: the same job, another failure. Shown again to the one who dismissed the first.
		while ( Plugin::instance()->jobs()->now() <= $done->finished_at ) {
			usleep( 200000 ); // The failures are told apart by the second they ended in.
		}
		$this->assertNotNull( Plugin::instance()->job_actions()->retry( $done->id ) );
		$queued = Plugin::instance()->jobs()->find( $done->id );
		$this->assertSame( Job::QUEUED, $queued->status, 'the control: retried' );
		// Up to the swap again as a request drives it (the retry begins at the check before the swap), then killed in it.
		$runner = Plugin::instance()->runner();
		for ( $i = 0; $i < 200; $i++ ) {
			$result = $this->autocommit(
				static function () use ( $runner, $queued ): TickResult {
					return $runner->tick( $queued->id, microtime( true ) );
				}
			);
			if ( TickResult::CLI === $result->status || ! in_array( Plugin::instance()->jobs()->find( $done->id )->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				break;
			}
		}
		$GLOBALS['wpdb']->query( 'COMMIT' );
		$queued = Plugin::instance()->jobs()->find( $done->id );
		$this->assertSame( SwapStep::ID, $queued->step, 'the control: the retry reached the swap: ' . $queued->status . ' ' . $queued->last_error );
		$this->assertContains( $queued->status, array( Job::QUEUED, Job::RUNNING ) );
		$this->killed_at( $queued, 'batch_sent', 2 );
		$again = $this->cli_run( Plugin::instance()->jobs()->find( $done->id ) );
		$this->assertSame( $done->id, $again->id, 'the control: the same job' );
		$this->assertSame( Job::FAILED, $again->status, 'the control: put back once more: ' . $again->last_error );
		$this->assertSame( 'reverted', $again->cursor['phase'] ?? '' );
		$this->assertSame( SwapStep::INTERRUPTED, $again->cursor['reason'] ?? '' );
		$this->assertGreaterThan( $done->finished_at, $again->finished_at, 'the control: this failure ended later' );
		$this->as_restore(
			$again,
			function () use ( $done, $again, $sentence, $other ): void {
				wp_set_current_user( self::$admin_id );
				$this->assertSame( array( PutBackNotice::ID => $done->id . ':' . $done->finished_at ), get_user_meta( self::$admin_id, Notices::USER_META, true ), 'the control: the dismissal of the first failure is still there' );
				$this->assertStringContainsString( $sentence, $this->shown(), 'the same job put back once more: shown again to the one who dismissed its first failure' );
				$this->assertStringContainsString( $sentence, $this->shown( 'toplevel_page_wp-checkpoint' ), 'and on the plugin\'s page' );
				wp_set_current_user( $other );
				$this->assertStringContainsString( $sentence, $this->shown(), 'another user, who dismissed nothing, is shown it as before' );
				( new Notices( $this->installation() ) )->record_dismissal( PutBackNotice::ID );
				$this->assertSame( '', $this->shown(), 'dismissed by the other user' );
				$this->assertSame( array( PutBackNotice::ID => $again->id . ':' . $again->finished_at ), get_user_meta( $other, Notices::USER_META, true ) );
				wp_set_current_user( self::$admin_id );
				$this->assertStringContainsString( $sentence, $this->shown(), 'which does not dismiss it for the first' );
			}
		);
	}
}
