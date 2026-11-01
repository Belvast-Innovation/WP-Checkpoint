<?php
/**
 * Whether a stuck restore that holds the site may be put back, and what that would do.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Restore\ImportSession;
use WPCheckpoint\Restore\Queries;
use WPCheckpoint\Restore\SwapPlan;
use WPCheckpoint\Restore\SwapRules;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Standalone\Failure;
use WPCheckpoint\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * The checks of wp wpcheckpoint job put-back, read once before the confirmation code is given and again under the
 * start lock right before the job is claimed (JobActions::put_back()): every refusal says why.
 *
 * A restore that holds the site (site_state changing or swapped) and has not ended, at its swap step, with its
 * maintenance mark recorded; managed by this installation; no live run holds it, no other job holds the site and no
 * other restore is under way; and its swap plan whole in the plan table, written for this WordPress directory and
 * table prefix. What it rests on is the plan, what is there, and the row's maintenance mark: neither the job's cursor
 * nor its options are read (either may be what keeps the job stuck). The plan's attempt is the highest the table holds
 * with its COMPLETE row (a job that holds the site never writes another: the final check is not run again while it
 * does). Which way: a swap that was half made (changing) is rolled back and the restore cancelled; one that was
 * committed (swapped) is reversed as an undo, and the restore ends undone.
 *
 * A plan that cannot be read is not guessed at: the site is in a state that needs a person, the job keeps holding it,
 * and what can be read is listed (the plan table's rows of the job by attempt, the job's tables by their names, and
 * which of the readable entries' live names are there).
 *
 * The confirmation code binds the job, its storage token, the plan (the attempt and every entry), the site state, the
 * maintenance mark and the time the swap was committed.
 */
final class PutBack {

	/**
	 * Jobs repository.
	 *
	 * @var JobRepository
	 */
	private $repository;

