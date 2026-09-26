<?php
/**
 * Shapes a job for the REST API, WP-CLI and the admin page.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Environment;
use WPCheckpoint\Support\Paths;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Support\Report;
use WPCheckpoint\Support\Utf8;

defined( 'ABSPATH' ) || exit;

/**
 * Every text that leaves the job engine (progress message, last error, step
 * label, log tail) goes through one pipeline: UTF-8 scrub (a cut multibyte
 * character would make json_encode fail), Redactor (credentials, e-mail
 * addresses, database names in remote errors), then path and host
 * placeholders (site identity). A masking failure yields a fixed text.
 * storage_path and the cursor are never exposed.
 */
final class JobPresenter {

	/**
	 * A job that stood still this long, with nothing waiting and nothing holding it, is reported as stalled.
	 */
	const STALL_SECONDS = 600;

	/**
	 * Redactor.
	 *
	 * @var Redactor
	 */
	private $redactor;

	/**
	 * Job types (labels).
	 *
	 * @var JobTypes
	 */
	private $types;

	/**
	 * Storage directories: logs are read from the current directory only.
	 *
	 * @var Directories
	 */
	private $directories;

	/**
	 * Placeholder => absolute path.
	 *
	 * @var array<string, string>
	 */
	private $paths;

	/**
	 * Site host names.
	 *
	 * @var string[]
	 */
	private $hosts;

	/**
	 * Site path prefixes (see Environment::report_site_paths()).
	 *
	 * @var array{paths: string[], coarse: bool, network_root: string}
	 */
	private $site_paths;

	/**
	 * Constructor.
	 *
	 * @param Redactor                                                        $redactor    Redactor.
	 * @param JobTypes                                                        $types       Job types.
	 * @param Directories                                                     $directories Storage directories.
	 * @param array<string, string>|null                                      $paths       Placeholder => path; null uses the installation's.
	 * @param string[]|null                                                   $hosts       Site hosts; null uses the installation's.
	 * @param array{paths: string[], coarse: bool, network_root: string}|null $site_paths  Site paths; null uses the installation's.
	 */
	public function __construct( Redactor $redactor, JobTypes $types, Directories $directories, $paths = null, $hosts = null, $site_paths = null ) {
		$this->redactor    = $redactor;
		$this->types       = $types;
		$this->directories = $directories;
		$this->paths       = is_array( $paths ) ? $paths : self::installation_paths();
		$this->hosts       = is_array( $hosts ) ? $hosts : Environment::report_hosts();
		$this->site_paths  = is_array( $site_paths ) ? $site_paths : Environment::report_site_paths();
	}

