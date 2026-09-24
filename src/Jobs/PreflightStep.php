<?php
/**
 * The first step of an export: what will be backed up, and whether it can be.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Archive\Packer;
use WPCheckpoint\Database\Connection;
use WPCheckpoint\Database\RowSizeCheck;
use WPCheckpoint\Database\SqlWriter;
use WPCheckpoint\Database\TableExporter;
use WPCheckpoint\Database\TableSelection;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages carry table names and numbers; the runner stores them through the redactor and the presenter cleans them before display.

/**
 * Three phases, each unit bounded:
 *
 *  1. environment: the storage directories are writable, which optional
 *     extensions are there (zlib for compression, intl for name
 *     normalisation), the platform's integer size. A directory that
 *     cannot be written fails the job here, before any work.
 *  2. tables: the base tables with the site's prefix (views are noted,
 *     names that cannot be stored are skipped with a note, tables the
 *     options leave out are dropped), the server's size statistics for
 *     them in one query, the free disk space against what a volume plus
 *     the database needs, the backup's base name. Written to plan.json.
 *  3. rows: one table per unit, RowSizeCheck (exact for small tables,
 *     sampled for large ones); tables that have or may have rows over
 *     the single-row limit are recorded as findings.
 *
 * The findings and notes go to preflight.json for ReviewStep. Nothing
 * here asks the user: the review does, once, with everything in hand.
 * WordPress specifics (the prefix, the table listing, disk space, the
 * site's slug) come in as callables so the step is testable without it.
 */
final class PreflightStep implements Step {

	/**
	 * Tables of a left-out group named in the findings (the rest are counted).
	 */
	const MAX_FOREIGN_LISTED = 20;

	/**
	 * The shape of every base name base_name() makes: slug of at most 40
	 * characters, UTC date and time, four hex digits.
	 */
	const BASE_PATTERN = '/\A[a-z0-9][a-z0-9-]{0,39}-[0-9]{8}-[0-9]{6}-[0-9a-f]{4}\z/';


	const ID = 'preflight';

	const STATS_QUERY = 'SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, AVG_ROW_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (';

	/**
	 * Database.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Environment callables and values, see __construct().
	 *
	 * @var array<string, mixed>
	 */
	private $env;

	/**
	 * Chunk size handed to the exporter (tests use a smaller one).
	 *
	 * @var int
	 */
	private $chunk_bytes;

	/**
	 * Constructor.
	 *
	 * @param Connection           $connection  Database.
	 * @param array<string, mixed> $env         'prefix' (string), 'tables' (callable(prefix): {tables, views}),
	 *                                          'writable' (callable(): string[] of unwritable directory names),
	 *                                          'disk_free' (callable(): int|false, bytes free in the storage directory),
	 *                                          'slug' (callable(): string), 'can_deflate' (bool), 'normalization' (bool),
	 *                                          'int_size' (int), 'now' (callable(): int), 'random' (callable(): string, four hex digits; tests),
	 *                                          'multisite' (bool), 'core_tables' (callable(): string[], this installation's core tables),
	 *                                          'own_tables' (string[], the plugin's own tables: never in a backup).
	 * @param int                  $chunk_bytes Chunk size.
	 */
	public function __construct( Connection $connection, array $env, int $chunk_bytes = TableExporter::CHUNK_BYTES ) {
		$this->connection  = $connection;
		$this->env         = $env;
		$this->chunk_bytes = $chunk_bytes;
	}

