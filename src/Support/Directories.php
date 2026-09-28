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

	const OPTION     = StoredNames::STORAGE;
	const DIR_PREFIX = 'wp-checkpoint-';

	/**
	 * Past storage tokens kept (own_tokens()).
	 */
	const PAST_TOKENS = 10;

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
	 * @var array{abspath: string, content_dir: string, document_root: string, is_web_request: bool, custom_dir: string, wordpress_dirs: array{within: array<string, string>, itself: array<string, string>}|null, after_marker: callable|null}
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
	 * The wordpress_dirs entry is null here: WordPress's own directories (wordpress_dirs()) are looked up only when a custom
	 * directory is checked. Tests pass stand-ins, and an after_marker callable (called in prepare() right after the owner
	 * marker is written: a request that dies there).
	 *
	 * @return array{abspath: string, content_dir: string, document_root: string, is_web_request: bool, custom_dir: string, wordpress_dirs: array{within: array<string, string>, itself: array<string, string>}|null, after_marker: callable|null}
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
			'wordpress_dirs' => null,
			'after_marker'   => null,
		);
	}

	/**
	 * WordPress's own directories, which a custom storage directory may not be, lie in or hold (StorageLocation):
	 * wp-admin, wp-includes and the content groups (plugins, must-use plugins, themes, uploads, languages, upgrade,
	 * the update backups, fonts), where WordPress resolves them now (UPLOADS, WPMU_PLUGIN_DIR and the like moved);
	 * and the content directory itself, which may hold one (inside it, a directory of none of these may be used).
	 * On a network, the main site's uploads and blogs.dir too. Not covered: an uploads directory moved for a single
	 * site of a network other than the main one.
	 *
	 * @return array{within: array<string, string>, itself: array<string, string>} directory => label.
	 */
	public static function wordpress_dirs(): array {
		$within = array();
		$add    = function ( string $dir, string $label ) use ( &$within ): void {
			if ( '' !== $dir && ! isset( $within[ $dir ] ) ) {
				$within[ $dir ] = $label;
			}
		};
		$add( ABSPATH . 'wp-admin', __( 'the wp-admin directory', 'wp-checkpoint' ) );
		$add( ABSPATH . 'wp-includes', __( 'the wp-includes directory', 'wp-checkpoint' ) ); // WPINC, which core never changes.
		$add( WP_PLUGIN_DIR, __( 'the plugins directory', 'wp-checkpoint' ) );
		$add( WPMU_PLUGIN_DIR, __( 'the must-use plugins directory', 'wp-checkpoint' ) );
		$add( (string) get_theme_root(), __( 'a themes directory', 'wp-checkpoint' ) );
		foreach ( (array) ( $GLOBALS['wp_theme_directories'] ?? array() ) as $themes ) {
			$add( (string) $themes, __( 'a themes directory', 'wp-checkpoint' ) );
		}
		$add( (string) wp_upload_dir( null, false )['basedir'], __( 'the uploads directory', 'wp-checkpoint' ) );
		if ( is_multisite() ) {
			if ( ! is_main_site() ) {
				switch_to_blog( get_main_site_id() );
				$add( (string) wp_upload_dir( null, false )['basedir'], __( 'the uploads directory', 'wp-checkpoint' ) );
				restore_current_blog();
			}
			$add( WP_CONTENT_DIR . '/blogs.dir', __( 'the uploads directory', 'wp-checkpoint' ) );
		}
		$add( WP_LANG_DIR, __( 'the languages directory', 'wp-checkpoint' ) );
		$add( WP_CONTENT_DIR . '/upgrade', __( 'the upgrade directory', 'wp-checkpoint' ) );
		$add( WP_CONTENT_DIR . '/upgrade-temp-backup', __( 'the directory WordPress keeps backups in during updates', 'wp-checkpoint' ) );
		if ( function_exists( 'wp_get_font_dir' ) ) {
			$add( (string) wp_get_font_dir()['basedir'], __( 'the fonts directory', 'wp-checkpoint' ) );
		}
		return array(
			'within' => $within,
			'itself' => array( WP_CONTENT_DIR => __( 'the content directory (wp-content)', 'wp-checkpoint' ) ),
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
				'past_tokens'         => array(),
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
	 * @return array{abspath: string, content_dir: string, document_root: string, is_web_request: bool, custom_dir: string, wordpress_dirs: array{within: array<string, string>, itself: array<string, string>}|null, after_marker: callable|null}
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
			$this->empty_directory( $abandoned );
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
				$this->state['past_tokens']  = array(); // The original installation's (own_tokens()).
				$this->state['verification'] = array();
			} elseif ( $this->restore_keeps( $existing ) && ! Paths::positively_gone( $existing ) ) {
				// Not reachable from this request (open_basedir, permissions), yet not shown to be gone, and a
				// restore keeps its files there (or that cannot be read): no other directory is chosen meanwhile.
				$this->error = __( 'The storage directory cannot be reached from this request, and a restore may be keeping its files there, so no other directory is chosen. Jobs continue from a request that can reach it.', 'wp-checkpoint' );
				return;
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
	 * Deleter::empty_directory(), with a refused path logged: nothing was deleted, and resolving the storage goes on.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private function empty_directory( string $dir ): void {
		try {
			Deleter::empty_directory( $dir );
		} catch ( DeletionRefused $e ) {
			$this->log_event( $e->getMessage() );
		}
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
		$refused = Deleter::storage_refusal( $this->context['custom_dir'] );
		if ( '' !== $refused ) {
			// Nothing in it could ever be deleted (reclaim, purge, uninstall): refused before anything is written there.
			// The constant as set, before any trimming (a drive root keeps its separator).
			$this->error = Deleter::NOT_A_FULL_PATH === $refused
				? __( 'WPCHECKPOINT_STORAGE_DIR must be an absolute path, without . or .. segments unless the directory exists. Set it to the full path of a directory of its own.', 'wp-checkpoint' )
				: __( 'WPCHECKPOINT_STORAGE_DIR names the root of the file system, a WordPress directory or a directory that holds one. Set it to a directory of its own, for example a new directory next to the WordPress directory. Backups already stored there stay in its backups sub-directory; move them to the new directory by hand.', 'wp-checkpoint' );
			return;
		}
		$wordpress = null === $this->context['wordpress_dirs'] ? self::wordpress_dirs() : $this->context['wordpress_dirs'];
		$label     = StorageLocation::refusal( $this->context['custom_dir'], $wordpress['within'], $wordpress['itself'] );
		if ( '' !== $label ) {
			// What the plugin writes there (index.php, .htaccess denying access) would change what WordPress serves.
			$this->error = in_array( $label, $wordpress['itself'], true )
				? sprintf( /* translators: %s: which directory, e.g. "the content directory (wp-content)" */ __( 'WPCHECKPOINT_STORAGE_DIR names %s or a directory that holds it. The files WP Checkpoint writes into its storage directory would change what WordPress serves from there. Set it to a new directory of its own, for example next to the WordPress directory or inside wp-content. If it was the storage directory before, its backups stay in its backups sub-directory; move them to the new one by hand.', 'wp-checkpoint' ), $label )
				: sprintf( /* translators: %s: which directory, e.g. "the uploads directory" */ __( 'WPCHECKPOINT_STORAGE_DIR names %s, a directory inside it or one that holds it. The files WP Checkpoint writes into its storage directory would change what WordPress serves from there. Set it to a new directory of its own, for example next to the WordPress directory or inside wp-content. If it was the storage directory before, its backups stay in its backups sub-directory; move them to the new one by hand.', 'wp-checkpoint' ), $label );
			return;
		}
		$dir = rtrim( $this->context['custom_dir'], '/\\' );
		if ( '' === $dir ) {
			$this->error = __( 'WPCHECKPOINT_STORAGE_DIR is empty.', 'wp-checkpoint' );
			return;
		}
		$marker = $dir . DIRECTORY_SEPARATOR . OwnerMarker::FILENAME;
		if ( is_file( $marker ) && ! $this->owns( $dir ) && ! $this->marker_unfinished( $dir ) ) {
			$this->state['clone_detected'] = true;
			$this->state['previous_path']  = $dir;
			$this->state['past_tokens']    = array(); // The original installation's (own_tokens()).
			$this->save_state();
			$this->error = __( 'WPCHECKPOINT_STORAGE_DIR belongs to another installation.', 'wp-checkpoint' );
			return;
		}
		$moved = '' !== (string) $this->state['path'] && ! Paths::same_location( $dir, (string) $this->state['path'] );
		$token = '';
		if ( $moved ) {
			// A restore keeps its files in the directory it was started with (its row's storage_path): another
			// directory here (the constant set differently for WP-CLI and for the web server) must not become
			// the choice while that directory is there. Nothing is created, re-tokened or saved.
			$restores = $this->restores();
			if ( null === $restores ) {
				$this->error = __( 'Whether a restore is in progress cannot be read from the database, so this request does not switch to the storage directory WPCHECKPOINT_STORAGE_DIR names. Try again once the database answers.', 'wp-checkpoint' );
				return;
			}
			$listings = array();
			foreach ( $restores as $restore ) {
				if ( Paths::same_location( $dir, $restore['path'] ) ) {
					$token = $restore['token']; // Its own directory: taken back with its own token.
					break;
				}
			}
			if ( '' === $token ) {
				foreach ( $restores as $restore ) {
					if ( ! Paths::positively_gone( $restore['path'], $listings ) ) {
						$this->error = __( 'A restore is in progress in a storage directory that this request cannot confirm to be the one WPCHECKPOINT_STORAGE_DIR names (for example, the constant is set differently for WP-CLI and for the web server). Set it to the directory the restore was started with, or wait until the restore has ended.', 'wp-checkpoint' );
						return;
					}
				}
			}
		}
		if ( ! $this->prepare( $dir ) ) {
			return;
		}
		$before = (string) $this->state['token'];
		if ( self::is_valid_token( $token ) ) {
			$this->state['token'] = $token;
		} elseif ( ! self::is_valid_token( $this->state['token'] ) || $moved ) {
			$this->state['token'] = bin2hex( random_bytes( 6 ) );
		}
		// The token and the path are saved together (one write): never a restore's token on another path.
		$this->adopt( $dir, self::SOURCE_CUSTOM, false, $before !== $this->state['token'] );
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
	 * Only a directory that does not exist yet, one that is empty, or one
	 * that carries this installation's owner marker is written into
	 * (unowned()); anything else is refused before the first write. The
	 * marker is the first thing written, with exclusive creation, so a
	 * directory the plugin created is marked as its own before it holds
	 * anything else: a request that dies after creating it leaves an empty
	 * directory, and one that dies while writing the marker leaves nothing
	 * but a marker holding the start of this installation's (both taken up
	 * again by the next request).
	 *
	 * @param string $dir Base directory.
	 * @return bool
	 */
	private function prepare( string $dir ): bool {
		$unowned = $this->unowned( $dir );
		if ( '' !== $unowned ) {
			$this->error = $unowned;
			return false;
		}
		if ( ! wp_mkdir_p( $dir ) || ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			$this->error = sprintf( /* translators: %s: directory path */ __( 'Cannot create or write to %s.', 'wp-checkpoint' ), $dir );
			return false;
		}
		if ( ! $this->mark( $dir ) ) {
			return false;
		}
		if ( null !== $this->context['after_marker'] ) {
			call_user_func( $this->context['after_marker'], $dir );
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

		$this->error = '';
		return true;
	}

	/**
	 * Why nothing may be written into $dir, or '' when it may: it does not exist yet (or is not a directory, which
	 * creating it will report), it is empty, it carries this installation's owner marker, or it holds nothing but an
	 * unfinished one (marker_unfinished()). A directory whose contents cannot be listed is refused: it cannot be shown
	 * to be empty.
	 *
	 * @param string $dir Directory.
	 * @return string
	 */
	private function unowned( string $dir ): string {
		clearstatcache();
		if ( ! @is_dir( $dir ) || $this->owns( $dir ) || $this->marker_unfinished( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir warnings would name the path.
			return '';
		}
		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		if ( false === $entries ) {
			return sprintf( /* translators: %s: directory path */ __( 'What %s holds cannot be listed, so it cannot be shown to be empty or to be WP Checkpoint\'s own; nothing is written there. Make it readable, or use a directory that does not exist yet (WP Checkpoint creates it).', 'wp-checkpoint' ), $dir );
		}
		$entries = array_values( array_diff( $entries, array( '.', '..' ) ) );
		if ( array() === $entries ) {
			return '';
		}
		if ( in_array( OwnerMarker::FILENAME, $entries, true ) ) {
			return __( 'The directory belongs to another installation.', 'wp-checkpoint' );
		}
		return sprintf( /* translators: %s: directory path */ __( '%s already holds files and does not carry WP Checkpoint\'s owner marker, so nothing is written there. Use a directory that does not exist yet (WP Checkpoint creates it) or an empty one.', 'wp-checkpoint' ), $dir );
	}

	/**
	 * Whether $dir holds nothing but an owner marker whose contents are the start of this installation's (empty
	 * included): what a request that died while writing the marker leaves behind. The marker is written in one call
	 * right after the directory is created, before anything else.
	 *
	 * @param string $dir Directory.
	 * @return bool
	 */
	private function marker_unfinished( string $dir ): bool {
		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir warnings would name the path.
		if ( false === $entries || array( OwnerMarker::FILENAME ) !== array_values( array_diff( $entries, array( '.', '..' ) ) ) ) {
			return false;
		}
		$contents = @file_get_contents( $dir . DIRECTORY_SEPARATOR . OwnerMarker::FILENAME ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- as above; a tiny local file.
		return is_string( $contents ) && OwnerMarker::is_unfinished( $contents, (string) $this->state['install_id'], $this->context['abspath'] );
	}

	/**
	 * Write this installation's owner marker into $dir unless it is there (exclusive creation); an unfinished one is
	 * replaced first.
	 *
	 * @param string $dir Directory.
	 * @return bool Whether the directory carries this installation's marker now.
	 */
	private function mark( string $dir ): bool {
		$marker = $dir . DIRECTORY_SEPARATOR . OwnerMarker::FILENAME;
		if ( $this->owns( $dir ) ) {
			return true; // The usual case: a directory prepared before.
		}
		if ( $this->marker_unfinished( $dir ) ) {
			wp_delete_file( $marker );
		}
		if ( OwnerMarker::create( $marker, OwnerMarker::build( (string) $this->state['install_id'], $this->context['abspath'] ) ) ) {
			return true;
		}
		clearstatcache( true, $marker );
		if ( $this->owns( $dir ) ) {
			return true; // Written meanwhile by another request of this installation.
		}
		// create() removes a marker it could not write in full: one still there was written by someone else.
		$this->error = is_file( $marker ) && ! $this->marker_unfinished( $dir ) ? __( 'The directory belongs to another installation.', 'wp-checkpoint' ) : __( 'Cannot write the owner marker.', 'wp-checkpoint' );
		return false;
	}

	/**
	 * Whether the owner marker in $dir belongs to this installation. It reads the file system on every call (another
	 * request may have written the marker meanwhile).
	 *
	 * @phpstan-impure
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
	 * @param bool   $save        Whether the state changed before (the token), so it is saved with the path.
	 * @return void
	 */
	private function adopt( string $dir, string $source, bool $provisional, bool $save = false ): void {
		$this->base  = $dir;
		$this->error = '';
		$abspath     = rtrim( Paths::normalize( (string) $this->context['abspath'] ), '/' ) . '/';
		if ( '' !== (string) $this->state['path'] && $dir !== $this->state['path'] && Paths::same_location( $dir, (string) $this->state['path'] ) ) {
			$dir = (string) $this->state['path']; // The same directory spelled another way: the stored spelling stays.
		}
		$changed = $save || $dir !== $this->state['path'] || $source !== $this->state['source'] || $provisional !== (bool) $this->state['provisional'] || $abspath !== $this->state['abspath'];
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
	 * and no job is unfinished.
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
		if ( false !== $this->jobs_unfinished() ) {
			// A job keeps its files here until it ends (a restore above all), and every driver goes on using this
			// directory: the choice stays provisional and is made once no job is left (also when the jobs table
			// cannot be read).
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

		$this->empty_directory( $current );
		@rmdir( $current ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best effort cleanup of the empty provisional directory.
		$this->adopt( $outside, self::SOURCE_OUTSIDE, false );
	}

	/**
	 * The directory and token of each unfinished restore, or null when that cannot be read (JobRepository, or
	 * the answer a test gives in the context: key "restores", a callable; internal, for tests only).
	 *
	 * @return array<int, array{path: string, token: string}>|null
	 */
	private function restores() {
		if ( isset( $this->context['restores'] ) && is_callable( $this->context['restores'] ) ) {
			return call_user_func( $this->context['restores'] );
		}
		return \WPCheckpoint\Jobs\JobRepository::unfinished_restores();
	}

	/**
	 * Whether an unfinished restore keeps its files in $dir, or that cannot be read.
	 *
	 * @param string $dir Directory.
	 * @return bool
	 */
	private function restore_keeps( string $dir ): bool {
		$restores = $this->restores();
		if ( null === $restores ) {
			return true;
		}
		foreach ( $restores as $restore ) {
			// The same location, or a restore whose directory this request cannot resolve either (or that names
			// none, or a relative one): that could be $dir spelled another way, and nothing shows it is not.
			$path     = $restore['path'];
			$relative = '' === $path || ( '/' !== Paths::normalize( $path )[0] && 1 !== preg_match( '#^[A-Za-z]:/#', Paths::normalize( $path ) ) );
			if ( $relative || Paths::same_location( $path, $dir ) || ( false === @realpath( $path ) && ! Paths::positively_gone( $path ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would put the path into the error log.
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether any job is unfinished: true, false, or null when that cannot be read.
	 *
	 * @return bool|null
	 */
	private function jobs_unfinished() {
		return $this->unfinished( null );
	}

	/**
	 * JobRepository::has_unfinished(), or the answer a test gives in the context (key "unfinished":
	 * function( ?string $type ): ?bool; internal, for tests only).
	 *
	 * @param string|null $type Job type, or null for any.
	 * @return bool|null
	 */
	private function unfinished( $type ) {
		if ( isset( $this->context['unfinished'] ) && is_callable( $this->context['unfinished'] ) ) {
			return call_user_func( $this->context['unfinished'], $type );
		}
		return \WPCheckpoint\Jobs\JobRepository::has_unfinished( $type );
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
		// A token this installation used before (its custom directory moved, a restore's token taken back) still
		// names its staging roots and probes next to the site (Residue::scan_site()): it is kept, a few of them.
		// Not after a clone was detected: the previous token is then the original installation's.
		$saved = self::load_state()['token'];
		if ( self::is_valid_token( $saved ) && $saved !== $this->state['token'] && empty( $this->state['clone_detected'] ) ) {
			$past = array_values( array_diff( (array) $this->state['past_tokens'], array( $saved, (string) $this->state['token'] ) ) );
			array_unshift( $past, $saved );
			$this->state['past_tokens'] = array_slice( $past, 0, self::PAST_TOKENS );
		}
		Options::set( self::OPTION, $this->state );
	}

	/**
	 * This installation's storage tokens, the current one first: the ones its staging roots and probes may carry.
	 * None while a clone is detected: the tokens in the copied state may be the original installation's (a custom
	 * directory keeps the original's current token while refusing it), so nothing is claimed until the notice is
	 * resolved; the earlier ones are dropped when the clone is detected.
	 *
	 * @param array<string, mixed>|null $state Stored state (load_state()), or null to load it.
	 * @return string[]
	 */
	public static function own_tokens( $state = null ): array {
		$state = is_array( $state ) ? $state : self::load_state();
		if ( ! empty( $state['clone_detected'] ) ) {
			return array();
		}
		$tokens = array_merge( array( (string) $state['token'] ), (array) $state['past_tokens'] );
		return array_values( array_unique( array_filter( array_map( 'strval', $tokens ), array( __CLASS__, 'is_valid_token' ) ) ) );
	}
}
