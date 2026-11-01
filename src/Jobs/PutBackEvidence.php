<?php
/**
 * What a put-back wrote over in a job's row, kept byte for byte.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Support\Protection;

defined( 'ABSPATH' ) || exit;

/**
 * The evidence of a put-back (wp wpcheckpoint job put-back): before its claim writes over a stuck restore's row, the
 * values of every column it writes are kept in a directory of their own under the storage directory's logs/, next to
 * the job's log: logs/job-<id>-putback-<UTC time>-<hex>/, with the JSON columns (cursor, options, questions) each in a
 * file holding the bytes the database holds (read through HEX(), so no character set conversion changes them; a NULL
 * column has no file), and row.json with the other columns and each file's SHA-256. The claim's cursor carries the
 * directory and the SHA-256 of row.json (SwapStep::put_back_cursor()), so a directory a process died writing is never
 * used: only after it was written whole is the row claimed.
 *
 * It is not masked (it is what the row held), so it is never offered: the log download gives only the job's log file
 * (JobPresenter), no REST route reads logs/, and the directory has the same protection files as logs/. It goes with the
 * job's row (JobRepository::delete_files_of()), and one whose row is gone is an orphan (Residue::PUT_BACK_EVIDENCE).
 */
final class PutBackEvidence {

	/**
	 * The JSON columns kept as files, and the other columns a put-back's claim writes over (row.json).
	 */
	const FILES    = array( 'cursor_json', 'options_json', 'questions_json' );
	const COLUMNS  = array( 'status', 'step', 'site_state', 'progress_message', 'last_error', 'failure_kind', 'finished_at', 'takeovers', 'takeover_mark', 'cron_deferrals', 'cancel_requested', 'lock_token', 'locked_until', 'committed_at' );
	const INTEGERS = array( 'site_state', 'finished_at', 'takeovers', 'cron_deferrals', 'cancel_requested', 'locked_until', 'committed_at' );

	/**
	 * The manifest's name, and the directory's name: job-<id>-putback-<YmdHis>-<8 hex>.
	 */
	const MANIFEST = 'row.json';

	/**
	 * The most bytes of a JSON column kept (and written back): far above what this plugin writes into a row (positions
	 * and identifiers), and small enough for one statement on a server with a 1 MB packet. A row holding more was not
	 * written by this plugin; a put-back refuses it (JobRepository::put_back_raw()).
	 */
	const MAX_BYTES = 262144;
	const NAME      = '/\Ajob-([1-9][0-9]{0,18})-putback-[0-9]{14}-[0-9a-f]{8}\z/';

