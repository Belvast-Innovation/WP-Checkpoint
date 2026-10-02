<?php
/**
 * The catalogue of everything the engine leaves under the storage tmp/ directory.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Restore\StagingLayout;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Paths;

/**
 * Every kind of temporary thing the plugin creates on disk is listed in
 * KINDS with the rule that decides when it is an orphan, and the reaper
 * and the purge enumerate through scan() and scan_site() only. A test (ResidueUsageTest)
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
 * - stage_dir: a restore's staging root, next to the site's directories
 *   rather than under tmp/ (StagingLayout: in the parent of a group's
 *   directory, so the swap is a rename there). Same rule as work_dir.
 * - probe: a directory or file the restore's preflight creates in a site
 *   directory to see what it can do there, and removes in the same unit
 *   (StagingLayout::probe_name()); left only by a process that died in
 *   between. An orphan as soon as no live run holds its job (a probe in
 *   mu-plugins would otherwise stay loaded on every request for days):
 *   the work_dir rule, or a job without a live lease.
 *   A staging root whose stray/ directory holds anything, or cannot be
 *   listed, is kept (keeps_stray()): the swap's rollback moves there what
 *   someone else put where the site's own directory had to go back, and
 *   that is never the plugin's to delete.
 * - maintenance_tmp: a temporary file of the maintenance file the swap
 *   writes in ABSPATH (".maintenance.{16 hex}.tmp", AtomicFile), left only
 *   by a process that died before its rename. No owner in the name; an
 *   orphan after VERIFY_TTL (scan_maintenance()).
 * - stray_table: a table the swap's rollback renamed out of the way
 *   (TempTables::stray()): someone else's, created under a name the old
 *   site's table had to go back to. Never an orphan: it is reported, and
 *   left for the administrator.
 *
 * Only entries whose name carries one of this installation's storage
 * tokens are ever listed (scan_site(), Directories::own_tokens()); another
 * installation's, on a copied site sharing the directories, are never
 * touched. So nothing is listed while a clone is unresolved, and an
 * entry under a token no longer kept (more than Directories::PAST_TOKENS changes, or
 * dropped when a clone was detected) is never reclaimed: the safe
 * direction. Uninstall removes them whatever the data setting (they are
 * never the user's data), when the stored state is this installation's.
 */
final class Residue {

	const WORK_DIR   = 'work_dir';
	const TEMP_TABLE = 'temp_table';
	const VERIFY_DIR = 'verify_dir';
	const STRAY      = 'stray';
	const STAGE_DIR  = 'stage_dir';
	const PROBE      = 'probe';

	const MAINTENANCE_TMP = 'maintenance_tmp';
	const STRAY_TABLE     = 'stray_table';

	const KINDS = array( self::WORK_DIR, self::TEMP_TABLE, self::VERIFY_DIR, self::STRAY, self::STAGE_DIR, self::PROBE, self::MAINTENANCE_TMP, self::STRAY_TABLE );

	/**
	 * The directory in a staging root where the swap's rollback moves what is in its way.
	 */
	const STRAY_DIR = 'stray';

	/**
	 * The temporary names of the maintenance file (AtomicFile: the name, a dot, 16 hex characters, ".tmp").
	 */
	const MAINTENANCE_TMP_NAME = '/\A\.maintenance\.[0-9a-f]{16}\.tmp\z/';

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
		'src/Restore/StagingLayout.php',
		'src/Support/AtomicFile.php',
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
				// Gone meanwhile, or a dangling link (filemtime follows links): a dangling job-N link is never
				// reclaimed, which leaks a directory entry and nothing more.
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
	 * The site directories where a restore stages and probes (StagingLayout): the parent of each group's live
	 * directory, the content directory itself (other-content's entries), and the mu-plugins directory (the
	 * loader probe).
	 *
	 * @param array<string, string> $groups Group => live directory, as ScanRoots::site_directories() gives them.
	 * @return string[]
	 */
	public static function site_dirs( array $groups ): array {
		$dirs = array();
		foreach ( $groups as $group => $dir ) {
			$dir = rtrim( str_replace( '\\', '/', (string) $dir ), '/' );
			if ( '' === $dir ) {
				continue;
			}
			$dirs[ StagingLayout::OTHER === $group ? $dir : dirname( $dir ) ] = true;
			if ( 'mu-plugins' === $group ) {
				$dirs[ $dir ] = true;
			}
		}
		return array_keys( $dirs );
	}

