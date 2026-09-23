<?php
/**
 * The site's size estimate and the measured export rate.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Backups;

use WPCheckpoint\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Two installation options (network-wide on multisite, like the jobs
 * table; removed on uninstall):
 *
 * - wpcheckpoint_estimate: files and bytes the estimate job counted, when,
 *   and by which job. Used for 7 days; no job is started while it is fresh.
 * - wpcheckpoint_export_rate: bytes and seconds of the last export that
 *   finished, with the scope it covered (a signature of what it included
 *   and left out) and whether it waited for an answer. A time is only
 *   estimated from an export of the same scope that never waited: an
 *   export of the database alone says nothing about one with the files,
 *   and hours spent waiting for a decision are not a rate. No record, no
 *   time: the screen shows none rather than a guess.
 */
final class Estimate {

	const OPTION        = 'wpcheckpoint_estimate';
	const RATE_OPTION   = 'wpcheckpoint_export_rate';
	const VALID_SECONDS = 604800;

	/**
	 * After a failed estimate job, none is started again for this long.
	 */
	const FAILED_BACKOFF_SECONDS = 86400;

	/**
	 * The estimate if it is fresh, else null.
	 *
	 * @param int $now Unix time.
	 * @return array{files: int, bytes: int, computed_at: int, job: int}|null
	 */
	public static function current( int $now ) {
		$value = Options::get( self::OPTION, null );
		if ( ! is_array( $value ) || ! isset( $value['files'], $value['bytes'], $value['computed_at'], $value['job'] ) ) {
			return null;
		}
		if ( $now - (int) $value['computed_at'] > self::VALID_SECONDS ) {
			return null;
		}
		return array(
			'files'       => max( 0, (int) $value['files'] ),
			'bytes'       => max( 0, (int) $value['bytes'] ),
			'computed_at' => (int) $value['computed_at'],
			'job'         => (int) $value['job'],
		);
	}

	/**
	 * Keep an estimate.
	 *
	 * @param int $files Files counted.
	 * @param int $bytes Bytes counted.
	 * @param int $now   Unix time.
	 * @param int $job   Estimate job id.
	 * @return void
	 */
	public static function record( int $files, int $bytes, int $now, int $job ): void {
		Options::set(
			self::OPTION,
			array(
				'files'       => $files,
				'bytes'       => $bytes,
				'computed_at' => $now,
				'job'         => $job,
			)
		);
	}

	/**
	 * A signature of what an export covers: two exports with the same
	 * signature back up the same things.
	 *
	 * @param array<string, mixed> $options Normalized export options.
	 * @return string
	 */
	public static function scope( array $options ): string {
		$contents = isset( $options['contents'] ) && is_array( $options['contents'] ) ? $options['contents'] : array();
		$parts    = array(
			'database'       => ! empty( $contents['database'] ),
			'files'          => self::sorted( $contents['files'] ?? array() ),
			'exclusions'     => self::sorted( $options['exclusions'] ?? array() ),
			'exclude_tables' => self::sorted( $options['exclude_tables'] ?? array() ),
			'include_tables' => self::sorted( $options['include_tables'] ?? array() ),
		);
		return substr( hash( 'sha256', (string) wp_json_encode( $parts ) ), 0, 16 );
	}

	/**
	 * Keep the rate of an export that finished.
	 *
	 * @param int    $bytes   Bytes stored.
	 * @param int    $seconds Seconds from its start to its end.
	 * @param string $scope   Its scope().
	 * @param bool   $waited  Whether it waited for an answer.
	 * @param int    $now     Unix time.
	 * @return void
	 */
	public static function record_rate( int $bytes, int $seconds, string $scope, bool $waited, int $now ): void {
		Options::set(
			self::RATE_OPTION,
			array(
				'bytes'   => max( 0, $bytes ),
				'seconds' => max( 0, $seconds ),
				'scope'   => $scope,
				'waited'  => $waited,
				'at'      => $now,
			)
		);
	}

	/**
	 * Seconds an export of this scope and size would take here, from the
	 * last one of the same scope; null when there is no such measurement.
	 *
	 * @param int    $bytes Bytes to back up.
	 * @param string $scope scope() of the export to estimate.
	 * @return int|null
	 */
	public static function seconds_for( int $bytes, string $scope ) {
		$rate = Options::get( self::RATE_OPTION, null );
		if ( ! is_array( $rate ) || ( $rate['scope'] ?? '' ) !== $scope || ! empty( $rate['waited'] ) ) {
			return null;
		}
		$measured_bytes   = (int) ( $rate['bytes'] ?? 0 );
		$measured_seconds = (int) ( $rate['seconds'] ?? 0 );
		if ( $measured_bytes <= 0 || $measured_seconds <= 0 ) {
			return null;
		}
		return (int) ceil( $bytes * $measured_seconds / $measured_bytes );
	}

	/**
	 * Strings of a list, sorted.
	 *
	 * @param mixed $values List.
	 * @return string[]
	 */
	private static function sorted( $values ): array {
		$out = array_map( 'strval', is_array( $values ) ? $values : array() );
		sort( $out, SORT_STRING );
		return $out;
	}
}
