<?php
/**
 * The operations every driver shares: tick, cancel, retry.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Support\HostFunctions;
use WPCheckpoint\Support\Logger;
use WPCheckpoint\Support\StoredNames;

defined( 'ABSPATH' ) || exit;

/**
 * REST, WP-CLI and the cron callback all go through here so the sequence
 * (schema check, housekeeping, tick, deferred cleanup, next hop) is the
 * same everywhere.
 */
final class JobActions {

	/**
	 * Site transient throttling sweep_events().
	 */
	const SWEPT = StoredNames::JOBS_SWEPT;

	/**
	 * Cron requests in a row that may put a job's tick off for starting too
	 * late (see cron_tick()); the next one ticks it, when it has time left for
	 * a unit.
	 */
	const MAX_CRON_DEFERRALS = 3;

	/**
	 * How long the first unit of a tick is taken to need when a late cron
	 * request forces one: with less time than this left before the request's
	 * time limit, it is put off again instead (see cron_tick()).
	 */
	const CRON_UNIT_SECONDS = 5;

	/**
	 * Late cron requests in a row that did not reach the Runner for the job
	 * (the first MAX_CRON_DEFERRALS included: one count) after which the job
	 * fails: the cron requests of this host start too late to ever run a
	 * unit.
	 */
	const CRON_DEFERRAL_LIMIT = 10;

	/**
	 * A budget start that leaves no time: the tick runs its first unit (the
	 * first unit of a tick always runs) and stops.
	 */
	const NO_TIME_LEFT = 0.0;

	/**
	 * What release_left() found: the file was taken down (or is no longer there).
	 */
	const LEFT_GONE = 'gone';

	/**
	 * What release_left() found: the file is not one of this plugin's restores (no mark, or not one written whole).
	 */
	const LEFT_NOT_OURS = 'not_ours';

	/**
	 * What release_left() found: the file carries a restore's mark that no job row here recorded.
	 */
	const LEFT_NO_JOB = 'no_job';

	/**
	 * What release_left() found: the job that recorded the mark has not ended, holds the site, or a run holds it.
	 */
	const LEFT_NOT_ENDED = 'not_ended';

	/**
	 * What release_left() found: the file of an ended job could not be taken down.
	 */
	const LEFT_STAYS = 'stays';

	/**
	 * Repository.
	 *
	 * @var JobRepository
	 */
	private $repository;

	/**
	 * Runner.
	 *
	 * @var Runner
	 */
	private $runner;

	/**
	 * Loopback / cron follow-up.
	 *
	 * @var Loopback
	 */
	private $loopback;

	/**
	 * Bytes the last web tick printed (see web_tick()).
	 *
	 * @var int
	 */
	private static $last_output_bytes = 0;

	/**
	 * The limits of this process: function(): array{memory_bytes: int, max_execution_time: int} (tests replace it).
	 *
	 * @var callable
	 */
	private $runtime = array( Budget::class, 'current_runtime' );

	/**
	 * What a job that holds the site and is managed elsewhere shows from here.
	 *
	 * @var HeldSite
	 */
	private $held;

	/**
	 * The checks of an undo (built on first use; tests inject one).
	 *
	 * @var Undo|null
	 */
	private $undo = null;

	/**
	 * Constructor.
	 *
	 * @param JobRepository $repository Repository.
	 * @param Runner        $runner     Runner.
	 * @param Loopback      $loopback   Loopback.
	 * @param HeldSite|null $held       What a job managed elsewhere shows from here (tests inject its parts).
	 */
	public function __construct( JobRepository $repository, Runner $runner, Loopback $loopback, ?HeldSite $held = null ) {
		$this->repository = $repository;
		$this->runner     = $runner;
		$this->loopback   = $loopback;
		$this->held       = $held ?? new HeldSite();
	}

	/**
	 * A job that holds the site changed and is managed by another token than this installation's (HeldSite): it, and
	 * what its plan shows from here; null when there is no such job, or it does not hold the site, was abandoned from
	 * this installation (JobRepository::abandoned_here()), or this installation manages it. A job abandoned from
	 * another installation is one: this site may be the one it left half swapped, and may take it over.
	 *
	 * @param int $id Job id.
	 * @return array{job: Job, assessment: array<string, mixed>}|null
	 */
	public function held_elsewhere( int $id ) {
		$job = $this->repository->find( $id );
		if ( null === $job || Job::SITE_UNTOUCHED === $job->site_state || $this->repository->abandoned_here( $job ) || in_array( $job->status, array( Job::COMPLETED, Job::CANCELLED ), true ) || $this->repository->manages( $job ) ) {
			return null;
		}
		return array(
			'job'        => $job,
			'assessment' => $this->held->assess( $job ),
		);
	}

	/**
	 * Let this installation manage a job that holds the site and is this site's (HeldSite::SITE): wp wpcheckpoint job
	 * rebind. Refused unless the code confirms it (HeldSite::code()) and, while the direction is not recorded, $then
	 * says "continue" or "rollback"; once it is recorded, $then is refused (the job only finishes).
	 *
	 * @param int    $id      Job id.
	 * @param string $confirm The confirmation code.
	 * @param string $then    "continue", "rollback" or ''.
	 * @return array{ok: bool, message: string}
	 */
	public function rebind( int $id, string $confirm, string $then ): array {
		$held = $this->held_elsewhere( $id );
		if ( null === $held ) {
			return self::outcome( false, $this->not_held( $id ) );
		}
		if ( array() !== $held['job']->unreadable_columns ) {
			return self::outcome( false, self::unreadable_refusal( $held['job'] ) );
		}
		$job = $held['job'];
		$see = $held['assessment'];
		if ( HeldSite::SITE !== $see['branch'] ) {
			/* translators: %s: why the job is not taken to be this site's */
			return self::outcome( false, sprintf( __( 'The job is not taken over: as far as can be told from here it is not this site\'s (%s). It is not run here.', 'wp-checkpoint' ), $see['why'] ) );
		}
		if ( ! hash_equals( HeldSite::code( HeldSite::REBIND, $job, '' ), $confirm ) ) {
			return self::outcome( false, __( 'The confirmation code is not this job\'s; wp wpcheckpoint job status shows the command with its code.', 'wp-checkpoint' ) );
		}
		if ( '' !== $see['direction'] && '' !== $then ) {
			return self::outcome( false, __( 'The restore already recorded its direction: taken over, it only finishes. Leave --then out.', 'wp-checkpoint' ) );
		}
		if ( '' === $see['direction'] && ! in_array( $then, array( 'continue', 'rollback' ), true ) ) {
			return self::outcome( false, __( 'Say what the restore does once taken over: --then=continue (it goes on as after any interruption: a swap cut off half way is put back first, then it can be retried) or --then=rollback (it puts the site back as it was and is cancelled). A swap that made every rename before it was interrupted finishes either way: wp wpcheckpoint job status says which applies.', 'wp-checkpoint' ) );
		}
		try {
			$job = $this->repository->take_over( $job, $then );
		} catch ( StaleJob $e ) {
			return self::outcome( false, __( 'The job changed meanwhile, or a run holds it; nothing was changed. Try again.', 'wp-checkpoint' ) );
		}
		$run = Job::FAILED === $job->status
			/* translators: %d: job id */
			? sprintf( __( 'Run it: wp wpcheckpoint job retry %1$d, then wp wpcheckpoint job run %1$d', 'wp-checkpoint' ), $job->id )
			/* translators: %d: job id */
			: sprintf( __( 'Run it: wp wpcheckpoint job run %d', 'wp-checkpoint' ), $job->id );
		$note = '';
		if ( $see['differs'] ) {
			// Taken over from another WordPress directory: the maintenance file there is not this one's to take down.
			/* translators: 1: the WordPress directory the job's plan records, 2: job id */
			$note = ' ' . sprintf( __( 'Its maintenance file stays up in %1$s, the WordPress directory its plan was written for: once this restore has ended, take it down there with wp wpcheckpoint job release %2$d.', 'wp-checkpoint' ), $see['recorded'], $job->id );
		}
		/* translators: 1: job id, 2: how to run it */
		return self::outcome( true, sprintf( __( 'Job %1$d is managed by this installation now. %2$s', 'wp-checkpoint' ), $job->id, $run ) . $note );
	}