	/**
	 * Keep a row's values (JobRepository::raw()) under a storage directory.
	 *
	 * @param string               $base   Storage directory.
	 * @param int                  $job_id Job id.
	 * @param array<string, mixed> $raw    Column => value: the JSON columns as their bytes or null, the others as read.
	 * @param int                  $now    Unix time.
	 * @return array{dir: string, sum: string} The directory relative to $base ("logs/…"), and the SHA-256 of row.json.
	 * @throws \RuntimeException When it cannot be written whole.
	 */
	public static function write( string $base, int $job_id, array $raw, int $now ): array {
		$name = 'job-' . $job_id . '-putback-' . gmdate( 'YmdHis', $now ) . '-' . bin2hex( random_bytes( 4 ) );
		$dir  = rtrim( $base, '/\\' ) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . $name;
		if ( ! @mkdir( $dir, 0700 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- a warning would put the path into the error log; said below.
			throw new \RuntimeException( 'The directory for what the put-back writes over could not be made.' );
		}
		if ( ! Protection::write( $dir ) ) {
			throw new \RuntimeException( 'The directory for what the put-back writes over could not be protected.' );
		}
		$manifest = array(
			'job'   => $job_id,
			'files' => array(),
			'row'   => array(),
		);
		foreach ( self::FILES as $column ) {
			$value = $raw[ $column ] ?? null;
			if ( null === $value ) {
				$manifest['files'][ $column ] = null;
				continue;
			}
			self::put( $dir . DIRECTORY_SEPARATOR . $column, (string) $value );
			$manifest['files'][ $column ] = hash( 'sha256', (string) $value );
		}
		foreach ( self::COLUMNS as $column ) {
			$manifest['row'][ $column ] = $raw[ $column ] ?? null;
		}
		$json = wp_json_encode( $manifest );
		if ( ! is_string( $json ) ) {
			throw new \RuntimeException( 'What the put-back writes over cannot be written down.' );
		}
		self::put( $dir . DIRECTORY_SEPARATOR . self::MANIFEST, $json );
		return array(
			'dir' => 'logs/' . $name,
			'sum' => hash( 'sha256', $json ),
		);
	}

	/**
	 * The values kept, read back and checked: the directory is a put-back's of this job, row.json has the SHA-256 the
	 * claim recorded, and every file has the SHA-256 row.json gives.
	 *
	 * @param string $base     Storage directory.
	 * @param int    $job_id   Job id.
	 * @param string $relative The directory relative to $base.
	 * @param string $sum      SHA-256 of row.json, as the claim recorded it.
	 * @return array<string, mixed> Column => value, as write() was given them.
	 * @throws \RuntimeException When it cannot be read, or is not what was written.
	 */
	public static function read( string $base, int $job_id, string $relative, string $sum ): array {
		$name = substr( $relative, 5 );
		if ( 0 !== strpos( $relative, 'logs/' ) || 1 !== preg_match( self::NAME, $name, $m ) || (int) $m[1] !== $job_id ) {
			throw new \RuntimeException( 'The put-back\'s record of what it wrote over is not where its claim says.' );
		}
		$dir  = rtrim( $base, '/\\' ) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . $name;
		$json = self::get( $dir . DIRECTORY_SEPARATOR . self::MANIFEST );
		if ( ! hash_equals( $sum, hash( 'sha256', $json ) ) ) {
			throw new \RuntimeException( 'The put-back\'s record of what it wrote over was changed.' );
		}
		$manifest = json_decode( $json, true );
		if ( ! is_array( $manifest ) || ! is_array( $manifest['files'] ?? null ) || ! is_array( $manifest['row'] ?? null ) || ( $manifest['job'] ?? null ) !== $job_id ) {
			throw new \RuntimeException( 'The put-back\'s record of what it wrote over cannot be read.' );
		}
		$out = array();
		foreach ( self::FILES as $column ) {
			if ( ! array_key_exists( $column, $manifest['files'] ) ) {
				throw new \RuntimeException( 'The put-back\'s record of what it wrote over is not whole.' );
			}
			if ( null === $manifest['files'][ $column ] ) {
				$out[ $column ] = null;
				continue;
			}
			$bytes = self::get( $dir . DIRECTORY_SEPARATOR . $column );
			if ( ! hash_equals( (string) $manifest['files'][ $column ], hash( 'sha256', $bytes ) ) ) {
				throw new \RuntimeException( 'The put-back\'s record of what it wrote over was changed.' );
			}
			$out[ $column ] = $bytes;
		}
		foreach ( self::COLUMNS as $column ) {
			if ( ! array_key_exists( $column, $manifest['row'] ) ) {
				throw new \RuntimeException( 'The put-back\'s record of what it wrote over is not whole.' );
			}
			$out[ $column ] = $manifest['row'][ $column ];
		}
		return $out;
	}

	/**
	 * The job id a directory name in logs/ is a put-back's evidence of (0 when it is not one).
	 *
	 * @param string $name Directory name.
	 * @return int
	 */
	public static function job_id_of( string $name ): int {
		return 1 === preg_match( self::NAME, $name, $m ) ? (int) $m[1] : 0;
	}

	/**
	 * Write a file that must not be there yet, in full.
	 *
	 * @param string $path  Path.
	 * @param string $bytes Contents.
	 * @return void
	 * @throws \RuntimeException When it cannot be written in full.
	 */
	private static function put( string $path, string $bytes ): void {
		$handle = @fopen( $path, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- a plugin-owned file; said below.
		if ( false === $handle ) {
			throw new \RuntimeException( 'What the put-back writes over could not be written down.' );
		}
		$written = @fwrite( $handle, $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see above.
		$closed  = @fclose( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		if ( strlen( $bytes ) !== $written || ! $closed ) {
			throw new \RuntimeException( 'What the put-back writes over could not be written down in full.' );
		}
	}

	/**
	 * Read a file whole (they are a row's columns: small).
	 *
	 * @param string $path Path.
	 * @return string
	 * @throws \RuntimeException When it cannot be read.
	 */
	private static function get( string $path ): string {
		clearstatcache( true, $path );
		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- said below.
		if ( false === $size || $size > self::MAX_BYTES * 2 ) {
			throw new \RuntimeException( 'The put-back\'s record of what it wrote over cannot be read.' );
		}
		$bytes = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a plugin-owned file, a row's column.
		if ( false === $bytes ) {
			throw new \RuntimeException( 'The put-back\'s record of what it wrote over cannot be read.' );
		}
		return $bytes;
	}
}