	/**
	 * The staging roots and probes this installation left in the site directories: entries whose name
	 * (StagingLayout::parse()) carries one of $tokens (Directories::own_tokens(), or one job's). Another
	 * installation's (a copied site sharing the directories) are never listed.
	 *
	 * @param string[] $dirs   Site directories (site_dirs()).
	 * @param string[] $tokens Storage tokens of this installation.
	 * @return array<int, array{kind: string, path: string, id: int, mtime: int, parent: string, token: string}>
	 */
	public static function scan_site( array $dirs, array $tokens ): array {
		$out    = array();
		$tokens = array_values( array_filter( $tokens, array( Directories::class, 'is_valid_token' ) ) );
		if ( array() === $tokens ) {
			return $out;
		}
		foreach ( array_unique( $dirs ) as $dir ) {
			$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable directory is simply nothing to reap.
			foreach ( is_array( $entries ) ? $entries : array() as $name ) {
				$parsed = StagingLayout::parse( (string) $name );
				if ( null === $parsed || ! in_array( $parsed['token'], $tokens, true ) ) {
					continue;
				}
				$path = $dir . DIRECTORY_SEPARATOR . $name;
				$stat = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- gone meanwhile: skipped.
				if ( false === $stat ) {
					continue;
				}
				$entry           = self::entry( 'stage' === $parsed['kind'] ? self::STAGE_DIR : self::PROBE, $path, $parsed['job_id'], (int) $stat['mtime'] );
				$entry['parent'] = $dir;
				$entry['token']  = $parsed['token'];
				$out[]           = $entry;
			}
		}
		return $out;
	}

	/**
	 * Whether a staging root must be kept for what the swap's rollback moved into it: its stray/ directory holds
	 * an entry, or is there and cannot be listed (not knowing keeps it). A root without one (positively: lstat
	 * says there is none) is not kept for this.
	 *
	 * @param string $root Staging root.
	 * @return bool
	 */
	public static function keeps_stray( string $root ): bool {
		$dir = rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR . self::STRAY_DIR;
		clearstatcache( true, $dir );
		if ( false === @lstat( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- not there: nothing to keep.
			return ! Paths::positively_gone( $dir );
		}
		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable: kept.
		return ! is_array( $entries ) || array() !== array_diff( $entries, array( '.', '..' ) );
	}

	/**
	 * Where the maintenance file is (null: ABSPATH). Tests only (replace_maintenance_dir()).
	 *
	 * @var string|null
	 */
	private static $maintenance_dir = null;

	/**
	 * The directory of the maintenance file, where its temporary files are looked for: ABSPATH.
	 *
	 * @return string '' when it is not known.
	 */
	public static function maintenance_dir(): string {
		if ( null !== self::$maintenance_dir ) {
			return self::$maintenance_dir;
		}
		return defined( 'ABSPATH' ) ? (string) ABSPATH : '';
	}

	/**
	 * Tests: look for the maintenance file's temporary files in a sandbox instead of ABSPATH, returning the one
	 * before (null: ABSPATH).
	 *
	 * @param string|null $dir Directory, or null for ABSPATH.
	 * @return string|null
	 */
	public static function replace_maintenance_dir( $dir ) {
		$before                = self::$maintenance_dir;
		self::$maintenance_dir = null === $dir ? null : (string) $dir;
		return $before;
	}

	/**
	 * The maintenance file's temporary files left in a directory (maintenance_dir()).
	 *
	 * @param string $dir Directory.
	 * @return array<int, array{kind: string, path: string, id: int, mtime: int}>
	 */
	public static function scan_maintenance( string $dir ): array {
		$out     = array();
		$entries = '' === $dir ? false : @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable directory is simply nothing to reap.
		foreach ( is_array( $entries ) ? $entries : array() as $name ) {
			if ( 1 !== preg_match( self::MAINTENANCE_TMP_NAME, (string) $name ) ) {
				continue;
			}
			$path = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $name;
			$stat = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- gone meanwhile: skipped.
			if ( false === $stat || 0100000 !== ( $stat['mode'] & 0170000 ) ) {
				continue; // Only a regular file: AtomicFile never makes anything else.
			}
			$out[] = self::entry( self::MAINTENANCE_TMP, $path, 0, (int) $stat['mtime'] );
		}
		return $out;
	}

	/**
	 * Whether an unowned entry (verify_dir, stray, maintenance_tmp) is old enough to remove.
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
			case self::MAINTENANCE_TMP:
				return $entry['mtime'] + self::VERIFY_TTL <= $now;
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
