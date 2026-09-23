<?php
/**
 * The export job: a backup of this site into backups/.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Database\WpdbConnection;
use WPCheckpoint\Files\PathKey;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Environment;
use WPCheckpoint\Support\Schema;

/**
 * The seven steps in order (pre-flight, scan, review, database, pack,
 * manifest, store), with the WordPress facts they need injected here so
 * the steps stay plain PHP. The steps are built when a run asks for them,
 * not when the plugin boots: the site facts are read once per run.
 */
final class ExportJob implements JobType {

	const ID = 'export';

	/**
	 * Returns the current storage directories: function(): Directories. Fetched
	 * per run, so a run never writes into a directory choice made stale.
	 *
	 * @var callable
	 */
	private $directories;

	/**
	 * Cleans the self-check's report for the error text (JobPresenter::clean()).
	 *
	 * @var callable
	 */
	private $clean;

	/**
	 * Constructor.
	 *
	 * @param callable $directories function(): Directories, the current storage directories.
	 * @param callable $clean       Text cleaner for reports that leave the engine.
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
		return __( 'Backup', 'wp-checkpoint' );
	}

	/**
	 * Step ids in order.
	 *
	 * @return string[]
	 */
	public function step_ids(): array {
		return array( PreflightStep::ID, FileScanStep::ID, ReviewStep::ID, DatabaseExportStep::ID, PackStep::ID, ManifestStep::ID, StoreStep::ID );
	}

	/**
	 * The steps, built for this run.
	 *
	 * @return Step[]
	 * @throws \LogicException When the directories callable returns something else.
	 */
	public function steps(): array {
		global $wpdb;
		$connection  = new WpdbConnection();
		$directories = call_user_func( $this->directories );
		if ( ! $directories instanceof Directories ) {
			throw new \LogicException( 'The storage directories are not available.' );
		}
		return array(
			new PreflightStep(
				$connection,
				array(
					'prefix'        => Environment::table_prefix(),
					'tables'        => array( $connection, 'tables_with_prefix' ),
					'writable'      => static function () use ( $directories ): array {
						$bad = array();
						foreach ( Directories::SUBDIRS as $sub ) {
							if ( ! is_writable( $directories->base() . DIRECTORY_SEPARATOR . $sub ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- the plugin's own storage directory.
								$bad[] = $sub;
							}
						}
						return $bad;
					},
					'disk_free'     => static function () use ( $directories ) {
						return Environment::default_probes()['disk_free_space']( $directories->base() );
					},
					'slug'          => static function (): string {
						return self::slug_from_url( self::main_url( 'home' ), function_exists( 'idn_to_ascii' ) ? 'idn_to_ascii' : null );
					},
					'can_deflate'   => function_exists( 'gzdeflate' ),
					'normalization' => PathKey::normalization_available(),
					'int_size'      => PHP_INT_SIZE,
					'multisite'     => is_multisite(),
					'core_tables'   => static function () use ( $wpdb ): array {
						// The main site's: a tick may run in a sub-site's context, where the blog tables would be that
						// sub-site's. The sub-sites' own tables are protected by their naming rule (TableSelection).
						return array_values( $wpdb->tables( 'all', true, is_multisite() ? get_main_site_id() : 0 ) );
					},
					'own_tables'    => array( Schema::jobs_table() ),
				)
			),
			FileScanStep::from_plan(),
			new ReviewStep(
				static function () use ( $directories ) {
					return Environment::default_probes()['disk_free_space']( $directories->base() );
				}
			),
			DatabaseExportStep::from_plan( $connection ),
			new PackStep(),
			new ManifestStep( self::site_facts(), self::generator(), array(), Manifest::DEFAULT_CHUNK, $this->clean ),
			new StoreStep( $directories->backups() ),
		);
	}

	/**
	 * The slug a backup's file names start with: the site address's host
	 * without its port, in ASCII (a Unicode host is converted to punycode
	 * when $idn is given, else its non-ASCII letters are dropped by the
	 * pre-flight's cleaning), followed by the path of a site installed in a
	 * subdirectory. "https://shop.example.com:8443/blog/" gives
	 * "shop.example.com/blog"; the pre-flight turns it into
	 * "shop-example-com-blog". Pure.
	 *
	 * @param string        $url Site address.
	 * @param callable|null $idn idn_to_ascii or null.
	 * @return string
	 */
	public static function slug_from_url( string $url, $idn = null ): string {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure PHP, also unit-tested without WordPress.
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) ) {
			return '';
		}
		$host = strtolower( (string) $parts['host'] );
		if ( 1 === preg_match( '/[^\x00-\x7F]/', $host ) && is_callable( $idn ) ) {
			$ascii = defined( 'INTL_IDNA_VARIANT_UTS46' ) ? call_user_func( $idn, $host, 0, INTL_IDNA_VARIANT_UTS46 ) : call_user_func( $idn, $host );
			if ( is_string( $ascii ) && '' !== $ascii ) {
				$host = strtolower( $ascii );
			}
		}
		$path = isset( $parts['path'] ) ? trim( (string) $parts['path'], '/' ) : '';
		return '' === $path ? $host : $host . '/' . $path;
	}

	/**
	 * The network's main site address on multisite, the site's otherwise.
	 *
	 * @param string $which 'home' or 'site'.
	 * @return string
	 */
	private static function main_url( string $which ): string {
		if ( is_multisite() ) {
			return 'home' === $which ? get_home_url( get_main_site_id() ) : get_site_url( get_main_site_id() );
		}
		return 'home' === $which ? home_url() : site_url();
	}

	/**
	 * The facts the manifest records about the site ("site" keys).
	 *
	 * @return array<string, mixed>
	 */
	private static function site_facts(): array {
		global $wpdb;
		return array(
			'home_url'     => self::main_url( 'home' ),
			'site_url'     => self::main_url( 'site' ),
			'abspath'      => ABSPATH,
			'content_dir'  => WP_CONTENT_DIR,
			'table_prefix' => Environment::table_prefix(),
			'wp_version'   => (string) get_bloginfo( 'version' ),
			'php_version'  => PHP_VERSION,
			'db_server'    => (string) $wpdb->db_server_info(),
			'locale'       => (string) get_locale(),
			'charset'      => (string) $wpdb->charset,
			'collate'      => (string) $wpdb->collate,
			'multisite'    => is_multisite(),
		);
	}

	/**
	 * The generator the manifest names.
	 *
	 * @return array{name: string, version: string}
	 */
	private static function generator(): array {
		return array(
			'name'    => 'wp-checkpoint',
			'version' => defined( 'WPCHECKPOINT_VERSION' ) ? (string) WPCHECKPOINT_VERSION : '0.0.0',
		);
	}
}
