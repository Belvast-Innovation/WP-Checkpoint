<?php
/**
 * Re-adopts the original storage directory after an ABSPATH change.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The owner marker binds a directory to install ID + ABSPATH. When only the
 * ABSPATH changed (release-directory deployment, a move), the administrator
 * may confirm that this is the same site; with a trusted deployment root the
 * plugin does it on its own for sibling releases. Everything here works on
 * the directory recorded in the state, never on a path from a request, and
 * refuses directories whose marker carries another install ID.
 */
final class StorageReclaim {

	const LOCK_FILE        = '.reclaim.lock';
	const LOCK_TTL         = 300;
	const JOB_LOCK_PATTERN = 'job-*.lock';
	const ACTIVITY_WINDOW  = 600;

	/**
	 * Current state.
	 *
	 * @var array<string, mixed>
	 */
	private $state;

	/**
	 * Environment (see Directories::default_context()).
	 *
	 * @var array<string, mixed>
	 */
	private $context;

	/**
	 * Called after the lock is taken and before the marker is renamed; only
	 * used by tests to simulate a concurrent process.
	 *
	 * @var callable|null
	 */
	private $before_rename = null;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $state   Storage state.
	 * @param array<string, mixed> $context Directories context.
	 */
	public function __construct( array $state, array $context ) {
		$this->state   = $state;
		$this->context = $context;
	}

	/**
	 * Test seam: run a callback while the lock is held, before the rename.
	 *
	 * @internal
	 * @param callable $callback Receives the directory.
	 * @return void
	 */
	public function on_before_rename( callable $callback ): void {
		$this->before_rename = $callback;
	}

	/**
	 * Directory that may be reclaimed: the one recorded when the clone was
	 * detected. Empty when there is nothing to reclaim.
	 *
	 * @return string
	 */
	public function target(): string {
		if ( empty( $this->state['clone_detected'] ) ) {
			return '';
		}
		$path = isset( $this->state['previous_path'] ) ? (string) $this->state['previous_path'] : '';
		return '' === $path ? '' : rtrim( $path, '/\\' );
	}

	/**
	 * Classification of the ABSPATH change.
	 *
	 * @return array{verdict: string, recommendation: string, previous_exists: bool, previous_is_wordpress: bool, siblings: bool, deploy_root: string}
	 */
	public function classify(): array {
		return CloneClassifier::classify( (string) $this->state['previous_abspath'], (string) $this->context['abspath'] );
	}

	/**
	 * Everything the confirmation page shows and every condition the
	 * take-over requires. Recomputed on the POST as well.
	 *
	 * @return array{ok: bool, problems: string[], facts: array<string, mixed>}
	 */
	public function prechecks(): array {
		$dir      = $this->target();
		$problems = array();
		$facts    = array(
			'path'               => $dir,
			'backups'            => 0,
			'latest_backup'      => 0,
			'logs'               => 0,
			'install_id_matches' => false,
			'writable'           => false,
			'busy'               => false,
		);

		if ( '' === $dir ) {
			$problems[] = __( 'There is no directory to reclaim.', 'wp-checkpoint' );
			return $this->result( $problems, $facts );
		}
		if ( ! is_dir( $dir ) ) {
			$problems[] = __( 'The original directory no longer exists.', 'wp-checkpoint' );
			return $this->result( $problems, $facts );
		}
		if ( Deleter::is_reparse( $dir ) ) {
			$problems[] = __( 'The original directory is a symbolic link.', 'wp-checkpoint' );
		}
		if ( Directories::SOURCE_CUSTOM !== $this->state['source'] && ! Directories::is_valid_token( substr( basename( $dir ), strlen( Directories::DIR_PREFIX ) ) ) ) {
			$problems[] = __( 'The original directory does not have the expected name.', 'wp-checkpoint' );
		}

		$facts['install_id_matches'] = $this->marker_install_id_matches( $dir );
		if ( ! $facts['install_id_matches'] ) {
			$problems[] = __( 'The owner marker carries a different install ID: that directory belongs to another installation.', 'wp-checkpoint' );
		}

		$facts['writable'] = self::probe_writable( $dir );
		if ( ! $facts['writable'] ) {
			$problems[] = __( 'The original directory is not writable.', 'wp-checkpoint' );
		}

		$facts['busy'] = self::is_busy( $dir );
		if ( $facts['busy'] ) {
			$problems[] = __( 'A job seems to be running in the original directory (lock file or recent temporary files).', 'wp-checkpoint' );
		}

		// No GLOB_BRACE: it is missing on musl-based hosts and on Windows.
		foreach ( array_merge( self::files( $dir . '/backups/*.wpcheckpoint.zip' ), self::files( $dir . '/backups/*.wpcheckpoint.tar' ) ) as $file ) {
			++$facts['backups'];
			$facts['latest_backup'] = max( $facts['latest_backup'], (int) filemtime( $file ) );
		}
		$facts['logs'] = count( self::files( $dir . '/logs/*.log' ) );

		return $this->result( $problems, $facts );
	}

