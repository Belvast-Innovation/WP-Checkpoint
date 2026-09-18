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
use WPCheckpoint\Admin\JobProgress;
use WPCheckpoint\Cli\JobCommand;
use WPCheckpoint\Jobs\JobActions;
use WPCheckpoint\Jobs\JobPresenter;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\JobTypes;
use WPCheckpoint\Jobs\Loopback;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Admin\Tabs;
use WPCheckpoint\Admin\Tabs\BackupsTab;
use WPCheckpoint\Admin\Tabs\CheckpointsTab;
use WPCheckpoint\Admin\Tabs\SettingsTab;
use WPCheckpoint\Admin\Tabs\ToolsTab;
use WPCheckpoint\Rest\Controller;
use WPCheckpoint\Rest\JobsController;
use WPCheckpoint\Rest\LoopbackController;
use WPCheckpoint\Rest\ProbeController;
use WPCheckpoint\Rest\StatusController;
use WPCheckpoint\Support\Guard;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Support\Schema;
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
	 * Job repository.
	 *
	 * @var JobRepository|null
	 */
	private $jobs = null;

	/**
	 * Cached runner.
	 *
	 * @var Runner|null
	 */
	private $runner = null;

	/**
	 * Cached loopback.
	 *
	 * @var Loopback|null
	 */
	private $loopback = null;

	/**
	 * Cached actions.
	 *
	 * @var JobActions|null
	 */
	private $job_actions = null;

	/**
	 * Cached presenter.
	 *
	 * @var JobPresenter|null
	 */
	private $presenter = null;

	/**
	 * Job type registry.
	 *
	 * @var JobTypes|null
	 */
	private $job_types = null;

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
		// The delayed re-tick must work outside the admin (real cron runs in a front-end or CLI process).
		add_action( Loopback::HOOK, array( $this, 'cron_tick' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'wpcheckpoint job', new JobCommand( $this->job_actions(), $this->job_presenter() ) );
		}

		if ( is_admin() ) {
			$this->boot_admin();
		}
	}

	/**
	 * Cron callback: one tick with the budget counted from now under WP-CLI
	 * ("wp cron event run" handles several events per process) and from the
	 * request start otherwise.
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	public function cron_tick( $job_id ): void {
		try {
			$this->job_actions()->tick( (int) $job_id, JobActions::started_at() );
		} catch ( \Throwable $e ) {
			$this->directories()->log_event( sprintf( 'Cron tick of job %d failed: %s', (int) $job_id, get_class( $e ) ) );
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
		wp_enqueue_script( 'wpcheckpoint-jobs', WPCHECKPOINT_URL . 'assets/admin/jobs.js', array(), WPCHECKPOINT_VERSION, true );
		// Not wp_localize_script(): it casts every value to a string and the boolean would become "".
		$config = array(
			'root'     => esc_url_raw( rest_url() ),
			'nonce'    => Guard::rest_nonce(),
			'loopback' => $this->loopback()->enabled(),
			'labels'   => JobProgress::script_labels(),
		);
		wp_add_inline_script( 'wpcheckpoint-jobs', 'window.wpcheckpointJobs = ' . wp_json_encode( $config ) . ';', 'before' );
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
		$this->jobs        = null;
		$this->redactor    = null; // The storage token is one of its secrets.
		$this->runner      = null;
		$this->job_actions = null;
		$this->presenter   = null;
	}

	/**
	 * Step runner.
	 *
	 * @return Runner
	 */
	public function runner(): Runner {
		if ( null === $this->runner ) {
			$this->runner = new Runner( $this->jobs(), $this->job_types(), $this->redactor() );
		}
		return $this->runner;
	}

	/**
	 * Loopback / cron follow-up.
	 *
	 * @return Loopback
	 */
	public function loopback(): Loopback {
		if ( null === $this->loopback ) {
			$this->loopback = new Loopback();
		}
		return $this->loopback;
	}

	/**
	 * Tick, cancel and retry shared by every driver.
	 *
	 * @return JobActions
	 */
	public function job_actions(): JobActions {
		if ( null === $this->job_actions ) {
			$this->job_actions = new JobActions( $this->jobs(), $this->runner(), $this->loopback() );
		}
		return $this->job_actions;
	}

	/**
	 * Presenter for REST, WP-CLI and the admin page.
	 *
	 * @return JobPresenter
	 */
	public function job_presenter(): JobPresenter {
		if ( null === $this->presenter ) {
			$this->presenter = new JobPresenter( $this->redactor(), $this->job_types() );
		}
		return $this->presenter;
	}

	/**
	 * Job repository bound to the storage directories.
	 *
	 * @return JobRepository
	 */
	public function jobs(): JobRepository {
		if ( null === $this->jobs ) {
			$this->jobs = new JobRepository( $this->directories(), $this->redactor() );
		}
		return $this->jobs;
	}

	/**
	 * Registered job types (built-in ones are added by later tasks).
	 *
	 * @return JobTypes
	 */
	public function job_types(): JobTypes {
		if ( null === $this->job_types ) {
			$this->job_types = new JobTypes();
		}
		return $this->job_types;
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
		} elseif ( $result['ran'] && ! $result['scanned'] ) {
			$this->directories()->log_event( 'Multisite: the "delete data on uninstall" setting is now network-wide and was initialised to off; the network is too large to scan for site-level settings, so the super admin was asked to confirm it.' );
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
			new JobsController( $this->job_actions(), $this->job_presenter() ),
			new LoopbackController( $this->job_actions() ),
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
		Schema::ensure();
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
