<?php
/**
 * The restore job (T042): so far, stages A and B of the database.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Support\Directories;

defined( 'ABSPATH' ) || exit;

/**
 * Restores a backup of the backups directory. The steps so far prepare
 * the database next to the live one and change nothing the site uses:
 *
 * 1. RestoreVerifyStep: the backup's structure (manifest, volumes, index
 *    lines, the layout) at the "structure" depth; a result that refuses a
 *    restore ends the job. The manifest it checked is copied into the work
 *    directory; later steps read that copy only.
 * 2. RestorePreflightStep: the table plan (temporary and final names),
 *    every table's definition and every chunk's columns, the foreign keys
 *    that would cross the swap.
 * 3. DatabaseImportStep: every chunk, statement by statement, into the
 *    temporary tables.
 *
 * The files, the swap and what follows are later parts of T042; no user
 * interface starts this job yet (only tests and, later, the restore
 * wizard). Options: {base, exclude_tables}; the backups directory comes
 * from the current storage directories, never from the options.
 */
final class RestoreJob implements JobType {

	const ID = 'restore';

	/**
	 * Most tables a restore may leave out (names of at most Manifest::MAX_TABLE_NAME bytes each).
	 */
	const MAX_EXCLUDED = 10000;

	/**
	 * Returns the current storage directories: function(): Directories.
	 *
	 * @var callable
	 */
	private $directories;

	/**
	 * Text cleaner (JobPresenter::clean()).
	 *
	 * @var callable
	 */
	private $clean;

	/**
	 * Constructor.
	 *
	 * @param callable $directories function(): Directories.
	 * @param callable $clean       Text cleaner.
	 */
	public function __construct( callable $directories, callable $clean ) {
		$this->directories = $directories;
		$this->clean       = $clean;
	}

	/**
	 * Type id.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Restore', 'wp-checkpoint' );
	}

	/**
	 * Step ids in order.
	 *
	 * @return string[]
	 */
	public function step_ids(): array {
		return array( RestoreVerifyStep::ID, RestorePreflightStep::ID, DatabaseImportStep::ID );
	}

	/**
	 * The steps.
	 *
	 * @return Step[]
	 */
	public function steps(): array {
		$directories = $this->directories;
		$backups     = static function () use ( $directories ): string {
			$dirs = call_user_func( $directories );
			return $dirs instanceof Directories ? $dirs->backups() : '';
		};
		return array(
			new RestoreVerifyStep( $backups, $this->clean ),
			new RestorePreflightStep( $backups ),
			new DatabaseImportStep(),
		);
	}

	/**
	 * Validated options.
	 *
	 * @param array<string, mixed> $options Options.
	 * @return array{base: string, exclude_tables: string[]}
	 * @throws \InvalidArgumentException When they are not valid.
	 */
	public static function options( array $options ): array {
		$base = isset( $options['base'] ) && is_string( $options['base'] ) ? $options['base'] : '';
		if ( 1 !== preg_match( PreflightStep::BASE_PATTERN, $base ) ) {
			throw new \InvalidArgumentException( 'Not the name of a backup.' );
		}
		$exclude = isset( $options['exclude_tables'] ) ? $options['exclude_tables'] : array();
		if ( ! is_array( $exclude ) || count( $exclude ) > self::MAX_EXCLUDED ) {
			throw new \InvalidArgumentException( 'The tables to leave out are not a list of table names.' );
		}
		foreach ( $exclude as $name ) {
			if ( ! is_string( $name ) || '' === $name || strlen( $name ) > Manifest::MAX_TABLE_NAME || 1 === preg_match( '/[\x00-\x1F\x7F]/', $name ) ) {
				throw new \InvalidArgumentException( 'The tables to leave out are not a list of table names.' );
			}
		}
		return array(
			'base'           => $base,
			'exclude_tables' => array_values( $exclude ),
		);
	}
}
