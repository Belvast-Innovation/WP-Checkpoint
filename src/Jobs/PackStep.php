<?php
/**
 * The step that writes the database chunks and the files into the volumes.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Archive\ArchiveVerifier;
use WPCheckpoint\Archive\ChunkHasher;
use WPCheckpoint\Archive\IndexLine;
use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Archive\Packer;
use WPCheckpoint\Archive\SealRequired;
use WPCheckpoint\Archive\SourceChanged;
use WPCheckpoint\Archive\SourceGone;
use WPCheckpoint\Files\Exclusions;
use WPCheckpoint\Files\ScanRoots;
use WPCheckpoint\Support\Paths;
use WPCheckpoint\Support\HostFunctions;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages carry archive paths and numbers; the runner stores them through the redactor and the presenter cleans them before display.

/**
 * Drives Archive\Packer over the two indexes in archive order: every line
 * of database.index.jsonl (the chunk files, verified against the hash in
 * the line as they are copied), then every line of files.index.jsonl (the
 * scan's plan). The plan is never modified; what was actually packed goes
 * to files.index.packed.jsonl, one line per finished file with its size,
 * mtime and hashes as packed, and that file is the files index of the
 * archive. A file that is gone or unreadable when its turn comes is left
 * out and counted (pack.summary.json); a file whose size changed is packed
 * as it is now.
 *
 * One unit of work is one content chunk (CHUNK_BYTES of source, PIECES
 * pieces of the packer) or one small deflated entry; the chunk's hash
 * context lives inside the unit. Between units the step checks the
 * budget with the measured cost of the last chunk (see run()), and the
 * cursor moves three committed lengths together: the offset into the plan
 * index, the packed index, the chunk hashes of the file in progress, plus
 * the packer's own state. On resume the packed index and the chunk hashes
 * are cut back to their committed lengths and the packer cuts its volume;
 * a file shorter than its committed length is damage and fails.
 *
 * A file that changes while it is being packed (size, mtime or inode
 * differ from the stat taken when its entry began, checked before every
 * chunk and reported by the packer on a short read) is started over from
 * a fresh stat, at most MAX_RESTARTS times; after that a file that shrank
 * is left out and one that only changed is finished as declared, both
 * with a warning. The abort moves the packer's state back to the entry
 * header without touching the volume; the unit ends there and the next
 * one cuts the volume when it opens the packer, so the committed length
 * never exceeds the file.
 */
final class PackStep implements Step {

	const ID           = 'pack';
	const PACKED_INDEX = 'files.index.packed.jsonl';
	const CHUNKS       = 'pack.chunks.jsonl';
	const SUMMARY      = 'pack.summary.json';
	const STATE        = 'packer.state.json';
	const VOLUMES      = 'volumes';
	const MAX_RESTARTS = 3;
	const MAX_LISTED   = 50;

	/**
	 * A unit must fit the time left with this much room, measured on the
	 * last chunk of the same tick.
	 */
	const TIME_MARGIN = 1.5;

	/**
	 * Scan roots (prefix => absolute path), or null to resolve them from
	 * plan.json and the storage path (the export job).
	 *
	 * @var array<int, array{group: string, path: string, prefix: string}>|null
	 */
	private $roots;

	/**
	 * Packer options (volume size and the like; tests use small values).
	 *
	 * @var array<string, mixed>
	 */
	private $packer_options;

	/**
	 * Content chunk size (the hashing chunk of the manifest).
	 *
	 * @var int
	 */
	private $chunk_bytes;

	/**
	 * Called after every content chunk with the archive path of the file in
	 * progress (tests change files between chunks of one tick with it).
	 *
	 * @var callable|null
	 */
	private $after_chunk;

	/**
	 * Cached export finishing time for database entries (per run).
	 *
	 * @var int|null
	 */
	private $database_mtime;

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>>|null $roots          Scan roots; null resolves them from the plan (WordPress).
	 * @param array<string, mixed>                  $packer_options Packer options.
	 * @param int                                   $chunk_bytes    Content chunk size.
	 * @param callable|null                         $after_chunk    Test seam, see $after_chunk.
	 * @throws \InvalidArgumentException When the chunk size is not whole pieces.
	 */
	public function __construct( $roots = null, array $packer_options = array(), int $chunk_bytes = Manifest::DEFAULT_CHUNK, $after_chunk = null ) {
		if ( $chunk_bytes <= 0 || ( $chunk_bytes > Packer::PIECE_BYTES && 0 !== $chunk_bytes % Packer::PIECE_BYTES ) ) {
			// A chunk is read as whole pieces (or as one piece when it is smaller than a piece): any other size
			// would hash piece boundaries while the manifest declares chunk boundaries.
			throw new \InvalidArgumentException( sprintf( 'The content chunk size must be at most %d bytes or a multiple of it.', Packer::PIECE_BYTES ) );
		}
		$this->roots          = $roots;
		$this->packer_options = self::packer_options_for( $packer_options, $chunk_bytes );
		$this->chunk_bytes    = $chunk_bytes;
		$this->after_chunk    = is_callable( $after_chunk ) ? $after_chunk : null;
	}

