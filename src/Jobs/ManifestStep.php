<?php
/**
 * The step that closes the archive: indexes, manifest, self-check.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Archive\ArchiveVerifier;
use WPCheckpoint\Archive\ChunkHasher;
use WPCheckpoint\Archive\IndexAudit;
use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Archive\Packer;
use WPCheckpoint\Archive\VerificationResult;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages carry line numbers, table names and the verifier's cleaned report; the runner stores them through the redactor and the presenter cleans them before display.

/**
 * Phases, in order, each resumable from the cursor and each made of
 * bounded units (a batch of index lines, one hash chunk, one 4 MiB piece,
 * one container block, one verifier step); nothing here reads a whole
 * index in one unit:
 *
 *  1. audit: every line of the packed files index has a content hash and
 *     the database index is in lockstep with the export summary
 *     (IndexAudit, a batch of lines per unit). Either failing fails the
 *     job before anything is written: an archive without content hashes
 *     would pass verification on sizes alone.
 *  2. hash: the content hashes of both index files, one chunk per unit,
 *     for their manifest entries.
 *  3. prepare: Packer::prepare_finish() decides whether the open volume
 *     takes the summaries or is sealed first (a checkpointed unit of its
 *     own, so the seal has a replay rule of its own).
 *  4. blocks_before: container hashes of what prepare sealed, one block
 *     per unit. Every sealed volume is hashed before the embedded copy is
 *     assembled: it lists exactly the sealed volumes, every volume but
 *     the one that holds it.
 *  5. indexes: the two index files are appended as entries, one piece
 *     per unit, the packer state checkpointed between pieces.
 *  6. finish: the embedded manifest is assembled and Packer::finish()
 *     appends it and seals the last volume (idempotent on replay).
 *  7. blocks_after: container hashes of the last volume.
 *  8. standalone: the full manifest (every volume) as <base>.manifest.json
 *     next to the volumes (temporary file and rename).
 *  9. verify: ArchiveVerifier at structure depth over what was written,
 *     one verifier step per unit. Anything but a clean structure pass
 *     fails the job with the verifier's own report: this is the writer
 *     and the reader disagreeing on the same bytes, a defect in the
 *     writer, and the report names the phase and the volume by number.
 *
 * Site facts (URLs, versions, charset) come in through the constructor so
 * the step is testable without WordPress; the export job supplies them.
 */
final class ManifestStep implements Step {

	const ID         = 'manifest';
	const VERIFY_DIR = 'verify';

	const SELF_CHECK_PREFIX = 'Self-check of the written archive failed. This is a defect in the backup writer, not in your data; please report it with the job log.';

	/**
	 * Fixed part of the room the summaries need beyond the index bytes and
	 * the manifest: three local headers, three central records, the end
	 * record, and a deflate that does not shrink. Plus one percent of the
	 * index bytes (deflate's worst case is well under that).
	 */
	const SUMMARY_MARGIN_BYTES = 8192;

	/**
	 * Site facts for the manifest: the keys Manifest requires under "site".
	 *
	 * @var array<string, mixed>
	 */
	private $site;

	/**
	 * Generator name and version.
	 *
	 * @var array{name: string, version: string}
	 */
	private $generator;

	/**
	 * Packer options (the same the pack step used).
	 *
	 * @var array<string, mixed>
	 */
	private $packer_options;

	/**
	 * Content chunk size.
	 *
	 * @var int
	 */
	private $chunk_bytes;

	/**
	 * Cleans the verifier's report for the error message (JobPresenter::clean in production).
	 *
	 * @var callable
	 */
	private $clean;

	/**
	 * Clock for the manifest's created_at and the summary entries' mtime (time() in production).
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>                 $site           Site facts (Manifest's "site" keys).
	 * @param array{name: string, version: string} $generator      Generator.
	 * @param array<string, mixed>                 $packer_options Packer options.
	 * @param int                                  $chunk_bytes    Content chunk size.
	 * @param callable|null                        $clean          Report cleaner; identity when null.
	 * @param callable|null                        $now            Clock (tests); time() when null.
	 */
	public function __construct( array $site, array $generator, array $packer_options = array(), int $chunk_bytes = Manifest::DEFAULT_CHUNK, $clean = null, $now = null ) {
		$this->now            = is_callable( $now ) ? $now : 'time';
		$this->site           = $site;
		$this->generator      = $generator;
		$this->packer_options = PackStep::packer_options_for( $packer_options, $chunk_bytes );
		$this->chunk_bytes    = $chunk_bytes;
		$this->clean          = is_callable( $clean ) ? $clean : static function ( string $text ): string {
			return $text;
		};
	}

