<?php
/**
 * Whether a completed restore may be undone, or an undone one swapped back in, and what either would drop.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Database\SqlWriter;
use WPCheckpoint\Restore\ImportSession;
use WPCheckpoint\Restore\Queries;
use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Restore\SwapPlan;
use WPCheckpoint\Restore\SwapRules;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Standalone\Failure;
use WPCheckpoint\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * The checks of wp wpcheckpoint job undo and job redo, read once before the confirmation code is given and again under
 * the start lock right before the job is claimed (JobActions::undo(), JobActions::redo()): every refusal says why.
 *
 * Undo: a restore, completed, its swap committed (site_state SITE_SWAPPED, committed_at recorded) no more than
 * WINDOW_SECONDS ago; redo: a restore undone (Job::UNDONE) no more than JobRepository::WORK_RETENTION_SECONDS ago and
 * its work not reclaimed. Both: managed by this installation; no job holds the site and no other restore is under way;
 * no job in the jobs table committed a swap after this one, whichever installation manages it (a restore of another
 * installation sharing this database changes the same tables); its work directory here, its swap plan whole and
 * written for this WordPress directory and table prefix; and every table and directory of the plan where the swap
 * left it (undo) or where the undo left it (redo), by positive evidence: what cannot be read refuses.
 *
 * The confirmation code binds the action, the job, its storage token, its plan (the attempt and every entry) and the
 * time the swap was committed (undo) or undone (redo): any of them changed, the code no longer holds. What would be
 * dropped is counted from the live tables of the plan by kind (comments, content, orders, uploads, users) since that
 * time; a count that cannot be read is null ("could not be counted"), never 0.
 */
final class Undo {

	const UNDO = 'undo';
	const REDO = 'redo';

	/**
	 * How long after its swap was committed a restore may be undone (the same 7 days as a failed job's work).
	 */
	const WINDOW_SECONDS = 604800;

	/**
	 * Kinds of writes that would be dropped, by the table they are counted in.
	 */
	const COMMENTS = 'comments';
	const CONTENT  = 'content';
	const ORDERS   = 'orders';
	const UPLOADS  = 'uploads';
	const USERS    = 'users';
	const KINDS    = array( self::COMMENTS, self::CONTENT, self::ORDERS, self::UPLOADS, self::USERS );

	/**
	 * Post types counted as orders rather than content (WooCommerce, stored as posts).
	 */
	const ORDER_TYPES = array( 'shop_order', 'shop_order_refund', 'shop_order_placehold' );

	/**
	 * Jobs.
	 *
	 * @var JobRepository
	 */
	private $repository;

	/**
	 * Returns the connection: function(): Queries.
	 *
	 * @var callable
	 */
	private $connect;

	/**
	 * Unix time: function(): int.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * The WordPress directory.
	 *
	 * @var string
	 */
	private $abspath;

	/**
	 * The job types that are restores.
	 *
	 * @var string[]
	 */
	private $restores;

