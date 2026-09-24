<?php
/**
 * The backups in the backups directory: list, details, delete.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Backups;

use WPCheckpoint\Archive\ChunkHasher;
use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Archive\ManifestError;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobConflicts;
use WPCheckpoint\Jobs\PreflightStep;

defined( 'ABSPATH' ) || exit;

/**
 * A backup is its standalone manifest, backups/{base}.manifest.json, with
 * the volumes it lists and, once checked, backups/{base}.verify.json.
 *
 * Listing sorts by the export time in the base name (…-YYYYMMDD-HHMMSS-hhhh),
 * so only the manifests of one page are read. Deleting never follows the
 * manifest's list of volumes (an uploaded or edited manifest could name
 * another backup's files): it removes exactly the files named "{base}." plus
 * one of this plugin's suffixes. It first writes {base}.deleting, then
 * removes the record, the volumes, the manifest and the marker last, so an
 * interrupted delete leaves a listed backup that says it was partly
 * deleted (never one that looks intact until a check finds volumes
 * missing) and can be deleted again, never files the list cannot see.
 *
 * Of the exported site, only facts that identify neither the site nor the
 * server are returned. Other text from the manifest (warnings, exclusions,
 * generator, contents) is returned as it is; the REST layer cleans it.
 */
final class BackupStore {

	const MANIFEST_SUFFIX = '.manifest.json';
	const DELETING_SUFFIX = '.deleting';
	const MAX_PER_PAGE    = 50;

	/**
	 * Backups directory.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Manifest hashes by base name and file state, for this request.
	 *
	 * @var array<string, string>
	 */
	private $hashes = array();

	/**
	 * Removes one file: function( string $path ): bool.
	 *
	 * @var callable
	 */
	private $unlink;

	/**
	 * Constructor.
	 *
	 * @param string        $dir    Backups directory.
	 * @param callable|null $unlink Removes one file (tests); unlink() by default.
	 */
	public function __construct( string $dir, $unlink = null ) {
		$this->dir    = rtrim( $dir, '/\\' );
		$this->unlink = is_callable( $unlink ) ? $unlink : static function ( string $path ): bool {
			return @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- a warning would put the path into the error log.
		};
	}

	/**
	 * Base names of the backups here, newest export first.
	 *
	 * @return string[]
	 */
	public function bases(): array {
		$bases = array();
		foreach ( $this->names() as $name ) {
			if ( strlen( $name ) <= strlen( self::MANIFEST_SUFFIX ) || substr( $name, -strlen( self::MANIFEST_SUFFIX ) ) !== self::MANIFEST_SUFFIX ) {
				continue;
			}
			$base = substr( $name, 0, -strlen( self::MANIFEST_SUFFIX ) );
			if ( 1 === preg_match( PreflightStep::BASE_PATTERN, $base ) && is_file( $this->dir . DIRECTORY_SEPARATOR . $name ) ) {
				$bases[] = $base;
			}
		}
		usort(
			$bases,
			static function ( string $a, string $b ): int {
				$by_time = strcmp( substr( $b, -20 ), substr( $a, -20 ) ); // "YYYYMMDD-HHMMSS-hhhh".
				return 0 !== $by_time ? $by_time : strcmp( $a, $b );
			}
		);
		return $bases;
	}

	/**
	 * One page of backups with their summary.
	 *
	 * @param int   $page     Page (from 1).
	 * @param int   $per_page Backups per page.
	 * @param Job[] $active   Queued, running and paused jobs.
	 * @return array{total: int, items: array<int, array<string, mixed>>}
	 */
	public function page( int $page, int $per_page, array $active ): array {
		$bases    = $this->bases();
		$per_page = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
		$items    = array();
		foreach ( array_slice( $bases, ( max( 1, $page ) - 1 ) * $per_page, $per_page ) as $base ) {
			$items[] = $this->summary( $base, $active );
		}
		return array(
			'total' => count( $bases ),
			'items' => $items,
		);
	}

