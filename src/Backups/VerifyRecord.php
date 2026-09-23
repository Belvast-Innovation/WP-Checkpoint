<?php
/**
 * The result of the latest check of a backup, kept next to it.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Backups;

use WPCheckpoint\Archive\ArchiveVerifier;
use WPCheckpoint\Archive\VerificationResult;

/**
 * The file backups/{base}.verify.json: one line about the latest check, which
 * outlives the verify job (jobs are purged). Identifiers, counts and fixed
 * vocabulary only: the finding texts stay in the job and its log. Read with
 * a hand-written, fail-closed parser: a missing, damaged or unknown record
 * means "not verified", never "intact".
 *
 * The manifest_sha256 field ties the record to the standalone manifest it was made
 * for: a manifest replaced in any way (uploaded again, replaced by hand, a
 * new backup under a reused name) no longer matches. It does not cover
 * volumes replaced under an unchanged manifest; the record says what the
 * latest check found, not that the files are still the same.
 *
 * Pure PHP.
 */
final class VerifyRecord {

	const FORMAT    = 'wpcheckpoint-verify';
	const VERSION   = 1;
	const SUFFIX    = '.verify.json';
	const MAX_BYTES = 4096;

	/**
	 * Finding kinds counted in the record.
	 */
	const KINDS = array( 'changed', 'corrupt', 'environment', 'malformed', 'missing', 'unsupported', 'unverified' );

	/**
	 * Outcomes a record may hold.
	 */
	const OUTCOMES = array(
		VerificationResult::PASSED,
		VerificationResult::PASSED_PARTIAL,
		VerificationResult::FAILED,
		VerificationResult::INVALID,
		VerificationResult::UNSUPPORTED_LAYOUT,
		VerificationResult::CHANGED,
		VerificationResult::UNREADABLE,
	);

	/**
	 * The record's file name for a base name.
	 *
	 * @param string $base Backup base name.
	 * @return string
	 */
	public static function file_name( string $base ): string {
		return $base . self::SUFFIX;
	}

	/**
	 * A record for a finished check.
	 *
	 * @param string             $base            Backup base name.
	 * @param string             $manifest_sha256 Hash of the standalone manifest the check was about.
	 * @param int                $verified_at     When the check finished (Unix time).
	 * @param string             $depth           ArchiveVerifier depth.
	 * @param VerificationResult $result          Result.
	 * @param int                $job             Verify job id.
	 * @return array<string, mixed>
	 */
	public static function from_result( string $base, string $manifest_sha256, int $verified_at, string $depth, VerificationResult $result, int $job ): array {
		$kinds = array();
		foreach ( self::KINDS as $kind ) {
			$kinds[ $kind ] = (int) ( $result->kinds()[ $kind ] ?? 0 );
		}
		return array(
			'format'           => self::FORMAT,
			'version'          => self::VERSION,
			'base'             => $base,
			'manifest_sha256'  => $manifest_sha256,
			'verified_at'      => gmdate( 'Y-m-d\TH:i:s\Z', $verified_at ),
			'depth'            => $depth,
			'outcome'          => $result->outcome(),
			'complete'         => $result->is_complete_pass(),
			'restore_refused'  => $result->restore_refused(),
			'cause'            => '' === $result->unreadable_cause() ? null : $result->unreadable_cause(),
			'findings_total'   => $result->findings_total(),
			'findings_by_kind' => $kinds,
			'job'              => $job,
		);
	}

	/**
	 * Encode; throws rather than writing a partial record.
	 *
	 * @param array<string, mixed> $record Record.
	 * @return string
	 * @throws \RuntimeException When it cannot be encoded.
	 */
	public static function to_json( array $record ): string {
		$json = json_encode( $record, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure PHP class.
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_BYTES ) {
			throw new \RuntimeException( 'The verification record could not be encoded.' );
		}
		return $json . "\n";
	}

	/**
	 * Parse and validate; null for anything that is not a valid record of
	 * this version for this base.
	 *
	 * @param string $json JSON.
	 * @param string $base Backup base name the record must be about.
	 * @return array<string, mixed>|null
	 */
	public static function from_json( string $json, string $base ) {
		if ( strlen( $json ) > self::MAX_BYTES ) {
			return null;
		}
		$data = json_decode( $json, true, 4 );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$ok = self::FORMAT === ( $data['format'] ?? null )
			&& self::VERSION === ( $data['version'] ?? null )
			&& ( $data['base'] ?? null ) === $base
			&& is_string( $data['manifest_sha256'] ?? null ) && 1 === preg_match( '/\A[0-9a-f]{64}\z/', $data['manifest_sha256'] )
			&& is_string( $data['verified_at'] ?? null ) && 1 === preg_match( '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $data['verified_at'] )
			&& in_array( $data['depth'] ?? null, array( ArchiveVerifier::DEPTH_STRUCTURE, ArchiveVerifier::DEPTH_FULL ), true )
			&& in_array( $data['outcome'] ?? null, self::OUTCOMES, true )
			&& is_bool( $data['complete'] ?? null )
			&& is_bool( $data['restore_refused'] ?? null )
			&& ( null === ( $data['cause'] ?? null ) || ( is_string( $data['cause'] ) && 1 === preg_match( '/\A[a-z]{1,16}\z/', $data['cause'] ) ) )
			&& is_int( $data['findings_total'] ?? null ) && $data['findings_total'] >= 0
			&& is_int( $data['job'] ?? null ) && $data['job'] > 0
			&& is_array( $data['findings_by_kind'] ?? null );
		if ( ! $ok ) {
			return null;
		}
		foreach ( self::KINDS as $kind ) {
			if ( ! is_int( $data['findings_by_kind'][ $kind ] ?? null ) || $data['findings_by_kind'][ $kind ] < 0 ) {
				return null;
			}
		}
		if ( array() !== array_diff( array_keys( $data['findings_by_kind'] ), self::KINDS ) ) {
			return null;
		}
		// Only the validated fields, in their order: nothing else in the file reaches a response.
		$record = array();
		foreach ( array( 'format', 'version', 'base', 'manifest_sha256', 'verified_at', 'depth', 'outcome', 'complete', 'restore_refused', 'cause', 'findings_total' ) as $key ) {
			$record[ $key ] = $data[ $key ] ?? null;
		}
		$record['findings_by_kind'] = array();
		foreach ( self::KINDS as $kind ) {
			$record['findings_by_kind'][ $kind ] = $data['findings_by_kind'][ $kind ];
		}
		$record['job'] = $data['job'];
		return $record;
	}
}
