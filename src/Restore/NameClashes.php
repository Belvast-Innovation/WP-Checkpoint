<?php
/**
 * Paths of a backup that one file system would put on one file.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * Pure PHP. A files index can list millions of paths, so the check runs
 * in two bounded passes over work files instead of one array in memory:
 *
 * 1. While the index is read, every path gives a record per bucket: its
 *    own key ("f") and the key of each directory it lies in ("d"), keyed
 *    under the staging parent and that parent's TargetNames. A directory
 *    shared with the previous path is not written again (the index lists
 *    a directory's files together, so almost every directory is written
 *    once). A record is fixed-width: 16 hex of the key's SHA-1, the type,
 *    the index offset of the line it came from.
 * 2. One bucket per unit: two records with one key clash when either is a
 *    file ("f" and "f" of different lines: one file would overwrite the
 *    other; "f" and "d": a file where a directory must be). Two "d" are
 *    one directory, as they should be; the same record twice is a replayed
 *    unit, not a clash.
 *
 * The bucket is chosen by the key's hash, so each bucket holds about
 * 1/BUCKETS of the records; one bucket must fit MAX_BUCKET_BYTES to be
 * read in one unit.
 */
final class NameClashes {

	const BUCKETS = 256;

	/**
	 * Largest bucket read in one unit: 4 MiB of records (about 140,000 keys, about 12 MB in memory).
	 */
	const MAX_BUCKET_BYTES = 4194304;

	/**
	 * Bytes of one record: 16 hex, the type, 12 digits of offset, a newline.
	 */
	const RECORD_BYTES = 30;

	/**
	 * Largest index offset a record holds.
	 */
	const MAX_OFFSET = 999999999999;

	/**
	 * The records of one path, by bucket.
	 *
	 * @param int         $slot     Staging parent (its position among the staged parents).
	 * @param string      $path     Path under the staging root ("{group}/{relative}").
	 * @param TargetNames $names    How that parent's file system compares names.
	 * @param int         $offset   Index offset of the line.
	 * @param string      $previous The previous line's path under the same parent ('' for none).
	 * @return array<int, string> Bucket => records.
	 * @throws \InvalidArgumentException When the offset is out of range.
	 */
	public static function records( int $slot, string $path, TargetNames $names, int $offset, string $previous ): array {
		if ( $offset < 0 || $offset > self::MAX_OFFSET ) {
			throw new \InvalidArgumentException( 'An index offset out of range.' );
		}
		$out   = array();
		$parts = explode( '/', $path );
		$old   = '' === $previous ? array() : explode( '/', $previous );
		$same  = true;
		$last  = count( $parts );
		$depth = 1;
		for ( ; $depth < $last; $depth++ ) {
			// The same directory as the previous path at every level so far: already written.
			$same = $same && isset( $old[ $depth - 1 ] ) && count( $old ) > $depth && $old[ $depth - 1 ] === $parts[ $depth - 1 ];
			if ( ! $same ) {
				self::add( $out, $slot, implode( '/', array_slice( $parts, 0, $depth ) ), 'd', $names, $offset );
			}
		}
		self::add( $out, $slot, $path, 'f', $names, $offset );
		return $out;
	}

	/**
	 * Add one record.
	 *
	 * @param array<int, string> $out    Bucket => records (updated).
	 * @param int                $slot   Staging parent.
	 * @param string             $path   Path under the staging root.
	 * @param string             $type   "f" or "d".
	 * @param TargetNames        $names  Names of that file system.
	 * @param int                $offset Index offset.
	 * @return void
	 */
	private static function add( array &$out, int $slot, string $path, string $type, TargetNames $names, int $offset ): void {
		$hash           = sha1( $slot . ':' . $names->key( $path ) );
		$bucket         = (int) hexdec( substr( $hash, 0, 2 ) ) % self::BUCKETS;
		$record         = substr( $hash, 2, 16 ) . $type . str_pad( (string) $offset, 12, '0', STR_PAD_LEFT ) . "\n";
		$out[ $bucket ] = ( $out[ $bucket ] ?? '' ) . $record;
	}

	/**
	 * The first clash in one bucket's records: the index offsets of the two lines, or null.
	 *
	 * @param string $records The bucket's records (whole records only).
	 * @return array{0: int, 1: int}|null
	 * @throws \UnexpectedValueException When the records are not records.
	 */
	public static function first_clash( string $records ) {
		if ( 0 !== strlen( $records ) % self::RECORD_BYTES ) {
			throw new \UnexpectedValueException( 'A bucket of name keys does not hold whole records.' );
		}
		$seen = array();
		for ( $at = 0, $end = strlen( $records ); $at < $end; $at += self::RECORD_BYTES ) {
			$record = substr( $records, $at, self::RECORD_BYTES );
			if ( 1 !== preg_match( '/\A([a-f0-9]{16})([fd])([0-9]{12})\n\z/', $record, $m ) ) {
				throw new \UnexpectedValueException( 'A bucket of name keys holds something that is not a record.' );
			}
			// Offset * 2 + 1 for a directory: one integer per key keeps a bucket's array small.
			$value = (int) $m[3] * 2 + ( 'd' === $m[2] ? 1 : 0 );
			if ( ! isset( $seen[ $m[1] ] ) ) {
				$seen[ $m[1] ] = $value;
				continue;
			}
			$first = $seen[ $m[1] ];
			if ( $first === $value || ( 1 === $first % 2 && 1 === $value % 2 ) ) {
				continue; // The same record again (a replayed unit), or one directory twice.
			}
			return array( intdiv( $first, 2 ), intdiv( $value, 2 ) );
		}
		return null;
	}
}
