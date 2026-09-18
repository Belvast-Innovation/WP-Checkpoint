<?php
/**
 * Reads a volume written by Packer (or any zip with a central directory).
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Archive;

use WPCheckpoint\Support\Paths;

// phpcs:disable WordPress.WP.AlternativeFunctions -- streamed reads of archive files; the WP filesystem API has no equivalent.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are internal (an entry-path verdict), never HTML; the caller presents them through JobPresenter::clean().

/**
 * Lists entries from the central directory and extracts them. Every entry
 * name goes through EntryPath, and the target of an extraction is checked
 * with realpath to lie inside the target directory (zip slip). Every
 * number in the central directory is untrusted: no loop bound and no
 * allocation follows a declared size alone.
 *
 * Extraction copies a whole entry in one call: there is no byte-offset
 * resumable extraction yet, so a 2 GiB stored entry cannot be spread over
 * several ticks (T030 needs an extract_piece() for that). Deflated entries
 * are inflated in one go and limited to MAX_INFLATE_BYTES.
 */
final class ZipReader {

	const TAIL_BYTES              = 65536 + 22 + 20 + 56;
	const MAX_HEADER_BYTES        = 1048576;  // A central header with name, extra and comment beyond this is malformed.
	const MAX_INFLATE_BYTES       = 8388608; // 8 MiB: the plugin deflates only entries up to 4 MiB. Inflating in one piece peaks at about 3 x this, which keeps a unit inside the 32 MB step increment (tested).
	const MAX_EMPTY_DEFLATE_BYTES = 64; // A deflate stream of an empty entry is 2 bytes; leave room for odd encoders.
	const MAX_ENTRIES             = 5000000;

	/**
	 * Windows device names: a file of this name (any extension) is the device,
	 * and fopen( 'COM1' ) blocks waiting for the port, so a restore would hang
	 * with no hint that a file name is the cause.
	 */
	const WINDOWS_DEVICES = array( 'CON', 'PRN', 'AUX', 'NUL', 'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9', 'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9' );

	/**
	 * Volume path.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * Central directory position.
	 *
	 * @var array{entries: int, cd_size: int, cd_offset: int}
	 */
	private $end;

	/**
	 * Use open().
	 *
	 * @param string                                            $path Path.
	 * @param array{entries: int, cd_size: int, cd_offset: int} $end  End record.
	 */
	private function __construct( string $path, array $end ) {
		$this->path = $path;
		$this->end  = $end;
	}

