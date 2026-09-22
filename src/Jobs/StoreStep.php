<?php
/**
 * The step that moves the finished archive into the backups directory.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Archive\Packer;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages carry file names; the runner stores them through the redactor and the presenter cleans them before display.

/**
 * Moves the volumes, in order, then the standalone manifest last, from
 * the work directory's volumes/ into backups/ with one rename() each
 * (both under the storage base, the same file system). A backup exists
 * once its manifest is in backups/: the listing keys on it.
 *
 * Every file is in one of four states on replay, and each has a rule:
 *   - in volumes/ only: rename it;
 *   - in backups/ only: it was moved before a crash; count it as moved;
 *   - in neither: the work directory was lost; fail;
 *   - in both: the name is taken by another backup, or the work directory
 *     was tampered with; fail without renaming (rename() would replace).
 * The collision check against backups/ runs once, before the first move,
 * and its result is in the cursor: a replay after "renamed, not yet
 * checkpointed" must not mistake its own file for a collision.
 */
final class StoreStep implements Step {

	const ID = 'store';

	/**
	 * Backups directory.
	 *
	 * @var string
	 */
	private $backups;

	/**
	 * Constructor.
	 *
	 * @param string $backups Absolute backups directory.
	 */
	public function __construct( string $backups ) {
		$this->backups = rtrim( $backups, '/\\' );
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
	 * Move the files.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws TransientFailure When a rename fails.
	 * @throws \RuntimeException When a file is missing or a name is taken.
	 */
	public function run( JobContext $context ): StepResult {
		$work   = $context->work_path();
		$plan   = ExportPlan::read( $work, ExportPlan::PLAN );
		$base   = (string) $plan['base'];
		$cursor = array_merge(
			array(
				'checked' => false,
				'moved'   => 0,
			),
			$context->cursor()
		);
		$names  = $this->names( $work, $base );

		if ( ! $cursor['checked'] ) {
			foreach ( $names as $name ) {
				if ( file_exists( $this->backups . DIRECTORY_SEPARATOR . $name ) ) {
					throw new \RuntimeException( sprintf( 'A backup named %s already exists in the backups directory; nothing was overwritten.', $name ) );
				}
			}
			$cursor['checked'] = true;
			$context->checkpoint( $cursor, 5, __( 'Storing the backup', 'wp-checkpoint' ) );
		}

		$total = count( $names );
		while ( $cursor['moved'] < $total ) {
			$name = $names[ $cursor['moved'] ];
			$from = $work . DIRECTORY_SEPARATOR . PackStep::VOLUMES . DIRECTORY_SEPARATOR . $name;
			$to   = $this->backups . DIRECTORY_SEPARATOR . $name;
			clearstatcache( true, $from );
			clearstatcache( true, $to );
			$in_work    = is_file( $from );
			$in_backups = is_file( $to );
			if ( $in_work && $in_backups ) {
				throw new \RuntimeException( sprintf( 'File %s is both in the work directory and in the backups directory; nothing was overwritten.', $name ) );
			}
			if ( ! $in_work && ! $in_backups ) {
				throw new \RuntimeException( sprintf( 'File %s of the finished archive is missing; the work directory was lost or changed.', $name ) );
			}
			if ( $in_work && ! @rename( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- a warning would put the path into the error log; failure is thrown.
				throw new TransientFailure( sprintf( 'File %s could not be moved into the backups directory.', $name ) );
			}
			++$cursor['moved'];
			$context->checkpoint( $cursor, (int) floor( 100 * $cursor['moved'] / $total ), sprintf( /* translators: 1: files moved, 2: files in total */ __( 'Stored %1$d of %2$d files', 'wp-checkpoint' ), $cursor['moved'], $total ) );
			if ( $cursor['moved'] < $total && $context->should_stop() ) {
				return StepResult::progress( $cursor, (int) floor( 100 * $cursor['moved'] / $total ), __( 'Storing the backup', 'wp-checkpoint' ) );
			}
		}
		$context->logger()->info( 'Backup stored', array( 'files' => $total ) );
		return StepResult::done( __( 'Backup stored', 'wp-checkpoint' ) );
	}

	/**
	 * Nothing to do: what is still in the work directory is removed by the
	 * engine; what reached backups/ is the backup.
	 *
	 * @param JobContext $context Context.
	 * @return void
	 */
	public function cleanup( JobContext $context ): void {
		unset( $context );
	}

	/**
	 * The files to move, in order: the volumes as the manifest lists them,
	 * then the standalone manifest.
	 *
	 * @param string $work Work directory.
	 * @param string $base Base name.
	 * @return string[]
	 * @throws \RuntimeException When the manifest cannot be read.
	 */
	private function names( string $work, string $base ): array {
		$manifest = $base . '.manifest.json';
		$path     = $work . DIRECTORY_SEPARATOR . PackStep::VOLUMES . DIRECTORY_SEPARATOR . $manifest;
		$stored   = $this->backups . DIRECTORY_SEPARATOR . $manifest;
		$source   = is_file( $path ) ? $path : ( is_file( $stored ) ? $stored : '' );
		if ( '' === $source ) {
			throw new \RuntimeException( 'The manifest of the finished archive is missing; the work directory was lost or changed.' );
		}
		$json = @file_get_contents( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the job's own manifest, small by construction.
		if ( ! is_string( $json ) ) {
			throw new \RuntimeException( 'The manifest of the finished archive cannot be read.' );
		}
		$names = array();
		foreach ( Manifest::from_json( $json )->volumes() as $volume ) {
			$names[] = (string) $volume['path'];
		}
		$names[] = $manifest;
		return $names;
	}
}