	/**
	 * Details of one backup, or null when there is no such backup.
	 *
	 * @param string $base   Base name.
	 * @param Job[]  $active Queued, running and paused jobs.
	 * @return array<string, mixed>|null
	 */
	public function details( string $base, array $active ) {
		if ( ! $this->exists( $base ) ) {
			return null;
		}
		$summary  = $this->summary( $base, $active );
		$manifest = $this->manifest( $base );
		if ( ! $manifest instanceof Manifest ) {
			return $summary;
		}
		$data    = $manifest->to_array();
		$volumes = array();
		foreach ( $manifest->volumes() as $volume ) {
			$path      = $this->dir . DIRECTORY_SEPARATOR . (string) $volume['path'];
			$present   = self::is_own_file( $base, (string) $volume['path'] ) && is_file( $path );
			$volumes[] = array(
				'name'    => (string) $volume['path'],
				'bytes'   => (int) $volume['bytes'],
				// A volume larger than one hash block records the hash of its block hashes, which no
				// sha256sum of the file reproduces: only a whole-file hash is shown for comparison.
				'sha256'  => isset( $volume['chunks'] ) ? null : (string) $volume['sha256'],
				'present' => $present,
				'size_ok' => $present && (int) filesize( $path ) === (int) $volume['bytes'],
			);
		}
		$file = $this->dir . DIRECTORY_SEPARATOR . $base . self::MANIFEST_SUFFIX;
		return array_merge(
			$summary,
			array(
				'site'          => self::site_facts( $manifest->site() ),
				'generator'     => $data['generator'] ?? array(),
				'exported'      => $manifest->database_exported(),
				'exclusions'    => $data['exclusions'] ?? array(),
				'warnings'      => $manifest->warnings(),
				'volume_files'  => $volumes,
				'manifest_file' => array(
					'name'   => $base . self::MANIFEST_SUFFIX,
					'bytes'  => (int) filesize( $file ),
					'sha256' => $this->manifest_hash( $base ),
				),
			)
		);
	}