	/**
	 * Open a volume.
	 *
	 * @param string $path Volume path.
	 * @return ZipReader
	 * @throws \RuntimeException When the file is not a readable zip.
	 */
	public static function open( string $path ): ZipReader {
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- thrown below.
		if ( false === $handle ) {
			throw new \RuntimeException( 'The volume cannot be opened.' );
		}
		try {
			$meta  = stream_get_meta_data( $handle );
			$stats = fstat( $handle );
			if ( 'plainfile' !== $meta['wrapper_type'] || ! is_array( $stats ) || 0100000 !== ( (int) $stats['mode'] & 0170000 ) ) {
				throw new \RuntimeException( 'Not a regular file.' );
			}
			$size  = (int) $stats['size'];
			$start = max( 0, $size - self::TAIL_BYTES );
			if ( 0 !== fseek( $handle, $start ) ) {
				throw new \RuntimeException( 'The volume could not be positioned.' );
			}
			$tail = stream_get_contents( $handle );
			$end  = is_string( $tail ) ? ZipFormat::parse_end( $tail, $start ) : null;
			if ( null === $end || $end['entries'] < 0 || $end['cd_size'] < 0 || $end['cd_offset'] < 0 || $end['cd_offset'] + $end['cd_size'] > $size || $end['entries'] > self::MAX_ENTRIES ) {
				throw new \RuntimeException( 'Not a zip archive (no usable central directory).' );
			}
			return new self( $path, $end );
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Number of entries.
	 *
	 * @return int
	 */
	public function count(): int {
		return $this->end['entries'];
	}

	/**
	 * Where the central directory starts (the size of the entry data).
	 *
	 * @return int
	 */
	public function central_directory_offset(): int {
		return $this->end['cd_offset'];
	}

	/**
	 * Walk the central directory. Names that break the entry-path rule are
	 * reported with "problem" set and must not be extracted. Every entry
	 * carries "cd_offset", the file position of its central header, and
	 * "cd_next", the position of the following one, so a caller can
	 * continue from entry $from_index without re-reading the headers before
	 * it (a verifier that handles one entry per tick).
	 *
	 * @param callable $callback    function( array $entry ): bool — return false to stop.
	 * @param int      $from_index  First entry to report (0-based).
	 * @param int      $from_offset File position of that entry's central header, or -1 to walk from the start.
	 * @return void
	 * @throws \RuntimeException When the central directory is malformed or the position does not match.
	 */
	public function each( callable $callback, int $from_index = 0, int $from_offset = -1 ): void {
		$handle = $this->handle();
		try {
			$position = $this->end['cd_offset'];
			$seen     = 0;
			if ( $from_index > 0 ) {
				if ( $from_offset < $this->end['cd_offset'] || $from_offset >= $this->end['cd_offset'] + $this->end['cd_size'] ) {
					throw new \RuntimeException( 'The central directory position does not belong to this volume.' );
				}
				$position = $from_offset;
				$seen     = $from_index;
			}
			if ( 0 !== fseek( $handle, $position ) ) {
				throw new \RuntimeException( 'The central directory could not be positioned.' );
			}
			$buffer = '';
			while ( $seen < $this->end['entries'] ) {
				$parsed = ZipFormat::parse_central_header( $buffer );
				while ( null === $parsed ) {
					$buffered = strlen( $buffer );
					if ( $buffered >= self::MAX_HEADER_BYTES || feof( $handle ) ) {
						break;
					}
					$more = fread( $handle, 65536 );
					if ( ! is_string( $more ) || '' === $more ) {
						break;
					}
					$buffer .= $more;
					$parsed  = ZipFormat::parse_central_header( $buffer );
				}
				if ( null === $parsed ) {
					throw new \RuntimeException( 'The central directory is malformed.' );
				}
				$buffer = substr( $buffer, $parsed['length'] );
				++$seen;
				$entry              = $parsed;
				$entry['index']     = $seen - 1;
				$entry['cd_offset'] = $position;
				$position          += $parsed['length'];
				$entry['cd_next']   = $position;
				$entry['directory'] = '' !== $parsed['name'] && '/' === substr( $parsed['name'], -1 );
				$entry['problem']   = $entry['directory'] ? 'Directory entry (nothing to extract; importers skip it).' : EntryPath::problem( $parsed['name'] );
				unset( $entry['length'] );
				if ( false === $callback( $entry ) ) {
					return;
				}
			}
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * All entries (small archives; large ones should use each()).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function entries(): array {
		$out = array();
		$this->each(
			static function ( array $entry ) use ( &$out ): bool {
				$out[] = $entry;
				return true;
			}
		);
		return $out;
	}

	/**
	 * Find an entry by name.
	 *
	 * @param string $name Entry name.
	 * @return array<string, mixed>|null
	 */
	public function find( string $name ) {
		$found = null;
		$this->each(
			static function ( array $entry ) use ( $name, &$found ): bool {
				if ( $entry['name'] === $name ) {
					$found = $entry;
					return false;
				}
				return true;
			}
		);
		return $found;
	}

	/**
	 * Read a small entry into memory (the manifest); the CRC is checked.
	 *
	 * @param array<string, mixed> $entry     Entry from each() or find().
	 * @param int                  $max_bytes Refuse larger content.
	 * @return string
	 * @throws \RuntimeException When too large, unreadable or corrupt.
	 */
	public function read( array $entry, int $max_bytes = Manifest::MAX_JSON_BYTES ): string {
		if ( $entry['usize'] > $max_bytes || $entry['csize'] > $max_bytes ) {
			throw new \RuntimeException( 'The entry is larger than allowed.' );
		}
		$temp = fopen( 'php://temp/maxmemory:' . ( $max_bytes + 1 ), 'w+b' );
		if ( false === $temp ) {
			throw new \RuntimeException( 'No memory stream.' );
		}
		try {
			$this->copy_entry( $entry, $temp );
			rewind( $temp );
			$data = stream_get_contents( $temp );
			return is_string( $data ) ? $data : '';
		} finally {
			fclose( $temp );
		}
	}

	/**
	 * Extract an entry to a file below $target_dir. Refuses names that
	 * break the entry-path rule and targets that resolve outside the
	 * directory. Existing files are overwritten.
	 *
	 * @param array<string, mixed> $entry      Entry.
	 * @param string               $target_dir Directory.
	 * @return string The written file's path.
	 * @throws EnvironmentFailure When the target cannot be created or written.
	 * @throws \RuntimeException When the entry is unsafe, unreadable or corrupt.
	 */
	public function extract( array $entry, string $target_dir ): string {
		list( $target, $out ) = $this->open_target( $entry, $target_dir, true );
		try {
			$this->copy_entry( $entry, $out );
		} catch ( \Throwable $e ) {
			fclose( $out );
			@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort after a failure.
			throw $e; // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- rethrown unchanged.
		}
		fclose( $out );
		return $target;
	}

	/**
	 * Extract one byte range of a stored entry, resumable across calls so
	 * that a multi-gigabyte entry never has to fit one unit of work: offset
	 * 0 creates the file with the same checks as extract(), a later offset
	 * appends to a file whose size must equal it. The CRC of everything
	 * written so far is returned and passed back in; the call that reaches
	 * the end compares it with the entry's and removes the file on a
	 * mismatch. A deflated entry has no addressable ranges: it must be
	 * asked for whole (offset 0, length >= usize), which extracts it in one
	 * piece as extract() does.
	 *
	 * @param array<string, mixed> $entry      Entry.
	 * @param string               $target_dir Directory.
	 * @param int                  $offset     Offset within the content.
	 * @param int                  $length     Bytes to write (clamped to the end).
	 * @param int                  $crc        CRC returned by the previous call (0 at offset 0).
	 * @return array{path: string, crc: int, done: bool}
	 * @throws EnvironmentFailure When the target cannot be created or written.
	 * @throws \RuntimeException When the entry is unsafe, the range invalid, the data corrupt or the target file not the one being resumed.
	 */
	public function extract_piece( array $entry, string $target_dir, int $offset, int $length, int $crc ): array {
		$usize = (int) $entry['usize'];
		if ( ZipFormat::METHOD_STORE !== (int) $entry['method'] ) {
			if ( 0 !== $offset || $length < $usize ) {
				throw new \RuntimeException( 'Only stored entries can be extracted in pieces.' );
			}
			return array(
				'path' => $this->extract( $entry, $target_dir ),
				'crc'  => (int) $entry['crc'],
				'done' => true,
			);
		}
		if ( $offset < 0 || $length < 0 || $offset > $usize || (int) $entry['csize'] !== $usize ) {
			throw new \RuntimeException( 'The range is outside the entry.' );
		}
		$length               = (int) min( $length, $usize - $offset );
		list( $target, $out ) = $this->open_target( $entry, $target_dir, 0 === $offset );
		try {
			if ( $offset > 0 ) {
				$stat = fstat( $out );
				if ( ! is_array( $stat ) || (int) $stat['size'] !== $offset || 0 !== fseek( $out, $offset ) ) {
					throw new \RuntimeException( 'The target file is not the one being resumed.' );
				}
			}
			$handle = $this->handle();
			try {
				if ( 0 !== fseek( $handle, $this->data_offset( $handle, $entry ) + $offset ) ) {
					throw new \RuntimeException( 'The entry could not be positioned.' );
				}
				$left = $length;
				while ( $left > 0 ) {
					$piece = fread( $handle, (int) min( 1048576, $left ) );
					if ( false === $piece || '' === $piece ) {
						throw new \RuntimeException( 'The entry is truncated.' );
					}
					if ( strlen( $piece ) !== fwrite( $out, $piece ) ) {
						throw new EnvironmentFailure( 'The entry could not be written.' );
					}
					$crc   = Crc32::combine( $crc, Crc32::of( $piece ), strlen( $piece ) );
					$left -= strlen( $piece );
				}
			} finally {
				fclose( $handle );
			}
			$done = $offset + $length >= $usize;
			if ( $done && Crc32::hex( $crc ) !== Crc32::hex( (int) $entry['crc'] ) ) {
				throw new \RuntimeException( 'CRC mismatch: the entry is corrupt.' );
			}
		} catch ( \Throwable $e ) {
			fclose( $out );
			@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort after a failure.
			throw $e; // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- rethrown unchanged.
		}
		fclose( $out );
		return array(
			'path' => $target,
			'crc'  => $crc,
			'done' => $done,
		);
	}

	/**
	 * Resolve and open an entry's target file: the name must pass the
	 * entry-path rule, the nearest existing ancestor and the parent must
	 * resolve inside the directory (a planted symlink would otherwise lead
	 * mkdir outside), and the file is created exclusively after removing
	 * whatever sits there (never written through a link). Resuming opens
	 * the existing regular file instead.
	 *
	 * @param array<string, mixed> $entry      Entry.
	 * @param string               $target_dir Directory.
	 * @param bool                 $create     Create anew (true) or reopen for resuming (false).
	 * @return array{0: string, 1: resource} Path and handle.
	 * @throws EnvironmentFailure When the directory or file cannot be created or opened.
	 * @throws \RuntimeException When the entry is unsafe or the target resolves outside the directory.
	 */
	private function open_target( array $entry, string $target_dir, bool $create ): array {
		if ( null !== $entry['problem'] ) {
			throw new \RuntimeException( 'Refusing to extract an unsafe entry name: ' . $entry['problem'] );
		}
		$target_dir = rtrim( $target_dir, '/\\' );
		if ( '' === $target_dir ) {
			// realpath( '' ) is the working directory; a caller that passes nothing gets an error, not the cwd.
			throw new \RuntimeException( 'The target directory is empty.' );
		}
		$real_root = realpath( $target_dir );
		if ( false === $real_root || ! is_dir( $real_root ) ) {
			throw new EnvironmentFailure( 'The target directory does not exist.' );
		}
		if ( 'Windows' === PHP_OS_FAMILY ) {
			self::assert_safe_on_windows( $entry['name'] );
		}
		$target = $target_dir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $entry['name'] );
		$parent = dirname( $target );
		// Before creating anything: the nearest existing ancestor must resolve inside the root, otherwise a
		// symlink planted earlier (a/ -> elsewhere) would make mkdir build directories outside the target.
		$existing = $parent;
		while ( ! file_exists( $existing ) && $existing !== $target_dir && dirname( $existing ) !== $existing ) {
			$existing = dirname( $existing );
		}
		if ( ! Paths::is_same_or_inside( $real_root, $existing ) ) {
			throw new \RuntimeException( 'Refusing to extract outside the target directory.' );
		}
		// Silenced: a PHP warning would put the full target path into the error log, bypassing the path masking.
		if ( ! is_dir( $parent ) && ! @mkdir( $parent, 0755, true ) && ! is_dir( $parent ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			throw new EnvironmentFailure( 'The target directory could not be created.' );
		}
		// After creating: the parent itself must resolve inside the root.
		if ( ! Paths::is_same_or_inside( $real_root, $parent ) ) {
			throw new \RuntimeException( 'Refusing to extract outside the target directory.' );
		}
		if ( is_dir( $target ) && ! is_link( $target ) ) {
			throw new \RuntimeException( 'A directory is in the way of the entry.' );
		}
		if ( ! $create ) {
			if ( is_link( $target ) || ! is_file( $target ) ) {
				throw new \RuntimeException( 'The target file is not the one being resumed.' );
			}
			$out = @fopen( $target, 'r+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			if ( false === $out ) {
				throw new EnvironmentFailure( 'The target file could not be opened.' );
			}
			return array( $target, $out );
		}
		// Never write through whatever sits at the target (a symlink or a hard link would carry the bytes to
		// its other name): remove it and create the file exclusively.
		if ( ( is_link( $target ) || file_exists( $target ) ) && ! @unlink( $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			throw new EnvironmentFailure( 'The target file could not be replaced.' );
		}
		$out = @fopen( $target, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		if ( false === $out ) {
			throw new EnvironmentFailure( 'The target file could not be created.' );
		}
		return array( $target, $out );
	}

	/**
	 * Copy an entry's content to a stream, verifying the CRC.
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @param resource             $out   Destination.
	 * @return void
	 * @throws \RuntimeException When the data is corrupt or the method unsupported.
	 */
	private function copy_entry( array $entry, $out ): void {
		$this->stream_entry(
			$entry,
			static function ( string $piece ) use ( $out ): void {
				if ( strlen( $piece ) !== fwrite( $out, $piece ) ) {
					throw new EnvironmentFailure( 'The entry could not be written.' );
				}
			}
		);
	}

	/**
	 * SHA-256 of an entry's content, streamed, with the CRC verified.
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @return string Lowercase hex.
	 * @throws \RuntimeException When the data is corrupt or the method unsupported.
	 */
	public function hash_entry( array $entry ): string {
		$context = hash_init( 'sha256' );
		$this->stream_entry(
			$entry,
			static function ( string $piece ) use ( $context ): void {
				hash_update( $context, $piece );
			}
		);
		return hash_final( $context );
	}

	/**
	 * Per-chunk SHA-256 of an entry's content (chunks of $chunk_bytes from
	 * the start, the last one shorter), streamed, with the CRC verified.
	 * One unit of work for the whole entry: meant for deflated entries,
	 * which cannot be read in ranges and are bounded by MAX_INFLATE_BYTES;
	 * a large stored entry is hashed range by range instead.
	 *
	 * @param array<string, mixed> $entry       Entry.
	 * @param int                  $chunk_bytes Chunk size.
	 * @return string[] Lowercase hex, empty for an empty entry.
	 * @throws \RuntimeException When the data is corrupt or the method unsupported.
	 */
	public function hash_entry_chunks( array $entry, int $chunk_bytes ): array {
		$hashes  = array();
		$context = null;
		$filled  = 0;
		$this->stream_entry(
			$entry,
			static function ( string $piece ) use ( &$hashes, &$context, &$filled, $chunk_bytes ): void {
				while ( '' !== $piece ) {
					if ( null === $context ) {
						$context = hash_init( 'sha256' );
						$filled  = 0;
					}
					$take = substr( $piece, 0, $chunk_bytes - $filled );
					hash_update( $context, $take );
					$filled += strlen( $take );
					$piece   = substr( $piece, strlen( $take ) );
					if ( $filled === $chunk_bytes ) {
						$hashes[] = hash_final( $context );
						$context  = null;
					}
				}
			}
		);
		if ( null !== $context ) {
			$hashes[] = hash_final( $context );
		}
		return $hashes;
	}

	/**
	 * SHA-256 of a byte range of a stored entry (one content chunk per call,
	 * so a large entry is verified across ticks). Deflated entries have no
	 * addressable ranges and are refused.
	 *
	 * @param array<string, mixed> $entry  Entry.
	 * @param int                  $offset Offset within the content.
	 * @param int                  $length Length.
	 * @return string Lowercase hex.
	 * @throws \RuntimeException When the entry is not stored, the range is outside the entry or the data is truncated.
	 */
	public function hash_entry_range( array $entry, int $offset, int $length ): string {
		if ( ZipFormat::METHOD_STORE !== (int) $entry['method'] ) {
			throw new \RuntimeException( 'Only stored entries can be hashed in ranges.' );
		}
		$usize = (int) $entry['usize'];
		if ( $offset < 0 || $length < 0 || $offset + $length > $usize || (int) $entry['csize'] !== $usize ) {
			throw new \RuntimeException( 'The range is outside the entry.' );
		}
		$handle = $this->handle();
		try {
			$data_offset = $this->data_offset( $handle, $entry );
			if ( 0 !== fseek( $handle, $data_offset + $offset ) ) {
				throw new \RuntimeException( 'The entry could not be positioned.' );
			}
			$context = hash_init( 'sha256' );
			$left    = $length;
			while ( $left > 0 ) {
				$piece = fread( $handle, (int) min( 1048576, $left ) );
				if ( false === $piece || '' === $piece ) {
					throw new \RuntimeException( 'The entry is truncated.' );
				}
				hash_update( $context, $piece );
				$left -= strlen( $piece );
			}
			return hash_final( $context );
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Where an entry's bytes start: after its local header, whose length is
	 * read from the file (the central directory's name and extra lengths
	 * may differ from the local ones).
	 *
	 * @param resource             $handle Open volume.
	 * @param array<string, mixed> $entry  Entry.
	 * @return int
	 * @throws \RuntimeException When the local header is malformed.
	 */
	private function data_offset( $handle, array $entry ): int {
		if ( 0 !== fseek( $handle, (int) $entry['offset'] ) ) {
			throw new \RuntimeException( 'The entry could not be positioned.' );
		}
		$local = fread( $handle, 30 );
		$len   = is_string( $local ) ? ZipFormat::local_header_length( $local ) : null;
		if ( null === $len ) {
			throw new \RuntimeException( 'The local header is malformed.' );
		}
		return (int) $entry['offset'] + $len;
	}

	/**
	 * Feed an entry's content to a sink piece by piece, verifying the CRC.
	 * Every number from the central directory is untrusted: a stored entry's
	 * two sizes must agree and the loop counts what it read against the
	 * uncompressed size; a deflated entry never reaches gzinflate() with a
	 * limit of zero.
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @param callable             $sink  function( string $piece ): void.
	 * @return void
	 * @throws \RuntimeException When the data is corrupt or the method unsupported.
	 */
	private function stream_entry( array $entry, callable $sink ): void {
		$handle = $this->handle();
		try {
			$data_offset = $this->data_offset( $handle, $entry );
			if ( 0 !== fseek( $handle, $data_offset ) ) {
				throw new \RuntimeException( 'The entry could not be positioned.' );
			}
			$crc   = 0;
			$usize = (int) $entry['usize'];
			$csize = (int) $entry['csize'];
			if ( $usize < 0 || $csize < 0 ) {
				throw new \RuntimeException( 'The entry sizes are malformed.' );
			}
			if ( ZipFormat::METHOD_STORE === (int) $entry['method'] ) {
				// Stored means "as is": the two sizes must agree before anything is read, and the loop is bounded
				// by the uncompressed size with a running count, never by the compressed size alone.
				if ( $csize !== $usize ) {
					throw new \RuntimeException( 'A stored entry declares different sizes.' );
				}
				$read = 0;
				while ( $read < $usize ) {
					$piece = fread( $handle, (int) min( 1048576, $usize - $read ) );
					if ( false === $piece || '' === $piece ) {
						throw new \RuntimeException( 'The entry is truncated.' );
					}
					$read += strlen( $piece );
					if ( $read > $usize ) {
						throw new \RuntimeException( 'The entry is longer than declared.' );
					}
					$crc = Crc32::combine( $crc, Crc32::of( $piece ), strlen( $piece ) );
					$sink( $piece );
				}
			} elseif ( ZipFormat::METHOD_DEFLATE === (int) $entry['method'] ) {
				if ( $csize > self::MAX_INFLATE_BYTES || $usize > self::MAX_INFLATE_BYTES ) {
					throw new \RuntimeException( 'A deflated entry is too large to inflate in one piece.' );
				}
				if ( 0 === $usize && $csize > self::MAX_EMPTY_DEFLATE_BYTES ) {
					// An empty entry deflates to a few bytes; anything more with a declared size of zero is a lie.
					throw new \RuntimeException( 'A deflated entry declares data but no size.' );
				}
				$compressed = $csize > 0 ? fread( $handle, $csize ) : '';
				if ( ! is_string( $compressed ) || strlen( $compressed ) !== $csize ) {
					throw new \RuntimeException( 'The entry is truncated.' );
				}
				// gzinflate()'s max_length must never be 0: PHP reads 0 as "unlimited", and a 64 MiB deflate
				// stream can expand to tens of gigabytes before any check after the call runs. A declared size
				// of zero therefore inflates with a limit of one byte and must yield nothing.
				$data = 0 === $csize ? '' : @gzinflate( $compressed, max( 1, $usize ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- corrupt data is reported below.
				if ( ! is_string( $data ) || strlen( $data ) !== $usize ) {
					throw new \RuntimeException( 'The entry could not be inflated.' );
				}
				$crc = Crc32::of( $data );
				$sink( $data );
			} else {
				throw new \RuntimeException( 'Unsupported compression method.' );
			}
			if ( Crc32::hex( $crc ) !== Crc32::hex( (int) $entry['crc'] ) ) {
				throw new \RuntimeException( 'CRC mismatch: the entry is corrupt.' );
			}
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Refuse names that Win32 maps to something else than a plain file:
	 * device names (CON, NUL, COM1 ... with any extension) and segments with
	 * trailing dots or spaces, which the kernel strips so that two entries
	 * land on one file. Only on Windows: on POSIX these are ordinary names.
	 *
	 * @param string $name Entry name.
	 * @return void
	 * @throws \RuntimeException When the name is unsafe on Windows.
	 */
	private static function assert_safe_on_windows( string $name ): void {
		foreach ( explode( '/', $name ) as $segment ) {
			$stem = strtoupper( (string) strtok( $segment, '.' ) );
			if ( in_array( $stem, self::WINDOWS_DEVICES, true ) ) {
				throw new \RuntimeException( 'Refusing to extract a Windows device name.' );
			}
			if ( '' !== $segment && ( '.' === substr( $segment, -1 ) || ' ' === substr( $segment, -1 ) ) ) {
				throw new \RuntimeException( 'Refusing a name with a trailing dot or space on Windows.' );
			}
		}
	}

	/**
	 * Open the volume for reading.
	 *
	 * @return resource
	 * @throws \RuntimeException When the operation fails (message says what).
	 */
	private function handle() {
		$handle = fopen( $this->path, 'rb' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'The volume cannot be opened.' );
		}
		return $handle;
	}
}