	/**
	 * The packer options of this run: the lease is confirmed right before
	 * each volume file is created or renamed (Packer's "confirm" option).
	 *
	 * @param JobContext $context Context.
	 * @return array<string, mixed>
	 */
	private function packer_options_with( JobContext $context ): array {
		return array_merge(
			$this->packer_options,
			array(
				'confirm' => static function () use ( $context ): void {
					$context->confirm_lease();
				},
			)
		);
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
	 * Run the units until done.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws TransientFailure When a file cannot be written.
	 * @throws \RuntimeException When an audit or the self-check fails.
	 */
	public function run( JobContext $context ): StepResult {
		$work    = $context->work_path();
		$cursor  = array_merge(
			array(
				'phase'    => 'audit',
				'audit'    => array(
					'database' => IndexAudit::database_start(),
					'files'    => IndexAudit::files_start(),
				),
				'indexes'  => array(),
				'mtime'    => 0,
				'entry'    => 0,
				'verifier' => array(),
			),
			$context->cursor()
		);
		$plan    = ExportPlan::read( $work, ExportPlan::PLAN );
		$base    = (string) $plan['base'];
		$volumes = $work . DIRECTORY_SEPARATOR . PackStep::VOLUMES;

		if ( 'audit' === $cursor['phase'] ) {
			$result = $this->audit( $context, $work, $cursor );
			if ( null !== $result ) {
				return $result;
			}
		}
		if ( 'hash' === $cursor['phase'] ) {
			$result = $this->hash_indexes( $context, $work, $cursor );
			if ( null !== $result ) {
				return $result;
			}
		}
		if ( 'prepare' === $cursor['phase'] ) {
			$packer = Packer::open( $volumes, $base, $this->packer_state( $context ), $this->packer_options_with( $context ) );
			try {
				if ( 0 === (int) $cursor['mtime'] ) {
					throw new \RuntimeException( 'The manifest clock was not fixed before the last volume was decided (a phase ran out of order).' );
				}
				// A volume prepare_finish() sealed in a run that died before its checkpoint was adopted by resume()
				// unhashed; the estimate below needs every sealed volume hashed. One block per unit.
				$more = $packer->has_unhashed_blocks();
				while ( $more ) {
					$packer->hash_next_block();
					$cursor['packer'] = $packer->state();
					$more             = $packer->has_unhashed_blocks();
					$context->checkpoint( $cursor, 30, __( 'Hashing the volumes', 'wp-checkpoint' ) );
					if ( $more && $context->should_stop() ) {
						return StepResult::progress( $cursor, 30, __( 'Hashing the volumes', 'wp-checkpoint' ) );
					}
				}
				$embedded = $this->assemble( $work, $plan, $packer, true, $cursor );
				$total    = $this->index_bytes( $cursor ) + strlen( $embedded );
				$packer->prepare_finish( $total + self::SUMMARY_MARGIN_BYTES + intdiv( $total, 100 ) );
				$cursor['packer'] = $packer->state();
			} finally {
				$packer->close();
			}
			$cursor['phase'] = 'blocks_before';
			$context->checkpoint( $cursor, 30, __( 'Last volume decided', 'wp-checkpoint' ) );
			if ( $context->should_stop() ) {
				return StepResult::progress( $cursor, 30, __( 'Last volume decided', 'wp-checkpoint' ) );
			}
		}
		if ( 'blocks_before' === $cursor['phase'] ) {
			$result = $this->hash_blocks( $context, $volumes, $base, $cursor, 'indexes', 35 );
			if ( null !== $result ) {
				return $result;
			}
		}
		if ( 'indexes' === $cursor['phase'] ) {
			$result = $this->append_indexes( $context, $work, $volumes, $base, $cursor );
			if ( null !== $result ) {
				return $result;
			}
			if ( $context->should_stop() ) {
				// The seal is a unit of its own: it starts a tick, never ends one that is already spent.
				return StepResult::progress( $cursor, 55, __( 'Indexes written', 'wp-checkpoint' ) );
			}
		}
		if ( 'finish' === $cursor['phase'] ) {
			$packer = Packer::open( $volumes, $base, $cursor['packer'], $this->packer_options_with( $context ) );
			try {
				if ( $packer->already_finished() ) {
					// Sealed by a run that died before this checkpoint: nothing to assemble, the copy is in place.
					$packer->finish( array(), '', (int) $cursor['mtime'] );
				} else {
					if ( ! $packer->has_open_volume() ) {
						// No index entries were written (an archive without indexes is refused earlier, but the
						// packer does not create volumes on its own): the summaries' volume, checkpointed first.
						$packer->open_volume();
						$cursor['packer'] = $packer->state();
						$context->checkpoint( $cursor, 58, __( 'Last volume opened', 'wp-checkpoint' ) );
					}
					$packer->finish( array(), $this->assemble( $work, $plan, $packer, true, $cursor ), (int) $cursor['mtime'] );
				}
				$cursor['packer'] = $packer->state();
			} finally {
				$packer->close();
			}
			$cursor['phase'] = 'blocks_after';
			$context->checkpoint( $cursor, 60, __( 'Archive sealed', 'wp-checkpoint' ) );
			if ( $context->should_stop() ) {
				return StepResult::progress( $cursor, 60, __( 'Archive sealed', 'wp-checkpoint' ) );
			}
		}
		if ( 'blocks_after' === $cursor['phase'] ) {
			$result = $this->hash_blocks( $context, $volumes, $base, $cursor, 'standalone', 65 );
			if ( null !== $result ) {
				return $result;
			}
		}
		if ( 'standalone' === $cursor['phase'] ) {
			$packer = Packer::open( $volumes, $base, $cursor['packer'], $this->packer_options_with( $context ) );
			try {
				$standalone = $this->assemble( $work, $plan, $packer, false, $cursor );
			} finally {
				$packer->close();
			}
			$this->write_standalone( $volumes . DIRECTORY_SEPARATOR . $base . '.manifest.json', $standalone );
			$cursor['phase'] = 'verify';
			$context->checkpoint( $cursor, 70, __( 'Manifest written', 'wp-checkpoint' ) );
			if ( $context->should_stop() ) {
				return StepResult::progress( $cursor, 70, __( 'Manifest written', 'wp-checkpoint' ) );
			}
		}
		if ( 'verify' === $cursor['phase'] ) {
			$dir = $work . DIRECTORY_SEPARATOR . self::VERIFY_DIR;
			if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700 ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- a warning would put the path into the error log.
				throw new TransientFailure( 'The self-check directory could not be created.' );
			}
			$verifier = ArchiveVerifier::open( $volumes . DIRECTORY_SEPARATOR . $base . '.manifest.json', $dir, ArchiveVerifier::DEPTH_STRUCTURE, is_array( $cursor['verifier'] ) ? $cursor['verifier'] : array() );
			while ( $verifier->step() ) {
				$cursor['verifier'] = $verifier->state();
				if ( $context->should_stop() ) {
					return StepResult::progress( $cursor, 85, __( 'Checking the written archive', 'wp-checkpoint' ) );
				}
				$context->checkpoint( $cursor, 85, __( 'Checking the written archive', 'wp-checkpoint' ) );
			}
			$this->judge( $verifier->result(), $context );
			$cursor['phase'] = 'done';
		}
		$context->logger()->info( 'Manifest step finished', array( 'volumes' => count( $this->packer_volumes( $cursor ) ) ) );
		return StepResult::done( __( 'Archive checked', 'wp-checkpoint' ) );
	}

