<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Archive\Packer;
use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Database\TableExporter;
use WPCheckpoint\Database\OwnTables;
use WPCheckpoint\Database\WpdbConnection;
use WPCheckpoint\Files\PathKey;
use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\DatabaseExportStep;
use WPCheckpoint\Jobs\ExportPlan;
use WPCheckpoint\Jobs\FileScanStep;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\JobTypes;
use WPCheckpoint\Jobs\PreflightStep;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Jobs\ReviewStep;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Jobs\FixtureJobType;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * Preflight, Scan, Review and Database in a row against the real
 * database and the real uploads directory: the questions the review
 * asks, the answers taking effect, the policies, and the agreement
 * between the PHP estimate and MariaDB's evaluation of the predicate.
 */
final class ExportPreflightTest extends JobTestCase {

	const PREFIX = 'wpcptest_';
	const CHUNK  = 262144;

	/** @var float */
	private $now;

	/** @var JobRepository */
	private $repo;

	/** @var JobTypes */
	private $types;

	/** @var Directories */
	private $dirs;

	/** @var string */
	private $uploads;

	/** @var string[] */
	private $tables = array();

	public function set_up(): void {
		parent::set_up();
		$this->dirs  = new Directories(
			array(
				'is_web_request' => false,
				'document_root'  => '',
			)
		);
		$this->now   = 1_800_000_000.0;
		$this->repo  = new JobRepository(
			$this->dirs,
			null,
			function (): int {
				return (int) floor( $this->now );
			}
		);
		$this->types = new JobTypes();
		Schema::ensure();
		$this->uploads = wp_upload_dir()['basedir'] . '/wpcptest-preflight';
		\WPCheckpoint\Support\Deleter::allow( $this->uploads ); // Made by this test under the site's own directories: registered to be deleted.
		mkdir( $this->uploads . '/node_modules/pkg', 0755, true );
		mkdir( $this->uploads . '/images', 0755, true );
		file_put_contents( $this->uploads . '/images/a.jpg', 'jpeg' );
		file_put_contents( $this->uploads . '/images/b.jpg', 'jpeg' );
		foreach ( array( 'one', 'two' ) as $name ) {
			$handle = fopen( $this->uploads . '/node_modules/pkg/' . $name . '.bin', 'wb' );
			ftruncate( $handle, 30 * 1048576 ); // Sparse: counts as 30 MB without writing it.
			fclose( $handle );
		}
		$this->create_tables();
	}

