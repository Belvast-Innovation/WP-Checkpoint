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
		foreach ( $rows as $row ) {
			if ( SwapPlan::SITE === $row['kind'] && 0 === $row['seq'] ) {
				$site = $row;
			} elseif ( SwapPlan::DIR === $row['kind'] ) {
				$dirs[] = $row['live'];
			}
		}
		$real = Paths::real( $this->abspath() );
		$here = false === $real ? '' : rtrim( Paths::normalize( (string) $real ), '/' );
		$why  = '';
		if ( null === $site ) {
			$why = 'its plan does not say which site it was written for';
		} elseif ( self::base_prefix() !== $site['stage'] ) {
			$why = 'its plan was written for another table prefix';
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
			'differs'   => '' !== $here && '' !== $recorded && ! Paths::same( $recorded, $here, Paths::is_windows() ),
			'direction' => in_array( $phase, array( 'committed', 'done' ), true ) ? 'committed' : ( 'restored' === $phase ? 'restored' : '' ),
			'file_here' => '' !== $mark && Maintenance::OURS === ( new Maintenance( $this->abspath(), $mark ) )->state(),
		);
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
		return hash( 'sha256', implode( "\0", array( 'wpcheckpoint-held-site', $action, (string) $job->id, $job->storage_token, $bound ) ) );
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
		if ( ! $assessment['differs'] ) {
			throw new \RuntimeException( 'This WordPress directory is not positively another than the one the job\'s plan records; nothing was taken down.' );
		}
		$mark = (string) ( $job->cursor['mark'] ?? '' );
		return '' === $mark || ( new Maintenance( $this->abspath(), $mark ) )->remove();
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
				return 'where one of the directories it swaps is cannot be told from here';
			}
			$path   = rtrim( Paths::normalize( (string) $real ), '/' ) . substr( $live, (int) $slash );
			$inside = false;
			foreach ( $groups as $group ) {
				$inside = $inside || LinkedTargets::within( $group, $path );
			}
			if ( ! $inside ) {
				return 'a directory it swaps is not one of this site\'s content directories';
			}
		}
		return array() === $dirs ? 'its plan swaps no directory of this site' : '';
	}

	/**
	 * The job's plan rows (site and directory units) of the attempt its cursor names, else of its last complete one.
	 *
	 * @param Job $job Job.
	 * @return array<int, array{seq: int, kind: string, live: string, stage: string}>
	 */
	private function plan_rows( Job $job ): array {
		global $wpdb;
		$table   = self::base_prefix() . SwapPlan::TABLE;
		$attempt = (int) ( $job->cursor['attempt'] ?? 0 );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table name from the prefix.
		if ( $attempt <= 0 ) {
			$attempt = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(attempt), 0) FROM {$table} WHERE job_id = %d AND kind = %s", $job->id, SwapPlan::COMPLETE ) );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT seq, kind, live, stage FROM {$table} WHERE job_id = %d AND attempt = %d AND kind IN (%s, %s) ORDER BY seq", $job->id, $attempt, SwapPlan::SITE, SwapPlan::DIR ), ARRAY_N );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