	/**
	 * Nothing to do: the self-check directory and the volumes live in the
	 * work directory, which the engine removes; no external resources.
	 *
	 * @param JobContext $context Context.
	 * @return void
	 */
	public function cleanup( JobContext $context ): void {
		unset( $context );
	}

	/**
	 * Phase 1: both audits, a batch of lines per unit.
	 *
	 * @param JobContext           $context Context.
	 * @param string               $work    Work directory.
	 * @param array<string, mixed> $cursor  Cursor (updated).
	 * @return StepResult|null Progress when the budget is spent, null when the phase is done.
	 * @throws \RuntimeException When an audit fails.
	 */
	private function audit( JobContext $context, string $work, array &$cursor ) {
		$summary = ExportPlan::read( $work, DatabaseExportStep::SUMMARY );
		$tables  = isset( $summary['tables'] ) && is_array( $summary['tables'] ) ? $summary['tables'] : array();
		while ( empty( $cursor['audit']['database']['done'] ) || empty( $cursor['audit']['files']['done'] ) ) {
			if ( empty( $cursor['audit']['database']['done'] ) ) {
				$cursor['audit']['database'] = IndexAudit::database_step( $work . DIRECTORY_SEPARATOR . Manifest::DATABASE_INDEX, $tables, $cursor['audit']['database'], $this->chunk_bytes );
			} else {
				$cursor['audit']['files'] = IndexAudit::files_step( $work . DIRECTORY_SEPARATOR . PackStep::PACKED_INDEX, $cursor['audit']['files'], $this->chunk_bytes );
			}
			$context->checkpoint( $cursor, 10, __( 'Auditing the indexes', 'wp-checkpoint' ) );
			if ( $context->should_stop() ) {
				return StepResult::progress( $cursor, 10, __( 'Auditing the indexes', 'wp-checkpoint' ) );
			}
		}
		$cursor['phase'] = 'hash';
		$context->checkpoint( $cursor, 20, __( 'Indexes audited', 'wp-checkpoint' ) );
		return null;
	}