	/**
	 * Constructor.
	 *
	 * @param JobRepository        $repository Jobs.
	 * @param array<string, mixed> $parts      Tests: "connect" (function(): Queries; the site's own settings by
	 *                                         default), "now" (function(): int), "abspath" (the WordPress directory),
	 *                                         "restore_types" (the job types that are restores).
	 */
	public function __construct( JobRepository $repository, array $parts = array() ) {
		$connect          = $parts['connect'] ?? null;
		$this->repository = $repository;
		$this->connect    = is_callable( $connect ) ? $connect : static function (): Queries {
			try {
				$credentials = Credentials::from_wordpress();
			} catch ( Failure $e ) {
				throw new \RuntimeException( 'The database settings of this site cannot be read: ' . $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message; callers say their own.
			}
			return ImportSession::open( $credentials );
		};
		$this->clock      = isset( $parts['now'] ) && is_callable( $parts['now'] ) ? $parts['now'] : 'time';
		$this->abspath    = isset( $parts['abspath'] ) ? (string) $parts['abspath'] : ABSPATH;
		$this->restores   = isset( $parts['restore_types'] ) ? array_map( 'strval', (array) $parts['restore_types'] ) : array( RestoreJob::ID );
	}

	/**
	 * Whether the job may be undone (UNDO) or swapped back in (REDO), and if so its code and what would be dropped.
	 *
	 * @param string $action UNDO or REDO.
	 * @param int    $id     Job id.
	 * @return array{ok: bool, why: string, job: Job|null, attempt: int, code: string, since: int, lost: array<string, int|null>}
	 */
	public function assess( string $action, int $id ): array {
		$out = array(
			'ok'      => false,
			'why'     => '',
			'job'     => null,
			'attempt' => 0,
			'code'    => '',
			'since'   => 0,
			'lost'    => array(),
		);
		try {
			$job = $this->repository->find( $id );
		} catch ( \RuntimeException $e ) {
			$job = null;
		}
		if ( null === $job ) {
			$out['why'] = __( 'No such job.', 'wp-checkpoint' );
			return $out;
		}
		$out['job'] = $job;
		$why        = self::REDO === $action ? $this->not_redoable( $job ) : $this->not_undoable( $job );
		if ( '' === $why ) {
			$why = $this->not_alone( $job );
		}
		if ( '' !== $why ) {
			$out['why'] = $why;
			return $out;
		}
		try {
			$db = call_user_func( $this->connect );
		} catch ( \RuntimeException $e ) {
			$out['why'] = __( 'The database cannot be reached to read the restore\'s plan; nothing was changed. Try again.', 'wp-checkpoint' );
			return $out;
		}
		try {
			$plan = $this->plan( $job, $db );
			if ( is_string( $plan ) ) {
				$out['why'] = $plan;
				return $out;
			}
			$why = $this->not_in_place( $action, $plan['entries'], $db );
			if ( '' !== $why ) {
				$out['why'] = $why;
				return $out;
			}
			$since          = self::REDO === $action ? $job->finished_at : $job->committed_at;
			$out['ok']      = true;
			$out['attempt'] = $plan['attempt'];
			$out['since']   = $since;
			$out['code']    = self::code( $action, $job, $plan['attempt'], $plan['entries'], $since );
			$out['lost']    = $this->lost( $plan['entries'], $since, $db );
			return $out;
		} finally {
			if ( $db instanceof ImportSession ) {
				$db->close();
			}
		}
	}

	/**
	 * The confirmation code: the action, the job, its storage token, the plan's attempt and entries, and the time the
	 * code is measured from (committed or undone).
	 *
	 * @param string                                                                                              $action  UNDO or REDO.
	 * @param Job                                                                                                 $job     Job.
	 * @param int                                                                                                 $attempt The plan's attempt.
	 * @param array<int, array{seq: int, kind: string, live: string, stage: string, old: string, had_live: bool}> $entries The plan's entries.
	 * @param int                                                                                                 $since   Committed or undone at.
	 * @return string
	 */
	public static function code( string $action, Job $job, int $attempt, array $entries, int $since ): string {
		$plan = array();
		foreach ( $entries as $entry ) {
			$plan[] = implode( "\0", array( (string) $entry['seq'], $entry['kind'], $entry['live'], $entry['stage'], $entry['old'], $entry['had_live'] ? '1' : '0' ) );
		}
		return substr( hash( 'sha256', implode( "\n", array( 'wpcheckpoint-' . $action, (string) $job->id, $job->storage_token, (string) $attempt, (string) $since, implode( "\n", $plan ) ) ) ), 0, 16 );
	}

	/**
	 * Why a job cannot be undone by its own state ('' when it can, so far).
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	private function not_undoable( Job $job ): string {
		if ( ! in_array( $job->type, $this->restores, true ) ) {
			return __( 'Only a restore can be undone.', 'wp-checkpoint' );
		}
		if ( Job::UNDONE === $job->status ) {
			/* translators: %d: job id */
			return sprintf( __( 'Restore job %1$d was undone already. To swap the restored copy back in: wp wpcheckpoint job redo %1$d', 'wp-checkpoint' ), $job->id );
		}
		if ( Job::COMPLETED !== $job->status || Job::SITE_SWAPPED !== $job->site_state ) {
			/* translators: 1: job id, 2: status */
			return sprintf( __( 'Restore job %1$d is %2$s: only a restore that completed can be undone. A restore that has not ended is put back by its own run (wp wpcheckpoint job status says how).', 'wp-checkpoint' ), $job->id, $job->status );
		}
		if ( '' === $job->site_mark ) {
			/* translators: %d: job id */
			return sprintf( __( 'Restore job %d has no maintenance mark recorded, so the site could not be held while it is put back: it is not undone.', 'wp-checkpoint' ), $job->id );
		}
		if ( $job->committed_at <= 0 ) {
			/* translators: %d: job id */
			return sprintf( __( 'Restore job %d completed before this version of WP Checkpoint recorded when a swap was made, so whether it is within the time it can be undone in cannot be told: it is not undone. Restore an earlier backup instead.', 'wp-checkpoint' ), $job->id );
		}
		if ( $this->now() > $job->committed_at + self::WINDOW_SECONDS ) {
			/* translators: 1: job id, 2: days, 3: date and time the restore was made (UTC) */
			return sprintf( __( 'Restore job %1$d can no longer be undone: a restore can be undone for %2$d days after it was made (%3$s UTC). What it replaced is kept until it is cleaned up; to go back, restore an earlier backup.', 'wp-checkpoint' ), $job->id, (int) ( self::WINDOW_SECONDS / 86400 ), gmdate( 'Y-m-d H:i', $job->committed_at ) );
		}
		return '';
	}

	/**
	 * Why an undone job cannot be swapped back in by its own state ('' when it can, so far).
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	private function not_redoable( Job $job ): string {
		if ( ! in_array( $job->type, $this->restores, true ) || Job::UNDONE !== $job->status || Job::SITE_UNTOUCHED !== $job->site_state ) {
			/* translators: 1: job id, 2: status */
			return sprintf( __( 'Job %1$d is %2$s: only a restore that was undone can be swapped back in.', 'wp-checkpoint' ), $job->id, $job->status );
		}
		if ( $job->work_expired_at > 0 || $job->finished_at <= 0 || $this->now() > $job->finished_at + JobRepository::WORK_RETENTION_SECONDS ) {
			/* translators: 1: job id, 2: days */
			return sprintf( __( 'Restore job %1$d can no longer be swapped back in: its restored copy is kept for %2$d days after the undo, and that time is over (the copy is removed, or about to be). Restore the backup again instead.', 'wp-checkpoint' ), $job->id, (int) ( JobRepository::WORK_RETENTION_SECONDS / 86400 ) );
		}
		return '';
	}

	/**
	 * Why the job is not the one to act on now: managed elsewhere, another job holds the site or is a restore under
	 * way, a later swap, its work elsewhere ('' when none).
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	private function not_alone( Job $job ): string {
		if ( ! $this->repository->manages( $job ) ) {
			/* translators: %d: job id */
			return sprintf( __( 'Restore job %d is managed by another installation of WP Checkpoint: it is undone or swapped back in only from there.', 'wp-checkpoint' ), $job->id );
		}
		try {
			$holding = array_values( array_diff( $this->repository->holding_site(), array( $job->id ) ) );
			$active  = $this->repository->list_jobs( array( Job::QUEUED, Job::RUNNING, Job::PAUSED ), 500 );
			$later   = $this->repository->later_commit( $job );
		} catch ( \RuntimeException $e ) {
			return __( 'The jobs could not be read, so whether another job is in the way cannot be told; nothing was changed. Try again.', 'wp-checkpoint' );
		}
		if ( array() !== $holding ) {
			/* translators: %d: job id */
			return sprintf( __( 'Job %d holds the site: nothing is undone or swapped back in until it has ended (wp wpcheckpoint job status shows where it is).', 'wp-checkpoint' ), $holding[0] );
		}
		foreach ( $active as $other ) {
			if ( $other->id !== $job->id && in_array( $other->type, $this->restores, true ) ) {
				/* translators: %d: job id */
				return sprintf( __( 'Restore job %d is under way: nothing is undone or swapped back in until it has ended.', 'wp-checkpoint' ), $other->id );
			}
		}
		if ( null !== $later ) {
			/* translators: 1: job id, 2: the later job's id */
			return sprintf( __( 'Restore job %2$d swapped the site after restore job %1$d (whichever installation of WP Checkpoint ran it): going back to before %1$d would drop that restore and everything written since. It is not done.', 'wp-checkpoint' ), $job->id, $later );
		}
		if ( '' === $this->repository->files_base( $job ) ) {
			/* translators: %d: job id */
			return sprintf( __( 'The work files of restore job %d are in a storage directory other than this one, so its plan cannot be read from here; nothing was changed.', 'wp-checkpoint' ), $job->id );
		}
		return '';
	}

	/**
	 * The plan the swap was made by: its attempt (from the work directory) and every entry, whole, of this job's
	 * grammar and written for this WordPress directory and table prefix; or why not.
	 *
	 * @param Job     $job Job.
	 * @param Queries $db  Connection.
	 * @return array{attempt: int, entries: array<int, array{seq: int, kind: string, live: string, stage: string, old: string, had_live: bool}>}|string
	 */
	private function plan( Job $job, Queries $db ) {
		$cannot = __( 'The restore\'s plan cannot be read whole, so what the swap changed cannot be told: nothing was changed.', 'wp-checkpoint' );
		try {
			$file = ExportPlan::read( Residue::work_dir( $this->repository->files_base( $job ), $job->id ), RestoreFiles::SWAP_PLAN );
		} catch ( \RuntimeException $e ) {
			return $cannot;
		}
		$attempt = (int) ( $file['attempt'] ?? 0 );
		$plan    = new SwapPlan( $db, self::base_prefix() . SwapPlan::TABLE );
		try {
			$count   = $plan->complete_count( $job->id, $attempt );
			$entries = null === $count ? array() : $plan->read( $job->id, $attempt, -1, $count + 1 );
		} catch ( \RuntimeException $e ) {
			return $cannot;
		}
		if ( $attempt < 1 || null === $count || count( $entries ) !== $count || (int) ( $file['entries'] ?? -1 ) !== $count ) {
			return $cannot;
		}
		$site = null;
		foreach ( $entries as $entry ) {
			if ( '' !== SwapRules::invalid( $entry, $job->storage_token, $job->id ) ) {
				return $cannot;
			}
			if ( SwapPlan::SITE === $entry['kind'] && 0 === $entry['seq'] ) {
				$site = $entry;
			}
		}
		$real = Paths::real( $this->abspath );
		$here = false === $real ? '' : rtrim( Paths::normalize( (string) $real ), '/' );
		if ( null === $site || '' === $here || $here !== $site['live'] || self::base_prefix() !== $site['stage'] ) {
			return __( 'The restore\'s plan was not written for this WordPress directory and table prefix: nothing was changed.', 'wp-checkpoint' );
		}
		return array(
			'attempt' => $attempt,
			'entries' => $entries,
		);
	}

	/**
	 * Why a table or directory of the plan is not where the swap (undo) or the undo (redo) left it ('' when all are).
	 *
	 * @param string                                                                                              $action  UNDO or REDO.
	 * @param array<int, array{seq: int, kind: string, live: string, stage: string, old: string, had_live: bool}> $entries Entries.
	 * @param Queries                                                                                             $db      Connection.
	 * @return string
	 */
	private function not_in_place( string $action, array $entries, Queries $db ): string {
		$tables = array();
		$dirs   = array();
		foreach ( $entries as $entry ) {
			if ( in_array( $entry['kind'], array( SwapPlan::TABLE_OF, SwapPlan::MOVE ), true ) ) {
				$tables[] = $entry;
			} elseif ( SwapPlan::DIR === $entry['kind'] ) {
				$dirs[] = $entry;
			}
		}
		$there = array();
		try {
			foreach ( array_chunk( SwapRules::names( $tables ), SwapStep::PAGE ) as $names ) {
				foreach ( $db->rows( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode( ', ', array_fill( 0, count( $names ), '?' ) ) . ')', $names ) as $row ) {
					if ( in_array( (string) $row[0], $names, true ) ) {
						$there[ (string) $row[0] ] = true;
					}
				}
			}
		} catch ( \RuntimeException $e ) {
			return __( 'Which of the restore\'s tables are there cannot be read now; nothing was changed. Try again.', 'wp-checkpoint' );
		}
		$want = self::REDO === $action ? SwapRules::BEFORE : SwapRules::AFTER;
		foreach ( $tables as $entry ) {
			if ( SwapRules::table_state( $entry, $there ) !== $want ) {
				/* translators: %s: table name */
				return sprintf( __( 'The table %s is not where the restore left it, so the site cannot be put back by its plan: nothing was changed.', 'wp-checkpoint' ), $entry['live'] );
			}
		}
		foreach ( $dirs as $entry ) {
			$l = self::exists( $entry['live'] );
			$s = self::exists( $entry['stage'] );
			$o = self::exists( $entry['old'] );
			if ( null === $l || null === $s || null === $o ) {
				/* translators: %s: directory */
				return sprintf( __( 'Whether %s is where the restore left it cannot be told now; nothing was changed. Try again.', 'wp-checkpoint' ), $entry['live'] );
			}
			$after  = $l && ! $s && $o === $entry['had_live'];
			$before = $s && ! $o && $l === $entry['had_live'];
			if ( ( self::REDO === $action && ! $before ) || ( self::UNDO === $action && ! $after ) ) {
				/* translators: %s: directory */
				return sprintf( __( 'The directory %s is not where the restore left it, so the site cannot be put back by its plan: nothing was changed.', 'wp-checkpoint' ), $entry['live'] );
			}
		}
		return '';
	}

	/**
	 * Whether a path is there: true, false (positively gone: its directory lists without it), or null (cannot tell).
	 *
	 * @param string $path Path.
	 * @return bool|null
	 */
	private static function exists( string $path ) {
		clearstatcache( true, $path );
		if ( false !== @lstat( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a path that is not there is the answer.
			return true;
		}
		return Paths::positively_gone( $path ) ? false : null;
	}

	/**
	 * What would be dropped: per kind, the rows written since $since in the plan's live tables of that kind (null when a
	 * count could not be read; kinds with no such table are left out).
	 *
	 * @param array<int, array{seq: int, kind: string, live: string, stage: string, old: string, had_live: bool}> $entries Entries.
	 * @param int                                                                                                 $since   Unix time.
	 * @param Queries                                                                                             $db      Connection.
	 * @return array<string, int|null>
	 */
	private function lost( array $entries, int $since, Queries $db ): array {
		$at      = gmdate( 'Y-m-d H:i:s', $since );
		$orders  = "'" . implode( "', '", self::ORDER_TYPES ) . "'";
		$queries = array();
		foreach ( $entries as $entry ) {
			if ( SwapPlan::TABLE_OF !== $entry['kind'] ) {
				continue;
			}
			$table = SqlWriter::identifier( $entry['live'] );
			switch ( self::table_kind( $entry['live'] ) ) {
				case 'comments':
					$queries[ self::COMMENTS ][] = "SELECT COUNT(*) FROM {$table} WHERE comment_date_gmt >= ?";
					break;
				case 'posts':
					$queries[ self::CONTENT ][] = "SELECT COUNT(*) FROM {$table} WHERE post_modified_gmt >= ? AND post_type NOT IN ('revision', 'attachment', {$orders})";
					$queries[ self::UPLOADS ][] = "SELECT COUNT(*) FROM {$table} WHERE post_date_gmt >= ? AND post_type = 'attachment'";
					$queries[ self::ORDERS ][]  = "SELECT COUNT(*) FROM {$table} WHERE post_modified_gmt >= ? AND post_type IN ({$orders})";
					break;
				case 'wc_orders':
					$queries[ self::ORDERS ][] = "SELECT COUNT(*) FROM {$table} WHERE date_updated_gmt >= ?";
					break;
				case 'users':
					$queries[ self::USERS ][] = "SELECT COUNT(*) FROM {$table} WHERE user_registered >= ?";
					break;
			}
		}
		$out = array();
		foreach ( self::KINDS as $kind ) {
			if ( ! isset( $queries[ $kind ] ) ) {
				continue;
			}
			$sum = 0;
			foreach ( $queries[ $kind ] as $sql ) {
				try {
					$rows = $db->rows( $sql, array( $at ) );
				} catch ( \RuntimeException $e ) {
					$sum = null;
					break;
				}
				$sum += (int) ( $rows[0][0] ?? 0 );
			}
			$out[ $kind ] = $sum;
		}
		return $out;
	}

	/**
	 * Which of the counted tables a live table name is (posts, comments, users, wc_orders), or ''. The site's own
	 * names: the table prefix, then a site number on a network, then the table.
	 *
	 * @param string $live Live table name.
	 * @return string
	 */
	public static function table_kind( string $live ): string {
		$prefix = self::base_prefix();
		if ( '' !== $prefix && 0 !== strpos( $live, $prefix ) ) {
			return '';
		}
		$rest = (string) substr( $live, strlen( $prefix ) );
		if ( 'users' === $rest ) {
			return 'users';
		}
		$rest = (string) preg_replace( '/\A[0-9]+_/', '', $rest );
		return in_array( $rest, array( 'posts', 'comments', 'wc_orders' ), true ) ? $rest : '';
	}

	/**
	 * What would be dropped, in words, one line per kind.
	 *
	 * @param array<string, int|null> $lost  lost().
	 * @param int                     $since Unix time.
	 * @return string[]
	 */
	public static function lost_lines( array $lost, int $since ): array {
		$labels = array(
			self::COMMENTS => __( 'comments', 'wp-checkpoint' ),
			self::CONTENT  => __( 'posts, pages and other content added or changed', 'wp-checkpoint' ),
			self::ORDERS   => __( 'orders added or changed', 'wp-checkpoint' ),
			self::UPLOADS  => __( 'files uploaded to the media library', 'wp-checkpoint' ),
			self::USERS    => __( 'new users', 'wp-checkpoint' ),
		);
		/* translators: %s: date and time (UTC) */
		$lines = array( sprintf( __( 'Everything written on the site since %s UTC is dropped, among it:', 'wp-checkpoint' ), gmdate( 'Y-m-d H:i', $since ) ) );
		foreach ( self::KINDS as $kind ) {
			if ( ! array_key_exists( $kind, $lost ) ) {
				continue;
			}
			$lines[] = '  ' . $labels[ $kind ] . ': ' . ( null === $lost[ $kind ] ? __( 'could not be counted', 'wp-checkpoint' ) : (string) $lost[ $kind ] );
		}
		$lines[] = '  ' . __( 'and every other change: settings, plugins and themes changed or updated, files written outside the media library.', 'wp-checkpoint' );
		return $lines;
	}

	/**
	 * Now.
	 *
	 * @return int
	 */
	private function now(): int {
		return (int) call_user_func( $this->clock );
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
