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
 * Volumes are sealed between entries once they reach the volume size; an
 * entry never spans volumes, so a volume may exceed the size by its last
 * entry. The open volume is "<name>.partial" with its central directory
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
	 * @param array<string, mixed> $options volume_bytes, volume_chunk_bytes, piece_bytes, deflate_max_bytes, zip64_threshold, max_volume_bytes, disk_free (callable( string $dir ): int|false), can_deflate (bool).
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
	 * Begin a file entry. Seals the open volume first when it reached the
	 * volume size. Nothing of the file is copied yet.
	 *
	 * @param string $source     Absolute path of the file to add.
	 * @param string $entry_path Path inside the archive (forward slashes, relative).
	 * @param int    $mtime      Modification time to record.
	 * @return void
	 * @throws \RuntimeException When an entry is already open, the path is invalid or the file cannot be read.
	 * @throws InsufficientSpace When the disk is full.
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
		if ( false === $stats || ! is_file( $source ) ) {
			throw new \RuntimeException( 'The source file cannot be read.' );
		}
		$size = (int) $stats['size'];
		if ( $size > self::max_entry_bytes() ) {
			throw new \RuntimeException( 'The file is larger than this platform can archive.' );
		}
		$this->ensure_volume_open( $size );
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
	 * @param int|null $max_bytes Piece size; null for the configured one.
	 * @return int Bytes of source consumed by this call (0 when the entry is complete or none is open).
	 * @throws \RuntimeException When the source changed underneath.
	 * @throws InsufficientSpace When the disk is full.
	 */
	public function write_piece( $max_bytes = null ): int {
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
			$out  = gzdeflate( $data, self::COMPRESSION_LEVEL );
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
	 * Drop the open entry: the volume is truncated back to the entry's
	 * header offset (the step calls this when the source changed).
	 *
	 * @return void
	 */
	public function abort_entry(): void {
		if ( ! $this->has_open_entry() ) {
			return;
		}
		$this->close_source();
		$offset = (int) $this->state['entry']['header_offset'];
		$this->truncate_volume( $offset );
		$this->state['volume']['bytes'] = $offset;
		$this->state['entry']           = null;
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
		$records   = fopen( $this->records_path(), 'rb' );
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
	 * Add the summary files to the last volume and seal it. Files are
	 * appended as entries; the manifest string is the embedded copy. When
	 * the open volume already holds entries and the summaries would push it
	 * over the volume size, it is sealed first and a new volume takes only
	 * the summaries. A single-volume archive is renamed to the single name.
	 *
	 * @param array<string, string> $files    Entry path => absolute source path (the index files).
	 * @param string                $manifest Embedded manifest JSON.
	 * @param int                   $mtime    Modification time to record.
	 * @return void
	 * @throws \RuntimeException When the operation fails (message says what).
	 */
	public function finish( array $files, string $manifest, int $mtime ): void {
		if ( $this->has_open_entry() ) {
			throw new \RuntimeException( 'An entry is still in progress.' );
		}
		if ( $this->state['finished'] ) {
			return;
		}
		if ( null === $this->state['volume'] && array() !== $this->state['sealed'] && $this->last_volume_has_summaries() ) {
			// A previous tick finished the archive and died before its checkpoint; resume() adopted the volume.
			$this->rename_single_volume();
			$this->state['finished'] = true;
			return;
		}
		$total = strlen( $manifest );
		foreach ( $files as $source ) {
			$total += (int) filesize( $source );
		}
		if ( null !== $this->state['volume'] && $this->state['volume']['entries'] > 0 && $this->state['volume']['bytes'] + $total > $this->options['volume_bytes'] ) {
			$this->seal_volume();
		}
		foreach ( $files as $entry_path => $source ) {
			$this->add_entry( $source, (string) $entry_path, $mtime );
			while ( $this->write_piece() > 0 ) {
				continue;
			}
		}
		$this->ensure_volume_open( strlen( $manifest ) );
		$this->add_string_entry( 'manifest.json', $manifest, $mtime );
		$this->seal_volume();
		$this->rename_single_volume();
		$this->state['finished'] = true;
	}

	/**
	 * Whether the last sealed volume already ends with the embedded manifest.
	 *
	 * @return bool
	 */
	private function last_volume_has_summaries(): bool {
		$last = $this->state['sealed'][ count( $this->state['sealed'] ) - 1 ];
		try {
			$reader = ZipReader::open( $this->dir . DIRECTORY_SEPARATOR . $last['path'] );
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
		if ( file_exists( $to ) || ! rename( $from, $to ) ) {
			throw new \RuntimeException( 'The volume could not be renamed to its single-volume name.' );
		}
		$this->state['sealed'][0]['path'] = $single;
	}

	/**
	 * Manifest entries of the sealed volumes (path, bytes, sha256 and the
	 * chunk list above volume_chunk_bytes), in order.
	 *
	 * @param bool $embedded Leave out the last volume (the one holding the embedded manifest).
	 * @return array<int, array{path: string, bytes: int, chunks?: string[], sha256: string}>
	 * @throws \RuntimeException When a volume is not fully hashed yet.
	 */
	public function volume_entries( bool $embedded = false ): array {
		$chunk = (int) $this->options['volume_chunk_bytes'];
		$out   = array();
		$list  = $this->state['sealed'];
		if ( $embedded ) {
			array_pop( $list );
		}
		foreach ( $list as $sealed ) {
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
			// Buffers were lost (an OS crash): a stored entry can rewind, a deflated one starts over.
			$lost = $committed - $actual;
			if ( null === $entry || ZipFormat::METHOD_STORE !== $entry['method'] || (int) $entry['written'] < $lost ) {
				throw new \RuntimeException( 'The volume lost committed data and cannot be resumed.' );
			}
			$this->state['entry']['written'] -= $lost;
			$this->state['entry']['offset']  -= $lost;
			$this->state['entry']['csize']    = $this->state['entry']['written'];
			// The running CRC cannot be rewound: recompute it from the bytes that are there.
			$this->state['entry']['crc'] = $this->crc_of_volume_range( (int) $entry['data_offset'], (int) $this->state['entry']['written'] );
			$committed                   = $actual;
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
	 * Open the volume if none is open, creating a new one.
	 *
	 * @param int $next_entry_bytes Size of the entry about to be added (free-space check).
	 * @return void
	 * @throws InsufficientSpace When the disk is full.
	 * @throws \RuntimeException When the operation fails (message says what).
	 */
	private function ensure_volume_open( int $next_entry_bytes ): void {
		if ( null !== $this->state['volume'] && $this->state['volume']['bytes'] >= $this->options['volume_bytes'] ) {
			$this->seal_volume();
		}
		if ( null !== $this->state['volume'] && $this->state['volume']['entries'] > 0 && $this->state['volume']['bytes'] + $next_entry_bytes + 65536 > $this->options['max_volume_bytes'] ) {
			// Every entry may fit the platform on its own while the volume would not: seal first.
			$this->seal_volume();
		}
		if ( null !== $this->state['volume'] && $this->state['volume']['entries'] >= self::MAX_VOLUME_ENTRIES ) {
			$this->seal_volume();
		}
		if ( null === $this->state['volume'] ) {
			$index                 = count( $this->state['sealed'] ) + 1;
			$name                  = sprintf( self::VOLUME_NAME_PATTERN, $this->state['base'], $index );
			$this->state['volume'] = array(
				'index'   => $index,
				'name'    => $name,
				'bytes'   => 0,
				'entries' => 0,
			);
			foreach ( array( $this->partial_path(), $this->records_path(), $this->dir . DIRECTORY_SEPARATOR . $name ) as $path ) {
				if ( file_exists( $path ) ) {
					throw new \RuntimeException( 'A file of the new volume already exists.' );
				}
			}
			$this->check_space( $next_entry_bytes );
			$handle = fopen( $this->partial_path(), 'w+b' );
			if ( false === $handle ) {
				throw new \RuntimeException( 'The volume file could not be created.' );
			}
			$this->handle = $handle;
			if ( false === file_put_contents( $this->records_path(), '' ) ) {
				throw new \RuntimeException( 'The record file could not be created.' );
			}
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
		$written = fwrite( $this->handle, $data );
		if ( false === $written || strlen( $data ) !== $written ) {
			throw new InsufficientSpace( 'The volume could not be written completely (disk full?).' );
		}
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
		if ( ! ftruncate( $this->handle, $bytes ) ) {
			throw new \RuntimeException( 'The volume could not be truncated.' );
		}
		$this->seek_volume( $bytes );
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
	 * CRC of a byte range of the open volume (resume after lost buffers).
	 *
	 * @param int $offset Offset.
	 * @param int $length Length.
	 * @return int
	 * @throws \RuntimeException When the operation fails (message says what).
	 */
	private function crc_of_volume_range( int $offset, int $length ): int {
		$this->open_volume_handle();
		$this->seek_volume( $offset );
		$crc  = 0;
		$left = $length;
		while ( $left > 0 ) {
			$data = fread( $this->handle, (int) min( 1048576, $left ) );
			if ( false === $data || '' === $data ) {
				throw new \RuntimeException( 'The volume could not be read back.' );
			}
			$crc   = Crc32::combine( $crc, Crc32::of( $data ), strlen( $data ) );
			$left -= strlen( $data );
		}
		return $crc;
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
		$this->handle = $handle;
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
		if ( ! is_file( $entry['source'] ) || (int) filesize( $entry['source'] ) !== (int) $entry['size'] ) {
			throw new \RuntimeException( 'The source file changed while it was being archived.' );
		}
		$handle = fopen( $entry['source'], 'rb' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'The source file cannot be read.' );
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
				throw new \RuntimeException( 'The source file changed while it was being archived.' );
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
