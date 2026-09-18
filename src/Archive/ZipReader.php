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
 * Lists entries from the central directory and extracts them in pieces.
 * Every entry name goes through EntryPath, and the target of an
 * extraction is checked with realpath to lie inside the target directory
 * (zip slip). Stored entries are copied in pieces so a large one can be
 * extracted across ticks; deflated entries are inflated in one go and
 * are therefore limited in size.
 */
final class ZipReader {

	const TAIL_BYTES        = 65536 + 22 + 20 + 56;
	const PIECE_BYTES       = 4194304;
	const MAX_INFLATE_BYTES = 67108864; // 64 MiB: the plugin deflates only entries up to 4 MiB.
	const MAX_ENTRIES       = 5000000;

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
			if ( null === $end || $end['cd_offset'] + $end['cd_size'] > $size || $end['entries'] > self::MAX_ENTRIES ) {
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
	 * Walk the central directory. Names that break the entry-path rule are
	 * reported with "problem" set and must not be extracted.
	 *
	 * @param callable $callback function( array $entry ): bool — return false to stop.
	 * @return void
	 * @throws \RuntimeException When the central directory is malformed.
	 */
	public function each( callable $callback ): void {
		$handle = $this->handle();
		try {
			if ( 0 !== fseek( $handle, $this->end['cd_offset'] ) ) {
				throw new \RuntimeException( 'The central directory could not be positioned.' );
			}
			$buffer = '';
			$seen   = 0;
			while ( $seen < $this->end['entries'] ) {
				if ( strlen( $buffer ) < 65536 + 46 ) {
					$more = fread( $handle, 65536 );
					if ( is_string( $more ) && '' !== $more ) {
						$buffer .= $more;
					}
				}
				$parsed = ZipFormat::parse_central_header( $buffer );
				if ( null === $parsed ) {
					throw new \RuntimeException( 'The central directory is malformed.' );
				}
				$buffer = substr( $buffer, $parsed['length'] );
				++$seen;
				$entry            = $parsed;
				$entry['index']   = $seen - 1;
				$entry['problem'] = EntryPath::problem( $parsed['name'] );
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
		if ( $entry['usize'] > $max_bytes ) {
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
	 * @throws \RuntimeException When the entry is unsafe, unreadable or corrupt.
	 */
	public function extract( array $entry, string $target_dir ): string {
		if ( null !== $entry['problem'] ) {
			throw new \RuntimeException( 'Refusing to extract an unsafe entry name: ' . $entry['problem'] );
		}
		$target_dir = rtrim( $target_dir, '/\\' );
		$real_root  = realpath( $target_dir );
		if ( false === $real_root || ! is_dir( $real_root ) ) {
			throw new \RuntimeException( 'The target directory does not exist.' );
		}
		$target = $target_dir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $entry['name'] );
		$parent = dirname( $target );
		if ( ! is_dir( $parent ) && ! mkdir( $parent, 0755, true ) && ! is_dir( $parent ) ) {
			throw new \RuntimeException( 'The target directory could not be created.' );
		}
		// The parent must resolve inside the root (a symlink planted earlier could point elsewhere) and
		// the target itself must not be a link.
		if ( ! Paths::is_same_or_inside( $real_root, $parent ) || is_link( $target ) ) {
			throw new \RuntimeException( 'Refusing to extract outside the target directory.' );
		}
		$out = fopen( $target, 'wb' );
		if ( false === $out ) {
			throw new \RuntimeException( 'The target file could not be created.' );
		}
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
	 * Copy an entry's content to a stream, verifying the CRC.
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @param resource             $out   Destination.
	 * @return void
	 * @throws \RuntimeException When the data is corrupt or the method unsupported.
	 */
	private function copy_entry( array $entry, $out ): void {
		$handle = $this->handle();
		try {
			if ( 0 !== fseek( $handle, (int) $entry['offset'] ) ) {
				throw new \RuntimeException( 'The entry could not be positioned.' );
			}
			$local = fread( $handle, 30 );
			$len   = is_string( $local ) ? ZipFormat::local_header_length( $local ) : null;
			if ( null === $len ) {
				throw new \RuntimeException( 'The local header is malformed.' );
			}
			if ( 0 !== fseek( $handle, (int) $entry['offset'] + $len ) ) {
				throw new \RuntimeException( 'The entry could not be positioned.' );
			}
			$crc = 0;
			if ( ZipFormat::METHOD_STORE === (int) $entry['method'] ) {
				$left = (int) $entry['csize'];
				while ( $left > 0 ) {
					$piece = fread( $handle, (int) min( 1048576, $left ) );
					if ( false === $piece || '' === $piece ) {
						throw new \RuntimeException( 'The entry is truncated.' );
					}
					$crc   = Crc32::combine( $crc, Crc32::of( $piece ), strlen( $piece ) );
					$left -= strlen( $piece );
					if ( strlen( $piece ) !== fwrite( $out, $piece ) ) {
						throw new \RuntimeException( 'The entry could not be written.' );
					}
				}
			} elseif ( ZipFormat::METHOD_DEFLATE === (int) $entry['method'] ) {
				if ( $entry['csize'] > self::MAX_INFLATE_BYTES || $entry['usize'] > self::MAX_INFLATE_BYTES ) {
					throw new \RuntimeException( 'A deflated entry is too large to inflate in one piece.' );
				}
				$compressed = (int) $entry['csize'] > 0 ? fread( $handle, (int) $entry['csize'] ) : '';
				if ( ! is_string( $compressed ) || strlen( $compressed ) !== (int) $entry['csize'] ) {
					throw new \RuntimeException( 'The entry is truncated.' );
				}
				$data = @gzinflate( $compressed, (int) $entry['usize'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- corrupt data is reported below.
				if ( ! is_string( $data ) || strlen( $data ) !== (int) $entry['usize'] ) {
					throw new \RuntimeException( 'The entry could not be inflated.' );
				}
				$crc = Crc32::of( $data );
				if ( strlen( $data ) !== fwrite( $out, $data ) ) {
					throw new \RuntimeException( 'The entry could not be written.' );
				}
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
