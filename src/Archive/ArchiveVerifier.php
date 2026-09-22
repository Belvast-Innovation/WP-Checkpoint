<?php
/**
 * Verifies an archive against its manifest, one bounded unit of work at a time.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Archive;

/**
 * Phases, in order: manifest (parse and validate), volumes (presence and
 * size), indexes (extract the sidecar indexes from the last volume and
 * check their hashes), index_lines (every line of both indexes against
 * the manifest summaries), containers (each volume's block hashes) and
 * contents (each entry against its index line, in lockstep). Depth
 * "structure" stops after index_lines; "full" runs everything.
 *
 * Every step() call does one bounded unit (a stat, one 256 MiB volume
 * block, one batch of index lines, one 16 MiB content chunk or one batch
 * of small entries) and leaves state() serialisable: the cursor holds
 * phase and positions, the counts and the first MAX_STORED_FINDINGS
 * findings, never the manifest itself. Any damage stops the phase that
 * needs the damaged part (a missing or corrupt sidecar index ends the run;
 * a missing volume other than the last one only ends that volume's
 * checks). A volume whose size differs from what the volumes phase
 * recorded ends the run as "changed" rather than damaged (see
 * changed()). The manifest is trusted for nothing beyond what it
 * declares: every entry is still bounded by the reader's own limits, and
 * the unit sizes are the verifier's own (MAX_CONTENT_CHUNK,
 * MAX_CONTAINER_CHUNK, EXTRACT_PIECE, LINES_PER_UNIT), never the
 * manifest's. The cursor, on the other hand, is trusted: it lives where
 * only the job engine writes, and a forged one (an entry_block pointing
 * at the last block) would skip checks rather than escape any bound;
 * positions that do not fit the volume make the reader fail closed.
 * Server-side failures (work directory gone, disk full, no permission)
 * surface as EnvironmentFailure and end the run as "unreadable", never as
 * damage.
 */
final class ArchiveVerifier {

	const DEPTH_STRUCTURE = 'structure';
	const DEPTH_FULL      = 'full';

	const PHASE_MANIFEST    = 'manifest';
	const PHASE_VOLUMES     = 'volumes';
	const PHASE_INDEXES     = 'indexes';
	const PHASE_INDEX_LINES = 'index_lines';
	const PHASE_CONTAINERS  = 'containers';
	const PHASE_CONTENTS    = 'contents';
	const PHASE_DONE        = 'done';

	const LINES_PER_UNIT   = 5000;
	const ENTRIES_PER_UNIT = 1000;
	// The verifier's own bounds on one unit. A manifest may declare larger hash chunks, but one SHA-256 over a
	// chunk cannot be split across ticks, so such archives are reported as unsupported instead.
	const MAX_CONTENT_CHUNK   = 16777216;
	const MAX_CONTAINER_CHUNK = 268435456;
	const EXTRACT_PIECE       = 16777216;
	const MAX_STORED_FINDINGS = 20;
	const FILES_PREFIX        = 'files/';
	const MANIFEST_ENTRY      = 'manifest.json';
	const VOLUME_SUFFIX       = '.wpcheckpoint.zip';

	const ORDER_MESSAGE = 'The order of entries in the archive does not match the index. This usually means the archive was repacked by another tool, not that data is damaged.';

	const FOREIGN_MESSAGE = 'This archive was not written by WP Checkpoint, or it was repacked by another tool: its entries use data descriptors, which this plugin never writes.';

	/**
	 * A walk whose estimate, extrapolated from the first ESTIMATE_AFTER
	 * entries, exceeds this many seconds is reported as slow (see progress()).
	 */
	const SLOW_SECONDS   = 60;
	const ESTIMATE_AFTER = 1000;

	/**
	 * Manifest file or last volume.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * Directory holding the volumes.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Directory for the extracted indexes.
	 *
	 * @var string
	 */
	private $work_dir;

	/**
	 * Cursor.
	 *
	 * @var array<string, mixed>
	 */
	private $state;

	/**
	 * Parsed manifest (per instance; the state never holds it).
	 *
	 * @var Manifest|null
	 */
	private $manifest = null;

	/**
	 * Volumes in verification order (per instance).
	 *
	 * @var array<int, array{ordinal: int, path: string, bytes: int, chunks?: string[], sha256: string, listed: bool}>|null
	 */
	private $volumes = null;

	/**
	 * Open index handles for the current unit.
	 *
	 * @var array<string, resource>
	 */
	private $handles = array();

	/**
	 * Clock for the walk's time estimate (microtime( true ); tests inject one).
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Entries walked and seconds spent by this instance, for the estimate.
	 * Measurements, not position: they stay in memory and never enter
	 * state(), which callers store (a stored cursor holds identifiers,
	 * offsets, counts and hashes only). A caller that opens the verifier
	 * again each run starts measuring again.
	 *
	 * @var array{entries: int, seconds: float}
	 */
	private $walk = array(
		'entries' => 0,
		'seconds' => 0.0,
	);

	/**
	 * Constructor: see open().
	 *
	 * @param string               $path     Path.
	 * @param string               $work_dir Work directory.
	 * @param array<string, mixed> $state    State.
	 */
	private function __construct( string $path, string $work_dir, array $state ) {
		$this->path     = $path;
		$this->dir      = dirname( $path );
		$this->work_dir = $work_dir;
		$this->state    = $state;
		$this->clock    = static function (): float {
			return microtime( true );
		};
	}

	/**
	 * Replace the clock the walk's estimate is measured with (tests).
	 *
	 * @param callable $clock function(): float, seconds.
	 * @return void
	 */
	public function set_clock( callable $clock ): void {
		$this->clock = $clock;
	}

	/**
	 * Where the run is, for a progress display: the phase, and in the entry
	 * walk (every depth walks every volume's entries; structure depth
	 * without hashing their data) entries done out of all entries the
	 * index declares. Once this instance has walked ESTIMATE_AFTER entries
	 * (one unit), the time they took is extrapolated to the rest
	 * ("seconds_left"), and "slow" says the whole walk is estimated above
	 * SLOW_SECONDS: on a cold disk with large files every local header is a
	 * seek, and a display that only says "checking" would look stuck.
	 * Before that, and in a new instance resumed from a stored state, there
	 * is no estimate (null): the measurement is not part of the state.
	 *
	 * @return array{phase: string, done: int, total: int, seconds_left: int|null, slow: bool}
	 */
	public function progress(): array {
		$counts  = (array) ( $this->state['counts'] ?? array() );
		$total   = (int) ( $counts['chunks_declared'] ?? 0 ) + (int) ( $counts['files_declared'] ?? 0 );
		$done    = (int) ( $counts['headers_checked'] ?? 0 );
		$entries = $this->walk['entries'];
		$seconds = $this->walk['seconds'];
		$left    = null;
		$slow    = false;
		if ( $entries >= self::ESTIMATE_AFTER && $total > 0 ) {
			$per  = $seconds / $entries;
			$left = (int) ceil( max( 0, $total - $done ) * $per );
			$slow = $per * $total > self::SLOW_SECONDS;
		}
		return array(
			'phase'        => (string) $this->state['phase'],
			'done'         => $done,
			'total'        => $total,
			'seconds_left' => $left,
			'slow'         => $slow,
		);
	}

