<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Archive\ChunkHasher;
use WPCheckpoint\Archive\IndexLine;
use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Database\TableExporter;
use WPCheckpoint\Database\WpdbConnection;
use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\DatabaseExportStep;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\JobTypes;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Jobs\FixtureJobType;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * The export against a real database: awkward values, several chunks,
 * ticks that die after the last checkpoint, and a round trip through the
 * mysql client into a scratch database that must hold the same rows.
 */
final class DatabaseExportStepTest extends JobTestCase {

	const PREFIX  = 'wpcptest_';
	const SCRATCH = 'wpcheckpoint_test_import';
	const CHUNK   = 262144;

	/** @var float */
	private $now;

	/** @var JobRepository */
	private $repo;

	/** @var JobTypes */
	private $types;

	/** @var Directories */
	private $dirs;

	/** @var string[] */
	private $tables = array();

	public function set_up(): void {
		parent::set_up();
		$this->dirs  = new Directories( array( 'is_web_request' => false, 'document_root' => '' ) );
		$this->now   = 1_800_000_000.0;
		$this->repo  = new JobRepository( $this->dirs, null, function (): int {
			return (int) floor( $this->now );
		} );
		$this->types = new JobTypes();
		Schema::ensure();
		$this->create_fixture_tables();
	}

	public function tear_down(): void {
		global $wpdb;
		foreach ( $this->tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		$wpdb->query( 'DROP VIEW IF EXISTS `' . self::PREFIX . 'view`' );
		$wpdb->query( 'DROP DATABASE IF EXISTS `' . self::SCRATCH . '`' );
		parent::tear_down();
	}

	private function create_fixture_tables(): void {
		global $wpdb;
		$p = self::PREFIX;
		$this->tables = array( $p . 'posts', $p . 'blob', $p . 'rel', $p . 'nokey', $p . 'types', $p . 'cols' );
		foreach ( $this->tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		$wpdb->query( "DROP VIEW IF EXISTS `{$p}view`" );
		$wpdb->query( "CREATE TABLE `{$p}posts` (`ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `post_title` text NOT NULL, `post_content` longtext NOT NULL, `post_date` datetime DEFAULT NULL, `menu_order` int(11) NOT NULL DEFAULT 0, PRIMARY KEY (`ID`), KEY `post_date` (`post_date`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
		$wpdb->query( "CREATE TABLE `{$p}blob` (`id` int(11) NOT NULL, `data` blob, `fixed` binary(16) NOT NULL, `vb` varbinary(255) DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB" );
		$wpdb->query( "CREATE TABLE `{$p}rel` (`object_id` bigint(20) unsigned NOT NULL, `term_taxonomy_id` bigint(20) unsigned NOT NULL, `term_order` int(11) NOT NULL DEFAULT 0, PRIMARY KEY (`object_id`,`term_taxonomy_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
		$wpdb->query( "CREATE TABLE `{$p}nokey` (`k` varchar(40) DEFAULT NULL, `v` text) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
		$wpdb->query( "CREATE TABLE `{$p}types` (`id` int(11) NOT NULL, `price` decimal(10,2) DEFAULT NULL, `ratio` double DEFAULT NULL, `flag` tinyint(1) NOT NULL DEFAULT 0, `day` date DEFAULT NULL, `note` varchar(191) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
		$wpdb->query( "CREATE VIEW `{$p}view` AS SELECT `ID` FROM `{$p}posts`" );
		$this->assertSame( '', $wpdb->last_error );
		// A generated column (computed on import, never inserted) and an INVISIBLE column (left out by SELECT *,
		// which would shift every value one column over). INVISIBLE needs MariaDB 10.3+ or MySQL 8.0.23+.
		$wpdb->query( "CREATE TABLE `{$p}cols` (`id` int(11) NOT NULL, `a` varchar(20) DEFAULT NULL, `twice` int(11) AS (`id` * 2) STORED, `hidden` varchar(20) DEFAULT NULL INVISIBLE, `z` varchar(20) DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
		if ( '' !== $wpdb->last_error ) {
			if ( false !== getenv( 'CI' ) ) {
				$this->fail( 'The CI database must support generated and INVISIBLE columns: ' . $wpdb->last_error );
			}
			$wpdb->query( "CREATE TABLE `{$p}cols` (`id` int(11) NOT NULL, `a` varchar(20) DEFAULT NULL, `twice` int(11) AS (`id` * 2) STORED, `hidden` varchar(20) DEFAULT NULL, `z` varchar(20) DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
			$this->assertSame( '', $wpdb->last_error );
		}
		$wpdb->query( "INSERT INTO `{$p}cols` (`id`, `a`, `hidden`, `z`) VALUES (1, 'one', 'h1', 'z1'), (2, 'two', NULL, 'z2'), (3, NULL, 'h3', 'z3')" );
		$this->assertSame( '', $wpdb->last_error );

		$awkward = "😀 中文 'quote' \"dq\" back\\slash %s %d \x00nul \x1a sub \r\n line";
		$filler  = str_repeat( 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 12 );
		for ( $batch = 0; $batch < 30; $batch++ ) {
			$values = array();
			for ( $i = 0; $i < 100; $i++ ) {
				$n        = $batch * 100 + $i + 1;
				$values[] = $wpdb->prepare( '(%s, %s, %s, %d)', $awkward . ' ' . $n, $filler . $n, '2026-09-20 10:00:00', $n * 10 );
			}
			$wpdb->query( "INSERT INTO `{$p}posts` (`post_title`, `post_content`, `post_date`, `menu_order`) VALUES " . implode( ',', $values ) );
		}
		$wpdb->query( "UPDATE `{$p}posts` SET `post_date` = NULL WHERE `ID` % 7 = 0" );
		$blob = '';
		for ( $i = 0; $i < 256; $i++ ) {
			$blob .= chr( $i );
		}
		for ( $i = 1; $i <= 20; $i++ ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO `{$p}blob` (`id`, `data`, `fixed`, `vb`) VALUES (%d, %s, %s, %s)", $i, str_repeat( $blob, $i ), md5( (string) $i, true ), 0 === $i % 2 ? null : "\x00\xff'\"\\" ) );
		}
		$wpdb->query( "UPDATE `{$p}blob` SET `vb` = NULL WHERE `id` % 2 = 0" );
		$wpdb->query( "UPDATE `{$p}blob` SET `data` = NULL WHERE `id` = 3" );
		$values = array();
		for ( $o = 1; $o <= 40; $o++ ) {
			for ( $t = 1; $t <= 30; $t++ ) {
				$values[] = "({$o}, {$t}, " . ( $o * $t ) . ')';
			}
		}
		$wpdb->query( "INSERT INTO `{$p}rel` VALUES " . implode( ',', $values ) );
		$values = array();
		for ( $i = 0; $i < 700; $i++ ) {
			$values[] = $wpdb->prepare( '(%s, %s)', 'key' . ( $i % 350 ), str_repeat( 'v', $i % 50 ) . ' ' . $awkward );
		}
		$values[] = '(NULL, NULL)';
		$wpdb->query( "INSERT INTO `{$p}nokey` VALUES " . implode( ',', $values ) );
		$wpdb->query( "INSERT INTO `{$p}types` VALUES (1, 12.50, 0.1, 1, '2026-02-29', 'ß'), (2, NULL, NULL, 0, NULL, NULL), (3, -0.01, 1e-7, 1, '1970-01-01', ''), (4, 99999999.99, 123456789.125, 0, '2000-12-31', 'ÄÖÜ é')" );
		$this->assertSame( '', $wpdb->last_error );
	}

	private function runner( int $seconds ): Runner {
		return new Runner(
			$this->repo,
			$this->types,
			new Redactor( Redactor::installation_secrets() ),
			array(
				'clock'        => function (): float {
					$this->now += 1.0;
					return $this->now;
				},
				'memory'       => static function (): int {
					return 10 * 1048576;
				},
				'budget'       => new Budget( $seconds, 32 * 1048576, false ),
				'memory_limit' => -1,
				'paths'        => array( '{abspath}' => rtrim( ABSPATH, '/' ) ),
			)
		);
	}

	private function work( Job $job ): string {
		return Residue::work_dir( $job->storage_path, $job->id );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function index_lines( Job $job ): array {
		$lines = array_values( array_filter( explode( "\n", (string) file_get_contents( $this->work( $job ) . '/' . Manifest::DATABASE_INDEX ) ) ) );
		return array_map(
			static function ( string $line ): array {
				return IndexLine::database( $line, self::CHUNK );
			},
			$lines
		);
	}

	public function test_the_table_listing_separates_views_and_sorts_by_bytes(): void {
		$listing = ( new WpdbConnection() )->tables_with_prefix( self::PREFIX );
		$sorted  = $this->tables;
		sort( $sorted, SORT_STRING );
		$this->assertSame( $sorted, $listing['tables'] );
		$this->assertSame( array( self::PREFIX . 'view' ), $listing['views'] );
	}

	public function test_the_export_spans_ticks_survives_torn_writes_and_round_trips_through_the_mysql_client(): void {
		global $wpdb;
		$connection = new WpdbConnection();
		$listing    = $connection->tables_with_prefix( self::PREFIX );
		$tables     = array_merge( $listing['tables'], array( str_repeat( 'x', 65 ) ) );
		$notes      = array( 'View ' . self::PREFIX . 'view was not exported.' );
		$this->types->add( new FixtureJobType( 'db-only', array( new DatabaseExportStep( $connection, $tables, $notes, self::CHUNK ) ) ) );
		$job = $this->repo->create( 'db-only' );

		$result = $this->runner( 1 )->tick( $job->id, $this->now );
		$stored = $this->repo->find( $job->id );
		$this->assertSame( TickResult::MORE, $result->status, (string) $stored->last_error );
		$this->assertSame( Job::RUNNING, $stored->status );
		$this->assertSame( array( 'index', 'done', 'state', 'started_at' ), array_values( preg_grep( '/^__runner/', array_keys( $stored->cursor ), PREG_GREP_INVERT ) ) );
		$this->assertSame( array_keys( TableExporter::initial_state( 'x' ) ), array_keys( $stored->cursor['state'] ), 'the cursor holds the exporter state: positions only' );
		$this->assertStringNotContainsString( 'pk', wp_json_encode( $stored->cursor ), 'no key value ever reaches the cursor' );
		$frozen = json_decode( (string) file_get_contents( $this->work( $stored ) . '/' . DatabaseExportStep::TABLES ), true );
		$this->assertSame( $listing['tables'], $frozen['tables'], 'the bad name was dropped from the frozen list' );
		$this->assertCount( 2, $frozen['notes'] );
		$this->assertStringContainsString( 'was skipped', $frozen['notes'][1] );

		// A batch written after the last checkpoint, torn mid-marker: cut back on resume.
		$state = $stored->cursor['state'];
		$path  = $this->work( $stored ) . '/' . DatabaseExportStep::DIR . '/' . basename( IndexLine::database_path( (string) $state['table'], (int) $state['chunk'] ) );
		$this->assertFileExists( $path );
		file_put_contents( $path, "INSERT INTO `x` VALUES (1);\n-- wpcheckpoint batch rows=1 pk=[\"", FILE_APPEND );

		$ticks = 1;
		while ( TickResult::MORE === $result->status && $ticks < 500 ) {
			$result = $this->runner( 3 )->tick( $job->id, $this->now );
			++$ticks;
			$stored = $this->repo->find( $job->id );
			if ( ! isset( $simulated ) && TickResult::MORE === $result->status && is_array( $stored->cursor['state'] ) && self::PREFIX . 'posts' === $stored->cursor['state']['table'] ) {
				// A tick that closed a chunk and started the next one, then died before its checkpoint: the
				// files are ahead of the cursor. The next tick must cut the chunk back and close it again.
				$state = $stored->cursor['state'];
				$ahead = new TableExporter( $connection, $this->work( $stored ) . '/' . DatabaseExportStep::DIR, self::CHUNK );
				$guard = 0;
				while ( null === $state['closed'] && empty( $state['done'] ) && $guard++ < 100 ) {
					$state = $ahead->step( $state );
				}
				$this->assertNotNull( $state['closed'], 'a chunk closed while the cursor still points into it' );
				$this->assertFalse( $state['done'], 'the posts table is large enough that the close is not its last' );
				$this->assertFileExists( $this->work( $stored ) . '/' . DatabaseExportStep::DIR . '/' . basename( IndexLine::database_path( (string) $state['table'], (int) $state['chunk'] ) ), 'the next chunk was started' );
				$simulated = $state['closed'];
			}
		}
		$this->assertSame( TickResult::COMPLETED, $result->status, (string) $result->message );
		$this->assertGreaterThan( 3, $ticks );
		$this->assertTrue( isset( $simulated ) );
		$stored = $this->repo->find( $job->id );
		$this->assertSame( Job::COMPLETED, $stored->status );

		// The index: every chunk once, numbered from 1 per table, in frozen-list order, hashes matching the files.
		$lines    = $this->index_lines( $stored );
		$expected = array();
		$per      = array();
		foreach ( $lines as $line ) {
			$per[ $line['t'] ]   = ( $per[ $line['t'] ] ?? 0 ) + 1;
			$expected[]          = $line['t'];
			$this->assertSame( $per[ $line['t'] ], $line['c'], 'chunks are numbered consecutively per table' );
			$file = $this->work( $stored ) . '/' . DatabaseExportStep::DIR . '/' . basename( $line['p'] );
			$this->assertSame( $line['b'], filesize( $file ) );
			$this->assertSame( $line['h'], hash_file( 'sha256', $file ) );
			$this->assertLessThanOrEqual( self::CHUNK, $line['b'] );
		}
		$this->assertSame( $listing['tables'], array_values( array_unique( $expected ) ), 'table order follows the frozen list' );
		$this->assertGreaterThan( 3, $per[ self::PREFIX . 'posts' ], 'the posts table needed several chunks' );
		$this->assertCount( count( $lines ), array_unique( array_column( $lines, 'p' ) ), 'the chunk closed twice has one index line' );
		foreach ( $lines as $line ) {
			if ( $line['p'] === IndexLine::database_path( self::PREFIX . 'posts', $simulated['chunk'] ) ) {
				$this->assertSame( $simulated['hash'], $line['h'], 'the same rows give the same chunk' );
			}
		}

		// The summary: names and counts only, a period, no snapshot claim.
		$summary = json_decode( (string) file_get_contents( $this->work( $stored ) . '/' . DatabaseExportStep::SUMMARY ), true );
		$this->assertFalse( $summary['snapshot'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $summary['exported']['started_at'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $summary['exported']['finished_at'] );
		$this->assertSame( $listing['tables'], array_column( $summary['tables'], 'name' ) );
		$this->assertContains( $notes[0], $summary['warnings'] );
		$this->assertStringNotContainsString( 'CREATE TABLE', (string) file_get_contents( $this->work( $stored ) . '/' . DatabaseExportStep::SUMMARY ) );
		foreach ( $summary['tables'] as $table ) {
			$this->assertSame( array( 'name', 'rows', 'bytes', 'chunks', 'sha256' ), array_keys( $table ) );
			$hashes = array();
			foreach ( $lines as $line ) {
				if ( $line['t'] === $table['name'] ) {
					$hashes[] = $line['h'];
				}
			}
			$this->assertSame( ChunkHasher::list_hash( $hashes ), $table['sha256'] );
			$this->assertSame( count( $hashes ), $table['chunks'] );
			$this->assertSame( (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table['name']}`" ), $table['rows'] );
		}
		$nokey = $summary['tables'][ array_search( self::PREFIX . 'nokey', array_column( $summary['tables'], 'name' ), true ) ];
		$this->assertSame( 701, $nokey['rows'] );
		$this->assertCount( 1, preg_grep( '/no primary key/', $summary['warnings'] ) );
		$log = (string) file_get_contents( $this->dirs->base() . '/' . $stored->log_path );
		$this->assertStringContainsString( 'Table exported', $log );
		$this->assertStringContainsString( 'Database export finished', $log );
		$this->assertStringNotContainsString( 'Lorem', $log );

		// Round trip: the chunks in index order through the mysql client into a scratch database.
		$this->round_trip( $stored, $lines );
	}

	public function test_the_upper_bound_follows_the_servers_own_key_order_on_a_composite_string_key(): void {
		global $wpdb;
		$table          = self::PREFIX . 'bounded';
		$this->tables[] = $table;
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		// A case-insensitive collation: 'B' sorts with 'b'. Only the server's comparison gets that right.
		$wpdb->query( "CREATE TABLE `{$table}` (`g` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL, `n` int NOT NULL, `v` text, PRIMARY KEY (`g`, `n`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
		$wpdb->query( "INSERT INTO `{$table}` VALUES ('a',1,'old'),('a',2,'old'),('b',1,'old'),('b',3,'old'),('c',1,'old'),('c',3,'old')" );
		$this->assertSame( '', $wpdb->last_error );
		$dir = $this->dirs->base() . '/tmp/bounded-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir, 0700, true );
		$exporter = new TableExporter( new WpdbConnection(), $dir, self::CHUNK, 16 ); // A row or two per unit.
		$state    = $exporter->step( TableExporter::initial_state( $table ) );
		$this->assertLessThan( 4, $state['rows'], 'the table is not read in one unit' );
		// Written while the table is exported: inside the bound ("B",2 sorts between "b",1 and "b",3; "c",2 before "c",3), and past it.
		$wpdb->query( "INSERT INTO `{$table}` VALUES ('B',2,'new inside'),('c',2,'new inside'),('c',4,'new past'),('d',1,'new past')" );
		$this->assertSame( '', $wpdb->last_error );
		while ( empty( $state['done'] ) ) {
			$state = $exporter->step( $state );
		}
		$sql = (string) file_get_contents( $dir . '/' . basename( IndexLine::database_path( $table, 1 ) ) );
		Deleter::empty_directory( $dir );
		@rmdir( $dir );
		$this->assertStringContainsString( "\n-- wpcheckpoint bound pk_max=[\"c\",\"3\"]\n", $sql );
		$this->assertSame( 2, substr_count( $sql, "'new inside'" ), 'string keys that sort inside the bound are read' );
		$this->assertSame( 0, substr_count( $sql, "'new past'" ), 'keys past the bound are not' );
		$this->assertSame( 8, $state['rows'] );
	}

	/**
	 * The unit test measures the largest row over an in-memory connection;
	 * the real path also holds the driver's copy of the row, so it is
	 * measured here too, against the same 32 MB budget.
	 */
	public function test_the_largest_row_is_exported_within_the_step_memory_budget_over_wpdb(): void {
		global $wpdb;
		$table = self::PREFIX . 'bigrow';
		$this->tables[] = $table;
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		$wpdb->query( "CREATE TABLE `{$table}` (`option_id` bigint(20) NOT NULL, `option_value` longtext, PRIMARY KEY (`option_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
		$bytes = (int) floor( ( TableExporter::MAX_ROW_BYTES - 16 ) / 1.1 );
		$wpdb->query( "INSERT INTO `{$table}` VALUES (1, 'small'), (2, REPEAT('x', {$bytes})), (3, 'small')" );
		$this->assertSame( '', $wpdb->last_error );
		$dir = $this->dirs->base() . '/tmp/bigrow-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir, 0700, true );
		$exporter = new TableExporter( new WpdbConnection(), $dir );
		$state    = TableExporter::initial_state( $table );
		gc_collect_cycles();
		$before = memory_get_peak_usage( true );
		while ( empty( $state['done'] ) ) {
			$state = $exporter->step( $state );
		}
		$delta = memory_get_peak_usage( true ) - $before;
		$this->assertSame( 3, $state['rows'] );
		$this->assertLessThanOrEqual( 32 * 1048576, $delta, sprintf( 'Exporting a %d-byte row over wpdb peaked at %.1f MiB above the baseline.', $bytes, $delta / 1048576 ) );
		$this->assertStringContainsString( "(2,'" . str_repeat( 'x', $bytes ) . "')", (string) file_get_contents( $dir . '/' . basename( IndexLine::database_path( $table, 1 ) ) ) );
		Deleter::empty_directory( $dir );
		@rmdir( $dir );
	}

	/**
	 * @param array<int, array<string, mixed>> $lines Index lines in order.
	 */
	private function round_trip( Job $job, array $lines ): void {
		global $wpdb;
		$client = trim( (string) shell_exec( 'command -v mariadb || command -v mysql' ) );
		if ( '' === $client ) {
			if ( false !== getenv( 'CI' ) ) {
				$this->fail( 'The round trip through the mysql client is the proof that the export restores; CI must not skip it.' );
			}
			$this->markTestSkipped( 'No mysql client in this environment.' );
		}
		$wpdb->query( 'DROP DATABASE IF EXISTS `' . self::SCRATCH . '`' );
		$wpdb->query( 'CREATE DATABASE `' . self::SCRATCH . '` DEFAULT CHARACTER SET utf8mb4' );
		$this->assertSame( '', $wpdb->last_error, 'the test user can create the scratch database' );
		$host   = DB_HOST;
		$port   = '3306';
		if ( false !== strpos( $host, ':' ) ) {
			list( $host, $port ) = explode( ':', $host, 2 );
		}
		$files = array();
		foreach ( $lines as $line ) {
			$files[] = escapeshellarg( $this->work( $job ) . '/' . DatabaseExportStep::DIR . '/' . basename( $line['p'] ) );
		}
		$command = sprintf(
			'cat %s | MYSQL_PWD=%s %s --host=%s --port=%s --user=%s --default-character-set=utf8mb4 %s 2>&1',
			implode( ' ', $files ),
			escapeshellarg( DB_PASSWORD ),
			escapeshellarg( $client ),
			escapeshellarg( $host ),
			escapeshellarg( $port ),
			escapeshellarg( DB_USER ),
			escapeshellarg( self::SCRATCH )
		);
		$output = (string) shell_exec( $command );
		$this->assertDoesNotMatchRegularExpression( '/ERROR/', $output, 'the client imported every chunk without an error (warnings about the client itself are fine)' );

		foreach ( $this->tables as $table ) {
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" );
			$order   = implode( ', ', array_map( static function ( string $c ): string {
				return "`{$c}`";
			}, $columns ) );
			$source  = $wpdb->get_results( "SELECT {$order} FROM `" . DB_NAME . "`.`{$table}` ORDER BY {$order}", ARRAY_N );
			$copy    = $wpdb->get_results( "SELECT {$order} FROM `" . self::SCRATCH . "`.`{$table}` ORDER BY {$order}", ARRAY_N );
			$this->assertSame( '', $wpdb->last_error );
			$this->assertNotEmpty( $source );
			$this->assertSame( count( $source ), count( $copy ), $table );
			$this->assertSame( $source, $copy, "every row of {$table} came back byte for byte" );
			if ( self::PREFIX . 'cols' === $table ) {
				$this->assertSame( array( 'id', 'a', 'twice', 'hidden', 'z' ), $columns );
				$this->assertSame( array( '1', 'one', '2', 'h1', 'z1' ), $copy[0], 'the generated column was computed on import and the invisible column kept its value' );
				$this->assertSame( array( '2', 'two', '4', null, 'z2' ), $copy[1] );
				$chunk = (string) file_get_contents( $this->work( $job ) . '/' . DatabaseExportStep::DIR . '/' . basename( IndexLine::database_path( $table, 1 ) ) );
				$this->assertStringContainsString( "INSERT INTO `{$table}` (`id`, `a`, `hidden`, `z`) VALUES (1,'one','h1','z1'),(2,'two',NULL,'z2'),(3,NULL,'h3','z3');", $chunk );
			}
			$this->assertSame(
				$wpdb->get_row( "SHOW CREATE TABLE `" . DB_NAME . "`.`{$table}`", ARRAY_N )[1],
				$wpdb->get_row( 'SHOW CREATE TABLE `' . self::SCRATCH . "`.`{$table}`", ARRAY_N )[1],
				"the structure of {$table} came back as it was"
			);
		}
	}
}