	/**
	 * The packer options with the deflate cap held to the content chunk:
	 * the packer deflates an entry in one piece (Packer::write_piece()),
	 * and one piece is one content chunk here, so an entry that is
	 * deflated must fit a chunk or its chunk list could not be built.
	 * Larger entries are stored and read piece by piece. ManifestStep
	 * opens the packer with the same options.
	 *
	 * @param array<string, mixed> $options     Packer options.
	 * @param int                  $chunk_bytes Content chunk size.
	 * @return array<string, mixed>
	 */
	public static function packer_options_for( array $options, int $chunk_bytes ): array {
		$cap                          = isset( $options['deflate_max_bytes'] ) ? (int) $options['deflate_max_bytes'] : (int) Packer::DEFLATE_MAX_BYTES;
		$options['deflate_max_bytes'] = min( $cap, $chunk_bytes );
		return $options;
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
	 * Pack until the budget is spent or everything is in the volumes.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws TransientFailure When a work file cannot be written (InsufficientSpace included).
	 * @throws \RuntimeException When the work directory is damaged or a chunk cannot fit a run.
	 */
	public function run( JobContext $context ): StepResult {
		$work    = $context->work_path();
		$plan    = ExportPlan::read( $work, ExportPlan::PLAN );
		$review  = ExportPlan::read( $work, ExportPlan::REVIEW );
		$active  = ExportPlan::effective( $plan, $review );
		$cursor  = $this->cursor( $context->cursor() );
		$volumes = $work . DIRECTORY_SEPARATOR . self::VOLUMES;
		if ( ! is_dir( $volumes ) && ! @mkdir( $volumes, 0700 ) && ! is_dir( $volumes ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- a warning would put the path into the error log.
			throw new TransientFailure( 'The volumes directory could not be created.' );
		}
		$base = isset( $plan['base'] ) ? (string) $plan['base'] : '';
		if ( array() === $cursor['packer'] ) {
			$this->check_space( $work, $volumes, $review );
		}
		$packer = Packer::open( $volumes, $base, $cursor['packer'], $this->packer_options_with( $context ) );
		self::cut( $work . DIRECTORY_SEPARATOR . self::PACKED_INDEX, $cursor['packed_bytes'] );
		self::cut( $work . DIRECTORY_SEPARATOR . self::CHUNKS, $cursor['chunks_bytes'] );
		$roots      = null === $this->roots ? ScanRoots::resolve( $active['groups'], $context->storage_path() )['roots'] : $this->roots;
		$exclusions = new Exclusions( $active['exclusions'], array() );
		$since      = 0;
		$last       = 0.0;
		$first      = true;
		$budget     = $context->budget()->seconds;

		try {
			while ( 'done' !== $cursor['phase'] ) {
				if ( ! $first && $context->remaining_seconds() < $last * self::TIME_MARGIN ) {
					// The next chunk would not fit what is left: end the tick here rather than mid-chunk.
					$context->checkpoint( $this->store( $cursor, $packer ), $this->percent( $cursor ), $this->message( $cursor ) );
					return StepResult::progress( $this->store( $cursor, $packer ), $this->percent( $cursor ), $this->message( $cursor ) );
				}
				$started = $context->elapsed();
				$bytes   = $this->unit( $context, $work, $active, $roots, $exclusions, $packer, $cursor );
				$cost    = $context->elapsed() - $started;
				$first   = false;
				$since  += $bytes;
				if ( $bytes > 0 ) {
					// Only a unit that moved bytes says what the next one will cost; a file boundary or a phase
					// switch costs nothing and must not reset the measure. The slowest unit of this tick rules.
					$last = max( $last, $cost );
					if ( $cost > $budget ) {
						throw new \RuntimeException( sprintf( 'Disk throughput is too low for a backup here: one %d MB chunk took %d seconds, more than the %d-second time budget of a single run.', (int) ( $this->chunk_bytes / 1048576 ), (int) ceil( $cost ), $budget ) );
					}
				}
				if ( 'done' === $cursor['phase'] ) {
					break;
				}
				if ( $cursor['restarted'] ) {
					// The packer state points back at the entry header; the volume is cut when the next unit opens it.
					$cursor['restarted'] = false;
					$context->checkpoint( $this->store( $cursor, $packer ), $this->percent( $cursor ), $this->message( $cursor ) );
					return StepResult::progress( $this->store( $cursor, $packer ), $this->percent( $cursor ), $this->message( $cursor ) );
				}
				if ( $context->should_checkpoint( $since ) ) {
					$context->checkpoint( $this->store( $cursor, $packer ), $this->percent( $cursor ), $this->message( $cursor ) );
					$since = 0;
				}
				if ( $context->should_stop() ) {
					return StepResult::progress( $this->store( $cursor, $packer ), $this->percent( $cursor ), $this->message( $cursor ) );
				}
			}
			// The manifest step continues from this packer state (the cursor is wiped between steps).
			ExportPlan::write( $work, self::STATE, $packer->state() );
		} finally {
			$packer->close();
		}
		$this->write_summary( $work, $cursor );
		$context->logger()->info(
			'Pack finished',
			array(
				'files'   => $cursor['files'],
				'skipped' => $cursor['skipped']['count'],
				'changed' => $cursor['changed']['count'],
			)
		);
		return StepResult::done( $this->message( $cursor ) );
	}

	/**
	 * Nothing to do: volumes in progress live in the work directory, which
	 * the engine removes; no tables or external resources.
	 *
	 * @param JobContext $context Context.
	 * @return void
	 */
	public function cleanup( JobContext $context ): void {
		unset( $context );
	}

	/**
	 * One unit: a database chunk file, one content chunk of a file, or one
	 * container block to hash.
	 *
	 * @param JobContext                       $context Context.
	 * @param string                           $work    Work directory.
	 * @param array<string, mixed>             $active  Effective plan.
	 * @param array<int, array<string, mixed>> $roots      Scan roots.
	 * @param Exclusions                       $exclusions Glob exclusions of the plan.
	 * @param Packer                           $packer     Packer.
	 * @param array<string, mixed>             $cursor     Cursor (updated).
	 * @return int Bytes handled.
	 * @throws \RuntimeException When a database chunk is missing or does not hash to its index line.
	 */
	private function unit( JobContext $context, string $work, array $active, array $roots, Exclusions $exclusions, Packer $packer, array &$cursor ): int {
		if ( 'database' === $cursor['phase'] ) {
			$line = self::line_at( $work . DIRECTORY_SEPARATOR . Manifest::DATABASE_INDEX, $cursor['offset'] );
			if ( null === $line ) {
				$cursor['phase']  = 'files';
				$cursor['offset'] = 0;
				return 0;
			}
			$data   = IndexLine::database( $line['text'], $this->chunk_bytes );
			$source = $work . DIRECTORY_SEPARATOR . DatabaseExportStep::DIR . DIRECTORY_SEPARATOR . basename( $data['p'] );
			clearstatcache( true, $source );
			if ( ! is_file( $source ) || (int) filesize( $source ) !== $data['b'] ) {
				throw new \RuntimeException( sprintf( 'Database chunk %s is missing or not %d bytes; the work directory was lost or changed.', $data['p'], $data['b'] ) );
			}
			if ( $this->make_room( $context, $packer, $cursor, $data['b'] ) ) {
				return 0;
			}
			$packer->add_entry( $source, $data['p'], $this->database_mtime( $work ) );
			$hash = hash_init( 'sha256' );
			while ( $packer->write_piece(
				null,
				static function ( string $bytes ) use ( $hash ): void {
					hash_update( $hash, $bytes );
				}
			) > 0 ) {
				continue;
			}
			if ( hash_final( $hash ) !== $data['h'] ) {
				throw new \RuntimeException( sprintf( 'Database chunk %s does not hash to what its index line records; the work directory was lost or changed.', $data['p'] ) );
			}
			$cursor['offset'] = $line['next'];
			++$cursor['entries'];
			return $data['b'];
		}

		if ( 'files' === $cursor['phase'] ) {
			if ( null === $cursor['file'] ) {
				return $this->begin_file( $context, $work, $active, $roots, $exclusions, $packer, $cursor );
			}
			return $this->chunk( $context, $work, $roots, $packer, $cursor );
		}

		// blocks: the container hashes of the volumes sealed so far.
		if ( $packer->has_unhashed_blocks() ) {
			$packer->hash_next_block();
			return isset( $this->packer_options['volume_chunk_bytes'] ) ? (int) $this->packer_options['volume_chunk_bytes'] : Manifest::DEFAULT_VOLUME_CHUNK;
		}
		$cursor['phase'] = 'done';
		return 0;
	}

	/**
	 * Take the next plan line, skip what is excluded, gone, unreadable or
	 * resolving outside its root, and open the entry for the rest. Returns
	 * without a unit's worth of work when a line was skipped; the caller loops.
	 *
	 * @param JobContext                       $context    Context (for the log).
	 * @param string                           $work       Work directory.
	 * @param array<string, mixed>             $active     Effective plan.
	 * @param array<int, array<string, mixed>> $roots      Scan roots.
	 * @param Exclusions                       $exclusions Glob exclusions of the plan.
	 * @param Packer                           $packer     Packer.
	 * @param array<string, mixed>             $cursor     Cursor (updated).
	 * @return int
	 */
	private function begin_file( JobContext $context, string $work, array $active, array $roots, Exclusions $exclusions, Packer $packer, array &$cursor ): int {
		$line = self::line_at( $work . DIRECTORY_SEPARATOR . Manifest::FILES_INDEX, $cursor['offset'] );
		if ( null === $line ) {
			$cursor['phase'] = 'blocks';
			return 0;
		}
		$data = IndexLine::files( $line['text'], $this->chunk_bytes );
		$p    = $data['p'];
		// A file sent back to its line by restart() (the volume had no room) keeps its restart count and its
		// "finish as it is" decision: MAX_RESTARTS bounds the file, not each attempt at it.
		$pending = isset( $cursor['pending'] ) && is_array( $cursor['pending'] ) && (int) $cursor['pending']['line'] === (int) $cursor['offset'] ? $cursor['pending'] : null;
		if ( ExportPlan::excluded_by_path( $p, $active['exclude_paths'] ) || $exclusions->excludes( $p ) ) {
			++$cursor['excluded'];
			$cursor['offset']  = $line['next'];
			$cursor['pending'] = null;
			return 0;
		}
		$root   = self::root_of( $roots, $p );
		$source = null === $root ? null : self::source_of( $roots, $p );
		$stat   = null === $source ? false : self::fresh_stat( $source );
		if ( false === $stat || ! is_readable( $source ) ) {
			self::note( $cursor, 'skipped', $p );
			$cursor['offset']  = $line['next'];
			$cursor['pending'] = null;
			return 0;
		}
		if ( ! Paths::is_inside( (string) $root['path'], $source ) ) {
			// The scan saw a directory; a link put in its place since would take the backup outside the
			// content directory (another site's files on a shared host). Resolved paths only.
			$context->logger()->warning( 'File left out: it resolves outside its content directory', array( 'p' => $p ) );
			self::note( $cursor, 'outside', $p );
			$cursor['offset']  = $line['next'];
			$cursor['pending'] = null;
			return 0;
		}
		if ( $this->make_room( $context, $packer, $cursor, (int) $stat['size'] ) ) {
			return 0; // The line is read again by the next unit.
		}
		try {
			$this->open_entry( $packer, $source, $p, $stat, $cursor, null === $pending ? 0 : (int) $pending['restarts'], (int) $cursor['offset'] );
			if ( null !== $pending && ! empty( $pending['final'] ) ) {
				$cursor['file']['final'] = true;
			}
		} catch ( SourceGone $e ) {
			// Gone between the stat above and the packer's own look: the same outcome as gone before it.
			self::note( $cursor, 'skipped', $p );
		} catch ( SealRequired $e ) {
			// Grown between the stat above and the packer's own (the platform bound): nothing was written and
			// the line is read again; make_room() seals first then. The counts carried so far stay.
			return 0;
		}
		$cursor['offset']  = $line['next'];
		$cursor['pending'] = null;
		return 0;
	}

	/**
	 * Make the packer ready for an entry of this size, one irreversible
	 * transition per unit, checkpointed on both sides: sealing the open
	 * volume when it has no room (before the seal the cursor knows every
	 * entry of the volume, so a crash after the rename is adopted by
	 * Packer::resume(); after it the seal is recorded), and creating the
	 * next volume when none is open. Neither happens as a side effect of
	 * adding the entry, and neither is measured as chunk time.
	 *
	 * @param JobContext           $context Context.
	 * @param Packer               $packer  Packer.
	 * @param array<string, mixed> $cursor  Cursor.
	 * @param int                  $size    Entry size.
	 * @return bool True when a transition was made (the unit is spent; the caller returns).
	 */
	private function make_room( JobContext $context, Packer $packer, array $cursor, int $size ): bool {
		if ( ! $packer->has_open_volume() ) {
			$packer->open_volume( $size );
			$context->checkpoint( $this->store( $cursor, $packer ), $this->percent( $cursor ), $this->message( $cursor ) );
			return true;
		}
		if ( $packer->has_room( $size ) ) {
			return false;
		}
		$context->checkpoint( $this->store( $cursor, $packer ), $this->percent( $cursor ), $this->message( $cursor ) );
		$packer->seal_volume();
		$context->checkpoint( $this->store( $cursor, $packer ), $this->percent( $cursor ), $this->message( $cursor ) );
		return true;
	}

	/**
	 * Add the entry and record its stat.
	 *
	 * @param Packer               $packer   Packer.
	 * @param string               $source   Absolute path.
	 * @param string               $p        Archive path.
	 * @param array<string, mixed> $stat     stat() result.
	 * @param array<string, mixed> $cursor      Cursor (updated).
	 * @param int                  $restarts    Restarts so far.
	 * @param int                  $line_offset Offset of the file's index line (to begin it again when the volume has no room).
	 * @return void
	 */
	private function open_entry( Packer $packer, string $source, string $p, array $stat, array &$cursor, int $restarts, int $line_offset ): void {
		$packer->add_entry( $source, ArchiveVerifier::FILES_PREFIX . $p, (int) $stat['mtime'] );
		$cursor['file']         = array(
			'p'        => $p,
			'line'     => $line_offset,
			'size'     => (int) $stat['size'],
			'mtime'    => (int) $stat['mtime'],
			'ino'      => (int) $stat['ino'],
			'chunk'    => 0,
			'restarts' => $restarts,
		);
		$cursor['chunks_bytes'] = 0;
	}

	/**
	 * One content chunk of the file in progress. Checks the file against
	 * the stat its entry began with first; a change starts the entry over
	 * (bounded), a short read reported by the packer does the same.
	 *
	 * @param JobContext                       $context Context.
	 * @param string                           $work    Work directory.
	 * @param array<int, array<string, mixed>> $roots   Scan roots.
	 * @param Packer                           $packer  Packer.
	 * @param array<string, mixed>             $cursor  Cursor (updated).
	 * @return int Bytes handled.
	 */
	private function chunk( JobContext $context, string $work, array $roots, Packer $packer, array &$cursor ): int {
		$file   = $cursor['file'];
		$source = self::source_of( $roots, (string) $file['p'] );
		if ( empty( $file['final'] ) ) {
			// Compared with the stat the entry began with; a file already past MAX_RESTARTS is finished as declared.
			$stat = null === $source ? false : self::fresh_stat( $source );
			$same = false !== $stat && (int) $stat['size'] === (int) $file['size'] && (int) $stat['mtime'] === (int) $file['mtime'] && (int) $stat['ino'] === (int) $file['ino'];
			if ( ! $same ) {
				return $this->restart( $context, $packer, $source, $stat, $cursor, 'changed before chunk ' . ( (int) $file['chunk'] + 1 ) );
			}
		}
		$hash  = hash_init( 'sha256' );
		$bytes = 0;
		try {
			$pieces = max( 1, intdiv( $this->chunk_bytes, (int) Packer::PIECE_BYTES ) );
			for ( $i = 0; $i < $pieces; $i++ ) {
				$piece  = $packer->write_piece(
					(int) min( Packer::PIECE_BYTES, $this->chunk_bytes - $bytes ),
					static function ( string $data ) use ( $hash ): void {
						hash_update( $hash, $data );
					}
				);
				$bytes += $piece;
				if ( 0 === $piece || ! $packer->has_open_entry() ) {
					break;
				}
			}
		} catch ( SourceChanged $e ) {
			return $this->restart( $context, $packer, $source, self::fresh_stat( (string) $source ), $cursor, 'shrank during chunk ' . ( (int) $file['chunk'] + 1 ) );
		} catch ( SourceGone $e ) {
			return $this->restart( $context, $packer, $source, false, $cursor, 'vanished during chunk ' . ( (int) $file['chunk'] + 1 ) );
		}
		if ( null !== $this->after_chunk ) {
			call_user_func( $this->after_chunk, (string) $file['p'], (int) $file['chunk'] );
		}
		if ( $bytes > 0 || 0 === (int) $file['size'] ) {
			$this->append(
				$work . DIRECTORY_SEPARATOR . self::CHUNKS,
				$cursor,
				'chunks_bytes',
				self::encode_line(
					array(
						'i' => (int) $file['chunk'],
						'h' => hash_final( $hash ),
					)
				)
			);
			++$cursor['file']['chunk'];
		}
		if ( ! $packer->has_open_entry() ) {
			$this->finish_file( $work, $cursor );
		}
		return $bytes;
	}

	/**
	 * Start the entry over from a fresh stat, or give the file up.
	 *
	 * @param JobContext                 $context Context.
	 * @param Packer                     $packer  Packer.
	 * @param string|null                $source  Absolute path.
	 * @param array<string, mixed>|false $stat Fresh stat, false when gone.
	 * @param array<string, mixed>       $cursor  Cursor (updated).
	 * @param string                     $how     What was seen (for the log).
	 * @return int
	 */
	private function restart( JobContext $context, Packer $packer, $source, $stat, array &$cursor, string $how ): int {
		$file = $cursor['file'];
		$p    = (string) $file['p'];
		$packer->abort_entry();
		$cursor['chunks_bytes'] = 0;
		$cursor['file']         = null;
		$cursor['restarted']    = true;
		if ( false === $stat || null === $source || ! is_readable( $source ) ) {
			$context->logger()->warning( 'File vanished while it was being packed', array( 'p' => $p ) );
			self::note( $cursor, 'skipped', $p );
			return 0;
		}
		$restarts = (int) $file['restarts'] + 1;
		if ( $restarts > self::MAX_RESTARTS ) {
			if ( ! empty( $file['final'] ) ) {
				// Already being finished as declared, and its size moved again: a stored entry cannot follow.
				$context->logger()->warning( 'File changed again while it was being packed as it was; left out', array( 'p' => $p ) );
				self::note( $cursor, 'unstable', $p );
				return 0;
			}
			if ( (int) $stat['size'] < (int) $file['size'] ) {
				// A stored entry declares its size up front; a file that keeps shrinking cannot be finished.
				$context->logger()->warning( 'File changed repeatedly and shrank; left out', array( 'p' => $p ) );
				self::note( $cursor, 'unstable', $p );
				return 0;
			}
			// It keeps changing without shrinking: finish it as declared now and say so (counted as "changed"
			// when its entry is complete, in finish_file(), so a file sent back to its line is counted once).
			$context->logger()->warning( 'File changed repeatedly; packed as it is now', array( 'p' => $p ) );
			return $this->reopen( $packer, $source, $p, $stat, $cursor, $restarts, (int) $file['line'], true );
		}
		$context->logger()->info(
			'File changed while it was being packed; starting it over',
			array(
				'p'       => $p,
				'seen'    => $how,
				'restart' => $restarts,
			)
		);
		return $this->reopen( $packer, $source, $p, $stat, $cursor, $restarts, (int) $file['line'], false );
	}

	/**
	 * Begin a restarted file's entry again, or, when the open volume can no
	 * longer take it (it grew past the platform bound, which only 32-bit
	 * PHP reaches), send it back to its index line with its restart count
	 * and its "finish as it is" decision in cursor['pending']: begin_file()
	 * seals the volume first (make_room()) and carries both over, so
	 * MAX_RESTARTS still bounds the file.
	 *
	 * @param Packer               $packer   Packer.
	 * @param string               $source   Absolute path.
	 * @param string               $p        Archive path.
	 * @param array<string, mixed> $stat     Fresh stat.
	 * @param array<string, mixed> $cursor   Cursor (updated).
	 * @param int                  $restarts Restarts so far, this one included.
	 * @param int                  $line     Offset of the file's index line.
	 * @param bool                 $as_is    Finish as declared, without further stat checks.
	 * @return int
	 */
	private function reopen( Packer $packer, string $source, string $p, array $stat, array &$cursor, int $restarts, int $line, bool $as_is ): int {
		if ( $packer->has_room( (int) $stat['size'] ) ) {
			try {
				$this->open_entry( $packer, $source, $p, $stat, $cursor, $restarts, $line );
				if ( $as_is ) {
					$cursor['file']['final'] = true;
				}
				return 0;
			} catch ( SealRequired $e ) {
				// Grown again between the two stats: back to its line, like no room at all.
				unset( $e );
			} catch ( SourceGone $e ) {
				self::note( $cursor, 'skipped', $p );
				return 0;
			}
		}
		$cursor['offset']  = $line;
		$cursor['pending'] = array(
			'line'     => $line,
			'restarts' => $restarts,
			'final'    => $as_is,
		);
		return 0;
	}

	/**
	 * The file's entry is complete: its line goes to the packed index.
	 *
	 * @param string               $work   Work directory.
	 * @param array<string, mixed> $cursor Cursor (updated).
	 * @return void
	 * @throws \RuntimeException When the line cannot be encoded or does not satisfy the index rule.
	 */
	private function finish_file( string $work, array &$cursor ): void {
		$file   = $cursor['file'];
		$hashes = self::read_chunks( $work . DIRECTORY_SEPARATOR . self::CHUNKS, $cursor['chunks_bytes'] );
		$line   = array(
			'p' => $file['p'],
			'b' => (int) $file['size'],
			'm' => (int) $file['mtime'],
		);
		if ( (int) $file['size'] > $this->chunk_bytes ) {
			$line['h']  = ChunkHasher::list_hash( $hashes );
			$line['hc'] = $hashes;
		} else {
			$line['h'] = array() === $hashes ? hash( 'sha256', '' ) : $hashes[0];
		}
		$json = json_encode( $line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure PHP class.
		if ( ! is_string( $json ) ) {
			throw new \RuntimeException( 'A packed index line could not be encoded.' );
		}
		IndexLine::files( $json, $this->chunk_bytes ); // The line must satisfy the reader's rule before it is written.
		$this->append( $work . DIRECTORY_SEPARATOR . self::PACKED_INDEX, $cursor, 'packed_bytes', $json . "\n" );
		$cursor['file']         = null;
		$cursor['chunks_bytes'] = 0;
		++$cursor['files'];
		$cursor['bytes'] += (int) $file['size'];
		if ( ! empty( $file['final'] ) ) {
			self::note( $cursor, 'changed', (string) $file['p'] );
		}
	}

	/**
	 * Append to a file with a committed length in the cursor: the length
	 * moves only after the write succeeded.
	 *
	 * @param string               $path   File.
	 * @param array<string, mixed> $cursor Cursor (updated).
	 * @param string               $key    Cursor key holding the committed length.
	 * @param string               $text   Text.
	 * @return void
	 * @throws TransientFailure When the write fails.
	 */
	private function append( string $path, array &$cursor, string $key, string $text ): void {
		$handle = @fopen( $path, 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- a warning would put the path into the error log.
		if ( false === $handle ) {
			throw new TransientFailure( 'A work file could not be opened.' );
		}
		try {
			if ( 0 !== fseek( $handle, (int) $cursor[ $key ] ) ) {
				throw new TransientFailure( 'A work file could not be positioned.' );
			}
			$written = fwrite( $handle, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- stream write of the job's own file.
			if ( false === $written || strlen( $text ) !== $written || ! fflush( $handle ) ) {
				throw new TransientFailure( 'A work file could not be written.' );
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
		$cursor[ $key ] += strlen( $text );
	}

	/**
	 * Cut a work file back to its committed length; shorter is damage.
	 *
	 * @param string $path   File.
	 * @param int    $length Committed length.
	 * @return void
	 * @throws TransientFailure When the file cannot be opened or cut.
	 * @throws \RuntimeException When the file is shorter than recorded.
	 */
	private static function cut( string $path, int $length ): void {
		$handle = @fopen( $path, 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- see above.
		if ( false === $handle ) {
			throw new TransientFailure( 'A work file could not be opened.' );
		}
		try {
			$stat = fstat( $handle );
			$size = is_array( $stat ) ? (int) $stat['size'] : 0;
			if ( $size < $length ) {
				throw new \RuntimeException( sprintf( 'The work file %s is shorter than its recorded committed length; the work directory was changed or damaged.', basename( $path ) ) );
			}
			if ( $size > $length && ! ftruncate( $handle, $length ) ) {
				throw new TransientFailure( 'A work file could not be cut back.' );
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
	}

	/**
	 * The chunk hashes of the file in progress, in order.
	 *
	 * @param string $path   pack.chunks.jsonl.
	 * @param int    $length Committed length.
	 * @return string[]
	 * @throws \RuntimeException When the file is missing or malformed.
	 */
	private static function read_chunks( string $path, int $length ): array {
		if ( 0 === $length ) {
			return array();
		}
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- see above.
		if ( false === $handle ) {
			throw new \RuntimeException( 'The chunk hashes of the file in progress are missing; the work directory was lost or changed.' );
		}
		$hashes = array();
		try {
			$read = 0;
			$line = fgets( $handle );
			while ( false !== $line && $read < $length ) {
				$read += strlen( $line );
				$data  = json_decode( rtrim( $line, "\n" ), true );
				if ( ! is_array( $data ) || ! isset( $data['i'], $data['h'] ) || count( $hashes ) !== (int) $data['i'] || ! is_string( $data['h'] ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $data['h'] ) ) {
					throw new \RuntimeException( 'The chunk hashes of the file in progress are malformed; the work directory was changed or damaged.' );
				}
				$hashes[] = $data['h'];
				$line     = fgets( $handle );
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
		return $hashes;
	}

	/**
	 * The line starting at a byte offset of an index, and the offset after it.
	 *
	 * @param string $path   Index file.
	 * @param int    $offset Offset.
	 * @return array{text: string, next: int}|null Null at the end.
	 * @throws \RuntimeException When the index cannot be read.
	 */
	private static function line_at( string $path, int $offset ) {
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- see above.
		if ( false === $handle ) {
			throw new \RuntimeException( sprintf( 'The index %s is missing; the work directory was lost or changed.', basename( $path ) ) );
		}
		try {
			if ( 0 !== fseek( $handle, $offset ) ) {
				throw new \RuntimeException( 'An index could not be positioned; the work directory was changed.' );
			}
			$line = fgets( $handle, IndexLine::MAX_LINE_BYTES + 2 );
			while ( is_string( $line ) && '' === trim( $line ) ) {
				$offset += strlen( $line );
				$line    = fgets( $handle, IndexLine::MAX_LINE_BYTES + 2 );
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
		if ( ! is_string( $line ) ) {
			return null;
		}
		return array(
			'text' => rtrim( $line, "\r\n" ),
			'next' => $offset + strlen( $line ),
		);
	}

	/**
	 * Before the first volume: the whole archive must fit, now with the
	 * database's exported size instead of the review's estimate (the chunks
	 * are on disk already; their copies in the volumes are not). Unknown free
	 * space does not block; the packer still checks before every volume.
	 *
	 * @param string               $work    Work directory.
	 * @param string               $volumes Volumes directory.
	 * @param array<string, mixed> $review  review.json.
	 * @return void
	 * @throws \RuntimeException When the archive would not fit.
	 */
	private function check_space( string $work, string $volumes, array $review ): void {
		$reader = isset( $this->packer_options['disk_free'] ) && is_callable( $this->packer_options['disk_free'] ) ? $this->packer_options['disk_free'] : array( HostFunctions::class, 'disk_free_space' );
		$free   = call_user_func( $reader, $volumes );
		if ( ! is_int( $free ) && ! is_float( $free ) ) {
			return;
		}
		$scan     = ExportPlan::exists( $work, FileScanStep::SUMMARY ) ? ExportPlan::read( $work, FileScanStep::SUMMARY ) : array();
		$summary  = ExportPlan::exists( $work, DatabaseExportStep::SUMMARY ) ? ExportPlan::read( $work, DatabaseExportStep::SUMMARY ) : array();
		$database = 0.0;
		foreach ( isset( $summary['tables'] ) && is_array( $summary['tables'] ) ? $summary['tables'] : array() as $table ) {
			$database += (float) ( is_array( $table ) ? ( $table['bytes'] ?? 0 ) : 0 );
		}
		$files  = ExportPlan::planned_files( $scan, $review );
		$needed = ExportPlan::required_bytes( $files['bytes'], $files['count'], $database, true );
		if ( (float) $free < $needed ) {
			throw new \RuntimeException( sprintf( 'Not enough free disk space to pack this backup: %1$d MB free in the storage directory, about %2$d MB needed for the archive (%3$d MB of files, %4$d MB of exported database). Free up space, or leave large directories or tables out. Failed backup jobs keep their work files for %5$d days so that they can be retried; they take space too until then.', (int) ( $free / 1048576 ), (int) ceil( $needed / 1048576 ), (int) ( $files['bytes'] / 1048576 ), (int) ceil( $database / 1048576 ), (int) ( JobRepository::WORK_RETENTION_SECONDS / 86400 ) ) );
		}
	}

	/**
	 * The modification time recorded for database chunk entries: the
	 * export's finishing time from database.summary.json, the same on every
	 * replay (a chunk file rewritten by a retried export would carry a new
	 * mtime and change the archive's bytes).
	 *
	 * @param string $work Work directory.
	 * @return int
	 */
	private function database_mtime( string $work ): int {
		if ( null === $this->database_mtime ) {
			$summary              = ExportPlan::exists( $work, DatabaseExportStep::SUMMARY ) ? ExportPlan::read( $work, DatabaseExportStep::SUMMARY ) : array();
			$stamp                = isset( $summary['exported']['finished_at'] ) ? strtotime( (string) $summary['exported']['finished_at'] ) : false;
			$this->database_mtime = false === $stamp ? 0 : $stamp;
		}
		return $this->database_mtime;
	}

	/**
	 * The root an archive path belongs to (longest prefix wins).
	 *
	 * @param array<int, array<string, mixed>> $roots Roots.
	 * @param string                           $p     Archive path.
	 * @return array<string, mixed>|null
	 */
	private static function root_of( array $roots, string $p ) {
		$best = null;
		$len  = -1;
		foreach ( $roots as $root ) {
			$prefix = (string) $root['prefix'];
			if ( ( $p === $prefix || 0 === strpos( $p, $prefix . '/' ) ) && strlen( $prefix ) > $len ) {
				$best = $root;
				$len  = strlen( $prefix );
			}
		}
		return $best;
	}

	/**
	 * The absolute path of an archive path under the roots (longest prefix wins).
	 *
	 * @param array<int, array<string, mixed>> $roots Roots.
	 * @param string                           $p     Archive path.
	 * @return string|null
	 */
	private static function source_of( array $roots, string $p ) {
		$best = null;
		$len  = -1;
		foreach ( $roots as $root ) {
			$prefix = (string) $root['prefix'];
			if ( ( $p === $prefix || 0 === strpos( $p, $prefix . '/' ) ) && strlen( $prefix ) > $len ) {
				$best = rtrim( (string) $root['path'], '/\\' ) . ( $p === $prefix ? '' : '/' . substr( $p, strlen( $prefix ) + 1 ) );
				$len  = strlen( $prefix );
			}
		}
		return $best;
	}

	/**
	 * A stat that bypasses PHP's cache (a change within the same request must be seen).
	 *
	 * @param string $path Path.
	 * @return array<string, mixed>|false
	 */
	private static function fresh_stat( string $path ) {
		clearstatcache( true, $path );
		if ( is_link( $path ) || ! is_file( $path ) ) {
			return false;
		}
		$stat = @stat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a vanished file is reported by the caller.
		return is_array( $stat ) ? $stat : false;
	}

	/**
	 * One warning per kind of left-out or doubtful file, however many
	 * there are: the count, the first MAX_LISTED paths, and how many more.
	 * The manifest caps its warnings (Manifest::MAX_WARNINGS); a busy site
	 * must not fail at the very end for having too many.
	 *
	 * @param array<string, mixed> $cursor Final cursor.
	 * @return string[]
	 */
	private static function warnings( array $cursor ): array {
		$texts = array(
			'skipped'  => '%d files listed by the scan were missing or unreadable when they were packed and are not in the backup: %s',
			'unstable' => '%d files changed repeatedly while they were being packed and are not in the backup: %s',
			'changed'  => '%d files were modified while they were being packed; their content in the backup may be inconsistent: %s',
			'outside'  => '%d files resolve outside their content directory (through a link) and are not in the backup: %s',
		);
		$out   = array();
		foreach ( $texts as $kind => $text ) {
			$count = (int) $cursor[ $kind ]['count'];
			if ( $count <= 0 ) {
				continue;
			}
			$listed = (array) $cursor[ $kind ]['listed'];
			$more   = $count - count( $listed );
			$out[]  = sprintf( $text, $count, implode( ', ', $listed ) . ( $more > 0 ? sprintf( ' and %d more', $more ) : '' ) );
		}
		return $out;
	}

	/**
	 * A JSONL line that will be read back: encoding failure is thrown, never a partial line.
	 *
	 * @param array<string, mixed> $data Line data.
	 * @return string With its newline.
	 * @throws \RuntimeException When it cannot be encoded.
	 */
	private static function encode_line( array $data ): string {
		$json = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure PHP class.
		if ( ! is_string( $json ) ) {
			throw new \RuntimeException( 'A work file line could not be encoded.' );
		}
		return $json . "\n";
	}

	/**
	 * Count a file under a kind and keep the first MAX_LISTED paths.
	 *
	 * @param array<string, mixed> $cursor Cursor (updated).
	 * @param string               $kind   'skipped' or 'changed'.
	 * @param string               $p      Archive path.
	 * @return void
	 */
	private static function note( array &$cursor, string $kind, string $p ): void {
		++$cursor[ $kind ]['count'];
		if ( count( $cursor[ $kind ]['listed'] ) < self::MAX_LISTED ) {
			$cursor[ $kind ]['listed'][] = $p;
		}
	}

	/**
	 * The cursor with defaults.
	 *
	 * @param array<string, mixed> $cursor Stored cursor.
	 * @return array<string, mixed>
	 */
	private function cursor( array $cursor ): array {
		return array_merge(
			array(
				'phase'        => 'database',
				'offset'       => 0,
				'packed_bytes' => 0,
				'chunks_bytes' => 0,
				'file'         => null,
				'packer'       => array(),
				'entries'      => 0,
				'files'        => 0,
				'bytes'        => 0,
				'excluded'     => 0,
				'skipped'      => array(
					'count'  => 0,
					'listed' => array(),
				),
				'changed'      => array(
					'count'  => 0,
					'listed' => array(),
				),
				'unstable'     => array(
					'count'  => 0,
					'listed' => array(),
				),
				'outside'      => array(
					'count'  => 0,
					'listed' => array(),
				),
				'restarted'    => false,
				'pending'      => null,
			),
			$cursor
		);
	}

	/**
	 * The cursor to persist: the packer's state included.
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @param Packer               $packer Packer.
	 * @return array<string, mixed>
	 */
	private function store( array $cursor, Packer $packer ): array {
		$cursor['packer'] = $packer->state();
		return $cursor;
	}

	/**
	 * Coarse progress: phase, then files done.
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @return int
	 */
	private function percent( array $cursor ): int {
		switch ( $cursor['phase'] ) {
			case 'database':
				return 5;
			case 'files':
				return 10;
			case 'blocks':
				return 95;
			default:
				return 100;
		}
	}

	/**
	 * Progress text: counts only.
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @return string
	 */
	private function message( array $cursor ): string {
		return sprintf(
			/* translators: 1: database chunks packed, 2: files packed */
			__( 'Packed %1$d database chunks and %2$d files', 'wp-checkpoint' ),
			(int) $cursor['entries'],
			(int) $cursor['files']
		);
	}

	/**
	 * Write pack.summary.json.
	 *
	 * @param string               $work   Work directory.
	 * @param array<string, mixed> $cursor Final cursor.
	 * @return void
	 */
	private function write_summary( string $work, array $cursor ): void {
		ExportPlan::write(
			$work,
			self::SUMMARY,
			array(
				'entries'  => (int) $cursor['entries'],
				'files'    => (int) $cursor['files'],
				'bytes'    => (int) $cursor['bytes'],
				'excluded' => (int) $cursor['excluded'],
				'skipped'  => $cursor['skipped'],
				'changed'  => $cursor['changed'],
				'unstable' => $cursor['unstable'],
				'outside'  => $cursor['outside'],
				'warnings' => self::warnings( $cursor ),
			)
		);
	}
}