	/**
	 * Phase 2: the content hashes of both index files, one chunk per unit.
	 *
	 * @param JobContext           $context Context.
	 * @param string               $work    Work directory.
	 * @param array<string, mixed> $cursor  Cursor (updated).
	 * @return StepResult|null Progress when the budget is spent, null when the phase is done.
	 * @throws \RuntimeException When an index cannot be read.
	 */
	private function hash_indexes( JobContext $context, string $work, array &$cursor ) {
		$sources = array(
			'database' => $work . DIRECTORY_SEPARATOR . Manifest::DATABASE_INDEX,
			'files'    => $work . DIRECTORY_SEPARATOR . PackStep::PACKED_INDEX,
		);
		foreach ( $sources as $which => $path ) {
			if ( ! isset( $cursor['indexes'][ $which ] ) ) {
				clearstatcache( true, $path );
				$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.
				if ( ! is_int( $size ) ) {
					throw new \RuntimeException( sprintf( 'The index %s is missing; the work directory was lost or changed.', basename( $path ) ) );
				}
				$cursor['indexes'][ $which ] = array(
					'bytes'  => $size,
					'chunks' => array(),
				);
			}
			$total = ChunkHasher::chunk_count( (int) $cursor['indexes'][ $which ]['bytes'], $this->chunk_bytes );
			$done  = count( $cursor['indexes'][ $which ]['chunks'] );
			while ( $done < $total ) {
				$cursor['indexes'][ $which ]['chunks'][] = ChunkHasher::hash_chunk( $path, $done, $this->chunk_bytes );
				++$done;
				$context->checkpoint( $cursor, 25, __( 'Hashing the indexes', 'wp-checkpoint' ) );
				if ( $done < $total && $context->should_stop() ) {
					return StepResult::progress( $cursor, 25, __( 'Hashing the indexes', 'wp-checkpoint' ) );
				}
			}
		}
		// Every value that reaches the manifest is fixed in a checkpoint before any byte that depends on it is
		// written: the clock behind created_at and the summary entries' mtime is decided here, once.
		$cursor['mtime'] = (int) call_user_func( $this->now );
		$cursor['phase'] = 'prepare';
		$context->checkpoint( $cursor, 28, __( 'Indexes hashed', 'wp-checkpoint' ) );
		return null;
	}

