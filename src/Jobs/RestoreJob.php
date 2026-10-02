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

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- validation messages that name an option; the presenter cleans them.

/**
 * Restores a backup of the backups directory. The steps so far prepare
 * the database next to the live one and change nothing the site uses:
 *
 * 0. RestorePlatformStep: a Windows server is refused before anything is
 *    read or written (not supported in this version).
 * 1. RestoreVerifyStep: the backup's structure (manifest, volumes, index
 *    lines, the layout) at the "structure" depth; a result that refuses a
 *    restore ends the job. The manifest it checked is copied into the work
 *    directory; later steps read that copy only.
 * 2. RestorePreflightStep: the table plan (temporary and final names),
 *    every table's definition and every chunk's columns, the foreign keys
 *    that would cross the swap.
 * 3. RestoreFilesPreflightStep: where the files are staged, and whether
 *    this site can take them (probes of the staging directories and the
 *    must-use plugins directory, every path of the files index, name
 *    clashes on this file system, free space). Nothing is staged yet.
 * 4. DatabaseImportStep: every chunk, statement by statement, into the
 *    temporary tables.
 * 5. PrefixRewriteStep: when the backup's table prefix is not this site's,
 *    the rows WordPress names after the prefix (roles, user capabilities
 *    and settings) renamed in the temporary tables.
 * 6. FileStagingStep: the backup's files, and the running copy of this
 *    plugin, written under the staging roots next to the site.
 * 7. SwapCheckStep: the final check before the swap (the temporary tables
 *    and the staged files are what the restore wrote, this plugin was not
 *    updated meanwhile, the site's directories did not move, no table left
 *    in place references one the swap moves away) and the swap's plan.
 *
 * The swap and what follows are later parts of T042; no user
 * interface starts this job yet (only tests and, later, the restore
 * wizard). Options: {base, exclude_tables, policy, unattended}; the backups
 * directory comes from the current storage directories, never from the
 * options. The policy says what to do with each kind of table another
 * installation in the same database may use (IncomingTables): "ask" (the
 * default), "restore" or "exclude". A restore nobody attends ("unattended")
 * is never asked, so it must say both; missing either refuses it before
 * anything of the backup is read (RestoreVerifyStep validates the options
 * first).
 */
final class RestoreJob implements JobType {

	const ID = 'restore';

	/**
	 * Most tables a restore may leave out (names of at most Manifest::MAX_TABLE_NAME bytes each).
	 */
	const MAX_EXCLUDED = 10000;

	/**
	 * What the restore does with each kind of table another installation may use, and the choices: the id of the
	 * question each is asked by, too (RestorePreflightStep).
	 */
	const POLICIES = array(
		'uncertain_tables' => array( 'ask', 'restore', 'exclude' ),
		'shared_tables'    => array( 'ask', 'restore', 'exclude' ),
	);

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
		return array( RestorePlatformStep::ID, RestoreVerifyStep::ID, RestorePreflightStep::ID, RestoreFilesPreflightStep::ID, DatabaseImportStep::ID, PrefixRewriteStep::ID, FileStagingStep::ID, SwapCheckStep::ID );
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
			new RestorePlatformStep(),
			new RestoreVerifyStep( $backups, $this->clean ),
			new RestorePreflightStep( $backups ),
			new RestoreFilesPreflightStep(),
			new DatabaseImportStep(),
			new PrefixRewriteStep(),
			new FileStagingStep(),
			new SwapCheckStep(),
		);
	}

	/**
	 * Validated options.
	 *
	 * @param array<string, mixed> $options Options.
	 * @return array{base: string, exclude_tables: string[], policy: array<string, string>, unattended: bool}
	 * @throws \InvalidArgumentException When they are not valid (the message names the option).
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
		$given  = isset( $options['policy'] ) ? $options['policy'] : array();
		$policy = array();
		if ( ! is_array( $given ) || array() !== array_diff( array_keys( $given ), array_keys( self::POLICIES ) ) ) {
			throw new \InvalidArgumentException( sprintf( 'The restore policy may only say: %s.', implode( ', ', array_keys( self::POLICIES ) ) ) );
		}
		foreach ( self::POLICIES as $key => $allowed ) {
			$value = $given[ $key ] ?? 'ask';
			if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
				throw new \InvalidArgumentException( sprintf( 'The restore policy "%1$s" must be one of: %2$s.', $key, implode( ', ', $allowed ) ) );
			}
			$policy[ $key ] = $value;
		}
		$unattended = $options['unattended'] ?? false;
		if ( ! is_bool( $unattended ) ) {
			throw new \InvalidArgumentException( 'The restore option "unattended" must be true or false.' );
		}
		if ( $unattended ) {
			// Nobody answers a question of a restore nobody attends: what it would ask must be said up front.
			foreach ( $policy as $key => $value ) {
				if ( 'ask' === $value ) {
					throw new \InvalidArgumentException( sprintf( 'An unattended restore must say what to do with %1$s: set the restore policy "%2$s" to "restore" or "exclude".', 'uncertain_tables' === $key ? 'tables that may belong to this site or to another installation in the same database' : 'tables this site shares with another installation in the same database', $key ) );
				}
			}
		}
		return array(
			'base'           => $base,
			'exclude_tables' => array_values( $exclude ),
			'policy'         => $policy,
			'unattended'     => $unattended,
		);
	}
}
