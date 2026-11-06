<?php
/**
 * What the Runner says, translated or not.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * The Runner's own texts. In WordPress each is translated (the default); a run made without WordPress, where the
 * translation functions are not loaded, asks for them untranslated, in English. Each text is written twice in its
 * method, the translatable literal (which the translation tools read from the source) and the English one returned
 * untranslated; RunnerTextsTest holds the two equal.
 */
final class RunnerTexts {

	/**
	 * Whether to translate.
	 *
	 * @var bool
	 */
	private $translate;

	/**
	 * Constructor.
	 *
	 * @param bool $translate Whether to translate (WordPress is loaded).
	 */
	public function __construct( bool $translate = true ) {
		$this->translate = $translate;
	}

	/**
	 * A job that waits for an answer.
	 *
	 * @return string
	 */
	public function waiting(): string {
		return $this->translate ? __( 'The job is waiting for your decision.', 'wp-checkpoint' ) : 'The job is waiting for your decision.';
	}

	/**
	 * Another run holds the job.
	 *
	 * @return string
	 */
	public function busy(): string {
		return $this->translate ? __( 'Another process is working on this job.', 'wp-checkpoint' ) : 'Another process is working on this job.';
	}

	/**
	 * The lock was lost mid-tick.
	 *
	 * @return string
	 */
	public function lost(): string {
		return $this->translate ? __( 'The job was cancelled or taken over by another process.', 'wp-checkpoint' ) : 'The job was cancelled or taken over by another process.';
	}

	/**
	 * The database refused to write the progress.
	 *
	 * @return string
	 */
	public function refused(): string {
		return $this->translate ? __( 'The database refused to save the job\'s progress, so the job stopped where it was saved last. It goes on from there when it runs again, once the lock of this run has lapsed.', 'wp-checkpoint' ) : 'The database refused to save the job\'s progress, so the job stopped where it was saved last. It goes on from there when it runs again, once the lock of this run has lapsed.';
	}

	/**
	 * A job taken over from another installation would retry from files that are not here.
	 *
	 * @return string
	 */
	public function files_elsewhere(): string {
		return $this->translate ? __( 'This restore was taken over from another installation of WP Checkpoint, and its files are in the storage directory it was started with, not here: the site stays as it is. Start the restore again from here.', 'wp-checkpoint' ) : 'This restore was taken over from another installation of WP Checkpoint, and its files are in the storage directory it was started with, not here: the site stays as it is. Start the restore again from here.';
	}

	/**
	 * A job that stands at a step only WP-CLI runs.
	 *
	 * @param int $id Job id.
	 * @return string
	 */
	public function cli_only( int $id ): string {
		/* translators: %d: job id */
		return sprintf( $this->translate ? __( 'This part of the job runs in WP-CLI only. Continue it with: wp wpcheckpoint job run %d', 'wp-checkpoint' ) : 'This part of the job runs in WP-CLI only. Continue it with: wp wpcheckpoint job run %d', $id );
	}

	/**
	 * A job whose row cannot be read.
	 *
	 * @param string $what What cannot be read of it.
	 * @return string
	 */
	public function unreadable( string $what ): string {
		/* translators: %s: what cannot be read of the job's row */
		return sprintf( $this->translate ? __( 'The job stopped: %s. Nothing was run, and its row is left as it is.', 'wp-checkpoint' ) : 'The job stopped: %s. Nothing was run, and its row is left as it is.', $what );
	}

	/**
	 * Why a job whose row cannot be read, and that holds the site changed, is not failed.
	 *
	 * @return string
	 */
	public function held_unreadable(): string {
		return $this->translate ? __( 'This job holds the site changed, so it is not failed: it is not run until its row is repaired. Look at the site\'s directories and tables, and the .maintenance file in the WordPress directory, before anything else; while the job holds the site, no other job starts on it.', 'wp-checkpoint' ) : 'This job holds the site changed, so it is not failed: it is not run until its row is repaired. Look at the site\'s directories and tables, and the .maintenance file in the WordPress directory, before anything else; while the job holds the site, no other job starts on it.';
	}

	/**
	 * Why a job whose table lacks columns, and that holds the site changed, is not failed.
	 *
	 * @return string
	 */
	public function held_columns(): string {
		return $this->translate ? __( 'This job holds the site changed, so it is not failed: it goes on once the table is repaired (deactivate and activate WP Checkpoint, or update it).', 'wp-checkpoint' ) : 'This job holds the site changed, so it is not failed: it goes on once the table is repaired (deactivate and activate WP Checkpoint, or update it).';
	}
}
