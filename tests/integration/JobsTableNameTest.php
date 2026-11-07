<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Jobs\WebJobs;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\ImportSession;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Support\Schema;

/**
 * The jobs table as each storage says it (RunnerRepository::jobs_table()): WordPress's (JobRepository) and the one of
 * a run without WordPress (WebJobs) give the same name for the same prefix, and WordPress's is what it always was.
 */
final class JobsTableNameTest extends WP_UnitTestCase {

	public function test_both_storages_give_the_same_name_and_wordpress_gives_what_it_did(): void {
		global $wpdb;
		$before  = $wpdb->base_prefix . 'wpcheckpoint_jobs'; // How the name was made before Schema::jobs_table_for().
		$session = ImportSession::open( Credentials::from_wordpress() );
		try {
			$web = new WebJobs(
				$session,
				(string) $wpdb->base_prefix,
				ABSPATH,
				static function ( string $line ): void {
					unset( $line );
				}
			);
			$this->assertSame( $before, Schema::jobs_table() );
			$this->assertSame( $before, Plugin::instance()->jobs()->jobs_table() );
			$this->assertSame( $before, $web->jobs_table() );
		} finally {
			$session->close();
		}
	}
}
