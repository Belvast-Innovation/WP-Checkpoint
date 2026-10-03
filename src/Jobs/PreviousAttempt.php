<?php
/**
 * What an earlier attempt of a restore made that a new one does not use: its temporary tables and staging roots.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Restore\StagingLayout;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\DeletionRefused;
use WPCheckpoint\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * A restore that starts over at its preflight (a retry from there, RetryFrom) makes its tables and staging roots
 * again under a new random part; the earlier attempt's would stay until the job ends, a second copy of the database
 * and of the site's files. The preflight reclaims them first (RestorePreflightStep), by what the earlier attempt
 * recorded and nothing else:
 *
 * - its plan file (RestoreFiles::PLAN): the temporary name of every table it imported, and the random part of its
 *   ledger. Only names TempTables says are this job's under its storage token are taken; the names the swap moves
 *   live tables to (TempTables::old()) and what its rollback moved out of the way (TempTables::stray()) are never
 *   taken: those are the site's tables, and someone else's.
 * - its staging file (RestoreFiles::STAGING): the staging root next to each staged group (one per parent directory;
 *   they may be on different disks). Only roots whose name says they are this job's are taken; one whose stray/
 *   directory holds anything, or cannot be listed, is kept (Residue::keeps_stray()).
 *
 * The list is written whole to RestoreFiles::RECLAIM before anything is deleted, and the position in it is the
 * step's cursor: a run that dies between two deletions is followed by one that goes on from the last checkpoint,
 * and what is gone already is not an error (the tables are listed again before each unit, a root that is positively
 * gone is passed). Without a record (an attempt that died before writing its plan, or a work directory without
 * those files) nothing is touched: the reaper's rules reclaim the job's tables and staging roots when the job ends.
 * Every deletion is made right after the job's lease is confirmed.
 */
final class PreviousAttempt {

	/**
	 * DROP statements in one unit.
	 */
	const UNIT_STATEMENTS = 8;

	/**
	 * Directory entries deleted in one unit.
	 */
	const UNIT_ENTRIES = 500;