	/**
	 * Returns a connection: function(): Queries.
	 *
	 * @var callable
	 */
	private $connect;

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
	 * @param array<string, mixed> $parts      Tests: "connect" (function(): Queries), "abspath", "restore_types".
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
		$this->abspath    = isset( $parts['abspath'] ) ? (string) $parts['abspath'] : ABSPATH;
		$this->restores   = isset( $parts['restore_types'] ) ? array_map( 'strval', (array) $parts['restore_types'] ) : array( RestoreJob::ID );
	}

	/**
	 * Whether the job may be put back, and if so how, its code and what would be dropped.
	 *
	 * @param int $id Job id.
	 * @return array{ok: bool, why: string, evidence: string[], job: Job|null, committed: bool, attempt: int, code: string, lines: string[], raw: array<string, mixed>}
	 */
	public function assess( int $id ): array {
		$out = array(
			'ok'        => false,
			'why'       => '',
			'evidence'  => array(),
			'job'       => null,
			'committed' => false,
			'attempt'   => 0,
			'code'      => '',
			'lines'     => array(),
			'raw'       => array(),
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
		$why        = $this->not_put_back( $job );
		if ( '' === $why ) {
			$why = $this->not_alone( $job );
		}
		if ( '' !== $why ) {
			$out['why'] = $why;
			return $out;
		}
		try {
			$raw = $this->repository->put_back_raw( $id );
		} catch ( \RuntimeException $e ) {
			$out['why'] = $e->getMessage();
			return $out;
		}
		if ( null === $raw ) {
			$out['why'] = __( 'No such job.', 'wp-checkpoint' );
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
			if ( null === $plan ) {
				/* translators: %d: job id */
				$out['why']      = sprintf( __( 'The swap plan of restore job %d cannot be read whole, so what its swap changed cannot be told from it: the site is in an inconsistent state and needs a person. Nothing was changed; the job keeps holding the site, so no other job starts. What can be read is below.', 'wp-checkpoint' ), $job->id );
				$out['evidence'] = $this->evidence( $job, $db );
				return $out;
			}
			$committed        = Job::SITE_SWAPPED === $job->site_state;
			$out['ok']        = true;
			$out['committed'] = $committed;
			$out['attempt']   = $plan['attempt'];
			$out['raw']       = $raw;
			$out['code']      = self::code( $job, $plan['attempt'], $plan['entries'] );
			$out['lines']     = $committed ? $this->committed_lines( $job, $plan['entries'], $db ) : self::half_lines();
			return $out;
		} finally {
			if ( $db instanceof ImportSession ) {
				$db->close();
			}
		}
	}

	/**
	 * The confirmation code: the job, its storage token, the plan's attempt and entries, the site state, the
	 * maintenance mark and the time the swap was committed.
	 *
	 * @param Job                                                                                                 $job     Job.
	 * @param int                                                                                                 $attempt The plan's attempt.
	 * @param array<int, array{seq: int, kind: string, live: string, stage: string, old: string, had_live: bool}> $entries The plan's entries.
	 * @return string
	 */
	public static function code( Job $job, int $attempt, array $entries ): string {
		$plan = array();
		foreach ( $entries as $entry ) {
			$plan[] = implode( "\0", array( (string) $entry['seq'], $entry['kind'], $entry['live'], $entry['stage'], $entry['old'], $entry['had_live'] ? '1' : '0' ) );
		}
		return substr( hash( 'sha256', implode( "\n", array( 'wpcheckpoint-put-back', (string) $job->id, $job->storage_token, (string) $attempt, (string) $job->site_state, $job->site_mark, (string) $job->committed_at, implode( "\n", $plan ) ) ) ), 0, 16 );
	}

	/**
	 * Why the job cannot be put back by its own row ('' when it can, so far).
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	private function not_put_back( Job $job ): string {
		if ( ! in_array( $job->type, $this->restores, true ) ) {
			return __( 'Only a restore can be put back.', 'wp-checkpoint' );
		}
		if ( Job::SITE_UNTOUCHED === $job->site_state || ! in_array( $job->status, array( Job::QUEUED, Job::RUNNING, Job::PAUSED, Job::FAILED ), true ) ) {
			/* translators: %d: job id */
			return sprintf( __( 'Restore job %d does not hold the site: there is nothing to put back. A restore that completed is undone with wp wpcheckpoint job undo.', 'wp-checkpoint' ), $job->id );
		}
		if ( Job::REASON_ABANDONED === $job->failure_reason ) {
			/* translators: %d: job id */
			return sprintf( __( 'Restore job %d was abandoned: it is not put back from here.', 'wp-checkpoint' ), $job->id );
		}
		if ( SwapStep::ID !== $job->step ) {
			/* translators: %d: job id */
			return sprintf( __( 'Restore job %d holds the site at a step other than its swap: the job row was changed. Nothing was changed; look at the site before you go on.', 'wp-checkpoint' ), $job->id );
		}
		if ( '' === $job->site_mark ) {
			/* translators: %d: job id */
			return sprintf( __( 'Restore job %d has no maintenance mark recorded, so the site could not be held while it is put back: it is not put back.', 'wp-checkpoint' ), $job->id );
		}
		return '';
	}

	/**
	 * Why the job is not the one to act on now: managed elsewhere, a run holds it, another job holds the site or is a
	 * restore under way ('' when none).
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	private function not_alone( Job $job ): string {
		if ( ! $this->repository->manages( $job ) ) {
			/* translators: %d: job id */
			return sprintf( __( 'Restore job %d is managed by another installation of WP Checkpoint: it is put back from there, or taken over here first (wp wpcheckpoint job status says how).', 'wp-checkpoint' ), $job->id );
		}
		if ( $job->is_locked( $this->repository->now() ) ) {
			/* translators: %d: job id */
			return sprintf( __( 'A run holds restore job %d right now: wait for it to end (or stop it), then put it back.', 'wp-checkpoint' ), $job->id );
		}
		try {
			$holding = array_values( array_diff( $this->repository->holding_site(), array( $job->id ) ) );
			$active  = $this->repository->list_jobs( array( Job::QUEUED, Job::RUNNING, Job::PAUSED ), 500 );
		} catch ( \RuntimeException $e ) {
			return __( 'The jobs could not be read, so whether another job is in the way cannot be told; nothing was changed. Try again.', 'wp-checkpoint' );
		}
		if ( array() !== $holding ) {
			/* translators: 1: job id, 2: the other job's id */
			return sprintf( __( 'Job %2$d holds the site too: restore job %1$d is not put back while another job holds the site. This needs a person: look at both with wp wpcheckpoint job status.', 'wp-checkpoint' ), $job->id, $holding[0] );
		}
		foreach ( $active as $other ) {
			if ( $other->id !== $job->id && in_array( $other->type, $this->restores, true ) ) {
				/* translators: %d: job id */
				return sprintf( __( 'Restore job %d is under way: nothing is put back until it has ended.', 'wp-checkpoint' ), $other->id );
			}
		}
		return '';
	}

	/**
	 * The plan in the plan table, whole: the highest attempt with its COMPLETE row, every entry of this job's grammar,
	 * written for this WordPress directory and table prefix; null when it cannot be read so.
	 *
	 * @param Job     $job Job.
	 * @param Queries $db  Connection.
	 * @return array{attempt: int, entries: array<int, array{seq: int, kind: string, live: string, stage: string, old: string, had_live: bool}>}|null
	 */
	private function plan( Job $job, Queries $db ) {
		$plan = new SwapPlan( $db, self::base_prefix() . SwapPlan::TABLE );
		try {
			$attempt = 0;
			foreach ( $plan->attempts( $job->id ) as $row ) {
				if ( $row['complete'] ) {
					$attempt = $row['attempt'];
					break;
				}
			}
			$count   = $attempt > 0 ? $plan->complete_count( $job->id, $attempt ) : null;
			$entries = null === $count ? array() : $plan->read( $job->id, $attempt, -1, $count + 1 );
		} catch ( \RuntimeException $e ) {
			return null;
		}
		if ( null === $count || count( $entries ) !== $count ) {
			return null;
		}
		$site = null;
		foreach ( $entries as $entry ) {
			if ( '' !== SwapRules::invalid( $entry, $job->storage_token, $job->id ) ) {
				return null;
			}
			if ( SwapPlan::SITE === $entry['kind'] && 0 === $entry['seq'] ) {
				$site = $entry;
			}
		}
		$real = Paths::real( $this->abspath );
		$here = false === $real ? '' : rtrim( Paths::normalize( (string) $real ), '/' );
		if ( null === $site || '' === $here || $here !== $site['live'] || self::base_prefix() !== $site['stage'] ) {
			return null;
		}
		return array(
			'attempt' => $attempt,
			'entries' => $entries,
		);
	}

	/**
	 * What can be read when the plan cannot: the plan table's rows of the job by attempt, the job's tables by their
	 * names (staging, moved aside, moved out of the way), and of the entries that can be read, which live names are
	 * there. Reads only.
	 *
	 * @param Job     $job Job.
	 * @param Queries $db  Connection.
	 * @return string[]
	 */
	private function evidence( Job $job, Queries $db ): array {
		$lines = array();
		$plan  = new SwapPlan( $db, self::base_prefix() . SwapPlan::TABLE );
		try {
			$attempts = $plan->attempts( $job->id );
			if ( array() === $attempts ) {
				$lines[] = __( 'The plan table holds no rows of this job.', 'wp-checkpoint' );
			}
			foreach ( $attempts as $row ) {
				/* translators: 1: attempt, 2: entries, 3: complete or not */
				$lines[] = sprintf( __( 'Plan attempt %1$d: %2$d entries, %3$s.', 'wp-checkpoint' ), $row['attempt'], $row['rows'], $row['complete'] ? __( 'marked complete', 'wp-checkpoint' ) : __( 'not marked complete', 'wp-checkpoint' ) );
			}
		} catch ( \RuntimeException $e ) {
			$attempts = array();
			$lines[]  = __( 'The plan table cannot be read.', 'wp-checkpoint' );
		}
		try {
			$prefix = TempTables::job_prefix( $job->storage_token, $job->id );
			$tail   = (string) substr( $prefix, strlen( TempTables::PREFIX ) );
			$kinds  = array(
				$prefix                          => __( 'restored copy (staging)', 'wp-checkpoint' ),
				TempTables::OLD_PREFIX . $tail   => __( 'moved aside by the swap (the site before the restore)', 'wp-checkpoint' ),
				TempTables::STRAY_PREFIX . $tail => __( 'moved out of the way by a rollback (someone else\'s)', 'wp-checkpoint' ),
			);
			foreach ( $kinds as $start => $what ) {
				$rows = $db->rows( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LEFT(TABLE_NAME, ?) = ? ORDER BY TABLE_NAME LIMIT 500', array( (string) strlen( $start ), $start ) );
				foreach ( $rows as $row ) {
					$lines[] = sprintf( '  %s: %s', (string) $row[0], $what );
				}
			}
		} catch ( \Throwable $e ) {
			$lines[] = __( 'The tables of this job cannot be listed.', 'wp-checkpoint' );
		}
		foreach ( $attempts as $row ) {
			try {
				foreach ( $plan->read( $job->id, $row['attempt'], -1, 500 ) as $entry ) {
					if ( in_array( $entry['kind'], array( SwapPlan::TABLE_OF, SwapPlan::MOVE ), true ) ) {
						$there   = $db->rows( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', array( $entry['live'] ) );
						$lines[] = sprintf(
							/* translators: 1: attempt, 2: table name, 3: there or not */
							__( 'Plan attempt %1$d, %2$s: %3$s.', 'wp-checkpoint' ),
							$row['attempt'],
							$entry['live'],
							(int) ( $there[0][0] ?? 0 ) > 0 ? __( 'a table is there under this name', 'wp-checkpoint' ) : __( 'no table under this name', 'wp-checkpoint' )
						);
					}
				}
			} catch ( \RuntimeException $e ) {
				/* translators: %d: attempt */
				$lines[] = sprintf( __( 'Plan attempt %d: its entries cannot be read.', 'wp-checkpoint' ), $row['attempt'] );
			}
		}
		return $lines;
	}

	/**
	 * What a put-back of a committed swap does and drops: an undo's lines (Undo::lost_lines()), from the time the swap
	 * was committed; a row that recorded no such time cannot be counted from it.
	 *
	 * @param Job                                                                                                 $job     Job.
	 * @param array<int, array{seq: int, kind: string, live: string, stage: string, old: string, had_live: bool}> $entries The plan's entries.
	 * @param Queries                                                                                             $db      Connection.
	 * @return string[]
	 */
	private function committed_lines( Job $job, array $entries, Queries $db ): array {
		if ( $job->committed_at > 0 ) {
			$lines = Undo::lost_lines( Undo::lost( $entries, $job->committed_at, $db ), $job->committed_at );
		} else {
			$lines = array(
				__( 'Everything written on the site since the restore\'s swap is dropped (when the swap was made was not recorded, so nothing of it can be counted).', 'wp-checkpoint' ),
			);
		}
		/* translators: %d: days */
		$lines[] = sprintf( __( 'The site is put back as it was before the restore, and the restore ends undone. The restored site is kept for %d days, then removed.', 'wp-checkpoint' ), (int) ( JobRepository::WORK_RETENTION_SECONDS / 86400 ) );
		return $lines;
	}

	/**
	 * What a put-back of a swap that was half made does.
	 *
	 * @return string[]
	 */
	private static function half_lines(): array {
		return array(
			__( 'The swap was not complete: the site is put back as it was before the restore, and the restore is cancelled. Its restored copy is removed with it.', 'wp-checkpoint' ),
		);
	}

	/**
	 * The table prefix of the site's own tables (the network's on multisite).
	 *
	 * @return string
	 */
	private static function base_prefix(): string {
		global $wpdb;
		return (string) $wpdb->base_prefix;
	}
}