	/**
	 * Container hashes of the sealed volumes that still lack them, one
	 * block per unit; then on to the next phase.
	 *
	 * @param JobContext           $context Context.
	 * @param string               $volumes Volumes directory.
	 * @param string               $base    Archive base name.
	 * @param array<string, mixed> $cursor  Cursor (updated).
	 * @param string               $next    Phase after this one.
	 * @param int                  $percent Progress to report.
	 * @return StepResult|null Progress when the budget is spent, null when the phase is done.
	 */
	private function hash_blocks( JobContext $context, string $volumes, string $base, array &$cursor, string $next, int $percent ) {
		$packer = Packer::open( $volumes, $base, $cursor['packer'], $this->packer_options_with( $context ) );
		try {
			$more = $packer->has_unhashed_blocks();
			while ( $more ) {
				$packer->hash_next_block();
				$cursor['packer'] = $packer->state();
				$more             = $packer->has_unhashed_blocks();
				$context->checkpoint( $cursor, $percent, __( 'Hashing the volumes', 'wp-checkpoint' ) );
				if ( $more && $context->should_stop() ) {
					return StepResult::progress( $cursor, $percent, __( 'Hashing the volumes', 'wp-checkpoint' ) );
				}
			}
		} finally {
			$packer->close();
		}
		$cursor['phase'] = $next;
		$context->checkpoint( $cursor, $percent, __( 'Volumes hashed', 'wp-checkpoint' ) );
		return null;
	}

	/**
	 * Phase 5: the two index files as entries of the last volume, one piece
	 * per unit. A replay finds the entry in progress in the packer state
	 * and continues it from the committed length; an archive a previous
	 * run already finished (and did not checkpoint) is left alone.
	 *
	 * @param JobContext           $context Context.
	 * @param string               $work    Work directory.
	 * @param string               $volumes Volumes directory.
	 * @param string               $base    Archive base name.
	 * @param array<string, mixed> $cursor  Cursor (updated).
	 * @return StepResult|null Progress when the budget is spent, null when the phase is done.
	 */
	private function append_indexes( JobContext $context, string $work, string $volumes, string $base, array &$cursor ) {
		$entries = array(
			Manifest::DATABASE_INDEX => $work . DIRECTORY_SEPARATOR . Manifest::DATABASE_INDEX,
			Manifest::FILES_INDEX    => $work . DIRECTORY_SEPARATOR . PackStep::PACKED_INDEX,
		);
		$names   = array_keys( $entries );
		$count   = count( $names );
		$packer  = Packer::open( $volumes, $base, $cursor['packer'], $this->packer_options_with( $context ) );
		try {
			if ( $packer->already_finished() ) {
				$cursor['entry'] = $count;
			}
			$since = 0;
			while ( (int) $cursor['entry'] < $count ) {
				$name = $names[ (int) $cursor['entry'] ];
				if ( ! $packer->has_open_volume() ) {
					// prepare_finish() sealed the data volume: the summaries get one of their own, created as a
					// unit of its own and checkpointed before anything is written into it.
					$packer->open_volume( (int) filesize( $entries[ $name ] ) );
					$cursor['packer'] = $packer->state();
					$context->checkpoint( $cursor, 45, __( 'Last volume opened', 'wp-checkpoint' ) );
				}
				if ( ! $packer->has_open_entry() ) {
					$packer->add_entry( $entries[ $name ], $name, (int) $cursor['mtime'] );
				}
				$since += $packer->write_piece();
				if ( ! $packer->has_open_entry() ) {
					++$cursor['entry'];
				}
				$cursor['packer'] = $packer->state();
				if ( $context->should_checkpoint( $since ) || ! $packer->has_open_entry() ) {
					$context->checkpoint( $cursor, 50, __( 'Writing the indexes', 'wp-checkpoint' ) );
					$since = 0;
				}
				if ( (int) $cursor['entry'] < $count && $context->should_stop() ) {
					$context->checkpoint( $cursor, 50, __( 'Writing the indexes', 'wp-checkpoint' ) );
					return StepResult::progress( $cursor, 50, __( 'Writing the indexes', 'wp-checkpoint' ) );
				}
			}
			$cursor['packer'] = $packer->state();
		} finally {
			$packer->close();
		}
		$cursor['phase'] = 'finish';
		$context->checkpoint( $cursor, 55, __( 'Indexes written', 'wp-checkpoint' ) );
		return null;
	}

