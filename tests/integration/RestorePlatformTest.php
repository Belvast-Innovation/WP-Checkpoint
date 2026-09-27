<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Files\ScanRoots;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Jobs\RestorePlatformStep;
use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Tests\Fixtures\Restore\RestoreTestCase;

/**
 * Restoring onto a Windows server is refused in this version, first, before
 * anything of the restore is read, probed or written.
 */
final class RestorePlatformTest extends RestoreTestCase {

	/**
	 * A restore job whose platform check sees this operating system family.
	 */
	private function restore_on( string $family, string $base ): Job {
		$type = 'restore_on_' . strtolower( $family ) . '_' . bin2hex( random_bytes( 2 ) );
		$this->register(
			$type,
			self::restore_steps_with(
				new RestorePlatformStep(
					static function () use ( $family ): string {
						return $family;
					}
				)
			)
		);
		return Plugin::instance()->jobs()->create( $type, self::$admin_id, array(), array( 'base' => $base ) );
	}

	/**
	 * What a restore makes: its work directory, its temporary tables, its staging and probes next to the site.
	 *
	 * @return array{work: bool, tables: string[], site: int}
	 */
	private static function made( Job $job ): array {
		global $wpdb;
		return array(
			'work'   => is_dir( Residue::work_dir( $job->storage_path, $job->id ) ),
			'tables' => (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( TempTables::job_prefix( $job->storage_token, $job->id ) ) . '%' ) ),
			'site'   => count( Residue::scan_site( Residue::site_dirs( ScanRoots::site_directories() ), Directories::own_tokens() ) ),
		);
	}

	public function test_a_windows_server_is_refused_before_anything_is_made_and_another_goes_on(): void {
		$base = $this->backup( self::site_tables(), null, null, array( 'files' => array( 'wp-content/uploads/a.txt' => 'a' ) ) );

		$job = $this->run_restore( $this->restore_on( 'Windows', $base ) );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'This version of WP Checkpoint cannot restore onto a Windows server yet. Backups and exports are not affected.', (string) $job->last_error );
		$this->assertSame( Job::FAILURE_FINAL, $job->failure_kind, 'retrying cannot change it' );
		$this->assertSame(
			array(
				'work'   => false,
				'tables' => array(),
				'site'   => 0,
			),
			self::made( $job ),
			'nothing of the restore was made'
		);

		// The control: the same restore on Linux goes past the check, and the same observation sees what it made.
		$job = $this->run_restore( $this->restore_on( 'Linux', $base ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$made = self::made( $job );
		$this->assertTrue( $made['work'], 'its work directory' );
		$this->assertNotSame( array(), $made['tables'], 'its temporary tables' );
	}
}