	/**
	 * Step id.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Run the phases until done or the budget is spent.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws TransientFailure When the work directory cannot be written.
	 * @throws \RuntimeException When the export cannot go ahead (the message says why).
	 */
	public function run( JobContext $context ): StepResult {
		$options = ExportOptions::normalize( $context->options() );
		$work    = $context->work_path();
		$cursor  = $context->cursor();
		$phase   = isset( $cursor['phase'] ) ? (string) $cursor['phase'] : 'environment';
		$state   = array(
			'table'    => isset( $cursor['table'] ) ? (int) $cursor['table'] : 0,
			'oversize' => isset( $cursor['oversize'] ) && is_array( $cursor['oversize'] ) ? $cursor['oversize'] : array(),
			'checks'   => isset( $cursor['checks'] ) && is_array( $cursor['checks'] ) ? $cursor['checks'] : array(),
			'warnings' => isset( $cursor['warnings'] ) && is_array( $cursor['warnings'] ) ? $cursor['warnings'] : array(),
		);

		if ( 'environment' === $phase ) {
			$this->environment( $state );
			$phase = 'tables';
			$context->checkpoint( $this->cursor( $phase, $state ), 10, __( 'Checked the environment', 'wp-checkpoint' ) );
		}
		if ( 'tables' === $phase ) {
			$this->tables( $work, $options, $state );
			$phase = 'rows';
			$context->checkpoint( $this->cursor( $phase, $state ), 20, __( 'Listed the tables', 'wp-checkpoint' ) );
		}
		if ( 'rows' === $phase ) {
			$plan   = ExportPlan::read( $work, ExportPlan::PLAN );
			$tables = $options['contents']['database'] ? $plan['tables'] : array();
			$stats  = isset( $plan['stats'] ) && is_array( $plan['stats'] ) ? $plan['stats'] : array();
			$total  = count( $tables );
			$since  = 0;
			while ( $state['table'] < $total ) {
				$table = (string) $tables[ $state['table'] ];
				$check = ( new RowSizeCheck( $this->connection, new TableExporter( $this->connection, $work, $this->chunk_bytes ) ) )->check( $table, self::stats_of( $stats, $table ) );
				if ( $check['likely'] ) {
					$state['oversize'][] = array(
						'table' => $check['table'],
						'exact' => $check['exact'],
						'count' => $check['count'],
						'limit' => $check['limit'],
					);
				}
				++$state['table'];
				++$since;
				$percent = 20 + (int) floor( 75 * $state['table'] / max( 1, $total ) );
				$message = sprintf(
					/* translators: 1: tables checked, 2: tables in total */
					__( 'Checked %1$s of %2$s tables for oversized rows', 'wp-checkpoint' ),
					number_format_i18n( (int) $state['table'] ),
					number_format_i18n( (int) $total )
				);
				if ( $state['table'] < $total && $context->should_stop() ) {
					return StepResult::progress( $this->cursor( $phase, $state ), $percent, $message );
				}
				if ( $context->should_checkpoint( 0 ) ) {
					$context->checkpoint( $this->cursor( $phase, $state ), $percent, $message );
					$since = 0;
				}
			}
			unset( $since );
			$this->finish( $work, $state );
		}
		return StepResult::done( __( 'Pre-flight finished', 'wp-checkpoint' ) );
	}

	/**
	 * Nothing to do: plan.json and preflight.json live in the work
	 * directory, which the engine removes; no tables or external resources.
	 *
	 * @param JobContext $context Context.
	 * @return void
	 */
	public function cleanup( JobContext $context ): void {
		unset( $context );
	}

	/**
	 * Cursor from the phase and the state.
	 *
	 * @param string               $phase Phase.
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private function cursor( string $phase, array $state ): array {
		return array_merge( array( 'phase' => $phase ), $state );
	}

	/**
	 * Phase 1: directories and extensions.
	 *
	 * @param array<string, mixed> $state State (updated).
	 * @return void
	 * @throws \RuntimeException When a storage directory cannot be written.
	 */
	private function environment( array &$state ): void {
		$unwritable = (array) call_user_func( $this->env['writable'] );
		if ( array() !== $unwritable ) {
			throw new \RuntimeException( sprintf( 'The storage directory cannot be written (%s); the backup has nowhere to go.', implode( ', ', array_map( 'strval', $unwritable ) ) ) );
		}
		$state['checks']['writable']    = true;
		$state['checks']['can_deflate'] = ! empty( $this->env['can_deflate'] );
		if ( ! $state['checks']['can_deflate'] ) {
			$state['warnings'][] = 'The zlib extension is not available; files are stored without compression.';
		}
		$state['checks']['normalization'] = ! empty( $this->env['normalization'] );
		if ( ! $state['checks']['normalization'] ) {
			$state['warnings'][] = FileScanStep::normalization_warning();
		}
		$int_size                           = isset( $this->env['int_size'] ) ? (int) $this->env['int_size'] : PHP_INT_SIZE;
		$state['checks']['int_size']        = $int_size;
		$state['checks']['max_entry_bytes'] = Packer::max_entry_bytes( $int_size );
	}

