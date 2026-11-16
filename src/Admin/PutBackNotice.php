<?php
/**
 * The notice that a restore ended with the site put back as it was.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Admin;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\RestoreJob;
use WPCheckpoint\Jobs\SwapStep;

defined( 'ABSPATH' ) || exit;

/**
 * What the admin says when the latest restore this installation manages failed with its swap put back: that the
 * site is as it was before the restore, why, and the command that tries again.
 *
 * It reads fixed codes only: the row's type and status, and the phase and the reason code of its cursor
 * (SwapStep::REASONS). Nothing of the row's error, its log or any other text a run wrote is read or shown; the
 * sentences are this class's own. Who put the site back (a WP-CLI run or a visitor's request) is not told apart: the
 * row does not record it.
 */
final class PutBackNotice {

	/**
	 * The notice's id, under which a user's dismissal is kept (with the job's id as its value).
	 */
	const ID = 'put_back';

	/**
	 * Every reason a swap is put back for (SwapStep::REASONS), and whether the notice tells of it: not of what an
	 * administrator asked for (wp wpcheckpoint job undo, job put-back).
	 */
	const TELLS = array(
		SwapStep::INTERRUPTED     => true,
		SwapStep::INCOMPLETE      => true,
		SwapStep::STOPPED         => true,
		SwapStep::BUSY            => true,
		SwapStep::ABANDONED       => true,
		SwapStep::REVERSED        => true,
		SwapStep::UNDONE          => true,
		SwapStep::ASKED           => false,
		SwapStep::PUT_BACK_REASON => false,
	);

	/**
	 * The reason code the notice tells of for this job, or '' when the job is not one it tells of: a restore that
	 * failed at its swap, its cursor at the end of a put-back, with one of the reasons of TELLS. Not one put back
	 * after the restored site was let out to visitors ("out"), nor an undone restore being swapped in again
	 * ("undone_at"): those are undos, their sites served the restored copy meanwhile, and a retry ends the undo
	 * rather than trying the restore again.
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	public static function reason( Job $job ): string {
		if ( RestoreJob::ID !== $job->type || Job::FAILED !== $job->status || SwapStep::ID !== $job->step ) {
			return '';
		}
		if ( ! empty( $job->cursor['out'] ) || ! empty( $job->cursor['undone_at'] ) ) {
			return '';
		}
		$phase  = isset( $job->cursor['phase'] ) && is_string( $job->cursor['phase'] ) ? $job->cursor['phase'] : '';
		$reason = isset( $job->cursor['reason'] ) && is_string( $job->cursor['reason'] ) ? $job->cursor['reason'] : '';
		if ( 'reverted' !== $phase || ! isset( self::TELLS[ $reason ] ) || true !== self::TELLS[ $reason ] ) {
			return '';
		}
		return $reason;
	}

	/**
	 * The sentence for a reason code, or '' for a code the notice does not tell of.
	 *
	 * @param string $reason Reason code.
	 * @return string
	 */
	public static function sentence( string $reason ): string {
		switch ( $reason ) {
			case SwapStep::INTERRUPTED:
				return __( 'A restore was interrupted while it was swapping the restored site in. The site was put back and is as it was before the restore.', 'wp-checkpoint' );
			case SwapStep::INCOMPLETE:
				return __( 'A restore could not put every table of the restored site in its place. The site was put back and is as it was before the restore.', 'wp-checkpoint' );
			case SwapStep::STOPPED:
				return __( 'A restore stopped while it was swapping the restored site in (its log says why). The site was put back and is as it was before the restore.', 'wp-checkpoint' );
			case SwapStep::BUSY:
				return __( 'A restore could not swap the restored site in: the database stayed busy with other work through every try. The site was put back and is as it was before the restore.', 'wp-checkpoint' );
			case SwapStep::ABANDONED:
				return __( 'A restore was not continued in WP-CLI while it held the site, and a visitor\'s request put the site back. The site is as it was before the restore.', 'wp-checkpoint' );
			case SwapStep::REVERSED:
				return __( 'A restore swapped the restored site in, and a check found the restored site broken. The site was put back and is as it was before the restore.', 'wp-checkpoint' );
			case SwapStep::UNDONE:
				return __( 'A restore swapped the restored site in, the restored site could not be checked, and the swap was undone as chosen. The site was put back and is as it was before the restore.', 'wp-checkpoint' );
		}
		return '';
	}

	/**
	 * The notice for the job, or an empty array when there is none for it.
	 *
	 * @param Job $job Job.
	 * @return array<string, mixed>
	 */
	public static function notice( Job $job ): array {
		$sentence = self::sentence( self::reason( $job ) );
		if ( '' === $sentence ) {
			return array();
		}
		$retry = $job->retry_useful(); // As the plugin's page offers Retry: the work files there, the failure not final.
		return array(
			'type'        => 'warning',
			'message'     => $sentence . ' ' . ( $retry
				? __( 'To try the restore again, run this in WP-CLI:', 'wp-checkpoint' )
				: __( 'It cannot be tried again (its work files have been removed since, or its failure is final): start a new restore.', 'wp-checkpoint' ) ),
			'extra'       => $retry ? 'wp wpcheckpoint job retry ' . $job->id : '',
			'dismissible' => true,
			'seen'        => (string) $job->id,
		);
	}
}
