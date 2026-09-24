<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Database\SqlWriter;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Plugin;
use WPCheckpoint\Tests\Fixtures\Restore\RestoreTestCase;

/**
 * A restore's database stages (A and B) on real backups of this site's
 * tables: every table lands in its temporary table row for row, keys point
 * where they must, and nothing the site uses changes, also when the backup
 * holds something the restore does not run or a tick dies anywhere.
 */
final class RestoreImportTest extends RestoreTestCase {

	/** @var string */
	private $p;

	/** @var array<string, array<int, array<string, string|null>>> Rows of the test tables when the backup was made. */
	private $backed_up = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->p = $wpdb->base_prefix . 'wpcr_';
		$p       = $this->p;
		$this->create( $p . 'parent', '(`id` bigint unsigned NOT NULL AUTO_INCREMENT, `code` varchar(20) NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `code` (`code`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' );
		$this->create( $p . 'child', "(`id` bigint unsigned NOT NULL AUTO_INCREMENT, `parent_id` bigint unsigned NULL, `parent_code` varchar(20) NULL, `note` text, PRIMARY KEY (`id`), CONSTRAINT `fk_{$p}child_parent` FOREIGN KEY (`parent_id`) REFERENCES `{$p}parent` (`id`), FOREIGN KEY (`parent_code`) REFERENCES `{$p}parent` (`code`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
		$this->create( $p . 'big', '(`id` bigint unsigned NOT NULL AUTO_INCREMENT, `v` longtext, `b` blob, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' );
		$this->create( $p . 'isam', '(`id` int NOT NULL AUTO_INCREMENT, `v` text, PRIMARY KEY (`id`)) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4' );
		$this->create( $p . 'nokey', '(`a` int, `b` varchar(10)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' );
		$wpdb->query( "INSERT INTO `{$p}parent` (`id`, `code`) VALUES (1, 'a'), (2, 'b'), (3, 'c')" );
		$odd = array( "a;b", "it's; fine", "back\\slash", "ends\\", "\\';DROP TABLE x;--", "line\nbreak", "/* no */", "`tick`", "ünï ✓" );
		foreach ( $odd as $i => $text ) {
			$wpdb->insert( $p . 'child', array( 'parent_id' => 1 + $i % 3, 'parent_code' => chr( 97 + $i % 3 ), 'note' => $text ) );
		}
		// Several 1 MiB chunks: a table, and one without transactions.
		for ( $batch = 0; $batch < 6; $batch++ ) {
			$rows = array();
			for ( $i = 0; $i < 100; $i++ ) {
				$rows[] = $wpdb->prepare( '(%s, UNHEX(%s))', str_repeat( chr( 97 + ( $i % 26 ) ) . "'", 2000 ) . $batch . '-' . $i, bin2hex( random_bytes( 64 ) ) );
			}
			$wpdb->query( "INSERT INTO `{$p}big` (`v`, `b`) VALUES " . implode( ',', $rows ) );
			$wpdb->query( "INSERT INTO `{$p}isam` (`v`) VALUES " . implode( ',', array_map( static function ( string $row ): string {
				return substr( $row, 0, (int) strpos( $row, ', UNHEX' ) ) . ')';
			}, $rows ) ) );
		}
		$wpdb->query( "INSERT INTO `{$p}nokey` VALUES (1, 'x'), (1, 'x'), (NULL, NULL)" );
		$this->assertSame( '', $wpdb->last_error );
		foreach ( $this->tables() as $table ) {
			$this->backed_up[ $table ] = $this->rows_of( $table );
		}
	}

	/**
	 * The test's tables, parent before child.
	 *
	 * @return string[]
	 */
	private function tables(): array {
		return array( $this->p . 'parent', $this->p . 'child', $this->p . 'big', $this->p . 'isam', $this->p . 'nokey' );
	}