	/**
	 * The packer state to continue from: this step's own cursor once the
	 * prepare phase ran, before that the state the pack step wrote to
	 * packer.state.json (the cursor is wiped between steps).
	 *
	 * @param JobContext $context Context.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When the state is missing.
	 */
	private function packer_state( JobContext $context ): array {
		$cursor = $context->cursor();
		if ( isset( $cursor['packer'] ) && is_array( $cursor['packer'] ) && array() !== $cursor['packer'] ) {
			return $cursor['packer'];
		}
		return ExportPlan::read( $context->work_path(), PackStep::STATE );
	}

	/**
	 * Bytes of both index files (from the hash phase).
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @return int
	 */
	private function index_bytes( array $cursor ): int {
		$bytes = 0;
		foreach ( array( 'database', 'files' ) as $which ) {
			$bytes += isset( $cursor['indexes'][ $which ]['bytes'] ) ? (int) $cursor['indexes'][ $which ]['bytes'] : 0;
		}
		return $bytes;
	}

	/**
	 * The sealed volumes recorded in the cursor's packer state (for the log).
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @return array<int, mixed>
	 */
	private function packer_volumes( array $cursor ): array {
		return isset( $cursor['packer']['sealed'] ) && is_array( $cursor['packer']['sealed'] ) ? $cursor['packer']['sealed'] : array();
	}

