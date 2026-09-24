<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Admin\Tabs\BackupsTab;
use WPCheckpoint\Backups\BackupStore;
use WPCheckpoint\Jobs\EstimateJob;
use WPCheckpoint\Jobs\ExportPlan;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Archive\ArchiveBuilder;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * The Backups tab as the server renders it: the empty state (no job
 * started by a page load), the list, one backup's details, the badges,
 * and the jobs block (the plugin's own estimate not among them, a stalled
 * job said to be stalled only on evidence).
 */
final class BackupsTabTest extends JobTestCase {

	/** @var ArchiveBuilder|null */
	private $builder;

	/** @var string */
	private $backups;

	public function set_up(): void {
		parent::set_up();
		$this->backups = Plugin::instance()->directories()->backups();
		$_GET          = array();
	}

	public function tear_down(): void {
		if ( null !== $this->builder ) {
			$this->builder->cleanup();
			$this->builder = null;
		}
		$_GET = array();
		parent::tear_down();
	}

	private function html( array $query = array() ): string {
		$_GET = $query;
		ob_start();
		( new BackupsTab() )->render();
		return (string) ob_get_clean();
	}

	private function add_backup( array $warnings = array(), string $finished = '2026-09-18T10:04:07Z' ): void {
		$this->builder = ( new ArchiveBuilder(
			array(
				'manifest' => static function ( array $manifest, bool $embedded ) use ( $warnings, $finished ): array {
					$manifest['warnings']             = $warnings;
					$manifest['database']['exported'] = array(
						'started_at'  => '2026-09-18T10:00:05Z',
						'finished_at' => $finished,
					);
					return $manifest;
				},
			)
		) )->typical()->build();
		foreach ( array_merge( $this->builder->volumes, array( $this->builder->manifest_path ) ) as $file ) {
			copy( $file, $this->backups . '/' . basename( $file ) );
		}
	}

	public function test_before_the_first_backup_the_screen_says_what_one_would_hold_and_starts_nothing(): void {
		$html = $this->html();
		$this->assertStringContainsString( 'No backups yet', $html );
		$this->assertStringContainsString( 'Create your first backup', $html );
		$this->assertStringContainsString( 'data-wpcheckpoint-estimate data-state="due"', $html, 'the script starts the estimate, not the page load' );
		$this->assertMatchesRegularExpression( '/Database: about [0-9.,]+ [KMG]?B/', $html );
		$this->assertSame( array(), Plugin::instance()->jobs()->list_jobs(), 'a GET never starts a job' );
	}

	public function test_the_list_shows_each_backup_with_its_check_and_actions(): void {
		$this->add_backup();
		$html = $this->html();
		$this->assertStringNotContainsString( 'No backups yet', $html );
		$this->assertStringContainsString( 'data-backup="' . ArchiveBuilder::BASE . '"', $html );
		$this->assertStringContainsString( 'Not checked yet.', $html );
		$this->assertStringContainsString( 'data-backup-action="verify"', $html );
		$this->assertStringContainsString( 'data-backup-action="delete"', $html );
		$this->assertStringContainsString( 'backup=' . ArchiveBuilder::BASE, $html );
	}

	public function test_a_backup_in_use_or_partly_deleted_says_so_and_cannot_be_checked(): void {
		$this->add_backup();
		$restore = Plugin::instance()->jobs()->create( 'restore', self::$admin_id, array(), array( 'base' => ArchiveBuilder::BASE ) );
		$html    = $this->html();
		$this->assertStringContainsString( sprintf( 'This backup is being restored (job %d).', $restore->id ), $html );
		$this->assertMatchesRegularExpression( '/data-backup-action="verify" data-base="' . ArchiveBuilder::BASE . '"\s+disabled/', $html );
		$this->assertStringContainsString( 'disabled', $this->html( array( 'backup' => ArchiveBuilder::BASE ) ) );
		$details = $this->html( array( 'backup' => ArchiveBuilder::BASE ) );
		$this->assertStringNotContainsString( 'wpcheckpoint_download', $details, 'no download links while it is being restored' );

		Plugin::instance()->job_actions()->cancel( $restore->id );
		file_put_contents( $this->backups . '/' . ArchiveBuilder::BASE . BackupStore::DELETING_SUFFIX, '' );
		$this->assertStringContainsString( 'The last delete did not finish: delete it again.', $this->html() );
	}