	/**
	 * Paths masked in every text.
	 *
	 * @return array<string, string>
	 */
	public static function installation_paths(): array {
		$abspath = rtrim( ABSPATH, '/\\' );
		$paths   = array(
			'{abspath}'        => $abspath,
			'{abspath-parent}' => dirname( $abspath ),
			'{wp-content}'     => WP_CONTENT_DIR,
			'{tmp}'            => sys_get_temp_dir(),
		);
		if ( isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) && '' !== $_SERVER['DOCUMENT_ROOT'] ) {
			$paths['{document-root}'] = sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) );
		}
		return $paths;
	}

	/**
	 * The pipeline: scrub, neutralize terminal controls, redact, mask
	 * paths, mask hosts. Fails closed. Every text that leaves the engine
	 * (progress messages, errors, log tails, verification findings) goes
	 * through here, and untrusted input reaches it (archive entry names,
	 * manifest strings), so the pipeline must cover what a terminal, a
	 * log viewer or a ticket would interpret: escape sequences and
	 * bidirectional overrides are replaced, not passed on.
	 *
	 * @param string                $text  Text.
	 * @param array<string, string> $extra Extra placeholder => path (the job's storage directory).
	 * @return string
	 */
	public function clean( string $text, array $extra = array() ): string {
		if ( '' === $text ) {
			return '';
		}
		$text   = $this->redactor->redact( Utf8::neutralize_controls( Utf8::scrub( $text ) ) );
		$masked = Report::mask_paths( $text, array_merge( $extra, $this->paths ) );
		if ( ! is_string( $masked ) ) {
			return Report::failure_text();
		}
		$masked = Report::mask_hosts( $masked, $this->hosts, $this->site_paths['paths'], $this->site_paths['coarse'], $this->site_paths['network_root'] );
		if ( ! is_string( $masked ) ) {
			return Report::failure_text();
		}
		// A backup's base name ({slug}-{date}-{time}-{hex}) carries the site's slug: the second line of
		// defence behind messages that refer to files by number. Fail-closed like the other masks. Masked
		// bare or with the suffixes the plugin gives it (volume, single archive, manifest, and what follows
		// those); a user's file that merely looks alike and goes on with another extension is left alone.
		$masked = preg_replace( '/[a-z0-9][a-z0-9-]*-\d{8}-\d{6}-[0-9a-f]{4}(?![a-z0-9-])(?!\.(?!(?:part\d{3,}\.)?wpcheckpoint\.|manifest\.json)[A-Za-z0-9])/', '[backup]', $masked );
		return is_string( $masked ) ? $masked : Report::failure_text();
	}

	/**
	 * Why a failed job can no longer be retried.
	 *
	 * @return string
	 */
	public static function retry_note(): string {
		return __( 'The intermediate files of this job passed their retention period and were cleaned up. Start a new job instead.', 'wp-checkpoint' );
	}

	/**
	 * The job's state in words: a job that waits for an answer is not
	 * "paused" to the person who has to give it.
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	public static function status_text( Job $job ): string {
		switch ( $job->status ) {
			case Job::QUEUED:
				return __( 'Queued', 'wp-checkpoint' );
			case Job::RUNNING:
				return __( 'Running', 'wp-checkpoint' );
			case Job::PAUSED:
				return $job->awaiting_answer() ? __( 'Needs your decision', 'wp-checkpoint' ) : __( 'Continuing', 'wp-checkpoint' );
			case Job::COMPLETED:
				return __( 'Completed', 'wp-checkpoint' );
			case Job::FAILED:
				return __( 'Failed', 'wp-checkpoint' );
			case Job::CANCELLED:
				return __( 'Cancelled', 'wp-checkpoint' );
		}
		return $job->status;
	}

	/**
	 * What a failure means for the person looking at it, and what to do:
	 * retry when the cause may pass, start over when retrying cannot succeed
	 * (Job::FAILURE_FINAL), and otherwise retry once and start over if it
	 * fails the same way. The technical detail (step, exception) stays in
	 * the log.
	 *
	 * @param Job $job Failed job.
	 * @return string
	 */
	public static function failure_text( Job $job ): string {
		$verify = 'verify' === $job->type;
		if ( ! $job->can_retry() ) {
			return $verify
				? __( 'The check could not be finished, and its work files have been removed, so it cannot go on. Start a new check.', 'wp-checkpoint' )
				: __( 'The backup could not be finished, and its work files have been removed, so it cannot go on. Create a new backup.', 'wp-checkpoint' );
		}
		if ( Job::FAILURE_TEMPORARY === $job->failure_kind ) {
			return $verify
				? __( 'The check stopped because of a problem on the server that may pass, such as a full disk or the database being unavailable. Retry when it is solved: the check goes on where it stopped.', 'wp-checkpoint' )
				: __( 'The backup stopped because of a problem on the server that may pass, such as a full disk or the database being unavailable. Retry when it is solved: the backup goes on where it stopped.', 'wp-checkpoint' );
		}
		if ( Job::FAILURE_FINAL === $job->failure_kind && Job::REASON_TABLE_CHANGED === $job->failure_reason ) {
			return __( 'The backup was stopped because the structure of a table changed while it was being exported. Going on would give a backup whose table data does not match its table definition. Create a new backup.', 'wp-checkpoint' );
		}
		if ( Job::FAILURE_FINAL === $job->failure_kind ) {
			return $verify
				? __( 'The check could not be finished, and retrying would fail the same way. Start a new check.', 'wp-checkpoint' )
				: __( 'The backup could not be finished, and retrying would fail the same way. Create a new backup.', 'wp-checkpoint' );
		}
		return $verify
			? __( 'The check could not be finished. Retry goes on where it stopped; if it fails the same way again, start a new check.', 'wp-checkpoint' )
			: __( 'The backup could not be finished. Retry goes on where it stopped; if it fails the same way again, create a new backup.', 'wp-checkpoint' );
	}

	/**
	 * A step in words (the id when the step is not one of the plugin's own).
	 *
	 * @param string $step Step id.
	 * @return string
	 */
	public static function step_label( string $step ): string {
		switch ( $step ) {
			case 'preflight':
				return __( 'Checking the site', 'wp-checkpoint' );
			case 'scan':
				return __( 'Listing the files', 'wp-checkpoint' );
			case 'review':
				return __( 'Reviewing what to back up', 'wp-checkpoint' );
			case 'database':
				return __( 'Exporting the database', 'wp-checkpoint' );
			case 'pack':
				return __( 'Packing the archive', 'wp-checkpoint' );
			case 'manifest':
				return __( 'Writing and checking the archive', 'wp-checkpoint' );
			case 'store':
				return __( 'Storing the backup', 'wp-checkpoint' );
			case 'verify':
				return __( 'Checking the backup', 'wp-checkpoint' );
		}
		return $step;
	}

	/**
	 * The failure without the runner's frame: the step id and the exception
	 * class (Runner's "Step "x": Class: …", "Step "x" failed N times:
	 * Class: …" and "Step "x" could not make progress …") belong in the
	 * log, not on the screen. Nothing when the pattern fails (fail-closed).
	 *
	 * @param string $error last_error.
	 * @return string
	 */
	public static function error_detail( string $error ): string {
		return self::strip_frame( Utf8::scrub( $error ) );
	}

	/**
	 * The frame taken off text already scrubbed; '' when the pattern fails.
	 *
	 * @param string $text Valid UTF-8.
	 * @return string
	 */
	public static function strip_frame( string $text ): string {
		$detail = preg_replace( '/\AStep "[^"]*"(?:(?: failed \d+ times)?: (?:[A-Za-z0-9_\\\\]+: )?| (?=could not ))/u', '', $text );
		return is_string( $detail ) ? ucfirst( $detail ) : '';
	}

	/**
	 * What to say about a job that stood still, or '' for 0 minutes.
	 *
	 * @param int $minutes stalled_minutes().
	 * @return string
	 */
	public static function stalled_text( int $minutes ): string {
		if ( $minutes <= 0 ) {
			return '';
		}
		/* translators: %d: minutes */
		return sprintf( _n( 'This job has not moved for %d minute. It goes on while this page is open, and otherwise when someone visits the site. For jobs that run with nobody on the site, set up a system cron job for WordPress, or use WP-CLI.', 'This job has not moved for %d minutes. It goes on while this page is open, and otherwise when someone visits the site. For jobs that run with nobody on the site, set up a system cron job for WordPress, or use WP-CLI.', $minutes, 'wp-checkpoint' ), $minutes );
	}

	/**
	 * Minutes a job has gone without moving although nothing waits for a
	 * person and nothing holds it, or 0. Evidence, not a prediction: a
	 * screen says the job needs a visit, a real cron or WP-CLI only once it
	 * has actually stood still, never from a probe result that may be stale.
	 * The threshold is above the longest wait (a step's wait or a backoff,
	 * up to 300 s) plus the fallback cron interval and a lease.
	 *
	 * @param Job $job Job.
	 * @param int $now Unix time.
	 * @return int
	 */
	public static function stalled_minutes( Job $job, int $now ): int {
		if ( ! in_array( $job->status, array( Job::QUEUED, Job::RUNNING ), true ) || $job->locked_until > $now ) {
			return 0;
		}
		$since = max( $job->progress_at, $job->created_at, $job->updated_at > 0 && Job::QUEUED === $job->status ? $job->updated_at : 0 );
		$idle  = $now - $since;
		return $idle >= self::STALL_SECONDS ? intdiv( $idle, 60 ) : 0;
	}

	/**
	 * A job as an array safe to send to the client. No storage_path, no
	 * cursor, no options; the questions of a job that waits for an answer
	 * are included (cleaned) so the client can ask them.
	 *
	 * @param Job  $job      Job.
	 * @param bool $with_log Include the log tail.
	 * @return array<string, mixed>
	 */
	public function present( Job $job, bool $with_log = true ): array {
		$extra                = '' !== $job->storage_path ? array( '{storage}' => $job->storage_path ) : array();
		$type                 = $this->types->get( $job->type );
		$data                 = array(
			'id'           => $job->id,
			'type'         => $job->type,
			'type_label'   => $this->clean( null !== $type ? $type->label() : $job->type, $extra ),
			'status'       => $job->status,
			'step'         => $job->step,
			'step_label'   => $this->clean( self::step_label( $job->step ), $extra ),
			'progress'     => $job->progress,
			'message'      => '' === $job->progress_message && Job::QUEUED === $job->status ? __( 'Starting…', 'wp-checkpoint' ) : $this->clean( $job->progress_message, $extra ),
			'status_text'  => self::status_text( $job ),
			'awaiting'     => $job->awaiting_answer(),
			'attempts'     => $job->attempts,
			'created_at'   => $job->created_at,
			'started_at'   => $job->started_at,
			'updated_at'   => $job->updated_at,
			'finished_at'  => $job->finished_at,
			'last_error'   => $this->clean( $job->last_error, $extra ),
			'retryable'    => $job->can_retry(),
			'retry_useful' => $job->retry_useful(),
			'failure_kind' => $job->failure_kind,
			'error_detail' => $this->clean( self::error_detail( $job->last_error ), $extra ),
			'failure_text' => Job::FAILED === $job->status ? self::failure_text( $job ) : '',
			'retry_note'   => Job::FAILED === $job->status && ! $job->can_retry() ? self::retry_note() : '',
			'questions'    => $job->awaiting_answer() ? $this->clean_deep( $job->questions, $extra ) : null,
			'progress_at'  => $job->progress_at,
			'stalled'      => self::stalled_minutes( $job, time() ),
		);
		$data['stalled_text'] = self::stalled_text( $data['stalled'] );
		if ( $with_log ) {
			$data['log_tail'] = $this->log_tail( $job );
		}
		return $data;
	}

	/**
	 * Apply clean() to every string in a structure (the questions a step
	 * asked); keys are kept, other scalars pass through.
	 *
	 * @param mixed                 $value Structure.
	 * @param array<string, string> $extra Extra path placeholders.
	 * @return mixed
	 */
	private function clean_deep( $value, array $extra ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ is_string( $key ) ? $this->clean( $key, $extra ) : $key ] = $this->clean_deep( $item, $extra );
			}
			return $out;
		}
		return is_string( $value ) ? $this->clean( $value, $extra ) : $value;
	}

	/**
	 * The job's log file, or '' when it must not be read from here: the
	 * same gate for the tail on screen and the download.
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	public function log_file( Job $job ): string {
		if ( '' === $job->storage_path || '' === $job->log_path || 0 !== strpos( $job->log_path, 'logs/' ) ) {
			return '';
		}
		// Files in another directory belong to another installation (a copied database on a shared file
		// system) or to an abandoned directory: the same rule as the lock file writer and the purge.
		$base = $this->directories->base();
		if ( '' === $base || ! Paths::same_location( $job->storage_path, $base ) ) {
			return '';
		}
		// The same gate as the writer and the purge: only a file inside the job's logs/ directory is ever read,
		// reached under the current directory (the row's spelling of it was only resolved for the check).
		$logs = $base . DIRECTORY_SEPARATOR . 'logs';
		$path = $base . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $job->log_path );
		return Paths::is_inside( $logs, $path ) ? $path : '';
	}

	/**
	 * The cleaned tail of the job log: empty when there is no file yet (or
	 * the job's files live in another storage directory), a fixed text when
	 * the file could not be read.
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	public function log_tail( Job $job ): string {
		$path = $this->log_file( $job );
		if ( '' === $path ) {
			return '';
		}
		$tail = LogTail::read( $path );
		if ( ! $tail['exists'] ) {
			return '';
		}
		if ( ! $tail['ok'] ) {
			return __( 'The log could not be read.', 'wp-checkpoint' );
		}
		return $this->clean( $tail['text'], array( '{storage}' => $job->storage_path ) );
	}
}
