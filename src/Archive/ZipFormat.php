<?php
/**
 * Byte layouts of the zip structures the plugin writes and reads.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Archive;

/**
 * Pure encoders and decoders (no I/O): local file header, central
 * directory header, end of central directory, the zip64 extra field and
 * records, DOS timestamps. Only what the format needs: methods STORE and
 * DEFLATE, UTF-8 names (general purpose bit 11), no data descriptors (the
 * local header is patched when the entry ends), no comments, no encryption.
 */
final class ZipFormat {

	const SIG_LOCAL         = 0x04034b50;
	const SIG_CENTRAL       = 0x02014b50;
	const SIG_EOCD          = 0x06054b50;
	const SIG_ZIP64_EOCD    = 0x06064b50;
	const SIG_ZIP64_LOCATOR = 0x07064b50;

	const METHOD_STORE   = 0;
	const METHOD_DEFLATE = 8;

	const FLAG_UTF8 = 0x0800;

	const VERSION_DEFAULT = 20;
	const VERSION_ZIP64   = 45;

	const ZIP64_EXTRA_ID = 0x0001;
	const LIMIT_32       = 0xFFFFFFFF;
	const LIMIT_16       = 0xFFFF;

	/**
	 * DOS time and date of a Unix timestamp (UTC, two-second resolution).
	 *
	 * @param int $mtime Unix timestamp.
	 * @return array{time: int, date: int}
	 */
	public static function dos_datetime( int $mtime ): array {
		$mtime = max( 315532800, $mtime ); // DOS dates start in 1980.
		$y     = (int) gmdate( 'Y', $mtime );
		$mo    = (int) gmdate( 'n', $mtime );
		$d     = (int) gmdate( 'j', $mtime );
		$h     = (int) gmdate( 'G', $mtime );
		$mi    = (int) gmdate( 'i', $mtime );
		$s     = (int) gmdate( 's', $mtime );
		return array(
			'time' => ( $h << 11 ) | ( $mi << 5 ) | ( (int) floor( $s / 2 ) ),
			'date' => ( ( $y - 1980 ) << 9 ) | ( $mo << 5 ) | $d,
		);
	}

	/**
	 * Local file header. Sizes and CRC may be zero at first and patched
	 * later (see patch_offsets()); a zip64 entry reserves the extra field now.
	 *
	 * @param string $name   Entry name (UTF-8).
	 * @param int    $method Method.
	 * @param int    $mtime  Unix timestamp.
	 * @param int    $crc    CRC-32 (0 when not yet known).
	 * @param int    $csize  Compressed size (0 when not yet known).
	 * @param int    $usize  Uncompressed size (0 when not yet known).
	 * @param bool   $zip64  Reserve the zip64 extra field.
	 * @return string
	 */
	public static function local_header( string $name, int $method, int $mtime, int $crc, int $csize, int $usize, bool $zip64 ): string {
		$dt    = self::dos_datetime( $mtime );
		$extra = $zip64 ? self::zip64_extra( $usize, $csize, null ) : '';
		return pack(
			'VvvvvvVVVvv',
			self::SIG_LOCAL,
			$zip64 ? self::VERSION_ZIP64 : self::VERSION_DEFAULT,
			self::FLAG_UTF8,
			$method,
			$dt['time'],
			$dt['date'],
			$crc,
			$zip64 ? self::LIMIT_32 : $csize,
			$zip64 ? self::LIMIT_32 : $usize,
			strlen( $name ),
			strlen( $extra )
		) . $name . $extra;
	}

	/**
	 * Where CRC, sizes and (for zip64) the extra field sit inside a local
	 * header, so the writer can patch them when the entry ends.
	 *
	 * @param int  $name_len Name length.
	 * @param bool $zip64    Whether the extra field was reserved.
	 * @return array{crc: int, sizes: int, extra: int|null}
	 */
	public static function patch_offsets( int $name_len, bool $zip64 ): array {
		return array(
			'crc'   => 14,
			'sizes' => 18,
			'extra' => $zip64 ? 30 + $name_len + 4 : null, // After the extra header id and size.
		);
	}