	public function test_details_say_what_the_backup_holds_and_from_when_without_naming_the_site(): void {
		$this->add_backup( array( 'A <script>alert(1)</script> warning about ' . ABSPATH . 'wp-config.php' ) );
		$html = $this->html( array( 'backup' => ArchiveBuilder::BASE ) );
		$this->assertStringContainsString( 'Database: each table as it was when its export started', $html );
		$this->assertStringContainsString( 'Files: scanned and packed while the backup was made', $html );
		$this->assertStringContainsString( 'What a backup contains, and when', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'manifest text is escaped' );
		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringNotContainsString( rtrim( ABSPATH, '/' ), $html, 'paths from the manifest are masked' );
		$this->assertStringNotContainsString( 'example.com', $html, 'the source site is not named' );
		$this->assertStringContainsString( 'Download every file listed here', $html );
		foreach ( $this->builder->volumes as $volume ) {
			$this->assertStringContainsString( 'file=backups/' . basename( $volume ), html_entity_decode( $html ) );
		}
		$this->assertStringContainsString( 'Checking downloaded volumes by hand', $html, 'the large volume says how to check it by blocks' );
		$this->assertStringContainsString( hash_file( 'sha256', end( $this->builder->volumes ) ), $html, 'the small volume shows its SHA-256' );
	}

	public function test_the_plugins_own_estimate_is_not_among_the_jobs(): void {
		$this->add_backup();
		$estimate = Plugin::instance()->jobs()->create( EstimateJob::ID, self::$admin_id );
		$export   = Plugin::instance()->jobs()->create( 'export', self::$admin_id );
		$html     = $this->html();
		$this->assertStringContainsString( 'data-wpcheckpoint-job="' . $export->id . '"', $html, 'the user\'s job is shown' );
		$this->assertStringNotContainsString( 'data-wpcheckpoint-job="' . $estimate->id . '"', $html );
	}

	public function test_a_job_is_said_to_be_stalled_only_once_it_actually_stood_still(): void {
		global $wpdb;
		$this->add_backup();
		$fresh = Plugin::instance()->jobs()->create( 'export', self::$admin_id );
		$html  = $this->html();
		$this->assertStringContainsString( 'data-wpcheckpoint-job="' . $fresh->id . '"', $html );
		$this->assertStringNotContainsString( 'has not moved for', $html, 'a job that has just started is not stalled' );
		// Eleven minutes without a move, nothing holding it, nothing waiting for a person.
		$wpdb->update( Schema::jobs_table(), array( 'created_at' => time() - 660, 'updated_at' => time() - 660, 'progress_at' => time() - 660 ), array( 'id' => $fresh->id ) );
		$this->assertStringContainsString( 'This job has not moved for 11 minutes.', $this->html() );
		// Held by a driver: not stalled, whatever the clock says.
		$wpdb->update( Schema::jobs_table(), array( 'lock_token' => str_repeat( 'a', 32 ), 'locked_until' => time() + 60 ), array( 'id' => $fresh->id ) );
		$this->assertStringNotContainsString( 'has not moved for', $this->html() );
	}

	private function job_with_status( string $status, int $finished_ago = 0 ): Job {
		global $wpdb;
		$job = Plugin::instance()->jobs()->create( 'export', self::$admin_id );
		$finished = time() - $finished_ago;
		// The kind is stored stamped with the failure's time (Job::read_failure_kind()).
		$wpdb->update( Schema::jobs_table(), array( 'status' => $status, 'finished_at' => $finished, 'failure_kind' => Job::FAILED === $status ? Job::FAILURE_FINAL . ':' . $finished : '' ), array( 'id' => $job->id ) );
		return Plugin::instance()->jobs()->find( $job->id );
	}

	public function test_a_finished_export_is_not_among_the_jobs_and_the_list_highlights_its_backup(): void {
		$this->add_backup();
		$done = $this->job_with_status( Job::COMPLETED );
		\WPCheckpoint\Backups\ExportResults::record( $done->id, ArchiveBuilder::BASE );
		$html = $this->html( array( 'job' => (string) $done->id ) );
		$this->assertStringNotContainsString( 'data-wpcheckpoint-job="' . $done->id . '"', $html, 'its backup is in the list: that is where it belongs' );
		$this->assertStringContainsString( 'class="wpcheckpoint-highlight"', $html );
		$this->assertStringContainsString( '>New<', $html );
		$this->assertStringNotContainsString( 'class="wpcheckpoint-highlight"', $this->html(), 'only right after the export' );

		// Stored before the results were kept: the job's plan names it while its work directory is there.
		$older = $this->job_with_status( Job::COMPLETED );
		$work  = Residue::work_dir( $older->storage_path, $older->id );
		wp_mkdir_p( $work );
		ExportPlan::write( $work, ExportPlan::PLAN, array( 'base' => ArchiveBuilder::BASE ) );
		$this->assertStringContainsString( 'class="wpcheckpoint-highlight"', $this->html( array( 'job' => (string) $older->id ) ) );
	}

