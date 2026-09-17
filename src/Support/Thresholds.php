<?php
/**
 * Pure judgement rules for environment values.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

/**
 * Turns raw numbers into statuses. No WordPress dependencies so the rules
 * are unit tested directly.
 */
final class Thresholds {

	const MEMORY_ERROR_BYTES   = 67108864;  // 64 MB
	const MEMORY_WARNING_BYTES = 134217728; // 128 MB
	const TIME_WARNING_SECONDS = 20;
	const BUDGET_MIN_SECONDS   = 5;
	const BUDGET_MAX_SECONDS   = 20;
	const DISK_ERROR_BYTES     = 209715200;  // 200 MB
	const DISK_WARNING_BYTES   = 1073741824; // 1 GB

	/**
	 * Status for a memory limit in bytes (-1 = unlimited).
	 *
	 * @param int $bytes Limit in bytes.
	 * @return string Check status.
	 */
	public static function memory_status( int $bytes ): string {
		if ( $bytes < 0 ) {
			return Check::OK;
		}
		if ( $bytes < self::MEMORY_ERROR_BYTES ) {
			return Check::ERROR;
		}
		if ( $bytes < self::MEMORY_WARNING_BYTES ) {
			return Check::WARNING;
		}
		return Check::OK;
	}

	/**
	 * Status for max_execution_time in seconds (0 = unlimited).
	 *
	 * @param int $seconds Limit in seconds.
	 * @return string Check status.
	 */
	public static function time_status( int $seconds ): string {
		if ( $seconds <= 0 ) {
			return Check::OK;
		}
		return $seconds < self::TIME_WARNING_SECONDS ? Check::WARNING : Check::OK;
	}

	/**
	 * Seconds one job step may run: half the limit, clamped to 5-20 seconds.
	 *
	 * @param int $max_execution_time Limit in seconds (0 = unlimited).
	 * @return int
	 */
	public static function time_budget( int $max_execution_time ): int {
		if ( $max_execution_time <= 0 ) {
			return self::BUDGET_MAX_SECONDS;
		}
		$half = (int) floor( $max_execution_time / 2 );
		return max( self::BUDGET_MIN_SECONDS, min( self::BUDGET_MAX_SECONDS, $half ) );
	}

	/**
	 * Status for free disk space.
	 *
	 * @param mixed $free Bytes, or anything else when the host does not report it.
	 * @return string Check status (INFO when unknown).
	 */
	public static function disk_status( $free ): string {
		if ( ! self::is_disk_value( $free ) ) {
			return Check::INFO;
		}
		if ( $free < self::DISK_ERROR_BYTES ) {
			return Check::ERROR;
		}
		if ( $free < self::DISK_WARNING_BYTES ) {
			return Check::WARNING;
		}
		return Check::OK;
	}

	/**
	 * Whether a disk_free_space()/disk_total_space() result is usable.
	 *
	 * @param mixed $value Raw result.
	 * @return bool
	 */
	public static function is_disk_value( $value ): bool {
		return ( is_int( $value ) || is_float( $value ) ) && is_finite( $value ) && $value > 0;
	}

	/**
	 * Database flavour, version and status from the server info string.
	 *
	 * @param string $server_info Result of $wpdb->db_server_info().
	 * @return array{flavor: string, version: string, status: string}
	 */
	public static function database( string $server_info ): array {
		$flavor  = false !== stripos( $server_info, 'mariadb' ) ? 'MariaDB' : 'MySQL';
		$version = '';
		if ( preg_match( '/(\d+\.\d+(?:\.\d+)?)/', $server_info, $m ) ) {
			$version = $m[1];
		}
		// MariaDB may report as "5.5.5-10.6.12-MariaDB"; take the part after the marker.
		if ( 'MariaDB' === $flavor && preg_match( '/5\.5\.5-(\d+\.\d+(?:\.\d+)?)/', $server_info, $m ) ) {
			$version = $m[1];
		}
		$minimum = 'MariaDB' === $flavor ? '10.4' : '5.7';
		$status  = '' === $version ? Check::INFO : ( version_compare( $version, $minimum, '>=' ) ? Check::OK : Check::WARNING );
		return array(
			'flavor'  => $flavor,
			'version' => $version,
			'status'  => $status,
		);
	}

	/**
	 * Status of a loopback probe outcome.
	 *
	 * @param string $outcome One of reachable|altered|http_auth|blocked|unreachable.
	 * @return string Check status.
	 */
	public static function loopback_status( string $outcome ): string {
		switch ( $outcome ) {
			case 'reachable':
				return Check::OK;
			case 'http_auth':
			case 'blocked':
				return Check::WARNING;
			default:
				return Check::ERROR;
		}
	}
}
