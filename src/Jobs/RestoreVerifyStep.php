<?php
/**
 * A restore's first step: the backup's structure, and a copy of its manifest.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Archive\ArchiveVerifier;
use WPCheckpoint\Archive\ChunkHasher;
use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Archive\ManifestError;
use WPCheckpoint\Backups\BackupStore;
use WPCheckpoint\Restore\RestoreFiles;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- job errors; the presenter cleans them.
// phpcs:disable WordPress.WP.AlternativeFunctions -- the manifest and its copy in the job's work directory.

/**
 * Before the first unit, the backup's manifest is copied into the work
 * directory (RestoreFiles::MANIFEST) and its hash fixed in the cursor:
 * every later step reads that copy and nothing else, so a manifest
 * replaced during the restore cannot change what the restore does, and
 * the check below is about the same bytes. A manifest larger than any this
 * plugin writes, or one that lists volumes not named after the backup,
 * ends the job.
 *
 * Then ArchiveVerifier at the "structure" depth (manifest, volumes and
 * their sizes, the index files and each of their lines, the order of the
 * entries), in bounded units with its state in the cursor, as the verify
 * job runs it: the first unit of a tick always runs, the tick ends when
 * the time left is under 1.5 times the slowest unit, and a unit slower
 * than the whole budget ends the job with the reason. A result that
 * refuses a restore (VerificationResult::restore_refused()) ends the job
 * with the report; nothing has been touched.
 */
final class RestoreVerifyStep implements Step {

	const ID         = 'restore_verify';
	const VERIFY_DIR = 'restore-verify';
	const MARGIN     = 1.5;

	/**
	 * Returns the backups directory: function(): string.
	 *
	 * @var callable
	 */
	private $backups;

	/**
	 * Text cleaner.
	 *
	 * @var callable
	 */
	private $clean;

	/**
	 * Constructor.
	 *
	 * @param callable $backups function(): string.
	 * @param callable $clean   Text cleaner.
	 */
	public function __construct( callable $backups, callable $clean ) {
		$this->backups = $backups;
		$this->clean   = $clean;
	}

