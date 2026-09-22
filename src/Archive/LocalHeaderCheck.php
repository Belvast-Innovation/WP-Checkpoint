<?php
/**
 * A local file header against its central directory record.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Archive;

/**
 * The fields a zip keeps twice, once before the data (local header) and
 * once in the central directory, must agree: readers that stream an
 * archive trust the local copy, readers that seek trust the central one,
 * and an archive where they differ reads differently depending on the
 * tool. This plugin writes both from the same values, so for its own
 * archives every field below is equal (a test packs every writer path and
 * checks it).
 *
 * Compared: name (bytes), general-purpose flags, method, DOS time and
 * date, CRC-32, compressed and uncompressed size (zip64 extra fields
 * resolved on both sides). Not compared, because they legitimately
 * differ: "version needed" and whether the local header carries a zip64
 * extra field (a writer may reserve zip64 locally before the final sizes
 * are known; the central record follows the final values), and the rest
 * of the extra fields (the central zip64 field also holds the offset).
 *
 * An entry with the data-descriptor flag (bit 3) may carry zeros for CRC
 * and sizes in its local header; only name, method and flags are
 * compared then, and the entry is reported as written by another tool.
 */
final class LocalHeaderCheck {

	/**
	 * Compare.
	 *
	 * @param array<string, mixed> $central Central record (ZipReader entry).
	 * @param array<string, mixed> $local   Local header (ZipReader::local_header()).
	 * @return array{data_descriptor: bool, fields: string[]} The fields that differ, in a fixed order.
	 */
	public static function compare( array $central, array $local ): array {
		$descriptor = 0 !== ( (int) $central['flags'] & ZipFormat::FLAG_DATA_DESCRIPTOR ) || 0 !== ( (int) $local['flags'] & ZipFormat::FLAG_DATA_DESCRIPTOR );
		$fields     = $descriptor ? array( 'name', 'flags', 'method' ) : array( 'name', 'flags', 'method', 'time', 'date', 'crc', 'csize', 'usize' );
		$differ     = array();
		foreach ( $fields as $field ) {
			$a = 'name' === $field ? (string) $central[ $field ] : (int) $central[ $field ];
			$b = 'name' === $field ? (string) $local[ $field ] : (int) $local[ $field ];
			if ( $a !== $b ) {
				$differ[] = $field;
			}
		}
		return array(
			'data_descriptor' => $descriptor,
			'fields'          => $differ,
		);
	}
}
