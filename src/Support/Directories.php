<?php
/**
 * Locates, creates and validates the plugin's storage directory.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Decides where backups, temporary files and logs live.
 *
 * Preference: a sibling of the WordPress directory outside the document
 * root; otherwise wp-content/wp-checkpoint-{token}/ with deny rules. The
 * choice, a random install ID and the verification result are stored in an
 * installation option; the directory carries an owner marker so a cloned
 * site never writes into the original site's directory.
 */
final class Directories {

	const OPTION     = 'wpcheckpoint_storage';
	const DIR_PREFIX = 'wp-checkpoint-';

	const SOURCE_OUTSIDE = 'outside';
	const SOURCE_CONTENT = 'content';
	const SOURCE_CUSTOM  = 'custom';

	/**
	 * Sub-directories created inside the base directory.
	 *
	 * @var string[]
	 */
	const SUBDIRS = array( 'backups', 'tmp', 'logs' );

	/**
	 * Environment (injectable for tests).
	 *
	 * @var array{abspath: string, content_dir: string, document_root: string, is_web_request: bool, custom_dir: string}
	 */
	private $context;

	/**
	 * Persisted state.
	 *
	 * @var array<string, mixed>
	 */
	private $state;

	/**
	 * Resolved base directory, empty string when unusable, null before resolve().
	 *
	 * @var string|null
	 */
	private $base = null;

	/**
	 * Why the storage is unusable, if it is.
	 *
	 * @var string
	 */
	private $error = '';

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $context Overrides for default_context().
	 */
	public function __construct( array $context = array() ) {
		$this->context = array_merge( self::default_context(), $context );
		$this->state   = self::load_state();
	}