	/**
	 * Phase 2: the table list, statistics, disk space, base name; plan.json.
	 *
	 * @param string               $work    Work directory.
	 * @param array<string, mixed> $options Normalised options.
	 * @param array<string, mixed> $state   State (updated).
	 * @return void
	 * @throws \RuntimeException When there is not enough free disk space.
	 */
	private function tables( string $work, array $options, array &$state ): void {
		$tables  = array();
		$notes   = array();
		$stats   = array();
		$foreign = array();
		if ( $options['contents']['database'] ) {
			$listing = call_user_func( $this->env['tables'], (string) $this->env['prefix'] );
			$core    = isset( $this->env['core_tables'] ) ? (array) call_user_func( $this->env['core_tables'] ) : array();
			$missing = self::missing_essentials( array_map( 'strval', (array) $listing['tables'] ), array_map( 'strval', $core ), (string) $this->env['prefix'] );
			if ( array() !== $missing ) {
				// A listing without this site's own posts or options is not this site's database (a failed or
				// filtered query, a wrong prefix): a backup made from it would hold no database and look complete.
				throw new \RuntimeException( sprintf( 'The database did not list this site\'s own tables (%s missing). The backup is stopped rather than made without the database; check the table prefix and the database connection, and try again.', implode( ', ', $missing ) ) );
			}
			// The plugin's own job table describes this installation's jobs and storage, not the site: a restore
			// keeps the target's own.
			$own    = isset( $this->env['own_tables'] ) ? array_map( 'strval', (array) $this->env['own_tables'] ) : array();
			$all    = array_values( array_diff( array_map( 'strval', (array) $listing['tables'] ), $own ) );
			$groups = TableSelection::foreign( $all, (string) $this->env['prefix'], ! empty( $this->env['multisite'] ), $core );
			$left   = array_fill_keys( $own, true );
			foreach ( $groups as $group_prefix => $group ) {
				// Another installation's core tables stay out unless the user named them; the rest under its prefix stays in.
				$out  = array_values( array_diff( $group['excluded'], $options['include_tables'] ) );
				$kept = array_values( array_diff( $group['kept'], $options['exclude_tables'] ) );
				if ( array() !== $out || array() !== $kept ) {
					$foreign[] = array(
						'prefix'      => (string) $group_prefix,
						'count'       => count( $out ),
						'listed'      => array_slice( $out, 0, self::MAX_FOREIGN_LISTED ),
						'kept'        => count( $kept ),
						'kept_listed' => array_slice( $kept, 0, self::MAX_FOREIGN_LISTED ),
					);
				}
				foreach ( $out as $table ) {
					$left[ $table ] = true;
				}
			}
			$seen = array();
			foreach ( (array) $listing['tables'] as $table ) {
				$table = (string) $table;
				if ( in_array( $table, $options['exclude_tables'], true ) || isset( $left[ $table ] ) ) {
					continue;
				}
				if ( ! DatabaseExportStep::storable_name( $table ) ) {
					$notes[] = sprintf( 'Table %s was skipped: its name cannot be stored in an archive path.', $table );
					continue;
				}
				$folded = strtolower( $table );
				if ( isset( $seen[ $folded ] ) ) {
					$notes[] = sprintf( 'Table %s was skipped: its name differs only by letter case from table %s, and both cannot be stored in one archive.', $table, $seen[ $folded ] );
					continue;
				}
				$seen[ $folded ] = $table;
				$tables[]        = $table;
			}
			foreach ( (array) $listing['views'] as $view ) {
				$notes[] = sprintf( 'View %s is not part of the backup (views are not exported).', (string) $view );
			}
			$stats = $this->statistics( $tables );
		}
		// The table data only: InnoDB's index pages never reach the exported SQL. Floats throughout: free space and
		// sums of large sites do not fit a 32-bit integer (a cast there wraps to a negative number).
		$db_bytes = 0.0;
		foreach ( $stats as $row ) {
			$db_bytes += (float) $row['data_bytes'];
		}
		$free = call_user_func( $this->env['disk_free'] );
		if ( is_int( $free ) || is_float( $free ) ) {
			$needed = (float) Packer::required_free_bytes() + $db_bytes;
			if ( (float) $free < $needed ) {
				throw new \RuntimeException( sprintf( 'Not enough free disk space in the storage directory: %d MB free, at least %d MB needed for one volume and the database.', (int) floor( (float) $free / 1048576 ), (int) ceil( $needed / 1048576 ) ) );
			}
			$state['checks']['disk_free'] = (float) $free;
		} else {
			$state['warnings'][] = 'The free disk space could not be measured; the export stops if the disk fills up.';
		}
		$state['checks']['db_bytes'] = $db_bytes;
		ExportPlan::write(
			$work,
			ExportPlan::PLAN,
			array(
				'base'       => $this->base_name(),
				'tables'     => $tables,
				'notes'      => $notes,
				'groups'     => $options['contents']['files'],
				'exclusions' => $options['exclusions'],
				'stats'      => $stats,
				'foreign'    => $foreign,
			)
		);
	}

