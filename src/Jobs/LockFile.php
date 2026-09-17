<?php
/**
 * The per-job lock file in the storage tmp/ directory.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

/**
 * The tmp/job-<id>.lock file tells observers that cannot read this database (the
 * clone-side directory take-over of T005, uninstall) that a job may be
 * working in the directory. It exists from the first acquire until the job
 * reaches completed, failed or cancelled, and records the latest lease
 * expiry; between two ticks the recorded lease may have passed, which
 * observers treat as "recently active" for a grace period. The database
 * lock stays authoritative for mutual exclusion; the file mirrors it.
 *
 * Contents: job id, SHA-256 of the lock token (never the token itself: the
 * directory may be readable over HTTP when protection could not be
 * verified), the lock expiry and the creation time. Observers only need
 * "is there a job" and "is its lock expired"; the engine compares hashes.
 */
final class LockFile {

	const PREFIX = 'job-';
	const SUFFIX = '.lock';

	/**
	 * Path of the lock file for a job.
	 *
	 * @param string $base   Storage base directory.
	 * @param int    $job_id Job id.
	 * @return string
	 */
	public static function path( string $base, int $job_id ): string {
		return rtrim( $base, '/\\' ) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . self::PREFIX . $job_id . self::SUFFIX;
	}

	/**
	 * Hash stored for a lock token.
	 *
	 * @param string $token Lock token.
	 * @return string
	 */
	public static function token_hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Write (or overwrite) the lock file.
	 *
	 * @param string $base         Storage base directory.
	 * @param int    $job_id       Job id.
	 * @param string $token        Lock token (hashed before writing).
	 * @param int    $locked_until Expiry timestamp.
	 * @return bool
	 */
	public static function write( string $base, int $job_id, string $token, int $locked_until ): bool {
		$path = self::path( $base, $job_id );
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		$contents = 'job:' . $job_id . "\n"
			. 'token_sha256:' . self::token_hash( $token ) . "\n"
			. 'locked_until:' . $locked_until . "\n"
			. 'written:' . time() . "\n";
		return false !== file_put_contents( $path, $contents, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- plugin-owned directory.
	}

	/**
	 * Remove the lock file (no error when missing).
	 *
	 * @param string $base   Storage base directory.
	 * @param int    $job_id Job id.
	 * @return void
	 */
	public static function remove( string $base, int $job_id ): void {
		$path = self::path( $base, $job_id );
		if ( is_file( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- best effort removal of the plugin's own file.
		}
	}

	/**
	 * Parse a lock file.
	 *
	 * @param string $path Lock file path.
	 * @return array{job: int, token_sha256: string, locked_until: int, written: int}|null Null when unreadable or malformed.
	 */
	public static function read( string $path ) {
		if ( ! is_file( $path ) ) {
			return null;
		}
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- tiny local file.
		if ( ! is_string( $contents ) ) {
			return null;
		}
		$data = array(
			'job'          => 0,
			'token_sha256' => '',
			'locked_until' => 0,
			'written'      => 0,
		);
		foreach ( explode( "\n", $contents ) as $line ) {
			$parts = explode( ':', trim( $line ), 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			switch ( $parts[0] ) {
				case 'job':
				case 'locked_until':
				case 'written':
					$data[ $parts[0] ] = (int) $parts[1];
					break;
				case 'token_sha256':
					$data['token_sha256'] = strtolower( trim( $parts[1] ) );
					break;
			}
		}
		return 0 === $data['job'] ? null : $data;
	}

	/**
	 * Whether a lock file belongs to the given token.
	 *
	 * @param string $path  Lock file path.
	 * @param string $token Lock token.
	 * @return bool
	 */
	public static function is_owned_by( string $path, string $token ): bool {
		$data = self::read( $path );
		return null !== $data && '' !== $data['token_sha256'] && hash_equals( self::token_hash( $token ), $data['token_sha256'] );
	}

	/**
	 * Whether a lock file's recorded lock expired more than $grace seconds ago.
	 *
	 * @param string $path  Lock file path.
	 * @param int    $now   Unix timestamp.
	 * @param int    $grace Seconds after expiry before the file counts as stale.
	 * @return bool True for malformed files as well.
	 */
	public static function is_stale( string $path, int $now, int $grace ): bool {
		$data = self::read( $path );
		if ( null === $data ) {
			return true;
		}
		return $data['locked_until'] + $grace < $now;
	}

	/**
	 * Job id encoded in a lock file name, 0 when the name does not match.
	 *
	 * @param string $path Lock file path.
	 * @return int
	 */
	public static function job_id_from_path( string $path ): int {
		if ( preg_match( '/^' . preg_quote( self::PREFIX, '/' ) . '(\d+)' . preg_quote( self::SUFFIX, '/' ) . '$/', basename( $path ), $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}
}