	/**
	 * Manual take-over after the administrator confirmed.
	 *
	 * @param bool $trust_root Remember the deployment root for automatic take-overs.
	 * @return array{ok: bool, message: string, trusted_root: string}
	 */
	public function reclaim( bool $trust_root ): array {
		$checks = $this->prechecks();
		if ( ! $checks['ok'] ) {
			return array(
				'ok'           => false,
				'message'      => implode( ' ', $checks['problems'] ),
				'trusted_root' => '',
			);
		}
		$dir    = $this->target();
		$result = $this->rewrite_marker( $dir );
		if ( ! $result['ok'] ) {
			return array(
				'ok'           => false,
				'message'      => $result['message'],
				'trusted_root' => '',
			);
		}
		$root = '';
		if ( $trust_root ) {
			$root = $this->classify()['deploy_root'];
		}
		return array(
			'ok'           => true,
			'message'      => __( 'The original storage directory is in use again.', 'wp-checkpoint' ),
			'trusted_root' => $root,
		);
	}

	/**
	 * Automatic take-over for a new release under the trusted deployment root.
	 *
	 * @return bool True when the marker was rewritten.
	 */
	public function auto(): bool {
		$root = isset( $this->state['trusted_deploy_root'] ) ? (string) $this->state['trusted_deploy_root'] : '';
		if ( '' === $root || '' === $this->target() ) {
			return false;
		}
		$previous_parent = CloneClassifier::parent( rtrim( Paths::normalize( (string) $this->state['previous_abspath'] ), '/' ) );
		$current_parent  = CloneClassifier::parent( rtrim( Paths::normalize( (string) $this->context['abspath'] ), '/' ) );
		$ci              = Paths::is_windows();
		if ( '' === $current_parent || ! Paths::same( $root, $current_parent, $ci ) || ! Paths::same( $root, $previous_parent, $ci ) ) {
			return false;
		}
		if ( ! $this->prechecks()['ok'] ) {
			return false;
		}
		return $this->rewrite_marker( $this->target() )['ok'];
	}

	/**
	 * Whether the marker in $dir carries this installation's ID.
	 *
	 * @param string $dir Directory.
	 * @return bool
	 */
	public function marker_install_id_matches( string $dir ): bool {
		$lines = self::read_marker( $dir );
		return null !== $lines && '' !== (string) $this->state['install_id'] && hash_equals( (string) $this->state['install_id'], $lines[0] );
	}

	/**
	 * Marker lines (install ID, ABSPATH hash) or null.
	 *
	 * @param string $dir Directory.
	 * @return array{0: string, 1: string}|null
	 */
	public static function read_marker( string $dir ) {
		$file = $dir . DIRECTORY_SEPARATOR . OwnerMarker::FILENAME;
		if ( ! is_file( $file ) ) {
			return null;
		}
		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- tiny local file.
		if ( ! is_string( $contents ) ) {
			return null;
		}
		$lines = array_map( 'trim', explode( "\n", trim( $contents ) ) );
		return count( $lines ) >= 2 ? array( $lines[0], $lines[1] ) : null;
	}

	/**
	 * Write / read back / delete a probe file.
	 *
	 * @param string $dir Directory.
	 * @return bool
	 */
	public static function probe_writable( string $dir ): bool {
		if ( ! wp_is_writable( $dir ) ) {
			return false;
		}
		$probe = $dir . DIRECTORY_SEPARATOR . '.probe-' . bin2hex( random_bytes( 4 ) );
		$nonce = bin2hex( random_bytes( 8 ) );
		try {
			return false !== file_put_contents( $probe, $nonce, LOCK_EX ) && file_get_contents( $probe ) === $nonce; // phpcs:ignore WordPress.WP.AlternativeFunctions -- probing the plugin's own directory.
		} finally {
			if ( is_file( $probe ) ) {
				wp_delete_file( $probe );
			}
		}
	}