	/**
	 * Start or resume a verification.
	 *
	 * @param string               $path     The standalone manifest, or the last volume for its embedded copy.
	 * @param string               $work_dir Existing directory where the sidecar indexes are extracted; must outlive the run.
	 * @param string               $depth    DEPTH_STRUCTURE or DEPTH_FULL (ignored when resuming).
	 * @param array<string, mixed> $state    A previous state() to resume from.
	 * @return ArchiveVerifier
	 * @throws \InvalidArgumentException When the depth or work directory is unusable.
	 */
	public static function open( string $path, string $work_dir, string $depth = self::DEPTH_STRUCTURE, array $state = array() ): ArchiveVerifier {
		if ( '' === $work_dir || ! is_dir( $work_dir ) ) {
			throw new \InvalidArgumentException( 'The work directory does not exist.' );
		}
		if ( array() === $state ) {
			if ( self::DEPTH_STRUCTURE !== $depth && self::DEPTH_FULL !== $depth ) {
				throw new \InvalidArgumentException( 'Unknown depth.' );
			}
			$state = array(
				'depth'          => $depth,
				'phase'          => self::PHASE_MANIFEST,
				'embedded'       => false,
				'invalid'        => false,
				'stopped_at'     => null,
				'volume'         => 0,
				'block'          => 0,
				'sub'            => '',
				'index'          => 'database',
				'offset'         => 0,
				'line'           => 0,
				'table'          => null,
				'sum'            => 0,
				'entry'          => array(
					'index'     => 0,
					'cd_offset' => -1,
				),
				'entry_block'    => 0,
				'gap'            => false,
				'sizes'          => array(),
				'changed'        => false,
				'unreadable'     => false,
				'crc'            => 0,
				'counts'         => array(),
				'kinds'          => array(),
				'findings'       => array(),
				'findings_total' => 0,
			);
		} elseif ( ! isset( $state['phase'], $state['depth'] ) ) {
			throw new \InvalidArgumentException( 'The state is not a verifier state.' );
		}
		return new self( $path, $work_dir, $state );
	}

	/**
	 * Cursor: phase, positions, counts and the first findings. Safe to store.
	 *
	 * @return array<string, mixed>
	 */
	public function state(): array {
		return $this->state;
	}

	/**
	 * Whether every phase has run or the run was stopped.
	 *
	 * @return bool
	 */
	public function finished(): bool {
		return self::PHASE_DONE === $this->state['phase'];
	}

	/**
	 * The result. Only meaningful once finished().
	 *
	 * @return VerificationResult
	 * @throws \LogicException When not finished.
	 */
	public function result(): VerificationResult {
		return VerificationResult::from_state( $this->state );
	}

	/**
	 * Run to the end in one call.
	 *
	 * @return VerificationResult
	 * @throws \RuntimeException When the verifier itself cannot proceed (work directory lost, manifest changed meanwhile).
	 */
	public function run(): VerificationResult {
		while ( $this->step() ) {
			continue;
		}
		return $this->result();
	}

	/**
	 * One bounded unit of work.
	 *
	 * @return bool True while there is more to do.
	 * @throws \RuntimeException When the verifier itself cannot proceed (work directory lost, manifest changed meanwhile).
	 */
	public function step(): bool {
		$walking = self::PHASE_CONTENTS === $this->state['phase'];
		$before  = (int) ( $this->state['counts']['headers_checked'] ?? 0 );
		$started = $walking ? (float) call_user_func( $this->clock ) : 0.0;
		try {
			$this->dispatch();
			if ( $walking ) {
				$this->walk['entries'] += (int) ( $this->state['counts']['headers_checked'] ?? 0 ) - $before;
				$this->walk['seconds'] += max( 0.0, (float) call_user_func( $this->clock ) - $started );
			}
		} catch ( EnvironmentFailure $e ) {
			$this->add( new Finding( (string) $this->state['phase'], Finding::ENVIRONMENT, 'This server could not read or write what the check needs: ' . $e->getMessage() ) );
			$this->state['unreadable'] = true;
			$this->stop( (string) $this->state['phase'] );
		} finally {
			foreach ( $this->handles as $handle ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream read of an extracted index.
			}
			$this->handles = array();
		}
		return ! $this->finished();
	}

	/**
	 * Run the current phase's unit.
	 *
	 * @return void
	 */
	private function dispatch(): void {
		switch ( $this->state['phase'] ) {
			case self::PHASE_MANIFEST:
				$this->step_manifest();
				break;
			case self::PHASE_VOLUMES:
				$this->step_volumes();
				break;
			case self::PHASE_INDEXES:
				$this->step_indexes();
				break;
			case self::PHASE_INDEX_LINES:
				$this->step_index_lines();
				break;
			case self::PHASE_CONTAINERS:
				$this->step_containers();
				break;
			case self::PHASE_CONTENTS:
				$this->step_contents();
				break;
			default:
				return;
		}
	}

	/*
	 * Phase 1: manifest.
	 */

	/**
	 * Parse the manifest; a manifest that cannot be read ends the run as invalid.
	 *
	 * @return void
	 */
	private function step_manifest(): void {
		try {
			$manifest = $this->manifest();
		} catch ( ManifestError $e ) {
			$this->add( new Finding( self::PHASE_MANIFEST, Finding::MALFORMED, $e->getMessage(), array( 'field' => $e->field() ) ) );
			$this->state['invalid'] = true;
			$this->stop( self::PHASE_MANIFEST );
			return;
		} catch ( \RuntimeException $e ) {
			$this->add( new Finding( self::PHASE_MANIFEST, Finding::MALFORMED, $e->getMessage() ) );
			$this->state['invalid'] = true;
			$this->stop( self::PHASE_MANIFEST );
			return;
		}
		$chunks = 0;
		foreach ( $manifest->tables() as $table ) {
			$chunks += $table['chunks'];
		}
		if ( $manifest->chunk_bytes() > self::MAX_CONTENT_CHUNK || $manifest->volume_chunk_bytes() > self::MAX_CONTAINER_CHUNK ) {
			// One SHA-256 over a chunk cannot be split across ticks (the hash state is not serialisable on the
			// PHP floor and the cursor holds no hash state by design), so a chunk the unit bound cannot cover is
			// not checkable here. Not damage: another reader with a bigger budget could verify it.
			$this->add( new Finding( self::PHASE_MANIFEST, Finding::UNSUPPORTED, sprintf( 'The manifest declares hash chunks larger than this verifier checks in one step (content %d bytes, container %d bytes; at most %d and %d are supported).', $manifest->chunk_bytes(), $manifest->volume_chunk_bytes(), self::MAX_CONTENT_CHUNK, self::MAX_CONTAINER_CHUNK ) ) );
			$this->stop( self::PHASE_MANIFEST );
			return;
		}
		$this->state['embedded'] = $manifest->embedded();
		$this->state['counts']   = array(
			'volumes_declared' => count( $this->volumes() ),
			'volumes_present'  => 0,
			'volumes_verified' => 0,
			'blocks_verified'  => 0,
			'tables_declared'  => count( $manifest->tables() ),
			'tables_indexed'   => 0,
			'chunks_declared'  => $chunks,
			'chunks_verified'  => 0,
			'files_declared'   => $manifest->files_summary()['count'],
			'files_indexed'    => 0,
			'files_verified'   => 0,
			'files_size_only'  => 0,
			'entries_missing'  => 0,
			'headers_checked'  => 0,
		);
		$this->state['phase']    = self::PHASE_VOLUMES;
		$this->state['volume']   = 0;
	}

