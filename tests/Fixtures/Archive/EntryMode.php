<?php

namespace WPCheckpoint\Tests\Fixtures\Archive;

use WPCheckpoint\Archive\ZipReader;

/**
 * Give an entry of a zip another Unix mode and creator host, in place: what another tool writes for a
 * symbolic link, a FIFO, a device or a socket (this plugin writes regular files only). The central
 * header's "version made by" is at offset 4 and its external attributes at offset 38; nothing else changes
 * (a zip has no checksum over its central directory).
 */
final class EntryMode {

	/**
	 * Set an entry's mode.
	 *
	 * @param string $zip  Zip file.
	 * @param string $name Entry name.
	 * @param int    $mode Unix mode (type and permissions), e.g. 0120777 for a symbolic link.
	 * @param int    $host Creator host (3: Unix, 0: MS-DOS).
	 */
	public static function set( string $zip, string $name, int $mode, int $host = 3 ): void {
		$entry = ZipReader::open( $zip )->find( $name );
		if ( null === $entry ) {
			throw new \RuntimeException( 'No such entry: ' . $name );
		}
		$handle = fopen( $zip, 'r+b' );
		fseek( $handle, (int) $entry['cd_offset'] + 4 );
		$made = unpack( 'v', (string) fread( $handle, 2 ) )[1];
		fseek( $handle, (int) $entry['cd_offset'] + 4 );
		fwrite( $handle, pack( 'v', ( $host << 8 ) | ( $made & 0xFF ) ) );
		fseek( $handle, (int) $entry['cd_offset'] + 38 );
		fwrite( $handle, pack( 'V', ( $mode & 0xFFFF ) << 16 ) );
		fclose( $handle );
	}
}
