<?php
/**
 * A restore's preflight: what it will create, under which names, and whether that can work.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Archive\ChunkHasher;
use WPCheckpoint\Archive\EnvironmentFailure;
use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Archive\ManifestError;
use WPCheckpoint\Archive\ZipFormat;
use WPCheckpoint\Archive\ZipReader;
use WPCheckpoint\Database\WpdbConnection;
use WPCheckpoint\Restore\ChunkReader;
use WPCheckpoint\Restore\ChunkWalk;
use WPCheckpoint\Restore\ConstraintNames;
use WPCheckpoint\Restore\ImportTarget;
use WPCheckpoint\Restore\Refused;
use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Restore\Statement;
use WPCheckpoint\Restore\TablePlan;
use WPCheckpoint\Support\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- job errors with table names; the presenter cleans them.
// phpcs:disable WordPress.WP.AlternativeFunctions -- files in the job's work directory.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- reads of the schema.

/**
 * Nothing is created here; the restore stops before anything is touched
 * when a check fails. Three phases:
 *
 * 1. plan: the manifest (the verify step's copy) must describe a database
 *    and a site of the same kind as this one (a network's backup is not
 *    restored onto a single site, or the reverse); the table plan
 *    (TablePlan: temporary and final names, which are fixed here with the
 *    restore's random part and never change); database.index.jsonl
 *    extracted from the last volume and checked against the manifest's
 *    hash.
 * 2. heads: every chunk of every planned table, in lockstep (ChunkWalk),
 *    one per unit: the head of the chunk (at most HEAD_BYTES, or FIRST_BYTES
 *    for a table's first chunk) is extracted and read by the same reader
 *    the import runs (ChunkReader, heads only): the first chunk must hold
 *    the table's CREATE TABLE, whose definition (stored columns, primary
 *    key, foreign keys, engine) is appended to RestoreFiles::DEFINITIONS;
 *    the first INSERT of every chunk must list exactly those columns. A
 *    table the manifest lists without a chunk has no definition and is
 *    refused.
 * 3. references: the foreign keys that would cross the swap. The swap
 *    moves aside every live table of this site's prefix and puts the
 *    restored ones in their place; tables of other prefixes, this plugin's
 *    jobs table and the tables the user left out stay. A restored table's
 *    key to a table that is moved aside and not restored would point at
 *    the old table after the swap; a staying table's key to a moved table
 *    would too (InnoDB follows the rename), holding the new data to the
 *    old rows and keeping the old tables from being deleted. Either
 *    refuses the restore with both ways out. A restored key to a table
 *    that exists nowhere is logged as a warning (inserts into that table
 *    will fail after the restore, as they would have on the backed-up
 *    site). The live keys are read from
 *    information_schema.REFERENTIAL_CONSTRAINTS a page per unit.
 */
final class RestorePreflightStep implements Step {

	const ID = 'restore_preflight';

	/**
	 * Head of a table's first chunk read: its CREATE TABLE and the head of its first INSERT.
	 */
	const FIRST_BYTES = ChunkReader::MAX_STATEMENT_BYTES + 1048576;

	/**
	 * Head of a later chunk read: the preamble and the head of its first INSERT.
	 */
	const HEAD_BYTES = 1048576;

	/**
	 * Live foreign keys read per unit.
	 */
	const PAGE = 1000;

	/**
	 * Returns the backups directory: function(): string.
	 *
	 * @var callable
	 */
	private $backups;

	/**
	 * Head of a later chunk read (tests make it small).
	 *
	 * @var int
	 */
	private $head_bytes;