	/**
	 * The zip64 extended information extra field.
	 *
	 * @param int      $usize  Uncompressed size.
	 * @param int      $csize  Compressed size.
	 * @param int|null $offset Local header offset (central directory only), null to omit.
	 * @return string
	 */
	public static function zip64_extra( int $usize, int $csize, $offset ): string {
		$body = self::u64( $usize ) . self::u64( $csize );
		if ( null !== $offset ) {
			$body .= self::u64( $offset );
		}
		return pack( 'vv', self::ZIP64_EXTRA_ID, strlen( $body ) ) . $body;
	}

	/**
	 * Central directory header for one entry.
	 *
	 * @param array{name: string, method: int, mtime: int, crc: int, csize: int, usize: int, offset: int} $entry Entry.
	 * @return string
	 */
	public static function central_header( array $entry ): string {
		$dt    = self::dos_datetime( (int) $entry['mtime'] );
		$zip64 = $entry['usize'] >= self::LIMIT_32 || $entry['csize'] >= self::LIMIT_32 || $entry['offset'] >= self::LIMIT_32;
		$extra = $zip64 ? self::zip64_extra( (int) $entry['usize'], (int) $entry['csize'], (int) $entry['offset'] ) : '';
		return pack(
			'VvvvvvvVVVvvvvvVV',
			self::SIG_CENTRAL,
			$zip64 ? self::VERSION_ZIP64 : self::VERSION_DEFAULT, // Version made by (DOS attributes).
			$zip64 ? self::VERSION_ZIP64 : self::VERSION_DEFAULT,
			self::FLAG_UTF8,
			(int) $entry['method'],
			$dt['time'],
			$dt['date'],
			(int) $entry['crc'],
			$zip64 ? self::LIMIT_32 : (int) $entry['csize'],
			$zip64 ? self::LIMIT_32 : (int) $entry['usize'],
			strlen( $entry['name'] ),
			strlen( $extra ),
			0, // Comment length.
			0, // Disk number start.
			0, // Internal attributes.
			0, // External attributes.
			$zip64 ? self::LIMIT_32 : (int) $entry['offset']
		) . $entry['name'] . $extra;
	}

	/**
	 * End of central directory, preceded by the zip64 record and locator
	 * when any value does not fit the classic fields.
	 *
	 * @param int $entries   Number of entries.
	 * @param int $cd_size   Central directory size.
	 * @param int $cd_offset Central directory offset.
	 * @return string
	 */
	public static function end_of_central_directory( int $entries, int $cd_size, int $cd_offset ): string {
		$zip64 = $entries >= self::LIMIT_16 || $cd_size >= self::LIMIT_32 || $cd_offset >= self::LIMIT_32;
		$out   = '';
		if ( $zip64 ) {
			$record_offset = $cd_offset + $cd_size;
			$out          .= pack( 'V', self::SIG_ZIP64_EOCD ) . self::u64( 44 ) . pack( 'vvVV', self::VERSION_ZIP64, self::VERSION_ZIP64, 0, 0 )
				. self::u64( $entries ) . self::u64( $entries ) . self::u64( $cd_size ) . self::u64( $cd_offset );
			$out          .= pack( 'VV', self::SIG_ZIP64_LOCATOR, 0 ) . self::u64( $record_offset ) . pack( 'V', 1 );
		}
		$out .= pack(
			'VvvvvVVv',
			self::SIG_EOCD,
			0,
			0,
			$zip64 ? self::LIMIT_16 : $entries,
			$zip64 ? self::LIMIT_16 : $entries,
			$zip64 ? self::LIMIT_32 : $cd_size,
			$zip64 ? self::LIMIT_32 : $cd_offset,
			0
		);
		return $out;
	}

