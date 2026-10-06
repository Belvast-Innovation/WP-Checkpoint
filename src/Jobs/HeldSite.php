<?php
/**
 * A job that holds the site changed, seen from an installation that does not manage it.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Files\ScanRoots;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Restore\LinkedTargets;
use WPCheckpoint\Restore\SwapPlan;
use WPCheckpoint\Restore\SwapRules;
use WPCheckpoint\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * After the site's identity changed while a restore's swap held the site (a copy made, or the site moved), the job
 * is managed by a token this installation does not hold (Job::managing()), and it is not run here. What can be done
 * depends on what the job's plan shows (its site entry, SwapPlan::SITE, and its directory units), only from positive
 * evidence:
 *
 * - this site ("site"): every directory the plan swaps is in one of this site's content directories now (its parent
 *   resolved, then its name), and the table prefix the plan records is this site's. The job may be taken over
 *   (rebind): this installation then manages it, and it goes on, or is rolled back, here.
 * - anything else ("other": a copy, another directory sharing the database, or what cannot be told): the job never
 *   runs here. This job's maintenance file under this WordPress directory may be taken down (release), and the job
 *   given up (abandon), when this WordPress directory is positively another than the one the plan records.
 *
 * Each command is confirmed with a code: a digest of the command, the job's id and the storage token it was started
 * with, and, for release and abandon, the WordPress directory the plan records. A code shown for one job, or before
 * the plan said where its site was, does not confirm anything else.
 */
final class HeldSite {

	const SITE  = 'site';
	const OTHER = 'other';

	const REBIND  = 'rebind';
	const RELEASE = 'release';
	const ABANDON = 'abandon';

	/**
	 * Injected parts (tests): "abspath" (in place of ABSPATH), "site_dirs" function(): array (group => directory, in
	 * place of ScanRoots::site_directories()), "at" function( string $point ): void (a seam: JobActions::abandon()
	 * calls at( 'abandon_released' ) between its two steps).
	 *
	 * @var array<string, mixed>
	 */
	private $parts;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $parts Injected parts (tests).
	 */
	public function __construct( array $parts = array() ) {
		$this->parts = $parts;
	}

	/**
	 * What the job's plan shows from here.
	 *
	 * @param Job $job Job (holding the site).
	 * @return array{branch: string, why: string, recorded: string, prefix: string|null, here: string, differs: bool, direction: string, file_here: bool}
	 *         branch: SITE or OTHER; why: what kept it from SITE (''); recorded: the WordPress directory the plan
	 *         records ('' when it records none); here: this WordPress directory resolved ('' when it cannot be);
	 *         differs: whether here is positively another directory than recorded; direction: "committed",
	 *         "restored" or '' (not recorded yet); file_here: whether this job's maintenance file is in this
	 *         WordPress directory.
	 */
	public function assess( Job $job ): array {
		clearstatcache( true );
		$rows = $this->plan_rows( $job );
		$site = null;
		$dirs = array();
		foreach ( null === $rows ? array() : $rows as $row ) {
			if ( SwapPlan::SITE === $row['kind'] && 0 === $row['seq'] ) {
				$site = $row;
			} elseif ( SwapPlan::DIR === $row['kind'] ) {
				$dirs[] = $row['live'];
			}
		}
		$real = Paths::real( $this->abspath() );
		$here = false === $real ? '' : rtrim( Paths::normalize( (string) $real ), '/' );
		$why  = '';
		if ( null === $rows ) {
			$why = __( 'its plan cannot be read from the database now', 'wp-checkpoint' );
		} elseif ( null === $site ) {
			$why = __( 'its plan does not say which site it was written for', 'wp-checkpoint' );
		} elseif ( self::base_prefix() !== $site['stage'] ) {
			$why = __( 'its plan was written for another table prefix', 'wp-checkpoint' );
		} else {
			$why = $this->outside( $dirs );
		}
		$recorded = null === $site ? '' : $site['live'];
		$phase    = (string) ( $job->cursor['phase'] ?? '' );
		$mark     = (string) ( $job->cursor['mark'] ?? '' );
		return array(
			'branch'    => '' === $why ? self::SITE : self::OTHER,
			'why'       => $why,
			'recorded'  => $recorded,
			'prefix'    => null === $site ? null : $site['stage'],
			'here'      => $here,
			'differs'   => self::another( $recorded, $here ),
			'direction' => in_array( $phase, array( 'committed', 'done' ), true ) ? 'committed' : ( 'restored' === $phase ? 'restored' : '' ),
			'file_here' => '' !== $mark && Maintenance::OURS === ( new Maintenance( $this->abspath(), $mark ) )->state(),
			'finishes'  => 'rename' === $phase ? $this->renamed_all( $job ) : false,
		);
	}

