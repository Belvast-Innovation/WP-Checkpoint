<?php
/**
 * A restore's database import: every chunk, statement by statement, into temporary tables.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Archive\ChunkHasher;
use WPCheckpoint\Archive\EnvironmentFailure;
use WPCheckpoint\Database\SqlWriter;
use WPCheckpoint\Restore\ChunkReader;
use WPCheckpoint\Restore\ChunkWalk;
use WPCheckpoint\Restore\ConstraintNames;
use WPCheckpoint\Restore\ImportSession;
use WPCheckpoint\Restore\ImportTarget;
use WPCheckpoint\Restore\Ledger;
use WPCheckpoint\Restore\Refused;
use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Restore\StateCarry;
use WPCheckpoint\Restore\Statement;
use WPCheckpoint\Restore\TablePlan;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Standalone\Failure;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- job errors with table names; the presenter cleans them.
// phpcs:disable WordPress.WP.AlternativeFunctions -- files in the job's work directory.

/**
 * Nothing the site uses is touched: every statement runs on a temporary
 * table (ChunkReader rewrites and refuses), through a connection of its own
 * (ImportSession). The chunks are taken in lockstep (ChunkWalk), one at a
 * time: extracted into the work directory as a unit of its own, checked
 * against the index's hash, then read and run statement by statement, and
 * deleted once its last statement is recorded.
 *
 * Where to go on is the ledger's to say (Ledger), not the cursor's: the
 * cursor names the chunk, the ledger the offset in it up to which the
 * statements' effects are committed. For a table with transactions, a
 * batch of INSERTs and the ledger's move are one transaction, committed at
 * the engine's checkpoint pace (2 s or 16 MB) and when the tick ends; for
 * a table without, after each statement, and a count that disagrees with
 * the ledger at the start of a tick empties the table and imports it again
 * from its first chunk. DROP and CREATE TABLE commit on their own; the
 * ledger learns of a table only after its CREATE ran, so a replay before
 * that runs the chunk's DROP and CREATE again. A resumed chunk runs its
 * preamble (the session's character set) again from the chunk's head
 * first; a position is recorded only after a statement that is not part of
 * the preamble, so it never falls inside it.
 *
 * The cursor carries the chunk's offset too (from the ledger, after each
 * commit), so a tick that ran statements moves the cursor and is not taken
 * for one without progress.
 *
 * The time a statement takes depends on the database: the first statement
 * of a tick always runs, the tick ends when the time left is under 1.5
 * times the slowest statement so far, and a statement slower than the
 * whole budget ends the job with the reason.
 *
 * After a table's last chunk, the rows it received must be the rows the
 * manifest says it had; after the options table (and the network's
 * sitemeta table), the list of active plugins must be one WordPress wrote
 * (StateCarry::readable()): the restore does not guess which plugins the
 * restored site runs.
 */
final class DatabaseImportStep implements Step {

	const ID     = 'restore_database';
	const MARGIN = 1.5;

	/**
	 * Engines with transactions (lowercase), as information_schema names them.
	 */
	const TRANSACTIONAL = array( 'innodb', 'tokudb', 'rocksdb' );

	/**
	 * Opens the import connection: function(): ImportSession.
	 *
	 * @var callable
	 */
	private $connect;

	/**
	 * Test seam: function( string $point ): void, called at "statement" (a statement ran, its record not
	 * yet) and "commit" (a record committed, the cursor not yet checkpointed); a test throws there to
	 * stand for a run killed at that point.
	 *
	 * @var callable|null
	 */
	private $crash;