	/**
	 * Write the list of what the earlier attempt recorded, before anything is deleted.
	 *
	 * @param JobContext $context Context.
	 * @return bool Whether there is anything on it.
	 * @throws TransientFailure When the list cannot be written.
	 */
	public static function record( JobContext $context ): bool {
		$job    = $context->job();
		$work   = $context->work_path();
		$tables = array();
		$roots  = array();
		if ( ExportPlan::exists( $work, RestoreFiles::PLAN ) ) {
			try {
				$plan  = RestorePreflightStep::load_plan( $work );
				$names = array( TempTables::ledger( $job->storage_token, $job->id, (string) $plan['random'] ) );
				foreach ( $plan['plan']->tables() as $table ) {
					$names[] = (string) $table['temporary'];
				}
				foreach ( $names as $name ) {
					if ( TempTables::is_safe_name( $name ) && TempTables::job_id_of( $job->storage_token, $name ) === $job->id && 0 === strpos( $name, TempTables::job_prefix( $job->storage_token, $job->id ) ) ) {
						$tables[ $name ] = true;
					}
				}
			} catch ( \RuntimeException $e ) {
				$context->logger()->warning( 'The earlier attempt\'s plan could not be read; its tables are left to the reclaim at the end of the job', array( 'error' => $e->getMessage() ) );
			}
		}
		if ( ExportPlan::exists( $work, RestoreFiles::STAGING ) ) {
			try {
				$staging = RestoreFilesPreflightStep::staging( $work );
				$layout  = RestoreFilesPreflightStep::layout_of( $staging, $job );
				$name    = $layout->root_name();
				$owner   = StagingLayout::parse( $name );
				if ( null !== $owner && 'stage' === $owner['kind'] && $job->id === $owner['job_id'] ) {
					foreach ( (array) $staging['staged'] as $group ) {
						$roots[ $layout->root( (string) $group ) ] = $layout->parent( (string) $group );
					}
				}
			} catch ( \RuntimeException $e ) {
				$context->logger()->warning( 'The earlier attempt\'s staging layout could not be read; its staging roots are left to the reclaim at the end of the job', array( 'error' => $e->getMessage() ) );
			}
		}
		$list = array(
			'tables' => array_map( 'strval', array_keys( $tables ) ),
			'roots'  => array(),
		);
		foreach ( $roots as $root => $parent ) {
			$list['roots'][] = array(
				'path'   => (string) $root,
				'parent' => (string) $parent,
			);
		}
		try {
			ExportPlan::write( $work, RestoreFiles::RECLAIM, $list );
		} catch ( \RuntimeException $e ) {
			throw new TransientFailure( 'The list of what the earlier attempt of the restore left could not be written: ' . $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
		}
		return array() !== $list['tables'] || array() !== $list['roots'];
	}

	/**
	 * The position at the start of the list.
	 *
	 * @return array{part: string, r: int, left: string[], units: int}
	 */
	public static function start(): array {
		return array(
			'part'  => 'tables',
			'r'     => 0,
			'left'  => array(),
			'units' => 0,
		);
	}

	/**
	 * One unit: some of the tables, or part of one staging root.
	 *
	 * @param JobContext                                              $context  Context.
	 * @param array{part: string, r: int, left: string[], units: int} $position Where the list stands (updated; units
	 *                                                                         counts the units done, so a unit that
	 *                                                                         deleted something is progress even
	 *                                                                         where the position in the list stays).
	 * @param callable                                                $confirm  function(): void, right before each deletion.
	 * @return bool Whether the whole list is done.
	 * @throws TransientFailure When the list cannot be read or the tables cannot be listed.
	 */
	public static function unit( JobContext $context, array &$position, callable $confirm ): bool {
		$job = $context->job();
		++$position['units'];
		try {
			$list = ExportPlan::read( $context->work_path(), RestoreFiles::RECLAIM );
		} catch ( \RuntimeException $e ) {
			throw new TransientFailure( 'The list of what the earlier attempt of the restore left could not be read: ' . $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
		}
		if ( 'tables' === $position['part'] ) {
			$there = self::there( $job );
			$drop  = array();
			foreach ( (array) ( $list['tables'] ?? array() ) as $name ) {
				$name = (string) $name;
				if ( isset( $there[ $name ] ) && ! in_array( $name, $position['left'], true ) ) {
					$drop[] = $name; // On the list and still there: what is gone already is passed.
				}
			}
			if ( array() === $drop ) {
				$position['part'] = 'roots';
				return array() === (array) ( $list['roots'] ?? array() );
			}
			$result = TempTableDropper::drop( $drop, TempTables::owner_prefix( $job->storage_token ), self::UNIT_STATEMENTS, null, $confirm );
			$left   = array_merge( $result['failed'], array_keys( $result['kept'] ) );
			if ( array() !== $left ) {
				// Given up on in this pass (a foreign key of another table, or a DROP the server refused): the reclaim at the
				// end of the job tries them again.
				$context->logger()->warning( 'Tables the earlier attempt of the restore left could not be dropped now; they are reclaimed when the job ends', array( 'tables' => $left ) );
				$position['left'] = array_values( array_unique( array_merge( $position['left'], array_map( 'strval', $left ) ) ) );
			}
			return false;
		}
		$roots = array_values( (array) ( $list['roots'] ?? array() ) );
		if ( $position['r'] >= count( $roots ) ) {
			return true;
		}
		$root   = (string) ( $roots[ $position['r'] ]['path'] ?? '' );
		$parent = (string) ( $roots[ $position['r'] ]['parent'] ?? '' );
		clearstatcache( true, $root );
		if ( '' === $root || '' === $parent || false === @lstat( $root ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- not there: told apart below.
			if ( '' !== $root && ! Paths::positively_gone( $root ) ) {
				$context->logger()->warning( 'Whether a staging root of the earlier attempt of the restore is there cannot be told; it is left to the reclaim at the end of the job', array( 'root' => $root ) );
			}
			++$position['r'];
			return $position['r'] >= count( $roots );
		}
		if ( Residue::keeps_stray( $root ) ) {
			$context->logger()->warning( 'A staging root of the earlier attempt of the restore holds what a rollback moved out of the way; it is kept', array( 'root' => $root ) );
			++$position['r'];
			return $position['r'] >= count( $roots );
		}
		try {
			$result = Deleter::delete_tree( $parent, $root, self::UNIT_ENTRIES, $confirm );
		} catch ( DeletionRefused $e ) {
			$context->logger()->warning( 'A staging root of the earlier attempt of the restore may not be deleted; it is left as it is', array( 'root' => $root ) );
			++$position['r'];
			return $position['r'] >= count( $roots );
		}
		if ( array() !== $result['failed'] && 0 === $result['deleted'] ) {
			// Nothing could be deleted in this unit: the reclaim at the end of the job tries again.
			$context->logger()->warning( 'A staging root of the earlier attempt of the restore could not be deleted now; it is reclaimed when the job ends', array( 'root' => $root ) );
			++$position['r'];
			return $position['r'] >= count( $roots );
		}
		if ( ! $result['remaining'] && array() === $result['failed'] ) {
			++$position['r'];
		}
		return $position['r'] >= count( $roots );
	}

	/**
	 * This job's temporary tables as the server lists them (only to tell which names on the list are still there).
	 *
	 * @param Job $job Job.
	 * @return array<string, true>
	 * @throws TransientFailure When they cannot be listed.
	 */
	private static function there( Job $job ): array {
		global $wpdb;
		$prefix = TempTables::job_prefix( $job->storage_token, $job->id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table listing.
		$names = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
		if ( '' !== self::db_error() ) {
			throw new TransientFailure( 'The tables of the database could not be listed.' );
		}
		return array_fill_keys( array_map( 'strval', (array) $names ), true );
	}

	/**
	 * The last database error (read through a function: a property read right after a query is not known to change).
	 *
	 * @return string
	 */
	private static function db_error(): string {
		global $wpdb;
		return (string) $wpdb->last_error;
	}
}
