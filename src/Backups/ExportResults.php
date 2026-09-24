<?php
/**
 * Which backup each recent export made.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Backups;

use WPCheckpoint\Jobs\PreflightStep;
use WPCheckpoint\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The installation option wpcheckpoint_export_results: export job id =>
 * the base name of the backup it stored, for the last MAX exports. Written
 * when the store step finishes (again on a replay: the same pair), read by
 * the screen to link and highlight a finished export's backup. The job's
 * work directory also names it, but maintenance removes that directory
 * soon after the job ends. Network-wide on multisite; removed on uninstall.
 */
final class ExportResults {

	const OPTION = 'wpcheckpoint_export_results';
	const MAX    = 20;

	/**
	 * Remember the backup an export stored.
	 *
	 * @param int    $job  Export job id.
	 * @param string $base Backup base name.
	 * @return void
	 */
	public static function record( int $job, string $base ): void {
		if ( $job <= 0 || 1 !== preg_match( PreflightStep::BASE_PATTERN, $base ) ) {
			return;
		}
		$map         = self::all();
		$map[ $job ] = $base;
		ksort( $map );
		Options::set( self::OPTION, array_slice( $map, -self::MAX, null, true ) );
	}

	/**
	 * The backup an export stored, or '' when it is not known (any more).
	 *
	 * @param int $job Export job id.
	 * @return string
	 */
	public static function base_of( int $job ): string {
		return self::all()[ $job ] ?? '';
	}

	/**
	 * The map, only well-formed pairs.
	 *
	 * @return array<int, string>
	 */
	private static function all(): array {
		$value = Options::get( self::OPTION, array() );
		$out   = array();
		foreach ( is_array( $value ) ? $value : array() as $job => $base ) {
			if ( (int) $job > 0 && is_string( $base ) && 1 === preg_match( PreflightStep::BASE_PATTERN, $base ) ) {
				$out[ (int) $job ] = $base;
			}
		}
		return $out;
	}
}
