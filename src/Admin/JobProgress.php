<?php
/**
 * The progress block for a job on the admin page.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Admin;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobPresenter;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered progress bar, step, message, log tail and cancel / retry
 * buttons; assets/admin/jobs.js keeps it moving. Everything printed comes
 * from JobPresenter (scrubbed, redacted, masked) and is escaped here.
 */
final class JobProgress {

	/**
	 * Presenter.
	 *
	 * @var JobPresenter
	 */
	private $presenter;

	/**
	 * Constructor.
	 *
	 * @param JobPresenter $presenter Presenter.
	 */
	public function __construct( JobPresenter $presenter ) {
		$this->presenter = $presenter;
	}

	/**
	 * Print the block.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	public function render( Job $job ): void {
		$data   = $this->presenter->present( $job );
		$active = in_array( $job->status, array( Job::QUEUED, Job::RUNNING, Job::PAUSED ), true );
		?>
		<div class="wpcheckpoint-job" data-wpcheckpoint-job="<?php echo esc_attr( (string) $job->id ); ?>" data-status="<?php echo esc_attr( $job->status ); ?>">
			<p class="wpcheckpoint-job-title">
				<strong data-field="type_label"><?php echo esc_html( $data['type_label'] ); ?></strong>
				<span class="wpcheckpoint-job-status" data-field="status_label"><?php echo esc_html( self::status_label( $job->status ) ); ?></span>
			</p>
			<progress class="wpcheckpoint-job-bar" max="100" value="<?php echo esc_attr( (string) $data['progress'] ); ?>" data-field="progress"><?php echo esc_html( $data['progress'] . '%' ); ?></progress>
			<p class="wpcheckpoint-job-line">
				<span data-field="progress_text"><?php echo esc_html( $data['progress'] . '%' ); ?></span>
				<span data-field="step_label"><?php echo esc_html( $data['step_label'] ); ?></span>
				<span data-field="message"><?php echo esc_html( $data['message'] ); ?></span>
			</p>
			<p class="wpcheckpoint-job-error" data-field="last_error"<?php echo '' === $data['last_error'] ? ' hidden' : ''; ?>><?php echo esc_html( $data['last_error'] ); ?></p>
			<p class="wpcheckpoint-job-notice" data-field="notice" hidden></p>
			<pre class="wpcheckpoint-job-log" data-field="log_tail"><?php echo esc_html( $data['log_tail'] ); ?></pre>
			<p class="wpcheckpoint-job-actions">
				<button type="button" class="button" data-action="cancel"<?php echo $active ? '' : ' hidden'; ?>><?php esc_html_e( 'Cancel', 'wp-checkpoint' ); ?></button>
				<button type="button" class="button" data-action="retry"<?php echo Job::FAILED === $job->status ? '' : ' hidden'; ?>><?php esc_html_e( 'Retry', 'wp-checkpoint' ); ?></button>
			</p>
		</div>
		<?php
	}

	/**
	 * Translated status label.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( string $status ): string {
		switch ( $status ) {
			case Job::QUEUED:
				return __( 'Queued', 'wp-checkpoint' );
			case Job::RUNNING:
				return __( 'Running', 'wp-checkpoint' );
			case Job::PAUSED:
				return __( 'Paused', 'wp-checkpoint' );
			case Job::COMPLETED:
				return __( 'Completed', 'wp-checkpoint' );
			case Job::FAILED:
				return __( 'Failed', 'wp-checkpoint' );
			case Job::CANCELLED:
				return __( 'Cancelled', 'wp-checkpoint' );
		}
		return $status;
	}

	/**
	 * Labels for the script.
	 *
	 * @return array<string, string>
	 */
	public static function script_labels(): array {
		return array(
			'queued'          => self::status_label( Job::QUEUED ),
			'running'         => self::status_label( Job::RUNNING ),
			'paused'          => self::status_label( Job::PAUSED ),
			'completed'       => self::status_label( Job::COMPLETED ),
			'failed'          => self::status_label( Job::FAILED ),
			'cancelled'       => self::status_label( Job::CANCELLED ),
			'session_expired' => __( 'Your session expired. Reload the page to continue.', 'wp-checkpoint' ),
			'request_failed'  => __( 'The request failed; retrying.', 'wp-checkpoint' ),
			'busy'            => __( 'Another process is working on this job.', 'wp-checkpoint' ),
			'waiting'         => __( 'Waiting before the next attempt.', 'wp-checkpoint' ),
		);
	}
}
