<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Archive\IndexLine;
use WPCheckpoint\Archive\Limits;
use WPCheckpoint\Archive\ZipFormat;
use WPCheckpoint\Database\SqlWriter;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\RestorePreflightStep;
use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Tests\Fixtures\MemoryBudget;
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

	/**
	 * The largest chunks a restore takes are restored within the step budget: a stored chunk of
	 * Limits::CONTENT_CHUNK_BYTES (its head read in a range) and a compressed one of
	 * Limits::INFLATE_BYTES (read whole). One byte more is refused by the check before the preflight, with
	 * the reason in the job's log, and nothing is created.
	 *
	 * The budget holds on every PHP version and in any order of tests (MemoryBudget).
	 */
	public function test_chunks_of_the_largest_sizes_are_restored_within_the_budget_and_larger_ones_are_refused(): void {
		global $wpdb;
		$wide = $this->p . 'wide';
		$this->create( $wide, '(`id` int NOT NULL, `v` varchar(1000) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' );
		$wpdb->query( "INSERT INTO `{$wide}` VALUES (1, 'a')" );
		$max = Limits::CONTENT_CHUNK_BYTES;
		foreach ( array(
			'stored'     => array( $max, 1048576, ZipFormat::METHOD_STORE ),
			'compressed' => array( Limits::INFLATE_BYTES, 2 * $max, ZipFormat::METHOD_DEFLATE ),
		) as $label => list( $bytes, $deflate_max, $method ) ) {
			list( $base, $rows ) = $this->wide_backup( $wide, $bytes, $deflate_max );
			$this->assertSame( $method, $this->entry_method( $base, IndexLine::database_path( $wide, 2 ) ), $label );
			$job = $this->start_restore( $base );
			$job = MemoryBudget::within(
				40 * 1048576, // A step's 32 MB and the engine's 8 MB reserve (Budget).
				function () use ( $job ): Job {
					return $this->run_restore( $job );
				}
			);
			$this->assertSame( Job::COMPLETED, $job->status, $label . ': ' . (string) $job->last_error );
			$names = array_column( RestorePreflightStep::load_plan( $this->work( $job ) )['plan']->tables(), 'temporary', 'table' );
			$this->assertSame( (string) $rows, (string) $wpdb->get_var( "SELECT COUNT(*) FROM `{$names[ $wide ]}`" ), $label );
			$this->assertContains( $names[ $wide ], $this->job_tables( $job ), $label . ': the control, the job\'s tables are found' );
		}

		list( $base ) = $this->wide_backup( $wide, Limits::INFLATE_BYTES + 1, 2 * $max );
		$this->assertSame( ZipFormat::METHOD_DEFLATE, $this->entry_method( $base, IndexLine::database_path( $wide, 2 ) ) );
		$job = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'This backup cannot be restored', (string) $job->last_error );
		$this->assertStringContainsString( 'The entry is stored compressed and is 8388609 bytes large; this plugin decompresses a compressed entry in one piece and reads at most 8388608 bytes (8 MiB)', (string) file_get_contents( $job->storage_path . '/' . $job->log_path ), 'the reason, in the job\'s log' );
		$this->assertSame( array(), $this->job_tables( $job ), 'nothing was created' );

		$base = $this->backup( self::site_tables(), null, null, array( 'chunk_bytes' => $max + 1 ) );
		$job  = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'This backup cannot be restored', (string) $job->last_error );
		$this->assertStringContainsString( 'The manifest declares hash chunks larger than this verifier checks in one step (content 16777217 bytes', (string) file_get_contents( $job->storage_path . '/' . $job->log_path ), 'the reason, in the job\'s log' );
		$this->assertSame( array(), $this->job_tables( $job ), 'nothing was created' );
	}

	/**
	 * A backup of the site's tables and $wide, whose second chunk is exactly $bytes long: the first chunk's
	 * header and preamble, INSERTs of about 1 KB rows, a comment to fill up.
	 *
	 * @return array{0: string, 1: int} The backup's base name and the table's row count.
	 */
	private function wide_backup( string $wide, int $bytes, int $deflate_max ): array {
		$rows   = 1;
		$base   = $this->backup(
			array_merge( self::site_tables(), array( $wide ) ),
			static function ( string $table, array $chunks ) use ( $wide, $bytes, &$rows ): array {
				if ( $wide !== $table ) {
					return $chunks;
				}
				$text = substr( $chunks[0], 0, (int) strpos( $chunks[0], 'DROP TABLE' ) );
				$head = SqlWriter::insert_head( $wide, array( 'id', 'v' ) );
				$row  = "'" . str_repeat( 'b', 990 ) . "')";
				foreach ( array( 1000, 1 ) as $per ) {
					$size = strlen( $head ) + $per * ( strlen( $row ) + 10 ) + 2;
					while ( strlen( $text ) + $size + 2048 <= $bytes ) {
						$values = array();
						for ( $i = 0; $i < $per; $i++ ) {
							$values[] = '(' . ( ++$rows ) . ',' . $row;
						}
						$text .= $head . implode( ',', $values ) . ";\n";
					}
				}
				$text .= '-- ' . str_repeat( 'p', $bytes - strlen( $text ) - 4 ) . "\n";
				return array( $chunks[0], $text );
			},
			null,
			array(
				'chunk_bytes'       => Limits::CONTENT_CHUNK_BYTES,
				'volume_bytes'      => 4 * Limits::CONTENT_CHUNK_BYTES,
				'deflate_max_bytes' => $deflate_max,
				'rows'              => array( $wide => &$rows ),
			)
		);
		return array( $base, $rows );
	}
}