	public function test_every_table_lands_in_its_temporary_table_and_the_site_is_untouched(): void {
		global $wpdb;
		$base   = $this->backup( array_merge( self::site_tables(), $this->tables() ) );
		$before = $this->live_site();
		$job    = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$this->assertSame( $before, $this->live_site(), 'nothing of the site changed' );

		$names = $this->temporary_names( $job );
		foreach ( $this->tables() as $table ) {
			$this->assertSame( $this->backed_up[ $table ], $this->rows_of( $names[ $table ] ), $table . ': row for row' );
		}
		$this->assertSame( 'MyISAM', $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $names[ $this->p . 'isam' ] ) ) );

		// The temporary child's keys point at the temporary parent, and enforce against it.
		$keys = $wpdb->get_results( $wpdb->prepare( 'SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME', $names[ $this->p . 'child' ] ), ARRAY_N );
		$this->assertCount( 2, $keys );
		foreach ( $keys as $key ) {
			$this->assertSame( $names[ $this->p . 'parent' ], $key[1] );
		}
		// What this server named the live child's keys decides what the backup holds: its generated form, or a name of its own (MariaDB 12: "1").
		$given = array_column( $keys, 0 );
		$live  = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME', $this->p . 'child' ) );
		$this->assertCount( 2, $live );
		foreach ( $live as $name ) {
			if ( 1 === preg_match( '/\A' . preg_quote( $this->p . 'child', '/' ) . '(_ibfk_[0-9]+)\z/', $name, $m ) ) {
				$this->assertContains( $names[ $this->p . 'child' ] . $m[1], $given, 'the generated form follows the temporary table' );
			} else {
				$this->assertCount( 1, preg_grep( '/\Awcp[0-9a-f]{4}_[0-9a-z]+_' . preg_quote( $name, '/' ) . '\z/', $given ), $name . ': the marker in front, the name whole' );
			}
		}
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
		$this->assertFalse( $wpdb->query( 'INSERT INTO ' . SqlWriter::identifier( $names[ $this->p . 'child' ] ) . ' (`parent_id`) VALUES (999)' ) );
		$this->assertNotFalse( $wpdb->query( 'INSERT INTO ' . SqlWriter::identifier( $names[ $this->p . 'child' ] ) . ' (`parent_id`) VALUES (2)' ), 'the control: a parent that is there' );
	}

	public function test_a_backup_with_a_statement_the_restore_does_not_run_changes_nothing(): void {
		global $wpdb;
		$users = $wpdb->base_prefix . 'users';
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$users}`" );
		// A second chunk (not looked at in the preflight beyond its first INSERT) carries a statement for another table.
		$base = $this->backup(
			array_merge( self::site_tables(), $this->tables() ),
			function ( string $table, array $chunks ) use ( $users ): array {
				if ( $this->p . 'big' === $table ) {
					$chunks[1] = str_replace( "-- wpcheckpoint end", "DELETE FROM `{$users}`;\n-- wpcheckpoint end", $chunks[1] );
				}
				return $chunks;
			}
		);
		$before = $this->live_site();
		$job    = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'chunk 2', (string) $job->last_error );
		$this->assertStringContainsString( 'does not run (DELETE)', (string) $job->last_error );
		$this->assertSame( $count, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$users}`" ) );
		$this->assertSame( $before, $this->live_site() );
	}

	public function test_a_chunk_in_a_character_set_where_a_backslash_can_end_a_character_runs_nothing_but_hex_strings(): void {
		global $wpdb;
		// A later chunk switched to gbk, with a row that is one string to a byte-wise reader and a subquery to the server.
		$base   = $this->backup(
			array_merge( self::site_tables(), $this->tables() ),
			function ( string $table, array $chunks ): array {
				if ( $this->p . 'nokey' === $table ) {
					$chunks[0] = str_replace( '/*!40101 SET NAMES utf8mb4 */;', '/*!40101 SET NAMES gbk */;', $chunks[0] );
					$chunks[0] = str_replace( "-- wpcheckpoint end", "INSERT INTO `{$table}` (`a`, `b`) VALUES ('x\xbf\\', (SELECT 41+1)) #', 5);\n-- wpcheckpoint end", $chunks[0] );
				}
				return $chunks;
			}
		);
		$before = $this->live_site();
		$job    = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'A quoted string under the character set gbk', (string) $job->last_error );
		$names = $this->temporary_names( $job );
		$this->assertSame( '0', (string) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $names[ $this->p . 'nokey' ] . "` WHERE b = '42'" ), 'the subquery never ran' );
		$this->assertSame( $before, $this->live_site() );
	}

	public function test_an_insert_whose_columns_differ_from_the_definition_is_refused_in_the_preflight(): void {
		$base = $this->backup(
			array_merge( self::site_tables(), $this->tables() ),
			function ( string $table, array $chunks ): array {
				if ( $this->p . 'big' === $table ) {
					$chunks[2] = str_replace( '(`id`, `v`, `b`)', '(`id`, `b`, `v`)', $chunks[2] );
				}
				return $chunks;
			}
		);
		$job = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'Step "restore_preflight"', (string) $job->last_error );
		$this->assertStringContainsString( 'chunk 3', (string) $job->last_error );
		$this->assertStringContainsString( 'lists other columns', (string) $job->last_error );
		$this->assertSame( array(), $this->job_tables( $job ), 'nothing was created' );
	}

	public function test_only_a_table_whose_constraint_name_had_to_be_shortened_is_warned_about(): void {
		$long = str_repeat( 'k', 60 );
		$this->create( $this->p . 'longfk', "(`id` int NOT NULL, `parent_id` bigint unsigned NULL, PRIMARY KEY (`id`), CONSTRAINT `{$long}` FOREIGN KEY (`parent_id`) REFERENCES `{$this->p}parent` (`id`)) ENGINE=InnoDB" );
		$base = $this->backup( array_merge( self::site_tables(), array( $this->p . 'parent', $this->p . 'child', $this->p . 'longfk' ) ) );
		$job  = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$log      = (string) file_get_contents( $job->storage_path . '/' . $job->log_path );
		$warnings = preg_grep( '/constraint name of this table was shortened/', explode( "\n", $log ) );
		$this->assertCount( 1, $warnings, 'one table, one warning; the child\'s names fit' );
		$warning = (string) reset( $warnings );
		$this->assertStringContainsString( $this->p . 'longfk', $warning );
		$this->assertStringContainsString( 'Do not update WooCommerce before confirming the restore', $warning );
		$this->assertStringNotContainsString( $this->p . 'child', $warning );
	}

	/**
	 * A run killed right after a statement (its record not written: an InnoDB batch is rolled back, a
	 * MyISAM row stays) or right after a commit (the ledger ahead of the cursor), at every such point of
	 * a whole import in turn; the retry continues from what the ledger says and the tables end up row
	 * for row as backed up: nothing twice, nothing missing.
	 */
	public function test_a_run_killed_at_any_point_of_the_import_leaves_nothing_twice_and_nothing_out(): void {
		$base   = $this->backup( array_merge( self::site_tables(), $this->tables() ) );
		$points = array();
		$this->register_crashing( 'restore_counting', static function ( string $point ) use ( &$points ): void {
			$points[] = $point;
		} );
		$job = $this->run_restore( Plugin::instance()->jobs()->create( 'restore_counting', self::$admin_id, array(), array( 'base' => $base ) ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$counts = array_count_values( $points );
		$this->assertGreaterThan( 10, $counts['statement'] ?? 0, 'the control: the seam sees the statements' );
		$this->assertGreaterThan( 5, $counts['commit'] ?? 0, 'and the commits' );

		$restarted = 0;
		foreach ( array( 'statement', 'commit' ) as $point ) {
			for ( $at = 1; $at <= $counts[ $point ]; $at++ ) {
				$seen  = 0;
				$armed = true;
				$type  = 'restore_crash_' . $point . '_' . $at;
				$this->register_crashing( $type, static function ( string $here ) use ( $point, $at, &$seen, &$armed ): void {
					if ( $armed && $here === $point && ++$seen === $at ) {
						$armed = false;
						throw new \RuntimeException( 'simulated: the run is killed here' );
					}
				} );
				$job = $this->run_restore( Plugin::instance()->jobs()->create( $type, self::$admin_id, array(), array( 'base' => $base ) ) );
				$this->assertSame( Job::FAILED, $job->status, $type );
				$this->assertStringContainsString( 'simulated', (string) $job->last_error );
				Plugin::instance()->job_actions()->retry( $job->id );
				$job = $this->run_restore( $job );
				$this->assertSame( Job::COMPLETED, $job->status, $type . ': ' . (string) $job->last_error );
				$names = $this->temporary_names( $job );
				foreach ( $this->tables() as $table ) {
					$this->assertSame( $this->backed_up[ $table ], $this->rows_of( $names[ $table ] ), $type . ': ' . $table );
				}
				$log        = (string) file_get_contents( $job->storage_path . '/' . $job->log_path );
				$restarted += substr_count( $log, 'imported again from its first chunk' );
				$this->drop_job_tables( $job );
			}
		}
		$this->assertGreaterThan( 0, $restarted, 'a MyISAM statement without its record was found by the count and the table imported again' );
	}

	/**
	 * A table without transactions is started over after a statement of its second chunk ran unrecorded;
	 * a run that dies right after the start-over was recorded, while its position is still on the second
	 * chunk, is followed by one that goes back to the first chunk all the same and finishes the table row
	 * for row.
	 */
	public function test_a_run_killed_after_starting_a_table_over_is_followed_by_one_that_finishes_it(): void {
		$base  = $this->backup( array_merge( self::site_tables(), $this->tables() ) );
		$isam  = $this->p . 'isam';
		$seen  = array();
		$type  = 'restore_crash_restart';
		$this->register_crashing(
			$type,
			static function ( string $point, string $table = '', int $chunk = 0 ) use ( $isam, &$seen ): void {
				if ( $table !== $isam || ( 'statement' === $point && 2 !== $chunk ) ) {
					return;
				}
				$seen[ $point ] = ( $seen[ $point ] ?? 0 ) + 1;
				// The first INSERT of the table's second chunk (after its three preamble statements), run and not recorded;
				// then the start-over it causes, while the position is still on the second chunk.
				if ( ( 'statement' === $point && 4 === $seen[ $point ] ) || ( 'restart' === $point && 1 === $seen[ $point ] ) ) {
					throw new \RuntimeException( 'simulated: the run is killed here (' . $point . ')' );
				}
			}
		);
		$job = $this->run_restore( Plugin::instance()->jobs()->create( $type, self::$admin_id, array(), array( 'base' => $base ) ) );
		$this->assertStringContainsString( '(statement)', (string) $job->last_error );
		Plugin::instance()->job_actions()->retry( $job->id );
		$job = $this->run_restore( $job );
		$this->assertStringContainsString( '(restart)', (string) $job->last_error, 'the retry started the table over and was killed right after' );
		Plugin::instance()->job_actions()->retry( $job->id );
		$job = $this->run_restore( $job );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$names = $this->temporary_names( $job );
		foreach ( $this->tables() as $table ) {
			$this->assertSame( $this->backed_up[ $table ], $this->rows_of( $names[ $table ] ), $table );
		}
	}

	/**
	 * The preflight reads the head of a later chunk; a deflated entry has no addressable ranges and is read
	 * whole, whatever the head size (the packer deflates entries of up to 4 MiB; a head is 1 MiB).
	 */
	public function test_the_heads_of_deflated_chunks_larger_than_a_head_are_read(): void {
		$base = $this->backup( array_merge( self::site_tables(), $this->tables() ) );
		$real = Plugin::instance()->job_types()->get( 'restore' )->steps();
		$this->register(
			'restore_small_heads',
			array(
				$real[0],
				new \WPCheckpoint\Jobs\RestorePreflightStep(
					static function (): string {
						return Plugin::instance()->directories()->backups();
					},
					4096
				),
				$real[2],
			)
		);
		$job = $this->run_restore( Plugin::instance()->jobs()->create( 'restore_small_heads', self::$admin_id, array(), array( 'base' => $base ) ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
	}

	/**
	 * A job type with the restore's steps and a crash seam in the import.
	 */
	private function register_crashing( string $id, callable $crash ): void {
		$real  = Plugin::instance()->job_types()->get( 'restore' );
		$steps = $real->steps();
		$steps[2] = new \WPCheckpoint\Jobs\DatabaseImportStep( null, $crash );
		$this->register( $id, $steps );
	}

	/**
	 * Drop a finished job's tables (the loop above makes many).
	 */
	private function drop_job_tables( Job $job ): void {
		global $wpdb;
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );
		foreach ( $this->job_tables( $job ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
	}

	/**
	 * Tables of a job (temporary tables and its ledger).
	 *
	 * @return string[]
	 */
	private function job_tables( Job $job ): array {
		global $wpdb;
		return (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( TempTables::job_prefix( $job->storage_token, $job->id ) ) . '%' ) );
	}
}