	/**
	 * Whether a swap interrupted while renaming tables had made every rename: then its next run finishes it, whatever
	 * a rollback request says (SwapStep, phase "rename": SwapRules::committed() on the plan's table entries and the
	 * tables there). Null when that cannot be read (the plan or the tables).
	 *
	 * @param Job $job Job (its cursor at phase "rename").
	 * @return bool|null
	 */
	private function renamed_all( Job $job ) {
		global $wpdb;
		$quiet            = $wpdb->suppress_errors( true );
		$wpdb->last_error = '';
		$table            = self::base_prefix() . SwapPlan::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table name from the prefix.
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT kind, live, stage, old, had_live FROM {$table} WHERE job_id = %d AND attempt = %d AND kind IN (%s, %s) ORDER BY seq", $job->id, (int) ( $job->cursor['attempt'] ?? 0 ), SwapPlan::TABLE_OF, SwapPlan::MOVE ), ARRAY_N );
		$failed  = '' !== JobRepository::db_error() || ! is_array( $rows ) || array() === $rows;
		$entries = array();
		foreach ( $failed ? array() : $rows as $row ) {
			$entries[] = array(
				'kind'     => (string) $row[0],
				'live'     => (string) $row[1],
				'stage'    => (string) $row[2],
				'old'      => (string) $row[3],
				'had_live' => '1' === (string) $row[4],
			);
		}
		$there = array();
		foreach ( $failed ? array() : array_chunk( SwapRules::names( $entries ), SwapStep::PAGE ) as $names ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built from the list.
			$found = $wpdb->get_col( $wpdb->prepare( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode( ', ', array_fill( 0, count( $names ), '%s' ) ) . ')', $names ) );
			if ( '' !== JobRepository::db_error() || ! is_array( $found ) ) {
				$failed = true;
				break;
			}
			foreach ( array_intersect( array_map( 'strval', $found ), $names ) as $name ) {
				$there[ $name ] = true;
			}
		}
		$wpdb->suppress_errors( $quiet );
		return $failed ? null : SwapRules::committed( $entries, $there );
	}

	/**
	 * The confirmation code of a command for a job.
	 *
	 * @param string $action   REBIND, RELEASE or ABANDON.
	 * @param Job    $job      Job.
	 * @param string $recorded The WordPress directory its plan records (release and abandon; '' for rebind).
	 * @return string
	 */
	public static function code( string $action, Job $job, string $recorded ): string {
		$bound = self::REBIND === $action ? '' : $recorded;
		return hash( 'sha256', implode( "\0", array( 'wpcheckpoint-held-site', $action, (string) $job->id, $job->storage_token, $job->managing_token(), $bound ) ) );
	}

