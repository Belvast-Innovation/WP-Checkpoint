<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Archive\IndexLine;
use WPCheckpoint\Archive\ZipFormat;
use WPCheckpoint\Database\SqlWriter;
use WPCheckpoint\Jobs\DatabaseImportStep;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\RestorePreflightStep;
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

	public function tear_down(): void {
		$this->backed_up = array();
		parent::tear_down();
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
	 * A table without transactions is started over after a statement of its second chunk ran unrecorded.
	 * Starting over passes through five states (marked; emptied; position reset; job's position back on the
	 * first chunk; mark removed); a run killed after any of them is followed by one that finishes the table
	 * row for row, and the start-over is counted once.
	 */
	public function test_a_run_killed_in_any_state_of_starting_a_table_over_is_followed_by_one_that_finishes_it(): void {
		$base = $this->backup( array_merge( self::site_tables(), $this->tables() ) );
		$isam = $this->p . 'isam';
		foreach ( array( 'marked', 'emptied', 'reset', 'rewound', 'restarted' ) as $stop ) {
			$seen = array();
			$type = 'restore_crash_' . $stop;
			$this->register_crashing(
				$type,
				static function ( string $point, string $table = '', int $chunk = 0 ) use ( $isam, $stop, &$seen ): void {
					if ( $table !== $isam || ( 'statement' === $point && 2 !== $chunk ) ) {
						return;
					}
					$seen[ $point ] = ( $seen[ $point ] ?? 0 ) + 1;
					// The first INSERT of the table's second chunk, run and not recorded; then the start-over it causes.
					if ( ( 'statement' === $point || $stop === $point ) && 1 === $seen[ $point ] ) {
						throw new \RuntimeException( 'simulated: the run is killed here (' . $point . ')' );
					}
				}
			);
			$job = $this->run_restore( Plugin::instance()->jobs()->create( $type, self::$admin_id, array(), array( 'base' => $base ) ) );
			$this->assertStringContainsString( '(statement)', (string) $job->last_error, $stop );
			Plugin::instance()->job_actions()->retry( $job->id );
			$job = $this->run_restore( $job );
			$this->assertStringContainsString( '(' . $stop . ')', (string) $job->last_error, 'the retry started the table over and was killed after: ' . $stop );
			Plugin::instance()->job_actions()->retry( $job->id );
			$job = $this->run_restore( $job );
			$this->assertSame( Job::COMPLETED, $job->status, $stop . ': ' . (string) $job->last_error );
			$names = $this->temporary_names( $job );
			foreach ( $this->tables() as $table ) {
				$this->assertSame( $this->backed_up[ $table ], $this->rows_of( $names[ $table ] ), $stop . ': ' . $table );
			}
			$this->assertSame( array( 1, 0 ), $this->restarts( $job, $isam ), $stop . ': started over once, and no longer marked' );
			$log = (string) file_get_contents( $job->storage_path . '/' . $job->log_path );
			$this->assertStringContainsString( 'imported again from its first chunk: its row count does not match the ledger', $log, $stop . ': the control, the start-over is logged' );
			$resumed = 'was being imported again from its first chunk when a run stopped; this run goes on with it';
			if ( 'restarted' === $stop ) {
				$this->assertStringNotContainsString( $resumed, $log, 'the mark was gone: nothing to go on with' );
			} else {
				$this->assertStringContainsString( $resumed, $log, $stop . ': going on with it is logged' );
			}
			$this->drop_job_tables( $job );
		}
	}

	/**
	 * Starting a table over empties it, and TRUNCATE sets its AUTO_INCREMENT counter back to its start on every
	 * supported server; the table gets its CREATE TABLE's value again (rows deleted at the end of the table on the
	 * original site keep their numbers unused).
	 */
	public function test_a_table_started_over_keeps_the_auto_increment_of_its_definition(): void {
		global $wpdb;
		$isam = $this->p . 'isam';
		$wpdb->query( "ALTER TABLE `{$isam}` AUTO_INCREMENT = 5000" );
		$base = $this->backup( array_merge( self::site_tables(), $this->tables() ) );
		$next = function ( Job $job ) use ( $isam ): int {
			global $wpdb;
			$temporary = $this->temporary_names( $job )[ $isam ];
			$wpdb->query( "INSERT INTO `{$temporary}` (`v`) VALUES ('next')" );
			return (int) $wpdb->get_var( "SELECT MAX(`id`) FROM `{$temporary}`" );
		};
		$job = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$this->assertSame( 5000, $next( $job ), 'the control: imported in one pass, the counter is the definition\'s' );
		$this->drop_job_tables( $job );

		$done = false;
		$this->register_crashing(
			'restore_crash_counter',
			static function ( string $point, string $table = '', int $chunk = 0 ) use ( $isam, &$done ): void {
				if ( ! $done && 'statement' === $point && $table === $isam && 2 === $chunk ) {
					$done = true;
					throw new \RuntimeException( 'simulated: the run is killed here (statement)' );
				}
			}
		);
		$job = $this->run_restore( Plugin::instance()->jobs()->create( 'restore_crash_counter', self::$admin_id, array(), array( 'base' => $base ) ) );
		Plugin::instance()->job_actions()->retry( $job->id );
		$job = $this->run_restore( $job );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$this->assertSame( array( 1, 0 ), $this->restarts( $job, $isam ), 'the control: the table was started over' );
		$this->assertSame( 5000, $next( $job ), 'the counter after starting over' );
	}

	/**
	 * Going back to a table's first chunk is followed by a look at the budget: a tick whose time is up ends there,
	 * before it extracts the chunk (up to 16 MiB, at the disk's speed).
	 */
	public function test_a_tick_whose_time_is_up_ends_where_a_table_goes_back_to_its_first_chunk(): void {
		$base   = $this->backup( array_merge( self::site_tables(), $this->tables() ) );
		$isam   = $this->p . 'isam';
		$killed = false;
		$seen   = array();
		$this->register_crashing(
			'restore_rewind_budget',
			function ( string $point, string $table = '', int $chunk = 0 ) use ( $isam, &$killed, &$seen ): void {
				if ( $table !== $isam ) {
					return;
				}
				$seen[] = $point;
				if ( ! $killed && 'statement' === $point && 2 === $chunk ) {
					$killed = true;
					throw new \RuntimeException( 'simulated: the run is killed here (statement)' );
				}
				if ( 'rewound' === $point ) {
					$this->now += 1000; // The time is up.
				}
			}
		);
		$job = $this->run_restore( Plugin::instance()->jobs()->create( 'restore_rewind_budget', self::$admin_id, array(), array( 'base' => $base ) ) );
		Plugin::instance()->job_actions()->retry( $job->id );
		$runner = $this->small_runner();
		for ( $i = 0; $i < 5000 && ! in_array( 'rewound', $seen, true ); $i++ ) {
			$seen = array();
			$runner->tick( $job->id, microtime( true ) );
		}
		$this->assertContains( 'rewound', $seen, 'the control: a tick went back to the first chunk' );
		$this->assertSame( 'rewound', end( $seen ), 'nothing after it in that tick' );
		$GLOBALS['wpdb']->query( 'COMMIT' );
		$stored = Plugin::instance()->jobs()->find( $job->id )->cursor;
		$this->assertArrayHasKey( 'current', $stored );
		$this->assertNull( $stored['current'], 'the position stops before the first chunk is taken up' );
		$job = $this->run_restore( $job, true, 5000 );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
	}

	/**
	 * A table without transactions whose count keeps disagreeing with the ledger is started over at most
	 * MAX_RESTARTS times; the next run fails it, saying what the count showed and what can cause it.
	 */
	public function test_a_table_started_over_the_most_times_fails_the_restore_with_the_reason(): void {
		$base = $this->backup( array_merge( self::site_tables(), $this->tables() ) );
		$isam = $this->p . 'isam';
		$type = 'restore_crash_always';
		$this->register_crashing(
			$type,
			static function ( string $point, string $table = '', int $chunk = 0 ) use ( $isam ): void {
				if ( 'statement' === $point && $table === $isam && 2 === $chunk ) {
					throw new \RuntimeException( 'simulated: the run is killed here (statement)' );
				}
			}
		);
		$job = $this->run_restore( Plugin::instance()->jobs()->create( $type, self::$admin_id, array(), array( 'base' => $base ) ) );
		for ( $retries = 0; $retries < 10 && false !== strpos( (string) $job->last_error, 'simulated' ); $retries++ ) {
			$this->assertSame( array( $retries, 0 ), $this->restarts( $job, $isam ), 'each retry started the table over once' );
			Plugin::instance()->job_actions()->retry( $job->id );
			$job = $this->run_restore( $job );
		}
		$this->assertSame( DatabaseImportStep::MAX_RESTARTS + 1, $retries, 'the retry after the last start-over fails' );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'was imported again from its first chunk ' . DatabaseImportStep::MAX_RESTARTS . ' times, each time because its row count did not match the rows the restore had recorded', (string) $job->last_error );
		$this->assertStringContainsString( 'a run stopped between a statement and its record, a statement failed partway and kept some of its rows (see the log for the database\'s errors), or rows were written by another run of this restore that outlived its lease or by another process', (string) $job->last_error );
		$this->assertSame( array( DatabaseImportStep::MAX_RESTARTS, 0 ), $this->restarts( $job, $isam ) );
	}

	/**
	 * A table that must be emptied to be started over and that the server does not empty fails the restore
	 * with the server's reason. (A view in the temporary table's place: counted like a table, never truncated.)
	 */
	public function test_a_table_the_database_does_not_empty_fails_the_restore_with_the_servers_reason(): void {
		global $wpdb;
		$base = $this->backup( array_merge( self::site_tables(), $this->tables() ) );
		$isam = $this->p . 'isam';
		$type = 'restore_crash_view';
		$view = '';
		$seen = array();
		$this->register_crashing(
			$type,
			function ( string $point, string $table = '', int $chunk = 0 ) use ( $isam, $type, &$view, &$seen ): void {
				if ( $table !== $isam || ( 'statement' === $point && 2 !== $chunk ) ) {
					return;
				}
				$seen[ $point ] = ( $seen[ $point ] ?? 0 ) + 1;
				if ( 'statement' === $point && 1 === $seen[ $point ] ) {
					throw new \RuntimeException( 'simulated: the run is killed here (statement)' );
				}
				if ( 'marked' === $point ) {
					global $wpdb;
					$job  = Plugin::instance()->jobs()->find( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . \WPCheckpoint\Support\Schema::jobs_table() . ' WHERE type = %s', $type ) ) );
					$view = $this->temporary_names( $job )[ $isam ];
					$wpdb->query( 'COMMIT' );
					$wpdb->query( "DROP TABLE `{$view}`" );
					$wpdb->query( "CREATE VIEW `{$view}` AS SELECT 1 AS `id`" );
				}
			}
		);
		try {
			$job = $this->run_restore( Plugin::instance()->jobs()->create( $type, self::$admin_id, array(), array( 'base' => $base ) ) );
			$this->assertStringContainsString( '(statement)', (string) $job->last_error );
			Plugin::instance()->job_actions()->retry( $job->id );
			$job = $this->run_restore( $job );
			$this->assertNotSame( '', $view, 'the table was replaced' );
			$this->assertSame( Job::FAILED, $job->status );
			$this->assertStringContainsString( 'must be emptied to be imported again after an interrupted run, and the database did not empty it (The database refused a statement', (string) $job->last_error );
		} finally {
			if ( '' !== $view ) {
				$wpdb->query( "DROP VIEW IF EXISTS `{$view}`" );
			}
		}
	}

	/**
	 * A first chunk may hold nothing but the table's definition, its rows beginning in the second (the format
	 * allows it; this plugin's exporter writes rows into the first chunk). The ledger then stands at the start
	 * of the rows when the second chunk comes, and that is no interrupted start-over: the table is imported
	 * row for row, in one pass. With transactions:
	 */
	public function test_a_table_with_transactions_whose_rows_begin_in_its_second_chunk_is_imported(): void {
		$this->assert_imported_with_rows_from_the_second_chunk( $this->p . 'big' );
	}

	/**
	 * The same without transactions (the ticks are capped: a start-over taken for this layout loops).
	 */
	public function test_a_table_without_transactions_whose_rows_begin_in_its_second_chunk_is_imported(): void {
		$this->assert_imported_with_rows_from_the_second_chunk( $this->p . 'isam' );
	}

	private function assert_imported_with_rows_from_the_second_chunk( string $split ): void {
		$base = $this->backup(
			array_merge( self::site_tables(), $this->tables() ),
			function ( string $table, array $chunks ) use ( $split ): array {
				if ( $split !== $table ) {
					return $chunks;
				}
				// Split the first chunk after its CREATE TABLE: its rows go to a new second chunk, with the preamble.
				$first = $chunks[0];
				$rows  = strpos( $first, "\nINSERT INTO " );
				$drop  = strpos( $first, 'DROP TABLE' );
				$this->assertNotFalse( $rows, $table );
				$this->assertNotFalse( $drop, $table );
				return array_merge( array( substr( $first, 0, $rows + 1 ), substr( $first, 0, $drop ) . substr( $first, $rows + 1 ) ), array_slice( $chunks, 1 ) );
			}
		);
		// Short ticks, so a loop ends at the cap in seconds; a pass that works takes about a thousand.
		$job = $this->run_restore( $this->start_restore( $base ), true, 3000 );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$names = $this->temporary_names( $job );
		foreach ( $this->tables() as $table ) {
			$this->assertSame( $this->backed_up[ $table ], $this->rows_of( $names[ $table ] ), $table );
		}
		$this->assertSame( array( 0, 0 ), $this->restarts( $job, $split ), 'never started over' );
		$log = (string) file_get_contents( $job->storage_path . '/' . $job->log_path );
		$this->assertStringNotContainsString( 'imported again from its first chunk', $log );
	}

	/**
	 * Ticks so short that each runs a statement or two: every chunk is resumed in its middle again and
	 * again, and a tick that begins a chunk must get past its preamble (which records no position) before
	 * it may stop, or it ends where it began and the job fails for making no progress.
	 */
	public function test_a_restore_of_one_statement_per_tick_finishes_row_for_row(): void {
		$base = $this->backup( array_merge( self::site_tables(), $this->tables() ) );
		$job  = $this->run_restore( $this->start_restore( $base ), true, 5000 );
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
		$this->assertSame( ZipFormat::METHOD_DEFLATE, $this->entry_method( $base, IndexLine::database_path( $this->p . 'big', 2 ) ), 'a later chunk, deflated' );
		$this->assertGreaterThan( 4096, (int) $this->entry( $base, IndexLine::database_path( $this->p . 'big', 2 ) )['usize'], 'larger than the head read' );
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
	 * A table's start-overs and mark in the job's ledger: [restarts, restarting].
	 *
	 * @return array{0: int, 1: int}
	 */
	private function restarts( Job $job, string $table ): array {
		global $wpdb;
		$plan   = RestorePreflightStep::load_plan( $this->work( Plugin::instance()->jobs()->find( $job->id ) ) );
		$ledger = TempTables::ledger( $job->storage_token, $job->id, $plan['random'] );
		$wpdb->query( 'COMMIT' ); // The test's snapshot may predate the ledger.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT restarts, restarting FROM `{$ledger}` WHERE n = %d", $plan['plan']->find( $table )['number'] ), ARRAY_N );
		$this->assertIsArray( $row, 'the ledger has the table' );
		return array( (int) $row[0], (int) $row[1] );
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
