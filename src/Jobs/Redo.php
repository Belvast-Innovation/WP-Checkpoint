<?php
/**
 * Whether an undone restore may be swapped back in, and what that would drop.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Restore\ImportSession;
use WPCheckpoint\Restore\Queries;
use WPCheckpoint\Restore\SiteTables;
use WPCheckpoint\Restore\SwapPlan;
use WPCheckpoint\Restore\SwapRules;
use WPCheckpoint\Restore\TableMoves;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Standalone\Failure;

defined( 'ABSPATH' ) || exit;

/**
 * The checks of wp wpcheckpoint job redo, read once before the confirmation code is given and again under the start
 * lock right before the job is claimed (JobActions::redo()), and the ones that can change meanwhile once more by the
 * swap step right before it changes the site (again()): every refusal says why.
 *
 * A restore, undone at its swap step (wp wpcheckpoint job undo, an undo answered after the release, or a put-back of a
 * swap that was committed) no more than Undo::WINDOW_SECONDS ago, with its work here and not reclaimed, its maintenance
 * mark recorded, its options not reset by a put-back; managed by this installation; no other job holds the site and
 * no other restore is under way; no job in the jobs table committed a swap after the undo, whichever installation
 * manages it, and none holds the site swapped without a commit time unless it ended before the undo; its swap plan
 * whole and written for this WordPress directory and table prefix (Undo::plan()); every table and directory of the
 * plan where the undo left it, by positive evidence: what cannot be read refuses; no table of this site's made since
 * that the plan would not move away (TableMoves, the rule the plan was made by: the redo would put the restored copy
 * next to it); and, when the plugins are in the plan, this plugin's copy among them of the version that runs (the redo
 * would put another version in place).
 *
 * What only holds for a copy just imported is not checked again: the ledger's counts, the staged files against the
 * backup's index, the tables of other installations judged anew. That copy was the live site, and was written to.
 *
 * The confirmation code binds the job, its storage token, its plan (the attempt and every entry) and the time of the
 * undo, under a name of its own (an undo's code is never a redo's). What would be dropped is counted as for an undo
 * (Undo::lost()), since the undo.
 */
final class Redo {

	/**
	 * The most tables made since the undo a refusal names (the rest are counted).
	 */
	const MAX_NAMED = 10;

	/**
	 * Jobs.
	 *
	 * @var JobRepository
	 */
	private $repository;

	/**
	 * The undo's checks (the plan is read the same way).
	 *
	 * @var Undo
	 */
	private $undo;

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
	 * The job types that are restores.
	 *
	 * @var string[]
	 */
	private $restores;

	/**
	 * This plugin as it runs: "name" (its directory's name under the plugins directory), "file" (its main file's name)
	 * and "version".
	 *
	 * @var array{name: string, file: string, version: string}
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param JobRepository        $repository Jobs.
	 * @param array<string, mixed> $parts      Tests: as Undo's ("connect", "now", "abspath", "restore_types"), and
	 *                                         "plugin" (array: "name", "file", "version" of the running plugin).
	 */
	public function __construct( JobRepository $repository, array $parts = array() ) {
		$connect          = $parts['connect'] ?? null;
		$this->repository = $repository;
		$this->undo       = new Undo( $repository, $parts );
		$this->connect    = is_callable( $connect ) ? $connect : static function (): Queries {
			try {
				$credentials = Credentials::from_wordpress();
			} catch ( Failure $e ) {
				throw new \RuntimeException( 'The database settings of this site cannot be read: ' . $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message; callers say their own.
			}
			return ImportSession::open( $credentials );
		};
		$this->clock      = isset( $parts['now'] ) && is_callable( $parts['now'] ) ? $parts['now'] : 'time';
		$this->restores   = isset( $parts['restore_types'] ) ? array_map( 'strval', (array) $parts['restore_types'] ) : array( RestoreJob::ID );
		$plugin           = isset( $parts['plugin'] ) && is_array( $parts['plugin'] ) ? $parts['plugin'] : array();
		$this->plugin     = array(
			'name'    => (string) ( $plugin['name'] ?? basename( WPCHECKPOINT_DIR ) ),
			'file'    => (string) ( $plugin['file'] ?? basename( WPCHECKPOINT_FILE ) ),
			'version' => (string) ( $plugin['version'] ?? WPCHECKPOINT_VERSION ),
		);
	}

	/**
	 * Whether the job may be swapped back in, and if so its code and what would be dropped.
	 *
	 * @param int $id Job id.
	 * @return array{ok: bool, why: string, job: Job|null, attempt: int, code: string, since: int, lost: array<string, int|null>}
	 */
	public function assess( int $id ): array {
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
		$why        = $this->not_redoable( $job );
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
			$since = $job->finished_at;
			$seen  = $this->again( $job, $since, $db );
			if ( '' !== $seen['why'] ) {
				$out['why'] = $seen['why'];
				return $out;
			}
			$out['ok']      = true;
			$out['attempt'] = $seen['attempt'];
			$out['since']   = $since;
			$out['code']    = self::code( $job, $seen['attempt'], $seen['entries'], $since );
			$out['lost']    = Undo::lost( $seen['entries'], $since, $db );
			return $out;
		} finally {
			if ( $db instanceof ImportSession ) {
				$db->close();
			}
		}
	}