	/**
	 * Step id.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Copy the manifest, then check the structure.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws TransientFailure When a work file cannot be written.
	 * @throws \RuntimeException When the backup cannot be restored.
	 */
	public function run( JobContext $context ): StepResult {
		$options = RestoreJob::options( $context->options() );
		$cursor  = array_merge(
			array(
				'manifest_sha256' => '',
				'verifier'        => array(),
			),
			$context->cursor()
		);
		$backups = (string) call_user_func( $this->backups );
		if ( '' === $backups ) {
			throw new TransientFailure( 'The backups directory is not available.' );
		}
		$source = $backups . DIRECTORY_SEPARATOR . $options['base'] . BackupStore::MANIFEST_SUFFIX;
		$copy   = RestoreFiles::path( $context->work_path(), RestoreFiles::MANIFEST );
		if ( '' === $cursor['manifest_sha256'] ) {
			$cursor['manifest_sha256'] = self::copy_manifest( $source, $copy, $options['base'] );
			$context->checkpoint( $cursor, 0, __( 'Checking the backup', 'wp-checkpoint' ) );
		} elseif ( ! is_file( $copy ) || ! hash_equals( (string) $cursor['manifest_sha256'], ChunkHasher::hash_file( $copy ) ) ) {
			throw new WorkLost( 'The copy of the manifest in the work directory is gone or changed.' );
		}
		$dir = $context->work_path() . DIRECTORY_SEPARATOR . self::VERIFY_DIR;
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700 ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would put the path into the error log.
			throw new TransientFailure( 'The check\'s work directory could not be created.' );
		}
		// The verifier reads the volumes next to the manifest it is given: the original's directory.
		$verifier = ArchiveVerifier::open( $source, $dir, ArchiveVerifier::DEPTH_STRUCTURE, is_array( $cursor['verifier'] ) ? $cursor['verifier'] : array() );
		$budget   = (float) $context->budget()->seconds;
		$first    = true;
		$slowest  = 0.0;
		while ( true ) {
			if ( ! $first && $context->remaining_seconds() < $slowest * self::MARGIN ) {
				return StepResult::progress( $cursor, 2, __( 'Checking the backup', 'wp-checkpoint' ) );
			}
			$started            = $context->elapsed();
			$more               = $verifier->step();
			$cost               = $context->elapsed() - $started;
			$first              = false;
			$slowest            = max( $slowest, $cost );
			$cursor['verifier'] = $verifier->state();
			if ( $cost > $budget ) {
				throw new \RuntimeException( sprintf( 'Reading this backup is too slow on this server: one step of the check took %d seconds, more than the %d-second time budget of a single run.', (int) ceil( $cost ), (int) $budget ) );
			}
			if ( ! $more ) {
				break;
			}
			if ( $context->should_stop() ) {
				return StepResult::progress( $cursor, 2, __( 'Checking the backup', 'wp-checkpoint' ) );
			}
		}
		$result = $verifier->result();
		$report = $result->to_text( $this->clean );
		$context->logger()->info( 'Backup checked before the restore', array( 'report' => $report ) );
		if ( ! hash_equals( (string) $cursor['manifest_sha256'], ChunkHasher::hash_file( $source ) ) ) {
			throw new \RuntimeException( 'The backup\'s manifest was replaced while it was being checked; nothing was restored. Start the restore again.' );
		}
		if ( $result->restore_refused() ) {
			throw new \RuntimeException( 'This backup cannot be restored: ' . strtok( $report, "\n" ) );
		}
		return StepResult::done( __( 'The backup can be restored', 'wp-checkpoint' ) );
	}

	/**
	 * Copy the manifest into the work directory; its hash.
	 *
	 * @param string $source Manifest.
	 * @param string $copy   Copy.
	 * @param string $base   Backup base name.
	 * @return string
	 * @throws TransientFailure When the copy cannot be written.
	 * @throws \RuntimeException When there is no usable manifest.
	 */
	private static function copy_manifest( string $source, string $copy, string $base ): string {
		clearstatcache( true, $source );
		$size = is_file( $source ) ? (int) filesize( $source ) : 0;
		if ( $size <= 0 ) {
			throw new \RuntimeException( 'This backup has no manifest in the backups directory.' );
		}
		if ( $size > Manifest::MAX_JSON_BYTES ) {
			throw new \RuntimeException( sprintf( 'The manifest of this backup is larger than %d MB, more than any manifest this plugin writes; it cannot be restored.', (int) ( Manifest::MAX_JSON_BYTES / 1048576 ) ) );
		}
		$json = @file_get_contents( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- bounded above; a warning would put the path into the error log.
		if ( ! is_string( $json ) ) {
			throw new \RuntimeException( 'The manifest of this backup cannot be read.' );
		}
		try {
			$manifest = Manifest::from_json( $json );
		} catch ( ManifestError $e ) {
			$manifest = null; // The verifier reports what is wrong with it.
		}
		foreach ( null !== $manifest ? $manifest->volumes() : array() as $volume ) {
			if ( ! BackupStore::is_own_file( $base, (string) $volume['path'] ) ) {
				throw new \RuntimeException( sprintf( 'This manifest lists the volume %s, which is not named after this backup; it is not restored.', (string) $volume['path'] ) );
			}
		}
		$temp = $copy . '.tmp';
		if ( false === @file_put_contents( $temp, $json ) || ! @rename( $temp, $copy ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			throw new TransientFailure( 'The manifest could not be copied into the work directory.' );
		}
		return hash( 'sha256', $json );
	}

	/**
	 * Nothing to do: the check's files and the manifest's copy are in the work directory, which the engine removes.
	 *
	 * @param JobContext $context Context.
	 * @return void
	 */
	public function cleanup( JobContext $context ): void {
		unset( $context );
	}
}
