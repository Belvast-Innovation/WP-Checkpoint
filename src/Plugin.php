<?php
/**
 * Plugin bootstrap and service container.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint;

defined( 'ABSPATH' ) || exit; // Before the imports: checks that look for it near the top of the file find it.

use WPCheckpoint\Admin\DownloadHandler;
use WPCheckpoint\Admin\EnvironmentActions;
use WPCheckpoint\Admin\Menu;
use WPCheckpoint\Admin\Notices;
use WPCheckpoint\Admin\ReclaimActions;
use WPCheckpoint\Admin\Page;
use WPCheckpoint\Admin\SettingsActions;
use WPCheckpoint\Admin\SiteIdentityActions;
use WPCheckpoint\Admin\JobProgress;
use WPCheckpoint\Admin\LogDownload;
use WPCheckpoint\Cli\ExportCommand;
use WPCheckpoint\Cli\JobCommand;
use WPCheckpoint\Cli\SiteIdentityCommand;
use WPCheckpoint\Cli\VerifyCommand;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobActions;
use WPCheckpoint\Jobs\JobPresenter;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\ExportJob;
use WPCheckpoint\Jobs\RestoreJob;
use WPCheckpoint\Jobs\VerifyJob;
use WPCheckpoint\Jobs\EstimateJob;
use WPCheckpoint\Backups\Estimate;
use WPCheckpoint\Jobs\JobTypes;
use WPCheckpoint\Jobs\Loopback;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Admin\Tabs;
use WPCheckpoint\Admin\Tabs\BackupsTab;
use WPCheckpoint\Admin\Tabs\CheckpointsTab;
use WPCheckpoint\Admin\Tabs\SettingsTab;
use WPCheckpoint\Admin\Tabs\ToolsTab;
use WPCheckpoint\Rest\Controller;
use WPCheckpoint\Rest\BackupsController;
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
		// Automatic updates run in cron and admin requests: held while a restore is unfinished.
		Support\AutoUpdateHold::register();
		// After a restore's swap, the first request with the restored site loaded sets the fallback events again.
		add_action( 'init', array( $this, 'after_swap' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'wpcheckpoint job', new JobCommand( $this->job_actions(), $this->job_presenter(), $this->directories() ) );
			\WP_CLI::add_command( 'wpcheckpoint verify', new VerifyCommand( $this->job_presenter(), $this->directories() ) );
			\WP_CLI::add_command( 'wpcheckpoint export', new ExportCommand( $this->job_actions(), $this->job_presenter(), $this->directories() ) );
			\WP_CLI::add_command( 'wpcheckpoint site-identity', new SiteIdentityCommand( $this->job_presenter(), $this->directories() ) );
			// Every command of the plugin says it first when a restore left the site half swapped ("before_invoke"
			// fires for a command's own parent only, never for "wpcheckpoint" under "wpcheckpoint job list").
			\WP_CLI::add_hook(
				'before_run_command',
				function ( $args = array() ): void {
					if ( ! is_array( $args ) || 'wpcheckpoint' !== ( $args[0] ?? '' ) ) {
						return;
					}
					foreach ( $this->half_swapped_warnings() as $warning ) {
						\WP_CLI::warning( $warning );
					}
				}
			);
		}

		if ( is_admin() ) {
			$this->boot_admin();
		}
	}

	/**
	 * What a WP-CLI command says first while a restore holds the site: one line per such job that has something left
	 * to do, by where it is. Swapped in (the swap recorded as made, its end, or the ended step's position cleared) or
	 * put back ("restored"): only the end is left. Half swapped: running the job finishes the swap or puts the site
	 * back (by what is there). Half swapped with no position recorded (the row was changed): not to be run blindly. A
	 * failed job is retried first; one another process holds is under way; one another installation manages is never
	 * run here, and what can be done from here is said instead (JobPresenter::held_lines()). And whenever a held maintenance file of
	 * this plugin is up, a line saying so, with the advice to remove it only when the jobs were read and none holds
	 * the site.
	 *
	 * @param callable|null $holding function(): int[] in place of the jobs table (tests); it may throw.
	 * @return string[]
	 */
	public function half_swapped_warnings( $holding = null ): array {
		$out  = array();
		$read = true;
		try {
			$ids = is_callable( $holding ) ? (array) call_user_func( $holding ) : $this->jobs()->holding_site();
		} catch ( \Throwable $e ) {
			$ids  = array();
			$read = false;
		}
		foreach ( $ids as $id ) {
			$job = $this->jobs()->find( (int) $id );
			if ( null === $job ) {
				continue; // Listed, then not found: the list is not empty, so no advice to remove the file follows.
			}
			$phase = (string) ( $job->cursor['phase'] ?? '' );
			/* translators: 1: job id, 2: job id */
			$finish = Job::FAILED === $job->status ? sprintf( __( 'wp wpcheckpoint job retry %1$d, then wp wpcheckpoint job run %2$d', 'wp-checkpoint' ), $id, $id ) : sprintf( 'wp wpcheckpoint job run %d', $id );
			$held   = $job->is_locked( time() ) ? null : $this->job_actions()->held_elsewhere( (int) $id );
			if ( $job->is_locked( time() ) ) {
				/* translators: %d: job id */
				$out[] = sprintf( __( 'Restore job %d is at work on the site in another process.', 'wp-checkpoint' ), $id );
			} elseif ( null !== $held ) {
				// Managed by another installation: why it is not run here, and what can be done from here.
				$out[] = $this->job_presenter()->clean( implode( "\n", JobPresenter::held_lines( $held['job'], $held['assessment'] ) ) );
				continue;
			} elseif ( Job::SITE_SWAPPED === $job->site_state ) {
				/* translators: 1: job id, 2: the command */
				$out[] = sprintf( __( 'Restore job %1$d is at its last step (the restored site is swapped in). Finish it with: %2$s', 'wp-checkpoint' ), $id, $finish );
			} elseif ( 'restored' === $phase ) {
				/* translators: 1: job id, 2: the command */
				$out[] = sprintf( __( 'Restore job %1$d is at its last step (the site is put back as it was). Finish it with: %2$s', 'wp-checkpoint' ), $id, $finish );
			} elseif ( '' === $phase ) {
				/* translators: %d: job id */
				$out[] = sprintf( __( 'Restore job %d holds the site half swapped, and its position is lost (the job row was changed): do not run it. Look at the site\'s directories and tables, and the .maintenance file in the WordPress directory, before anything else.', 'wp-checkpoint' ), $id );
			} else {
				/* translators: 1: job id, 2: the command */
				$out[] = sprintf( __( 'The site is half swapped by restore job %1$d: visitors see the maintenance page until the restore finishes or puts the site back. Resolve it with: %2$s', 'wp-checkpoint' ), $id, $finish );
			}
			if ( Job::FAILED === $job->status && '' !== $phase && '' !== (string) $job->last_error ) {
				// Why the last try failed, masked as every text that leaves the engine.
				/* translators: %s: the error of the job's last try */
				$out[ count( $out ) - 1 ] .= ' ' . sprintf( __( 'Its last try failed: %s', 'wp-checkpoint' ), $this->job_presenter()->clean( (string) $job->last_error ) );
			}
		}
		if ( defined( 'ABSPATH' ) && Restore\Maintenance::held_in( (string) ABSPATH ) ) {
			$owner = array() === $ids && $read ? $this->ended_owner() : null;
			if ( array() !== $ids ) {
				$out[] = __( 'A restore\'s maintenance file is up and does not lapse (.maintenance in the WordPress directory): visitors see the maintenance page until the restore above ends.', 'wp-checkpoint' );
			} elseif ( null !== $owner ) {
				$out[] = $this->job_presenter()->clean(
					sprintf(
						/* translators: 1: job id, 2: the release command */
						__( 'The maintenance file of restore job %1$d is up and does not lapse (.maintenance in the WordPress directory): visitors see the maintenance page. That job no longer holds the site; take its file down with: %2$s', 'wp-checkpoint' ),
						$owner->id,
						'wp wpcheckpoint job release ' . $owner->id . ' --confirm=' . Jobs\HeldSite::code( Jobs\HeldSite::RELEASE, $owner, (string) ( new Jobs\HeldSite() )->assess( $owner )['recorded'] )
					)
				);
			} elseif ( $read ) {
				$out[] = __( 'A restore\'s maintenance file is up and does not lapse (.maintenance in the WordPress directory): visitors see the maintenance page. No restore holds the site now; check with wp wpcheckpoint job list, and if none does, remove that file.', 'wp-checkpoint' );
			} else {
				$out[] = __( 'A restore\'s maintenance file is up and does not lapse (.maintenance in the WordPress directory): visitors see the maintenance page. The jobs could not be read: do not remove that file until wp wpcheckpoint job list shows no restore that holds the site.', 'wp-checkpoint' );
			}
		}
		return $out;
	}

	/**
	 * The job whose maintenance file is in the WordPress directory, when that job no longer holds the site (its row
	 * recorded the file's mark: Job::$site_mark); null otherwise. A job abandoned from another installation holds the
	 * site (JobRepository::holds_site(), as release judges it).
	 *
	 * @return Jobs\Job|null
	 */
	private function ended_owner() {
		$owner = $this->jobs()->find_by_site_mark( Restore\Maintenance::mark_in( (string) ABSPATH ) );
		return null === $owner || $this->jobs()->holds_site( $owner ) ? null : $owner;
	}

	/**
	 * Cron callback: one tick with the budget counted from now under WP-CLI
	 * ("wp cron event run" handles several events per process) and from the
	 * request start otherwise; a tick that starts late in its request may be
	 * put off (JobActions::cron_tick()).
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	public function cron_tick( $job_id ): void {
		try {
			$this->job_actions()->cron_tick( (int) $job_id, JobActions::started_at() );
		} catch ( \Throwable $e ) {
			$this->directories()->log_event( sprintf( 'Cron tick of job %d failed: %s', (int) $job_id, get_class( $e ) ) );
		}
	}

	/**
	 * After a restore's swap (Jobs\SwapStep left StoredNames::AFTER_SWAP in the restored options): the restored
	 * cron option holds none of this installation's fallback events, so the jobs still queued or running get one
	 * again (Loopback::schedule()), in this request, with the restored site loaded rather than in the process that
	 * swapped (which still held the old site's plugins). Read from the autoloaded options already in memory: a
	 * request without the mark costs nothing. On a network, the main site's requests (the events are the main
	 * site's). The mark stays when the jobs cannot be read, for the next request.
	 *
	 * @return void
	 */
	public function after_swap(): void {
		if ( ! function_exists( 'wp_load_alloptions' ) || ( function_exists( 'is_multisite' ) && is_multisite() && ! is_main_site() ) ) {
			return;
		}
		$all = wp_load_alloptions();
		if ( ! is_array( $all ) || ! array_key_exists( Support\StoredNames::AFTER_SWAP, $all ) ) {
			return;
		}
		try {
			foreach ( $this->job_actions()->active() as $job ) {
				if ( in_array( $job->status, array( Job::QUEUED, Job::RUNNING ), true ) && Job::SITE_UNTOUCHED === $job->site_state ) {
					Loopback::schedule( $job->id, Loopback::FALLBACK_SECONDS, Loopback::KEEP );
				}
			}
		} catch ( \Throwable $e ) {
			$this->directories()->log_event( sprintf( 'Setting the fallback events after a restore failed: %s', get_class( $e ) ) );
			return;
		}
		delete_option( Support\StoredNames::AFTER_SWAP );
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
		( new DownloadHandler(
			$this->directories(),
			function (): array {
				return $this->job_actions()->active();
			}
		) )->register();
		( new LogDownload( $this->job_actions(), $this->job_presenter() ) )->register();
		( new Notices( $this->directories() ) )->register();
		( new EnvironmentActions( $this->directories() ) )->register();
		( new ReclaimActions( $this->directories() ) )->register();
		( new SiteIdentityActions( $this->directories() ) )->register();
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
		wp_enqueue_style( 'wpcheckpoint-admin', WPCHECKPOINT_URL . 'assets/admin/admin.css', array(), WPCHECKPOINT_VERSION );
		wp_enqueue_script( 'wpcheckpoint-backups', WPCHECKPOINT_URL . 'assets/admin/backups.js', array( 'wpcheckpoint-jobs' ), WPCHECKPOINT_VERSION, true );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation state.
		$paged   = isset( $_GET['paged'] ) && is_string( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 0;
		$backups = array(
			'url'    => esc_url_raw(
				add_query_arg(
					array(
						'page' => Page::SLUG,
						'tab'  => 'backups',
					),
					Page::base_url()
				)
			),
			'paged'  => $paged,
			'labels' => array(
				'starting'       => __( 'Starting…', 'wp-checkpoint' ),
				'failed'         => __( 'That did not work; reload the page and try again.', 'wp-checkpoint' ),
				'confirm_delete' => __( 'Delete this backup? Its files are removed from the server and cannot be brought back.', 'wp-checkpoint' ),
			),
		);
		wp_add_inline_script( 'wpcheckpoint-backups', 'window.wpcheckpointBackups = ' . wp_json_encode( $backups ) . ';', 'before' );
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
			$this->presenter = new JobPresenter( $this->redactor(), $this->job_types(), $this->directories() );
			$this->presenter->with_held(
				function ( int $id ) {
					return $this->job_actions()->held_elsewhere( $id );
				}
			);
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
	 * Registered job types: the export job, and fixtures in tests.
	 *
	 * @return JobTypes
	 */
	public function job_types(): JobTypes {
		if ( null === $this->job_types ) {
			$this->job_types = new JobTypes();
			$plugin          = $this;
			$this->job_types->add(
				new ExportJob(
					static function () use ( $plugin ): Directories {
						return $plugin->directories();
					},
					static function ( string $text ) use ( $plugin ): string {
						// Fetched when a report is cleaned: the presenter itself needs the job types.
						return $plugin->job_presenter()->clean( $text );
					}
				)
			);
			$this->job_types->add(
				new EstimateJob(
					static function () use ( $plugin ): Directories {
						return $plugin->directories();
					},
					static function ( int $files, int $bytes, int $job ): void {
						Estimate::record( $files, $bytes, time(), $job );
					}
				)
			);
			$this->job_types->add(
				new VerifyJob(
					static function () use ( $plugin ): Directories {
						return $plugin->directories();
					},
					static function ( string $text ) use ( $plugin ): string {
						return $plugin->job_presenter()->clean( $text );
					}
				)
			);
			$this->job_types->add(
				new RestoreJob(
					static function () use ( $plugin ): Directories {
						return $plugin->directories();
					},
					static function ( string $text ) use ( $plugin ): string {
						return $plugin->job_presenter()->clean( $text );
					}
				)
			);
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
			new JobsController( $this->job_actions(), $this->job_presenter(), $this->directories() ),
			new BackupsController( $this->job_actions(), $this->job_presenter(), $this->directories() ),
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
		Schema::ensure( true );
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
