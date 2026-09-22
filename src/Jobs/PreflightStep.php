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
	 *                                          'int_size' (int), 'now' (callable(): int).
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
					__( 'Checked %1$d of %2$d tables for oversized rows', 'wp-checkpoint' ),
					$state['table'],
					$total
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
		$tables = array();
		$notes  = array();
		$stats  = array();
		if ( $options['contents']['database'] ) {
			$listing = call_user_func( $this->env['tables'], (string) $this->env['prefix'] );
			$seen    = array();
			foreach ( (array) $listing['tables'] as $table ) {
				$table = (string) $table;
				if ( in_array( $table, $options['exclude_tables'], true ) ) {
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
		$db_bytes = 0;
		foreach ( $stats as $row ) {
			$db_bytes += (int) $row['data_bytes'] + (int) $row['index_bytes'];
		}
		$free = call_user_func( $this->env['disk_free'] );
		if ( is_numeric( $free ) ) {
			$needed = Packer::required_free_bytes() + $db_bytes;
			if ( (int) $free < $needed ) {
				throw new \RuntimeException( sprintf( 'Not enough free disk space in the storage directory: %d MB free, at least %d MB needed for one volume and the database.', (int) ( (int) $free / 1048576 ), (int) ( $needed / 1048576 ) ) );
			}
			$state['checks']['disk_free'] = (int) $free;
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
		ExportPlan::write(
			$work,
			ExportPlan::PREFLIGHT,
			array(
				'checks'   => $state['checks'],
				'findings' => array( 'oversize' => array_values( $state['oversize'] ) ),
				'warnings' => array_values( $state['warnings'] ),
			)
		);
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
		return $slug . '-' . gmdate( 'Ymd-His', $now ) . '-' . bin2hex( random_bytes( 2 ) );
	}
}
