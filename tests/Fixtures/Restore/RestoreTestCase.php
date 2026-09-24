<?php

namespace WPCheckpoint\Tests\Fixtures\Restore;

use WPCheckpoint\Database\TableExporter;
use WPCheckpoint\Database\WpdbConnection;
use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Jobs\RestoreJob;
use WPCheckpoint\Jobs\RestorePreflightStep;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Tests\Fixtures\Archive\ArchiveBuilder;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * Backups made of this site's own tables with the real exporter (1 MiB
 * chunks, so a table of a few MB spans several), packed by ArchiveBuilder
 * into the plugin's backups directory; and restore jobs run on them.
 */
abstract class RestoreTestCase extends JobTestCase {

	/** @var string[] Tables a test created. */
	protected $created = array();

	/** @var ArchiveBuilder[] */
	private $builders = array();

	/** @var float */
	protected $now = 1_800_000_000.0;

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );
		foreach ( $this->created as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		foreach ( (array) $wpdb->get_col( "SHOW TABLES LIKE 'wcptmp%'" ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
		foreach ( $this->builders as $builder ) {
			$builder->cleanup();
		}
		parent::tear_down();
	}

	/**
	 * Create a table (dropped after the test).
	 */
	protected function create( string $table, string $definition ): void {
		global $wpdb;
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		$wpdb->query( "CREATE TABLE `{$table}` {$definition}" );
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
		$this->assertSame( '', $wpdb->last_error, $table );
		$this->created[] = $table;
	}

	/**
	 * The chunks of a table as the exporter writes them, and its row count.
	 *
	 * @return array{0: string[], 1: int}
	 */
	protected function export_table( string $table ): array {
		$dir = sys_get_temp_dir() . '/wpc-restore-export-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir, 0700 );
		$exporter = new TableExporter( new WpdbConnection(), $dir, ArchiveBuilder::CHUNK_BYTES, 262144 );
		$state    = TableExporter::initial_state( $table );
		for ( $i = 0; $i < 10000 && empty( $state['done'] ); $i++ ) {
			$state = $exporter->step( $state );
		}
		$chunks = array();
		for ( $c = 1; $c <= (int) $state['chunks']; $c++ ) {
			$chunks[] = (string) file_get_contents( $exporter->chunk_path( $table, $c ) );
		}
		Deleter::empty_directory( $dir );
		rmdir( $dir );
		return array( $chunks, (int) $state['rows'] );
	}

	/**
	 * A backup of these tables of this site (chunk contents may be edited), in the backups directory.
	 *
	 * @param string[]      $tables  Tables in order.
	 * @param callable|null $edit    function( string $table, string[] $chunks ): string[].
	 * @param callable|null $site    function( array $site ): array, the manifest's site.
	 * @return string The backup's base name.
	 */
	protected function backup( array $tables, $edit = null, $site = null ): string {
		global $wpdb;
		$rows    = array();
		$builder = new ArchiveBuilder(
			array(
				'manifest' => static function ( array $manifest ) use ( &$rows, $site, $wpdb ): array {
					foreach ( $manifest['database']['tables'] as $i => $table ) {
						$manifest['database']['tables'][ $i ]['rows'] = $rows[ $table['name'] ];
					}
					$manifest['site']['table_prefix'] = $wpdb->base_prefix;
					$manifest['site']['multisite']    = is_multisite();
					if ( null !== $site ) {
						$manifest['site'] = $site( $manifest['site'] );
					}
					return $manifest;
				},
			)
		);
		$this->builders[] = $builder;
		foreach ( $tables as $table ) {
			list( $chunks, $count ) = $this->export_table( $table );
			if ( null !== $edit ) {
				$chunks = $edit( $table, $chunks );
			}
			$rows[ $table ] = $count;
			$builder->table( $table, $chunks );
		}
		$builder->build();
		$backups = Plugin::instance()->directories()->backups();
		foreach ( array_merge( $builder->volumes, array( $builder->manifest_path ) ) as $file ) {
			copy( $file, $backups . '/' . basename( $file ) );
		}
		return ArchiveBuilder::BASE;
	}

	/**
	 * The site's own tables every restore of it needs.
	 *
	 * @return string[]
	 */
	protected static function site_tables(): array {
		global $wpdb;
		return is_multisite() ? array( $wpdb->base_prefix . 'options', $wpdb->base_prefix . 'sitemeta' ) : array( $wpdb->base_prefix . 'options' );
	}

	/**
	 * Start a restore job.
	 */
	protected function start_restore( string $base, array $options = array() ): Job {
		return Plugin::instance()->jobs()->create( RestoreJob::ID, self::$admin_id, array(), array_merge( array( 'base' => $base ), $options ) );
	}

	/**
	 * Tick until the job is no longer running (large budget), or with one short tick at a time.
	 */
	protected function run_restore( Job $job, bool $small = false, int $max = 500 ): Job {
		$runner = $small ? $this->small_runner() : Plugin::instance()->runner();
		for ( $i = 0; $i < $max; $i++ ) {
			$now = Plugin::instance()->jobs()->find( $job->id );
			if ( ! in_array( $now->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				// The test's own transaction holds a snapshot from before the import's connection created its tables.
				$GLOBALS['wpdb']->query( 'COMMIT' );
				return $now;
			}
			$runner->tick( $job->id, microtime( true ) );
		}
		$this->fail( 'the restore did not end' );
	}

	/**
	 * A runner whose clock moves on at every look, so each tick does a statement or two.
	 */
	protected function small_runner(): Runner {
		return new Runner(
			Plugin::instance()->jobs(),
			Plugin::instance()->job_types(),
			new Redactor( Redactor::installation_secrets() ),
			array(
				'clock'        => function (): float {
					$this->now += 0.25;
					return $this->now;
				},
				'memory'       => static function (): int {
					return 10 * 1048576;
				},
				'budget'       => new Budget( 2, 32 * 1048576, false ),
				'memory_limit' => -1,
			)
		);
	}

	/**
	 * The job's work directory.
	 */
	protected function work( Job $job ): string {
		return Residue::work_dir( $job->storage_path, $job->id );
	}

	/**
	 * The plan of a restore: backup name => temporary name.
	 *
	 * @return array<string, string>
	 */
	protected function temporary_names( Job $job ): array {
		return array_column( RestorePreflightStep::load_plan( $this->work( Plugin::instance()->jobs()->find( $job->id ) ) )['plan']->tables(), 'temporary', 'table' );
	}

	/**
	 * Every row of a table in primary key order (or all columns' order).
	 *
	 * @return array<int, array<string, string|null>>
	 */
	protected function rows_of( string $table ): array {
		global $wpdb;
		$columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" );
		return (array) $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY " . implode( ', ', array_map( static function ( string $c ): string {
			return "`{$c}`";
		}, $columns ) ), ARRAY_A );
	}

	/**
	 * A fingerprint of every live table of this site (names and content).
	 *
	 * @return array<string, string>
	 */
	protected function live_site(): array {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%' ) ) as $table ) {
			if ( $table === $wpdb->base_prefix . 'wpcheckpoint_jobs' ) {
				continue; // The restore's own row changes.
			}
			$rows = $this->rows_of( $table );
			if ( in_array( $table, array( $wpdb->base_prefix . 'options', $wpdb->base_prefix . 'sitemeta' ), true ) ) {
				// The plugin's own bookkeeping (its options, transients, the cron option) moves while any job runs.
				$rows = array_values(
					array_filter(
						$rows,
						static function ( array $row ): bool {
							$name = (string) ( $row['option_name'] ?? $row['meta_key'] ?? '' );
							return false === strpos( $name, 'wpcheckpoint' ) && 'cron' !== $name && 0 !== strpos( $name, '_transient' ) && 0 !== strpos( $name, '_site_transient' );
						}
					)
				);
			}
			$out[ $table ] = md5( (string) wp_json_encode( $rows ) );
		}
		ksort( $out );
		return $out;
	}
}