	/**
	 * Server statistics for the tables, one query for all of them.
	 *
	 * @param string[] $tables Tables.
	 * @return array<string, array{rows: int, data_bytes: int, index_bytes: int, avg_row_bytes: int}>
	 * @throws \RuntimeException When the query fails.
	 */
	private function statistics( array $tables ): array {
		$stats = array();
		foreach ( array_chunk( $tables, 500 ) as $chunk ) {
			$rows = $this->connection->rows( self::STATS_QUERY . implode( ', ', array_fill( 0, count( $chunk ), '?' ) ) . ')', $chunk );
			if ( null === $rows ) {
				throw new \RuntimeException( sprintf( 'Database query failed (%d): %s', $this->connection->last_errno(), $this->connection->last_error() ) );
			}
			foreach ( $rows as $row ) {
				$stats[ (string) $row[0] ] = array(
					'rows'          => (int) $row[1],
					'data_bytes'    => (int) $row[2],
					'index_bytes'   => (int) $row[3],
					'avg_row_bytes' => (int) $row[4],
				);
			}
		}
		return $stats;
	}

	/**
	 * Statistics of one table, zeros when the server has none (a table
	 * created after the listing, a storage engine without statistics):
	 * zeros make the check exact, which is right for a table the server
	 * knows nothing about.
	 *
	 * @param array<string, array<string, int>> $stats All statistics.
	 * @param string                            $table Table.
	 * @return array{rows: int, data_bytes: int, avg_row_bytes: int}
	 */
	private static function stats_of( array $stats, string $table ): array {
		$row = isset( $stats[ $table ] ) && is_array( $stats[ $table ] ) ? $stats[ $table ] : array();
		return array(
			'rows'          => isset( $row['rows'] ) ? (int) $row['rows'] : 0,
			'data_bytes'    => isset( $row['data_bytes'] ) ? (int) $row['data_bytes'] : 0,
			'avg_row_bytes' => isset( $row['avg_row_bytes'] ) ? (int) $row['avg_row_bytes'] : 0,
		);
	}

	/**
	 * Phase 3 done: preflight.json.
	 *
	 * @param string               $work  Work directory.
	 * @param array<string, mixed> $state State.
	 * @return void
	 */
	private function finish( string $work, array $state ): void {
		$plan = ExportPlan::read( $work, ExportPlan::PLAN );
		ExportPlan::write(
			$work,
			ExportPlan::PREFLIGHT,
			array(
				'checks'   => $state['checks'],
				'findings' => array(
					'oversize' => array_values( $state['oversize'] ),
					'foreign'  => isset( $plan['foreign'] ) && is_array( $plan['foreign'] ) ? array_values( $plan['foreign'] ) : array(),
				),
				'warnings' => array_values( $state['warnings'] ),
			)
		);
	}

	/**
	 * This installation's essential tables (its main site's posts and options,
	 * from its core list; the base prefix when the core list is unknown) that
	 * the listing does not contain. Not every core table: a cleanup plugin may
	 * have dropped one such as links.
	 *
	 * @param string[] $listing Tables listed by the database.
	 * @param string[] $core    Core tables.
	 * @param string   $prefix  Base prefix.
	 * @return string[]
	 */
	private static function missing_essentials( array $listing, array $core, string $prefix ): array {
		$essential = array();
		foreach ( array( 'posts', 'options' ) as $name ) {
			$essential[] = in_array( $prefix . $name, $core, true ) || array() === $core ? $prefix . $name : '';
		}
		$essential = array_filter( $essential );
		return array_values( array_diff( $essential, $listing ) );
	}

	/**
	 * The backup's base name: slug, UTC timestamp, a random suffix so two
	 * backups in the same second cannot collide. Matches Packer's rule for
	 * a base name.
	 *
	 * @return string
	 */
	private function base_name(): string {
		$slug = (string) call_user_func( $this->env['slug'] );
		$slug = strtolower( preg_replace( '/[^A-Za-z0-9-]+/', '-', $slug ) ?? '' );
		$slug = trim( substr( $slug, 0, 40 ), '-' );
		if ( '' === $slug || 1 !== preg_match( '/\A[a-z0-9]/', $slug ) ) {
			$slug = 'site';
		}
		$now = isset( $this->env['now'] ) ? (int) call_user_func( $this->env['now'] ) : time();
		$hex = isset( $this->env['random'] ) ? (string) call_user_func( $this->env['random'] ) : bin2hex( random_bytes( 2 ) );
		return $slug . '-' . gmdate( 'Ymd-His', $now ) . '-' . $hex;
	}
}
