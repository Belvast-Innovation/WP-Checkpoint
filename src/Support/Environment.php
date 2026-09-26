<?php
/**
 * Collects environment checks for the Tools tab and the text report.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

use WPCheckpoint\Archive\Packer;
use WPCheckpoint\Rest\Controller;
use WPCheckpoint\Rest\ProbeController;

defined( 'ABSPATH' ) || exit;

/**
 * Cheap facts (versions, extensions, limits, writability) are read live.
 * The database size and the loopback probe are cached installation-wide for
 * twelve hours in a site transient; "Re-check" clears the cache.
 *
 * Probes are injectable so tests can simulate a missing extension or a
 * particular loopback outcome.
 */
final class Environment {

	const CACHE     = 'wpcheckpoint_environment';
	const CACHE_TTL = 43200;

	/**
	 * Sub-directory networks with more sites than this are not enumerated for
	 * the report: the path list would make masking expensive (and a PCRE
	 * failure would withhold the whole report), so a coarse rule is used.
	 * Same order of magnitude as UninstallSetting::SCAN_LIMIT.
	 */
	const SITE_PATH_LIMIT = 50;

	/**
	 * Sites fetched per page while enumerating a network.
	 */
	const SITE_BATCH_SIZE = 500;

	const GROUP_WORDPRESS = 'wordpress';
	const GROUP_PHP       = 'php';
	const GROUP_LIMITS    = 'limits';
	const GROUP_DATABASE  = 'database';
	const GROUP_STORAGE   = 'storage';
	const GROUP_LOOPBACK  = 'loopback';
	const GROUP_SERVER    = 'server';

	/**
	 * Storage directories.
	 *
	 * @var Directories
	 */
	private $directories;

	/**
	 * Probe callables.
	 *
	 * @var array<string, callable>
	 */
	private $probes;

	/**
	 * Constructor.
	 *
	 * @param Directories             $directories Storage directories.
	 * @param array<string, callable> $probes      Overrides for default_probes().
	 */
	public function __construct( Directories $directories, array $probes = array() ) {
		$this->directories = $directories;
		$this->probes      = array_merge( self::default_probes(), $probes );
	}

	/**
	 * Real probes.
	 *
	 * @return array<string, callable>
	 */
	public static function default_probes(): array {
		return array(
			'extension_loaded' => 'extension_loaded',
			'class_exists'     => 'class_exists',
			'ini_get'          => 'ini_get',
			'int_size'         => static function (): int {
				return PHP_INT_SIZE;
			},
			// The one way this plugin reads free and total disk space (HostFunctions guards the calls).
			'disk_free_space'  => static function ( string $dir ) {
				return HostFunctions::disk_free_space( $dir );
			},
			'disk_total_space' => static function ( string $dir ) {
				return HostFunctions::disk_total_space( $dir );
			},
			'db_server_info'   => static function (): string {
				global $wpdb;
				return (string) $wpdb->db_server_info();
			},
			'db_size'          => static function () {
				return self::query_database_size();
			},
			'loopback'         => static function ( string $challenge ) {
				return self::request_probe( $challenge );
			},
		);
	}

	/**
	 * Whether a function exists and is not listed in disable_functions.
	 *
	 * @param string $name Function name.
	 * @return bool
	 */
	public static function function_available( string $name ): bool {
		return HostFunctions::available( $name );
	}

	/**
	 * Limits of the current process: what a request like this one gets.
	 *
	 * @return array{memory_limit: string, memory_bytes: int, max_execution_time: int, set_time_limit: bool}
	 */
	public static function runtime_values(): array {
		$memory  = (string) ini_get( 'memory_limit' );
		$seconds = (int) ini_get( 'max_execution_time' );
		$extend  = false;
		// Re-applies the current limit only to learn whether the call is allowed.
		$extend = HostFunctions::set_time_limit( $seconds );
		return array(
			'memory_limit'       => $memory,
			'memory_bytes'       => (int) wp_convert_hr_to_bytes( $memory ),
			'max_execution_time' => $seconds,
			'set_time_limit'     => $extend,
		);
	}

	/**
	 * All checks in display order.
	 *
	 * @param bool $refresh Ignore and replace the cached probe results.
	 * @return Check[]
	 */
	public function checks( bool $refresh = false ): array {
		$cached = $this->cached( $refresh );

		return array_merge(
			$this->wordpress_checks(),
			$this->php_checks(),
			$this->limit_checks( $cached['loopback'] ),
			$this->database_checks( $cached['db'] ),
			$this->storage_checks(),
			$this->loopback_checks( $cached['loopback'] ),
			$this->server_checks()
		);
	}