	/**
	 * Take this job's maintenance file down from this WordPress directory, when it is positively another than the one
	 * its plan records. Nothing else is touched.
	 *
	 * @param Job                  $job        Job.
	 * @param array<string, mixed> $assessment assess().
	 * @return bool Whether none of this job's is there now.
	 * @throws \RuntimeException When this WordPress directory is not positively another one.
	 */
	public function release( Job $job, array $assessment ): bool {
		$why = self::not_another( $assessment );
		if ( '' !== $why ) {
			throw new \RuntimeException( $why ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a message of this class.
		}
		$mark = (string) ( $job->cursor['mark'] ?? '' );
		return '' === $mark || ( new Maintenance( $this->abspath(), $mark ) )->remove();
	}

	/**
	 * Why nothing of a job may be taken down or given up from this WordPress directory: it is not positively another
	 * than the one the job's plan records (the same, or it cannot be told); '' when it is. The one rule for release and
	 * abandon (JobActions and release() both ask it).
	 *
	 * @param array<string, mixed> $assessment assess().
	 * @return string
	 */
	public static function not_another( array $assessment ): string {
		return true === $assessment['differs'] ? '' : __( 'This WordPress directory is not positively another than the one the job\'s plan records (the same, or it cannot be told); nothing was changed.', 'wp-checkpoint' );
	}

	/**
	 * Take down, from this WordPress directory, the maintenance file of a job that no longer holds the site: only the
	 * file that carries the mark the job's row recorded (Job::$site_mark).
	 *
	 * @param Job           $job     Job (ended, or abandoned).
	 * @param callable|null $confirm Called right before the file is deleted (throws to stop: Maintenance::remove()).
	 * @return bool|null Whether it is gone now; null when there was no file of this job here.
	 */
	public function release_ended( Job $job, $confirm = null ) {
		if ( '' === $job->site_mark ) {
			return null;
		}
		$file = new Maintenance( $this->abspath(), $job->site_mark );
		if ( Maintenance::OURS !== $file->state() ) {
			return null;
		}
		return $file->remove( $confirm );
	}

	/**
	 * A test seam.
	 *
	 * @param string $point Seam.
	 * @return void
	 */
	public function at( string $point ): void {
		if ( isset( $this->parts['at'] ) ) {
			call_user_func( $this->parts['at'], $point );
		}
	}

	/**
	 * The first directory unit that is not in one of this site's content directories now, as a reason, or ''.
	 *
	 * @param string[] $dirs The plan's live directories.
	 * @return string
	 */
	private function outside( array $dirs ): string {
		$groups = array();
		foreach ( isset( $this->parts['site_dirs'] ) ? (array) call_user_func( $this->parts['site_dirs'] ) : ScanRoots::site_directories() as $dir ) {
			$real = Paths::real( (string) $dir );
			if ( false !== $real ) {
				$groups[] = rtrim( Paths::normalize( (string) $real ), '/' );
			}
		}
		foreach ( $dirs as $live ) {
			$live  = rtrim( Paths::normalize( $live ), '/' );
			$slash = strrpos( $live, '/' );
			$real  = false === $slash || 0 === $slash ? false : Paths::real( (string) substr( $live, 0, $slash ) );
			if ( false === $real ) {
				return __( 'where one of the directories it swaps is cannot be told from here', 'wp-checkpoint' );
			}
			$path   = rtrim( Paths::normalize( (string) $real ), '/' ) . substr( $live, (int) $slash );
			$inside = false;
			foreach ( $groups as $group ) {
				$inside = $inside || LinkedTargets::within( $group, $path );
			}
			if ( ! $inside ) {
				return __( 'a directory it swaps is not one of this site\'s content directories', 'wp-checkpoint' );
			}
		}
		return array() === $dirs ? __( 'its plan swaps no directory of this site', 'wp-checkpoint' ) : '';
	}

	/**
	 * The job's plan rows (site and directory units) of the attempt its cursor names, else of its last complete one;
	 * null when they cannot be read (an error, not an answer: never taken for a plan without them).
	 *
	 * @param Job $job Job.
	 * @return array<int, array{seq: int, kind: string, live: string, stage: string}>|null
	 */
	private function plan_rows( Job $job ) {
		global $wpdb;
		$quiet            = $wpdb->suppress_errors( true );
		$wpdb->last_error = '';
		$table            = self::base_prefix() . SwapPlan::TABLE;
		$attempt          = (int) ( $job->cursor['attempt'] ?? 0 );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table name from the prefix.
		if ( $attempt <= 0 ) {
			$attempt = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(attempt), 0) FROM {$table} WHERE job_id = %d AND kind = %s", $job->id, SwapPlan::COMPLETE ) );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT seq, kind, live, stage FROM {$table} WHERE job_id = %d AND attempt = %d AND kind IN (%s, %s) ORDER BY seq", $job->id, $attempt, SwapPlan::SITE, SwapPlan::DIR ), ARRAY_N );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$failed = '' !== JobRepository::db_error() || ! is_array( $rows );
		$wpdb->suppress_errors( $quiet );
		if ( $failed ) {
			return null;
		}
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'seq'   => (int) $row[0],
				'kind'  => (string) $row[1],
				'live'  => (string) $row[2],
				'stage' => (string) $row[3],
			);
		}
		return $out;
	}

	/**
	 * Whether this WordPress directory is positively another than the one a plan records: the recorded one is
	 * positively gone (Paths::positively_gone()), or both are there and their device and inode numbers differ. Two
	 * spellings of one directory (a bind mount, a file system that ignores case) are the same directory; anything that
	 * cannot be told (a stat that fails, inode numbers the platform does not give) is not "another".
	 *
	 * @param string $recorded The WordPress directory the plan records ('' for none).
	 * @param string $here     This WordPress directory, resolved ('' when it cannot be).
	 * @return bool
	 */
	private static function another( string $recorded, string $here ): bool {
		if ( '' === $recorded || '' === $here ) {
			return false;
		}
		clearstatcache( true );
		$then = @stat( $recorded ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- not to be read: told below.
		if ( false === $then ) {
			return Paths::positively_gone( $recorded );
		}
		$now = @stat( $here ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- not to be read: cannot be told.
		if ( false === $now || 0 === (int) $then['ino'] || 0 === (int) $now['ino'] ) {
			return false;
		}
		return (int) $then['dev'] !== (int) $now['dev'] || (int) $then['ino'] !== (int) $now['ino'];
	}

	/**
	 * This WordPress directory.
	 *
	 * @return string
	 */
	private function abspath(): string {
		return (string) ( $this->parts['abspath'] ?? ABSPATH );
	}

	/**
	 * The site's base table prefix.
	 *
	 * @return string
	 */
	private static function base_prefix(): string {
		global $wpdb;
		return (string) $wpdb->base_prefix;
	}
}