	/**
	 * Take a job's maintenance file down from this WordPress directory (wp wpcheckpoint job release): only once the job
	 * no longer holds the site (completed, cancelled, put back, or abandoned from here: JobRepository::holds_site()),
	 * no run holds it, and only the file that carries the mark its row recorded (Job::$site_mark): a file another job
	 * wrote carries another mark and stays. While the job holds the site it is refused, and what can be done is said
	 * (holding_paths(); abandoned_elsewhere() for a job abandoned from another installation, which holds the site
	 * everywhere until it is taken over; nothing cleans it up yet). Checked on each read, the last right before the
	 * file goes.
	 * The job is not changed.
	 *
	 * @param int    $id      Job id.
	 * @param string $confirm The confirmation code.
	 * @return array{ok: bool, message: string}
	 */
	public function release( int $id, string $confirm ): array {
		$job = $this->repository->find( $id );
		if ( null === $job ) {
			return self::outcome( false, __( 'No such job.', 'wp-checkpoint' ) );
		}
		$why = $this->not_ended( $job );
		if ( '' !== $why ) {
			return self::outcome( false, $why );
		}
		if ( ! hash_equals( HeldSite::code( HeldSite::RELEASE, $job, (string) $this->held->assess( $job )['recorded'] ), $confirm ) ) {
			return self::outcome( false, __( 'The confirmation code is not this job\'s; wp wpcheckpoint job status shows the command with its code.', 'wp-checkpoint' ) );
		}
		$this->held->at( 'release_confirmed' );
		// Read again right before the file goes (Deleter calls it just before the unlink): a retry or a run between
		// the checks above and there holds it again.
		$late    = '';
		$confirm = function () use ( $id, &$late ): void {
			$now  = $this->repository->find( $id );
			$late = null === $now ? __( 'No such job.', 'wp-checkpoint' ) : $this->not_ended( $now );
			if ( '' !== $late ) {
				throw new StaleJob( 'The job no longer lets its maintenance file go.' );
			}
		};
		try {
			$gone = $this->held->release_ended( $job, $confirm );
		} catch ( StaleJob $e ) {
			return self::outcome( false, $late );
		}
		if ( null === $gone ) {
			return self::outcome( true, __( 'There is no maintenance file of this job in this WordPress directory; nothing was changed.', 'wp-checkpoint' ) );
		}
		return $gone
			? self::outcome( true, __( 'This job\'s maintenance file was taken down from this WordPress directory: the site here answers again. The job itself was not changed.', 'wp-checkpoint' ) )
			: self::outcome( false, __( 'This job\'s maintenance file could not be taken down; nothing else was changed. Try again.', 'wp-checkpoint' ) );
	}

	/**
	 * Take down a maintenance file that an earlier restore left in a WordPress directory, for a restore about to put
	 * up its own: only when it is the file a job row here recorded the mark of (Job::$site_mark), and that job has
	 * ended as release() judges it (not_ended()), read right before the file goes. A file with a mark that no row
	 * here recorded (copied from another site, or its row purged) stays, and so does anyone else's. The job is not
	 * changed.
	 *
	 * @param string   $dir   The WordPress directory.
	 * @param callable $lease Called right before the file is deleted, after that read (the restore's lease; throws to
	 *                        stop).
	 * @return array{outcome: string, id: int} outcome: one of the LEFT_* constants; id: the job that recorded the
	 *                                         mark (0 when none did).
	 * @throws StaleJob What the lease throws (the restore's own job changed), and the check of the job that left the
	 *                  file only through its outcome.
	 */
	public function release_left( string $dir, callable $lease ): array {
		$mark = Maintenance::mark_in( $dir );
		$job  = '' === $mark ? null : $this->repository->find_by_site_mark( $mark );
		if ( null === $job ) {
			return array(
				'outcome' => '' === $mark ? self::LEFT_NOT_OURS : self::LEFT_NO_JOB,
				'id'      => 0,
			);
		}
		$file = new Maintenance( $dir, $job->site_mark );
		if ( Maintenance::OURS !== $file->state() ) {
			return array(
				'outcome' => self::LEFT_NOT_OURS,
				'id'      => $job->id,
			);
		}
		$this->held->at( 'left_found' );
		$late    = false;
		$confirm = function () use ( $job, $lease, &$late ): void {
			$now = $this->repository->find( $job->id );
			if ( null === $now || '' !== $this->not_ended( $now ) ) {
				$late = true;
				throw new StaleJob( 'The job no longer lets its maintenance file go.' );
			}
			call_user_func( $lease );
		};
		try {
			$gone = $file->remove( $confirm );
		} catch ( StaleJob $e ) {
			if ( ! $late ) {
				throw $e;
			}
			return array(
				'outcome' => self::LEFT_NOT_ENDED,
				'id'      => $job->id,
			);
		}
		return array(
			'outcome' => $gone ? self::LEFT_GONE : self::LEFT_STAYS,
			'id'      => $job->id,
		);
	}

