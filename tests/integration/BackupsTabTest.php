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

	private function add_backup( array $warnings = array() ): void {
		$this->builder = ( new ArchiveBuilder(
			array(
				'manifest' => static function ( array $manifest, bool $embedded ) use ( $warnings ): array {
					$manifest['warnings']             = $warnings;
					$manifest['database']['exported'] = array(
						'started_at'  => '2026-09-18T10:00:00Z',
						'finished_at' => '2026-09-18T10:04:00Z',
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

	public function test_a_finished_export_links_its_backup_and_the_list_highlights_it(): void {
		global $wpdb;
		$this->add_backup();
		$job  = Plugin::instance()->jobs()->create( 'export', self::$admin_id );
		$work = Residue::work_dir( Plugin::instance()->jobs()->find( $job->id )->storage_path, $job->id );
		wp_mkdir_p( $work );
		ExportPlan::write( $work, ExportPlan::PLAN, array( 'base' => ArchiveBuilder::BASE ) );
		$wpdb->update( Schema::jobs_table(), array( 'status' => Job::COMPLETED, 'finished_at' => time() ), array( 'id' => $job->id ) );
		$html = $this->html( array( 'job' => (string) $job->id ) );
		$this->assertStringContainsString( 'The backup is ready.', $html );
		$this->assertStringContainsString( 'class="wpcheckpoint-highlight"', $html );
		$this->assertStringContainsString( '>New<', $html );
		$this->assertStringNotContainsString( 'class="wpcheckpoint-highlight"', $this->html(), 'only the export just finished on this page is highlighted' );

		foreach ( glob( $this->backups . '/' . ArchiveBuilder::BASE . '.*' ) as $file ) {
			unlink( $file );
		}
		$this->assertStringContainsString( 'The job completed, but its backup is no longer in the backups directory.', $this->html( array( 'job' => (string) $job->id ) ) );
	}
}
