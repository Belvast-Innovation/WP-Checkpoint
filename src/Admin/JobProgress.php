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
	 * @param Job                                                                  $job     Job.
	 * @param array{base: string, confirmed: bool, text: string, url: string}|null $outcome What a completed export left.
	 * @return void
	 */
	public function render( Job $job, $outcome = null ): void {
		$data   = $this->presenter->present( $job );
		$active = in_array( $job->status, array( Job::QUEUED, Job::RUNNING, Job::PAUSED ), true );
		?>
		<div class="wpcheckpoint-job" data-wpcheckpoint-job="<?php echo esc_attr( (string) $job->id ); ?>" data-type="<?php echo esc_attr( $job->type ); ?>" data-status="<?php echo esc_attr( $job->status ); ?>">
			<p class="wpcheckpoint-job-title">
				<strong data-field="type_label"><?php echo esc_html( $data['type_label'] ); ?></strong>
				<span class="wpcheckpoint-job-status" data-field="status_label"><?php echo esc_html( self::status_label( $job->status ) ); ?></span>
			</p>
			<progress class="wpcheckpoint-job-bar" max="100" value="<?php echo esc_attr( (string) $data['progress'] ); ?>" data-field="progress"><?php echo esc_html( $data['progress'] . '%' ); ?></progress>
			<p class="wpcheckpoint-job-line" role="status" aria-live="polite">
				<span data-field="progress_text"><?php echo esc_html( $data['progress'] . '%' ); ?></span>
				<span data-field="step_label"><?php echo esc_html( $data['step_label'] ); ?></span>
				<span data-field="message"><?php echo esc_html( $data['message'] ); ?></span>
			</p>
			<p class="wpcheckpoint-job-error" data-field="last_error"<?php echo '' === $data['last_error'] ? ' hidden' : ''; ?>><?php echo esc_html( $data['last_error'] ); ?></p>
			<p class="wpcheckpoint-job-notice" data-field="notice" hidden></p>
			<p class="wpcheckpoint-job-notice" data-field="retry_note"<?php echo '' === $data['retry_note'] ? ' hidden' : ''; ?>><?php echo esc_html( $data['retry_note'] ); ?></p>
			<p class="wpcheckpoint-job-notice" data-field="stalled"<?php echo 0 === $data['stalled'] ? ' hidden' : ''; ?>><?php echo esc_html( $data['stalled_text'] ); ?></p>
			<div class="wpcheckpoint-job-questions" data-field="questions" hidden></div>
			<?php if ( null !== $outcome ) : ?>
				<p class="wpcheckpoint-job-outcome">
					<?php echo esc_html( $outcome['text'] ); ?>
					<?php if ( '' !== $outcome['url'] ) : ?>
						<a href="<?php echo esc_url( $outcome['url'] ); ?>"><?php esc_html_e( 'Details and download', 'wp-checkpoint' ); ?></a>
					<?php endif; ?>
				</p>
			<?php endif; ?>
			<details class="wpcheckpoint-job-log-box"<?php echo Job::FAILED === $job->status ? ' open' : ''; ?>>
				<summary><?php esc_html_e( 'Log', 'wp-checkpoint' ); ?></summary>
				<pre class="wpcheckpoint-job-log" data-field="log_tail"><?php echo esc_html( $data['log_tail'] ); ?></pre>
			</details>
			<p class="wpcheckpoint-job-actions">
				<button type="button" class="button" data-action="cancel"<?php echo $active ? '' : ' hidden'; ?>><?php esc_html_e( 'Cancel', 'wp-checkpoint' ); ?></button>
				<button type="button" class="button" data-action="retry"<?php echo $data['retryable'] ? '' : ' hidden'; ?>><?php esc_html_e( 'Retry', 'wp-checkpoint' ); ?></button>
				<a class="button-link" href="<?php echo esc_url( LogDownload::url( $job->id ) ); ?>"><?php esc_html_e( 'Download the log', 'wp-checkpoint' ); ?></a>
				<button type="button" class="button-link" data-action="dismiss"<?php echo $active ? ' hidden' : ''; ?>><?php esc_html_e( 'Dismiss', 'wp-checkpoint' ); ?></button>
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
			'answer'          => __( 'Continue', 'wp-checkpoint' ),
			'answer_failed'   => __( 'The answer was not taken; reload the page and answer again.', 'wp-checkpoint' ),
			'questions'       => __( 'The job needs a decision before it can go on:', 'wp-checkpoint' ),
			'choice_continue' => __( 'Continue without them', 'wp-checkpoint' ),
			'choice_stop'     => __( 'Stop the backup', 'wp-checkpoint' ),
			'choice_include'  => __( 'Include it', 'wp-checkpoint' ),
			'choice_exclude'  => __( 'Leave it out', 'wp-checkpoint' ),
		);
	}
}