	/**
	 * Why a job's maintenance file may not be taken down yet, or '': it no longer holds the site
	 * (JobRepository::holds_site(): a job abandoned from another installation does), it is completed, cancelled or
	 * failed (queued, running or paused, a run may be about to hold it again: a retry's attempt carries the same
	 * mark), and no run holds its lock.
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	private function not_ended( Job $job ): string {
		if ( $this->repository->holds_site( $job ) ) {
			if ( array() !== $job->unreadable_columns ) {
				// First: the commands the other answers name refuse such a job (rebind(), abandon()).
				return self::unreadable_refusal( $job );
			}
			if ( Job::REASON_ABANDONED === $job->failure_reason ) {
				return self::abandoned_elsewhere( $job, $this->held->assess( $job ) );
			}
			if ( ! $this->repository->manages( $job ) ) {
				// While the job holds the site there is no case in which taking its file down here is right.
				return self::holding_paths( $job, $this->held->assess( $job ) );
			}
			return __( 'This installation manages the job and it still holds the site: run it with wp wpcheckpoint job run.', 'wp-checkpoint' );
		}
		if ( ! in_array( $job->status, array( Job::COMPLETED, Job::CANCELLED, Job::FAILED ), true ) || $job->is_locked( time() ) ) {
			return __( 'The job has not ended, or a run holds it: its maintenance file stays. Try again once it has ended.', 'wp-checkpoint' );
		}
		return '';
	}

	/**
	 * Why nothing is done to a job that holds the site and whose row cannot be read (Job::$unreadable_columns): what
	 * it did to the site, and so what taking it over, giving it up or taking its file down would do, cannot be told.
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	private static function unreadable_refusal( Job $job ): string {
		/* translators: 1: job id, 2: what cannot be read of the job's row */
		return sprintf( __( 'Job %1$d holds the site changed, and %2$s: what it did to the site cannot be told from it, so nothing was changed. Look at the site\'s directories and tables, and the .maintenance file in the WordPress directory, before anything else.', 'wp-checkpoint' ), $job->id, JobRepository::unreadable_text( $job->unreadable_columns ) );
	}

	/**
	 * Whether a job was abandoned from this installation (JobRepository::abandoned_here()).
	 *
	 * @param Job $job Job.
	 * @return bool
	 */
	public function abandoned_here( Job $job ): bool {
		return $this->repository->abandoned_here( $job );
	}

	/**
	 * Give up a job that holds the site and is not this site's as far as can be told (wp wpcheckpoint job abandon):
	 * first its maintenance file is taken down from this WordPress directory (as release()), then the job is
	 * recorded as abandoned (JobRepository::abandon_held()). In that order: a run that dies between them leaves the
	 * job holding the site with no file here, and a second abandon finishes; the other order could leave a site that
	 * answers only with a maintenance page and no job to say why.
	 *
	 * @param int    $id      Job id.
	 * @param string $confirm The confirmation code.
	 * @return array{ok: bool, message: string}
	 */
	public function abandon( int $id, string $confirm ): array {
		$held = $this->held_elsewhere( $id );
		if ( null !== $held && array() !== $held['job']->unreadable_columns ) {
			// What it did to the site (HeldSite::assess() reads the cursor) cannot be told: not given up on a guess.
			return self::outcome( false, self::unreadable_refusal( $held['job'] ) );
		}
		$why = null === $held ? $this->not_held( $id ) : $this->may_release( $held, HeldSite::ABANDON, $confirm );
		if ( '' !== $why ) {
			return self::outcome( false, $why );
		}
		$job = $held['job'];
		if ( Job::REASON_ABANDONED === $job->failure_reason ) {
			// Once is the record: abandoning it again would make it this installation's to have given up.
			/* translators: %d: job id */
			return self::outcome( false, sprintf( __( 'Job %d was already abandoned from another installation; nothing was changed.', 'wp-checkpoint' ), $job->id ) );
		}
		if ( ! $this->repository->holds_own_token() ) {
			// abandon_held() would refuse: the file must not go first for a refusal known in advance.
			return self::outcome( false, __( 'This installation holds no storage token of its own yet (it was found to be a copy, and that is not settled): settle it on the plugin\'s page first, then abandon the job. Nothing was changed.', 'wp-checkpoint' ) );
		}
		try {
			$gone = ! $held['assessment']['file_here'] || $this->held->release( $job, $held['assessment'] );
		} catch ( \RuntimeException $e ) {
			$gone = false;
		}
		if ( ! $gone ) {
			return self::outcome( false, __( 'This job\'s maintenance file could not be taken down, so the job was not abandoned; nothing else was changed. Try again.', 'wp-checkpoint' ) );
		}
		$this->held->at( 'abandon_released' );
		/* translators: %s: the WordPress directory the job's plan records */
		$message = sprintf( __( 'Abandoned from another WordPress directory (wp wpcheckpoint job abandon). The site its plan was written for, at %s, may hold tables and directories half swapped.', 'wp-checkpoint' ), $held['assessment']['recorded'] );
		try {
			$this->repository->abandon_held( $job, $message );
		} catch ( StaleJob $e ) {
			return self::outcome( false, __( 'The job changed meanwhile, or a run holds it; it was not abandoned (its maintenance file here is down). Try again.', 'wp-checkpoint' ) );
		}
		// Nothing of it is reclaimed (JobRepository::reclaim_scope()): its tables, the ones its swap moved aside among
		// them, are what that site needs to roll the restore back, if this database is shared with it after all.
		/* translators: 1: job id, 2: the WordPress directory the job's plan records */
		return self::outcome( true, sprintf( __( 'Job %1$d was abandoned: it never runs again from this installation. Its tables are kept, the ones its swap moved aside (named wcpold…) among them, and nothing at the paths its plan records is touched. If this database is shared with the site at %2$s after all, that site stays half swapped. While an abandoned restore exists, uninstalling WP Checkpoint leaves everything in place: nothing cleans one up yet.', 'wp-checkpoint' ), $job->id, $held['assessment']['recorded'] ) );
	}

	/**
	 * The checks of an undo.
	 *
	 * @param Undo|null $undo Checks (tests), or null for the site's own.
	 * @return Undo
	 */
	public function undo_gate( ?Undo $undo = null ): Undo {
		if ( null !== $undo ) {
			$this->undo = $undo;
		}
		if ( null === $this->undo ) {
			$this->undo = new Undo( $this->repository );
		}
		return $this->undo;
	}

	/**
	 * Undo a completed restore (wp wpcheckpoint job undo): without a confirmation code, what it would drop and the
	 * code; with the code, the job queued again at its own reverse (Undo, JobRepository::reopen_for_undo()), to be run
	 * by WP-CLI. Every check is read once, and again under the start lock right before the job is claimed.
	 *
	 * @param int    $id      Job id.
	 * @param string $confirm The confirmation code, or '' to be told it.
	 * @return array{ok: bool, message: string, confirmed: bool, code: string, lines: string[]}
	 * @throws JobsUnavailable When the jobs table is not usable, or the start lock is busy.
	 */
	public function undo( int $id, string $confirm ): array {
		$refuse = static function ( string $why ): array {
			return array(
				'ok'        => false,
				'message'   => $why,
				'confirmed' => false,
				'code'      => '',
				'lines'     => array(),
			);
		};
		$this->usable( $id );
		$gate = $this->undo_gate();
		$seen = $gate->assess( $id );
		if ( ! $seen['ok'] || null === $seen['job'] ) {
			return $refuse( $seen['why'] );
		}
		$lines   = Undo::lost_lines( $seen['lost'], $seen['since'] );
		$lines[] = sprintf(
			/* translators: %d: days */
			__( 'The restored site is kept for %d days after the undo, then removed; swapping it back in is not possible in this version.', 'wp-checkpoint' ),
			(int) ( JobRepository::WORK_RETENTION_SECONDS / 86400 )
		);
		if ( '' === $confirm ) {
			return array(
				'ok'        => true,
				/* translators: 1: job id, 2: confirmation code */
				'message'   => sprintf( __( 'Nothing was changed. To undo restore job %1$d, run: wp wpcheckpoint job undo %1$d --confirm=%2$s', 'wp-checkpoint' ), $id, $seen['code'] ),
				'confirmed' => false,
				'code'      => $seen['code'],
				'lines'     => $lines,
			);
		}
		$changed = __( 'The confirmation code does not match: the restore or its plan changed since it was given, or it is another job\'s. Nothing was changed; run the command without --confirm to see what it would do now.', 'wp-checkpoint' );
		if ( ! hash_equals( $seen['code'], $confirm ) ) {
			return $refuse( $changed );
		}
		if ( ! $this->repository->lock_starts() ) {
			throw new JobsUnavailable( 'Another job is being started right now; try again in a moment.' );
		}
		try {
			// Read again under the lock: a job that started, swapped or changed meanwhile is seen here.
			$again = $gate->assess( $id );
			if ( ! $again['ok'] || null === $again['job'] ) {
				return $refuse( $again['why'] );
			}
			if ( ! hash_equals( $again['code'], $confirm ) ) {
				return $refuse( $changed );
			}
			$job    = $again['job'];
			$others = array_values(
				array_filter(
					$this->active(),
					static function ( Job $other ) use ( $job ): bool {
						return $other->id !== $job->id;
					}
				)
			);
			$reason = JobConflicts::conflict( $job->type, $job->options, $others );
			if ( '' !== $reason ) {
				return $refuse( $reason );
			}
			$this->cancel_yielding();
			try {
				$job = $this->repository->reopen_for_undo( $job, SwapStep::undo_cursor( $again['attempt'], $job->site_mark ), __( 'Putting the site back as it was', 'wp-checkpoint' ), $job->managing_token() );
			} catch ( StaleJob $e ) {
				return $refuse( __( 'The job changed meanwhile, or a run holds it; nothing was changed. Try again.', 'wp-checkpoint' ) );
			}
		} finally {
			$this->repository->unlock_starts();
		}
		$this->runner->note( $job, Logger::WARNING, 'The restore is undone, as asked from WP-CLI (wp wpcheckpoint job undo)', array( 'since' => gmdate( 'Y-m-d H:i:s', $seen['since'] ) ) );
		return array(
			'ok'        => true,
			'message'   => __( 'The restore is being undone.', 'wp-checkpoint' ),
			'confirmed' => true,
			'code'      => $confirm,
			'lines'     => $lines,
		);
	}

	/**
	 * What to do from here with a job that holds the site, is managed elsewhere and is not this site's as far as can
	 * be told: the two ways, by whether the database is shared.
	 *
	 * @param Job                  $job Job.
	 * @param array<string, mixed> $see HeldSite::assess().
	 * @return string
	 */
	public static function holding_paths( Job $job, array $see ): string {
		$shared = sprintf(
			/* translators: 1: the WordPress directory the job's plan records, 2: job id */
			__( 'The restore still holds the site, so its maintenance file stays up here. If this database is shared with the site at %1$s: finish the restore or roll it back there; afterwards take its file down here with wp wpcheckpoint job release %2$d.', 'wp-checkpoint' ),
			'' === $see['recorded'] ? __( '(not recorded)', 'wp-checkpoint' ) : $see['recorded'],
			$job->id
		);
		if ( ! $see['differs'] ) {
			// No abandon command: nothing of what it does is said.
			return $shared . ' ' . __( 'If it is not shared, nothing can be done from this WordPress directory: it cannot be told for certain to be another one than that.', 'wp-checkpoint' );
		}
		return $shared . ' ' . sprintf(
			/* translators: %s: the abandon command */
			__( 'If it is not shared: %s (it takes the file down too; the restore\'s tables are kept, the ones its swap moved aside among them).', 'wp-checkpoint' ),
			'wp wpcheckpoint job abandon ' . $job->id . ' --confirm=' . HeldSite::code( HeldSite::ABANDON, $job, (string) $see['recorded'] )
		);
	}

	/**
	 * What to say when more than one job holds the site at once (JobRepository::holding_site()): which they are, and
	 * that a person has to look at them. It is not expected: no job is started or queued while one holds the site
	 * (held_reason()). Each holder's own runs and retries do not look at the other, and are left as they are. '' when
	 * one or none does, or the jobs could not be read (the commands say that themselves).
	 *
	 * @return string
	 */
	public function holding_warning(): string {
		try {
			$ids = $this->repository->holding_site();
		} catch ( \RuntimeException $e ) {
			return '';
		}
		if ( count( $ids ) < 2 ) {
			return '';
		}
		return sprintf(
			/* translators: %s: job ids */
			__( 'Jobs %s hold the site changed at the same time. This should not happen, and needs a person: look at the site, and at each of these jobs with wp wpcheckpoint job status, before running any of them.', 'wp-checkpoint' ),
			implode( ', ', array_map( 'intval', $ids ) )
		);
	}

	/**
	 * What to say of a job abandoned from another installation, wherever it is seen from (it holds the site everywhere
	 * but there): this may be the site it left half swapped.
	 *
	 * @param Job                  $job Job.
	 * @param array<string, mixed> $see HeldSite::assess().
	 * @return string
	 */
	public static function abandoned_elsewhere( Job $job, array $see ): string {
		$how = HeldSite::SITE === $see['branch']
			? __( 'If the restore is this site\'s, take it over here and finish it or roll it back: wp wpcheckpoint job status shows how.', 'wp-checkpoint' )
			: __( 'As far as can be told from here it is not this site\'s, and nothing can be done with it from this WordPress directory.', 'wp-checkpoint' );
		/* translators: 1: job id, 2: what can be done */
		return sprintf( __( 'Restore job %1$d was abandoned from another installation of WP Checkpoint, which said its database is not shared with this site. This site may still be half swapped by it: the restore holds the site here, and its maintenance file stays, until it is taken over (nothing cleans up an abandoned restore yet). %2$s', 'wp-checkpoint' ), $job->id, $how );
	}

	/**
	 * Why a job managed elsewhere may not be released or abandoned from here, or ''.
	 *
	 * @param array{job: Job, assessment: array<string, mixed>} $held    held_elsewhere().
	 * @param string                                            $action  HeldSite::RELEASE or ABANDON.
	 * @param string                                            $confirm The confirmation code.
	 * @return string
	 */
	private function may_release( array $held, string $action, string $confirm ): string {
		$see = $held['assessment'];
		if ( HeldSite::SITE === $see['branch'] ) {
			return __( 'As far as can be told from here, the job is this site\'s: take it over instead (wp wpcheckpoint job status shows how).', 'wp-checkpoint' );
		}
		$why = HeldSite::not_another( $see );
		if ( '' !== $why ) {
			return $why;
		}
		if ( ! hash_equals( HeldSite::code( $action, $held['job'], (string) $see['recorded'] ), $confirm ) ) {
			return __( 'The confirmation code is not this job\'s; wp wpcheckpoint job status shows the command with its code.', 'wp-checkpoint' );
		}
		return '';
	}

	/**
	 * Why a job is none that holds the site and is managed elsewhere.
	 *
	 * @param int $id Job id.
	 * @return string
	 */
	private function not_held( int $id ): string {
		$job = $this->repository->find( $id );
		if ( null === $job ) {
			return __( 'No such job.', 'wp-checkpoint' );
		}
		if ( Job::REASON_ABANDONED === $job->failure_reason ) {
			return __( 'The job was abandoned; there is nothing more to do with it.', 'wp-checkpoint' );
		}
		if ( Job::SITE_UNTOUCHED === $job->site_state || in_array( $job->status, array( Job::COMPLETED, Job::CANCELLED ), true ) ) {
			return __( 'The job does not hold the site changed.', 'wp-checkpoint' );
		}
		return __( 'This installation manages the job: run it with wp wpcheckpoint job run.', 'wp-checkpoint' );
	}

	/**
	 * An outcome.
	 *
	 * @param bool   $ok      Whether it was done.
	 * @param string $message What to say.
	 * @return array{ok: bool, message: string}
	 */
	private static function outcome( bool $ok, string $message ): array {
		return array(
			'ok'      => $ok,
			'message' => $message,
		);
	}

	/**
	 * When the current driver's budget started. A web request counts from
	 * its start (bootstrap included); WP-CLI (including "wp cron event run",
	 * which processes several events in one process) counts from now.
	 *
	 * @param bool|null $cli Whether this is a WP-CLI process; null detects (tests inject).
	 * @return float
	 */
	public static function started_at( $cli = null ): float {
		if ( null === $cli ) {
			$cli = defined( 'WP_CLI' ) && WP_CLI;
		}
		if ( $cli ) {
			return microtime( true );
		}
		if ( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) && is_numeric( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
			return (float) $_SERVER['REQUEST_TIME_FLOAT'];
		}
		return microtime( true );
	}

	/**
	 * Why a stored job must not run next to the active jobs created before it
	 * (lower id), or '' when it may.
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	public function conflict_for( Job $job ): string {
		$before = array_values(
			array_filter(
				$this->active(),
				static function ( Job $other ) use ( $job ): bool {
					return $other->id < $job->id;
				}
			)
		);
		return JobConflicts::conflict( $job->type, $job->options, $before );
	}

	/**
	 * Run work that must not interleave with a job start (deleting a
	 * backup): under the start lock, with the active jobs read inside it,
	 * so no job can be created between the conflict check the work makes
	 * and its last change.
	 *
	 * @template T
	 * @param callable(Job[]): T $work Gets the queued, running and paused jobs.
	 * @return T
	 * @throws JobsUnavailable When the lock is held elsewhere beyond the timeout.
	 * @throws \RuntimeException What the work throws (after the lock is released).
	 */
	public function exclusive( callable $work ) {
		if ( ! $this->repository->lock_starts() ) {
			throw new JobsUnavailable( 'Another job is being started right now; try again in a moment.' );
		}
		try {
			return $work( $this->active() );
		} finally {
			$this->repository->unlock_starts();
		}
	}

	/**
	 * Create a job, unless a job holds the site (held_reason(): nothing is
	 * created then, and nothing gives way) or it must not run next to the
	 * active ones (JobConflicts). The latter is checked after the insert
	 * against the active jobs with a lower id, so two requests racing each
	 * other cannot both start: the later one sees the earlier and removes
	 * itself before anything ran.
	 *
	 * @param string               $type       Job type.
	 * @param int                  $owner_user Creating user.
	 * @param array<string, mixed> $options    Options.
	 * @return Job
	 * @throws JobsUnavailable When jobs cannot be created now.
	 * @throws JobConflict When a job holds the site, or the job must not run next to an active one (the message says
	 *                     which).
	 */
	public function start( string $type, int $owner_user, array $options ): Job {
		if ( ! $this->repository->lock_starts() ) {
			throw new JobsUnavailable( 'Another job is being started right now; try again in a moment.' );
		}
		try {
			$held = $this->held_reason();
			if ( '' !== $held ) {
				throw new JobConflict( esc_html( $held ) );
			}
			if ( in_array( $type, array( JobConflicts::EXPORT, JobConflicts::RESTORE ), true ) ) {
				$this->cancel_yielding();
			}
			$job    = $this->repository->create( $type, $owner_user, array(), $options );
			$reason = $this->conflict_for( $job );
		} finally {
			$this->repository->unlock_starts();
		}
		if ( '' === $reason ) {
			// Whoever started it (a page, WP-CLI) usually ticks it at once; if not, cron does.
			Loopback::schedule( $job->id, Loopback::FALLBACK_SECONDS );
			return $job;
		}
		if ( ! $this->repository->discard_unstarted( $job->id ) ) {
			try {
				$this->cancel( $job->id ); // A driver picked it up in the meantime.
			} catch ( \RuntimeException $e ) {
				unset( $e ); // Finished or changed meanwhile: the conflict is reported all the same.
			} catch ( \LogicException $e ) {
				unset( $e );
			}
		}
		throw new JobConflict( esc_html( $reason ) );
	}

	/**
	 * Why no job may start or be queued again while a job holds the site, or '' when none does: a restore's swap under
	 * way or stopped partway, until it has ended (JobRepository::holding_site(), the rule of release and the
	 * notices). An export then would capture a site half restored that looks fine, and anything else would run on
	 * it. Names the first such job and what can be done with it, by where it is. Jobs that cannot be read are not
	 * "none".
	 *
	 * @return string
	 * @throws JobsUnavailable When the jobs could not be read.
	 */
	private function held_reason(): string {
		try {
			$ids = $this->repository->holding_site();
		} catch ( \RuntimeException $e ) {
			throw new JobsUnavailable( 'Whether a restore holds the site could not be read; try again in a moment.' );
		}
		if ( array() === $ids ) {
			return '';
		}
		$id  = (int) $ids[0];
		$job = $this->repository->find( $id );
		if ( null !== $job && ! $this->repository->manages( $job ) ) {
			/* translators: %d: job id */
			return sprintf( __( 'Restore job %1$d holds the site and another installation manages it: no job can start until it has ended. wp wpcheckpoint job status %1$d says what can be done from here.', 'wp-checkpoint' ), $id );
		}
		if ( null !== $job && Job::FAILED === $job->status ) {
			/* translators: %d: job id */
			return sprintf( __( 'Restore job %1$d stopped partway through and holds the site: no job can start until it has ended (a backup now would capture a half restored site). Resolve it with: wp wpcheckpoint job retry %1$d, then wp wpcheckpoint job run %1$d', 'wp-checkpoint' ), $id );
		}
		/* translators: %d: job id */
		return sprintf( __( 'Restore job %1$d holds the site and has not ended: no job can start until it has. wp wpcheckpoint job status %1$d shows where it is.', 'wp-checkpoint' ), $id );
	}

	/**
	 * Load a job.
	 *
	 * @param int $id Job id.
	 * @return Job|null
	 */
	public function find( int $id ) {
		return $this->repository->find( $id );
	}

	/**
	 * Cancel the jobs that give way (an estimate) before an export or a
	 * restore starts. Silently: the user started a backup, the estimate was
	 * ours; a cancel that fails (it finished meanwhile) changes nothing.
	 *
	 * @return void
	 */
	private function cancel_yielding(): void {
		foreach ( $this->active() as $job ) {
			if ( ! in_array( $job->type, JobConflicts::YIELDING, true ) ) {
				continue;
			}
			try {
				$this->cancel( $job->id );
			} catch ( \RuntimeException $e ) {
				unset( $e );
			} catch ( \LogicException $e ) {
				unset( $e );
			}
		}
	}

	/**
	 * The user's jobs, newest first: the jobs that give way (an estimate)
	 * are the plugin's own and are left out.
	 *
	 * @param string[] $statuses Statuses (empty for all).
	 * @param int      $limit    Maximum.
	 * @return Job[]
	 */
	public function list_user_jobs( array $statuses = array(), int $limit = 50 ): array {
		return array_values(
			array_filter(
				$this->repository->list_jobs( $statuses, $limit ),
				static function ( Job $job ): bool {
					return ! in_array( $job->type, JobConflicts::YIELDING, true );
				}
			)
		);
	}

	/**
	 * Queued, running and paused jobs: the ones JobConflicts weighs.
	 *
	 * @return Job[]
	 */
	public function active(): array {
		return $this->repository->list_jobs( array( Job::QUEUED, Job::RUNNING, Job::PAUSED ), 500 );
	}

	/**
	 * Jobs, newest first.
	 *
	 * @param string[] $statuses Statuses (empty for all).
	 * @param int      $limit    Maximum.
	 * @return Job[]
	 */
	public function list_jobs( array $statuses = array(), int $limit = 50 ): array {
		return $this->repository->list_jobs( $statuses, $limit );
	}

	/**
	 * A tick driven by a web request (the REST tick route, the loopback hop,
	 * a cron event): the request keeps running when the client goes away,
	 * and nothing it prints reaches the client.
	 *
	 * PHP notices a client that went away only when it tries to send output,
	 * and then ends the script: a tick killed like that holds its lock until
	 * the lease expires and counts as a takeover (three at the same position
	 * fail the job). ignore_user_abort( true ) keeps the request running;
	 * the output buffer makes sure a tick never sends anything, so a stray
	 * notice with display_errors on cannot be that write either. What was
	 * captured is discarded and its size logged. Such a tick does not turn
	 * into an orphan process: it is bounded by its time budget and its lease
	 * like any other.
	 *
	 * @param int        $id         Job id.
	 * @param float|null $started_at Budget start.
	 * @return TickResult
	 */
	public function web_tick( int $id, $started_at = null ): TickResult {
		return $this->captured(
			$id,
			function () use ( $id, $started_at ): TickResult {
				return $this->tick( $id, $started_at );
			}
		);
	}

	/**
	 * Run $work for a web entry point: the request keeps running without its client, and what it prints is
	 * captured, discarded and its size logged (see web_tick()).
	 *
	 * @template T
	 * @param int           $id   Job id (for the log line).
	 * @param callable(): T $work The work.
	 * @return T
	 */
	private function captured( int $id, callable $work ) {
		HostFunctions::ignore_user_abort();
		ob_start();
		try {
			return $work();
		} finally {
			$output                  = ob_get_clean();
			self::$last_output_bytes = is_string( $output ) ? strlen( $output ) : 0;
			if ( self::$last_output_bytes > 0 ) {
				$this->repository->log_event( sprintf( 'Job %d: the tick printed %d bytes; discarded, nothing was sent to the client.', $id, self::$last_output_bytes ) );
			}
		}
	}

	/**
	 * The tick of a cron event. wp-cron.php runs every due event in one
	 * request, and a tick's budget counts from the request start. A tick
	 * that starts LATE_CRON_SECONDS or more into its request would still run
	 * its first unit and could pass the server's time limit (then counted
	 * as a takeover), so it is put off to the next cron request, but at
	 * most MAX_CRON_DEFERRALS times in a row: on a host where every cron
	 * request starts that late, the job would otherwise never move on from
	 * cron. The next late request ticks it with no time left, so it runs
	 * its first unit only, when it can still hold one: with its time limit
	 * known (max_execution_time) and less than CRON_UNIT_SECONDS of it left,
	 * counted from the request start, the request puts the tick off again,
	 * and at CRON_DEFERRAL_LIMIT deferrals without a hand-off to the Runner
	 * in between the job fails with the reason. A timeout outside PHP (PHP-FPM's, a reverse
	 * proxy's) cannot be seen from here, so the floor applies only where
	 * PHP's own limit is set; without one, the tick runs its first unit as
	 * described. The count (Job::$cron_deferrals) is of cron requests in a
	 * row that did not reach the Runner for the job: any driver whose tick
	 * the Runner takes up sets it back to 0 (the forced tick too, whatever it
	 * then does), and so do a retry and an answer; a tick the Runner stops at
	 * a step only WP-CLI runs writes nothing, the count included.
	 * Each deferral and each forced tick is logged. Like web_tick(), nothing
	 * is sent to the client.
	 *
	 * @param int   $id         Job id.
	 * @param float $started_at When the cron request started (see started_at()).
	 * @return TickResult|null Null when the tick was put off.
	 */
	public function cron_tick( int $id, float $started_at ): ?TickResult {
		return $this->captured(
			$id,
			function () use ( $id, $started_at ): ?TickResult {
				if ( microtime( true ) - $started_at < Loopback::LATE_CRON_SECONDS ) {
					return $this->tick( $id, $started_at );
				}
				return $this->late_cron_tick( $id, round( microtime( true ) - $started_at, 1 ), $started_at );
			}
		);
	}

	/**
	 * A cron tick that starts late in its request: put off, or run with no time left (see cron_tick()).
	 *
	 * @param int   $id         Job id.
	 * @param float $late       Seconds into the request.
	 * @param float $started_at When the cron request started.
	 * @return TickResult|null Null when the tick was put off.
	 */
	private function late_cron_tick( int $id, float $late, float $started_at ): ?TickResult {
		// The count is written before the tick, which would otherwise be what migrates; a table that is behind
		// has no count to write, and the tick says why.
		$schema = Schema::ensure();
		if ( in_array( $schema['action'], array( 'pending', 'failed' ), true ) ) {
			return $this->tick( $id, $started_at );
		}
		$job = $this->repository->find( $id );
		if ( null === $job || ! in_array( $job->status, array( Job::QUEUED, Job::RUNNING, Job::PAUSED ), true ) || $job->awaiting_answer() ) {
			// Nothing of it runs (the tick only reports the job and settles its event): no reason to wait.
			return $this->tick( $id, $started_at );
		}
		// The next cron request is asked for before the deferral is counted: a request that dies in between
		// leaves an event and a lower count, never a count without an event (WP-Cron removed the one that ran).
		Loopback::schedule( $id, 1, Loopback::EARLIER );
		if ( $this->repository->count_cron_deferral( $id, self::MAX_CRON_DEFERRALS ) ) {
			$counted = $this->repository->find( $id );
			$this->runner->note(
				$job,
				Logger::INFO,
				'Tick put off to the next cron request: this one started too late',
				array(
					'late_seconds' => $late,
					'deferrals'    => null !== $counted ? $counted->cron_deferrals : $job->cron_deferrals + 1,
					'limit'        => self::MAX_CRON_DEFERRALS,
				)
			);
			return null;
		}
		// Put off MAX_CRON_DEFERRALS times: this request runs the first unit, if it can still hold one. Its time
		// left is taken as its own limit less the real time since the request started. What would end the
		// request is a timeout of the web server or a reverse proxy, and those count real time: real time is
		// closer to that than PHP's own count, which on all but Windows includes only the time the script runs
		// (not waiting for the database or the network) and starts over with set_time_limit(). Their limits
		// cannot be read from PHP, so max_execution_time stands in for them where it is set; with none (0, as
		// under WP-CLI) the unit runs, as it always did.
		$limit = (int) ( call_user_func( $this->runtime )['max_execution_time'] ?? 0 );
		if ( $limit > 0 ) {
			$left = $limit - ( microtime( true ) - $started_at );
			if ( $left < self::CRON_UNIT_SECONDS ) {
				return $this->no_room_for_a_unit( $job, $late, $left, $started_at );
			}
		}
		// The tick runs after all: its event is the one it would have had (the tick adjusts it as usual), not the
		// one just set for the next cron request.
		Loopback::schedule( $id, Loopback::FALLBACK_SECONDS, Loopback::REPLACE );
		$job = $this->repository->find( $id );
		if ( null === $job || ! in_array( $job->status, array( Job::QUEUED, Job::RUNNING, Job::PAUSED ), true ) ) {
			return $this->tick( $id, $started_at ); // It ended meanwhile.
		}
		$this->runner->note(
			$job,
			Logger::WARNING,
			$job->cron_deferrals >= self::MAX_CRON_DEFERRALS
				? sprintf( 'Cron requests start too slowly: %d cron requests in a row started too late to run the job, so this one runs the first unit only', $job->cron_deferrals )
				: 'This cron request started too late, and the deferral could not be recorded: it runs the first unit only',
			array( 'late_seconds' => $late )
		);
		return $this->tick( $id, self::NO_TIME_LEFT );
	}

	/**
	 * A late cron request after MAX_CRON_DEFERRALS deferrals that has too little time left for a unit: put off
	 * again, counted on (the event for the next cron request is set); at CRON_DEFERRAL_LIMIT the job fails
	 * (JobRepository::fail_for_late_cron(): not while a live run holds it, nor while it waits for an answer; a
	 * late request is not counted while a live run holds it either).
	 *
	 * @param Job   $job        Job, as read before counting.
	 * @param float $late       Seconds into the request.
	 * @param float $left       Seconds left of the request's time limit, counted from the request start.
	 * @param float $started_at When the cron request started.
	 * @return TickResult|null Null when the tick was put off.
	 */
	private function no_room_for_a_unit( Job $job, float $late, float $left, float $started_at ): ?TickResult {
		$counted = $this->repository->count_cron_deferral( $job->id, self::CRON_DEFERRAL_LIMIT );
		$now     = $this->repository->find( $job->id );
		if ( null === $now || ! in_array( $now->status, array( Job::QUEUED, Job::RUNNING, Job::PAUSED ), true ) || $now->awaiting_answer() ) {
			return $this->tick( $job->id, $started_at ); // Ended, or waits for an answer, meanwhile: the tick settles its event.
		}
		$context = array(
			'late_seconds' => $late,
			'left_seconds' => round( max( 0.0, $left ), 1 ),
			'deferrals'    => $now->cron_deferrals,
			'limit'        => self::CRON_DEFERRAL_LIMIT,
		);
		if ( ! $counted && $now->is_locked( $this->repository->now() ) ) {
			// Being run: not a job late requests keep from running (count_cron_deferral() leaves it).
			$this->runner->note( $job, Logger::INFO, 'Tick put off: a live run holds the job, so this late request is not counted', $context );
			return null;
		}
		if ( $now->cron_deferrals < self::CRON_DEFERRAL_LIMIT ) {
			$this->runner->note(
				$job,
				Logger::WARNING,
				$counted
					? 'Tick put off again: this cron request has less time left of its time limit than one unit needs (counted from the request start)'
					: 'Tick put off again: this cron request has too little time left for one unit, and the deferral could not be recorded',
				$context
			);
			return null;
		}
		$message = sprintf(
			/* translators: 1: number of cron requests, 2: how many of them had too little time left, 3: seconds. */
			__( 'The cron requests of this site start too late to run this job: %1$d in a row started too late to run it (and nothing else ran it in between); the last %2$d had less than %3$d seconds of the request\'s time limit left, counted in real time from the start of the request. Run cron with WP-CLI from a system cron job (wp cron event run --due-now), or run the job with WP-CLI (wp wpcheckpoint job run), then retry.', 'wp-checkpoint' ),
			$now->cron_deferrals,
			self::CRON_DEFERRAL_LIMIT - self::MAX_CRON_DEFERRALS,
			self::CRON_UNIT_SECONDS
		);
		$outcome = $this->repository->fail_for_late_cron( $now, $message, self::CRON_DEFERRAL_LIMIT );
		if ( JobRepository::FAIL_HELD === $outcome ) {
			// A live run holds it, it was answered, retried or handed to the Runner meanwhile: its state decides.
			$this->runner->note( $job, Logger::WARNING, 'Tick put off: the limit of deferrals is reached, but the job was not failed (a live run holds it, or it changed meanwhile)', $context );
			return null;
		}
		if ( JobRepository::FAIL_ERROR === $outcome ) {
			$this->runner->note( $job, Logger::WARNING, 'Tick put off: the limit of deferrals is reached, but the failure could not be written', $context );
			return null;
		}
		Loopback::unschedule( $job->id );
		$this->runner->note( $job, Logger::ERROR, 'Job failed', array_merge( array( 'error' => $message ), $context ) );
		return new TickResult( TickResult::FAILED, -1, $now, $message );
	}

	/**
	 * Bytes the last web tick printed (and discarded); 0 on a clean tick. For tests.
	 *
	 * @return int
	 */
	public static function last_output_bytes(): int {
		return self::$last_output_bytes;
	}

	/**
	 * One tick: schema, housekeeping, run, deferred cleanup, next hop.
	 *
	 * @param int        $id         Job id.
	 * @param float|null $started_at Budget start (see started_at()).
	 * @param bool       $follow_up  Whether to arrange the next tick (self-request or cron event). A driver
	 *                               that keeps ticking itself (the WP-CLI loop) passes false and calls
	 *                               follow_up() once when it stops before the job is finished.
	 * @return TickResult
	 */
	public function tick( int $id, $started_at = null, bool $follow_up = true ): TickResult {
		if ( $follow_up ) {
			// Before anything else: a request the server kills (in the tick, or in the reap before it) never
			// reaches its follow-up, and if cron started it, that event is used up. Only makes sure one exists
			// (a wait's later time stays); the result adjusts or clears it afterwards.
			Loopback::schedule( $id, Loopback::FALLBACK_SECONDS, Loopback::KEEP );
		}
		$schema = Schema::ensure();
		$behind = in_array( $schema['action'], array( 'pending', 'failed' ), true );
		if ( ! $behind ) {
			// Housekeeping writes rows as this code knows them: not on a table that is behind.
			$this->repository->maintenance();
			$this->sweep_events();
		}
		if ( 'pending' === $schema['action'] || ( 'failed' === $schema['action'] && ! is_array( $schema['problems'] ?? null ) ) ) {
			// An upgrade is due and this request may not try it, or the table could not be read: nothing says
			// what it lacks now. The job waits for the admin, cron or WP-CLI (the follow-up sets a cron event).
			$message = 'pending' === $schema['action'] ? Schema::pending_message( $schema['last_problems'] ?? null ) : Schema::problem_message( null );
			$result  = $this->held( $id, $message );
		} else {
			// A table the migration could not bring up to date (read in this request: a column missing, or
			// narrower than needed) fails the job with the reason.
			$problems = 'failed' === $schema['action'] ? (array) $schema['problems'] : array();
			$result   = $this->runner->tick( $id, null === $started_at ? self::started_at() : (float) $started_at, $problems );
		}
		if ( TickResult::LOST === $result->status && null !== $result->job && Job::CANCELLED === $result->job->status ) {
			// The cancel happened while this driver held the lock: the step has stopped now, so clean up here.
			$this->runner->cleanup( $result->job );
		}
		if ( $follow_up ) {
			$this->follow_up( $result );
			if ( TickResult::MISSING === $result->status ) {
				Loopback::unschedule( $id ); // No job to follow up: take back the event set before the tick.
			}
		}
		return $result;
	}

	/**
	 * The result of a tick that waits for the job table: for a job that can still run, blocked with $message; for
	 * one that ended or waits for an answer, what the Runner would say (and no cron event for it).
	 *
	 * @param int    $id      Job id.
	 * @param string $message Why it waits.
	 * @return TickResult
	 */
	private function held( int $id, string $message ): TickResult {
		$job = $this->repository->find( $id );
		if ( null === $job ) {
			return new TickResult( TickResult::MISSING, -1, null );
		}
		if ( ! in_array( $job->status, array( Job::QUEUED, Job::RUNNING, Job::PAUSED ), true ) ) {
			return new TickResult( TickResult::FINISHED, -1, $job );
		}
		if ( $job->awaiting_answer() ) {
			return new TickResult( TickResult::PAUSED, -1, $job, __( 'The job is waiting for your decision.', 'wp-checkpoint' ) );
		}
		return new TickResult( TickResult::BLOCKED, Loopback::FALLBACK_SECONDS, $job, $message );
	}

	/**
	 * Remove cron events of jobs no driver should tick any more (throttled
	 * like the reap): a job that ended without a follow-up, one that waits
	 * for an answer, one that was purged.
	 *
	 * @return void
	 */
	private function sweep_events(): void {
		if ( false !== get_site_transient( self::SWEPT ) ) {
			return;
		}
		set_site_transient( self::SWEPT, 1, JobRepository::REAP_THROTTLE );
		Loopback::sweep(
			function ( int $id ): bool {
				global $wpdb;
				$job = $this->repository->find( $id );
				if ( null === $job ) {
					return '' !== (string) $wpdb->last_error; // A failed query proves nothing: keep the event.
				}
				// An answered job stays paused, with no questions, until its next tick takes it.
				return in_array( $job->status, array( Job::QUEUED, Job::RUNNING ), true ) || ( Job::PAUSED === $job->status && ! $job->awaiting_answer() );
			}
		);
	}

	/**
	 * Arrange the next tick for a result: a self-request for "more", a cron
	 * event for waits, nothing (and a clean-up of both) for the rest.
	 *
	 * @param TickResult $result Tick result.
	 * @return void
	 */
	public function follow_up( TickResult $result ): void {
		$this->loopback->after_tick( $result );
	}

	/**
	 * Cancel a job. The lock is taken with the same compare-and-set as a
	 * tick: when that succeeds nobody is working on the job and the cleanup
	 * runs here; when it fails the holder cleans up as soon as its next
	 * fenced write refuses (see tick()).
	 *
	 * @param int $id Job id.
	 * @return array{job: Job, cleaned: bool, reason: string}|null Null when the job does not exist. reason: "cleaned",
	 *                                                            "holder" (a driver holds the lock and cleans up when it
	 *                                                            stops), "unavailable" (the storage directory cannot be
	 *                                                            used from here; nothing will clean up), "requested"
	 *                                                            (a restore's swap is under way: it is rolled back, then
	 *                                                            the job is cancelled), "swapped" (refused: the restored
	 *                                                            site is in place; the job is not cancelled),
	 *                                                            "elsewhere" (refused: it holds the site and another
	 *                                                            installation manages it, JobRepository::manages()) or
	 *                                                            "abandoned" (refused: a cancel request would turn a
	 *                                                            later take-over's "continue" into a rollback),
	 *                                                            "undo_given_up" (an undo that had not changed the site
	 *                                                            was given up: the job is completed again, the restored
	 *                                                            site in place), "undo_requested" (such an undo is
	 *                                                            running: it gives itself up before it changes the
	 *                                                            site) or "undoing" (refused: the undo has started
	 *                                                            changing the site and finishes).
	 * @throws InvalidTransition When the job is already finished.
	 * @throws StaleJob When the job changed meanwhile.
	 */
	public function cancel( int $id ) {
		$job = $this->repository->find( $id );
		if ( null === $job ) {
			return null;
		}
		if ( Job::REASON_ABANDONED === $job->failure_reason ) {
			// Refused: a cancel request on its row would end a later take-over's "continue" cancelled, not retryable.
			return array(
				'job'     => $job,
				'cleaned' => false,
				'reason'  => 'abandoned',
			);
		}
		if ( Job::SITE_UNTOUCHED !== $job->site_state && ! $this->repository->manages( $job ) ) {
			// Refused: another installation manages it (its rollback would run there, or nowhere).
			return array(
				'job'     => $job,
				'cleaned' => false,
				'reason'  => 'elsewhere',
			);
		}
		if ( SwapStep::undoing( $job->cursor ) && Job::SITE_UNTOUCHED !== $job->site_state ) {
			// An undo is given up only before it changes the site (2026-10-30): then the restored site stays, the job
			// completed again; once it changed anything it finishes, and the cancel is refused. Never accepted and not done.
			$reason = 'undoing';
			if ( 'undo' === ( $job->cursor['phase'] ?? '' ) ) {
				try {
					$reason = 'given_up' === $this->repository->cancel_undo( $job ) ? 'undo_given_up' : 'undo_requested';
				} catch ( StaleJob $e ) {
					$reason = 'undoing'; // It started changing the site meanwhile (or ended): refused, as from here on.
				}
			}
			$now = $this->repository->find( $id ) ?? $job;
			if ( 'undo_given_up' === $reason ) {
				$this->runner->note( $now, Logger::WARNING, 'The undo was cancelled before it changed the site: the restored site stays' );
			}
			return array(
				'job'     => $now,
				'cleaned' => false,
				'reason'  => $reason,
			);
		}
		if ( Job::SITE_CHANGING === $job->site_state ) {
			// Its step rolls the site back first (in WP-CLI), then cancels it; nothing is taken from it here.
			$this->repository->request_cancel( $job );
			return array(
				'job'     => $job,
				'cleaned' => false,
				'reason'  => 'requested',
			);
		}
		if ( Job::SITE_SWAPPED === $job->site_state ) {
			// Refused: a cancel cannot change the restored site back.
			return array(
				'job'     => $job,
				'cleaned' => false,
				'reason'  => 'swapped',
			);
		}
		Loopback::unschedule( $id );
		Loopback::revoke_tokens( $id );

		$held = in_array( $job->status, array( Job::QUEUED, Job::RUNNING ), true ) ? $this->repository->acquire_for_cancel( $id ) : null;
		if ( null !== $held ) {
			$job = $held['job'];
			try {
				$this->repository->transition( $job, Job::CANCELLED );
			} catch ( StaleJob $e ) {
				// The row changed under our lock: a concurrent cancel cleared the lock and reported "the holder
				// cleans up" (that holder is this request), or the write itself failed. Our compare-and-set
				// succeeded, so nobody was writing; if the job is cancelled now, clean up here. This is only
				// safe in this branch: without a successful compare-and-set a cancelled status says nothing
				// about who may still be writing.
				$current = $this->repository->find( $id );
				if ( null !== $current && Job::CANCELLED === $current->status ) {
					$this->runner->cleanup( $current );
					return array(
						'job'     => $current,
						'cleaned' => true,
						'reason'  => 'cleaned',
					);
				}
				throw $e; // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- rethrown unchanged.
			} finally {
				// Guarded by the token: a no-op when the transition already cleared the lock or someone else took it.
				$this->repository->release( $job, $held['token'] );
			}
			$this->runner->cleanup( $job );
			return array(
				'job'     => $job,
				'cleaned' => true,
				'reason'  => 'cleaned',
			);
		}

		$was_paused = Job::PAUSED === $job->status;
		$holder     = $job->is_locked( $this->repository->now() );
		$this->repository->transition( $job, Job::CANCELLED );
		if ( $was_paused ) {
			// A paused job holds no lock, so nobody is writing.
			$this->runner->cleanup( $job );
		}
		return array(
			'job'     => $job,
			'cleaned' => $was_paused,
			'reason'  => $was_paused ? 'cleaned' : ( $holder ? 'holder' : 'unavailable' ),
		);
	}

	/**
	 * Store the answers to a paused job's questions. The job is not ticked
	 * here: the caller ticks it (or hands it to the other drivers with a
	 * follow-up of a "more" result) once the answers are in.
	 *
	 * @param int                  $id      Job id.
	 * @param array<string, mixed> $answers Answers keyed by question id.
	 * @return Job|null Null when the job does not exist.
	 * @throws InvalidTransition When the job is not waiting for an answer.
	 * @throws \InvalidArgumentException When an answer carries a secret.
	 * @throws StaleJob When the job changed meanwhile.
	 * @throws JobsUnavailable When the job table lacks columns the answer writes.
	 */
	public function answer( int $id, array $answers ) {
		$job = $this->usable( $id );
		if ( null === $job ) {
			return null;
		}
		$answered = $this->repository->answer( $job, $answers );
		Loopback::schedule( $id, Loopback::FALLBACK_SECONDS ); // Answered and the page closed: the job still goes on.
		return $answered;
	}

	/**
	 * A job to change from outside a tick (answer, retry), after the schema had its chance to be brought up to
	 * date; refused while its row lacks columns those writes need.
	 *
	 * @param int $id Job id.
	 * @return Job|null Null when the job does not exist.
	 * @throws JobsUnavailable When the row lacks columns.
	 */
	private function usable( int $id ) {
		// Where the request may upgrade (WP-CLI), columns lost since the version was recorded are added again; a
		// REST request (the page's Retry and answers) only reads, and the job's row is the check.
		$schema = Schema::ensure( true );
		if ( 'failed' === $schema['action'] ) {
			throw new JobsUnavailable( esc_html( Schema::problem_message( $schema['problems'] ?? null ) ) );
		}
		if ( 'pending' === $schema['action'] ) {
			throw new JobsUnavailable( esc_html( Schema::pending_message( $schema['last_problems'] ?? null ) ) );
		}
		$job = $this->repository->find( $id );
		if ( null !== $job && array() !== $job->missing_columns ) {
			throw new JobsUnavailable( esc_html( Schema::problem_message( $job->missing_columns ) ) );
		}
		return $job;
	}

	/**
	 * Queue a failed job again; the cursor is kept. Under the start lock and by the rules a new job of its type
	 * starts by (JobConflicts), against every other active job: a job queued again is as much a job that starts as
	 * a new one, and a retry next to a job it must not run with is refused with the reason, the job left failed. The
	 * jobs that give way (an estimate) are cancelled as before a new export or restore. Whether the job can be
	 * retried at all (the state machine, JobRepository::check_retry()) is checked first, on the job read under the
	 * lock: a job that cannot be retried says so, and nothing gives way for it. While another job holds the site
	 * (held_reason()) it is refused as a new job is; a job that holds the site itself is retried, the way to put the
	 * site right.
	 *
	 * @param int $id Job id.
	 * @return Job|null Null when the job does not exist.
	 * @throws InvalidTransition When the job is not failed.
	 * @throws StaleJob When the job changed meanwhile.
	 * @throws JobsUnavailable When the job table lacks columns the retry writes, another start holds the lock, or the
	 *                         jobs that hold the site could not be read.
	 * @throws JobConflict When another job holds the site, or the job must not run next to an active one (the message
	 *                     says which).
	 */
	public function retry( int $id ) {
		$job = $this->usable( $id );
		if ( null === $job ) {
			return null;
		}
		if ( ! $this->repository->lock_starts() ) {
			throw new JobsUnavailable( 'Another job is being started right now; try again in a moment.' );
		}
		try {
			// Read again under the lock, and whether it can be retried at all before anything else is weighed.
			$job = $this->repository->find( $id );
			if ( null === $job ) {
				return null;
			}
			if ( Job::UNDONE === $job->status ) {
				$this->repository->check_retry( $job ); // Refuses it: an undone job is never retried.
			}
			( clone $job )->transition( Job::QUEUED );
			$this->repository->check_retry( $job );
			// A job that holds the site is retried to put it right; any other waits until none does, as a new one.
			$held = $this->repository->holds_site( $job ) ? '' : $this->held_reason();
			if ( '' !== $held ) {
				throw new JobConflict( esc_html( $held ) );
			}
			$others = array_values(
				array_filter(
					$this->active(),
					static function ( Job $other ) use ( $job ): bool {
						return $other->id !== $job->id;
					}
				)
			);
			$reason = JobConflicts::conflict( $job->type, $job->options, $others );
			if ( '' !== $reason ) {
				throw new JobConflict( esc_html( $reason ) );
			}
			if ( in_array( $job->type, array( JobConflicts::EXPORT, JobConflicts::RESTORE ), true ) ) {
				$this->cancel_yielding();
			}
			$queued = $this->repository->transition( $job, Job::QUEUED );
		} finally {
			$this->repository->unlock_starts();
		}
		Loopback::schedule( $id, Loopback::FALLBACK_SECONDS );
		return $queued;
	}
}