	/**
	 * The manifest, parsed once per instance. A volume path yields its
	 * embedded copy (which must say so); a file must be the standalone one.
	 *
	 * @return Manifest
	 * @throws ManifestError When the document is not acceptable.
	 * @throws \RuntimeException When the file or entry cannot be read.
	 */
	private function manifest(): Manifest {
		if ( null !== $this->manifest ) {
			return $this->manifest;
		}
		if ( $this->from_volume() ) {
			$reader = ZipReader::open( $this->path );
			$entry  = $reader->find( self::MANIFEST_ENTRY );
			if ( null === $entry || null !== $entry['problem'] ) {
				throw new \RuntimeException( 'The volume has no manifest.json entry.' );
			}
			$manifest = Manifest::from_json( $reader->read( $entry ) );
			if ( ! $manifest->embedded() ) {
				throw new \RuntimeException( 'The manifest inside a volume must be marked as embedded.' );
			}
		} else {
			clearstatcache();
			if ( ! is_file( $this->path ) ) {
				throw new \RuntimeException( 'The manifest file does not exist.' );
			}
			$size = filesize( $this->path );
			if ( false === $size || $size > Manifest::MAX_JSON_BYTES ) {
				throw new \RuntimeException( 'The manifest is larger than allowed.' );
			}
			$json = file_get_contents( $this->path, false, null, 0, Manifest::MAX_JSON_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded to 4 MB by the size check above.
			if ( ! is_string( $json ) ) {
				throw new \RuntimeException( 'The manifest file could not be read.' );
			}
			$manifest = Manifest::from_json( $json );
			if ( $manifest->embedded() ) {
				throw new \RuntimeException( 'An embedded manifest copy must be verified from the volume that holds it.' );
			}
		}
		$this->manifest = $manifest;
		return $manifest;
	}

	/**
	 * Whether the path names a volume (embedded copy) rather than a manifest file.
	 *
	 * @return bool
	 */
	private function from_volume(): bool {
		return substr( $this->path, -strlen( self::VOLUME_SUFFIX ) ) === self::VOLUME_SUFFIX;
	}

	/**
	 * Volumes in order, the last one holding the indexes. With an embedded
	 * copy the given volume is appended as unlisted: present, walked for
	 * its contents, but without a container hash to check.
	 *
	 * @return array<int, array{ordinal: int, path: string, bytes: int, chunks?: string[], sha256: string, listed: bool}>
	 */
	private function volumes(): array {
		if ( null !== $this->volumes ) {
			return $this->volumes;
		}
		$out = array();
		foreach ( $this->manifest()->volumes() as $i => $volume ) {
			$volume['ordinal'] = $i + 1;
			$volume['listed']  = true;
			$out[]             = $volume;
		}
		if ( $this->from_volume() ) {
			$out[] = array(
				'ordinal' => count( $out ) + 1,
				'path'    => basename( $this->path ),
				'bytes'   => -1,
				'sha256'  => '',
				'listed'  => false,
			);
		}
		$this->volumes = $out;
		return $out;
	}

	/**
	 * Absolute path of a volume.
	 *
	 * @param array<string, mixed> $volume Volume.
	 * @return string
	 */
	private function volume_path( array $volume ): string {
		return $this->dir . DIRECTORY_SEPARATOR . $volume['path'];
	}

	/**
	 * A volume's size on disk right now, or null when it is not there.
	 *
	 * @param array<string, mixed> $volume Volume.
	 * @return int|null
	 */
	private function size_now( array $volume ): ?int {
		clearstatcache();
		$path = $this->volume_path( $volume );
		if ( ! is_file( $path ) ) {
			return null;
		}
		$size = filesize( $path );
		return false === $size ? null : $size;
	}

	/**
	 * The size the volumes phase recorded (null: missing then).
	 *
	 * @param int $i Volume position.
	 * @return int|null
	 */
	private function size_then( int $i ): ?int {
		return isset( $this->state['sizes'][ $i ] ) ? (int) $this->state['sizes'][ $i ] : null;
	}

	/**
	 * A full verification of a large archive runs for hours; a volume that a
	 * transfer or download is still writing would otherwise be read half
	 * new and reported as damaged, and a user who reads "damaged" deletes a
	 * backup that was fine. One stat per unit compares the volume with what
	 * the volumes phase saw: any difference ends the run as "changed", a
	 * separate outcome that asks for a re-run once writing has finished.
	 * A volume swapped for one of the same size is not caught here; the
	 * reader then fails closed on the stale central-directory position.
	 *
	 * @param int    $i     Volume position.
	 * @param string $phase Phase to stop in.
	 * @return bool True when the run was stopped.
	 */
	private function changed( int $i, string $phase ): bool {
		$volume = $this->volumes()[ $i ];
		if ( $this->size_now( $volume ) === $this->size_then( $i ) ) {
			return false;
		}
		$this->add( new Finding( $phase, Finding::CHANGED, 'The volume changed while it was being verified.', array( 'volume' => $volume['ordinal'] ) ) );
		$this->state['changed'] = true;
		$this->stop( $phase );
		return true;
	}

	/*
	 * Phase 2: volumes.
	 */

	/**
	 * One volume: is it there, with the declared size? The last volume
	 * (holding the indexes) missing ends the run.
	 *
	 * @return void
	 */
	private function step_volumes(): void {
		$volumes = $this->volumes();
		if ( array() === $volumes ) {
			$this->add( new Finding( self::PHASE_VOLUMES, Finding::MALFORMED, 'The manifest lists no volumes.' ) );
			$this->state['invalid'] = true;
			$this->stop( self::PHASE_VOLUMES );
			return;
		}
		$i       = (int) $this->state['volume'];
		$volume  = $volumes[ $i ];
		$is_last = count( $volumes ) - 1 === $i;
		$size    = $this->size_now( $volume );

		$this->state['sizes'][ $i ] = $size;
		if ( null === $size ) {
			$this->add( new Finding( self::PHASE_VOLUMES, Finding::MISSING, 'The volume is not in the archive directory.', array( 'volume' => $volume['ordinal'] ) ) );
			if ( $is_last ) {
				$this->stop( self::PHASE_VOLUMES );
				return;
			}
		} else {
			++$this->state['counts']['volumes_present'];
			if ( $volume['listed'] && $size !== $volume['bytes'] ) {
				$this->add( new Finding( self::PHASE_VOLUMES, Finding::CORRUPT, 'The volume size differs from the manifest.', array( 'volume' => $volume['ordinal'] ) ) );
			} elseif ( $is_last && ! $this->from_volume() ) {
				$this->check_embedded_copy( $volume );
			}
		}
		$this->state['volume'] = $i + 1;
		if ( $is_last ) {
			$this->state['phase'] = self::PHASE_INDEXES;
			$this->state['index'] = 'database';
			$this->state['sub']   = 'extract';
			$this->state['block'] = 0;
		}
	}

	/**
	 * The embedded copy in the last volume must describe the same archive
	 * as the manifest: marked embedded, listing exactly the volumes before
	 * the one that holds it. A copy that leaves a volume out would make
	 * that volume invisible to anyone restoring from the volumes alone,
	 * which is what the copy exists for; the writer's self-check runs at
	 * this depth, so a writer defect of that shape is caught here.
	 *
	 * @param array<string, mixed> $volume The last volume.
	 * @return void
	 * @throws \RuntimeException Never leaves: a copy that cannot be read or is not a valid manifest is a finding (caught inside).
	 */
	private function check_embedded_copy( array $volume ): void {
		try {
			$reader = ZipReader::open( $this->volume_path( $volume ) );
			$entry  = $reader->find( self::MANIFEST_ENTRY );
			if ( null === $entry || null !== $entry['problem'] ) {
				throw new \RuntimeException( 'The last volume has no manifest.json entry.' );
			}
			$copy = Manifest::from_json( $reader->read( $entry ) );
		} catch ( ManifestError $e ) {
			$this->add(
				new Finding(
					self::PHASE_VOLUMES,
					Finding::MALFORMED,
					'The embedded manifest copy is not a valid manifest: ' . $e->getMessage(),
					array(
						'volume' => $volume['ordinal'],
						'field'  => $e->field(),
					)
				)
			);
			return;
		} catch ( \RuntimeException $e ) {
			$this->add( new Finding( self::PHASE_VOLUMES, Finding::MALFORMED, 'The embedded manifest copy cannot be read: ' . $e->getMessage(), array( 'volume' => $volume['ordinal'] ) ) );
			return;
		}
		if ( ! $copy->embedded() ) {
			$this->add( new Finding( self::PHASE_VOLUMES, Finding::MALFORMED, 'The manifest copy inside the last volume is not marked as embedded.', array( 'volume' => $volume['ordinal'] ) ) );
			return;
		}
		$expected = array_slice( $this->manifest()->volumes(), 0, -1 );
		if ( json_encode( $expected ) !== json_encode( $copy->volumes() ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure PHP class; a comparison, not output.
			$this->add( new Finding( self::PHASE_VOLUMES, Finding::MALFORMED, 'The embedded manifest copy does not list the volumes before the last one as the manifest does.', array( 'volume' => $volume['ordinal'] ) ) );
		}
	}

	/*
	 * Phase 3: indexes.
	 */

	/**
	 * The manifest's content entry for an index.
	 *
	 * @param string $which 'database' or 'files'.
	 * @return array{path: string, bytes: int, chunks?: string[], sha256: string}
	 */
	private function index_spec( string $which ): array {
		return 'database' === $which ? $this->manifest()->database_index() : $this->manifest()->files_index();
	}

	/**
	 * Where an extracted index lives.
	 *
	 * @param string $which 'database' or 'files'.
	 * @return string
	 */
	private function index_file( string $which ): string {
		return $this->work_dir . DIRECTORY_SEPARATOR . $this->index_spec( $which )['path'];
	}

	/**
	 * Extract one index from the last volume, then check its hash chunk by
	 * chunk. Any problem ends the run: without an intact index nothing
	 * else can be attributed.
	 *
	 * @return void
	 * @throws EnvironmentFailure When the work directory cannot be written or is gone.
	 */
	private function step_indexes(): void {
		$which   = (string) $this->state['index'];
		$spec    = $this->index_spec( $which );
		$volumes = $this->volumes();
		$last    = $volumes[ count( $volumes ) - 1 ];
		if ( 'extract' === $this->state['sub'] ) {
			if ( $this->changed( count( $volumes ) - 1, self::PHASE_INDEXES ) ) {
				return;
			}
			try {
				$reader = ZipReader::open( $this->volume_path( $last ) );
			} catch ( \RuntimeException $e ) {
				$this->add( new Finding( self::PHASE_INDEXES, Finding::CORRUPT, 'The volume could not be opened as a zip archive: ' . $e->getMessage(), array( 'volume' => $last['ordinal'] ) ) );
				$this->stop( self::PHASE_INDEXES );
				return;
			}
			try {
				$entry = $reader->find( $spec['path'] );
			} catch ( \RuntimeException $e ) {
				$this->add( new Finding( self::PHASE_INDEXES, Finding::CORRUPT, 'The central directory of the volume is malformed: ' . $e->getMessage(), array( 'volume' => $last['ordinal'] ) ) );
				$this->stop( self::PHASE_INDEXES );
				return;
			}
			if ( null === $entry ) {
				$this->add(
					new Finding(
						self::PHASE_INDEXES,
						Finding::MISSING,
						'The sidecar index is not in the last volume.',
						array(
							'volume' => $last['ordinal'],
							'entry'  => $spec['path'],
						)
					)
				);
				$this->stop( self::PHASE_INDEXES );
				return;
			}
			if ( (int) $entry['usize'] !== $spec['bytes'] ) {
				$this->add(
					new Finding(
						self::PHASE_INDEXES,
						Finding::CORRUPT,
						'The sidecar index size differs from the manifest.',
						array(
							'volume' => $last['ordinal'],
							'entry'  => $spec['path'],
						)
					)
				);
				$this->stop( self::PHASE_INDEXES );
				return;
			}
			// One EXTRACT_PIECE per unit (a files index of a million-file site is hundreds of MB); the running
			// CRC lives in the cursor and the last piece checks it. A deflated index (small) comes in one piece.
			$piece  = (int) $this->state['block'];
			$offset = $piece * self::EXTRACT_PIECE;
			try {
				$result = $reader->extract_piece( $entry, $this->work_dir, $offset, self::EXTRACT_PIECE, 0 === $piece ? 0 : (int) $this->state['crc'] );
			} catch ( EnvironmentFailure $e ) {
				throw $e; // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- rethrown unchanged; step() reports it as an environment problem.
			} catch ( \RuntimeException $e ) {
				$this->add(
					new Finding(
						self::PHASE_INDEXES,
						Finding::CORRUPT,
						'The sidecar index could not be extracted: ' . $e->getMessage(),
						array(
							'volume' => $last['ordinal'],
							'entry'  => $spec['path'],
						)
					)
				);
				$this->stop( self::PHASE_INDEXES );
				return;
			}
			if ( ! $result['done'] ) {
				$this->state['block'] = $piece + 1;
				$this->state['crc']   = $result['crc'];
				return;
			}
			$this->state['sub']   = 'hash';
			$this->state['block'] = 0;
			$this->state['crc']   = 0;
			return;
		}
		$file  = $this->index_file( $which );
		$block = (int) $this->state['block'];
		clearstatcache();
		if ( ! is_file( $file ) ) {
			throw new EnvironmentFailure( 'The extracted index is no longer in the work directory.' );
		}
		if ( isset( $spec['chunks'] ) ) {
			$ok   = ChunkHasher::verify_chunk( $file, $block, $this->manifest()->chunk_bytes(), $spec['chunks'][ $block ] );
			$more = $block + 1 < count( $spec['chunks'] );
		} else {
			$ok   = $this->hash_file_equals( $file, $spec['sha256'] );
			$more = false;
		}
		if ( ! $ok ) {
			$this->add(
				new Finding(
					self::PHASE_INDEXES,
					Finding::CORRUPT,
					'The sidecar index content differs from its hash.',
					array(
						'volume' => $last['ordinal'],
						'entry'  => $spec['path'],
						'block'  => $block,
					)
				)
			);
			$this->stop( self::PHASE_INDEXES );
			return;
		}
		if ( $more ) {
			$this->state['block'] = $block + 1;
			return;
		}
		if ( 'database' === $which ) {
			$this->state['index'] = 'files';
			$this->state['sub']   = 'extract';
			$this->state['block'] = 0;
			return;
		}
		$this->state['phase']  = self::PHASE_INDEX_LINES;
		$this->state['index']  = 'database';
		$this->state['offset'] = 0;
		$this->state['line']   = 0;
		$this->state['table']  = null;
	}

	/**
	 * Whole-file hash comparison that treats an unreadable file as a mismatch.
	 *
	 * @param string $file     File.
	 * @param string $expected Lowercase hex.
	 * @return bool
	 */
	private function hash_file_equals( string $file, string $expected ): bool {
		try {
			return hash_equals( $expected, ChunkHasher::hash_file( $file ) );
		} catch ( \RuntimeException $e ) {
			return false;
		}
	}

	/*
	 * Phase 4: index lines.
	 */

	/**
	 * An open handle on an extracted index, kept for the unit.
	 *
	 * @param string $which 'database' or 'files'.
	 * @return resource
	 * @throws EnvironmentFailure When the extracted index is gone (work directory lost).
	 */
	private function index_handle( string $which ) {
		if ( ! isset( $this->handles[ $which ] ) ) {
			$handle = @fopen( $this->index_file( $which ), 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- stream read; failure is thrown.
			if ( false === $handle ) {
				throw new EnvironmentFailure( 'The extracted index is no longer in the work directory.' );
			}
			$this->handles[ $which ] = $handle;
		}
		return $this->handles[ $which ];
	}

	/**
	 * Read the line starting at $offset.
	 *
	 * @param string $which  'database' or 'files'.
	 * @param int    $offset Byte offset of the line start.
	 * @return array{0: string, 1: int}|null Line without its newline and the offset after it; null at the end.
	 * @throws \RuntimeException When the file cannot be positioned.
	 */
	private function read_line( string $which, int $offset ): ?array {
		$handle = $this->index_handle( $which );
		if ( 0 !== fseek( $handle, $offset ) ) {
			throw new \RuntimeException( 'The extracted index could not be positioned.' );
		}
		$line = fgets( $handle, IndexLine::MAX_LINE_BYTES + 2 );
		if ( false === $line ) {
			return null;
		}
		$next = $offset + strlen( $line );
		if ( "\n" === substr( $line, -1 ) ) {
			$line = substr( $line, 0, -1 );
		}
		return array( $line, $next );
	}

	/**
	 * Up to LINES_PER_UNIT lines of the current index, each parsed and
	 * cross-checked with the manifest. A line that does not parse means the
	 * index is damaged and ends the run.
	 *
	 * @return void
	 */
	private function step_index_lines(): void {
		$which       = (string) $this->state['index'];
		$chunk_bytes = $this->manifest()->chunk_bytes();
		for ( $i = 0; $i < self::LINES_PER_UNIT; $i++ ) {
			$start = (int) $this->state['offset'];
			$read  = $this->read_line( $which, $start );
			if ( null === $read ) {
				$this->finish_index( $which );
				return;
			}
			list( $line, $next )   = $read;
			$number                = (int) $this->state['line'] + 1;
			$this->state['line']   = $number;
			$this->state['offset'] = $next;
			try {
				if ( 'database' === $which ) {
					$this->accept_table_line( IndexLine::database( $line, $chunk_bytes ), $number, $start, $next );
				} else {
					$this->accept_file_line( IndexLine::files( $line, $chunk_bytes ), $number );
				}
			} catch ( IndexLineError $e ) {
				$this->add(
					new Finding(
						self::PHASE_INDEX_LINES,
						Finding::MALFORMED,
						'The sidecar index line could not be parsed: ' . $e->getMessage(),
						array(
							'entry' => $this->index_spec( $which )['path'],
							'line'  => $number,
							'field' => $e->key(),
						)
					)
				);
				$this->stop( self::PHASE_INDEX_LINES );
				return;
			}
			if ( $this->finished() ) {
				return;
			}
		}
	}

	/**
	 * One database index line against the table summaries. Tables appear in
	 * manifest order, each with chunks 1..n contiguous; a table's list hash
	 * is checked when its last chunk is seen by re-reading its lines.
	 *
	 * @param array{t: string, c: int, p: string, b: int, h: string} $line   Parsed line.
	 * @param int                                                    $number Line number.
	 * @param int                                                    $start  Offset of the line.
	 * @param int                                                    $end    Offset after the line.
	 * @return void
	 */
	private function accept_table_line( array $line, int $number, int $start, int $end ): void {
		$tables = $this->manifest()->tables();
		$where  = array(
			'entry' => Manifest::DATABASE_INDEX,
			'line'  => $number,
			'table' => $line['t'],
			'chunk' => $line['c'],
		);
		$table  = $this->state['table'];
		if ( null !== $table && ! $table['complete'] ) {
			if ( $tables[ $table['pos'] ]['name'] !== $line['t'] ) {
				$this->fail_lines( 'The table has fewer chunks than the manifest declares.', $where );
				return;
			}
			if ( $line['c'] !== $table['next'] ) {
				$this->fail_lines( 'Chunk numbers are not sequential.', $where );
				return;
			}
		} else {
			if ( null !== $table && $tables[ $table['pos'] ]['name'] === $line['t'] ) {
				$this->fail_lines( 'The table has more chunks than the manifest declares.', $where );
				return;
			}
			$pos = null === $table ? 0 : $table['pos'] + 1;
			$pos = $this->skip_empty_tables( $pos );
			if ( $this->finished() ) {
				return;
			}
			if ( $pos >= count( $tables ) ) {
				$this->fail_lines( 'The table is not in the manifest, or appears twice.', $where );
				return;
			}
			if ( $tables[ $pos ]['name'] !== $line['t'] ) {
				$this->fail_lines( 'Table order differs from the manifest.', $where );
				return;
			}
			if ( 1 !== $line['c'] ) {
				$this->fail_lines( 'The first chunk of a table must be 1.', $where );
				return;
			}
			$table = array(
				'pos'      => $pos,
				'next'     => 1,
				'bytes'    => 0,
				'start'    => $start,
				'complete' => false,
			);
		}
		$table['next']  += 1;
		$table['bytes'] += $line['b'];
		$declared        = $tables[ $table['pos'] ];
		if ( $table['next'] - 1 === $declared['chunks'] ) {
			if ( $table['bytes'] !== $declared['bytes'] ) {
				$this->fail_lines( 'The table size differs from the manifest.', $where );
				return;
			}
			if ( ! hash_equals( $declared['sha256'], $this->table_list_hash( $table['start'], $end ) ) ) {
				$this->fail_lines( 'The chunk hashes of the table do not match the manifest summary.', $where );
				return;
			}
			$table['complete'] = true;
			++$this->state['counts']['tables_indexed'];
		}
		$this->state['table'] = $table;
	}

	/**
	 * Tables declared with zero chunks have no index lines; they are
	 * accepted in passing when their summary is the empty list hash.
	 *
	 * @param int $pos Position to start from.
	 * @return int First position with chunks, or the count.
	 */
	private function skip_empty_tables( int $pos ): int {
		$tables = $this->manifest()->tables();
		$total  = count( $tables );
		while ( $pos < $total && 0 === $tables[ $pos ]['chunks'] ) {
			if ( ! Manifest::is_empty_table( $tables[ $pos ] ) ) {
				$this->fail_lines( 'A table with no chunks declares bytes or a hash.', array( 'table' => $tables[ $pos ]['name'] ) );
				return $pos;
			}
			++$this->state['counts']['tables_indexed'];
			++$pos;
		}
		return $pos;
	}

	/**
	 * List hash of the chunk hashes on the lines between two offsets.
	 *
	 * @param int $start Offset of the first line.
	 * @param int $end   Offset after the last line.
	 * @return string
	 * @throws \RuntimeException When a line changed since it was accepted.
	 */
	private function table_list_hash( int $start, int $end ): string {
		$context = hash_init( 'sha256' );
		$offset  = $start;
		while ( $offset < $end ) {
			$read = $this->read_line( 'database', $offset );
			if ( null === $read ) {
				break;
			}
			try {
				$parsed = IndexLine::database( $read[0], $this->manifest()->chunk_bytes() );
			} catch ( IndexLineError $e ) {
				throw new \RuntimeException( 'The extracted index changed while it was being read.' );
			}
			hash_update( $context, $parsed['h'] );
			$offset = $read[1];
		}
		return hash_final( $context );
	}

	/**
	 * One files index line: counted and summed; the manifest's count bounds it.
	 *
	 * @param array{p: string, b: int, m: int, h: string|null, hc: string[]|null} $line   Parsed line.
	 * @param int                                                                 $number Line number.
	 * @return void
	 */
	private function accept_file_line( array $line, int $number ): void {
		$summary = $this->manifest()->files_summary();
		$count   = (int) $this->state['counts']['files_indexed'] + 1;
		if ( $count > $summary['count'] ) {
			$this->fail_lines(
				'The index lists more files than the manifest declares.',
				array(
					'entry' => Manifest::FILES_INDEX,
					'line'  => $number,
				)
			);
			return;
		}
		$this->state['counts']['files_indexed'] = $count;
		$this->state['sum']                     = (int) $this->state['sum'] + $line['b'];
		if ( null === $line['h'] ) {
			++$this->state['counts']['files_size_only'];
		}
	}

	/**
	 * End of an index: the summaries must be exhausted exactly.
	 *
	 * @param string $which 'database' or 'files'.
	 * @return void
	 */
	private function finish_index( string $which ): void {
		if ( 'database' === $which ) {
			$table = $this->state['table'];
			if ( null !== $table && ! $table['complete'] ) {
				$this->fail_lines( 'The index ends before the last chunk of the table.', array( 'table' => $this->manifest()->tables()[ $table['pos'] ]['name'] ) );
				return;
			}
			$pos = $this->skip_empty_tables( null === $table ? 0 : $table['pos'] + 1 );
			if ( $this->finished() ) {
				return;
			}
			if ( $pos < count( $this->manifest()->tables() ) ) {
				$this->fail_lines( 'The index has no lines for the table.', array( 'table' => $this->manifest()->tables()[ $pos ]['name'] ), Finding::MISSING );
				return;
			}
			$this->state['index']  = 'files';
			$this->state['offset'] = 0;
			$this->state['line']   = 0;
			$this->state['table']  = null;
			$this->state['sum']    = 0;
			return;
		}
		$summary = $this->manifest()->files_summary();
		if ( (int) $this->state['counts']['files_indexed'] !== $summary['count'] ) {
			$this->fail_lines( 'The index lists fewer files than the manifest declares.', array( 'entry' => Manifest::FILES_INDEX ), Finding::MISSING );
			return;
		}
		if ( (int) $this->state['sum'] !== $summary['bytes'] ) {
			$this->fail_lines( 'The total file size differs from the manifest.', array( 'entry' => Manifest::FILES_INDEX ) );
			return;
		}
		$this->state['table'] = null;
		if ( self::DEPTH_FULL !== $this->state['depth'] ) {
			// Structure depth walks every volume's central directory too, in step with the index and with each
			// local header compared to its central record, but hashes nothing (no containers, no data).
			$this->start_contents();
			return;
		}
		$this->state['phase']  = self::PHASE_CONTAINERS;
		$this->state['volume'] = 0;
		$this->state['block']  = 0;
	}

	/**
	 * An index that disagrees with the manifest is damage; the run ends.
	 *
	 * @param string               $message Fixed text.
	 * @param array<string, mixed> $where   Location.
	 * @param string               $kind    Finding kind.
	 * @return void
	 */
	private function fail_lines( string $message, array $where, string $kind = Finding::MALFORMED ): void {
		$this->add( new Finding( self::PHASE_INDEX_LINES, $kind, $message, $where ) );
		$this->stop( self::PHASE_INDEX_LINES );
	}

	/*
	 * Phase 5: containers.
	 */

	/**
	 * One block of one listed volume against the manifest's hashes. Volumes
	 * already reported missing or mis-sized are skipped.
	 *
	 * @return void
	 */
	private function step_containers(): void {
		$volumes = $this->volumes();
		$i       = (int) $this->state['volume'];
		if ( $i >= count( $volumes ) ) {
			$this->start_contents();
			return;
		}
		$volume = $volumes[ $i ];
		if ( $this->changed( $i, self::PHASE_CONTAINERS ) ) {
			return;
		}
		if ( ! $volume['listed'] || $this->size_then( $i ) !== $volume['bytes'] ) {
			// Unlisted (the given volume) or already reported missing or mis-sized.
			$this->next_container();
			return;
		}
		$path  = $this->volume_path( $volume );
		$block = (int) $this->state['block'];
		if ( isset( $volume['chunks'] ) ) {
			$ok   = ChunkHasher::verify_chunk( $path, $block, $this->manifest()->volume_chunk_bytes(), $volume['chunks'][ $block ] );
			$more = $block + 1 < count( $volume['chunks'] );
		} else {
			$ok   = $this->hash_file_equals( $path, $volume['sha256'] );
			$more = false;
		}
		if ( ! $ok ) {
			$this->add(
				new Finding(
					self::PHASE_CONTAINERS,
					Finding::CORRUPT,
					'The volume content differs from its hash.',
					array(
						'volume' => $volume['ordinal'],
						'block'  => $block,
					)
				)
			);
		}
		++$this->state['counts']['blocks_verified'];
		if ( $more ) {
			$this->state['block'] = $block + 1;
			return;
		}
		++$this->state['counts']['volumes_verified'];
		$this->next_container();
	}

	/**
	 * Advance to the next volume in the containers phase.
	 *
	 * @return void
	 */
	private function next_container(): void {
		$this->state['volume'] = (int) $this->state['volume'] + 1;
		$this->state['block']  = 0;
	}

	/**
	 * Enter the contents phase at the first volume and the first index line.
	 *
	 * @return void
	 */
	private function start_contents(): void {
		$this->state['phase']       = self::PHASE_CONTENTS;
		$this->state['volume']      = 0;
		$this->state['entry']       = array(
			'index'     => 0,
			'cd_offset' => -1,
		);
		$this->state['entry_block'] = 0;
		$this->state['index']       = 'database';
		$this->state['offset']      = 0;
		$this->state['line']        = 0;
		$this->state['gap']         = false;
	}

	/*
	 * Phase 6: contents.
	 */

	/**
	 * The next index line, database lines first, then files. Reaching the
	 * end of the database index moves the cursor to the files index.
	 *
	 * @return array{which: string, line: array<string, mixed>, name: string, end: int}|null Null when both are exhausted.
	 * @throws \RuntimeException When the extracted index is gone or changed.
	 */
	private function peek_line(): ?array {
		$chunk_bytes = $this->manifest()->chunk_bytes();
		while ( true ) {
			$which = (string) $this->state['index'];
			$read  = $this->read_line( $which, (int) $this->state['offset'] );
			if ( null === $read ) {
				if ( 'files' === $which ) {
					return null;
				}
				$this->state['index']  = 'files';
				$this->state['offset'] = 0;
				$this->state['line']   = 0;
				continue;
			}
			try {
				$parsed = 'database' === $which ? IndexLine::database( $read[0], $chunk_bytes ) : IndexLine::files( $read[0], $chunk_bytes );
			} catch ( IndexLineError $e ) {
				throw new \RuntimeException( 'The extracted index changed while it was being read.' );
			}
			return array(
				'which' => $which,
				'line'  => $parsed,
				'name'  => 'database' === $which ? $parsed['p'] : self::FILES_PREFIX . $parsed['p'],
				'end'   => $read[1],
			);
		}
	}

	/**
	 * Move past the peeked line.
	 *
	 * @param array{which: string, line: array<string, mixed>, name: string, end: int} $peeked Peeked line.
	 * @return void
	 */
	private function consume_line( array $peeked ): void {
		$this->state['offset'] = $peeked['end'];
		$this->state['line']   = (int) $this->state['line'] + 1;
	}

	/**
	 * Where a peeked line's finding points.
	 *
	 * @param array{which: string, line: array<string, mixed>, name: string, end: int} $peeked  Peeked line.
	 * @param int|null                                                                 $ordinal Volume ordinal.
	 * @return array<string, mixed>
	 */
	private function line_where( array $peeked, $ordinal = null ): array {
		$where = array(
			'entry' => $peeked['name'],
			'line'  => (int) $this->state['line'] + 1,
		);
		if ( 'database' === $peeked['which'] ) {
			$where['table'] = $peeked['line']['t'];
			$where['chunk'] = $peeked['line']['c'];
		}
		if ( null !== $ordinal ) {
			$where['volume'] = $ordinal;
		}
		return $where;
	}

	/**
	 * A batch of entries of the current volume, each against its index
	 * line; a stored entry larger than chunk_bytes takes one block per
	 * unit. After the last volume, lines left over are missing content.
	 *
	 * @return void
	 */
	private function step_contents(): void {
		$volumes = $this->volumes();
		$i       = (int) $this->state['volume'];
		if ( $i >= count( $volumes ) ) {
			$this->finish_contents();
			return;
		}
		$volume = $volumes[ $i ];
		if ( $this->changed( $i, self::PHASE_CONTENTS ) ) {
			return;
		}
		if ( null === $this->size_then( $i ) ) {
			$this->state['gap'] = true;
			$this->next_volume_contents();
			return;
		}
		try {
			$reader = ZipReader::open( $this->volume_path( $volume ) );
		} catch ( \RuntimeException $e ) {
			$this->add( new Finding( self::PHASE_CONTENTS, Finding::CORRUPT, 'The volume could not be opened as a zip archive: ' . $e->getMessage(), array( 'volume' => $volume['ordinal'] ) ) );
			$this->state['gap'] = true;
			$this->next_volume_contents();
			return;
		}
		$bytes   = 0;
		$entries = 0;
		try {
			$reader->each(
				function ( array $entry ) use ( $volume, $reader, &$bytes, &$entries ): bool {
					$next = array(
						'index'     => (int) $entry['index'] + 1,
						'cd_offset' => (int) $entry['cd_next'],
					);
					++$entries;
					if ( $entry['directory'] || in_array( $entry['name'], Packer::SUMMARY_ENTRIES, true ) ) {
						if ( ! $entry['directory'] ) {
							// The indexes and the embedded manifest have no index line, but their headers are ours too.
							$this->check_local_header(
								$reader,
								$entry,
								array(
									'volume' => $volume['ordinal'],
									'entry'  => (string) $entry['name'],
								),
								false
							);
						}
						$this->state['entry'] = $next;
						return $entries < self::ENTRIES_PER_UNIT;
					}
					// The unit bound is the verifier's: an entry that would push it past MAX_CONTENT_CHUNK waits
					// for the next unit (a large stored entry is then taken one chunk at a time).
					if ( $bytes > 0 && $bytes + (int) min( (int) $entry['usize'], self::MAX_CONTENT_CHUNK ) > self::MAX_CONTENT_CHUNK ) {
						return false;
					}
					$done = $this->verify_entry( $reader, $entry, $volume['ordinal'], $bytes );
					if ( $done ) {
						$this->state['entry']       = $next;
						$this->state['entry_block'] = 0;
					}
					return $done && ! $this->finished() && $bytes < self::MAX_CONTENT_CHUNK && $entries < self::ENTRIES_PER_UNIT;
				},
				(int) $this->state['entry']['index'],
				(int) $this->state['entry']['cd_offset']
			);
		} catch ( \RuntimeException $e ) {
			$this->add( new Finding( self::PHASE_CONTENTS, Finding::CORRUPT, 'The central directory of the volume is malformed: ' . $e->getMessage(), array( 'volume' => $volume['ordinal'] ) ) );
			$this->state['gap'] = true;
			$this->next_volume_contents();
			return;
		}
		if ( $this->finished() ) {
			return;
		}
		if ( (int) $this->state['entry']['index'] >= $reader->count() ) {
			$this->next_volume_contents();
		}
	}

	/**
	 * Advance to the next volume in the contents phase.
	 *
	 * @return void
	 */
	private function next_volume_contents(): void {
		$this->state['volume']      = (int) $this->state['volume'] + 1;
		$this->state['entry']       = array(
			'index'     => 0,
			'cd_offset' => -1,
		);
		$this->state['entry_block'] = 0;
	}

	/**
	 * One entry against the index. Returns false when the entry needs more
	 * units (a large stored entry hashed block by block).
	 *
	 * @param ZipReader            $reader  Open volume.
	 * @param array<string, mixed> $entry   Entry.
	 * @param int                  $ordinal Volume ordinal.
	 * @param int                  $bytes   Bytes handled in this unit (updated).
	 * @return bool
	 */
	private function verify_entry( ZipReader $reader, array $entry, int $ordinal, int &$bytes ): bool {
		if ( null !== $entry['problem'] ) {
			$this->add(
				new Finding(
					self::PHASE_CONTENTS,
					Finding::MALFORMED,
					'An entry name is not acceptable: ' . $entry['problem'],
					array(
						'volume' => $ordinal,
						'entry'  => (string) $entry['name'],
					)
				)
			);
			$this->stop( self::PHASE_CONTENTS );
			return true;
		}
		$peeked = $this->peek_line();
		if ( null === $peeked || $peeked['name'] !== $entry['name'] ) {
			if ( ! $this->state['gap'] || null === $peeked ) {
				$this->add(
					new Finding(
						self::PHASE_CONTENTS,
						Finding::UNSUPPORTED,
						self::ORDER_MESSAGE,
						array(
							'volume' => $ordinal,
							'entry'  => (string) $entry['name'],
						)
					)
				);
				$this->stop( self::PHASE_CONTENTS );
				return true;
			}
			// A volume before this one was missing: the lines up to this entry belong to it.
			$peeked = $this->resync( $peeked, $entry['name'], $ordinal );
			if ( null === $peeked ) {
				// Stopped, or LINES_PER_UNIT lines skipped: the entry stays current and the next unit goes on.
				return $this->finished();
			}
		}
		$this->state['gap'] = false;
		$line               = $peeked['line'];
		$where              = $this->line_where( $peeked, $ordinal );
		$chunk_bytes        = $this->manifest()->chunk_bytes();
		$usize              = (int) $entry['usize'];
		if ( 0 === (int) $this->state['entry_block'] ) {
			$this->check_local_header( $reader, $entry, $where );
		}
		if ( $usize !== $line['b'] ) {
			$this->add( new Finding( self::PHASE_CONTENTS, Finding::CORRUPT, 'The entry size differs from the index.', $where ) );
			$this->consume_line( $peeked );
			return true;
		}
		if ( self::DEPTH_FULL !== $this->state['depth'] ) {
			// Structure depth: order, name, size and headers are checked; the data is not read.
			$this->consume_line( $peeked );
			return true;
		}
		$bytes += $usize;
		if ( 'database' === $peeked['which'] ) {
			if ( $this->entry_hash_equals( $reader, $entry, $line['h'], $where ) ) {
				++$this->state['counts']['chunks_verified'];
			}
			$this->consume_line( $peeked );
			return true;
		}
		if ( null === $line['h'] ) {
			// Counted as size-only when its index line was read; nothing more to check here.
			$this->consume_line( $peeked );
			return true;
		}
		if ( null === $line['hc'] ) {
			if ( $this->entry_hash_equals( $reader, $entry, $line['h'], $where ) ) {
				++$this->state['counts']['files_verified'];
			}
			$this->consume_line( $peeked );
			return true;
		}
		if ( ZipFormat::METHOD_STORE !== (int) $entry['method'] ) {
			// Deflated (at most the reader's inflate limit): one unit for the whole entry.
			try {
				$hashes = $reader->hash_entry_chunks( $entry, $chunk_bytes );
			} catch ( \RuntimeException $e ) {
				$this->unreadable( $e, $where );
				$this->consume_line( $peeked );
				return true;
			}
			if ( $hashes === $line['hc'] ) {
				++$this->state['counts']['files_verified'];
			} else {
				$this->add( new Finding( self::PHASE_CONTENTS, Finding::CORRUPT, 'The entry content differs from its hash.', $where ) );
			}
			$this->consume_line( $peeked );
			return true;
		}
		// Stored and large: one chunk per unit; the first mismatch ends the entry.
		$block          = (int) $this->state['entry_block'];
		$where['block'] = $block;
		$length         = (int) min( $chunk_bytes, $usize - $block * $chunk_bytes );
		$bytes         += $length - $usize;
		try {
			$hash = $reader->hash_entry_range( $entry, $block * $chunk_bytes, $length );
		} catch ( \RuntimeException $e ) {
			$this->unreadable( $e, $where );
			$this->consume_line( $peeked );
			return true;
		}
		if ( ! hash_equals( $line['hc'][ $block ], $hash ) ) {
			$this->add( new Finding( self::PHASE_CONTENTS, Finding::CORRUPT, 'The entry content differs from its hash.', $where ) );
			$this->consume_line( $peeked );
			return true;
		}
		if ( $block + 1 < count( $line['hc'] ) ) {
			$this->state['entry_block'] = $block + 1;
			return false;
		}
		++$this->state['counts']['files_verified'];
		$this->consume_line( $peeked );
		return true;
	}

	/**
	 * The entry's local header against its central record: a difference is
	 * a corrupt finding naming the fields (the copies a streaming reader and
	 * a seeking reader trust would disagree); a header that cannot be read
	 * is one too. An entry with data descriptors marks an archive another
	 * tool wrote or repacked: reported once per run, as unsupported. Never
	 * throws.
	 *
	 * @param ZipReader            $reader Open volume.
	 * @param array<string, mixed> $entry  Central record.
	 * @param array<string, mixed> $where  Location.
	 * @param bool                 $count  Whether the entry counts toward progress (an index line's entry).
	 * @return void
	 */
	private function check_local_header( ZipReader $reader, array $entry, array $where, bool $count = true ): void {
		if ( $count ) {
			++$this->state['counts']['headers_checked'];
		}
		try {
			$local = $reader->local_header( $entry );
		} catch ( \RuntimeException $e ) {
			$this->add( new Finding( self::PHASE_CONTENTS, Finding::CORRUPT, 'The local header of the entry cannot be read: ' . $e->getMessage(), $where ) );
			return;
		}
		$check = LocalHeaderCheck::compare( $entry, $local );
		if ( $check['data_descriptor'] && empty( $this->state['foreign'] ) ) {
			$this->state['foreign'] = true;
			$this->add( new Finding( self::PHASE_CONTENTS, Finding::UNSUPPORTED, self::FOREIGN_MESSAGE, $where ) );
		}
		if ( array() !== $check['fields'] ) {
			$this->state['inconsistent'] = true; // A fact for the advice (VerificationResult::INCONSISTENT_ADVICE), not a finding.
			$this->add( new Finding( self::PHASE_CONTENTS, Finding::CORRUPT, 'The local header of the entry disagrees with the central directory (' . implode( ', ', $check['fields'] ) . ').', $where ) );
		}
	}

	/**
	 * Whole-entry hash comparison, recording a finding on mismatch or read failure.
	 *
	 * @param ZipReader            $reader   Open volume.
	 * @param array<string, mixed> $entry    Entry.
	 * @param string               $expected Lowercase hex.
	 * @param array<string, mixed> $where    Location.
	 * @return bool
	 */
	private function entry_hash_equals( ZipReader $reader, array $entry, string $expected, array $where ): bool {
		try {
			if ( hash_equals( $expected, $reader->hash_entry( $entry ) ) ) {
				return true;
			}
		} catch ( \RuntimeException $e ) {
			$this->unreadable( $e, $where );
			return false;
		}
		$this->add( new Finding( self::PHASE_CONTENTS, Finding::CORRUPT, 'The entry content differs from its hash.', $where ) );
		return false;
	}

	/**
	 * Record an entry the reader refused (CRC, truncation, unsupported method).
	 *
	 * @param \RuntimeException    $e     Reader exception.
	 * @param array<string, mixed> $where Location.
	 * @return void
	 */
	private function unreadable( \RuntimeException $e, array $where ): void {
		$this->add( new Finding( self::PHASE_CONTENTS, Finding::CORRUPT, 'The entry could not be read: ' . $e->getMessage(), $where ) );
	}

	/**
	 * After a missing volume, skip index lines until the current entry's
	 * line; every skipped line is missing content. Not finding it means the
	 * layout is not one this verifier understands. At most LINES_PER_UNIT
	 * lines per call: the skipped lines advance the cursor, so a later unit
	 * continues from where this one stopped.
	 *
	 * @param array{which: string, line: array<string, mixed>, name: string, end: int} $peeked  Current line.
	 * @param string                                                                   $name    Entry name.
	 * @param int                                                                      $ordinal Volume ordinal.
	 * @return array{which: string, line: array<string, mixed>, name: string, end: int}|null The matching line, or null after stopping or when the unit is used up.
	 */
	private function resync( array $peeked, string $name, int $ordinal ): ?array {
		$skipped = 0;
		while ( $peeked['name'] !== $name ) {
			if ( ++$skipped > self::LINES_PER_UNIT ) {
				return null;
			}
			$this->add( new Finding( self::PHASE_CONTENTS, Finding::MISSING, 'The content declared in the index is in no present volume.', $this->line_where( $peeked ) ) );
			++$this->state['counts']['entries_missing'];
			$this->consume_line( $peeked );
			$peeked = $this->peek_line();
			if ( null === $peeked ) {
				$this->add(
					new Finding(
						self::PHASE_CONTENTS,
						Finding::UNSUPPORTED,
						self::ORDER_MESSAGE,
						array(
							'volume' => $ordinal,
							'entry'  => $name,
						)
					)
				);
				$this->stop( self::PHASE_CONTENTS );
				return null;
			}
		}
		return $peeked;
	}

	/**
	 * After the last volume: any line left is content the archive does not
	 * hold, LINES_PER_UNIT of them per unit.
	 *
	 * @return void
	 */
	private function finish_contents(): void {
		$peeked = $this->peek_line();
		$lines  = 0;
		while ( null !== $peeked ) {
			if ( ++$lines > self::LINES_PER_UNIT ) {
				return; // Next unit continues from the cursor.
			}
			$this->add( new Finding( self::PHASE_CONTENTS, Finding::MISSING, 'The content declared in the index is in no present volume.', $this->line_where( $peeked ) ) );
			++$this->state['counts']['entries_missing'];
			$this->consume_line( $peeked );
			$peeked = $this->peek_line();
		}
		$this->state['phase'] = self::PHASE_DONE;
	}

	/*
	 * Findings.
	 */

	/**
	 * Record a finding: counted by kind, kept in full up to MAX_STORED_FINDINGS.
	 *
	 * @param Finding $finding Finding.
	 * @return void
	 */
	private function add( Finding $finding ): void {
		$kind                          = $finding->kind();
		$this->state['kinds'][ $kind ] = (int) ( $this->state['kinds'][ $kind ] ?? 0 ) + 1;
		$this->state['findings_total'] = (int) $this->state['findings_total'] + 1;
		if ( count( $this->state['findings'] ) < self::MAX_STORED_FINDINGS ) {
			$this->state['findings'][] = $finding->raw();
		}
	}

	/**
	 * End the run in the given phase.
	 *
	 * @param string $phase Phase.
	 * @return void
	 */
	private function stop( string $phase ): void {
		$this->state['stopped_at'] = $phase;
		$this->state['phase']      = self::PHASE_DONE;
	}
}
