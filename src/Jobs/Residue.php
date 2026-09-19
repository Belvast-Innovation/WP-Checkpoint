<?php
/**
 * The catalogue of everything the engine leaves under the storage tmp/ directory.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

/**
 * Every kind of temporary thing the plugin creates on disk is listed in
 * KINDS with the rule that decides when it is an orphan, and the reaper
 * and the purge enumerate through scan() only. A test (ResidueUsageTest)
 * refuses any source file that builds a tmp/ path or a temporary table
 * name outside the files named in SOURCES, so a new kind of leftover
 * cannot appear without being registered here.
 *
 * Kinds:
 * - work_dir: tmp/job-<id>/, the job's work directory (JobContext::work_path()).
 *   Everything a step writes, including the packer's *.partial and *.cdr
 *   files, lives inside it and goes with it. Orphan when the job row is
 *   gone (table recreated, crash before the row was written), the job is
 *   completed or cancelled, the job failed and its work files passed
 *   retention (work_expired_at > 0), or the job is bound to another
 *   storage directory (a copied database: those files were never this
 *   job's).
 * - temp_table: a database table named by TempTables (the prefix carries
 *   the installation token and the job id); same rule as work_dir.
 * - verify_dir: tmp/verify-<hex>/ of "wp wpcheckpoint verify", which has
 *   no job; the command removes it, a killed process cannot. Orphan after
 *   VERIFY_TTL: a full verification of a huge archive stays within it and
 *   never rewrites the extracted indexes.
 * - stray: a *.partial or *.cdr file directly under tmp/. The packer
 *   writes them inside a work directory, so nothing creates these today;
 *   the rule exists so that a future writer at the tmp/ root is caught by
 *   the usage test and lands on a registered rule. Orphan after
 *   STRAY_TTL: without an owner, deleting early would leave nothing to
 *   investigate, and they take little space.
 */
final class Residue {

	const WORK_DIR   = 'work_dir';
	const TEMP_TABLE = 'temp_table';
	const VERIFY_DIR = 'verify_dir';
	const STRAY      = 'stray';

	const KINDS = array( self::WORK_DIR, self::TEMP_TABLE, self::VERIFY_DIR, self::STRAY );

	const TMP            = 'tmp';
	const WORK_PREFIX    = 'job-';
	const VERIFY_PREFIX  = 'verify-';
	const STRAY_SUFFIXES = array( '.partial', '.cdr' );
	const VERIFY_TTL     = 86400;
	const STRAY_TTL      = 604800;

	/**
	 * Source files allowed to build tmp/ paths or temporary table names.
	 */
	const SOURCES = array(
		'src/Jobs/Residue.php',
		'src/Jobs/LockFile.php',
		'src/Jobs/TempTables.php',
		'src/Support/Directories.php',
		'src/Support/StorageReclaim.php',
	);

	/**
	 * The tmp/ directory of a storage base.
	 *
	 * @param string $base Storage base directory.
	 * @return string
	 */
	public static function tmp( string $base ): string {
		return $base . DIRECTORY_SEPARATOR . self::TMP;
	}

	/**
	 * A job's work directory.
	 *
	 * @param string $base   Storage base directory.
	 * @param int    $job_id Job id.
	 * @return string
	 */
	public static function work_dir( string $base, int $job_id ): string {
		return self::tmp( $base ) . DIRECTORY_SEPARATOR . self::WORK_PREFIX . $job_id;
	}

	/**
	 * The job id a directory name encodes, or 0.
	 *
	 * @param string $name Directory name (not a path).
	 * @return int
	 */
	public static function work_dir_id( string $name ): int {
		return 1 === preg_match( '/\A' . preg_quote( self::WORK_PREFIX, '/' ) . '([1-9][0-9]{0,18})\z/', $name, $m ) ? (int) $m[1] : 0;
	}

	/**
	 * A fresh, random name for a verification work directory (not created).
	 *
	 * @param string $tmp The tmp/ directory to place it in.
	 * @return string
	 */
	public static function new_verify_dir( string $tmp ): string {
		return $tmp . DIRECTORY_SEPARATOR . self::VERIFY_PREFIX . bin2hex( random_bytes( 8 ) );
	}

	/**
	 * Everything under tmp/ that the catalogue knows, oldest first within a
	 * kind. Lock files (LockFile) and the protection files are not residue
	 * and are left out. Temporary tables are not on disk: the repository
	 * lists them with TempTables.
	 *
	 * @param string $base Storage base directory.
	 * @return array<int, array{kind: string, path: string, id: int, mtime: int}>
	 */
	public static function scan( string $base ): array {
		$tmp = self::tmp( $base );
		if ( '' === $base || ! is_dir( $tmp ) ) {
			return array();
		}
		$entries = @scandir( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable tmp/ is simply nothing to reap.
		$out     = array();
		foreach ( is_array( $entries ) ? $entries : array() as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path  = $tmp . DIRECTORY_SEPARATOR . $name;
			$mtime = (int) @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a vanished entry is skipped below.
			if ( 0 === $mtime ) {
				continue;
			}
			$id = self::work_dir_id( $name );
			if ( $id > 0 && is_dir( $path ) ) {
				$out[] = self::entry( self::WORK_DIR, $path, $id, $mtime );
				continue;
			}
			if ( 0 === strpos( $name, self::VERIFY_PREFIX ) && is_dir( $path ) ) {
				$out[] = self::entry( self::VERIFY_DIR, $path, 0, $mtime );
				continue;
			}
			foreach ( self::STRAY_SUFFIXES as $suffix ) {
				if ( strlen( $name ) > strlen( $suffix ) && substr( $name, -strlen( $suffix ) ) === $suffix && is_file( $path ) ) {
					$out[] = self::entry( self::STRAY, $path, 0, $mtime );
					break;
				}
			}
		}
		usort(
			$out,
			static function ( array $a, array $b ): int {
				return array( $a['kind'], $a['mtime'] ) <=> array( $b['kind'], $b['mtime'] );
			}
		);
		return $out;
	}

	/**
	 * Whether an unowned entry (verify_dir, stray) is old enough to remove.
	 *
	 * @param array{kind: string, path: string, id: int, mtime: int} $entry Entry from scan().
	 * @param int                                                    $now   Current time.
	 * @return bool
	 */
	public static function is_expired( array $entry, int $now ): bool {
		switch ( $entry['kind'] ) {
			case self::VERIFY_DIR:
				return $entry['mtime'] + self::VERIFY_TTL <= $now;
			case self::STRAY:
				return $entry['mtime'] + self::STRAY_TTL <= $now;
			default:
				return false;
		}
	}

	/**
	 * Shape an entry.
	 *
	 * @param string $kind  Kind.
	 * @param string $path  Path.
	 * @param int    $id    Job id or 0.
	 * @param int    $mtime Modification time.
	 * @return array{kind: string, path: string, id: int, mtime: int}
	 */
	private static function entry( string $kind, string $path, int $id, int $mtime ): array {
		return array(
			'kind'  => $kind,
			'path'  => $path,
			'id'    => $id,
			'mtime' => $mtime,
		);
	}
}