	/**
	 * Whether a job is (or was very recently) working in the directory.
	 *
	 * Job runners must hold tmp/job-<id>.lock while active (T010).
	 *
	 * @param string $dir Directory.
	 * @return bool
	 */
	public static function is_busy( string $dir ): bool {
		$tmp = $dir . DIRECTORY_SEPARATOR . 'tmp';
		if ( ! is_dir( $tmp ) ) {
			return false;
		}
		if ( array() !== self::files( $tmp . '/' . self::JOB_LOCK_PATTERN ) ) {
			return true;
		}
		$cutoff = time() - self::ACTIVITY_WINDOW;
		foreach ( self::files( $tmp . '/*' ) as $file ) {
			if ( in_array( basename( $file ), array( 'index.php', '.htaccess' ), true ) ) {
				continue;
			}
			if ( (int) filemtime( $file ) > $cutoff ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A glob() that never returns false.
	 *
	 * @param string $pattern Pattern.
	 * @param int    $flags   glob() flags.
	 * @return string[]
	 */
	private static function files( string $pattern, int $flags = 0 ): array {
		$files = glob( $pattern, $flags );
		return is_array( $files ) ? $files : array();
	}

	/**
	 * Rewrite the owner marker with the current ABSPATH, under a lock and
	 * only while the marker still reads exactly as recorded.
	 *
	 * @param string $dir Directory.
	 * @return array{ok: bool, message: string}
	 */
	private function rewrite_marker( string $dir ): array {
		$secret = $this->acquire_lock( $dir );
		if ( '' === $secret ) {
			return array(
				'ok'      => false,
				'message' => __( 'Another installation is reclaiming this directory right now; try again in a few minutes.', 'wp-checkpoint' ),
			);
		}
		try {
			$lines = self::read_marker( $dir );
			if ( null === $lines || ! hash_equals( (string) $this->state['install_id'], $lines[0] ) ) {
				return array(
					'ok'      => false,
					'message' => __( 'The owner marker changed in the meantime.', 'wp-checkpoint' ),
				);
			}
			if ( ! hash_equals( OwnerMarker::hash_path( (string) $this->state['previous_abspath'] ), $lines[1] ) ) {
				return array(
					'ok'      => false,
					'message' => __( 'Another copy of this site has already claimed the directory since it was recorded here.', 'wp-checkpoint' ),
				);
			}
			if ( ! $this->lock_is_mine( $dir, $secret ) ) {
				return array(
					'ok'      => false,
					'message' => __( 'The reclaim lock was taken over by another process.', 'wp-checkpoint' ),
				);
			}

			if ( null !== $this->before_rename ) {
				call_user_func( $this->before_rename, $dir );
			}

			$marker  = $dir . DIRECTORY_SEPARATOR . OwnerMarker::FILENAME;
			$temp    = $marker . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
			$written = false !== file_put_contents( $temp, OwnerMarker::build( (string) $this->state['install_id'], (string) $this->context['abspath'] ), LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- plugin-owned directory.
			if ( ! $written ) {
				return array(
					'ok'      => false,
					'message' => __( 'The owner marker could not be rewritten.', 'wp-checkpoint' ),
				);
			}
			// Last check right before the atomic replace: the lock must still be ours.
			if ( ! $this->lock_is_mine( $dir, $secret ) ) {
				wp_delete_file( $temp );
				return array(
					'ok'      => false,
					'message' => __( 'The reclaim lock was taken over by another process.', 'wp-checkpoint' ),
				);
			}
			$ok = rename( $temp, $marker ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic replace of a plugin-owned file.
			if ( is_file( $temp ) ) {
				wp_delete_file( $temp );
			}
			return array(
				'ok'      => $ok,
				'message' => $ok ? '' : __( 'The owner marker could not be rewritten.', 'wp-checkpoint' ),
			);
		} finally {
			$this->release_lock( $dir, $secret );
		}
	}

	/**
	 * Create the lock file atomically; a stale lock is removed first.
	 *
	 * @param string $dir Directory.
	 * @return string Lock secret, empty when the lock is held by someone else.
	 */
	private function acquire_lock( string $dir ): string {
		$lock = self::lock_path( $dir );
		if ( is_file( $lock ) ) {
			clearstatcache( true, $lock );
			if ( (int) filemtime( $lock ) > time() - self::LOCK_TTL ) {
				return '';
			}
			wp_delete_file( $lock );
		}
		$handle = @fopen( $lock, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- O_EXCL creation is the lock.
		if ( false === $handle ) {
			return '';
		}
		$secret = bin2hex( random_bytes( 16 ) );
		fwrite( $handle, $secret . "\n" . (string) $this->state['install_id'] . "\n" . time() . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see above.
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		return $secret;
	}

	/**
	 * Whether the lock file still holds our secret (re-read every time: another
	 * process may have replaced the file).
	 *
	 * @phpstan-impure
	 * @param string $dir    Directory.
	 * @param string $secret Our secret.
	 * @return bool
	 */
	private function lock_is_mine( string $dir, string $secret ): bool {
		$contents = @file_get_contents( self::lock_path( $dir ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- a missing lock simply means it is not ours.
		if ( ! is_string( $contents ) ) {
			return false;
		}
		$first = strtok( $contents, "\n" );
		return is_string( $first ) && hash_equals( $secret, trim( $first ) );
	}

	/**
	 * Remove the lock if it is still ours.
	 *
	 * @param string $dir    Directory.
	 * @param string $secret Our secret.
	 * @return void
	 */
	private function release_lock( string $dir, string $secret ): void {
		if ( $this->lock_is_mine( $dir, $secret ) ) {
			wp_delete_file( self::lock_path( $dir ) );
		}
	}

	/**
	 * Lock file path.
	 *
	 * @param string $dir Directory.
	 * @return string
	 */
	public static function lock_path( string $dir ): string {
		return $dir . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . self::LOCK_FILE;
	}

	/**
	 * Shape the prechecks result.
	 *
	 * @param string[]             $problems Problems.
	 * @param array<string, mixed> $facts    Facts.
	 * @return array{ok: bool, problems: string[], facts: array<string, mixed>}
	 */
	private function result( array $problems, array $facts ): array {
		return array(
			'ok'       => array() === $problems,
			'problems' => $problems,
			'facts'    => $facts,
		);
	}
}