	public function test_only_unfinished_jobs_and_failures_within_a_week_are_shown(): void {
		$this->add_backup();
		$running   = $this->job_with_status( Job::RUNNING );
		$failed    = $this->job_with_status( Job::FAILED, 3600 );
		$old       = $this->job_with_status( Job::FAILED, 8 * 86400 );
		$cancelled = $this->job_with_status( Job::CANCELLED );
		$html      = $this->html();
		$this->assertStringContainsString( 'data-wpcheckpoint-job="' . $running->id . '"', $html );
		$this->assertStringContainsString( 'data-wpcheckpoint-job="' . $failed->id . '"', $html, 'a failure stays until it is dismissed' );
		$this->assertStringNotContainsString( 'data-wpcheckpoint-job="' . $old->id . '"', $html, 'its work files are gone after a week' );
		$this->assertStringNotContainsString( 'data-wpcheckpoint-job="' . $cancelled->id . '"', $html );
	}

	public function test_the_first_backup_screen_explains_first_and_steps_aside_while_it_is_made(): void {
		$html = $this->html();
		$this->assertLessThan( strpos( $html, 'Create your first backup' ), strpos( $html, 'No backups yet' ), 'what a backup is and holds, then the button' );
		$this->assertLessThan( strpos( $html, 'Create your first backup' ), strpos( $html, 'Files: counting…' ) );
		$this->assertStringContainsString( 'spinner is-active', $html );

		$export = Plugin::instance()->jobs()->create( 'export', self::$admin_id );
		$busy   = $this->html();
		$this->assertStringContainsString( 'data-wpcheckpoint-job="' . $export->id . '"', $busy );
		$this->assertStringNotContainsString( 'No backups yet', $busy, 'while the first backup is made, its block is the screen' );
		$this->assertStringNotContainsString( 'data-wpcheckpoint-create', $busy );

		$this->add_backup();
		$list = $this->html();
		$this->assertStringContainsString( 'A backup is being made. You can start another one when it has finished.', $list );
		$this->assertStringNotContainsString( '(job ', $list, 'no job numbers on the screen' );
	}

	public function test_the_export_period_is_shown_to_the_second_and_as_one_time_when_it_took_under_one(): void {
		$this->add_backup();
		$between = $this->html( array( 'backup' => ArchiveBuilder::BASE ) );
		$this->assertMatchesRegularExpression( '/between [^<]*10:00:05[^<]* and [^<]*10:04:07/', $between );
		$this->builder->cleanup();
		foreach ( glob( $this->backups . '/' . ArchiveBuilder::BASE . '.*' ) as $file ) {
			unlink( $file );
		}
		$this->add_backup( array(), '2026-09-18T10:00:05Z' );
		$at = $this->html( array( 'backup' => ArchiveBuilder::BASE ) );
		$this->assertMatchesRegularExpression( '/when its export started, at [^<]*10:00:05/', $at );
		$this->assertStringNotContainsString( 'between', $at );
	}

	public function test_the_results_keep_the_last_exports_only(): void {
		for ( $job = 1; $job <= \WPCheckpoint\Backups\ExportResults::MAX + 5; $job++ ) {
			\WPCheckpoint\Backups\ExportResults::record( $job, sprintf( 'site-20260924-%06d-ab12', $job ) );
		}
		$this->assertSame( '', \WPCheckpoint\Backups\ExportResults::base_of( 5 ), 'the oldest are dropped' );
		$this->assertSame( 'site-20260924-000006-ab12', \WPCheckpoint\Backups\ExportResults::base_of( 6 ) );
		$this->assertSame( 'site-20260924-000025-ab12', \WPCheckpoint\Backups\ExportResults::base_of( 25 ) );
		\WPCheckpoint\Backups\ExportResults::record( 26, '../not-a-backup' );
		$this->assertSame( '', \WPCheckpoint\Backups\ExportResults::base_of( 26 ) );
	}
}