	/**
	 * The checks that can change while the job waits (④–⑨ of the design): what is in the way, the plan, where its
	 * entries are, the tables made since the undo, this plugin's copy. Read by assess() and, right before it changes
	 * the site, by the swap step (the job then running: it is not in its own way).
	 *
	 * @param Job     $job   Job.
	 * @param int     $since The time of the undo.
	 * @param Queries $db    Connection.
	 * @return array{why: string, attempt: int, entries: array<int, array{seq: int, kind: string, live: string, stage: string, old: string, had_live: bool}>}
	 */
	public function again( Job $job, int $since, Queries $db ): array {
		$out = array(
			'why'     => '',
			'attempt' => 0,
			'entries' => array(),
		);
		$why = $this->not_alone( $job, $since );
		if ( '' !== $why ) {
			$out['why'] = $why;
			return $out;
		}
		$plan = $this->undo->plan( $job, $db );
		if ( is_string( $plan ) ) {
			$out['why'] = $plan;
			return $out;
		}
		foreach ( array( $this->not_in_place( $plan['entries'], $db ), $this->made_since( $job, $plan['entries'], $db ), $this->other_version( $job, $plan['entries'] ) ) as $why ) {
			if ( '' !== $why ) {
				$out['why'] = $why;
				return $out;
			}
		}
		$out['attempt'] = $plan['attempt'];
		$out['entries'] = $plan['entries'];
		return $out;
	}

	/**
	 * The confirmation code: the job, its storage token, the plan's attempt and entries, and the time of the undo.
	 *
	 * @param Job                                                                                                 $job     Job.
	 * @param int                                                                                                 $attempt The plan's attempt.
	 * @param array<int, array{seq: int, kind: string, live: string, stage: string, old: string, had_live: bool}> $entries The plan's entries.
	 * @param int                                                                                                 $since   The time of the undo.
	 * @return string
	 */
	public static function code( Job $job, int $attempt, array $entries, int $since ): string {
		$plan = array();
		foreach ( $entries as $entry ) {
			$plan[] = implode( "\0", array( (string) $entry['seq'], $entry['kind'], $entry['live'], $entry['stage'], $entry['old'], $entry['had_live'] ? '1' : '0' ) );
		}
		return substr( hash( 'sha256', implode( "\n", array( 'wpcheckpoint-redo', (string) $job->id, $job->storage_token, (string) $attempt, (string) $since, implode( "\n", $plan ) ) ) ), 0, 16 );
	}

	/**
	 * Whether the time a redo can be made in is still running at $now, for an undo at $since.
	 *
	 * @param int $since The time of the undo.
	 * @param int $now   Unix time.
	 * @return bool
	 */
	public static function within_window( int $since, int $now ): bool {
		return $since > 0 && $now <= $since + Undo::WINDOW_SECONDS;
	}