	/**
	 * Constructor.
	 *
	 * @param callable|null $connect function(): ImportSession (tests); the site's own settings by default.
	 * @param callable|null $crash   Test seam (see $crash).
	 */
	public function __construct( $connect = null, $crash = null ) {
		$this->crash   = is_callable( $crash ) ? $crash : null;
		$this->connect = is_callable( $connect ) ? $connect : static function (): ImportSession {
			try {
				$credentials = Credentials::from_wordpress();
			} catch ( Failure $e ) {
				throw new \RuntimeException( 'The database settings of this site cannot be read: ' . $e->getMessage() );
			}
			return ImportSession::open( $credentials );
		};
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
	 * Import until done or the tick ends.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws Refused When a chunk is not what the restore runs, or does not agree with the manifest.
	 * @throws TransientFailure When the database or the work directory is not available now.
	 */
	public function run( JobContext $context ): StepResult {
		$work     = $context->work_path();
		$plan     = RestorePreflightStep::load_plan( $work );
		$manifest = RestorePreflightStep::manifest( $work );
		$rows_of  = array_column( $manifest->tables(), 'rows', 'name' );
		$cursor   = array_merge(
			array(
				'walk'        => ChunkWalk::start(),
				'current'     => null,
				'table_start' => null,
			),
			$context->cursor()
		);
		$walk     = new ChunkWalk( RestoreFiles::path( $work, RestoreFiles::INDEX ), $plan['volumes'], $plan['chunk_bytes'] );
		$names    = new ConstraintNames( $plan['random'] );
		$db       = call_user_func( $this->connect );
		$site_set = $db->charset(); // Each chunk starts from the site's own character set; its preamble may set another.
		$counted  = array();
		$columns  = array();
		$slowest  = 0.0;
		$first    = true;
		try {
			$ledger = new Ledger( $db, TempTables::ledger( $context->job()->storage_token, $context->job()->id, $plan['random'] ) );
			while ( true ) {
				if ( null === $cursor['current'] ) {
					$chunk = $walk->at( $cursor['walk'] );
					if ( null === $chunk ) {
						$this->finish( $plan['plan'], $ledger );
						return StepResult::done( __( 'The database is ready next to the site', 'wp-checkpoint' ) );
					}
					$table = $plan['plan']->find( $chunk['line']['t'] );
					if ( null === $table ) {
						$cursor['walk'] = $chunk['next']; // Left out of the restore.
						continue;
					}
					if ( 1 === $chunk['line']['c'] ) {
						$cursor['table_start'] = $cursor['walk'];
					}
					$cursor['current'] = array(
						'at'    => $cursor['walk'],
						'n'     => $table['number'],
						'c'     => $chunk['line']['c'],
						'ready' => false,
						'pos'   => 0,
					);
					$cursor['walk']    = $chunk['next'];
				}
				$table = $plan['plan']->tables()[ (int) $cursor['current']['n'] ] ?? null;
				if ( null === $table ) {
					throw new WorkLost( 'The restore\'s position names a table its plan does not have.' );
				}
				$file = $this->chunk_file( $work, $walk, $cursor['current'] );
				if ( ! $cursor['current']['ready'] ) {
					$cursor['current']['ready'] = true;
					$context->checkpoint( $cursor, $this->percent( $cursor, $plan['plan'] ), __( 'Importing the database', 'wp-checkpoint' ) );
					if ( $context->should_stop() ) {
						return StepResult::progress( $cursor, $this->percent( $cursor, $plan['plan'] ), __( 'Importing the database', 'wp-checkpoint' ) );
					}
				}
				if ( ! isset( $columns[ $table['number'] ] ) ) {
					$columns = array( $table['number'] => RestorePreflightStep::definition( RestoreFiles::path( $work, RestoreFiles::DEFINITIONS ), $table['number'] )['columns'] );
				}
				$target  = new ImportTarget(
					$table['table'],
					$table['temporary'],
					$table['final'],
					$table['number'],
					$names,
					array( $plan['plan'], 'reference' ),
					$columns[ $table['number'] ]
				);
				$outcome = $this->import_chunk( $context, $db, $ledger, $target, $file, (int) $cursor['current']['c'], $counted, $slowest, $first, $cursor, $plan['plan'], $site_set );
				if ( 'restart' === $outcome ) {
					$cursor['current'] = null;
					$cursor['walk']    = $cursor['table_start'];
					$context->checkpoint( $cursor, $this->percent( $cursor, $plan['plan'] ), __( 'Importing the database', 'wp-checkpoint' ) );
					continue;
				}
				if ( 'stopped' === $outcome ) {
					$context->checkpoint( $cursor, $this->percent( $cursor, $plan['plan'] ), __( 'Importing the database', 'wp-checkpoint' ) );
					return StepResult::progress( $cursor, $this->percent( $cursor, $plan['plan'] ), __( 'Importing the database', 'wp-checkpoint' ) );
				}
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the next chunk's extraction overwrites leftovers; the engine removes the directory.
				if ( (int) $cursor['current']['c'] === $table['chunks'] ) {
					$this->table_done( $db, $ledger, $table, (int) ( $rows_of[ $table['table'] ] ?? -1 ), $plan );
				}
				$cursor['current'] = null;
				$context->checkpoint( $cursor, $this->percent( $cursor, $plan['plan'] ), __( 'Importing the database', 'wp-checkpoint' ) );
				if ( $context->should_stop() ) {
					return StepResult::progress( $cursor, $this->percent( $cursor, $plan['plan'] ), __( 'Importing the database', 'wp-checkpoint' ) );
				}
			}
		} finally {
			$db->close();
		}
	}

	/**
	 * Run the statements of one chunk from where the ledger says.
	 *
	 * @param JobContext           $context Context.
	 * @param ImportSession        $db      Connection.
	 * @param Ledger               $ledger  Ledger.
	 * @param ImportTarget         $target  The table.
	 * @param string               $file    The extracted chunk.
	 * @param int                  $chunk   Chunk number.
	 * @param array<int, bool>     $counted Tables whose row count was checked in this tick (updated).
	 * @param float                $slowest Slowest statement so far (updated).
	 * @param bool                 $first   Whether no statement ran yet in this tick (updated).
	 * @param array<string, mixed> $cursor  Cursor (its chunk's pos follows the ledger, so a tick that moves the ledger moves the cursor).
	 * @param TablePlan            $plan    Plan.
	 * @param string               $session_charset The site's character set, the session's before the chunk's preamble.
	 * @return string "done", "stopped" or "restart".
	 * @throws Refused When a statement is not one the restore runs.
	 * @throws WorkLost When the ledger and the position disagree.
	 * @throws LockLost When another run moved the ledger.
	 * @throws \RuntimeException When one statement takes longer than the whole time budget.
	 */
	private function import_chunk( JobContext $context, ImportSession $db, Ledger $ledger, ImportTarget $target, string $file, int $chunk, array &$counted, float &$slowest, bool &$first, array &$cursor, TablePlan $plan, string $session_charset ): string {
		$number = $target->number;
		$state  = $ledger->get( $number );
		if ( null === $state ) {
			if ( 1 !== $chunk ) {
				throw new WorkLost( sprintf( 'The restore\'s ledger has no record of the table %s, whose chunk %d is next.', $target->table, $chunk ) );
			}
			$pos = 0;
		} elseif ( $state['chunk'] === $chunk ) {
			$pos = $state['pos'];
		} elseif ( $state['chunk'] < $chunk ) {
			$pos = 0;
		} else {
			throw new WorkLost( sprintf( 'The restore\'s ledger is further on in the table %s than its position.', $target->table ) );
		}
		if ( null !== $state && ! $state['transactional'] && empty( $counted[ $number ] ) ) {
			$count = $db->rows( 'SELECT COUNT(*) FROM ' . SqlWriter::identifier( $target->temporary ) );
			if ( (int) ( $count[0][0] ?? -1 ) !== $state['rows'] ) {
				// A statement ran and was not recorded: its rows cannot be told from the others. Empty the table, keep its definition.
				$db->run( 'TRUNCATE TABLE ' . SqlWriter::identifier( $target->temporary ) );
				$ledger->restart( $number, $state['chunk'], $state['pos'] );
				$context->logger()->warning( 'A table without transactions is imported again from its first chunk: a run stopped between a statement and its record', array( 'table' => $target->table ) );
				$counted[ $number ] = true;
				return 'restart';
			}
			$counted[ $number ] = true;
		}
		$db->names( $session_charset );
		$charset = $session_charset;
		if ( $pos > 0 ) {
			// The session's settings (the character set of the chunk's text) come from the preamble at the head.
			$head = new ChunkReader( $file, 0, $target, $chunk );
			try {
				for ( $statement = $head->next(); null !== $statement && Statement::SET === $statement->kind; $statement = $head->next() ) {
					$this->run_set( $db, $statement );
				}
				$charset = $head->charset();
			} finally {
				$head->close();
			}
		}
		$reader   = new ChunkReader( $file, $pos, $target, $chunk, ChunkReader::READ_BYTES, false, $charset );
		$at_chunk = null === $state ? 0 : $state['chunk'];
		$at_pos   = null === $state ? 0 : $state['pos'];
		$rows     = 0;
		$end      = $pos;
		$open     = false;
		$bytes    = 0;
		$budget   = (float) $context->budget()->seconds;
		try {
			while ( true ) {
				if ( ! $first && $context->remaining_seconds() < $slowest * self::MARGIN ) {
					$this->flush( $db, $ledger, $number, $chunk, $at_chunk, $at_pos, $end, $rows, $open, null !== $state );
					$cursor['current']['pos'] = $end;
					return 'stopped';
				}
				$statement = $reader->next();
				if ( null === $statement ) {
					break;
				}
				$started = $context->elapsed();
				if ( Statement::SET === $statement->kind ) {
					$this->run_set( $db, $statement );
				} elseif ( Statement::DROP === $statement->kind || Statement::CREATE === $statement->kind ) {
					if ( null !== $state ) {
						throw new Refused( sprintf( 'Table %s, chunk %d: the table is dropped or created again after its rows began.', $target->table, $chunk ) );
					}
					$db->run( $statement->sql );
					if ( Statement::CREATE === $statement->kind ) {
						$engine = $db->rows( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', array( $target->temporary ) );
						$ledger->created( $number, $statement->end, in_array( strtolower( (string) ( $engine[0][0] ?? '' ) ), self::TRANSACTIONAL, true ) );
						$this->crash( 'commit' );
						$state    = $ledger->get( $number );
						$at_chunk = 1;
						$at_pos   = $statement->end;
						$this->record_constraints( $context, $target, $statement );
					}
				} else {
					if ( null === $state ) {
						throw new Refused( sprintf( 'Table %s, chunk %d: rows before the table is created.', $target->table, $chunk ) );
					}
					if ( ! $open ) {
						$db->begin();
						$open = true;
					}
					$db->run( $statement->sql );
					$rows  += $statement->rows;
					$bytes += strlen( $statement->sql );
				}
				if ( Statement::SET !== $statement->kind ) {
					// A recorded position is never inside the preamble: a resumed chunk runs the preamble from its head.
					$end = $statement->end;
				}
				$this->crash( 'statement' );
				$cost    = $context->elapsed() - $started;
				$first   = false;
				$slowest = max( $slowest, $cost );
				if ( $cost > $budget ) {
					throw new \RuntimeException( sprintf( 'The database is too slow on this server: one statement of the table %1$s took %2$d seconds, more than the %3$d-second time budget of a single run.', $target->table, (int) ceil( $cost ), (int) $budget ) );
				}
				if ( null !== $state && ! $state['transactional'] ) {
					// No transaction to put the record in: record each statement as soon as it ran.
					$this->flush( $db, $ledger, $number, $chunk, $at_chunk, $at_pos, $end, $rows, $open, true );
				}
				if ( $context->should_checkpoint( $bytes ) ) {
					$this->flush( $db, $ledger, $number, $chunk, $at_chunk, $at_pos, $end, $rows, $open, null !== $state );
					$bytes                    = 0;
					$cursor['current']['pos'] = $end;
					$context->checkpoint( $cursor, $this->percent( $cursor, $plan ), __( 'Importing the database', 'wp-checkpoint' ) );
				}
				if ( $context->should_stop() ) {
					$this->flush( $db, $ledger, $number, $chunk, $at_chunk, $at_pos, $end, $rows, $open, null !== $state );
					$cursor['current']['pos'] = $end;
					return 'stopped';
				}
			}
			$this->flush( $db, $ledger, $number, $chunk, $at_chunk, $at_pos, $end, $rows, $open, null !== $state );
			$cursor['current']['pos'] = $end;
			if ( null === $state ) {
				throw new Refused( sprintf( 'Table %s, chunk %d: the chunk does not create the table.', $target->table, $chunk ) );
			}
			return 'done';
		} finally {
			if ( $open ) {
				$db->rollback(); // Stopped by a failure: every return above committed first.
			}
			$reader->close();
		}
	}

	/**
	 * Run a preamble statement: SET NAMES through the connection (both of its sides), the others as they are.
	 *
	 * @param ImportSession $db        Connection.
	 * @param Statement     $statement A SET statement.
	 * @return void
	 */
	private function run_set( ImportSession $db, Statement $statement ): void {
		if ( '' !== $statement->charset ) {
			$db->names( $statement->charset );
			return;
		}
		$db->run( $statement->sql );
	}

	/**
	 * Commit what ran since the last record: the ledger's move and the rows, in one transaction.
	 *
	 * @param ImportSession $db       Connection.
	 * @param Ledger        $ledger   Ledger.
	 * @param int           $number   Table number.
	 * @param int           $chunk    Chunk number.
	 * @param int           $at_chunk Ledger chunk read before (updated).
	 * @param int           $at_pos   Ledger offset read before (updated).
	 * @param int           $end      Offset after the last statement run.
	 * @param int           $rows     Rows inserted since (reset).
	 * @param bool          $open     Whether a transaction is open (reset).
	 * @param bool          $recorded Whether the ledger knows the table (before its CREATE nothing is recorded).
	 * @return void
	 */
	private function flush( ImportSession $db, Ledger $ledger, int $number, int $chunk, int &$at_chunk, int &$at_pos, int $end, int &$rows, bool &$open, bool $recorded ): void {
		if ( $recorded && ( $at_chunk !== $chunk || $at_pos !== $end ) ) {
			if ( ! $open ) {
				$db->begin();
				$open = true;
			}
			$ledger->advance( $number, $at_chunk, $at_pos, $chunk, $end, $rows );
			$at_chunk = $chunk;
			$at_pos   = $end;
			$rows     = 0;
		}
		if ( $open ) {
			$db->commit();
			$open = false;
			$this->crash( 'commit' );
		}
	}

	/**
	 * The test seam.
	 *
	 * @param string $point "statement" or "commit".
	 * @return void
	 */
	private function crash( string $point ): void {
		if ( null !== $this->crash ) {
			call_user_func( $this->crash, $point );
		}
	}

	/**
	 * The extracted chunk of the position, extracted and checked again when it is not there or not the chunk.
	 *
	 * @param string               $work    Work directory.
	 * @param ChunkWalk            $walk    Walk.
	 * @param array<string, mixed> $current Position of the chunk.
	 * @return string
	 * @throws Refused When the chunk does not match the index.
	 * @throws TransientFailure When it cannot be extracted.
	 */
	private function chunk_file( string $work, ChunkWalk $walk, array $current ): string {
		$chunk = $walk->at( $current['at'] );
		if ( null === $chunk ) {
			throw new WorkLost( 'The restore\'s position is past the end of the database index.' );
		}
		$line = $chunk['line'];
		$dir  = RestoreFiles::path( $work, RestoreFiles::CHUNKS );
		$path = $dir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $line['p'] );
		clearstatcache( true, $path );
		if ( is_file( $path ) && (int) filesize( $path ) === $line['b'] && hash_equals( $line['h'], ChunkHasher::hash_file( $path ) ) ) {
			return $path;
		}
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700 ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would put the path into the error log.
			throw new TransientFailure( 'A work directory of the restore could not be created.' );
		}
		try {
			$piece = $chunk['reader']->extract_piece( $chunk['entry'], $dir, 0, $line['b'], 0 );
		} catch ( EnvironmentFailure $e ) {
			throw $e;
		} catch ( \RuntimeException $e ) {
			throw new Refused( sprintf( 'The database chunk %s cannot be read from the backup: %s', $line['p'], $e->getMessage() ) );
		}
		if ( ! hash_equals( $line['h'], ChunkHasher::hash_file( $piece['path'] ) ) ) {
			@unlink( $piece['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort.
			throw new Refused( sprintf( 'The database chunk %s does not match its checksum; the backup is damaged.', $line['p'] ) );
		}
		return $piece['path'];
	}

	/**
	 * A table's last chunk ran: its row count, and the lists of active plugins.
	 *
	 * @param ImportSession                                                                                $db     Connection.
	 * @param Ledger                                                                                       $ledger Ledger.
	 * @param array{table: string, temporary: string, final: string, number: int, chunks: int}             $table  The table.
	 * @param int                                                                                          $rows   Rows the manifest says it had.
	 * @param array{plan: TablePlan, random: string, chunk_bytes: int, volumes: string[], multisite: bool} $plan   Plan.
	 * @return void
	 * @throws Refused When they do not agree.
	 */
	private function table_done( ImportSession $db, Ledger $ledger, array $table, int $rows, array $plan ): void {
		$state = $ledger->get( $table['number'] );
		if ( null === $state || $state['rows'] !== $rows ) {
			throw new Refused( sprintf( 'The chunks of the table %1$s hold %2$d rows, and its manifest says %3$d; the backup does not agree with itself.', $table['table'], null === $state ? 0 : $state['rows'], $rows ) );
		}
		$prefix = (string) $plan['plan']->to_array()['backup_prefix'];
		if ( $prefix . 'options' === $table['table'] ) {
			foreach ( $db->rows( 'SELECT option_value FROM ' . SqlWriter::identifier( $table['temporary'] ) . " WHERE option_name = 'active_plugins'" ) as $row ) {
				if ( ! StateCarry::readable( null === $row[0] ? null : (string) $row[0] ) ) {
					throw new Refused( 'The backup\'s list of active plugins (active_plugins) is not one WordPress wrote, so its options table is damaged; the restore does not guess which plugins the restored site runs.' );
				}
			}
		}
		if ( $plan['multisite'] && $prefix . 'sitemeta' === $table['table'] ) {
			foreach ( $db->rows( 'SELECT meta_value FROM ' . SqlWriter::identifier( $table['temporary'] ) . " WHERE meta_key = 'active_sitewide_plugins'" ) as $row ) {
				if ( ! StateCarry::readable( null === $row[0] ? null : (string) $row[0] ) ) {
					throw new Refused( 'The backup\'s list of network-active plugins (active_sitewide_plugins) is not one WordPress wrote, so its sitemeta table is damaged; the restore does not guess which plugins the restored network runs.' );
				}
			}
		}
	}

	/**
	 * Every planned table was imported to its last chunk.
	 *
	 * @param TablePlan $plan   Plan.
	 * @param Ledger    $ledger Ledger.
	 * @return void
	 * @throws Refused When one was not.
	 */
	private function finish( TablePlan $plan, Ledger $ledger ): void {
		foreach ( $plan->tables() as $table ) {
			$state = $ledger->get( $table['number'] );
			if ( null === $state || $state['chunk'] !== $table['chunks'] ) {
				throw new Refused( sprintf( 'The database index ends before the last chunk of the table %s.', $table['table'] ) );
			}
		}
	}

	/**
	 * Note the names the table's constraints got (for the cleanup after the swap) and warn about shortened ones.
	 *
	 * @param JobContext   $context   Context.
	 * @param ImportTarget $target    The table.
	 * @param Statement    $statement Its CREATE TABLE.
	 * @return void
	 * @throws TransientFailure When the note cannot be written.
	 */
	private function record_constraints( JobContext $context, ImportTarget $target, Statement $statement ): void {
		if ( array() === $statement->constraints ) {
			return;
		}
		foreach ( $statement->constraints as $constraint ) {
			if ( $constraint['shortened'] ) {
				$context->logger()->warning(
					'A constraint name of this table was shortened: plugins that look for it by its name will not find it until the restore is confirmed. Do not update WooCommerce before confirming the restore.',
					array(
						'table'      => $target->table,
						'constraint' => $constraint['intended'],
					)
				);
			}
		}
		$line = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- read back; a failure is thrown.
			array(
				'n'           => $target->number,
				'temporary'   => $target->temporary,
				'constraints' => $statement->constraints,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if ( ! is_string( $line ) || false === @file_put_contents( RestoreFiles::path( $context->work_path(), RestoreFiles::CONSTRAINTS ), $line . "\n", FILE_APPEND ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would put the path into the error log.
			throw new TransientFailure( 'A work file of the restore could not be written.' );
		}
	}

	/**
	 * Progress through the chunks (60-95).
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @param TablePlan            $plan   Plan.
	 * @return int
	 */
	private function percent( array $cursor, TablePlan $plan ): int {
		$total = max( 1, array_sum( array_column( $plan->tables(), 'chunks' ) ) + count( $plan->skipped() ) );
		return (int) min( 95, 60 + floor( 35 * (int) $cursor['walk']['line'] / $total ) );
	}

	/**
	 * Nothing to do: the temporary tables and the ledger carry the job's prefix and the engine drops them with its work directory.
	 *
	 * @param JobContext $context Context.
	 * @return void
	 */
	public function cleanup( JobContext $context ): void {
		unset( $context );
	}
}