	/**
	 * Constructor.
	 *
	 * @param callable $backups    function(): string.
	 * @param int      $head_bytes Head of a later chunk read.
	 */
	public function __construct( callable $backups, int $head_bytes = self::HEAD_BYTES ) {
		$this->backups    = $backups;
		$this->head_bytes = max( 1, $head_bytes );
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
	 * Run the phases.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws Refused When the restore cannot work.
	 * @throws TransientFailure When a work file cannot be written.
	 */
	public function run( JobContext $context ): StepResult {
		$cursor = array_merge(
			array(
				'phase' => 'plan',
			),
			$context->cursor()
		);
		if ( 'plan' === $cursor['phase'] ) {
			$cursor = $this->plan( $context );
			$context->checkpoint( $cursor, 5, __( 'Reading the backup\'s tables', 'wp-checkpoint' ) );
		}
		$work = $context->work_path();
		$plan = self::load_plan( $work );
		if ( 'heads' === $cursor['phase'] ) {
			$result = $this->heads( $context, $cursor, $plan );
			if ( null !== $result ) {
				return $result;
			}
		}
		return $this->references( $context, $cursor, $plan );
	}

	/**
	 * The plan phase: returns the cursor of the heads phase.
	 *
	 * @param JobContext $context Context.
	 * @return array<string, mixed>
	 * @throws Refused When the backup does not fit this site.
	 */
	private function plan( JobContext $context ): array {
		global $wpdb;
		$work     = $context->work_path();
		$options  = RestoreJob::options( $context->options() );
		$manifest = self::manifest( $work );
		$site     = $manifest->site();
		$contents = $manifest->to_array()['contents'];
		// A chunk is at most chunk_bytes long, and chunk_bytes at most ArchiveVerifier::MAX_CONTENT_CHUNK: the check
		// before this step refuses a backup with larger hash chunks as unsupported. So each chunk is extracted and
		// hashed in one unit.
		if ( empty( $contents['database'] ) ) {
			throw new Refused( 'This backup holds no database; restoring files alone is not available yet.' );
		}
		$multisite = ! empty( $site['multisite'] );
		if ( is_multisite() !== $multisite ) {
			throw new Refused( $multisite ? 'This backup is of a multisite network and this site is a single site; it can only be restored onto a network.' : 'This backup is of a single site and this site is a multisite network; it can only be restored onto a single site.' );
		}
		$fold   = (int) $wpdb->get_var( 'SELECT @@lower_case_table_names' ) > 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- a server setting.
		$random = bin2hex( random_bytes( 2 ) );
		$plan   = TablePlan::make( $manifest->tables(), (string) ( $site['table_prefix'] ?? '' ), (string) $wpdb->base_prefix, $multisite, $options['exclude_tables'], $context->job()->storage_token, $context->job()->id, $random, $fold );
		foreach ( $plan->tables() as $table ) {
			if ( $table['chunks'] < 1 ) {
				throw new Refused( sprintf( 'The backup holds no definition of the table %s (no chunk); leave it out of the restore.', $table['table'] ) );
			}
		}
		$volumes = array();
		$backups = (string) call_user_func( $this->backups );
		foreach ( $manifest->volumes() as $volume ) {
			$volumes[] = $backups . DIRECTORY_SEPARATOR . (string) $volume['path'];
		}
		self::extract_index( $manifest, (string) end( $volumes ), $work );
		ExportPlan::write(
			$work,
			RestoreFiles::PLAN,
			array(
				'plan'        => $plan->to_array(),
				'random'      => $random,
				'chunk_bytes' => $manifest->chunk_bytes(),
				'volumes'     => $volumes,
				'multisite'   => $multisite,
			)
		);
		foreach ( $plan->skipped() as $name => $reason ) {
			$context->logger()->info(
				'A table of the backup is not restored',
				array(
					'table'  => $name,
					'reason' => $reason,
				)
			);
		}
		$definitions = RestoreFiles::path( $work, RestoreFiles::DEFINITIONS );
		if ( false === @file_put_contents( $definitions, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would put the path into the error log.
			throw new TransientFailure( 'A work file of the restore could not be written.' );
		}
		return array(
			'phase'   => 'heads',
			'walk'    => ChunkWalk::start(),
			'defined' => 0,
			'table'   => -1,
			'chunk'   => 0,
			'line'    => 0,
		);
	}

	/**
	 * The heads phase; null when it is over (the cursor then holds the references phase).
	 *
	 * @param JobContext                                                                                   $context Context.
	 * @param array<string, mixed>                                                                         $cursor  Cursor (updated).
	 * @param array{plan: TablePlan, random: string, chunk_bytes: int, volumes: string[], multisite: bool} $plan    Plan.
	 * @return StepResult|null
	 * @throws Refused When a chunk is not what the restore runs.
	 */
	private function heads( JobContext $context, array &$cursor, array $plan ) {
		$work        = $context->work_path();
		$walk        = new ChunkWalk( RestoreFiles::path( $work, RestoreFiles::INDEX ), $plan['volumes'], $plan['chunk_bytes'] );
		$definitions = RestoreFiles::path( $work, RestoreFiles::DEFINITIONS );
		self::truncate_to( $definitions, (int) $cursor['defined'] );
		$heads = RestoreFiles::path( $work, RestoreFiles::HEADS );
		if ( ! is_dir( $heads ) && ! @mkdir( $heads, 0700 ) && ! is_dir( $heads ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would put the path into the error log.
			throw new TransientFailure( 'A work directory of the restore could not be created.' );
		}
		$tables = $plan['plan']->tables();
		$names  = new ConstraintNames( $plan['random'] );
		while ( true ) {
			$chunk = $walk->at( $cursor['walk'] );
			if ( null === $chunk ) {
				if ( count( $tables ) - 1 !== (int) $cursor['table'] || $tables[ count( $tables ) - 1 ]['chunks'] !== (int) $cursor['chunk'] ) {
					throw new Refused( 'The database index ends before every chunk of the restored tables.' );
				}
				$cursor = array(
					'phase' => 'references',
					'page'  => 0,
				);
				$context->checkpoint( $cursor, 60, __( 'Checking the foreign keys', 'wp-checkpoint' ) );
				return null;
			}
			$line  = $chunk['line'];
			$table = $plan['plan']->find( $line['t'] );
			if ( null !== $table ) {
				$this->expect_order( $cursor, $table, $line['c'], $tables );
				$this->head( $chunk, $table, $heads, $definitions, $names, $plan['plan'] );
			} elseif ( ! array_key_exists( $line['t'], $plan['plan']->skipped() ) ) {
				throw new Refused( sprintf( 'The database index names the table %s, which the manifest does not list.', $line['t'] ) );
			}
			$cursor['walk'] = $chunk['next'];
			clearstatcache( true, $definitions );
			$cursor['defined'] = (int) filesize( $definitions );
			$percent           = 5 + (int) floor( 50 * $cursor['walk']['line'] / max( 1, array_sum( array_column( $tables, 'chunks' ) ) + count( $plan['plan']->skipped() ) ) );
			if ( $context->should_stop() ) {
				return StepResult::progress( $cursor, min( 55, $percent ), __( 'Reading the backup\'s tables', 'wp-checkpoint' ) );
			}
			if ( $context->should_checkpoint( 0 ) ) {
				$context->checkpoint( $cursor, min( 55, $percent ), __( 'Reading the backup\'s tables', 'wp-checkpoint' ) );
			}
		}
	}

	/**
	 * Require the chunks in the plan's order: each table's chunks 1..n, the tables one after another.
	 *
	 * @param array<string, mixed>                                                                         $cursor Cursor (updated: table, chunk).
	 * @param array{table: string, temporary: string, final: string, number: int, chunks: int}             $table  The line's table.
	 * @param int                                                                                          $chunk  The line's chunk number.
	 * @param array<int, array{table: string, temporary: string, final: string, number: int, chunks: int}> $tables Planned tables.
	 * @return void
	 * @throws Refused When the order is another.
	 */
	private function expect_order( array &$cursor, array $table, int $chunk, array $tables ): void {
		$current = (int) $cursor['table'];
		if ( $table['number'] === $current && $chunk === (int) $cursor['chunk'] + 1 && $chunk <= $table['chunks'] ) {
			$cursor['chunk'] = $chunk;
			return;
		}
		$done = $current < 0 || (int) $cursor['chunk'] === $tables[ $current ]['chunks'];
		if ( $done && $table['number'] === $current + 1 && 1 === $chunk ) {
			$cursor['table'] = $table['number'];
			$cursor['chunk'] = 1;
			return;
		}
		throw new Refused( sprintf( 'The database index lists chunk %d of the table %s out of order.', $chunk, $table['table'] ) );
	}

	/**
	 * Read one chunk's head; for a first chunk, append the table's definition.
	 *
	 * @param array<string, mixed>                                                             $chunk       ChunkWalk::at().
	 * @param array{table: string, temporary: string, final: string, number: int, chunks: int} $table       The table.
	 * @param string                                                                           $heads       Directory to extract into.
	 * @param string                                                                           $definitions Definitions file.
	 * @param ConstraintNames                                                                  $names       Constraint names.
	 * @param TablePlan                                                                        $plan        Plan.
	 * @return void
	 * @throws Refused When the head is not what the restore runs.
	 */
	private function head( array $chunk, array $table, string $heads, string $definitions, ConstraintNames $names, TablePlan $plan ): void {
		$line   = $chunk['line'];
		$first  = 1 === $line['c'];
		$known  = $first ? null : self::definition( $definitions, $table['number'] );
		$reader = $chunk['reader'];
		$entry  = $chunk['entry'];
		$stored = ZipFormat::METHOD_STORE === (int) $entry['method'];
		if ( ! $stored && max( (int) $entry['usize'], (int) $entry['csize'] ) > ZipReader::MAX_INFLATE_BYTES ) {
			// Refused here, before any table is created, rather than when the import comes to the chunk.
			throw new Refused( sprintf( 'The database chunk %1$s is stored compressed and is %2$d bytes large; the restore decompresses a compressed chunk in one piece and takes at most %3$d bytes (%4$d MiB).', $line['p'], max( (int) $entry['usize'], (int) $entry['csize'] ), ZipReader::MAX_INFLATE_BYTES, intdiv( ZipReader::MAX_INFLATE_BYTES, 1048576 ) ) );
		}
		try {
			// A deflated entry has no addressable ranges and is read whole (at most ZipReader::MAX_INFLATE_BYTES, checked above).
			$length = $stored ? ( $first ? self::FIRST_BYTES : $this->head_bytes ) : (int) $entry['usize'];
			$piece  = $reader->extract_piece( $entry, $heads, 0, $length, 0 );
		} catch ( EnvironmentFailure $e ) {
			throw $e;
		} catch ( \RuntimeException $e ) {
			throw new Refused( sprintf( 'The database chunk %s cannot be read from the backup: %s', $line['p'], $e->getMessage() ) );
		}
		$target = new ImportTarget(
			$table['table'],
			$table['temporary'],
			$table['final'],
			$table['number'],
			$names,
			array( $plan, 'reference' ),
			null !== $known ? $known['columns'] : null
		);
		$text   = new ChunkReader( $piece['path'], 0, $target, $line['c'], ChunkReader::READ_BYTES, true );
		$create = null;
		try {
			for ( $statement = $text->next(); null !== $statement; $statement = $text->next() ) {
				if ( Statement::CREATE === $statement->kind ) {
					if ( ! $first || null !== $create ) {
						throw new Refused( sprintf( 'The table %s is created again in its chunk %d.', $table['table'], $line['c'] ) );
					}
					$create          = $statement->create;
					$target->columns = $statement->columns;
				} elseif ( Statement::INSERT === $statement->kind ) {
					if ( $first && null === $create ) {
						throw new Refused( sprintf( 'The first chunk of the table %s inserts rows before it creates the table.', $table['table'] ) );
					}
					break;
				}
			}
		} finally {
			$text->close();
			@unlink( $piece['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the next head overwrites it anyway.
		}
		if ( $first ) {
			if ( null === $create ) {
				throw new Refused( sprintf( 'The first chunk of the table %s does not create it.', $table['table'] ) );
			}
			$definition = array(
				'n'       => $table['number'],
				'table'   => $table['table'],
				'columns' => $create->stored_columns(),
				'primary' => $create->primary_key(),
				'foreign' => $create->foreign_keys(),
				'engine'  => $create->engine(),
			);
			$json       = json_encode( $definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- read back; a failure is thrown.
			if ( ! is_string( $json ) ) {
				throw new Refused( sprintf( 'The definition of the table %s cannot be written down (names that are not UTF-8).', $table['table'] ) );
			}
			if ( false === @file_put_contents( $definitions, $json . "\n", FILE_APPEND ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
				throw new TransientFailure( 'A work file of the restore could not be written.' );
			}
		}
	}

	/**
	 * The references phase.
	 *
	 * @param JobContext                                                                                   $context Context.
	 * @param array<string, mixed>                                                                         $cursor  Cursor.
	 * @param array{plan: TablePlan, random: string, chunk_bytes: int, volumes: string[], multisite: bool} $plan    Plan.
	 * @return StepResult
	 * @throws Refused When a foreign key would cross the swap.
	 */
	private function references( JobContext $context, array $cursor, array $plan ): StepResult {
		global $wpdb;
		$work    = $context->work_path();
		$options = RestoreJob::options( $context->options() );
		$table   = $plan['plan'];
		$live    = ( new WpdbConnection() )->tables_with_prefix( (string) $wpdb->base_prefix )['tables'];
		$staying = array( Schema::jobs_table() => true );
		foreach ( $options['exclude_tables'] as $name ) {
			$staying[ $table->final_name( $name ) ] = true;
		}
		$moved = array();
		foreach ( $live as $name ) {
			if ( ! isset( $staying[ $name ] ) && 1 !== preg_match( '/\A(?:wcptmp|wcpold)/', $name ) ) {
				$moved[ $name ] = true;
			}
		}
		foreach ( $table->tables() as $planned ) {
			$moved[ $planned['final'] ] = true;
		}
		if ( 0 === (int) $cursor['page'] ) {
			$handle = @fopen( RestoreFiles::path( $work, RestoreFiles::DEFINITIONS ), 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.
			if ( false === $handle ) {
				throw new WorkLost( 'The table definitions of the restore are gone from the work directory.' );
			}
			try {
				for ( $text = fgets( $handle ); false !== $text; $text = fgets( $handle ) ) {
					$definition = json_decode( $text, true );
					if ( ! is_array( $definition ) || ! isset( $definition['foreign'] ) || ! is_array( $definition['foreign'] ) ) {
						throw new WorkLost( 'The table definitions of the restore in the work directory are damaged.' );
					}
					foreach ( $definition['foreign'] as $key ) {
						$target = (string) $key['references'];
						if ( null !== $table->find( $target ) ) {
							continue;
						}
						$here = $table->final_name( $target );
						if ( isset( $moved[ $here ] ) ) {
							throw new Refused( sprintf( 'The table %1$s of the backup has a foreign key (%2$s) to %3$s, which the backup does not hold (or which is left out) and which the restore moves aside with the rest of this site\'s tables. After the restore the key would point at the old table. Restore %3$s\'s table as well, or leave %1$s out of the restore.', (string) $definition['table'], (string) $key['name'], $here ) );
						}
						if ( ! in_array( $here, $live, true ) && ! isset( $staying[ $here ] ) ) {
							$context->logger()->warning(
								'A restored table has a foreign key to a table that is neither in the backup nor on this site; inserts into it will fail until that table exists',
								array(
									'table'      => (string) $definition['table'],
									'key'        => (string) $key['name'],
									'references' => $here,
								)
							);
						}
					}
				}
			} finally {
				fclose( $handle );
			}
		}
		while ( true ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() ORDER BY TABLE_NAME, CONSTRAINT_NAME LIMIT %d OFFSET %d', self::PAGE, (int) $cursor['page'] * self::PAGE ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- the live schema, read once per restore.
			if ( '' !== (string) $wpdb->last_error ) {
				throw new TransientFailure( 'The foreign keys of this site could not be read from the database.' );
			}
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				list( $owner, $name, $referenced ) = array_map( 'strval', $row );
				if ( isset( $moved[ $owner ] ) || 1 === preg_match( '/\A(?:wcptmp|wcpold)/', $owner ) || ! isset( $moved[ $referenced ] ) ) {
					continue;
				}
				throw new Refused( sprintf( 'The table %1$s stays as it is, but its foreign key %2$s references %3$s, which the restore replaces. InnoDB would keep the key on the old %3$s: the new data would be held to the old rows, and the old tables could not be deleted after the restore. Either restore %1$s too (it must be in the backup and not left out), or remove that foreign key first; then start the restore again.', $owner, $name, $referenced ) );
			}
			if ( ! is_array( $rows ) || count( $rows ) < self::PAGE ) {
				break;
			}
			$cursor['page'] = (int) $cursor['page'] + 1;
			if ( $context->should_stop() ) {
				return StepResult::progress( $cursor, 60, __( 'Checking the foreign keys', 'wp-checkpoint' ) );
			}
		}
		return StepResult::done( __( 'The backup\'s tables can be restored here', 'wp-checkpoint' ) );
	}

	/**
	 * The manifest copy.
	 *
	 * @param string $work Work directory.
	 * @return Manifest
	 * @throws WorkLost When the copy is gone or not a manifest.
	 */
	public static function manifest( string $work ): Manifest {
		$path = RestoreFiles::path( $work, RestoreFiles::MANIFEST );
		clearstatcache( true, $path );
		$json = is_file( $path ) && filesize( $path ) <= Manifest::MAX_JSON_BYTES ? @file_get_contents( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- bounded; reported below.
		if ( ! is_string( $json ) ) {
			throw new WorkLost( 'The copy of the manifest is gone from the work directory.' );
		}
		try {
			return Manifest::from_json( $json );
		} catch ( ManifestError $e ) {
			throw new WorkLost( 'The copy of the manifest in the work directory is not a manifest.' );
		}
	}

	/**
	 * The plan file.
	 *
	 * @param string $work Work directory.
	 * @return array{plan: TablePlan, random: string, chunk_bytes: int, volumes: string[], multisite: bool}
	 * @throws WorkLost When it is gone or damaged.
	 */
	public static function load_plan( string $work ): array {
		$data = ExportPlan::read( $work, RestoreFiles::PLAN );
		if ( ! isset( $data['plan'], $data['random'], $data['chunk_bytes'], $data['volumes'] ) || ! is_array( $data['plan'] ) || ! is_array( $data['volumes'] ) ) {
			throw new WorkLost( 'The plan of the restore in the work directory is damaged.' );
		}
		return array(
			'plan'        => TablePlan::from_array( $data['plan'] ),
			'random'      => (string) $data['random'],
			'chunk_bytes' => (int) $data['chunk_bytes'],
			'volumes'     => array_map( 'strval', $data['volumes'] ),
			'multisite'   => ! empty( $data['multisite'] ),
		);
	}

	/**
	 * A table's definition from the definitions file.
	 *
	 * @param string $definitions File.
	 * @param int    $number      The table's number.
	 * @return array{n: int, table: string, columns: string[], primary: string[], foreign: array<int, array<string, mixed>>, engine: string}
	 * @throws WorkLost When it is not there.
	 */
	public static function definition( string $definitions, int $number ): array {
		$handle = @fopen( $definitions, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.
		if ( false === $handle ) {
			throw new WorkLost( 'The table definitions of the restore are gone from the work directory.' );
		}
		try {
			for ( $text = fgets( $handle ); false !== $text; $text = fgets( $handle ) ) {
				$definition = json_decode( $text, true );
				if ( is_array( $definition ) && isset( $definition['n'], $definition['columns'] ) && $number === (int) $definition['n'] ) {
					return $definition;
				}
			}
		} finally {
			fclose( $handle );
		}
		throw new WorkLost( 'A table definition of the restore is missing from the work directory.' );
	}

	/**
	 * Extract database.index.jsonl from the last volume and check it against the manifest.
	 *
	 * @param Manifest $manifest Manifest.
	 * @param string   $volume   Last volume.
	 * @param string   $work     Work directory.
	 * @return void
	 * @throws Refused When it is missing or not the one the manifest describes.
	 */
	private static function extract_index( Manifest $manifest, string $volume, string $work ): void {
		$want = $manifest->database_index();
		try {
			$reader = ZipReader::open( $volume );
			$entry  = $reader->find( (string) $want['path'] );
			if ( null === $entry ) {
				throw new Refused( 'The backup\'s last volume holds no database index.' );
			}
			$dir = RestoreFiles::path( $work, RestoreFiles::HEADS );
			if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700 ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would put the path into the error log.
				throw new TransientFailure( 'A work directory of the restore could not be created.' );
			}
			$path = $reader->extract( $entry, $dir );
		} catch ( EnvironmentFailure $e ) {
			throw $e;
		} catch ( TransientFailure $e ) {
			throw $e;
		} catch ( Refused $e ) {
			throw $e;
		} catch ( \RuntimeException $e ) {
			throw new Refused( 'The backup\'s database index cannot be read: ' . $e->getMessage() );
		}
		$hash = ChunkHasher::content_hash( $path, $manifest->chunk_bytes() );
		if ( $hash['bytes'] !== (int) $want['bytes'] || ! hash_equals( (string) $want['sha256'], $hash['sha256'] ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort.
			throw new Refused( 'The backup\'s database index does not match its manifest; the backup is damaged.' );
		}
		if ( ! @rename( $path, RestoreFiles::path( $work, RestoreFiles::INDEX ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			throw new TransientFailure( 'A work file of the restore could not be written.' );
		}
	}

	/**
	 * Cut an appended file back to its committed length; fail when it is shorter.
	 *
	 * @param string $path   File.
	 * @param int    $length Committed length.
	 * @return void
	 * @throws WorkLost When the file is shorter than committed.
	 * @throws TransientFailure When it cannot be cut.
	 */
	private static function truncate_to( string $path, int $length ): void {
		clearstatcache( true, $path );
		$size = is_file( $path ) ? (int) filesize( $path ) : -1;
		if ( $size < $length ) {
			throw new WorkLost( 'A work file of the restore is shorter than recorded; the work directory was changed.' );
		}
		if ( $size > $length ) {
			$handle = @fopen( $path, 'r+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.
			if ( false === $handle || ! ftruncate( $handle, $length ) ) {
				throw new TransientFailure( 'A work file of the restore could not be cut back to its committed length.' );
			}
			fclose( $handle );
		}
	}

	/**
	 * Nothing to do: everything it wrote is in the work directory, which the engine removes.
	 *
	 * @param JobContext $context Context.
	 * @return void
	 */
	public function cleanup( JobContext $context ): void {
		unset( $context );
	}
}