	/**
	 * Environment as seen in the current request.
	 *
	 * @return array{abspath: string, content_dir: string, document_root: string, is_web_request: bool, custom_dir: string}
	 */
	public static function default_context(): array {
		$document_root = '';
		if ( isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) ) {
			$document_root = sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) );
		}
		return array(
			'abspath'        => ABSPATH,
			'content_dir'    => WP_CONTENT_DIR,
			'document_root'  => $document_root,
			'is_web_request' => 'cli' !== PHP_SAPI && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ),
			'custom_dir'     => defined( 'WPCHECKPOINT_STORAGE_DIR' ) ? (string) WPCHECKPOINT_STORAGE_DIR : '',
		);
	}

	/**
	 * Stored state with defaults applied.
	 *
	 * @return array<string, mixed>
	 */
	public static function load_state(): array {
		$stored = Options::get( self::OPTION, array() );
		return array_merge(
			array(
				'install_id'          => '',
				'token'               => '',
				'path'                => '',
				'source'              => '',
				'provisional'         => false,
				'verification'        => array(),
				'clone_detected'      => false,
				'previous_path'       => '',
				'abspath'             => '',
				'previous_abspath'    => '',
				'trusted_deploy_root' => '',
				'auto_reclaimed'      => array(),
			),
			is_array( $stored ) ? $stored : array()
		);
	}

	/**
	 * Whether a token has the expected shape.
	 *
	 * @param mixed $token Candidate.
	 * @return bool
	 */
	public static function is_valid_token( $token ): bool {
		return is_string( $token ) && 1 === preg_match( '/^[a-f0-9]{12}$/', $token );
	}

	/**
	 * Base directory, created on first use. Empty string when unusable.
	 *
	 * @return string
	 */
	public function base(): string {
		if ( null === $this->base ) {
			$this->resolve();
		}
		return (string) $this->base;
	}

	/**
	 * Backups directory or empty string.
	 *
	 * @return string
	 */
	public function backups(): string {
		return $this->subdir( 'backups' );
	}

	/**
	 * Temporary files directory or empty string.
	 *
	 * @return string
	 */
	public function tmp(): string {
		return $this->subdir( 'tmp' );
	}

	/**
	 * Logs directory or empty string.
	 *
	 * @return string
	 */
	public function logs(): string {
		return $this->subdir( 'logs' );
	}

	/**
	 * Current state (after resolving).
	 *
	 * @return array<string, mixed>
	 */
	public function state(): array {
		$this->base();
		return $this->state;
	}

	/**
	 * Reason the storage is unusable, empty when fine.
	 *
	 * @return string
	 */
	public function last_error(): string {
		$this->base();
		return $this->error;
	}

	/**
	 * Run the loopback verification and store the result.
	 *
	 * @return array{status: string, code: int|null, message: string}
	 */
	public function verify_protection(): array {
		$base = $this->base();
		if ( '' === $base ) {
			$result = array(
				'status'  => Protection::STATUS_UNVERIFIED,
				'code'    => null,
				'message' => $this->error,
			);
		} else {
			$result = Protection::verify( $base );
		}
		$this->state['verification'] = array_merge( $result, array( 'checked_at' => time() ) );
		$this->save_state();
		return $result;
	}

	/**
	 * Acknowledge a detected clone so the notice stops.
	 *
	 * @return void
	 */
	public function acknowledge_clone(): void {
		$this->base();
		$this->state['clone_detected']   = false;
		$this->state['previous_path']    = '';
		$this->state['previous_abspath'] = '';
		$this->save_state();
	}

	/**
	 * Environment of this instance.
	 *
	 * @return array{abspath: string, content_dir: string, document_root: string, is_web_request: bool, custom_dir: string}
	 */
	public function context(): array {
		return $this->context;
	}

	/**
	 * Reclaim helper bound to the current state.
	 *
	 * @return StorageReclaim
	 */
	public function reclaim(): StorageReclaim {
		$this->base();
		return new StorageReclaim( $this->state, $this->context );
	}

	/**
	 * Switch back to a reclaimed directory after its marker was rewritten.
	 *
	 * @param string $trusted_root Deployment root to trust from now on ('' keeps the current setting).
	 * @return void
	 */
	public function finish_reclaim( string $trusted_root = '' ): void {
		$this->base();
		$dir = (string) $this->state['previous_path'];
		if ( '' === $dir ) {
			return;
		}
		$abandoned = (string) $this->state['path'];
		if ( '' !== $trusted_root ) {
			$this->state['trusted_deploy_root'] = $trusted_root;
		}
		$this->state['clone_detected']   = false;
		$this->state['previous_path']    = '';
		$this->state['previous_abspath'] = '';
		$this->state['token']            = self::SOURCE_CUSTOM === $this->state['source'] ? $this->state['token'] : substr( basename( $dir ), strlen( self::DIR_PREFIX ) );
		$this->base                      = null;
		$this->adopt( $dir, $this->source_for( $dir ), false );
		$this->save_state();

		if ( '' !== $abandoned && $abandoned !== $dir && is_dir( $abandoned ) && $this->holds_only_plugin_files( $abandoned ) ) {
			Deleter::empty_directory( $abandoned );
			@rmdir( $abandoned ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best effort cleanup of the empty replacement directory.
		}
	}

	/**
	 * Forget the trusted deployment root.
	 *
	 * @return void
	 */
	public function untrust_deploy_root(): void {
		$this->base();
		$this->state['trusted_deploy_root'] = '';
		$this->save_state();
	}

	/**
	 * Clear the automatic take-over notice.
	 *
	 * @return void
	 */
	public function clear_auto_reclaimed(): void {
		$this->base();
		$this->state['auto_reclaimed'] = array();
		$this->save_state();
	}

	/**
	 * Source constant for a directory by its location.
	 *
	 * @param string $dir Directory.
	 * @return string
	 */
	private function source_for( string $dir ): string {
		if ( '' !== $this->context['custom_dir'] && Paths::same( $this->context['custom_dir'], $dir, Paths::is_windows() ) ) {
			return self::SOURCE_CUSTOM;
		}
		return Paths::is_same_or_inside( $this->context['content_dir'], $dir ) ? self::SOURCE_CONTENT : self::SOURCE_OUTSIDE;
	}

	/**
	 * A sub-directory path or empty string.
	 *
	 * @param string $name Sub-directory name.
	 * @return string
	 */
	private function subdir( string $name ): string {
		$base = $this->base();
		return '' === $base ? '' : $base . DIRECTORY_SEPARATOR . $name;
	}

	/**
	 * Decide the base directory.
	 *
	 * @return void
	 */
	private function resolve(): void {
		$this->base = '';

		if ( '' === $this->state['install_id'] ) {
			$this->state['install_id'] = OwnerMarker::generate_id();
			$this->save_state();
		}

		if ( '' !== $this->context['custom_dir'] ) {
			$this->resolve_custom();
			return;
		}

		if ( '' !== $this->state['path'] && self::is_valid_token( $this->state['token'] ) ) {
			$existing = $this->state['path'];
			if ( is_dir( $existing ) ) {
				if ( $this->owns( $existing ) ) {
					$this->adopt( $existing, $this->state['source'], (bool) $this->state['provisional'] );
					$this->maybe_migrate();
					return;
				}
				// Same options, different ABSPATH: a clone, a move, or a new release of a deployment.
				$this->state['clone_detected']   = true;
				$this->state['previous_path']    = $existing;
				$this->state['previous_abspath'] = (string) $this->state['abspath'];
				if ( $this->auto_reclaim( $existing ) ) {
					return;
				}
				$this->state['token']        = '';
				$this->state['verification'] = array();
			}
		}

		$token = self::is_valid_token( $this->state['token'] ) ? $this->state['token'] : bin2hex( random_bytes( 6 ) );
		$this->select( $token );
	}

	/**
	 * Take a sibling release over without asking when the deployment root is trusted.
	 *
	 * @param string $dir The directory recorded in the state.
	 * @return bool
	 */
	private function auto_reclaim( string $dir ): bool {
		$reclaim = new StorageReclaim( $this->state, $this->context );
		if ( ! $reclaim->auto() ) {
			return false;
		}
		$from                          = (string) $this->state['previous_abspath'];
		$this->state['auto_reclaimed'] = array(
			'at'   => time(),
			'from' => $from,
			'to'   => (string) $this->context['abspath'],
		);
		$this->finish_reclaim();
		$this->log_event( sprintf( 'Storage directory %s reclaimed automatically after a deployment: ABSPATH changed from %s to %s.', $dir, $from, (string) $this->context['abspath'] ) );
		return true;
	}

	/**
	 * Append a line to logs/storage.log in the base directory.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function log_event( string $message ): void {
		$logs = $this->logs();
		if ( '' === $logs || ! is_dir( $logs ) ) {
			return;
		}
		$redactor = new Redactor( Redactor::installation_secrets( array( (string) $this->state['token'] ) ) );
		( new Logger( $logs . DIRECTORY_SEPARATOR . 'storage.log', $redactor ) )->info( $message );
	}

	/**
	 * Use the directory from WPCHECKPOINT_STORAGE_DIR.
	 *
	 * The storage token is the identity of the current directory choice
	 * (job ownership, the storage gate, temporary table prefixes, notice
	 * dismissals). A default directory derives it from its own name; a
	 * custom directory has no such name, so one is generated here and kept
	 * in the same state, and a new one is generated whenever the custom
	 * path changes, exactly as choosing a new default directory would. It
	 * is distinct from install_id (the owner marker): that one stays the
	 * same across directory choices, the token does not.
	 *
	 * @return void
	 */
	private function resolve_custom(): void {
		$dir = rtrim( $this->context['custom_dir'], '/\\' );
		if ( '' === $dir ) {
			$this->error = __( 'WPCHECKPOINT_STORAGE_DIR is empty.', 'wp-checkpoint' );
			return;
		}
		$marker = $dir . DIRECTORY_SEPARATOR . OwnerMarker::FILENAME;
		if ( is_file( $marker ) && ! $this->owns( $dir ) ) {
			$this->state['clone_detected'] = true;
			$this->state['previous_path']  = $dir;
			$this->save_state();
			$this->error = __( 'WPCHECKPOINT_STORAGE_DIR belongs to another installation.', 'wp-checkpoint' );
			return;
		}
		if ( ! $this->prepare( $dir ) ) {
			return;
		}
		if ( ! self::is_valid_token( $this->state['token'] ) || $dir !== $this->state['path'] ) {
			$this->state['token'] = bin2hex( random_bytes( 6 ) );
			$this->save_state();
		}
		$this->adopt( $dir, self::SOURCE_CUSTOM, false );
	}

	/**
	 * Pick a new location for a token.
	 *
	 * @param string $token Directory token.
	 * @return void
	 */
	private function select( string $token ): void {
		$formal = $this->context['is_web_request'] && '' !== $this->context['document_root'];

		if ( $formal ) {
			$outside = $this->candidate_outside( $token );
			if ( '' !== $outside && $this->prepare( $outside ) ) {
				$this->state['token'] = $token;
				$this->adopt( $outside, self::SOURCE_OUTSIDE, false );
				return;
			}
		}

		$content = rtrim( $this->context['content_dir'], '/\\' ) . DIRECTORY_SEPARATOR . self::DIR_PREFIX . $token;
		if ( $this->prepare( $content ) ) {
			$this->state['token'] = $token;
			$this->adopt( $content, self::SOURCE_CONTENT, ! $formal );
			return;
		}

		$this->save_state();
	}

	/**
	 * Candidate directory next to the WordPress directory, if that is outside
	 * the document root.
	 *
	 * @param string $token Directory token.
	 * @return string Empty when not eligible.
	 */
	private function candidate_outside( string $token ): string {
		$abspath = realpath( $this->context['abspath'] );
		if ( false === $abspath ) {
			return '';
		}
		$parent = dirname( $abspath );
		if ( $parent === $abspath ) {
			return '';
		}
		if ( Paths::is_same_or_inside( $this->context['document_root'], $parent ) ) {
			return '';
		}
		return $parent . DIRECTORY_SEPARATOR . self::DIR_PREFIX . $token;
	}

	/**
	 * Create the directory tree, protection files and owner marker, and prove
	 * it is writable.
	 *
	 * @param string $dir Base directory.
	 * @return bool
	 */
	private function prepare( string $dir ): bool {
		if ( ! wp_mkdir_p( $dir ) || ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			$this->error = sprintf( /* translators: %s: directory path */ __( 'Cannot create or write to %s.', 'wp-checkpoint' ), $dir );
			return false;
		}

		$probe = $dir . DIRECTORY_SEPARATOR . '.probe-' . bin2hex( random_bytes( 4 ) );
		$nonce = bin2hex( random_bytes( 8 ) );
		try {
			if ( false === file_put_contents( $probe, $nonce, LOCK_EX ) || file_get_contents( $probe ) !== $nonce ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- probing the plugin's own directory.
				$this->error = sprintf( /* translators: %s: directory path */ __( 'Files written to %s cannot be read back.', 'wp-checkpoint' ), $dir );
				return false;
			}
		} finally {
			if ( is_file( $probe ) ) {
				wp_delete_file( $probe );
			}
		}

		foreach ( self::SUBDIRS as $name ) {
			$sub = $dir . DIRECTORY_SEPARATOR . $name;
			if ( ! wp_mkdir_p( $sub ) || ! Protection::write( $sub ) ) {
				$this->error = sprintf( /* translators: %s: directory path */ __( 'Cannot create %s.', 'wp-checkpoint' ), $sub );
				return false;
			}
		}
		if ( ! Protection::write( $dir ) ) {
			$this->error = sprintf( /* translators: %s: directory path */ __( 'Cannot write protection files to %s.', 'wp-checkpoint' ), $dir );
			return false;
		}

		$marker = $dir . DIRECTORY_SEPARATOR . OwnerMarker::FILENAME;
		if ( ! is_file( $marker ) ) {
			$contents = OwnerMarker::build( $this->state['install_id'], $this->context['abspath'] );
			if ( false === file_put_contents( $marker, $contents, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- plugin-owned directory.
				$this->error = __( 'Cannot write the owner marker.', 'wp-checkpoint' );
				return false;
			}
		} elseif ( ! $this->owns( $dir ) ) {
			$this->error = __( 'The directory belongs to another installation.', 'wp-checkpoint' );
			return false;
		}

		$this->error = '';
		return true;
	}

	/**
	 * Whether the owner marker in $dir belongs to this installation.
	 *
	 * @param string $dir Base directory.
	 * @return bool
	 */
	private function owns( string $dir ): bool {
		$marker = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . OwnerMarker::FILENAME;
		if ( ! is_file( $marker ) ) {
			return false;
		}
		$contents = file_get_contents( $marker ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- tiny local file.
		return is_string( $contents ) && OwnerMarker::matches( $contents, (string) $this->state['install_id'], $this->context['abspath'] );
	}

	/**
	 * Record a usable directory.
	 *
	 * @param string $dir         Base directory.
	 * @param string $source      Source constant.
	 * @param bool   $provisional Chosen without a proper web request.
	 * @return void
	 */
	private function adopt( string $dir, string $source, bool $provisional ): void {
		$this->base  = $dir;
		$this->error = '';
		$abspath     = rtrim( Paths::normalize( (string) $this->context['abspath'] ), '/' ) . '/';
		$changed     = $dir !== $this->state['path'] || $source !== $this->state['source'] || $provisional !== (bool) $this->state['provisional'] || $abspath !== $this->state['abspath'];
		if ( $changed ) {
			if ( $dir !== $this->state['path'] ) {
				$this->state['verification'] = array();
			}
			$this->state['path']        = $dir;
			$this->state['source']      = $source;
			$this->state['provisional'] = $provisional;
			$this->state['abspath']     = $abspath;
			$this->save_state();
		}
	}

	/**
	 * Re-evaluate a provisional (CLI/cron) choice during a proper web request:
	 * move to the outside candidate while the directory holds no user files
	 * and no restore is unfinished.
	 *
	 * @return void
	 */
	private function maybe_migrate(): void {
		if ( ! $this->state['provisional'] || self::SOURCE_CONTENT !== $this->state['source'] ) {
			return;
		}
		if ( ! $this->context['is_web_request'] || '' === $this->context['document_root'] ) {
			return;
		}
		if ( false !== $this->restore_unfinished() ) {
			// A restore keeps its files here until it ends, and every driver goes on using this directory: the
			// choice stays provisional and is made after the restore (also when the jobs table cannot be read).
			return;
		}

		$current = (string) $this->base;
		if ( ! $this->holds_only_plugin_files( $current ) ) {
			// User data present: keep the directory, but the choice is final now.
			$this->adopt( $current, self::SOURCE_CONTENT, false );
			return;
		}

		$outside = $this->candidate_outside( (string) $this->state['token'] );
		if ( '' === $outside || ! $this->prepare( $outside ) ) {
			$this->adopt( $current, self::SOURCE_CONTENT, false );
			return;
		}

		Deleter::empty_directory( $current );
		@rmdir( $current ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best effort cleanup of the empty provisional directory.
		$this->adopt( $outside, self::SOURCE_OUTSIDE, false );
	}

	/**
	 * Whether a restore job is unfinished: true, false, or null when that cannot be read.
	 *
	 * @return bool|null
	 */
	private function restore_unfinished() {
		if ( isset( $this->context['restore_unfinished'] ) && is_callable( $this->context['restore_unfinished'] ) ) {
			return call_user_func( $this->context['restore_unfinished'] ); // Tests.
		}
		return \WPCheckpoint\Jobs\JobRepository::has_unfinished( \WPCheckpoint\Jobs\RestoreJob::ID );
	}

	/**
	 * Whether a base directory contains nothing but the files this class writes.
	 *
	 * @param string $dir Base directory.
	 * @return bool
	 */
	private function holds_only_plugin_files( string $dir ): bool {
		$own = array( 'index.php', '.htaccess', OwnerMarker::FILENAME );
		foreach ( self::SUBDIRS as $sub ) {
			$entries = @scandir( $dir . DIRECTORY_SEPARATOR . $sub ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing sub-directory counts as empty.
			foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
				if ( ! in_array( $entry, array( '.', '..', 'index.php', '.htaccess' ), true ) ) {
					return false;
				}
			}
		}
		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
			if ( ! in_array( $entry, array_merge( array( '.', '..' ), $own, self::SUBDIRS ), true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Persist the state.
	 *
	 * @return void
	 */
	private function save_state(): void {
		Options::set( self::OPTION, $this->state );
	}
}