	/**
	 * Parse the tail of a file: the end of central directory (and zip64 records).
	 *
	 * @param string $tail Last bytes of the file (up to 64 KiB + 22).
	 * @param int    $tail_offset Absolute offset of $tail[0] in the file.
	 * @return array{entries: int, cd_size: int, cd_offset: int}|null Null when no end of central directory is found.
	 */
	public static function parse_end( string $tail, int $tail_offset ) {
		$pos = strrpos( $tail, pack( 'V', self::SIG_EOCD ) );
		if ( false === $pos || strlen( $tail ) - $pos < 22 ) {
			return null;
		}
		$eocd = unpack( 'Vsig/vdisk/vcddisk/ventries/vtotal/Vsize/Voffset/vcomment', substr( $tail, $pos, 22 ) );
		$out  = array(
			'entries'   => (int) $eocd['total'],
			'cd_size'   => (int) $eocd['size'],
			'cd_offset' => (int) $eocd['offset'],
		);
		if ( self::LIMIT_16 !== $out['entries'] && self::LIMIT_32 !== $out['cd_size'] && self::LIMIT_32 !== $out['cd_offset'] ) {
			return $out;
		}
		// zip64: the locator sits right before the end of central directory.
		if ( $pos < 20 ) {
			return null;
		}
		$locator = unpack( 'Vsig/Vdisk/Vlo/Vhi/Vdisks', substr( $tail, $pos - 20, 20 ) );
		if ( self::SIG_ZIP64_LOCATOR !== (int) $locator['sig'] ) {
			return null;
		}
		$record_offset = self::from_u64( (int) $locator['lo'], (int) $locator['hi'] );
		$rel           = $record_offset - $tail_offset;
		if ( $rel < 0 || $rel + 56 > strlen( $tail ) ) {
			return null;
		}
		$rec = unpack( 'Vsig/Vsizelo/Vsizehi/vmade/vneeded/Vdisk/Vcddisk/Vdentlo/Vdenthi/Ventlo/Venthi/Vsizelo2/Vsizehi2/Voffsetlo/Voffsethi', substr( $tail, $rel, 56 ) );
		if ( self::SIG_ZIP64_EOCD !== (int) $rec['sig'] ) {
			return null;
		}
		return array(
			'entries'   => self::from_u64( (int) $rec['entlo'], (int) $rec['enthi'] ),
			'cd_size'   => self::from_u64( (int) $rec['sizelo2'], (int) $rec['sizehi2'] ),
			'cd_offset' => self::from_u64( (int) $rec['offsetlo'], (int) $rec['offsethi'] ),
		);
	}

	/**
	 * Parse one central directory header at the start of $data.
	 *
	 * @param string $data Bytes starting at a central header.
	 * @return array{name: string, method: int, flags: int, crc: int, csize: int, usize: int, offset: int, length: int}|null Null when malformed; length is the header's total size.
	 */
	public static function parse_central_header( string $data ) {
		if ( strlen( $data ) < 46 ) {
			return null;
		}
		$h = unpack( 'Vsig/vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnamelen/vextralen/vcommentlen/vdisk/vinternal/Vexternal/Voffset', substr( $data, 0, 46 ) );
		if ( self::SIG_CENTRAL !== (int) $h['sig'] ) {
			return null;
		}
		$length = 46 + $h['namelen'] + $h['extralen'] + $h['commentlen'];
		if ( strlen( $data ) < $length ) {
			return null;
		}
		$name  = substr( $data, 46, $h['namelen'] );
		$extra = substr( $data, 46 + $h['namelen'], $h['extralen'] );
		$usize = (int) $h['usize'];
		$csize = (int) $h['csize'];
		$off   = (int) $h['offset'];
		if ( self::LIMIT_32 === $usize || self::LIMIT_32 === $csize || self::LIMIT_32 === $off ) {
			$z = self::find_zip64_extra( $extra );
			if ( null === $z ) {
				return null;
			}
			try {
				$p = 0;
				if ( self::LIMIT_32 === $usize ) {
					$usize = self::read_u64( $z, $p );
					$p    += 8;
				}
				if ( self::LIMIT_32 === $csize ) {
					$csize = self::read_u64( $z, $p );
					$p    += 8;
				}
				if ( self::LIMIT_32 === $off ) {
					$off = self::read_u64( $z, $p );
				}
			} catch ( \RuntimeException $e ) {
				return null;
			}
		}
		return array(
			'name'   => $name,
			'method' => (int) $h['method'],
			'flags'  => (int) $h['flags'],
			'crc'    => (int) $h['crc'],
			'csize'  => $csize,
			'usize'  => $usize,
			'offset' => $off,
			'length' => $length,
		);
	}