	/**
	 * When the cached probes were last run (0 = never).
	 *
	 * @return int
	 */
	public function checked_at(): int {
		$cache = get_site_transient( self::CACHE );
		return is_array( $cache ) && isset( $cache['checked_at'] ) ? (int) $cache['checked_at'] : 0;
	}

	/**
	 * Drop the cached probe results.
	 *
	 * @return void
	 */
	public static function invalidate(): void {
		delete_site_transient( self::CACHE );
	}

	/**
	 * Placeholders for the report: known directories, longest first is
	 * handled by Report::mask_paths().
	 *
	 * @return array<string, string>
	 */
	public function report_paths(): array {
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
	 * Host names that identify the site; the report replaces them.
	 *
	 * @return string[]
	 */
	public static function report_hosts(): array {
		$hosts = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				$hosts[] = $host;
			}
		}
		if ( is_multisite() ) {
			// The network domain covers every sub-site of a sub-domain network.
			$network = get_network();
			if ( $network && is_string( $network->domain ) && '' !== $network->domain ) {
				$hosts[] = (string) preg_replace( '/:\d+$/', '', $network->domain );
			}
		}
		return array_values( array_unique( $hosts ) );
	}

	/**
	 * URL path prefixes that identify a site for the report.
	 *
	 * "paths" holds the sub-directory this site is installed in and, on a
	 * sub-directory network up to $limit sites, every site's path (collected
	 * in pages of $batch_size). Larger sub-directory networks set "coarse":
	 * the report then masks the first path segment after the site host
	 * instead of matching known prefixes. "network_root" is the network's own
	 * path when WordPress itself lives in a sub-directory ("" otherwise).
	 *
	 * @param int      $limit      Maximum number of sites to enumerate.
	 * @param int      $batch_size Sites per page.
	 * @param int|null $site_count Number of sites; null queries the network (tests inject it).
	 * @return array{paths: string[], coarse: bool, network_root: string}
	 */
	public static function report_site_paths( int $limit = self::SITE_PATH_LIMIT, int $batch_size = self::SITE_BATCH_SIZE, $site_count = null ): array {
		$paths = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$path = wp_parse_url( $url, PHP_URL_PATH );
			if ( is_string( $path ) && '' !== trim( $path, '/' ) ) {
				$paths[] = '/' . trim( $path, '/' );
			}
		}

		$result = array(
			'paths'        => $paths,
			'coarse'       => false,
			'network_root' => '',
		);
		if ( ! is_multisite() ) {
			$result['paths'] = array_values( array_unique( $paths ) );
			return $result;
		}

		$network = get_network();
		if ( $network && '' !== trim( (string) $network->path, '/' ) ) {
			$result['network_root'] = '/' . trim( (string) $network->path, '/' );
		}
		if ( null === $site_count ) {
			$site_count = (int) get_sites( array( 'count' => true ) );
		}
		if ( self::use_coarse_site_paths( true, is_subdomain_install(), $site_count, $limit ) ) {
			$result['coarse'] = true;
			$result['paths']  = array_values( array_unique( $paths ) );
			return $result;
		}

		if ( ! is_subdomain_install() ) {
			$batch_size = max( 1, $batch_size );
			$offset     = 0;
			do {
				$sites   = get_sites(
					array(
						'number'  => $batch_size,
						'offset'  => $offset,
						'orderby' => 'id',
						'order'   => 'ASC',
					)
				);
				$fetched = count( $sites );
				foreach ( $sites as $site ) {
					if ( '' !== trim( (string) $site->path, '/' ) ) {
						$paths[] = '/' . trim( (string) $site->path, '/' );
					}
				}
				$offset += $batch_size;
			} while ( $fetched === $batch_size );
		}

		$result['paths'] = array_values( array_unique( $paths ) );
		return $result;
	}

	/**
	 * Whether the report must fall back to coarse site-path masking: only a
	 * sub-directory network with more sites than the limit. Single sites have
	 * no site paths; sub-domain networks distinguish sites by host, which the
	 * host masking already covers.
	 *
	 * @param bool $multisite  is_multisite().
	 * @param bool $subdomain  is_subdomain_install().
	 * @param int  $site_count Number of sites.
	 * @param int  $limit      Enumeration limit.
	 * @return bool
	 */
	public static function use_coarse_site_paths( bool $multisite, bool $subdomain, int $site_count, int $limit = self::SITE_PATH_LIMIT ): bool {
		return $multisite && ! $subdomain && $site_count > $limit;
	}

	/**
	 * The task runtime limits measured by the last loopback probe, without
	 * probing: null when nothing is cached (the runner then assumes limits).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function cached_runtime() {
		$cache = get_site_transient( self::CACHE );
		if ( is_array( $cache ) && isset( $cache['loopback']['runtime'] ) && is_array( $cache['loopback']['runtime'] ) && array() !== $cache['loopback']['runtime'] ) {
			return $cache['loopback']['runtime'];
		}
		return null;
	}

	/**
	 * Cached probe results, refreshed when missing, expired or forced.
	 *
	 * @param bool $refresh Force a refresh.
	 * @return array{checked_at: int, db: array<string, mixed>, loopback: array<string, mixed>}
	 */
	private function cached( bool $refresh ): array {
		$cache = $refresh ? false : get_site_transient( self::CACHE );
		if ( is_array( $cache ) && isset( $cache['db'], $cache['loopback'], $cache['checked_at'] ) ) {
			return $cache;
		}
		$cache = array(
			'checked_at' => time(),
			'db'         => array(
				'server_info' => (string) call_user_func( $this->probes['db_server_info'] ),
				'size'        => call_user_func( $this->probes['db_size'] ),
			),
			'loopback'   => $this->loopback(),
		);
		set_site_transient( self::CACHE, $cache, self::CACHE_TTL );
		return $cache;
	}

	/**
	 * Run the loopback probe against the plugin's own REST route.
	 *
	 * @return array{outcome: string, code: int|null, message: string, runtime: array<string, mixed>}
	 */
	public function loopback(): array {
		$challenge = ProbeController::issue_challenge();
		try {
			$response = call_user_func( $this->probes['loopback'], $challenge );
		} finally {
			ProbeController::revoke_challenge( $challenge );
		}

		if ( is_wp_error( $response ) ) {
			return self::loopback_result( 'unreachable', null, $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 401 === $code ) {
			return self::loopback_result( 'http_auth', $code, __( 'The site requires HTTP authentication, so it cannot call itself.', 'wp-checkpoint' ) );
		}
		if ( $code >= 300 && $code < 400 ) {
			$location = (string) wp_remote_retrieve_header( $response, 'location' );
			return self::loopback_result(
				'redirected',
				$code,
				sprintf(
					/* translators: %s: redirect target URL */
					__( 'The request was redirected to %s. Check that the WordPress Address and Site Address under Settings → General match how the site is actually reached (http vs https, www vs non-www).', 'wp-checkpoint' ),
					'' === $location ? __( '(no Location header)', 'wp-checkpoint' ) : $location
				)
			);
		}
		if ( 200 === $code ) {
			if ( is_array( $body ) && isset( $body['challenge'] ) && hash_equals( $challenge, (string) $body['challenge'] ) ) {
				return self::loopback_result( 'reachable', $code, __( 'The site can reach its own REST API.', 'wp-checkpoint' ), $body );
			}
			return self::loopback_result( 'altered', $code, __( 'The response did not contain the expected value; a cache, CDN or firewall may be answering instead of WordPress.', 'wp-checkpoint' ) );
		}
		if ( in_array( $code, array( 403, 429, 503 ), true ) ) {
			return self::loopback_result( 'blocked', $code, __( 'The request was blocked, probably by a firewall or rate limit.', 'wp-checkpoint' ) );
		}
		/* translators: %d: HTTP status code */
		return self::loopback_result( 'unreachable', $code, sprintf( __( 'Unexpected HTTP status %d.', 'wp-checkpoint' ), $code ) );
	}

	/**
	 * Perform the real HTTP probe.
	 *
	 * @param string $challenge One-time value.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function request_probe( string $challenge ) {
		$url = rest_url( Controller::ROUTE_NAMESPACE . '/' . ProbeController::ROUTE );
		return wp_remote_post(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false, $url ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, as used by WP_Site_Health.
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
				'body'        => array( 'challenge' => $challenge ),
			)
		);
	}

	/**
	 * Shape a loopback result.
	 *
	 * @param string               $outcome Outcome key.
	 * @param int|null             $code    HTTP status.
	 * @param string               $message Explanation.
	 * @param array<string, mixed> $body    Decoded probe body.
	 * @return array{outcome: string, code: int|null, message: string, runtime: array<string, mixed>}
	 */
	private static function loopback_result( string $outcome, $code, string $message, array $body = array() ): array {
		$runtime = array();
		foreach ( array( 'memory_limit', 'memory_bytes', 'max_execution_time', 'set_time_limit' ) as $key ) {
			if ( array_key_exists( $key, $body ) ) {
				$runtime[ $key ] = $body[ $key ];
			}
		}
		return array(
			'outcome' => $outcome,
			'code'    => $code,
			'message' => $message,
			'runtime' => $runtime,
		);
	}

	/**
	 * Size of the installation's tables (base prefix on multisite).
	 *
	 * @return int|null Bytes, or null when unavailable.
	 */
	public static function query_database_size() {
		global $wpdb;
		$like = $wpdb->esc_like( self::table_prefix() ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- information_schema aggregate; the result is cached for 12 hours in a site transient by cached().
		$size = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name LIKE %s',
				$like
			)
		);
		return null === $size ? null : (int) $size;
	}

	/**
	 * Prefix that covers every table of this installation.
	 *
	 * @return string
	 */
	public static function table_prefix(): string {
		global $wpdb;
		return is_multisite() ? (string) $wpdb->base_prefix : (string) $wpdb->prefix;
	}

	/**
	 * WordPress facts.
	 *
	 * @return Check[]
	 */
	private function wordpress_checks(): array {
		return array(
			new Check( 'wordpress.version', self::GROUP_WORDPRESS, __( 'WordPress version', 'wp-checkpoint' ), get_bloginfo( 'version' ), Check::INFO ),
			new Check( 'wordpress.multisite', self::GROUP_WORDPRESS, __( 'Multisite', 'wp-checkpoint' ), is_multisite() ? __( 'yes', 'wp-checkpoint' ) : __( 'no', 'wp-checkpoint' ), Check::INFO ),
			new Check( 'wordpress.locale', self::GROUP_WORDPRESS, __( 'Locale', 'wp-checkpoint' ), get_locale(), Check::INFO ),
			new Check( 'wordpress.debug', self::GROUP_WORDPRESS, __( 'WP_DEBUG', 'wp-checkpoint' ), defined( 'WP_DEBUG' ) && WP_DEBUG ? __( 'on', 'wp-checkpoint' ) : __( 'off', 'wp-checkpoint' ), Check::INFO ),
			new Check( 'wordpress.object_cache', self::GROUP_WORDPRESS, __( 'Persistent object cache', 'wp-checkpoint' ), wp_using_ext_object_cache() ? __( 'yes', 'wp-checkpoint' ) : __( 'no', 'wp-checkpoint' ), Check::INFO ),
			new Check( 'plugin.version', self::GROUP_WORDPRESS, __( 'WP Checkpoint version', 'wp-checkpoint' ), WPCHECKPOINT_VERSION, Check::INFO ),
		);
	}

	/**
	 * PHP version and extensions.
	 *
	 * @return Check[]
	 */
	private function php_checks(): array {
		$php_status = version_compare( PHP_VERSION, '8.0', '<' ) ? Check::WARNING : Check::OK;
		$checks     = array(
			new Check(
				'php.version',
				self::GROUP_PHP,
				__( 'PHP version', 'wp-checkpoint' ),
				PHP_VERSION,
				$php_status,
				Check::WARNING === $php_status ? __( 'PHP 7.4 no longer receives security updates.', 'wp-checkpoint' ) : '',
				''
			),
		);

		$has_zip  = (bool) call_user_func( $this->probes['class_exists'], 'ZipArchive' );
		$checks[] = new Check(
			'php.zip',
			self::GROUP_PHP,
			__( 'ZipArchive', 'wp-checkpoint' ),
			$has_zip ? __( 'available', 'wp-checkpoint' ) : __( 'missing', 'wp-checkpoint' ),
			$has_zip ? Check::OK : Check::WARNING,
			$has_zip ? '' : __( 'Backups will use the tar format instead of zip.', 'wp-checkpoint' ),
			__( 'Archive format.', 'wp-checkpoint' )
		);

		$extensions = array(
			'zlib'     => array( Check::WARNING, __( 'Tar backups will not be compressed and take more space.', 'wp-checkpoint' ), __( 'Compression.', 'wp-checkpoint' ) ),
			'mysqli'   => array( Check::ERROR, __( 'Database export and import cannot work without it.', 'wp-checkpoint' ), __( 'Database export and restore.', 'wp-checkpoint' ) ),
			'openssl'  => array( Check::WARNING, __( 'Remote storage destinations (S3, Google Drive) are unavailable.', 'wp-checkpoint' ), __( 'Remote storage.', 'wp-checkpoint' ) ),
			'mbstring' => array( Check::WARNING, __( 'Search and replace falls back to slower compatibility functions for multibyte text.', 'wp-checkpoint' ), __( 'Search and replace.', 'wp-checkpoint' ) ),
		);
		foreach ( $extensions as $ext => list( $missing_status, $message, $impact ) ) {
			$loaded   = (bool) call_user_func( $this->probes['extension_loaded'], $ext );
			$checks[] = new Check(
				'php.' . $ext,
				self::GROUP_PHP,
				$ext,
				$loaded ? __( 'loaded', 'wp-checkpoint' ) : __( 'missing', 'wp-checkpoint' ),
				$loaded ? Check::OK : $missing_status,
				$loaded ? '' : $message,
				$impact
			);
		}

		$disabled = array_filter( array_map( 'trim', explode( ',', (string) call_user_func( $this->probes['ini_get'], 'disable_functions' ) ) ) );
		$relevant = array_values( array_intersect( $disabled, HostFunctions::REPORTED ) );
		$checks[] = new Check(
			'php.disabled_functions',
			self::GROUP_PHP,
			__( 'Disabled functions (relevant)', 'wp-checkpoint' ),
			array() === $relevant ? __( 'none', 'wp-checkpoint' ) : implode( ', ', $relevant ),
			Check::INFO,
			'',
			__( 'The plugin never needs exec/shell functions; disabled set_time_limit or disk_free_space only reduce what can be measured.', 'wp-checkpoint' )
		);

		$open_basedir = (string) call_user_func( $this->probes['ini_get'], 'open_basedir' );
		$checks[]     = new Check( 'php.open_basedir', self::GROUP_PHP, __( 'open_basedir', 'wp-checkpoint' ), '' === $open_basedir ? __( 'not set', 'wp-checkpoint' ) : $open_basedir, Check::INFO );

		return $checks;
	}

	/**
	 * Memory and time limits: this admin request versus the task runtime
	 * (REST/loopback requests, as measured by the probe).
	 *
	 * @param array<string, mixed> $loopback Cached loopback result.
	 * @return Check[]
	 */
	private function limit_checks( array $loopback ): array {
		$admin   = self::runtime_values();
		$task    = isset( $loopback['runtime'] ) && is_array( $loopback['runtime'] ) ? $loopback['runtime'] : array();
		$known   = isset( $task['memory_bytes'], $task['max_execution_time'] );
		$checks  = array();
		$unknown = __( 'Unknown until the loopback probe succeeds; run "Re-check".', 'wp-checkpoint' );

		$checks[] = new Check(
			'limits.admin_memory',
			self::GROUP_LIMITS,
			__( 'Memory limit (this admin page)', 'wp-checkpoint' ),
			$admin['memory_limit'],
			Check::INFO,
			__( 'Admin pages are raised by WordPress and do not reflect what jobs get.', 'wp-checkpoint' )
		);
		$checks[] = new Check(
			'limits.admin_time',
			self::GROUP_LIMITS,
			__( 'Max execution time (this admin page)', 'wp-checkpoint' ),
			0 === $admin['max_execution_time'] ? __( 'unlimited', 'wp-checkpoint' ) : $admin['max_execution_time'] . ' s',
			Check::INFO
		);

		if ( $known ) {
			$memory_bytes = (int) $task['memory_bytes'];
			$seconds      = (int) $task['max_execution_time'];
			$budget       = Thresholds::time_budget( $seconds );
			$checks[]     = new Check(
				'limits.task_memory',
				self::GROUP_LIMITS,
				__( 'Memory limit (task runtime)', 'wp-checkpoint' ),
				(string) ( isset( $task['memory_limit'] ) ? $task['memory_limit'] : $memory_bytes ),
				Thresholds::memory_status( $memory_bytes ),
				$memory_bytes >= 0 && $memory_bytes < Thresholds::MEMORY_ERROR_BYTES ? __( 'Below 64 MB jobs refuse to start.', 'wp-checkpoint' ) : ( Check::WARNING === Thresholds::memory_status( $memory_bytes ) ? __( 'Below 128 MB large sites may need smaller chunks.', 'wp-checkpoint' ) : '' ),
				__( 'Each job step keeps its memory growth under 32 MB.', 'wp-checkpoint' )
			);
			$checks[]     = new Check(
				'limits.task_time',
				self::GROUP_LIMITS,
				__( 'Max execution time (task runtime)', 'wp-checkpoint' ),
				0 === $seconds ? __( 'unlimited', 'wp-checkpoint' ) : $seconds . ' s',
				Thresholds::time_status( $seconds ),
				/* translators: %d: seconds */
				sprintf( __( 'Each job step will run for at most %d seconds.', 'wp-checkpoint' ), $budget ) . ' ' . __( 'Proxy timeouts (nginx fastcgi_read_timeout, Cloudflare and similar) may cut requests earlier; the plugin cannot detect them.', 'wp-checkpoint' ),
				__( 'Step time budget: half the limit, between 5 and 20 seconds.', 'wp-checkpoint' )
			);
			$checks[] = new Check(
				'limits.task_set_time_limit',
				self::GROUP_LIMITS,
				__( 'set_time_limit() (task runtime)', 'wp-checkpoint' ),
				! empty( $task['set_time_limit'] ) ? __( 'available', 'wp-checkpoint' ) : __( 'not available', 'wp-checkpoint' ),
				Check::INFO,
				! empty( $task['set_time_limit'] ) ? '' : __( 'Steps cannot extend their own time limit.', 'wp-checkpoint' )
			);
		} else {
			$checks[] = new Check( 'limits.task_memory', self::GROUP_LIMITS, __( 'Memory limit (task runtime)', 'wp-checkpoint' ), __( 'unknown', 'wp-checkpoint' ), Check::INFO, $unknown );
			$checks[] = new Check( 'limits.task_time', self::GROUP_LIMITS, __( 'Max execution time (task runtime)', 'wp-checkpoint' ), __( 'unknown', 'wp-checkpoint' ), Check::INFO, $unknown );
		}

		$checks[] = $this->int_size_check();
		return $checks;
	}

	/**
	 * 32-bit PHP: file offsets stop at 2 GiB, so a single file above that
	 * cannot be backed up and an archive above that cannot be restored on
	 * this server (its manifest carries sizes the platform cannot hold). A
	 * warning, not an error: the plugin works, and the operations that hit
	 * the bound refuse individually with the same numbers. The numbers come
	 * from the packer's platform bound, not from a second copy.
	 *
	 * @return Check
	 */
	private function int_size_check(): Check {
		$int_size = (int) call_user_func( $this->probes['int_size'] );
		$wide     = $int_size >= 8;
		$limit    = size_format( Packer::max_entry_bytes( $int_size ), 0 );
		return new Check(
			'limits.int_size',
			self::GROUP_LIMITS,
			__( 'PHP integer size', 'wp-checkpoint' ),
			$wide ? __( '64-bit', 'wp-checkpoint' ) : __( '32-bit', 'wp-checkpoint' ),
			$wide ? Check::OK : Check::WARNING,
			$wide ? '' : sprintf(
				/* translators: %s: size such as "2 GB" */
				__( 'This server runs 32-bit PHP: files larger than %1$s cannot be backed up, and archives larger than %1$s cannot be restored here. Ask your host for 64-bit PHP.', 'wp-checkpoint' ),
				$limit
			),
			__( 'Backups of large files and restores of large archives.', 'wp-checkpoint' )
		);
	}

	/**
	 * Database version and size.
	 *
	 * @param array<string, mixed> $db Cached database facts.
	 * @return Check[]
	 */
	private function database_checks( array $db ): array {
		$info     = Thresholds::database( (string) $db['server_info'] );
		$size     = isset( $db['size'] ) ? $db['size'] : null;
		$schema   = Schema::stored();
		$exists   = Schema::table_exists();
		$problems = $exists ? Schema::column_problems() : null; // Null (not read) is no evidence of a problem.
		$counts   = array();
		if ( $exists ) {
			// Only with the table there: $wpdb does not throw on a missing table, it writes the error to the log.
			try {
				$counts = ( new \WPCheckpoint\Jobs\JobRepository( $this->directories ) )->counts();
			} catch ( \Throwable $e ) {
				$counts = array();
			}
		}
		if ( ! $exists ) {
			$jobs = new Check( 'database.jobs', self::GROUP_DATABASE, __( 'Job table', 'wp-checkpoint' ), __( 'missing', 'wp-checkpoint' ), Check::ERROR, __( 'The table will be created when the plugin page is opened; if this persists, the database user lacks CREATE TABLE.', 'wp-checkpoint' ), __( 'No job can run without it.', 'wp-checkpoint' ) );
		} elseif ( ! Schema::is_compatible() ) {
			$jobs = new Check( 'database.jobs', self::GROUP_DATABASE, __( 'Job table', 'wp-checkpoint' ), sprintf( 'schema v%d (requires plugin schema %d)', $schema['version'], $schema['min_compatible'] ), Check::ERROR, __( 'The database structure was created by a newer version of WP Checkpoint and this version cannot use it. Please update the plugin.', 'wp-checkpoint' ), __( 'Jobs cannot be created or continued.', 'wp-checkpoint' ) );
		} elseif ( null !== $problems && array() !== $problems ) {
			$jobs = new Check( 'database.jobs', self::GROUP_DATABASE, __( 'Job table', 'wp-checkpoint' ), sprintf( 'schema v%d, columns missing or too narrow', $schema['version'] ), Check::ERROR, Schema::problem_message( $problems ), __( 'Jobs cannot be created or continued.', 'wp-checkpoint' ) );
		} else {
			$summary = array();
			foreach ( $counts as $status => $n ) {
				if ( $n > 0 ) {
					$summary[] = $n . ' ' . $status;
				}
			}
			$jobs = new Check(
				'database.jobs',
				self::GROUP_DATABASE,
				__( 'Job table', 'wp-checkpoint' ),
				sprintf( 'schema v%d', $schema['version'] ) . ( array() === $summary ? '' : ' (' . implode( ', ', $summary ) . ')' ),
				Schema::is_newer() ? Check::WARNING : Check::OK,
				Schema::is_newer() ? __( 'The database structure comes from a newer plugin version; this version can still use it.', 'wp-checkpoint' ) : ''
			);
		}
		return array(
			$jobs,
			new Check(
				'database.version',
				self::GROUP_DATABASE,
				__( 'Database server', 'wp-checkpoint' ),
				trim( $info['flavor'] . ' ' . $info['version'] ),
				$info['status'],
				Check::WARNING === $info['status'] ? __( 'Older than the supported minimum (MySQL 5.7 / MariaDB 10.4).', 'wp-checkpoint' ) : '',
				__( 'Export and restore use standard SQL; older servers are untested.', 'wp-checkpoint' )
			),
			new Check(
				'database.size',
				self::GROUP_DATABASE,
				__( 'Database size (this installation)', 'wp-checkpoint' ),
				is_int( $size ) ? size_format( $size, 1 ) : __( 'unknown', 'wp-checkpoint' ),
				Check::INFO,
				is_int( $size ) ? '' : __( 'information_schema is not readable with the current database user.', 'wp-checkpoint' ),
				__( 'Used to estimate backup size.', 'wp-checkpoint' )
			),
		);
	}

	/**
	 * Storage directory, writability, disk space and protection.
	 *
	 * @return Check[]
	 */
	private function storage_checks(): array {
		$base   = $this->directories->base();
		$state  = $this->directories->state();
		$error  = $this->directories->last_error();
		$checks = array();

		if ( '' === $base ) {
			$checks[] = new Check( 'storage.path', self::GROUP_STORAGE, __( 'Storage directory', 'wp-checkpoint' ), __( 'unusable', 'wp-checkpoint' ), Check::ERROR, $error, __( 'No backup, restore or checkpoint can run.', 'wp-checkpoint' ) );
			return $checks;
		}

		$sources  = array(
			Directories::SOURCE_OUTSIDE => __( 'outside the document root', 'wp-checkpoint' ),
			Directories::SOURCE_CONTENT => __( 'inside wp-content', 'wp-checkpoint' ),
			Directories::SOURCE_CUSTOM  => __( 'custom (WPCHECKPOINT_STORAGE_DIR)', 'wp-checkpoint' ),
		);
		$source   = isset( $sources[ $state['source'] ] ) ? $sources[ $state['source'] ] : (string) $state['source'];
		$checks[] = new Check(
			'storage.path',
			self::GROUP_STORAGE,
			__( 'Storage directory', 'wp-checkpoint' ),
			$base . ' (' . $source . ')',
			! empty( $state['provisional'] ) ? Check::INFO : Check::OK,
			! empty( $state['provisional'] ) ? __( 'Chosen from the command line; it will be re-evaluated on the next admin request while empty.', 'wp-checkpoint' ) : ''
		);

		foreach ( Directories::SUBDIRS as $sub ) {
			$dir      = $base . DIRECTORY_SEPARATOR . $sub;
			$writable = is_dir( $dir ) && wp_is_writable( $dir );
			$checks[] = new Check(
				'storage.' . $sub,
				self::GROUP_STORAGE,
				/* translators: %s: sub-directory name */
				sprintf( __( 'Directory %s', 'wp-checkpoint' ), $sub . '/' ),
				$writable ? __( 'writable', 'wp-checkpoint' ) : __( 'not writable', 'wp-checkpoint' ),
				$writable ? Check::OK : Check::ERROR
			);
		}

		$free    = call_user_func( $this->probes['disk_free_space'], $base );
		$total   = call_user_func( $this->probes['disk_total_space'], $base );
		$status  = Thresholds::disk_status( $free );
		$value   = __( 'unknown', 'wp-checkpoint' );
		$message = __( 'This host does not report free space (disk_free_space is disabled or unreliable). Backups check space as they are written.', 'wp-checkpoint' );
		if ( Check::INFO !== $status ) {
			$value   = size_format( (int) $free, 1 );
			$message = '';
			if ( Thresholds::is_disk_value( $total ) && $total >= $free ) {
				/* translators: %s: percentage */
				$value .= ' ' . sprintf( __( '(%s%% free)', 'wp-checkpoint' ), number_format_i18n( $free / $total * 100, 0 ) );
			}
			if ( Check::OK !== $status ) {
				$message = __( 'A backup needs roughly the size of the site, a restore about twice the backup size.', 'wp-checkpoint' );
			}
			$message .= ( '' === $message ? '' : ' ' ) . __( 'On shared hosting this may be the whole server rather than your quota.', 'wp-checkpoint' );
		}
		$checks[] = new Check( 'storage.disk', self::GROUP_STORAGE, __( 'Free disk space', 'wp-checkpoint' ), $value, $status, $message, __( 'Preflight of exports and restores.', 'wp-checkpoint' ) );

		$verification = is_array( $state['verification'] ) ? $state['verification'] : array();
		$vstatus      = isset( $verification['status'] ) ? (string) $verification['status'] : '';
		$labels       = array(
			Protection::STATUS_PROTECTED      => array( Check::OK, __( 'protected', 'wp-checkpoint' ), '' ),
			Protection::STATUS_EXPOSED        => array( Check::ERROR, __( 'exposed', 'wp-checkpoint' ), __( 'Files in the directory can be downloaded by anyone. Add the server rule shown on this page.', 'wp-checkpoint' ) ),
			Protection::STATUS_UNVERIFIED     => array( Check::WARNING, __( 'unverified', 'wp-checkpoint' ), isset( $verification['message'] ) ? (string) $verification['message'] : '' ),
			Protection::STATUS_NOT_APPLICABLE => array( Check::OK, __( 'not reachable over HTTP', 'wp-checkpoint' ), '' ),
		);
		if ( isset( $labels[ $vstatus ] ) ) {
			list( $s, $v, $m ) = $labels[ $vstatus ];
		} else {
			list( $s, $v, $m ) = array( Check::INFO, __( 'not checked yet', 'wp-checkpoint' ), __( 'Use "Re-verify directory protection".', 'wp-checkpoint' ) );
		}
		$checks[] = new Check( 'storage.protection', self::GROUP_STORAGE, __( 'Direct HTTP access', 'wp-checkpoint' ), $v, $s, $m, __( 'Backups must not be downloadable without logging in.', 'wp-checkpoint' ) );

		if ( ! empty( $state['clone_detected'] ) ) {
			$checks[] = new Check( 'storage.clone', self::GROUP_STORAGE, __( 'Clone or migration detected', 'wp-checkpoint' ), __( 'yes', 'wp-checkpoint' ), Check::WARNING, __( 'The previously stored directory belongs to another installation and was left untouched.', 'wp-checkpoint' ) );
		}

		return $checks;
	}

	/**
	 * Loopback result as a check.
	 *
	 * @param array<string, mixed> $loopback Cached loopback result.
	 * @return Check[]
	 */
	private function loopback_checks( array $loopback ): array {
		$outcome = isset( $loopback['outcome'] ) ? (string) $loopback['outcome'] : 'unreachable';
		$status  = Thresholds::loopback_status( $outcome );
		$impact  = Check::OK === $status
			? __( 'Jobs can continue in the background after you leave the page.', 'wp-checkpoint' )
			: __( 'Jobs can only be driven by keeping the browser page open or by WP-CLI.', 'wp-checkpoint' );
		$value   = $outcome;
		if ( isset( $loopback['code'] ) ) {
			$value .= ' (HTTP ' . (int) $loopback['code'] . ')';
		}
		return array(
			new Check( 'loopback.rest', self::GROUP_LOOPBACK, __( 'Site can call itself (REST loopback)', 'wp-checkpoint' ), $value, $status, isset( $loopback['message'] ) ? (string) $loopback['message'] : '', $impact ),
		);
	}

	/**
	 * Server facts.
	 *
	 * @return Check[]
	 */
	private function server_checks(): array {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : __( 'unknown', 'wp-checkpoint' );
		return array(
			new Check( 'server.software', self::GROUP_SERVER, __( 'Web server', 'wp-checkpoint' ), $software, Check::INFO ),
			new Check( 'server.sapi', self::GROUP_SERVER, __( 'PHP SAPI', 'wp-checkpoint' ), PHP_SAPI, Check::INFO ),
			new Check( 'server.os', self::GROUP_SERVER, __( 'Operating system', 'wp-checkpoint' ), PHP_OS_FAMILY, Check::INFO ),
		);
	}
}
