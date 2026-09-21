<?php
/**
 * The files the export steps hand each other through the work directory.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Archive\Manifest;

/**
 * The three JSON files under the job's work directory, each written whole
 * by exactly one step and never appended to.
 *
 * PreflightStep writes plan.json (the backup's base name, the tables in
 * export order with their notes, the content groups and exclusion
 * patterns to scan with) and preflight.json (what it found: oversized
 * rows per table, environment notes). ReviewStep writes review.json (the
 * findings it put to the user and the decisions, from a policy or from
 * answers; written without "decisions" while the questions are open).
 *
 * Later steps never modify these; effective() combines plan and review
 * into what the database and file steps act on, so a step that runs
 * twice (a resumed review, a retry) always derives the same result from
 * the same inputs. Writes go through a temporary file and one rename.
 */
final class ExportPlan {

	const PLAN      = 'plan.json';
	const PREFLIGHT = 'preflight.json';
	const REVIEW    = 'review.json';

	const MAX_BYTES = 4194304;

	/**
	 * Read and decode one of the files.
	 *
	 * @param string $work Work directory.
	 * @param string $name One of the constants.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When the file is missing or not a JSON object (the work directory was lost or changed).
	 */
	public static function read( string $work, string $name ): array {
		$path = $work . DIRECTORY_SEPARATOR . $name;
		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file is reported below.
		if ( false === $size || $size > self::MAX_BYTES ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message with a file name.
			throw new \RuntimeException( sprintf( 'The file %s of this job is missing or too large; the work directory was lost or changed.', $name ) );
		}
		$json = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- small file in the job's own work directory, size checked above.
		$data = is_string( $json ) ? json_decode( $json, true, 16 ) : null;
		if ( ! is_array( $data ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message with a file name.
			throw new \RuntimeException( sprintf( 'The file %s of this job cannot be read; the work directory was lost or changed.', $name ) );
		}
		return $data;
	}

	/**
	 * Whether one of the files exists.
	 *
	 * @param string $work Work directory.
	 * @param string $name One of the constants.
	 * @return bool
	 */
	public static function exists( string $work, string $name ): bool {
		return is_file( $work . DIRECTORY_SEPARATOR . $name );
	}

	/**
	 * Write one of the files whole: a temporary file next to it, then one
	 * rename, so a reader sees the old content or the new, never a part.
	 *
	 * @param string               $work Work directory.
	 * @param string               $name One of the constants.
	 * @param array<string, mixed> $data Content.
	 * @return void
	 * @throws TransientFailure When the file cannot be written.
	 * @throws \RuntimeException When the content cannot be encoded.
	 */
	public static function write( string $work, string $name, array $data ): void {
		$json = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure PHP class, also unit-tested without WordPress.
		if ( ! is_string( $json ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message with a file name.
			throw new \RuntimeException( sprintf( 'The file %s of this job could not be encoded.', $name ) );
		}
		$path = $work . DIRECTORY_SEPARATOR . $name;
		$tmp  = $path . '.tmp';
		// Silenced: a warning would put the full path into the error log, bypassing the path masking.
		$written = @file_put_contents( $tmp, $json, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- see above; failure is thrown.
		if ( false === $written || strlen( $json ) !== $written || ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- see above.
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- best effort.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message with a file name.
			throw new TransientFailure( sprintf( 'The file %s of this job could not be written.', $name ) );
		}
	}

	/**
	 * What the export acts on: the plan's tables less the ones the review
	 * decided to leave out, the plan's exclusions plus the directories the
	 * review decided to leave out, and the tables whose oversized rows are
	 * left out. Pure: the same plan and review give the same result.
	 *
	 * @param array<string, mixed> $plan   plan.json.
	 * @param array<string, mixed> $review review.json (with decisions).
	 * @return array{tables: string[], notes: string[], groups: string[], exclusions: string[], exclude_oversize: string[], oversize_counts: array<string, int|null>}
	 * @throws \RuntimeException When the review holds no decisions (the review step did not finish).
	 */
	public static function effective( array $plan, array $review ): array {
		if ( ! isset( $review['decisions'] ) || ! is_array( $review['decisions'] ) ) {
			throw new \RuntimeException( 'The review of this job holds no decisions; the work directory was lost or changed.' );
		}
		$decisions = $review['decisions'];
		$tables    = self::strings( $plan, 'tables' );
		$notes     = self::strings( $plan, 'notes' );
		$excluded  = isset( $decisions['exclude_tables'] ) && is_array( $decisions['exclude_tables'] ) ? array_map( 'strval', $decisions['exclude_tables'] ) : array();
		$oversize  = isset( $decisions['exclude_oversize'] ) && is_array( $decisions['exclude_oversize'] ) ? array_map( 'strval', $decisions['exclude_oversize'] ) : array();
		$dirs      = isset( $decisions['exclude_dirs'] ) && is_array( $decisions['exclude_dirs'] ) ? array_map( 'strval', $decisions['exclude_dirs'] ) : array();
		$counts    = array();
		foreach ( isset( $review['findings']['oversize'] ) && is_array( $review['findings']['oversize'] ) ? $review['findings']['oversize'] : array() as $finding ) {
			if ( is_array( $finding ) && isset( $finding['table'] ) ) {
				$counts[ (string) $finding['table'] ] = isset( $finding['count'] ) && is_int( $finding['count'] ) ? $finding['count'] : null;
			}
		}
		return array(
			'tables'           => array_values( array_diff( $tables, $excluded ) ),
			'notes'            => array_merge( $notes, self::strings( $decisions, 'notes' ) ),
			'groups'           => self::strings( $plan, 'groups' ),
			'exclusions'       => array_values( array_unique( array_merge( self::strings( $plan, 'exclusions' ), $dirs ) ) ),
			'exclude_oversize' => array_values( array_intersect( $oversize, $tables ) ),
			'oversize_counts'  => $counts,
		);
	}

	/**
	 * A list of strings from a key, empty when absent.
	 *
	 * @param array<string, mixed> $data Data.
	 * @param string               $key  Key.
	 * @return string[]
	 */
	private static function strings( array $data, string $key ): array {
		if ( ! isset( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
			return array();
		}
		$out = array();
		foreach ( $data[ $key ] as $value ) {
			if ( is_string( $value ) && strlen( $value ) <= Manifest::MAX_TABLE_NAME * 16 ) {
				$out[] = $value;
			}
		}
		return $out;
	}
}
