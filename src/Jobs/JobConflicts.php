<?php
/**
 * Which jobs may run at the same time.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * A restore rewrites the database and the files: while one is queued,
 * running or paused, nothing else starts (an export would capture a half
 * restored site that looks fine). While an export runs, a restore does not
 * start (it would rewrite what the export is reading); verifying another
 * backup is read-only and allowed. A backup that is being verified is not
 * restored, deleted or verified a second time. One export at a time: the
 * free-space checks of a backup assume they have the disk to themselves.
 * Pure: the caller passes the active jobs.
 */
final class JobConflicts {

	const EXPORT   = 'export';
	const VERIFY   = 'verify';
	const RESTORE  = 'restore';
	const ESTIMATE = 'estimate';

	/**
	 * Types that give way: an export or a restore that starts cancels them
	 * (JobActions::start()), and they never hold anything up.
	 */
	const YIELDING = array( self::ESTIMATE );

	/**
	 * The backup a job reads, by base name ('' for none).
	 *
	 * @param string               $type    Job type.
	 * @param array<string, mixed> $options Job options.
	 * @return string
	 */
	public static function backup_of( string $type, array $options ): string {
		return in_array( $type, array( self::VERIFY, self::RESTORE ), true ) && isset( $options['base'] ) && is_string( $options['base'] ) ? $options['base'] : '';
	}

	/**
	 * Why a new job must not start next to the active ones, or '' when it may.
	 *
	 * @param string               $type    New job's type.
	 * @param array<string, mixed> $options New job's options.
	 * @param Job[]                $active  Queued, running and paused jobs (the new one excluded).
	 * @return string
	 */
	public static function conflict( string $type, array $options, array $active ): string {
		$backup = self::backup_of( $type, $options );
		foreach ( $active as $job ) {
			if ( self::ESTIMATE === $type && in_array( $job->type, array( self::EXPORT, self::RESTORE, self::ESTIMATE ), true ) ) {
				// An estimate only helps someone decide; there is nothing to decide while one of these runs.
				return sprintf( 'Job %d is running; no estimate now.', $job->id );
			}
			if ( in_array( $job->type, self::YIELDING, true ) ) {
				continue; // Cancelled by the start of an export or a restore, harmless next to anything else.
			}
			if ( self::RESTORE === $job->type ) {
				return sprintf( 'A restore is in progress (job %d); nothing else can start until it has ended.', $job->id );
			}
			if ( self::RESTORE === $type && self::EXPORT === $job->type ) {
				return sprintf( 'A backup is being made (job %d); restore once it has ended.', $job->id );
			}
			if ( self::EXPORT === $type && self::EXPORT === $job->type ) {
				return sprintf( 'A backup is already being made (job %d).', $job->id );
			}
			if ( '' !== $backup && self::VERIFY === $job->type && self::backup_of( $job->type, $job->options ) === $backup ) {
				return sprintf( 'This backup is being verified (job %d); wait until the check has ended.', $job->id );
			}
		}
		return '';
	}

	/**
	 * Why a backup's files cannot be downloaded now (it is being restored), or '' when they can.
	 *
	 * @param string $base   Backup base name.
	 * @param Job[]  $active Queued, running and paused jobs.
	 * @return string
	 */
	public static function restoring( string $base, array $active ): string {
		foreach ( $active as $job ) {
			if ( self::RESTORE === $job->type && self::backup_of( $job->type, $job->options ) === $base ) {
				return sprintf( 'This backup is being restored (job %d).', $job->id );
			}
		}
		return '';
	}

	/**
	 * Why a backup cannot be deleted (or downloaded, during a restore) now, or '' when it can.
	 *
	 * @param string $base   Backup base name.
	 * @param Job[]  $active Queued, running and paused jobs.
	 * @return string
	 */
	public static function backup_in_use( string $base, array $active ): string {
		foreach ( $active as $job ) {
			if ( self::backup_of( $job->type, $job->options ) !== $base ) {
				continue;
			}
			return self::RESTORE === $job->type
				? sprintf( 'This backup is being restored (job %d).', $job->id )
				: sprintf( 'This backup is being verified (job %d).', $job->id );
		}
		return '';
	}
}
