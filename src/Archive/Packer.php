<?php
/**
 * Writes the volumes of an archive in bounded pieces across ticks.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Archive;

// phpcs:disable WordPress.WP.AlternativeFunctions -- streamed writes to the plugin's own volume files; the WP filesystem API has no equivalent.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are internal (an entry-path verdict), never HTML; the caller presents them through JobPresenter::clean().

/**
 * A streaming multi-volume zip writer driven by a step. Everything it
 * knows lives in the array state() returns, which the step keeps in its
 * cursor: the open volume (index, committed bytes, committed entries), the
 * entry in progress (source, offset, running CRC), the sealed volumes and
 * their container chunk hashes. A tick can die at any point; open() with
 * the last state truncates the volume and its record file back to the
 * committed point and continues.
 *
 * Bounded work: add_entry() writes a header, write_piece() copies at most
 * one piece (4 MiB) of the entry, seal_volume() writes the central
 * directory, hash_next_block() hashes one container chunk of a sealed
 * volume. The step calls should_stop() between those calls and
 * should_checkpoint() to decide when to persist state().
 *
 * The caller seals volumes between entries once they reach the volume
 * size (has_room() says when; the packer never seals or creates a volume
 * on its own); an entry never spans volumes, so a volume may exceed the
 * size by its last entry. The open volume is "<name>.partial" with its central directory
 * records in "<name>.cdr"; sealing renames it to its final name. Entries up
 * to DEFLATE_MAX_BYTES are deflated in one piece, larger ones are stored:
 * a deflate state cannot survive a tick, and media files do not compress.
 */
final class Packer {

	const VOLUME_BYTES        = 1073741824; // 1 GiB, the seal threshold.
	const MAX_VOLUME_ENTRIES  = 100000;     // Sealed before this many entries: the central directory is written in one call (about 0.3 s per 100k here, several times slower on a shared host), and far below the reader's limit.
	const SUMMARY_ENTRIES     = array( 'manifest.json', Manifest::DATABASE_INDEX, Manifest::FILES_INDEX );
	const PIECE_BYTES         = 4194304;    // 4 MiB per write_piece().
	const DEFLATE_MAX_BYTES   = 4194304;
	const COMPRESSION_LEVEL   = 6;
	const ZIP64_THRESHOLD     = 4294967295; // Sizes and offsets at or above this need zip64.
	const SPACE_MARGIN_BYTES  = 67108864;   // 64 MiB kept free.
	const SPACE_CHECK_BYTES   = 67108864;   // Re-check free space every 64 MiB written.
	const PARTIAL_SUFFIX      = '.partial';
	const RECORDS_SUFFIX      = '.cdr';
	const SINGLE_SUFFIX       = '.wpcheckpoint.zip';
	const VOLUME_NAME_PATTERN = '%s.part%03d.wpcheckpoint.zip';

	/**
	 * Directory the volumes are written to.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Options (see open()).
	 *
	 * @var array<string, mixed>
	 */
	private $options;

	/**
	 * State (see state()).
	 *
	 * @var array<string, mixed>
	 */
	private $state;

	/**
	 * Length of the open volume as this process left it (see assert_sole_writer()); null when no handle is open.
	 *
	 * @var int|null
	 */
	private $high_water;

	/**
	 * Handle of the open volume, when any.
	 *
	 * @var resource|null
	 */
	private $handle = null;

	/**
	 * Handle of the entry's source file, when an entry is in progress.
	 *
	 * @var resource|null
	 */
	private $source = null;

	/**
	 * Bytes written since the last free-space check.
	 *
	 * @var int
	 */
	private $since_space_check = 0;

	/**
	 * Use open().
	 *
	 * @param string               $dir     Directory.
	 * @param array<string, mixed> $options Options.
	 * @param array<string, mixed> $state   State.
	 */
	private function __construct( string $dir, array $options, array $state ) {
		$this->dir     = rtrim( $dir, '/\\' );
		$this->options = $options;
		$this->state   = $state;
	}

	/**
	 * Largest entry this platform can write: PHP's file offsets are ints.
	 *
	 * @param int $int_size PHP_INT_SIZE of the platform (the environment check injects 4 in tests).
	 * @return int
	 */
	public static function max_entry_bytes( int $int_size = PHP_INT_SIZE ): int {
		return $int_size >= 8 ? 4398046511104 : 2147483647; // 4 TiB, or 2 GiB - 1 on 32-bit PHP.
	}

	/**
	 * Largest file a backup can hold here: the smaller of what the
	 * container can address (max_entry_bytes()) and what one index line
	 * can describe (IndexLine::max_indexable_bytes()). The scan lists
	 * larger files as too large so that the export stops before packing
	 * anything, never after.
	 *
	 * @param int $chunk_bytes Content chunk size.
	 * @param int $int_size    PHP_INT_SIZE of the platform.
	 * @return array{bytes: int, limited_by: string} The limit and which one applies ('int_size' or 'index').
	 */
	public static function max_file_bytes( int $chunk_bytes, int $int_size = PHP_INT_SIZE ): array {
		$entry = self::max_entry_bytes( $int_size );
		$index = IndexLine::max_indexable_bytes( $chunk_bytes );
		return $index < $entry ? array(
			'bytes'      => $index,
			'limited_by' => 'index',
		) : array(
			'bytes'      => $entry,
			'limited_by' => 'int_size',
		);
	}

	/**
	 * Largest volume this platform can write and hash: on 32-bit PHP file
	 * offsets stop at 2 GiB, so a volume is sealed before an entry would
	 * push it past this, even when every single entry fits. The export
	 * pre-flight uses the same number to warn before it starts.
	 *
	 * @param int $int_size PHP_INT_SIZE of the platform (the environment check injects 4 in tests).
	 * @return int
	 */
	public static function max_volume_bytes( int $int_size = PHP_INT_SIZE ): int {
		return $int_size >= 8 ? 4398046511104 : 2147483647 - 1048576; // 4 TiB, or 2 GiB - 1 minus room for the central directory.
	}

	/**
	 * Free space a caller should require before starting a volume.
	 *
	 * @param int $volume_bytes Volume size threshold.
	 * @return int
	 */
	public static function required_free_bytes( int $volume_bytes = self::VOLUME_BYTES ): int {
		return $volume_bytes + self::SPACE_MARGIN_BYTES;
	}