	public function tear_down(): void {
		global $wpdb;
		Deleter::empty_directory( $this->uploads );
		@rmdir( $this->uploads );
		foreach ( $this->tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		$wpdb->query( 'DROP VIEW IF EXISTS `' . self::PREFIX . 'view`' );
		parent::tear_down();
	}

	private function create_tables(): void {
		global $wpdb;
		$p            = self::PREFIX;
		$this->tables = array( $p . 'options', $p . 'posts' );
		foreach ( $this->tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		$wpdb->query( "DROP VIEW IF EXISTS `{$p}view`" );
		$wpdb->query( "CREATE TABLE `{$p}options` (`option_id` bigint(20) NOT NULL AUTO_INCREMENT, `option_name` varchar(191) NOT NULL, `option_value` longtext, `blob_value` longblob, PRIMARY KEY (`option_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
		$wpdb->query( "CREATE TABLE `{$p}posts` (`ID` bigint(20) NOT NULL AUTO_INCREMENT, `post_title` text, PRIMARY KEY (`ID`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
		$wpdb->query( "CREATE VIEW `{$p}view` AS SELECT `ID` FROM `{$p}posts`" );
		$this->assertSame( '', $wpdb->last_error );
		// Borderline rows around the 258048-byte limit of a 256 KiB chunk: NULLs, binary, two long columns.
		$rows = array(
			"( 'small', 'v', NULL )",
			"( 'text_over', REPEAT('x', 240000), NULL )",
			"( 'text_under', REPEAT('x', 200000), NULL )",
			"( 'blob_over', NULL, REPEAT(UNHEX('FF'), 120000) )",
			"( 'blob_under', NULL, REPEAT(UNHEX('FF'), 100000) )",
			"( 'both', REPEAT('y', 150000), REPEAT('z', 45000) )",
			"( 'null_row', NULL, NULL )",
			"( 'edge_under', REPEAT('e', 234560), NULL )",
			"( 'edge_over', REPEAT('e', 234590), NULL )",
		);
		$wpdb->query( "INSERT INTO `{$p}options` (`option_name`, `option_value`, `blob_value`) VALUES " . implode( ', ', $rows ) );
		$wpdb->query( "INSERT INTO `{$p}posts` (`post_title`) VALUES ('a'), ('b'), ('c')" );
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

	/**
	 * @param array<string, mixed> $env Preflight environment entries replacing the defaults.
	 */
	private function register_export( string $id, array $env = array() ): void {
		$connection = new WpdbConnection();
		$this->types->add(
			new FixtureJobType(
				$id,
				array(
					new PreflightStep( $connection, array_replace( $this->env( $connection ), $env ), self::CHUNK ),
					FileScanStep::from_plan(),
					new ReviewStep(),
					DatabaseExportStep::from_plan( $connection, self::CHUNK ),
				)
			)
		);
	}

	/**
	 * The pre-flight's environment in these tests.
	 *
	 * @return array<string, mixed>
	 */
	private function env( WpdbConnection $connection ): array {
		$dirs = $this->dirs;
		return array(
			'prefix'        => self::PREFIX,
			'tables'        => array( $connection, 'tables_with_prefix' ),
			'writable'      => static function () use ( $dirs ): array {
				$bad = array();
				foreach ( Directories::SUBDIRS as $sub ) {
					if ( ! is_writable( $dirs->base() . '/' . $sub ) ) {
						$bad[] = $sub;
					}
				}
				return $bad;
			},
			'disk_free'     => static function () use ( $dirs ) {
				return disk_free_space( $dirs->base() );
			},
			'slug'          => static function (): string {
				return 'Example.Test Site';
			},
			'can_deflate'   => true,
			'normalization' => PathKey::normalization_available(),
			'int_size'      => PHP_INT_SIZE,
			'now'           => function (): int {
				return (int) $this->now;
			},
		);
	}

	private function work( Job $job ): string {
		return Residue::work_dir( $job->storage_path, $job->id );
	}

	/**
	 * Tick until the job stops being "more".
	 */
	private function drive( int $id ): TickResult {
		for ( $i = 0; $i < 500; $i++ ) {
			$result = $this->runner( 3 )->tick( $id, $this->now );
			if ( TickResult::MORE !== $result->status ) {
				return $result;
			}
		}
		$this->fail( 'the job did not stop' );
	}

	/**
	 * Ids of the options rows the PHP estimate calls oversized, from LENGTH() values fetched here.
	 *
	 * @return string[]
	 */
	private function oversized_by_php( TableExporter $exporter ): array {
		global $wpdb;
		$desc = $exporter->describe( self::PREFIX . 'options' );
		$rows = $wpdb->get_results( 'SELECT `option_id`, LENGTH(`option_id`), LENGTH(`option_name`), LENGTH(`option_value`), LENGTH(`blob_value`) FROM `' . self::PREFIX . 'options` ORDER BY `option_id`', ARRAY_N );
		$ids  = array();
		foreach ( $rows as $row ) {
			$lengths = array_map(
				static function ( $v ) {
					return null === $v ? null : (int) $v;
				},
				array_slice( $row, 1 )
			);
			if ( TableExporter::estimate_row_bytes( $lengths, $desc['kinds'], $exporter->hex_all() ) > $exporter->row_limit() ) {
				$ids[] = (string) $row[0];
			}
		}
		return $ids;
	}

	public function test_a_stop_at_the_review_is_asked_again_on_retry_and_the_exclusions_stay(): void {
		$this->register_export( 'export-stop' );
		$job = $this->repo->create( 'export-stop', 0, array(), array( 'contents' => array( 'files' => array( 'uploads' ) ) ) );
		$this->assertSame( TickResult::PAUSED, $this->drive( $job->id )->status, (string) $this->repo->find( $job->id )->last_error );
		$paused = $this->repo->find( $job->id );
		$ids    = array_column( $paused->questions, 'id' );
		$this->assertContains( 'oversize_0', $ids );
		$answers = array(
			'large_dir_0' => 'exclude',
			'oversize_0'  => 'stop',
		);
		if ( in_array( 'unreadable', $ids, true ) ) {
			$answers['unreadable'] = 'continue';
		}
		$this->repo->answer( $paused, $answers );
		$this->drive( $job->id );
		$failed = $this->repo->find( $job->id );
		$this->assertSame( Job::FAILED, $failed->status );
		$this->assertStringContainsString( 'rows larger than the single-row limit', (string) $failed->last_error );
		unset( $answers['oversize_0'] );
		$this->assertSame( $answers, $failed->options['answers'], 'the stop went with the failure; the exclusion stays' );

		$this->repo->transition( $failed, Job::QUEUED );
		$this->assertSame( TickResult::PAUSED, $this->drive( $job->id )->status );
		$this->assertSame( array( 'oversize_0' ), array_column( $this->repo->find( $job->id )->questions, 'id' ), 'only the stopped question is asked again' );
	}

	public function test_the_review_asks_the_answers_take_effect_and_mariadb_agrees_with_the_estimate(): void {
		global $wpdb;
		$this->register_export( 'export-b' );
		$job    = $this->repo->create( 'export-b', 0, array(), array( 'contents' => array( 'files' => array( 'uploads' ) ) ) );
		$result = $this->drive( $job->id );
		$this->assertSame( TickResult::PAUSED, $result->status, (string) $this->repo->find( $job->id )->last_error );
		$paused = $this->repo->find( $job->id );
		$this->assertSame( ReviewStep::ID, $paused->step );
		$ids = array_column( $paused->questions, 'id' );
		$this->assertContains( 'large_dir_0', $ids );
		$this->assertContains( 'oversize_0', $ids );
		$oversize = $paused->questions[ array_search( 'oversize_0', $ids, true ) ];
		$this->assertSame( 'oversize', $oversize['kind'], 'a small table is counted exactly' );

		// plan.json and preflight.json as the steps left them.
		$work = $this->work( $paused );
		$plan = ExportPlan::read( $work, ExportPlan::PLAN );
		$this->assertSame( array( self::PREFIX . 'options', self::PREFIX . 'posts' ), $plan['tables'] );
		$this->assertMatchesRegularExpression( '/\Aexample-test-site-\d{8}-\d{6}-[0-9a-f]{4}\z/', $plan['base'] );
		$this->assertSame( array( 'uploads' ), $plan['groups'] );
		$this->assertCount( 1, preg_grep( '/View wpcptest_view is not part of the backup/', $plan['notes'] ) );
		$preflight = ExportPlan::read( $work, ExportPlan::PREFLIGHT );
		$this->assertSame( self::PREFIX . 'options', $preflight['findings']['oversize'][0]['table'] );
		$exporter = new TableExporter( new WpdbConnection(), $work, self::CHUNK );
		$by_php   = $this->oversized_by_php( $exporter );
		$this->assertSame( array( '2', '4', '6', '9' ), $by_php, 'text_over, blob_over, both, edge_over; the rest is under the limit by the PHP estimate' );
		$this->assertSame( count( $by_php ), $preflight['findings']['oversize'][0]['count'], 'MariaDB evaluating the predicate agrees with the PHP estimate row for row' );
		$this->assertSame( $oversize['count'], count( $by_php ) );
		$review = ExportPlan::read( $work, ExportPlan::REVIEW );
		$this->assertArrayNotHasKey( 'decisions', $review );
		$this->assertSame( 'wp-content/uploads/wpcptest-preflight/node_modules', $review['findings']['heavy'][0]['p'] );
		$this->assertSame( 60 * 1048576, $review['findings']['heavy'][0]['bytes'] );
		$scan = ExportPlan::read( $work, FileScanStep::SUMMARY );
		$this->assertSame( array( 'wp-content/uploads/wpcptest-preflight/node_modules' => 60 * 1048576 ), array_intersect_key( $scan['lists']['heavy'], array( 'wp-content/uploads/wpcptest-preflight/node_modules' => 1 ) ) );
		$index = (string) file_get_contents( $work . '/files.index.jsonl' );
		$this->assertStringContainsString( 'wp-content/uploads/wpcptest-preflight/images/a.jpg', $index, 'the scan took its group from the plan' );
		$this->assertStringNotContainsString( 'wp-content/plugins/', $index );

		// Ticking again does not resume; the review file is untouched.
		$before = (string) file_get_contents( $work . '/' . ExportPlan::REVIEW );
		$this->assertSame( TickResult::PAUSED, $this->runner( 3 )->tick( $job->id, $this->now )->status );
		$this->assertSame( $before, (string) file_get_contents( $work . '/' . ExportPlan::REVIEW ) );

		// Answer: leave the heavy directory and the oversized rows out.
		$answers = array(
			'large_dir_0' => 'exclude',
			'oversize_0'  => 'exclude',
		);
		if ( in_array( 'unreadable', $ids, true ) ) {
			$answers['unreadable'] = 'continue';
		}
		$this->repo->answer( $paused, $answers );
		$result = $this->drive( $job->id );
		$this->assertSame( TickResult::COMPLETED, $result->status, (string) $this->repo->find( $job->id )->last_error );
		$review = ExportPlan::read( $work, ExportPlan::REVIEW );
		$this->assertSame( array( self::PREFIX . 'options' ), $review['decisions']['exclude_oversize'] );
		$this->assertSame( array( 'wp-content/uploads/wpcptest-preflight/node_modules' ), $review['decisions']['exclude_paths'] );
		$this->assertTrue( $review['asked'] );
		$frozen = json_decode( (string) file_get_contents( $work . '/' . DatabaseExportStep::TABLES ), true );
		$this->assertSame( array( self::PREFIX . 'options' ), $frozen['exclude_oversize'] );
		$summary = ExportPlan::read( $work, DatabaseExportStep::SUMMARY );
		$this->assertSame( 9 - count( $by_php ), $summary['tables'][0]['rows'], 'exactly the rows MariaDB and the estimate call oversized were left out' );
		$this->assertSame( 3, $summary['tables'][1]['rows'] );
		$this->assertCount( 1, preg_grep( '/rows larger than the single-row limit of \d+ bytes \(as SQL\) were left out, as chosen/', $summary['warnings'] ) );
		$sql = '';
		foreach ( glob( $work . '/database/' . self::PREFIX . 'options.*.sql' ) ?: array() as $chunk ) {
			$sql .= (string) file_get_contents( $chunk );
		}
		$this->assertStringContainsString( "'null_row',NULL,NULL", $sql, 'the NULL row stays' );
		$this->assertStringContainsString( "'edge_under'", $sql );
		$this->assertStringNotContainsString( "'edge_over'", $sql );
		$this->assertStringNotContainsString( "'text_over'", $sql );
		$log = (string) file_get_contents( $this->dirs->base() . '/' . $this->repo->find( $job->id )->log_path );
		$this->assertStringContainsString( 'was left out of the backup, as chosen', $log );
		$this->assertStringNotContainsString( $wpdb->dbname ?? '###', $log );
	}

	public function test_an_unattended_policy_never_asks_and_a_fail_policy_stops_with_the_table(): void {
		$this->register_export( 'export-b' );
		$job    = $this->repo->create(
			'export-b',
			0,
			array(),
			array(
				'contents' => array( 'files' => array( 'uploads' ) ),
				'policy'   => array(
					'unreadable' => 'continue',
					'oversize'   => 'exclude',
					'large_dirs' => 'include',
				),
			)
		);
		$result = $this->drive( $job->id );
		$this->assertSame( TickResult::COMPLETED, $result->status, (string) $this->repo->find( $job->id )->last_error );
		$review = ExportPlan::read( $this->work( $this->repo->find( $job->id ) ), ExportPlan::REVIEW );
		$this->assertFalse( $review['asked'] );
		$this->assertSame( array( self::PREFIX . 'options' ), $review['decisions']['exclude_oversize'] );
		$this->assertSame( array(), $review['decisions']['exclude_paths'] );

		$job    = $this->repo->create(
			'export-b',
			0,
			array(),
			array(
				'contents' => array( 'files' => array( 'uploads' ) ),
				'policy'   => array(
					'unreadable' => 'continue',
					'oversize'   => 'fail',
					'large_dirs' => 'include',
				),
			)
		);
		$result = $this->drive( $job->id );
		$this->assertSame( TickResult::FAILED, $result->status );
		$failed = $this->repo->find( $job->id );
		$this->assertSame( ReviewStep::ID, $failed->step );
		$this->assertStringContainsString( 'The backup was stopped as chosen: table wpcptest_options has rows larger than the single-row limit', $failed->last_error );
		$this->assertStringContainsString( '(4 rows)', $failed->last_error );
	}

	public function test_free_space_beyond_a_32_bit_integer_passes_and_the_database_is_counted_by_its_data(): void {
		// A float from disk_free_space() cast to int wraps on 32-bit PHP (and beyond PHP_INT_MAX everywhere).
		$this->register_export(
			'export-b',
			array(
				'disk_free' => static function (): float {
					return 1e19;
				},
			)
		);
		$job    = $this->repo->create(
			'export-b',
			0,
			array(),
			array(
				'contents' => array( 'files' => array( 'uploads' ) ),
				'policy'   => array(
					'unreadable' => 'continue',
					'oversize'   => 'exclude',
					'large_dirs' => 'include',
				),
			)
		);
		$result = $this->drive( $job->id );
		$this->assertSame( TickResult::COMPLETED, $result->status, (string) $this->repo->find( $job->id )->last_error );
		$work      = $this->work( $this->repo->find( $job->id ) );
		$preflight = ExportPlan::read( $work, ExportPlan::PREFLIGHT );
		$data      = 0.0;
		foreach ( ExportPlan::read( $work, ExportPlan::PLAN )['stats'] as $row ) {
			$data += (float) $row['data_bytes'];
		}
		$this->assertEquals( $data, $preflight['checks']['db_bytes'], 'the table data only: index pages never reach the SQL' );
		$this->assertEquals( 1e19, $preflight['checks']['disk_free'] );
	}

	public function test_a_table_listing_that_fails_is_retried_and_never_becomes_a_backup_without_the_database(): void {
		$this->register_export( 'export-b' );
		$break = static function ( $query ) {
			return 0 === strpos( ltrim( (string) $query ), 'SHOW FULL TABLES' ) ? 'SHOW FULL TABLES FROM `wpcheckpoint_no_such_database`' : $query;
		};
		add_filter( 'query', $break );
		$job    = $this->repo->create( 'export-b', 0, array(), array( 'contents' => array( 'files' => array( 'uploads' ) ), 'policy' => array( 'unreadable' => 'continue', 'oversize' => 'exclude', 'large_dirs' => 'include' ) ) );
		$result = $this->drive( $job->id );
		remove_filter( 'query', $break );
		$this->assertSame( TickResult::WAITING, $result->status, 'a retryable failure, not a finished step' );
		$stored = $this->repo->find( $job->id );
		$this->assertSame( PreflightStep::ID, $stored->step );
		// A retry carries its reason in the tick result and the job log; last_error is for a final failure.
		$this->assertStringContainsString( 'The list of tables could not be read from the database', $result->message );
		$this->assertStringNotContainsString( DB_NAME, $result->message );
		$this->assertFalse( ExportPlan::exists( $this->work( $stored ), ExportPlan::PLAN ), 'no plan was written from the empty listing' );
	}

	public function test_a_listing_without_this_sites_own_tables_stops_the_backup(): void {
		// A listing that lost the site's own tables (a filtered or failed query): never a backup without the database.
		$connection = new WpdbConnection();
		$this->register_export(
			'export-b',
			array(
				'tables' => static function ( string $prefix ) use ( $connection ): array {
					$listing           = $connection->tables_with_prefix( $prefix );
					$listing['tables'] = array_values( array_diff( $listing['tables'], array( $prefix . 'posts' ) ) );
					return $listing;
				},
			)
		);
		$job    = $this->repo->create(
			'export-b',
			0,
			array(),
			array(
				'contents' => array( 'files' => array( 'uploads' ) ),
				'policy'   => array(
					'unreadable' => 'continue',
					'oversize'   => 'exclude',
					'large_dirs' => 'include',
				),
			)
		);
		$result = $this->drive( $job->id );
		$this->assertSame( TickResult::FAILED, $result->status );
		$failed = $this->repo->find( $job->id );
		$this->assertSame( PreflightStep::ID, $failed->step );
		$this->assertStringContainsString( "The database did not list this site's own tables (wpcptest_posts missing)", $failed->last_error );

		// Files only: the listing is not consulted.
		$job = $this->repo->create(
			'export-b',
			0,
			array(),
			array(
				'contents' => array(
					'database' => false,
					'files'    => array( 'uploads' ),
				),
				'policy'   => array(
					'unreadable' => 'continue',
					'oversize'   => 'exclude',
					'large_dirs' => 'include',
				),
			)
		);
		$this->assertSame( TickResult::COMPLETED, $this->drive( $job->id )->status, (string) $this->repo->find( $job->id )->last_error );
	}

	public function test_the_plugins_own_tables_are_left_out_by_name_and_a_site_with_a_prefix_like_them_keeps_every_table(): void {
		global $wpdb;
		$connection = new WpdbConnection();
		// Names this plugin makes (any installation's: another token), in each of their forms.
		$generated = array(
			TempTables::name( 'abcdef12', 7, '1a2b', 'posts' ),
			TempTables::name( 'abcdef12', 7, '1a2b', str_repeat( 'long_name_', 6 ) ),
			TempTables::ledger( 'abcdef12', 7, '1a2b' ),
			TempTables::old( '0123456789', 7, '1a2b', 'options' ),
		);
		$this->assertStringEndsWith( '_' . substr( hash( 'sha256', str_repeat( 'long_name_', 6 ) ), 0, 7 ), $generated[1], 'the control: the cut form with its hash is among them' );
		foreach ( array( 'wcp_', 'w', 'wc' ) as $prefix ) {
			// The site's own tables, some of them spelled much like the plugin's names but not in their form.
			$site = array( $prefix . 'options', $prefix . 'posts', $prefix . 'wc_orders', $prefix . 'cptmp_notes', $prefix . 'ptmpabcdef12_notes' );
			$mine = OwnTables::names( $prefix );
			foreach ( array_merge( $site, $mine, $generated ) as $table ) {
				$this->tables[] = $table;
				$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
				$wpdb->query( "CREATE TABLE `{$table}` (`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB" );
			}
			$this->assertSame( '', $wpdb->last_error );
			$id = 'export-own-' . rtrim( $prefix, '_' );
			$this->types->add(
				new FixtureJobType(
					$id,
					array(
						new PreflightStep(
							$connection,
							array_replace(
								$this->env( $connection ),
								array(
									'prefix' => $prefix,
									'own'    => static function ( string $table ) use ( $prefix ): bool {
										return OwnTables::is_own( $table, $prefix );
									},
								)
							),
							self::CHUNK
						),
					)
				)
			);
			$job = $this->repo->create( $id, 0, array(), array( 'contents' => array( 'files' => array() ) ) );
			$this->assertSame( TickResult::COMPLETED, $this->drive( $job->id )->status, (string) $this->repo->find( $job->id )->last_error );
			$planned = array_map( 'strval', (array) ExportPlan::read( $this->work( $this->repo->find( $job->id ) ), ExportPlan::PLAN )['tables'] );
			foreach ( $site as $table ) {
				$this->assertContains( $table, $planned, $prefix . ': the site\'s table stays in the backup' );
			}
			foreach ( array_merge( $mine, $generated ) as $table ) {
				if ( 0 === strpos( $table, $prefix ) ) {
					$this->assertNotContains( $table, $planned, $prefix . ': the plugin\'s own table is left out' );
				}
			}
			$listed = $connection->tables_with_prefix( $prefix )['tables'];
			$this->assertNotSame( array(), array_intersect( $mine, $listed ), 'the control: the listing of ' . $prefix . ' holds the plugin\'s tables by name' );
			if ( 'wcp_' !== $prefix ) {
				$this->assertSame( $generated, array_values( array_intersect( $generated, $listed ) ), 'the control: the listing of ' . $prefix . ' holds every generated name' );
			}
		}
	}

	public function test_triggers_procedures_functions_and_events_are_named_in_the_notes_and_the_database_summary(): void {
		global $wpdb;
		$p          = self::PREFIX;
		$connection = new WpdbConnection();
		$this->tables[] = 'wpcpother_t';
		$wpdb->query( 'CREATE TABLE `wpcpother_t` (`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB' );
		$made = array(
			'DROP TRIGGER IF EXISTS `wpcptest_count_posts`'   => "CREATE TRIGGER `wpcptest_count_posts` BEFORE INSERT ON `{$p}posts` FOR EACH ROW SET NEW.post_title = NEW.post_title",
			'DROP TRIGGER IF EXISTS `wpcptest_other_trigger`' => 'CREATE TRIGGER `wpcptest_other_trigger` BEFORE INSERT ON `wpcpother_t` FOR EACH ROW SET NEW.id = NEW.id',
			'DROP PROCEDURE IF EXISTS `wpcptest_tidy`'        => 'CREATE PROCEDURE `wpcptest_tidy`() SELECT 1',
			'DROP FUNCTION IF EXISTS `wpcptest_slugify`'      => 'CREATE FUNCTION `wpcptest_slugify`( t TEXT ) RETURNS TEXT DETERMINISTIC RETURN LOWER( t )',
			'DROP EVENT IF EXISTS `wpcptest_nightly`'         => 'CREATE EVENT `wpcptest_nightly` ON SCHEDULE EVERY 1 DAY DISABLE DO SELECT 1',
		);
		try {
			foreach ( $made as $drop => $create ) {
				$wpdb->query( $drop );
				$wpdb->query( $create );
				$this->assertSame( '', $wpdb->last_error, $create );
			}
			$this->register_export( 'export-routines', array( 'routines' => array( $connection, 'routines' ) ) );
			$job = $this->repo->create(
				'export-routines',
				0,
				array(),
				array(
					'contents' => array( 'files' => array() ),
					'policy'   => array(
						'unreadable' => 'continue',
						'oversize'   => 'exclude',
						'large_dirs' => 'include',
					),
				)
			);
			$this->assertSame( TickResult::COMPLETED, $this->drive( $job->id )->status, (string) $this->repo->find( $job->id )->last_error );
			$work  = $this->work( $this->repo->find( $job->id ) );
			$notes = implode( "\n", (array) ExportPlan::read( $work, ExportPlan::PLAN )['notes'] );
			$this->assertStringContainsString( "wpcptest_count_posts (on {$p}posts)", $notes, 'a trigger on a table of the backup' );
			$this->assertStringNotContainsString( 'wpcptest_other_trigger', $notes, 'not one on a table outside it' );
			$this->assertStringContainsString( 'procedure wpcptest_tidy', $notes );
			$this->assertStringContainsString( 'function wpcptest_slugify', $notes );
			$this->assertStringContainsString( 'wpcptest_nightly', $notes );
			$this->assertStringContainsString( 'View ' . $p . 'view is not part of the backup', $notes, 'the control: next to the views, as they are' );
			// On to the manifest's warnings: the database export's summary carries them.
			$summary = (string) wp_json_encode( ExportPlan::read( $work, DatabaseExportStep::SUMMARY ) );
			$this->assertStringContainsString( 'wpcptest_count_posts', $summary );
			$this->assertStringContainsString( 'wpcptest_nightly', $summary );
		} finally {
			foreach ( array_keys( $made ) as $drop ) {
				$wpdb->query( $drop );
			}
		}
	}

	public function test_bad_options_and_a_database_only_export_are_handled_by_the_preflight(): void {
		$this->register_export( 'export-b' );
		$job    = $this->repo->create( 'export-b', 0, array(), array( 'contents' => array( 'files' => array( 'media' ) ) ) );
		$result = $this->drive( $job->id );
		$this->assertSame( TickResult::FAILED, $result->status );
		$this->assertStringContainsString( 'Unknown content group "media"', $this->repo->find( $job->id )->last_error );

		$job    = $this->repo->create(
			'export-b',
			0,
			array(),
			array(
				'contents'       => array( 'files' => array() ),
				'exclude_tables' => array( self::PREFIX . 'options' ),
				'policy'         => array(
					'unreadable' => 'continue',
					'oversize'   => 'fail',
					'large_dirs' => 'include',
				),
			)
		);
		$result = $this->drive( $job->id );
		$this->assertSame( TickResult::COMPLETED, $result->status, (string) $this->repo->find( $job->id )->last_error );
		$work = $this->work( $this->repo->find( $job->id ) );
		$this->assertSame( array( self::PREFIX . 'posts' ), ExportPlan::read( $work, ExportPlan::PLAN )['tables'], 'the excluded table never reaches the row check' );
		$this->assertSame( array(), ExportPlan::read( $work, ExportPlan::PREFLIGHT )['findings']['oversize'] );
		$this->assertSame( array(), json_decode( (string) file_get_contents( $work . '/files.index.jsonl' ), true ) ?? array(), 'no content groups: an empty scan' );
		$this->assertSame( 3, ExportPlan::read( $work, DatabaseExportStep::SUMMARY )['tables'][0]['rows'] );
	}

	/**
	 * The preflight runs with a 256 KiB database chunk while the scan uses the default content chunk:
	 * the limit in the review message must be the one the scanner judged with, read from its summary.
	 */
	public function test_a_file_too_large_to_index_stops_the_review_with_the_threshold_the_scan_used(): void {
		if ( PHP_INT_SIZE < 8 ) {
			$this->markTestSkipped( 'The index limit is above the platform integer on 32-bit PHP.' );
		}
		$limit = Packer::max_file_bytes( Manifest::DEFAULT_CHUNK )['bytes'];
		$huge  = $this->uploads . '/images/huge.iso';
		$h     = fopen( $huge, 'wb' );
		$ok    = 0 === fseek( $h, $limit + 1 ) && 1 === fwrite( $h, 'x' );
		fclose( $h );
		clearstatcache( true, $huge );
		if ( ! $ok || filesize( $huge ) !== $limit + 2 ) {
			unlink( $huge );
			$this->markTestSkipped( 'A sparse file above the index limit cannot be created here.' );
		}
		$this->register_export( 'export-b' );
		$job    = $this->repo->create(
			'export-b',
			0,
			array(),
			array(
				'contents' => array( 'files' => array( 'uploads' ) ),
				'policy'   => array(
					'unreadable' => 'continue',
					'oversize'   => 'exclude',
					'large_dirs' => 'include',
				),
			)
		);
		$result = $this->drive( $job->id );
		$this->assertSame( TickResult::FAILED, $result->status );
		$failed = $this->repo->find( $job->id );
		$this->assertSame( ReviewStep::ID, $failed->step );
		$scan = ExportPlan::read( $this->work( $failed ), FileScanStep::SUMMARY );
		$this->assertSame(
			array(
				'max_file_bytes' => $limit,
				'max_file_limit' => 'index',
				'volume_bytes'   => Packer::VOLUME_BYTES,
			),
			$scan['limits'],
			'the scanner recorded the thresholds it used'
		);
		$this->assertSame( array( 'wp-content/uploads/wpcptest-preflight/images/huge.iso' ), $scan['lists']['too_large'] );
		$this->assertStringContainsString( sprintf( '1 files are larger than %d MB, the largest file the backup format can describe: wp-content/uploads/wpcptest-preflight/images/huge.iso.', intdiv( $limit, 1048576 ) ), $failed->last_error );
		$this->assertArrayNotHasKey( 'max_file_bytes', ExportPlan::read( $this->work( $failed ), ExportPlan::PREFLIGHT )['checks'], 'the pre-flight does not compute a second copy of the limit' );
	}
}
