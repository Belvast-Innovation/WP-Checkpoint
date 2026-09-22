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

		$total = count( $names );
		if ( ! $cursor['checked'] ) {
			foreach ( $names as $i => $name ) {
				if ( file_exists( $this->backups . DIRECTORY_SEPARATOR . $name ) ) {
					throw new \RuntimeException( sprintf( '%s already exists in the backups directory; nothing was overwritten.', self::label( $i, $total ) ) );
				}
			}
			$cursor['checked'] = true;
			$context->checkpoint( $cursor, 5, __( 'Storing the backup', 'wp-checkpoint' ) );
		}

		while ( $cursor['moved'] < $total ) {
			$name = $names[ $cursor['moved'] ];
			$from = $work . DIRECTORY_SEPARATOR . PackStep::VOLUMES . DIRECTORY_SEPARATOR . $name;
			$to   = $this->backups . DIRECTORY_SEPARATOR . $name;
			clearstatcache( true, $from );
			clearstatcache( true, $to );
			$in_work    = is_file( $from );
			$in_backups = is_file( $to );
			if ( $in_work && $in_backups ) {
				throw new \RuntimeException( sprintf( '%s is both in the work directory and in the backups directory; nothing was overwritten.', self::label( $cursor['moved'], $total ) ) );
			}
			if ( ! $in_work && ! $in_backups ) {
				throw new \RuntimeException( sprintf( '%s is missing; the work directory was lost or changed.', self::label( $cursor['moved'], $total ) ) );
			}
			if ( $in_work ) {
				$context->confirm_lease(); // Nothing between the lease check and the rename.
			}
			if ( $in_work && ! @rename( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- a warning would put the path into the error log; failure is thrown.
				throw new TransientFailure( sprintf( '%s could not be moved into the backups directory.', self::label( $cursor['moved'], $total ) ) );
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
	 * A file by its place, never by its name: the name carries the site's
	 * slug, and this text reaches the job's error and log.
	 *
	 * @param int $i     Position (0-based).
	 * @param int $total Files in total.
	 * @return string
	 */
	private static function label( int $i, int $total ): string {
		return $i + 1 === $total ? sprintf( 'The manifest (file %d of %d)', $i + 1, $total ) : sprintf( 'Volume %d (file %d of %d)', $i + 1, $i + 1, $total );
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
		clearstatcache( true, $source );
		$size = @filesize( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.
		if ( ! is_int( $size ) || $size > Manifest::MAX_JSON_BYTES ) {
			throw new \RuntimeException( 'The manifest of the finished archive cannot be read or is larger than allowed.' );
		}
		$json = @file_get_contents( $source, false, null, 0, Manifest::MAX_JSON_BYTES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded by the size check above.
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