	/**
	 * Start a new archive or resume one from its state.
	 *
	 * @param string               $dir     Directory for the volumes (the job's temporary directory).
	 * @param string               $base    Base name of the archive, e.g. "example-20260918-100000-a1b2".
	 * @param array<string, mixed> $state   State from a previous tick, or empty.
	 * @param array<string, mixed> $options volume_bytes, volume_chunk_bytes, piece_bytes, deflate_max_bytes, zip64_threshold, max_volume_bytes, disk_free (callable( string $dir ): int|false), can_deflate (bool), confirm (callable, called right before each volume file is created or renamed; throws to stop the transition).
	 * @return Packer
	 * @throws \RuntimeException When the state cannot be resumed.
	 */
	public static function open( string $dir, string $base, array $state = array(), array $options = array() ): Packer {
		if ( ! is_dir( $dir ) ) {
			throw new \RuntimeException( 'The volume directory does not exist.' );
		}
		if ( 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,120}\z/', $base ) ) {
			throw new \RuntimeException( 'Invalid archive base name.' );
		}
		$options = array_merge(
			array(
				'volume_bytes'       => self::VOLUME_BYTES,
				'volume_chunk_bytes' => Manifest::DEFAULT_VOLUME_CHUNK,
				'piece_bytes'        => self::PIECE_BYTES,
				'deflate_max_bytes'  => self::DEFLATE_MAX_BYTES,
				'zip64_threshold'    => self::ZIP64_THRESHOLD,
				'max_volume_bytes'   => self::max_volume_bytes(),
				'disk_free'          => 'disk_free_space',
				'can_deflate'        => function_exists( 'gzdeflate' ),
			),
			$options
		);
		if ( array() === $state ) {
			$state = array(
				'base'     => $base,
				'volume'   => null,
				'entry'    => null,
				'sealed'   => array(),
				'prepared' => false,
				'finished' => false,
			);
		} elseif ( ! isset( $state['base'] ) || $state['base'] !== $base ) {
			throw new \RuntimeException( 'The state belongs to another archive.' );
		}
		$packer = new self( $dir, $options, $state );
		$packer->resume();
		return $packer;
	}

	/**
	 * The state to keep in the cursor: identifiers, offsets and hashes only.
	 *
	 * @return array<string, mixed>
	 */
	public function state(): array {
		return $this->state;
	}

	/**
	 * Whether an entry is in progress.
	 *
	 * @return bool
	 */
	public function has_open_entry(): bool {
		return null !== $this->state['entry'];
	}

	/**
	 * Whether a volume is open (a step checks this before seal_volume():
	 * after a crash between the rename and its checkpoint, resume() finds
	 * the volume already sealed).
	 *
	 * @return bool
	 */
	public function has_open_volume(): bool {
		return null !== $this->state['volume'];
	}

	/**
	 * Begin a file entry in the open volume, which must have room for it
	 * (has_room()); otherwise SealRequired is thrown and the caller seals
	 * and opens explicitly. Nothing is sealed or created here, and nothing
	 * of the file is copied yet.
	 *
	 * @param string $source     Absolute path of the file to add.
	 * @param string $entry_path Path inside the archive (forward slashes, relative).
	 * @param int    $mtime      Modification time to record.
	 * @return void
	 * @throws \RuntimeException When an entry is already open, no volume is open or the path is invalid.
	 * @throws SourceGone When the file is missing or cannot be read.
	 * @throws SealRequired When the open volume cannot take the entry (seal it and open the next one first).
	 */
	public function add_entry( string $source, string $entry_path, int $mtime ): void {
		if ( $this->has_open_entry() ) {
			throw new \RuntimeException( 'An entry is still in progress.' );
		}
		$problem = EntryPath::problem( $entry_path );
		if ( null !== $problem ) {
			throw new \RuntimeException( 'Invalid entry path: ' . $problem );
		}
		if ( 1 !== preg_match( '//u', $entry_path ) ) {
			// Checked before any byte is written: the name is flagged UTF-8 in the archive and json_encode()
			// would refuse it only after the header and the data were written, failing the whole export.
			throw new \RuntimeException( 'Invalid entry path: not valid UTF-8.' );
		}
		$stats = @stat( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.
		if ( false === $stats || ! is_file( $source ) || ! is_readable( $source ) ) {
			throw new SourceGone( 'The source file is missing or cannot be read.' );
		}
		$size = (int) $stats['size'];
		if ( $size > self::max_entry_bytes() ) {
			throw new \RuntimeException( 'The file is larger than this platform can archive.' );
		}
		$this->assert_room( $size );
		$method = $this->options['can_deflate'] && $size <= $this->options['deflate_max_bytes'] && $size > 0 ? ZipFormat::METHOD_DEFLATE : ZipFormat::METHOD_STORE;
		$this->begin_entry( $entry_path, $mtime, $method, $size, $source );
	}

	/**
	 * Add a small in-memory entry (the embedded manifest) in one go.
	 *
	 * @param string $entry_path Path inside the archive.
	 * @param string $data       Content (at most deflate_max_bytes).
	 * @param int    $mtime      Modification time to record.
	 * @return void
	 * @throws \RuntimeException When too large or an entry is open.
	 */
	public function add_string_entry( string $entry_path, string $data, int $mtime ): void {
		if ( strlen( $data ) > $this->options['deflate_max_bytes'] ) {
			throw new \RuntimeException( 'A string entry must fit one piece; write larger content to a file first.' );
		}
		$temp = tempnam( $this->dir, 'wpcheckpoint-entry-' );
		if ( false === $temp || false === file_put_contents( $temp, $data ) ) {
			throw new \RuntimeException( 'The entry could not be staged.' );
		}
		try {
			$this->add_entry( $temp, $entry_path, $mtime );
			while ( $this->write_piece() > 0 ) {
				continue;
			}
		} finally {
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort.
		}
	}

	/**
	 * Copy at most one piece of the open entry into the volume. When the
	 * entry's last byte is written the local header is patched and the
	 * entry is recorded; the next call returns 0.
	 *
	 * @param int|null      $max_bytes Piece size; null for the configured one.
	 * @param callable|null $observer  Receives the source bytes of the piece as they are read, so a content
	 *                                 hash can be fed from the same read; the caller keeps the hash context
	 *                                 within one unit of work, it never survives a tick.
	 * @return int Bytes of source consumed by this call (0 when the entry is complete or none is open).
	 * @throws SourceChanged When the source no longer holds the bytes the entry declared.
	 * @throws InsufficientSpace When the disk is full.
	 */
	public function write_piece( $max_bytes = null, $observer = null ): int {
		if ( ! $this->has_open_entry() ) {
			return 0;
		}
		$max = null === $max_bytes ? (int) $this->options['piece_bytes'] : max( 1, (int) $max_bytes );
		if ( $this->state['entry']['offset'] >= $this->state['entry']['size'] ) {
			$this->end_entry();
			return 0;
		}
		$this->open_source();
		if ( ZipFormat::METHOD_DEFLATE === $this->state['entry']['method'] ) {
			// Small entries only (deflate_max_bytes): read, deflate and write in one piece.
			$size = (int) $this->state['entry']['size'];
			$data = $this->read_source( 0, $size );
			if ( is_callable( $observer ) ) {
				$observer( $data );
			}
			$out = gzdeflate( $data, self::COMPRESSION_LEVEL );
			if ( ! is_string( $out ) ) {
				throw new \RuntimeException( 'Compression failed.' );
			}
			$this->write_volume( $out );
			$this->state['entry']['crc']     = Crc32::of( $data );
			$this->state['entry']['csize']   = strlen( $out );
			$this->state['entry']['offset']  = $size;
			$this->state['entry']['written'] = strlen( $out );
			$this->end_entry();
			return $size;
		}
		$length = (int) min( $max, $this->state['entry']['size'] - $this->state['entry']['offset'] );
		$data   = $this->read_source( (int) $this->state['entry']['offset'], $length );
		if ( is_callable( $observer ) ) {
			$observer( $data );
		}
		$this->write_volume( $data );
		$this->state['entry']['crc']      = Crc32::combine( (int) $this->state['entry']['crc'], Crc32::of( $data ), strlen( $data ) );
		$this->state['entry']['offset']  += strlen( $data );
		$this->state['entry']['written'] += strlen( $data );
		$this->state['entry']['csize']    = $this->state['entry']['written'];
		if ( $this->state['entry']['offset'] >= $this->state['entry']['size'] ) {
			$this->end_entry();
		}
		return strlen( $data );
	}

	/**
	 * Drop the entry in progress from the state: the volume's committed
	 * length goes back to the entry's header and the entry is gone. The
	 * file is not touched (see inside); the caller persists the state and
	 * the next open() cuts the volume back. The same or another entry can
	 * then be added at the same place.
	 *
	 * @return void
	 */
	public function abort_entry(): void {
		if ( ! $this->has_open_entry() ) {
			return;
		}
		$this->close_source();
		// The state moves back to the entry's header; the bytes after it stay on disk until the next
		// open() resumes from this state and cuts the volume to the committed length. Cutting here
		// would put the file behind the state: a crash between the cut and the checkpoint would leave
		// a committed length longer than the file, which resume() refuses.
		$this->state['volume']['bytes'] = (int) $this->state['entry']['header_offset'];
		$this->state['entry']           = null;
		$this->seek_volume( (int) $this->state['volume']['bytes'] );
	}

	/**
	 * Finish the open volume: central directory, end record, fsync, rename
	 * to the final name. Returns the sealed volume record.
	 *
	 * @return array{index: int, path: string, bytes: int, chunks: string[], hashed: int}
	 * @throws \RuntimeException When an entry is open or nothing is open.
	 */
	public function seal_volume(): array {
		if ( $this->has_open_entry() ) {
			throw new \RuntimeException( 'An entry is still in progress.' );
		}
		if ( null === $this->state['volume'] ) {
			throw new \RuntimeException( 'No volume is open.' );
		}
		$this->open_volume_handle();
		$volume    = $this->state['volume'];
		$cd_offset = (int) $volume['bytes'];
		$cd_size   = 0;
		// An entry aborted in this same run left its bytes after the committed length (abort_entry() does not cut);
		// the central directory must start exactly at the committed length, so cut here.
		$this->truncate_volume( $cd_offset );
		$records = fopen( $this->records_path(), 'rb' );
		if ( false === $records ) {
			throw new \RuntimeException( 'The central directory records cannot be read.' );
		}
		try {
			$this->seek_volume( $cd_offset );
			$count = 0;
			while ( ! feof( $records ) ) {
				$line = fgets( $records );
				if ( false === $line || '' === trim( $line ) ) {
					continue;
				}
				$record = json_decode( $line, true );
				if ( ! is_array( $record ) ) {
					throw new \RuntimeException( 'A central directory record is unreadable.' );
				}
				$header = ZipFormat::central_header( $record );
				$this->write_volume( $header );
				$cd_size += strlen( $header );
				++$count;
				if ( $count >= (int) $volume['entries'] ) {
					break;
				}
			}
		} finally {
			fclose( $records );
		}
		if ( $count !== (int) $volume['entries'] ) {
			throw new \RuntimeException( 'The central directory records do not match the entry count.' );
		}
		$this->write_volume( ZipFormat::end_of_central_directory( $count, $cd_size, $cd_offset ) );
		$bytes = $cd_offset + $cd_size + strlen( ZipFormat::end_of_central_directory( $count, $cd_size, $cd_offset ) );
		fflush( $this->handle );
		if ( function_exists( 'fsync' ) ) {
			fsync( $this->handle );
		}
		fclose( $this->handle );
		$this->handle = null;
		$final        = $this->dir . DIRECTORY_SEPARATOR . $volume['name'];
		if ( file_exists( $final ) ) {
			// resume() would have adopted our own sealed volume; a file here now belongs to someone else.
			throw new \RuntimeException( 'A volume with this name already exists.' );
		}
		// The commit point. A crash between this rename and the step's checkpoint leaves a cursor that
		// still says "open"; resume() recognises the sealed file and carries on (see adopt_sealed_volume()).
		$this->confirm();
		if ( ! rename( $this->partial_path(), $final ) ) {
			throw new \RuntimeException( 'The volume could not be renamed to its final name.' );
		}
		@unlink( $this->records_path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort.
		$sealed                  = array(
			'index'  => (int) $volume['index'],
			'path'   => $volume['name'],
			'bytes'  => $bytes,
			'chunks' => array(),
			'hashed' => 0,
		);
		$this->state['sealed'][] = $sealed;
		$this->state['volume']   = null;
		return $sealed;
	}

	/**
	 * Hash one container chunk of a sealed volume that still has unhashed
	 * chunks. One chunk (volume_chunk_bytes) per call.
	 *
	 * @return bool True when there is more to hash after this call.
	 * @throws \RuntimeException When a sealed volume cannot be read.
	 */
	public function hash_next_block(): bool {
		$chunk = (int) $this->options['volume_chunk_bytes'];
		foreach ( $this->state['sealed'] as $i => $sealed ) {
			$total = ChunkHasher::chunk_count( (int) $sealed['bytes'], $chunk );
			if ( (int) $sealed['hashed'] >= $total ) {
				continue;
			}
			$path                                    = $this->dir . DIRECTORY_SEPARATOR . $sealed['path'];
			$index                                   = (int) $sealed['hashed'];
			$this->state['sealed'][ $i ]['chunks'][] = ChunkHasher::hash_chunk( $path, $index, $chunk );
			$this->state['sealed'][ $i ]['hashed']   = $index + 1;
			return $this->has_unhashed_blocks();
		}
		return false;
	}

	/**
	 * Whether hash_next_block() has work left.
	 *
	 * @return bool
	 */
	public function has_unhashed_blocks(): bool {
		$chunk = (int) $this->options['volume_chunk_bytes'];
		foreach ( $this->state['sealed'] as $sealed ) {
			if ( (int) $sealed['hashed'] < ChunkHasher::chunk_count( (int) $sealed['bytes'], $chunk ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Decide where the summaries go, before the embedded manifest is
	 * assembled: the open volume keeps them when it has room for
	 * $summary_bytes (the index files, the manifest and their headers),
	 * otherwise it is sealed here and finish() opens a new one. The
	 * embedded copy then lists exactly the sealed volumes, which is every
	 * volume but the one holding it. Sealing here is a checkpointed unit
	 * of its own: a crash after the rename is adopted by resume() and a
	 * second call finds nothing to seal.
	 *
	 * @param int $summary_bytes Bytes the summaries will take, with their margin.
	 * @return bool True when the open volume was sealed.
	 * @throws \RuntimeException When an entry is open.
	 */
	public function prepare_finish( int $summary_bytes ): bool {
		if ( $this->has_open_entry() ) {
			throw new \RuntimeException( 'An entry is still in progress.' );
		}
		$this->state['prepared'] = true;
		$volume                  = $this->state['volume'];
		if ( null === $volume ) {
			return false;
		}
		$sealed = (int) $volume['entries'] > 0 && (
			(int) $volume['bytes'] + $summary_bytes > (int) $this->options['volume_bytes']
			|| (int) $volume['bytes'] + $summary_bytes + 65536 > (int) $this->options['max_volume_bytes']
			|| (int) $volume['entries'] + count( self::SUMMARY_ENTRIES ) > self::MAX_VOLUME_ENTRIES
		);
		if ( $sealed ) {
			$this->seal_volume();
		}
		return $sealed;
	}

	/**
	 * Append the summaries and seal the last volume: the index files given
	 * here (the caller may have added them piece by piece already and pass
	 * none), then the embedded manifest, into the volume prepare_finish()
	 * left room in, or a new one. Idempotent across a crash before the
	 * step's checkpoint: a last volume already ending with the manifest is
	 * recognised, whether it was the open volume (adopted by resume()) or
	 * a new one created here.
	 *
	 * @param array<string, string> $files    Entry path => source file, in order.
	 * @param string                $manifest Embedded manifest JSON.
	 * @param int                   $mtime    Modification time for the entries.
	 * @return void
	 * @throws \RuntimeException When an entry is open, prepare_finish() did not run, no volume is open, or a file cannot be read.
	 * @throws SealRequired When the open volume cannot take the summaries (prepare_finish() made sure it can).
	 */
	public function finish( array $files, string $manifest, int $mtime ): void {
		if ( $this->has_open_entry() ) {
			throw new \RuntimeException( 'An entry is still in progress.' );
		}
		if ( $this->state['finished'] ) {
			return;
		}
		if ( empty( $this->state['prepared'] ) ) {
			throw new \RuntimeException( 'prepare_finish() decides the last volume before finish().' );
		}
		if ( $this->already_finished() ) {
			// A previous run sealed the last volume and died before its checkpoint: the open volume with the
			// summaries appended (adopted by resume()), or a new volume made for them (adopted here).
			$this->rename_single_volume();
			$this->state['finished'] = true;
			return;
		}
		foreach ( $files as $entry_path => $source ) {
			$this->add_entry( $source, (string) $entry_path, $mtime );
			while ( $this->write_piece() > 0 ) {
				continue;
			}
		}
		$this->assert_room( strlen( $manifest ) );
		$this->add_string_entry( 'manifest.json', $manifest, $mtime );
		$this->seal_volume();
		$this->rename_single_volume();
		$this->state['finished'] = true;
	}

	/**
	 * Whether the archive is already finished on disk although the state
	 * does not say so: no volume open and the last sealed volume ends with
	 * the embedded manifest, or a sealed volume after the last recorded
	 * one holds nothing but summaries (adopted into the state here). A
	 * step that appends the summaries itself asks this first so that a
	 * replay does not append them a second time into a new volume.
	 *
	 * @return bool
	 */
	public function already_finished(): bool {
		if ( $this->state['finished'] ) {
			return true;
		}
		if ( null !== $this->state['volume'] ) {
			return false;
		}
		if ( array() !== $this->state['sealed'] && $this->last_volume_has_summaries() ) {
			return true;
		}
		return $this->adopt_summary_volume();
	}

	/**
	 * A sealed volume after the last recorded one that holds nothing but
	 * summary entries is this archive's last volume, written by a run that
	 * died between its seal and the checkpoint. Record it as sealed.
	 *
	 * @return bool True when such a volume was adopted.
	 */
	private function adopt_summary_volume(): bool {
		$index      = count( $this->state['sealed'] ) + 1;
		$candidates = array( sprintf( self::VOLUME_NAME_PATTERN, $this->state['base'], $index ) );
		if ( 1 === $index ) {
			$candidates[] = $this->state['base'] . self::SINGLE_SUFFIX;
		}
		foreach ( $candidates as $name ) {
			$final = $this->dir . DIRECTORY_SEPARATOR . $name;
			if ( ! is_file( $final ) ) {
				continue;
			}
			try {
				$reader = ZipReader::open( $final );
			} catch ( \RuntimeException $e ) {
				return false;
			}
			$names = array_column( $reader->entries(), 'name' );
			if ( array() === $names || count( $names ) !== count( array_unique( $names ) ) || array() !== array_diff( $names, self::SUMMARY_ENTRIES ) || ! in_array( 'manifest.json', $names, true ) ) {
				return false;
			}
			$this->state['sealed'][] = array(
				'index'  => $index,
				'path'   => $name,
				'bytes'  => (int) filesize( $final ),
				'chunks' => array(),
				'hashed' => 0,
			);
			return true;
		}
		return false;
	}

	/**
	 * Whether the last sealed volume already ends with the embedded manifest.
	 *
	 * @return bool
	 */
	private function last_volume_has_summaries(): bool {
		$i    = count( $this->state['sealed'] ) - 1;
		$last = $this->state['sealed'][ $i ];
		$path = $this->dir . DIRECTORY_SEPARATOR . $last['path'];
		if ( 0 === $i && ! is_file( $path ) && is_file( $this->dir . DIRECTORY_SEPARATOR . $this->state['base'] . self::SINGLE_SUFFIX ) ) {
			// finish() renamed the lone volume to the single name and died before the checkpoint.
			$path = $this->dir . DIRECTORY_SEPARATOR . $this->state['base'] . self::SINGLE_SUFFIX;
			try {
				if ( null !== ZipReader::open( $path )->find( 'manifest.json' ) ) {
					$this->state['sealed'][0]['path'] = $this->state['base'] . self::SINGLE_SUFFIX;
					return true;
				}
			} catch ( \RuntimeException $e ) {
				return false;
			}
			return false;
		}
		try {
			$reader = ZipReader::open( $path );
		} catch ( \RuntimeException $e ) {
			return false;
		}
		return null !== $reader->find( 'manifest.json' );
	}

	/**
	 * A lone volume takes the single-volume name (idempotent: already done
	 * when the sealed record carries that name).
	 *
	 * @return void
	 * @throws \RuntimeException When the rename fails or the name is taken by another file.
	 */
	private function rename_single_volume(): void {
		if ( 1 !== count( $this->state['sealed'] ) ) {
			return;
		}
		$single = $this->state['base'] . self::SINGLE_SUFFIX;
		if ( $this->state['sealed'][0]['path'] === $single ) {
			return;
		}
		$from = $this->dir . DIRECTORY_SEPARATOR . $this->state['sealed'][0]['path'];
		$to   = $this->dir . DIRECTORY_SEPARATOR . $single;
		if ( file_exists( $to ) ) {
			throw new \RuntimeException( 'The volume could not be renamed to its single-volume name.' );
		}
		$this->confirm();
		if ( ! rename( $from, $to ) ) {
			throw new \RuntimeException( 'The volume could not be renamed to its single-volume name.' );
		}
		$this->state['sealed'][0]['path'] = $single;
	}

	/**
	 * Manifest entries of the sealed volumes (path, bytes, sha256 and the
	 * chunk list above volume_chunk_bytes), in order. Called after
	 * prepare_finish() and before finish() this is exactly what the
	 * embedded copy lists: every volume but the one that will hold it,
	 * which is still open (or not yet created) at that point.
	 *
	 * @return array<int, array{path: string, bytes: int, chunks?: string[], sha256: string}>
	 * @throws \RuntimeException When a sealed volume is not fully hashed yet.
	 */
	public function volume_entries(): array {
		$chunk = (int) $this->options['volume_chunk_bytes'];
		$out   = array();
		foreach ( $this->state['sealed'] as $sealed ) {
			$total = ChunkHasher::chunk_count( (int) $sealed['bytes'], $chunk );
			if ( (int) $sealed['hashed'] < $total ) {
				throw new \RuntimeException( 'A volume is not hashed yet.' );
			}
			$entry = array(
				'path'  => $sealed['path'],
				'bytes' => (int) $sealed['bytes'],
			);
			if ( (int) $sealed['bytes'] > $chunk ) {
				$entry['chunks'] = $sealed['chunks'];
				$entry['sha256'] = ChunkHasher::list_hash( $sealed['chunks'] );
			} else {
				$entry['sha256'] = 1 === $total ? $sealed['chunks'][0] : hash( 'sha256', '' );
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * Paths of the sealed volumes.
	 *
	 * @return string[]
	 */
	public function sealed_paths(): array {
		$out = array();
		foreach ( $this->state['sealed'] as $sealed ) {
			$out[] = $this->dir . DIRECTORY_SEPARATOR . $sealed['path'];
		}
		return $out;
	}

	/**
	 * Remove the open volume and its records (cancel). Sealed volumes stay.
	 *
	 * @return void
	 */
	public function discard_open_volume(): void {
		$this->close_source();
		if ( null !== $this->handle ) {
			fclose( $this->handle );
			$this->handle = null;
		}
		foreach ( array( $this->partial_path(), $this->records_path() ) as $path ) {
			if ( is_file( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort.
			}
		}
		$this->state['volume'] = null;
		$this->state['entry']  = null;
	}

	/**
	 * Close handles without changing state (end of a tick).
	 *
	 * @return void
	 */
	public function close(): void {
		$this->high_water = null;
		$this->close_source();
		if ( null !== $this->handle ) {
			fflush( $this->handle );
			fclose( $this->handle );
			$this->handle = null;
		}
	}

	/**
	 * Flush the open volume to the OS (before a checkpoint).
	 *
	 * @return void
	 */
	public function flush(): void {
		if ( null !== $this->handle ) {
			fflush( $this->handle );
		}
	}

	/**
	 * Truncate the open volume and its records back to the committed state.
	 *
	 * @return void
	 * @throws \RuntimeException When the committed bytes are not on disk any more.
	 */
	private function resume(): void {
		if ( null === $this->state['volume'] ) {
			return;
		}
		$partial = $this->partial_path();
		if ( ! is_file( $partial ) ) {
			if ( $this->adopt_sealed_volume() ) {
				return;
			}
			throw new \RuntimeException( 'The open volume is missing.' );
		}
		$actual = (int) filesize( $partial );
		$entry  = $this->state['entry'];
		// Committed bytes: the completed entries, plus the open entry's header and the pieces written so far.
		$committed = null === $entry ? (int) $this->state['volume']['bytes'] : (int) $entry['data_offset'] + (int) $entry['written'];
		if ( $actual < $committed ) {
			// A committed length is only ever recorded after the bytes are on disk, so a shorter file means the
			// work directory was changed (or the OS dropped what it had acknowledged). Never pad it: the
			// zeros would be archived as data.
			throw new \RuntimeException( 'The volume is shorter than its recorded committed length; the work directory was changed or damaged.' );
		}
		$this->truncate_volume( $committed );
		$this->truncate_records( (int) $this->state['volume']['entries'] );
	}

	/**
	 * The volume the state calls open was sealed by a tick that died between
	 * the rename and its checkpoint. Recognise it: the final file exists, is
	 * a readable zip, and its central directory starts at the committed
	 * byte count with the committed number of entries. Then record it as
	 * sealed so the step replays from here. Sealing is idempotent this way,
	 * as every step must be.
	 *
	 * @return bool True when the volume was adopted.
	 */
	private function adopt_sealed_volume(): bool {
		$volume = $this->state['volume'];
		if ( null !== $this->state['entry'] ) {
			return false; // A volume is never sealed with an entry in progress.
		}
		// finish() seals the last volume after appending the summary entries and renames a lone volume to
		// the single name; a crash before the step's checkpoint leaves either outcome on disk.
		$candidates = array( $volume['name'] );
		if ( 1 === (int) $volume['index'] && array() === $this->state['sealed'] ) {
			$candidates[] = $this->state['base'] . self::SINGLE_SUFFIX;
		}
		foreach ( $candidates as $name ) {
			$final = $this->dir . DIRECTORY_SEPARATOR . $name;
			if ( ! is_file( $final ) ) {
				continue;
			}
			try {
				$reader = ZipReader::open( $final );
			} catch ( \RuntimeException $e ) {
				return false;
			}
			$entries = (int) $volume['entries'];
			$count   = $reader->count();
			if ( $count < $entries || $count - $entries > count( self::SUMMARY_ENTRIES ) || $reader->central_directory_offset() < (int) $volume['bytes'] ) {
				return false;
			}
			if ( $count === $entries && $reader->central_directory_offset() !== (int) $volume['bytes'] ) {
				return false;
			}
			// Any entries beyond the committed count must be exactly the summary files, nothing else.
			$extra = array();
			$reader->each(
				static function ( array $entry ) use ( $entries, &$extra ): bool {
					if ( $entry['index'] >= $entries ) {
						$extra[] = $entry['name'];
					}
					return true;
				}
			);
			if ( count( $extra ) !== count( array_unique( $extra ) ) || array() !== array_diff( $extra, self::SUMMARY_ENTRIES ) ) {
				return false;
			}
			@unlink( $this->records_path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the unlink after the rename may not have happened.
			$this->state['sealed'][] = array(
				'index'  => (int) $volume['index'],
				'path'   => $name,
				'bytes'  => (int) filesize( $final ),
				'chunks' => array(),
				'hashed' => 0,
			);
			$this->state['volume']   = null;
			return true;
		}
		return false;
	}

	/**
	 * The caller's lease check (option "confirm"), called immediately before
	 * each irreversible transition (creating a volume file, renaming one)
	 * with nothing in between: a run that lost its lease throws here and the
	 * transition does not happen. No-op without the option (tests, tools).
	 *
	 * @return void
	 */
	private function confirm(): void {
		if ( isset( $this->options['confirm'] ) && is_callable( $this->options['confirm'] ) ) {
			call_user_func( $this->options['confirm'] );
		}
	}

	/**
	 * Whether the open volume can take an entry of this size: it is open,
	 * and either empty (an entry never spans volumes, so an empty volume
	 * takes anything) or below the volume size, the platform bound and the
	 * entry count. The caller seals and opens explicitly when it cannot.
	 *
	 * @param int $next_entry_bytes Size of the entry about to be added.
	 * @return bool
	 */
	public function has_room( int $next_entry_bytes ): bool {
		$volume = $this->state['volume'];
		if ( null === $volume ) {
			return false;
		}
		if ( 0 === (int) $volume['entries'] ) {
			return true;
		}
		if ( (int) $volume['bytes'] >= (int) $this->options['volume_bytes'] ) {
			return false;
		}
		if ( (int) $volume['bytes'] + $next_entry_bytes + 65536 > (int) $this->options['max_volume_bytes'] ) {
			// Every entry may fit the platform on its own while the volume would not.
			return false;
		}
		return (int) $volume['entries'] < self::MAX_VOLUME_ENTRIES;
	}

	/**
	 * Create the next volume. Only the caller does this, as a unit it
	 * checkpoints: the packer never creates a file as a side effect of
	 * adding an entry. Leftovers of this volume from a run that died
	 * before its first checkpoint are replaced; a final file under the
	 * volume's name belongs to someone else and is refused.
	 *
	 * @param int $next_entry_bytes Size of the entry about to be added (free-space check).
	 * @return void
	 * @throws InsufficientSpace When the disk is full.
	 * @throws \RuntimeException When a volume is open, or the operation fails (message says what).
	 */
	public function open_volume( int $next_entry_bytes = 0 ): void {
		if ( null !== $this->state['volume'] ) {
			throw new \RuntimeException( 'A volume is already open.' );
		}
		$index                 = count( $this->state['sealed'] ) + 1;
		$name                  = sprintf( self::VOLUME_NAME_PATTERN, $this->state['base'], $index );
		$this->state['volume'] = array(
			'index'   => $index,
			'name'    => $name,
			'bytes'   => 0,
			'entries' => 0,
		);
		if ( file_exists( $this->dir . DIRECTORY_SEPARATOR . $name ) ) {
			throw new \RuntimeException( 'A file of the new volume already exists.' );
		}
		foreach ( array( $this->partial_path(), $this->records_path() ) as $path ) {
			// Left by a run that created this volume and died before its first checkpoint: nothing of it is
			// committed, and no other writer uses this base name (it carries a random suffix).
			if ( file_exists( $path ) && ! @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- failure is thrown.
				throw new \RuntimeException( 'A leftover file of the new volume could not be removed.' );
			}
		}
		$this->check_space( $next_entry_bytes );
		$this->confirm();
		$handle = fopen( $this->partial_path(), 'w+b' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'The volume file could not be created.' );
		}
		$this->handle     = $handle;
		$this->high_water = 0;
		if ( false === file_put_contents( $this->records_path(), '' ) ) {
			throw new \RuntimeException( 'The record file could not be created.' );
		}
	}

	/**
	 * The open volume must exist and have room; nothing is sealed or
	 * created here.
	 *
	 * @param int $next_entry_bytes Size of the entry about to be added.
	 * @return void
	 * @throws SealRequired When the open volume cannot take the entry.
	 * @throws \RuntimeException When no volume is open.
	 */
	private function assert_room( int $next_entry_bytes ): void {
		if ( null === $this->state['volume'] ) {
			throw new \RuntimeException( 'No volume is open; open_volume() first.' );
		}
		if ( ! $this->has_room( $next_entry_bytes ) ) {
			throw new SealRequired( 'The open volume cannot take this entry; seal it and open the next one.' );
		}
	}

	/**
	 * Write the local header and set up the entry state.
	 *
	 * @param string $entry_path Entry path.
	 * @param int    $mtime      Modification time.
	 * @param int    $method     Method.
	 * @param int    $size       Source size.
	 * @param string $source     Source path.
	 * @return void
	 */
	private function begin_entry( string $entry_path, int $mtime, int $method, int $size, string $source ): void {
		$this->open_volume_handle();
		$offset = (int) $this->state['volume']['bytes'];
		$zip64  = $size >= $this->options['zip64_threshold'] || $offset >= $this->options['zip64_threshold'];
		$header = ZipFormat::local_header( $entry_path, $method, $mtime, 0, 0, 0, $zip64 );
		$this->seek_volume( $offset );
		$this->write_volume( $header );
		$this->state['entry'] = array(
			'name'          => $entry_path,
			'source'        => $source,
			'size'          => $size,
			'mtime'         => $mtime,
			'method'        => $method,
			'zip64'         => $zip64,
			'header_offset' => $offset,
			'data_offset'   => $offset + strlen( $header ),
			'offset'        => 0,
			'written'       => 0,
			'crc'           => 0,
			'csize'         => 0,
		);
	}

	/**
	 * Patch the local header, record the entry, and commit it to the volume state.
	 *
	 * @return void
	 * @throws \RuntimeException When the operation fails (message says what).
	 */
	private function end_entry(): void {
		$entry = $this->state['entry'];
		$this->close_source();
		$patch = ZipFormat::patch_offsets( strlen( $entry['name'] ), (bool) $entry['zip64'] );
		$this->seek_volume( $entry['header_offset'] + $patch['crc'] );
		$this->write_volume( Crc32::pack( (int) $entry['crc'] ) );
		if ( $entry['zip64'] ) {
			$this->seek_volume( $entry['header_offset'] + $patch['sizes'] );
			$this->write_volume( pack( 'VV', ZipFormat::LIMIT_32, ZipFormat::LIMIT_32 ) );
			$this->seek_volume( $entry['header_offset'] + $patch['extra'] );
			$this->write_volume( ZipFormat::u64( (int) $entry['size'] ) . ZipFormat::u64( (int) $entry['csize'] ) );
		} else {
			$this->seek_volume( $entry['header_offset'] + $patch['sizes'] );
			$this->write_volume( pack( 'VV', (int) $entry['csize'], (int) $entry['size'] ) );
		}
		$end = (int) $entry['data_offset'] + (int) $entry['csize'];
		$this->seek_volume( $end );
		$record = array(
			'name'   => $entry['name'],
			'method' => (int) $entry['method'],
			'mtime'  => (int) $entry['mtime'],
			'crc'    => (int) $entry['crc'],
			'csize'  => (int) $entry['csize'],
			'usize'  => (int) $entry['size'],
			'offset' => (int) $entry['header_offset'],
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure PHP class.
		$line = json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $line ) || false === file_put_contents( $this->records_path(), $line . "\n", FILE_APPEND | LOCK_EX ) ) {
			throw new \RuntimeException( 'The central directory record could not be written.' );
		}
		$this->state['volume']['bytes'] = $end;
		++$this->state['volume']['entries'];
		$this->state['entry'] = null;
		fflush( $this->handle );
	}

	/**
	 * Free-space check against the injected reader; unknown values do not block.
	 *
	 * @param int $needed Bytes about to be written.
	 * @return void
	 * @throws InsufficientSpace When the disk is full.
	 */
	private function check_space( int $needed ): void {
		$free = call_user_func( $this->options['disk_free'], $this->dir );
		if ( ! is_int( $free ) && ! is_float( $free ) ) {
			return;
		}
		if ( $free < $needed + self::SPACE_MARGIN_BYTES ) {
			throw new InsufficientSpace( 'Not enough free disk space to write the archive.' );
		}
		$this->since_space_check = 0;
	}

	/**
	 * Write to the open volume; a short write means the disk is full.
	 *
	 * @param string $data Bytes.
	 * @return void
	 * @throws InsufficientSpace When the write is short.
	 */
	private function write_volume( string $data ): void {
		$this->open_volume_handle();
		$this->assert_sole_writer();
		$written = fwrite( $this->handle, $data );
		if ( false === $written || strlen( $data ) !== $written ) {
			throw new InsufficientSpace( 'The volume could not be written completely (disk full?).' );
		}
		$position                 = ftell( $this->handle );
		$this->high_water         = max( (int) $this->high_water, false === $position ? 0 : $position );
		$this->since_space_check += $written;
		if ( $this->since_space_check >= self::SPACE_CHECK_BYTES ) {
			$this->check_space( (int) $this->options['piece_bytes'] );
		}
	}

	/**
	 * Seek the open volume.
	 *
	 * @param int $offset Offset.
	 * @return void
	 * @throws \RuntimeException When the operation fails (message says what).
	 */
	private function seek_volume( int $offset ): void {
		$this->open_volume_handle();
		if ( 0 !== fseek( $this->handle, $offset ) ) {
			throw new \RuntimeException( 'The volume could not be positioned.' );
		}
	}

	/**
	 * Truncate the open volume to $bytes.
	 *
	 * @param int $bytes Length.
	 * @return void
	 * @throws \RuntimeException When the operation fails (message says what).
	 */
	private function truncate_volume( int $bytes ): void {
		$this->open_volume_handle();
		$this->assert_sole_writer();
		if ( ! ftruncate( $this->handle, $bytes ) ) {
			throw new \RuntimeException( 'The volume could not be truncated.' );
		}
		$this->high_water = $bytes;
		$this->seek_volume( $bytes );
	}

	/**
	 * The open volume is as long as this process left it: its length at
	 * open, extended only by this process's writes and cut only by its own
	 * truncation. Any other length means another process writes the same
	 * file (a run that outlived its lease while this one took over). That
	 * process's writes beyond this one's are caught here; its overwrites of
	 * bytes inside this one's range change no length and are not (a known
	 * limit of a directory without a file-level fence).
	 *
	 * @return void
	 * @throws ConcurrentWriter When the length is not what this process wrote.
	 */
	private function assert_sole_writer(): void {
		if ( null === $this->high_water || null === $this->handle ) {
			return;
		}
		$stat = fstat( $this->handle );
		if ( is_array( $stat ) && (int) $stat['size'] !== (int) $this->high_water ) {
			throw new ConcurrentWriter( 'Another process is writing the same work directory (a previous run outlived its lease); this run stops without touching the volume.' );
		}
	}

	/**
	 * Keep only the first $count records.
	 *
	 * @param int $count Records to keep.
	 * @return void
	 * @throws \RuntimeException When the operation fails (message says what).
	 */
	private function truncate_records( int $count ): void {
		$path = $this->records_path();
		if ( ! is_file( $path ) ) {
			if ( 0 !== $count ) {
				throw new \RuntimeException( 'The central directory records are missing.' );
			}
			file_put_contents( $path, '' );
			return;
		}
		$handle = fopen( $path, 'r+b' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'The central directory records cannot be opened.' );
		}
		try {
			$seen = 0;
			$keep = 0;
			while ( $seen < $count ) {
				$line = fgets( $handle );
				if ( false === $line ) {
					throw new \RuntimeException( 'The central directory records are incomplete.' );
				}
				if ( "\n" !== substr( $line, -1 ) ) {
					throw new \RuntimeException( 'The central directory records are incomplete.' );
				}
				++$seen;
				$keep = (int) ftell( $handle );
			}
			if ( ! ftruncate( $handle, $keep ) ) {
				// Stale records would otherwise be written into the central directory, corrupting the volume silently.
				throw new \RuntimeException( 'The central directory records could not be truncated.' );
			}
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Open the partial volume for read/write when not open.
	 *
	 * @return void
	 * @throws \RuntimeException When the operation fails (message says what).
	 */
	private function open_volume_handle(): void {
		if ( null !== $this->handle ) {
			return;
		}
		if ( null === $this->state['volume'] ) {
			throw new \RuntimeException( 'No volume is open.' );
		}
		$handle = fopen( $this->partial_path(), 'r+b' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'The volume file could not be opened.' );
		}
		$this->handle     = $handle;
		$stat             = fstat( $handle );
		$this->high_water = is_array( $stat ) ? (int) $stat['size'] : null;
	}

	/**
	 * Open the entry's source, checking it did not change.
	 *
	 * @return void
	 * @throws \RuntimeException When the source changed size.
	 */
	private function open_source(): void {
		if ( null !== $this->source ) {
			return;
		}
		$entry = $this->state['entry'];
		clearstatcache( true, $entry['source'] );
		if ( ! is_file( $entry['source'] ) ) {
			throw new SourceGone( 'The source file is missing or cannot be read.' );
		}
		if ( (int) filesize( $entry['source'] ) !== (int) $entry['size'] ) {
			throw new SourceChanged( 'The source file changed while it was being archived.' );
		}
		$handle = @fopen( $entry['source'], 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- a warning would put the path into the error log; failure is thrown.
		if ( false === $handle ) {
			throw new SourceGone( 'The source file is missing or cannot be read.' );
		}
		$this->source = $handle;
	}

	/**
	 * Read a range of the source.
	 *
	 * @param int $offset Offset.
	 * @param int $length Length.
	 * @return string
	 * @throws \RuntimeException When the operation fails (message says what).
	 */
	private function read_source( int $offset, int $length ): string {
		if ( 0 === $length ) {
			return '';
		}
		if ( 0 !== fseek( $this->source, $offset ) ) {
			throw new \RuntimeException( 'The source file could not be positioned.' );
		}
		$data = '';
		$left = $length;
		while ( $left > 0 ) {
			$piece = fread( $this->source, (int) min( 1048576, $left ) );
			if ( false === $piece || '' === $piece ) {
				throw new SourceChanged( 'The source file changed while it was being archived.' );
			}
			$data .= $piece;
			$left -= strlen( $piece );
		}
		return $data;
	}

	/**
	 * Close the source handle.
	 *
	 * @return void
	 */
	private function close_source(): void {
		if ( null !== $this->source ) {
			fclose( $this->source );
			$this->source = null;
		}
	}

	/**
	 * Path of the open volume's partial file.
	 *
	 * @return string
	 */
	private function partial_path(): string {
		return $this->dir . DIRECTORY_SEPARATOR . $this->state['volume']['name'] . self::PARTIAL_SUFFIX;
	}

	/**
	 * Path of the open volume's record file.
	 *
	 * @return string
	 */
	private function records_path(): string {
		return $this->dir . DIRECTORY_SEPARATOR . $this->state['volume']['name'] . self::RECORDS_SUFFIX;
	}
}
