<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Backups\ExportResults;
use WPCheckpoint\Jobs\ExportJob;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\PreflightStep;
use WPCheckpoint\Jobs\RestorePreflightStep;
use WPCheckpoint\Plugin;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Restore\ImportSession;
use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Restore\SwapPlan;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Restore\RestoreTestCase;

/**
 * A backup that holds this plugin's own swap plan, made by the plugin's own export as it was before the plan was
 * left out of backups: the restore skips the table and never plans to replace the live one, which the swap reads.
 */
final class RestoreOwnTablesTest extends RestoreTestCase {

	/** @var string[] Files of the backup this test exported. */
	private $exported = array();

	public function tear_down(): void {
		foreach ( $this->exported as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		parent::tear_down();
	}

	public function test_a_backup_holding_the_swap_plan_restores_without_planning_to_replace_the_live_one(): void {
		global $wpdb;
		$plan_table = $wpdb->base_prefix . SwapPlan::TABLE;
		$this->assertSame( $plan_table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $plan_table ) ), 'the control: the live plan table is there' );

		// The plugin's own export steps, with its rule for its own tables as it was before: only the jobs table out.
		$steps = Plugin::instance()->job_types()->get( ExportJob::ID )->steps();
		$found = false;
		foreach ( $steps as $step ) {
			if ( $step instanceof PreflightStep ) {
				$env = new \ReflectionProperty( PreflightStep::class, 'env' );
				$env->setAccessible( true );
				$value        = $env->getValue( $step );
				$value['own'] = static function ( string $table ): bool {
					return Schema::jobs_table() === $table;
				};
				$env->setValue( $step, $value );
				$found = true;
			}
		}
		$this->assertTrue( $found, 'the control: the export has its pre-flight' );
		$this->register( 'export-before-own-tables', $steps );
		$export = $this->run_restore(
			Plugin::instance()->jobs()->create(
				'export-before-own-tables',
				self::$admin_id,
				array(),
				array(
					'contents' => array( 'files' => array() ),
					'policy'   => array(
						'unreadable' => 'continue',
						'oversize'   => 'exclude',
						'large_dirs' => 'include',
					),
				)
			)
		);
		// The export job's own backup (not the newest file: another could share its second), registered for removal
		// before anything else is asserted.
		$backups = Plugin::instance()->directories()->backups();
		$base    = ExportResults::base_of( $export->id );
		$this->assertNotSame( '', $base, 'the export recorded its backup' );
		$path             = $backups . '/' . $base . '.manifest.json';
		$this->exported[] = $path;
		$manifest         = json_decode( (string) file_get_contents( $path ), true );
		foreach ( (array) ( $manifest['volumes'] ?? array() ) as $volume ) {
			$this->exported[] = $backups . '/' . $volume['path'];
		}
		$this->assertSame( Job::COMPLETED, $export->status, (string) $export->last_error );
		$this->assertContains( $plan_table, array_column( $manifest['database']['tables'], 'name' ), 'the backup, made by the plugin\'s own export, holds the swap plan' );

		// The restore of that backup.
		$job = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$plan = RestorePreflightStep::load_plan( $this->work( $job ) )['plan'];
		$this->assertSame( 'own', $plan->skipped()[ $plan_table ] ?? null, 'the backup\'s plan table is not restored' );
		$this->assertNull( $plan->find( $plan_table ) );

		$file    = json_decode( (string) file_get_contents( RestoreFiles::path( $this->work( $job ), RestoreFiles::SWAP_PLAN ) ), true );
		$db      = ImportSession::open( Credentials::from_wordpress() );
		$entries = ( new SwapPlan( $db, $plan_table ) )->read( $job->id, (int) $file['attempt'], -1, 100000 );
		$db->close();
		$tables = array();
		foreach ( $entries as $row ) {
			if ( SwapPlan::TABLE_OF === $row['kind'] || SwapPlan::MOVE === $row['kind'] ) {
				$tables[] = (string) $row['live'];
			}
		}
		$this->assertContains( $wpdb->base_prefix . 'options', $tables, 'the control: the plan replaces the site\'s tables' );
		$this->assertNotContains( $plan_table, $tables, 'the swap never plans to replace or move the plan it reads' );
	}
}
