<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Tests\Fixtures\Restore\RestoreTestCase;

/**
 * What stops a restore before anything is created: foreign keys that would
 * cross the swap, a backup of the other kind of site, and a list of active
 * plugins WordPress did not write.
 */
final class RestorePreflightTest extends RestoreTestCase {

	/** @var string */
	private $p;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->p = $wpdb->base_prefix . 'wpcr_';
		$this->create( $this->p . 'parent', '(`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB' );
		$this->create( $this->p . 'child', "(`id` int NOT NULL, `parent_id` int NULL, PRIMARY KEY (`id`), CONSTRAINT `fk_wpcr_child` FOREIGN KEY (`parent_id`) REFERENCES `{$this->p}parent` (`id`)) ENGINE=InnoDB" );
		$wpdb->query( "INSERT INTO `{$this->p}parent` VALUES (1), (2)" );
		$wpdb->query( "INSERT INTO `{$this->p}child` VALUES (1, 1), (2, 2)" );
	}

	/**
	 * Tables of a job.
	 *
	 * @return string[]
	 */
	private function job_tables( Job $job ): array {
		global $wpdb;
		return (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( TempTables::job_prefix( $job->storage_token, $job->id ) ) . '%' ) );
	}

	public function test_a_staying_table_whose_key_references_a_replaced_table_stops_the_restore(): void {
		global $wpdb;
		$other = 'wpcrother_ref';
		$this->create( $other, "(`id` int NOT NULL, `parent_id` int, PRIMARY KEY (`id`), CONSTRAINT `fk_other_ref` FOREIGN KEY (`parent_id`) REFERENCES `{$this->p}parent` (`id`)) ENGINE=InnoDB" );
		$base = $this->backup( array_merge( self::site_tables(), array( $this->p . 'parent', $this->p . 'child' ) ) );
		$job  = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::FAILED, $job->status );
		$error = (string) $job->last_error;
		$this->assertStringContainsString( "The table {$other} stays as it is, but its foreign key fk_other_ref references {$this->p}parent", $error );
		$this->assertStringContainsString( 'the new data would be held to the old rows, and the old tables could not be deleted', $error, 'why one of the ways out must be taken' );
		$this->assertStringContainsString( 'Either restore', $error );
		$this->assertStringContainsString( 'or remove that foreign key first', $error );
		$this->assertSame( array(), $this->job_tables( $job ), 'nothing was created' );

		// The control: without that key the same backup goes through.
		$wpdb->query( "ALTER TABLE `{$other}` DROP FOREIGN KEY `fk_other_ref`" );
		$this->assertSame( Job::COMPLETED, $this->run_restore( $this->start_restore( $base ) )->status );
	}

	public function test_a_restored_key_to_a_table_moved_aside_but_not_restored_stops_the_restore(): void {
		$base = $this->backup( array_merge( self::site_tables(), array( $this->p . 'child' ) ) );
		$job  = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( "The table {$this->p}child of the backup has a foreign key (fk_wpcr_child) to {$this->p}parent", (string) $job->last_error );
		$this->assertStringContainsString( 'or leave ' . $this->p . 'child out of the restore', (string) $job->last_error );
		$this->assertSame( array(), $this->job_tables( $job ) );
	}

	public function test_a_table_left_out_stays_and_restored_keys_reference_it_by_its_name_here(): void {
		global $wpdb;
		$base = $this->backup( array_merge( self::site_tables(), array( $this->p . 'parent', $this->p . 'child' ) ) );
		$job  = $this->run_restore( $this->start_restore( $base, array( 'exclude_tables' => array( $this->p . 'parent' ) ) ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$names = $this->temporary_names( $job );
		$this->assertArrayNotHasKey( $this->p . 'parent', $names );
		$this->assertSame(
			$this->p . 'parent',
			$wpdb->get_var( $wpdb->prepare( 'SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND REFERENCED_TABLE_NAME IS NOT NULL', $names[ $this->p . 'child' ] ) ),
			'the live table that stays'
		);
	}

	public function test_a_list_of_active_plugins_wordpress_did_not_write_stops_the_restore_after_that_table(): void {
		global $wpdb;
		$key  = is_multisite() ? 'active_sitewide_plugins' : 'active_plugins';
		$good = $this->backup( array_merge( self::site_tables(), array( $this->p . 'parent' ) ) );
		$this->assertSame( Job::COMPLETED, $this->run_restore( $this->start_restore( $good ) )->status, 'the control: the list as WordPress wrote it' );

		$base = $this->backup(
			array_merge( self::site_tables(), array( $this->p . 'parent' ) ),
			static function ( string $table, array $chunks ) use ( $key ): array {
				foreach ( $chunks as $i => $chunk ) {
					$chunks[ $i ] = (string) preg_replace( "/('{$key}',)'a:/", "\$1'x:", $chunk );
				}
				return $chunks;
			}
		);
		$job = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( "({$key}) is not one WordPress wrote", (string) $job->last_error );
		$this->assertStringContainsString( 'does not guess', (string) $job->last_error );
		unset( $wpdb );
	}

	public function test_an_archive_table_is_refused_before_anything_is_created(): void {
		global $wpdb;
		// Servers need not have the ARCHIVE engine loaded: the table is made here with InnoDB and the backup says ARCHIVE.
		$this->create( $this->p . 'log', '(`id` int NOT NULL AUTO_INCREMENT, `v` text, PRIMARY KEY (`id`)) ENGINE=InnoDB' );
		$wpdb->query( "INSERT INTO `{$this->p}log` (`v`) VALUES ('a'), ('b')" );
		$log  = $this->p . 'log';
		$base = $this->backup(
			array_merge( self::site_tables(), array( $this->p . 'parent', $log ) ),
			static function ( string $table, array $chunks ) use ( $log ): array {
				if ( $log === $table ) {
					$chunks[0] = str_replace( ') ENGINE=InnoDB', ') ENGINE=ARCHIVE', $chunks[0] );
				}
				return $chunks;
			}
		);
		$job  = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::FAILED, $job->status );
		$error = (string) $job->last_error;
		$this->assertStringContainsString( 'Step "restore_preflight"', $error );
		$this->assertStringContainsString( 'Table ' . $this->p . 'log', $error );
		$this->assertStringContainsString( 'ARCHIVE engine', $error );
		$this->assertStringContainsString( 'Leave the table out of the restore', $error );
		$this->assertSame( array(), $this->job_tables( $job ), 'nothing was created' );

		// The way out: without it the same backup goes through.
		$this->assertSame( Job::COMPLETED, $this->run_restore( $this->start_restore( $base, array( 'exclude_tables' => array( $this->p . 'log' ) ) ) )->status );
	}

	public function test_a_backup_of_the_other_kind_of_site_is_refused(): void {
		$base = $this->backup(
			self::site_tables(),
			null,
			static function ( array $site ): array {
				$site['multisite'] = ! is_multisite();
				return $site;
			}
		);
		$job = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( is_multisite() ? 'of a single site and this site is a multisite network' : 'of a multisite network and this site is a single site', (string) $job->last_error );
		$this->assertSame( array(), $this->job_tables( $job ) );
	}
}