	/**
	 * Delete a backup's files.
	 *
	 * @param string $base   Base name.
	 * @param Job[]  $active Queued, running and paused jobs.
	 * @return int Files deleted.
	 * @throws \RuntimeException When the backup is in use or a file cannot be deleted (what was deleted stays deleted).
	 */
	public function delete( string $base, array $active ): int {
		$reason = JobConflicts::backup_in_use( $base, $active );
		if ( '' !== $reason ) {
			throw new \RuntimeException( $reason ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed text with a job number.
		}
		$files = array();
		foreach ( $this->names() as $name ) {
			if ( self::is_own_file( $base, $name ) && is_file( $this->dir . DIRECTORY_SEPARATOR . $name ) ) {
				$files[] = $name;
			}
		}
		usort(
			$files,
			static function ( string $a, string $b ) use ( $base ): int {
				$by_order = self::delete_order( $base, $a ) <=> self::delete_order( $base, $b );
				return 0 !== $by_order ? $by_order : strcmp( $a, $b );
			}
		);
		$marker = $this->dir . DIRECTORY_SEPARATOR . $base . self::DELETING_SUFFIX;
		if ( ! is_file( $marker ) && false === @file_put_contents( $marker, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a warning would put the path into the error log.
			throw new \RuntimeException( 'The backup could not be deleted: the backups directory is not writable.' );
		}
		$files   = array_values( array_diff( $files, array( $base . self::DELETING_SUFFIX ) ) );
		$deleted = 0;
		foreach ( $files as $name ) {
			$path = $this->dir . DIRECTORY_SEPARATOR . $name;
			if ( ! call_user_func( $this->unlink, $path ) && is_file( $path ) ) {
				throw new \RuntimeException( sprintf( 'A file of the backup could not be deleted (%d of %d deleted); delete it again once the file can be removed.', (int) $deleted, count( $files ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- numbers only.
			}
			++$deleted;
		}
		call_user_func( $this->unlink, $marker ); // Left behind, it names no listed backup (the manifest is gone).
		return $deleted;
	}

	/**
	 * Whether a delete of this backup started and did not finish.
	 *
	 * @param string $base Base name.
	 * @return bool
	 */
	public function delete_incomplete( string $base ): bool {
		return 1 === preg_match( PreflightStep::BASE_PATTERN, $base ) && is_file( $this->dir . DIRECTORY_SEPARATOR . $base . self::DELETING_SUFFIX );
	}

	/**
	 * Whether a backup's manifest is here.
	 *
	 * @param string $base Base name.
	 * @return bool
	 */
	public function exists( string $base ): bool {
		return 1 === preg_match( PreflightStep::BASE_PATTERN, $base ) && is_file( $this->dir . DIRECTORY_SEPARATOR . $base . self::MANIFEST_SUFFIX );
	}

	/**
	 * Whether a file name is one of this backup's own files: its manifest,
	 * its record, its single volume or one of its numbered volumes.
	 *
	 * @param string $base Base name.
	 * @param string $name File name.
	 * @return bool
	 */
	public static function is_own_file( string $base, string $name ): bool {
		if ( 0 !== strpos( $name, $base . '.' ) ) {
			return false;
		}
		$rest = substr( $name, strlen( $base ) );
		return 1 === preg_match( '/\A(\.manifest\.json|\.verify\.json|\.deleting|(\.part[0-9]{3,4})?\.wpcheckpoint\.(zip|tar))\z/', $rest );
	}

	/**
	 * The summary of a backup for the list.
	 *
	 * @param string $base   Base name.
	 * @param Job[]  $active Queued, running and paused jobs.
	 * @return array<string, mixed>
	 */
	private function summary( string $base, array $active ): array {
		$out      = array(
			'base'              => $base,
			'in_use'            => JobConflicts::backup_in_use( $base, $active ),
			'delete_incomplete' => $this->delete_incomplete( $base ),
			'valid'             => false,
			'created_at'        => '',
			'bytes'             => 0,
			'volumes'           => 0,
			'complete'          => false,
			'contents'          => array(),
			'tables'            => 0,
			'files'             => 0,
			'warnings'          => 0,
			'verification'      => $this->verification( $base ),
		);
		$manifest = $this->manifest( $base );
		if ( ! $manifest instanceof Manifest ) {
			return $out;
		}
		$data     = $manifest->to_array();
		$bytes    = 0;
		$complete = true;
		foreach ( $manifest->volumes() as $volume ) {
			$bytes += (int) $volume['bytes'];
			$path   = $this->dir . DIRECTORY_SEPARATOR . (string) $volume['path'];
			if ( ! self::is_own_file( $base, (string) $volume['path'] ) || ! is_file( $path ) || (int) filesize( $path ) !== (int) $volume['bytes'] ) {
				$complete = false;
			}
		}
		return array_merge(
			$out,
			array(
				'valid'      => true,
				'created_at' => (string) ( $data['created_at'] ?? '' ),
				'bytes'      => $bytes,
				'volumes'    => count( $manifest->volumes() ),
				'complete'   => $complete,
				'contents'   => $data['contents'] ?? array(),
				'tables'     => count( $manifest->tables() ),
				'files'      => $manifest->files_summary()['count'],
				'warnings'   => count( $manifest->warnings() ),
			)
		);
	}

	/**
	 * The latest check of a backup: the record and whether it still applies
	 * to the manifest (state "none", "current" or "manifest_changed").
	 *
	 * @param string $base Base name.
	 * @return array{state: string, record: array<string, mixed>|null}
	 */
	public function verification( string $base ): array {
		$file   = $this->dir . DIRECTORY_SEPARATOR . VerifyRecord::file_name( $base );
		$size   = is_file( $file ) ? (int) filesize( $file ) : 0;
		$json   = $size > 0 && $size <= VerifyRecord::MAX_BYTES ? (string) @file_get_contents( $file ) : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- small file, size checked.
		$record = '' === $json ? null : VerifyRecord::from_json( $json, $base );
		if ( null === $record ) {
			return array(
				'state'  => 'none',
				'record' => null,
			);
		}
		$current = $this->manifest_hash( $base );
		return array(
			'state'  => hash_equals( (string) $record['manifest_sha256'], $current ) ? 'current' : 'manifest_changed',
			'record' => $record,
		);
	}

	/**
	 * The parsed manifest, or null when it cannot be read or is not valid.
	 *
	 * @param string $base Base name.
	 * @return Manifest|null
	 */
	private function manifest( string $base ) {
		$file = $this->dir . DIRECTORY_SEPARATOR . $base . self::MANIFEST_SUFFIX;
		$size = is_file( $file ) ? (int) filesize( $file ) : 0;
		if ( $size <= 0 || $size > Manifest::MAX_JSON_BYTES ) {
			return null;
		}
		$json = @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded by the size check above.
		if ( ! is_string( $json ) ) {
			return null;
		}
		try {
			$manifest = Manifest::from_json( $json );
		} catch ( ManifestError $e ) {
			return null;
		}
		return $manifest->embedded() ? null : $manifest;
	}

	/**
	 * Entry names of the backups directory. Read with readdir(), not glob():
	 * a custom storage path may contain glob metacharacters.
	 *
	 * @return string[]
	 */
	private function names(): array {
		$names  = array();
		$handle = is_dir( $this->dir ) ? @opendir( $this->dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would put the path into the error log.
		if ( false === $handle ) {
			return $names;
		}
		while ( false !== ( $name = readdir( $handle ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition,Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- readdir loop.
			if ( '.' !== $name && '..' !== $name ) {
				$names[] = $name;
			}
		}
		closedir( $handle );
		return $names;
	}

	/**
	 * SHA-256 of a backup's standalone manifest; '' when it cannot be read or
	 * is larger than any manifest (hashing is then not bounded).
	 *
	 * @param string $base Base name.
	 * @return string
	 */
	private function manifest_hash( string $base ): string {
		// Once per file state: a details page asks twice; a file that changed (size or time) is judged again.
		$file = $this->dir . DIRECTORY_SEPARATOR . $base . self::MANIFEST_SUFFIX;
		clearstatcache( true, $file );
		$key = $base . '|' . ( is_file( $file ) ? (int) filesize( $file ) . '|' . (int) filemtime( $file ) : '-' );
		if ( ! isset( $this->hashes[ $key ] ) ) {
			$this->hashes[ $key ] = $this->hash_manifest( $base );
		}
		return $this->hashes[ $key ];
	}

	/**
	 * SHA-256 of a backup's standalone manifest, read from the disk (see manifest_hash()).
	 *
	 * @param string $base Base name.
	 * @return string
	 */
	private function hash_manifest( string $base ): string {
		$file = $this->dir . DIRECTORY_SEPARATOR . $base . self::MANIFEST_SUFFIX;
		clearstatcache( true, $file ); // The size decides whether it is read at all: never a cached one.
		$size = is_file( $file ) ? (int) filesize( $file ) : 0;
		return $size > 0 && $size <= Manifest::MAX_JSON_BYTES ? self::hash_or_empty( $file ) : '';
	}

	/**
	 * The facts about the exported site that identify neither the site nor
	 * the server (no URL, path or database host).
	 *
	 * @param array<string, mixed> $site Manifest site object.
	 * @return array<string, mixed>
	 */
	private static function site_facts( array $site ): array {
		return array_intersect_key( $site, array_flip( array( 'table_prefix', 'wp_version', 'php_version', 'locale', 'charset', 'collate', 'multisite' ) ) );
	}

	/**
	 * SHA-256 of a file, '' when it cannot be read.
	 *
	 * @param string $file File.
	 * @return string
	 */
	private static function hash_or_empty( string $file ): string {
		try {
			return ChunkHasher::hash_file( $file );
		} catch ( \RuntimeException $e ) {
			return '';
		}
	}

	/**
	 * Delete order: the record, the volumes, the manifest last.
	 *
	 * @param string $base Base name.
	 * @param string $name File name.
	 * @return int
	 */
	private static function delete_order( string $base, string $name ): int {
		if ( $base . VerifyRecord::SUFFIX === $name ) {
			return 0;
		}
		return $base . self::MANIFEST_SUFFIX === $name ? 2 : 1;
	}
}