	/**
	 * Why a job cannot be swapped back in by its own state ('' when it can, so far).
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	private function not_redoable( Job $job ): string {
		if ( ! in_array( $job->type, $this->restores, true ) ) {
			return __( 'Only a restore that was undone can be swapped back in.', 'wp-checkpoint' );
		}
		if ( Job::UNDONE !== $job->status || SwapStep::ID !== $job->step || Job::SITE_UNTOUCHED !== $job->site_state ) {
			/* translators: 1: job id, 2: status */
			return sprintf( __( 'Restore job %1$d is %2$s: only a restore that was undone can be swapped back in.', 'wp-checkpoint' ), $job->id, $job->status );
		}
		if ( '' === $job->site_mark ) {
			/* translators: %d: job id */
			return sprintf( __( 'Restore job %d has no maintenance mark recorded, so the site could not be held while it is swapped back in: it is not done.', 'wp-checkpoint' ), $job->id );
		}
		if ( ! $this->repository->manages( $job ) ) {
			/* translators: %d: job id */
			return sprintf( __( 'Restore job %d is managed by another installation of WP Checkpoint: it is swapped back in only from there.', 'wp-checkpoint' ), $job->id );
		}
		if ( ! self::within_window( $job->finished_at, $this->now() ) || $job->work_expired_at > 0 ) {
			/* translators: 1: job id, 2: days, 3: date and time of the undo (UTC) */
			return sprintf( __( 'Restore job %1$d can no longer be swapped back in: that can be done for %2$d days after the undo (%3$s UTC), and its restored copy is then removed.', 'wp-checkpoint' ), $job->id, (int) ( Undo::WINDOW_SECONDS / 86400 ), gmdate( 'Y-m-d H:i', $job->finished_at ) );
		}
		if ( '' === $this->repository->files_base( $job ) ) {
			/* translators: %d: job id */
			return sprintf( __( 'The work files of restore job %d are in a storage directory other than this one, so its plan cannot be read from here; nothing was changed.', 'wp-checkpoint' ), $job->id );
		}
		if ( isset( $job->options[ JobRepository::OPTIONS_RESET ] ) ) {
			/* translators: %d: job id */
			return sprintf( __( 'The options of restore job %d were reset when it was put back (they could not be read then), so it is not swapped back in: it would run by options that are not its own. Restore the backup again instead.', 'wp-checkpoint' ), $job->id );
		}
		return '';
	}

	/**
	 * Why the job is not the one to act on now: another job holds the site or is a restore under way, a swap committed
	 * after the undo, one whose order cannot be told ('' when none).
	 *
	 * @param Job $job   Job.
	 * @param int $since The time of the undo.
	 * @return string
	 */
	private function not_alone( Job $job, int $since ): string {
		try {
			$holding = array_values( array_diff( $this->repository->holding_site(), array( $job->id ) ) );
			$active  = $this->repository->list_jobs( array( Job::QUEUED, Job::RUNNING, Job::PAUSED ), 500 );
			$later   = $this->repository->later_commit( $job, $since );
			$unknown = null === $later ? $this->repository->unordered_swap( $job, $since ) : null;
		} catch ( \RuntimeException $e ) {
			return __( 'The jobs could not be read, so whether another job is in the way cannot be told; nothing was changed. Try again.', 'wp-checkpoint' );
		}
		if ( array() !== $holding ) {
			/* translators: %d: job id */
			return sprintf( __( 'Job %d holds the site: nothing is swapped back in until it has ended (wp wpcheckpoint job status shows where it is).', 'wp-checkpoint' ), $holding[0] );
		}
		foreach ( $active as $other ) {
			if ( $other->id !== $job->id && in_array( $other->type, $this->restores, true ) ) {
				/* translators: %d: job id */
				return sprintf( __( 'Restore job %d is under way: nothing is swapped back in until it has ended.', 'wp-checkpoint' ), $other->id );
			}
		}
		if ( null !== $later ) {
			/* translators: 1: job id, 2: the later job's id */
			return sprintf( __( 'Restore job %2$d swapped the site after restore job %1$d was undone (whichever installation of WP Checkpoint ran it): swapping %1$d back in would drop that restore and everything written since. It is not done.', 'wp-checkpoint' ), $job->id, $later );
		}
		if ( null !== $unknown ) {
			/* translators: 1: job id, 2: the other job's id */
			return sprintf( __( 'Job %2$d holds the site swapped but recorded no time of its swap (made by an earlier version of WP Checkpoint, here or by an installation sharing this database), and it did not end before restore job %1$d was undone: whether it came after cannot be told, and swapping %1$d back in could drop it. It is not done.', 'wp-checkpoint' ), $job->id, $unknown );
		}
		return '';
	}

	/**
	 * Why a table or directory of the plan is not where the undo left it ('' when all are): the restored tables under
	 * their temporary names and the site's own under theirs (a table the backup has and the site did not have: not
	 * there), nothing under the moved-aside names; the restored directories at their staged paths, the site's own in
	 * place (when it had one), nothing at the old paths. What cannot be read refuses.
	 *
	 * @param array<int, array{seq: int, kind: string, live: string, stage: string, old: string, had_live: bool}> $entries Entries.
	 * @param Queries                                                                                             $db      Connection.
	 * @return string
	 */
	private function not_in_place( array $entries, Queries $db ): string {
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
		foreach ( $tables as $entry ) {
			if ( SwapRules::table_state( $entry, $there ) !== SwapRules::BEFORE ) {
				/* translators: %s: table name */
				return sprintf( __( 'The table %s is not where the undo left it, so the restore cannot be swapped back in by its plan: nothing was changed.', 'wp-checkpoint' ), $entry['live'] );
			}
		}
		foreach ( $dirs as $entry ) {
			$l = Undo::exists( $entry['live'] );
			$s = Undo::exists( $entry['stage'] );
			$o = Undo::exists( $entry['old'] );
			if ( null === $l || null === $s || null === $o ) {
				/* translators: %s: directory */
				return sprintf( __( 'Whether %s is where the undo left it cannot be told now; nothing was changed. Try again.', 'wp-checkpoint' ), $entry['live'] );
			}
			if ( ! ( $s && ! $o && $l === $entry['had_live'] ) ) {
				/* translators: %s: directory */
				return sprintf( __( 'The directory %s is not where the undo left it, so the restore cannot be swapped back in by its plan: nothing was changed.', 'wp-checkpoint' ), $entry['live'] );
			}
		}
		return '';
	}

	/**
	 * Why tables of this site's were made since that the plan would not move away ('' when none): the rule the plan
	 * was made by (TableMoves::select(), with the live tables now, the backup's tables and the ones left out of the
	 * restore), against the tables the plan moves away (SwapPlan::MOVE). The swap back in would leave such a table next
	 * to the restored copy, where the swap would have moved it away. What cannot be read refuses.
	 *
	 * @param Job                                                                                                 $job     Job.
	 * @param array<int, array{seq: int, kind: string, live: string, stage: string, old: string, had_live: bool}> $entries Entries.
	 * @param Queries                                                                                             $db      Connection.
	 * @return string
	 */
	private function made_since( Job $job, array $entries, Queries $db ): string {
		$cannot = __( 'Which tables this site has now cannot be read, so whether any was made since the undo cannot be told; nothing was changed. Try again.', 'wp-checkpoint' );
		try {
			$run = RestorePreflightStep::load_plan( Residue::work_dir( $this->repository->files_base( $job ), $job->id ) );
		} catch ( \RuntimeException $e ) {
			return __( 'The restore\'s table plan cannot be read from its work directory; nothing was changed.', 'wp-checkpoint' );
		}
		$live = array();
		try {
			$after = '';
			do {
				$page = $db->rows( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND BINARY TABLE_NAME > BINARY ? ORDER BY BINARY TABLE_NAME LIMIT ' . SwapStep::PAGE, array( $after ) );
				$full = count( $page ) === SwapStep::PAGE;
				foreach ( $page as $row ) {
					$live[] = (string) $row[0];
					$after  = (string) $row[0];
				}
			} while ( $full );
		} catch ( \RuntimeException $e ) {
			return $cannot;
		}
		$plan   = $run['plan'];
		$finals = array();
		$moved  = array();
		foreach ( $entries as $entry ) {
			if ( SwapPlan::TABLE_OF === $entry['kind'] ) {
				$finals[] = $entry['live'];
			} elseif ( SwapPlan::MOVE === $entry['kind'] ) {
				$moved[ $entry['live'] ] = true;
			}
		}
		$kept = array();
		foreach ( array_keys( $plan->skipped() ) as $name ) {
			$kept[] = $plan->final_name( (string) $name );
		}
		$new = array();
		foreach ( TableMoves::select( $plan->site_prefix(), is_multisite(), $live, $finals, $kept, SiteTables::core() )['move'] as $name ) {
			if ( ! isset( $moved[ $name ] ) ) {
				$new[] = $name;
			}
		}
		if ( array() === $new ) {
			return '';
		}
		$named = implode( ', ', array_slice( $new, 0, self::MAX_NAMED ) );
		if ( count( $new ) > self::MAX_NAMED ) {
			/* translators: %d: how many more */
			$named .= ' ' . sprintf( __( 'and %d more', 'wp-checkpoint' ), count( $new ) - self::MAX_NAMED );
		}
		/* translators: %s: table names */
		return sprintf( __( 'This site has tables that its restore would have moved away and that are not in its plan (made since, by a plugin for example): %s. Swapping the restored copy back in would leave them next to it, so it is not done; nothing was changed.', 'wp-checkpoint' ), $named );
	}

	/**
	 * Why this plugin's copy among the restored plugins is not of the version that runs ('' when it is, or when the
	 * plugins are not in the plan): the swap back in would put another version of it in place. What cannot be read
	 * refuses.
	 *
	 * @param Job                                                                                                 $job     Job.
	 * @param array<int, array{seq: int, kind: string, live: string, stage: string, old: string, had_live: bool}> $entries Entries.
	 * @return string
	 */
	private function other_version( Job $job, array $entries ): string {
		try {
			$work    = Residue::work_dir( $this->repository->files_base( $job ), $job->id );
			$staging = RestoreFilesPreflightStep::staging( $work );
			if ( ! in_array( 'plugins', (array) $staging['staged'], true ) ) {
				return '';
			}
			$stage = RestoreFilesPreflightStep::layout_of( $staging, $job )->stage_dir( 'plugins' );
		} catch ( \RuntimeException $e ) {
			return __( 'The restore\'s staging layout cannot be read from its work directory; nothing was changed.', 'wp-checkpoint' );
		}
		$planned = false;
		foreach ( $entries as $entry ) {
			$planned = $planned || ( SwapPlan::DIR === $entry['kind'] && $entry['stage'] === $stage );
		}
		if ( ! $planned ) {
			return '';
		}
		$main = $stage . '/' . $this->plugin['name'] . '/' . $this->plugin['file'];
		clearstatcache( true, $main );
		$header  = is_file( $main ) && is_readable( $main ) && function_exists( 'get_file_data' ) ? get_file_data( $main, array( 'Version' => 'Version' ) ) : null;
		$version = is_array( $header ) ? (string) ( $header['Version'] ?? '' ) : '';
		if ( '' === $version ) {
			return __( 'The version of WP Checkpoint among the restored plugins cannot be read, so whether swapping them back in would put another version of it in place cannot be told; nothing was changed.', 'wp-checkpoint' );
		}
		if ( $version !== $this->plugin['version'] ) {
			/* translators: 1: version among the restored plugins, 2: running version */
			return sprintf( __( 'The restored plugins hold WP Checkpoint %1$s, and %2$s runs now: swapping them back in would put the other version in place, so it is not done; nothing was changed. Restore the backup again instead.', 'wp-checkpoint' ), $version, $this->plugin['version'] );
		}
		return '';
	}

	/**
	 * Now.
	 *
	 * @return int
	 */
	public function now(): int {
		return (int) call_user_func( $this->clock );
	}
}
