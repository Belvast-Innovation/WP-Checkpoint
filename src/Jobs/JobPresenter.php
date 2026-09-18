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
	 * The pipeline: scrub, redact, mask paths, mask hosts. Fails closed.
	 *
	 * @param string                $text  Text.
	 * @param array<string, string> $extra Extra placeholder => path (the job's storage directory).
	 * @return string
	 */
	public function clean( string $text, array $extra = array() ): string {
		if ( '' === $text ) {
			return '';
		}
		$text   = $this->redactor->redact( Utf8::scrub( $text ) );
		$masked = Report::mask_paths( $text, array_merge( $extra, $this->paths ) );
		if ( ! is_string( $masked ) ) {
			return Report::failure_text();
		}
		$masked = Report::mask_hosts( $masked, $this->hosts, $this->site_paths['paths'], $this->site_paths['coarse'], $this->site_paths['network_root'] );
		return is_string( $masked ) ? $masked : Report::failure_text();
	}

	/**
	 * A job as an array safe to send to the client. No storage_path, no cursor.
	 *
	 * @param Job  $job      Job.
	 * @param bool $with_log Include the log tail.
	 * @return array<string, mixed>
	 */
	public function present( Job $job, bool $with_log = true ): array {
		$extra = '' !== $job->storage_path ? array( '{storage}' => $job->storage_path ) : array();
		$type  = $this->types->get( $job->type );
		$data  = array(
			'id'          => $job->id,
			'type'        => $job->type,
			'type_label'  => $this->clean( null !== $type ? $type->label() : $job->type, $extra ),
			'status'      => $job->status,
			'step'        => $job->step,
			'step_label'  => $this->clean( $job->step, $extra ),
			'progress'    => $job->progress,
			'message'     => $this->clean( $job->progress_message, $extra ),
			'attempts'    => $job->attempts,
			'created_at'  => $job->created_at,
			'started_at'  => $job->started_at,
			'updated_at'  => $job->updated_at,
			'finished_at' => $job->finished_at,
			'last_error'  => $this->clean( $job->last_error, $extra ),
		);
		if ( $with_log ) {
			$data['log_tail'] = $this->log_tail( $job );
		}
		return $data;
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
		if ( '' === $job->storage_path || '' === $job->log_path || 0 !== strpos( $job->log_path, 'logs/' ) ) {
			return '';
		}
		// Files in another directory belong to another installation (a copied database on a shared file
		// system) or to an abandoned directory: the same rule as the lock file writer and the purge.
		$base = $this->directories->base();
		if ( '' === $base || ! Paths::same( $job->storage_path, $base, Paths::is_windows() ) ) {
			return '';
		}
		// The same gate as the writer and the purge: only a file inside the job's logs/ directory is ever read.
		$logs = $job->storage_path . DIRECTORY_SEPARATOR . 'logs';
		$path = $job->storage_path . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $job->log_path );
		if ( ! Paths::is_inside( $logs, $path ) ) {
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