	/**
	 * Size of the local header at $data (signature checked), so the reader
	 * can find where the entry's bytes start.
	 *
	 * @param string $data First 30 bytes of a local header.
	 * @return int|null
	 */
	public static function local_header_length( string $data ) {
		if ( strlen( $data ) < 30 ) {
			return null;
		}
		$h = unpack( 'Vsig/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnamelen/vextralen', $data );
		if ( self::SIG_LOCAL !== (int) $h['sig'] ) {
			return null;
		}
		return 30 + $h['namelen'] + $h['extralen'];
	}

	/**
	 * Unsigned 64-bit little-endian.
	 *
	 * @param int $value Value (non-negative).
	 * @return string
	 */
	public static function u64( int $value ): string {
		return pack( 'P', $value );
	}

	/**
	 * Combine the two halves of a 64-bit value.
	 *
	 * @param int $lo Low 32 bits.
	 * @param int $hi High 32 bits.
	 * @return int
	 * @throws \RuntimeException When the value does not fit a 32-bit PHP.
	 */
	private static function from_u64( int $lo, int $hi ): int {
		if ( PHP_INT_SIZE < 8 ) {
			if ( 0 !== $hi || $lo < 0 ) {
				throw new \RuntimeException( 'A value in this archive does not fit a 32-bit PHP.' );
			}
			return $lo;
		}
		if ( $hi < 0 || $hi > 0x7FFFFFFF ) {
			// Bit 63 set: the value would come out negative and pass every "< limit" check.
			throw new \RuntimeException( 'A value in this archive is out of range.' );
		}
		return ( $hi << 32 ) | ( $lo & 0xFFFFFFFF );
	}

	/**
	 * Read a 64-bit little-endian value at $pos.
	 *
	 * @param string $data Bytes.
	 * @param int    $pos  Offset.
	 * @return int
	 * @throws \RuntimeException When the field is truncated or the value out of range.
	 */
	private static function read_u64( string $data, int $pos ): int {
		if ( $pos < 0 || strlen( $data ) < $pos + 8 ) {
			throw new \RuntimeException( 'A zip64 field is truncated.' );
		}
		$parts = unpack( 'Vlo/Vhi', substr( $data, $pos, 8 ) );
		return self::from_u64( (int) $parts['lo'], (int) $parts['hi'] );
	}

	/**
	 * The body of the zip64 extra field, or null.
	 *
	 * @param string $extra Extra field bytes.
	 * @return string|null
	 */
	private static function find_zip64_extra( string $extra ) {
		$pos    = 0;
		$length = strlen( $extra );
		while ( $pos + 4 <= $length ) {
			$h    = unpack( 'vid/vsize', substr( $extra, $pos, 4 ) );
			$size = (int) $h['size'];
			if ( $pos + 4 + $size > $length ) {
				return null; // A field that claims more bytes than the extra area holds.
			}
			if ( self::ZIP64_EXTRA_ID === (int) $h['id'] ) {
				return substr( $extra, $pos + 4, $size );
			}
			$pos += 4 + $size;
		}
		return null;
	}
}
