<?php
/**
 * Plugin bootstrap and service container.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint;

use WPCheckpoint\Admin\DownloadHandler;
use WPCheckpoint\Admin\EnvironmentActions;
use WPCheckpoint\Admin\Menu;
use WPCheckpoint\Admin\Notices;
use WPCheckpoint\Admin\ReclaimActions;
use WPCheckpoint\Admin\Page;
use WPCheckpoint\Admin\SettingsActions;
use WPCheckpoint\Admin\Tabs;
use WPCheckpoint\Admin\Tabs\BackupsTab;
use WPCheckpoint\Admin\Tabs\CheckpointsTab;
use WPCheckpoint\Admin\Tabs\SettingsTab;
use WPCheckpoint\Admin\Tabs\ToolsTab;
use WPCheckpoint\Rest\Controller;
use WPCheckpoint\Rest\ProbeController;
use WPCheckpoint\Rest\StatusController;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Support\Uninstaller;
use WPCheckpoint\Support\UninstallSetting;

defined( 'ABSPATH' ) || exit;

/**
 * Wires services together. Keep this class thin: it only registers hooks
 * and instantiates services; business logic lives in domain classes.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Storage directories, created on first use.
	 *
	 * @var Directories|null
	 */
	private $directories = null;

	/**
	 * Redactor seeded with the installation's secrets.
	 *
	 * @var Redactor|null
	 */
	private $redactor = null;

	/**
	 * Get the plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks. Called on plugins_loaded.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		if ( is_admin() ) {
			$this->boot_admin();
		}
	}

	/**
	 * Register the admin page and settings.
	 *
	 * Public so tests can boot the admin layer without is_admin().
	 *
	 * @return void
	 */
	public function boot_admin(): void {
		( new Menu( $this->admin_page() ) )->register();
		( new SettingsActions() )->register();
		add_action( 'admin_init', array( $this, 'migrate_settings' ) );
		( new DownloadHandler( $this->directories() ) )->register();
		( new Notices( $this->directories() ) )->register();
		( new EnvironmentActions( $this->directories() ) )->register();
		( new ReclaimActions( $this->directories() ) )->register();
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Scripts for the plugin page only.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_' . Page::SLUG !== $hook_suffix ) {
			return;
		}
		wp_enqueue_script( 'wpcheckpoint-environment', WPCHECKPOINT_URL . 'assets/admin/environment.js', array(), WPCHECKPOINT_VERSION, true );
	}

	/**
	 * Storage directories for this installation.
	 *
	 * @return Directories
	 */
	public function directories(): Directories {
		if ( null === $this->directories ) {
			$this->directories = new Directories();
		}
		return $this->directories;
	}

	/**
	 * Drop the cached Directories instance (tests recreate storage between cases).
	 *
	 * @internal
	 * @return void
	 */
	public function reset_directories(): void {
		$this->directories = null;
	}

	/**
	 * Multisite: initialise the network-wide uninstall setting once and warn
	 * when a site-level "on" was left behind.
	 *
	 * @return void
	 */
	public function migrate_settings(): void {
		$result = UninstallSetting::migrate_multisite();
		if ( $result['leftover'] ) {
			$this->directories()->log_event( 'Multisite: the "delete data on uninstall" setting is now network-wide and was initialised to off; a site-level "on" was found and must be confirmed again on the Settings tab.' );
		}
	}

	/**
	 * Redactor knowing the database credentials, keys and salts.
	 *
	 * @return Redactor
	 */
	public function redactor(): Redactor {
		if ( null === $this->redactor ) {
			// The random storage token protects the directory under wp-content; treat it as a secret.
			$state          = Directories::load_state();
			$this->redactor = new Redactor( Redactor::installation_secrets( array( (string) $state['token'] ) ) );
		}
		return $this->redactor;
	}

	/**
	 * The admin page with the built-in tabs.
	 *
	 * @return Page
	 */
	public function admin_page(): Page {
		$tabs = new Tabs();
		$tabs->add( new BackupsTab() );
		$tabs->add( new CheckpointsTab() );
		$tabs->add( new ToolsTab( $this->directories() ) );
		$tabs->add( new SettingsTab( $this->directories() ) );

		return new Page( $tabs );
	}

	/**
	 * REST controllers to register. Later tasks append theirs here.
	 *
	 * @return Controller[]
	 */
	public function rest_controllers(): array {
		return array(
			new StatusController(),
			new ProbeController(),
		);
	}

	/**
	 * Register all REST routes.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		foreach ( $this->rest_controllers() as $controller ) {
			$controller->register_routes();
		}
	}

	/**
	 * Activation hook. Must stay silent: any output breaks activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		update_option( Uninstaller::OPTION_VERSION, WPCHECKPOINT_VERSION, false );
		// Activation from the admin is a proper web request: the best moment to choose the storage location.
		self::instance()->directories()->base();
		// T010: create/upgrade database tables via dbDelta.
	}

	/**
	 * Deactivation hook. Must not delete user backups.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// T093: remove troubleshooting mu-plugin if present.
	}
}