	/**
	 * The manifest as JSON, validated by Manifest itself before it leaves.
	 * The volume list is the packer's sealed volumes: for the embedded
	 * copy (assembled before finish()) that is every volume but the one
	 * about to hold it; for the standalone manifest, every volume.
	 *
	 * @param string               $work     Work directory.
	 * @param array<string, mixed> $plan     plan.json.
	 * @param Packer               $packer   Packer (for the volume entries).
	 * @param bool                 $embedded Embedded copy or standalone.
	 * @param array<string, mixed> $cursor   Cursor (audit totals and index hashes).
	 * @return string
	 * @throws \RuntimeException When the manifest does not validate (a writer defect).
	 */
	private function assemble( string $work, array $plan, Packer $packer, bool $embedded, array $cursor ): string {
		$review   = ExportPlan::read( $work, ExportPlan::REVIEW );
		$active   = ExportPlan::effective( $plan, $review );
		$database = ExportPlan::read( $work, DatabaseExportStep::SUMMARY );
		$warnings = array();
		foreach ( array( ExportPlan::PREFLIGHT, FileScanStep::SUMMARY, DatabaseExportStep::SUMMARY, PackStep::SUMMARY ) as $name ) {
			if ( ExportPlan::exists( $work, $name ) ) {
				$data = ExportPlan::read( $work, $name );
				foreach ( isset( $data['warnings'] ) && is_array( $data['warnings'] ) ? $data['warnings'] : array() as $warning ) {
					$warnings[] = (string) $warning;
				}
			}
		}
		foreach ( isset( $review['decisions']['notes'] ) && is_array( $review['decisions']['notes'] ) ? $review['decisions']['notes'] : array() as $note ) {
			$warnings[] = (string) $note;
		}
		$tables = array();
		foreach ( isset( $database['tables'] ) && is_array( $database['tables'] ) ? $database['tables'] : array() as $table ) {
			$tables[] = array(
				'name'   => (string) $table['name'],
				'rows'   => (int) $table['rows'],
				'bytes'  => (int) $table['bytes'],
				'chunks' => (int) $table['chunks'],
				'sha256' => (string) $table['sha256'],
			);
		}
		$document = array(
			'format'            => 'wpcheckpoint-archive',
			'format_version'    => 1,
			'required_features' => array(),
			'kind'              => 'backup',
			'trigger'           => 'manual',
			'created_at'        => gmdate( 'Y-m-d\TH:i:s\Z', (int) $cursor['mtime'] ),
			'generator'         => $this->generator,
			'site'              => $this->site,
			'hashing'           => array(
				'algorithm'          => 'sha256',
				'chunk_bytes'        => $this->chunk_bytes,
				'volume_chunk_bytes' => isset( $this->packer_options['volume_chunk_bytes'] ) ? (int) $this->packer_options['volume_chunk_bytes'] : Manifest::DEFAULT_VOLUME_CHUNK,
			),
			'contents'          => array(
				'database' => array() !== $tables,
				'files'    => $active['groups'],
			),
			'exclusions'        => array_values( array_merge( $active['exclusions'], $active['exclude_paths'] ) ),
			'database'          => array_merge(
				array( 'index' => $this->content_entry( $cursor, 'database', Manifest::DATABASE_INDEX ) ),
				isset( $database['exported'] ) && is_array( $database['exported'] ) ? array( 'exported' => $database['exported'] ) : array(),
				array( 'tables' => $tables )
			),
			'files'             => array(
				'count' => (int) $cursor['audit']['files']['count'],
				'bytes' => (int) $cursor['audit']['files']['bytes'],
				'index' => $this->content_entry( $cursor, 'files', Manifest::FILES_INDEX ),
			),
			'volumes'           => $packer->volume_entries(),
			'embedded'          => $embedded,
			'encryption'        => null,
			'warnings'          => array_values( array_unique( $warnings ) ),
		);
		$json     = json_encode( $document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure PHP class.
		if ( ! is_string( $json ) ) {
			throw new \RuntimeException( 'The manifest could not be encoded.' );
		}
		try {
			return Manifest::from_json( $json )->to_json(); // The reader's rules, before a byte is written.
		} catch ( \InvalidArgumentException $e ) {
			throw new \RuntimeException( 'The manifest the writer assembled does not pass the reader\'s validation (a defect in the backup writer, please report it): ' . $e->getMessage() );
		}
	}

	/**
	 * A content entry ({path, bytes, chunks?, sha256}) for a side index,
	 * from the hashes the hash phase recorded.
	 *
	 * @param array<string, mixed> $cursor     Cursor.
	 * @param string               $which      'database' or 'files'.
	 * @param string               $entry_path Entry name in the archive.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When the hashes are not there (a phase ran out of order).
	 */
	private function content_entry( array $cursor, string $which, string $entry_path ): array {
		if ( ! isset( $cursor['indexes'][ $which ]['bytes'], $cursor['indexes'][ $which ]['chunks'] ) ) {
			throw new \RuntimeException( 'The index hashes are missing from the cursor.' );
		}
		$bytes  = (int) $cursor['indexes'][ $which ]['bytes'];
		$chunks = (array) $cursor['indexes'][ $which ]['chunks'];
		$entry  = array(
			'path'  => $entry_path,
			'bytes' => $bytes,
		);
		if ( $bytes > $this->chunk_bytes ) {
			$entry['chunks'] = array_values( $chunks );
			$entry['sha256'] = ChunkHasher::list_hash( $chunks );
		} else {
			$entry['sha256'] = array() === $chunks ? hash( 'sha256', '' ) : (string) $chunks[0];
		}
		return $entry;
	}

	/**
	 * Write the standalone manifest whole (temporary file and rename).
	 *
	 * @param string $path Path.
	 * @param string $json Manifest JSON.
	 * @return void
	 * @throws TransientFailure When it cannot be written.
	 */
	private function write_standalone( string $path, string $json ): void {
		$tmp     = $path . '.tmp';
		$written = @file_put_contents( $tmp, $json, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a warning would put the path into the error log; failure is thrown.
		if ( false === $written || strlen( $json ) !== $written || ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- see above.
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- best effort.
			throw new TransientFailure( 'The manifest could not be written.' );
		}
	}

	/**
	 * Only a clean structure pass is accepted.
	 *
	 * @param VerificationResult $result  Result.
	 * @param JobContext         $context Context (for the log).
	 * @return void
	 * @throws \RuntimeException Otherwise, with the verifier's cleaned report.
	 */
	private function judge( VerificationResult $result, JobContext $context ): void {
		$clean = $this->clean;
		if ( VerificationResult::PASSED_PARTIAL === $result->outcome() && array( VerificationResult::REASON_STRUCTURE ) === $result->partial_reasons() && 0 === $result->findings_total() ) {
			$context->logger()->info( 'Self-check passed', array( 'outcome' => $result->outcome() ) );
			return;
		}
		$report = $result->to_text( $clean );
		$context->logger()->error( 'Self-check failed', array( 'report' => $report ) );
		throw new \RuntimeException( self::SELF_CHECK_PREFIX . ' ' . str_replace( "\n", ' ', $report ) );
	}
}
