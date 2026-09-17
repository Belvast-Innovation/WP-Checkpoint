<?php
/**
 * Plugin bootstrap and service container.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint;

use WPCheckpoint\Admin\Menu;
use WPCheckpoint\Admin\Page;
use WPCheckpoint\Admin\Settings;
use WPCheckpoint\Admin\Tabs;
use WPCheckpoint\Admin\Tabs\BackupsTab;
use WPCheckpoint\Admin\Tabs\CheckpointsTab;
use WPCheckpoint\Admin\Tabs\SettingsTab;
use WPCheckpoint\Admin\Tabs\ToolsTab;
use WPCheckpoint\Rest\Controller;
use WPCheckpoint\Rest\StatusController;
use WPCheckpoint\Support\Uninstaller;

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
		( new Settings() )->register();
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
		$tabs->add( new ToolsTab() );
		$tabs->add( new SettingsTab() );

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
