<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\RestorePreflightStep;
use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Plugin;
use WPCheckpoint\Tests\Fixtures\Restore\RestoreTestCase;

/**
 * The restore's collation check (RestorePreflightStep, collations phase): a collation this server does not know is
 * written under a name it knows, logged and counted on the job (what the swap says of the count is the swap's test,
 * SwapCollationSummaryTest; the restores here end at the swap check, as RestoreTestCase runs them); one with no
 * such name stops the restore for good before any table is created, naming every table; and the server's collations
 * are read by their full names, which SHOW COLLATION and information_schema.COLLATIONS do not give for MariaDB's UCA
 * 14 ones.
 */
final class RestoreCollationsTest extends RestoreTestCase {

	/** @var string */
	private $p;

	/** @var callable|null The query filter a test added. */
	private $filter = null;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->p = $wpdb->base_prefix . 'wpcc_';
		$this->create( $this->p . 'c', '(`id` int NOT NULL, `name` varchar(20) COLLATE utf8mb4_bin DEFAULT NULL, `v` varchar(20) DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' );
		$this->create( $this->p . 'd', '(`id` int NOT NULL, `v` varchar(20) DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' );
		$wpdb->query( "INSERT INTO `{$this->p}c` VALUES (1, 'a', 'x'), (2, 'B', 'y')" );
		$wpdb->query( "INSERT INTO `{$this->p}d` VALUES (1, 'x')" );
	}

	public function tear_down(): void {
		if ( null !== $this->filter ) {
			remove_filter( 'query', $this->filter );
			$this->filter = null;
		}
		parent::tear_down();
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

	/**
	 * Hide the collations matching any of $likes from what the server says it knows: as a server that does not have them.
	 */
	private function hide_collations( string ...$likes ): void {
		$this->filter = static function ( $sql ) use ( $likes ) {
			$sql = (string) $sql;
			foreach ( array( RestorePreflightStep::COLLATIONS_SQL => 'FULL_COLLATION_NAME', RestorePreflightStep::COLLATIONS_SQL_FALLBACK => 'COLLATION_NAME' ) as $query => $column ) {
				if ( $query === $sql ) {
					$conditions = array();
					foreach ( $likes as $like ) {
						$conditions[] = $column . " NOT LIKE '" . $like . "'";
					}
					return $sql . ' WHERE ' . implode( ' AND ', $conditions );
				}
			}
			return $sql;
		};
		add_filter( 'query', $this->filter );
	}

	private function log_of( Job $job ): string {
		return (string) file_get_contents( Plugin::instance()->directories()->base() . '/' . $job->log_path );
	}

	/**
	 * The collation of a live table's definition.
	 */
	private static function table_collation( string $table ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
	}

	public function test_a_collation_this_server_does_not_know_is_written_under_a_name_it_knows_logged_and_counted(): void {
		$known = RestorePreflightStep::known_collations();
		if ( ! isset( $known['utf8mb4_unicode_520_nopad_ci'] ) ) {
			$this->markTestSkipped( 'The server has no utf8mb4_unicode_520_nopad_ci to map to (MariaDB 10.6 or later has).' );
		}
		$c    = $this->p . 'c';
		$base = $this->backup(
			array_merge( self::site_tables(), array( $c, $this->p . 'd' ) ),
			static function ( string $table, array $chunks ) use ( $c ): array {
				if ( $c === $table ) {
					// As a MySQL 8 backup writes it: the table option and a column in utf8mb4_0900_ai_ci.
					$chunks[0] = str_replace( array( 'COLLATE=utf8mb4_unicode_ci', '`v` varchar(20) DEFAULT NULL' ), array( 'COLLATE=utf8mb4_0900_ai_ci', '`v` varchar(20) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL' ), $chunks[0] );
				}
				return $chunks;
			}
		);
		// The control: a server that knows the name keeps it, counts nothing and logs nothing.
		if ( isset( $known['utf8mb4_0900_ai_ci'] ) ) {
			$plain = $this->run_restore( $this->start_restore( $base ) );
			$this->assertSame( Job::COMPLETED, $plain->status, (string) $plain->last_error );
			$this->assertSame( 0, $plain->options['recorded']['collations_mapped'] ?? null, 'recorded as none' );
			$this->assertStringNotContainsString( 'is written under another name', $this->log_of( $plain ) );
		}

		// A server without it, nor the first candidate (MariaDB 11.4 and later know utf8mb4_0900_ai_ci as an alias
		// of utf8mb4_uca1400_nopad_ai_ci, so only the second candidate shows that the rewrite was written): the two
		// places are written under the NO PAD name it knows.
		$this->hide_collations( '%0900%', '%uca1400_nopad_ai_ci' );
		$hidden = RestorePreflightStep::known_collations();
		$this->assertArrayNotHasKey( 'utf8mb4_0900_ai_ci', $hidden, 'the control: hidden from the check' );
		$this->assertArrayNotHasKey( 'utf8mb4_uca1400_nopad_ai_ci', $hidden, 'the control: the first candidate hidden too' );
		$job = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$temporary = $this->temporary_names( $job )[ $c ];
		$this->assertSame( 'utf8mb4_unicode_520_nopad_ci', self::table_collation( $temporary ), 'the restored table, under the mapped name (an alias of the backup\'s would resolve to another)' );
		$this->assertSame( 2, $job->options['recorded']['collations_mapped'] ?? null, 'the table option and the column, recorded on the job for the swap to say' );
		$log = $this->log_of( $job );
		$this->assertSame( 2, substr_count( $log, 'A collation this server does not know is written under another name' ) );
		$this->assertStringContainsString( '"table":"' . $c . '","at":"table","from":"utf8mb4_0900_ai_ci","to":"utf8mb4_unicode_520_nopad_ci"', $log );
		$this->assertStringContainsString( '"table":"' . $c . '","at":"column:v","from":"utf8mb4_0900_ai_ci","to":"utf8mb4_unicode_520_nopad_ci"', $log );
		$this->assertSame( array( array( 'id' => '1', 'name' => 'a', 'v' => 'x' ), array( 'id' => '2', 'name' => 'B', 'v' => 'y' ) ), $this->rows_of( $temporary ), 'the rows came through' );
	}

	public function test_a_collation_with_no_name_this_server_knows_stops_the_restore_before_any_table_is_created_naming_every_table(): void {
		$c    = $this->p . 'c';
		$d    = $this->p . 'd';
		$base = $this->backup(
			array_merge( self::site_tables(), array( $c, $d ) ),
			static function ( string $table, array $chunks ) use ( $c, $d ): array {
				if ( $c === $table ) {
					// In a generated column's expression: a language-specific MySQL 8 collation no MariaDB knows and no rule maps.
					$chunks[0] = str_replace( '  PRIMARY KEY (`id`)', "  `u` varchar(20) GENERATED ALWAYS AS ((`v` COLLATE utf8mb4_ja_0900_as_cs)) VIRTUAL,\n  PRIMARY KEY (`id`)", $chunks[0] );
				}
				if ( $d === $table ) {
					$chunks[0] = str_replace( 'ENGINE=InnoDB', 'ENGINE=InnoDB COLLATE=utf8mb4_ja_0900_as_cs', $chunks[0] );
				}
				return $chunks;
			}
		);
		$this->assertArrayNotHasKey( 'utf8mb4_ja_0900_as_cs', RestorePreflightStep::known_collations(), 'the control: this server does not know it' );
		$job = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertSame( Job::FAILURE_FINAL, $job->failure_kind, 'for good: retrying would fail the same way' );
		$this->assertFalse( $job->retry_useful() );
		$error = (string) $job->last_error;
		$this->assertStringContainsString( 'Step "restore_preflight"', $error );
		$this->assertStringContainsString( 'This database server cannot take the collation utf8mb4_ja_0900_as_cs, which the backup uses. Tables that use it: ' . $c . ', ' . $d . '.', $error, 'every missing collation, every table, at once' );
		$this->assertSame( array(), $this->job_tables( $job ), 'nothing was created' );
		$this->assertSame( 2, substr_count( $this->log_of( $job ), 'A collation this server does not know has no name to write instead' ) );

		// The way out: without those two tables the same backup goes through.
		$this->assertSame( Job::COMPLETED, $this->run_restore( $this->start_restore( $base, array( 'exclude_tables' => array( $c, $d ) ) ) )->status );
	}

	public function test_the_servers_collations_are_read_by_their_full_names_which_show_collation_and_the_collations_view_do_not_give(): void {
		global $wpdb;
		$known = RestorePreflightStep::known_collations();
		$this->assertArrayHasKey( 'utf8mb4_general_ci', $known, 'the control: a name every server has' );
		$this->assertArrayHasKey( 'utf8mb4_bin', $known );
		$this->assertArrayNotHasKey( 'no_such_collation', $known );
		if ( false === stripos( (string) $wpdb->db_server_info(), 'mariadb' ) || version_compare( (string) $wpdb->db_version(), '10.10', '<' ) ) {
			$this->markTestSkipped( 'The trap shows on MariaDB 10.10 and later only.' );
		}
		$this->assertArrayHasKey( 'utf8mb4_uca1400_ai_ci', $known, 'the full name of a UCA 14 collation this server creates tables with' );
		$this->assertArrayHasKey( 'utf8mb4_uca1400_nopad_ai_ci', $known );
		foreach ( array( 'SHOW COLLATION', 'SELECT COLLATION_NAME FROM information_schema.COLLATIONS' ) as $other ) {
			$listed = array_map( 'strtolower', (array) $wpdb->get_col( $other ) );
			$this->assertNotContains( 'utf8mb4_uca1400_ai_ci', $listed, 'the trap: ' . $other . ' gives the generic name only, so a check reading it would map a name the server knows' );
			$this->assertContains( 'uca1400_ai_ci', $listed, 'the control: the generic name is what it gives' );
			$this->assertContains( 'utf8mb4_general_ci', $listed, 'the control: the listing is not empty' );
		}
	}
}
