<?php
/**
 * Storage notices shown on the plugin's own admin page.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Admin;

use WPCheckpoint\Support\CloneClassifier;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Guard;
use WPCheckpoint\Support\Protection;
use WPCheckpoint\Support\UninstallSetting;
use WPCheckpoint\Admin\ReclaimActions;

defined( 'ABSPATH' ) || exit;

/**
 * Warns about a detected clone, an exposed or unverified directory, or
 * unusable storage. Notices never leave the plugin page and can be closed;
 * the choice is remembered per user and per storage token.
 */
final class Notices {

	const DISMISS_ACTION = 'wpcheckpoint_dismiss_notice';
	const NONCE_ACTION   = 'dismiss-notice';
	const USER_META      = 'wpcheckpoint_dismissed_notices';

	/**
	 * Storage directories.
	 *
	 * @var Directories
	 */
	private $directories;

	/**
	 * Constructor.
	 *
	 * @param Directories $directories Storage directories.
	 */
	public function __construct( Directories $directories ) {
		$this->directories = $directories;
	}

	/**
	 * Hook rendering and dismissal.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'dismiss' ) );
	}

	/**
	 * Notices for the current state, keyed by id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function notices(): array {
		$state   = $this->directories->state();
		$error   = $this->directories->last_error();
		$notices = array();

		if ( '' !== $error ) {
			$notices['storage_error'] = array(
				'type'        => 'error',
				'message'     => __( 'WP Checkpoint cannot use its storage directory, so backups cannot run.', 'wp-checkpoint' ) . ' ' . $error,
				'extra'       => '',
				'dismissible' => false,
			);
		}

		if ( ! empty( $state['clone_detected'] ) ) {
			$reclaim = $this->directories->reclaim();
			$verdict = $reclaim->classify();
			if ( CloneClassifier::CLONE === $verdict['verdict'] ) {
				$message = __( 'This site looks like a copy: the WordPress directory changed and the previous one still exists. WP Checkpoint left the previous backup directory untouched and now uses a new one.', 'wp-checkpoint' );
			} else {
				$message = __( 'The WordPress directory changed (a deployment or a move?). WP Checkpoint left the previous backup directory untouched and now uses a new one. If this is the same site, you can continue with the original directory.', 'wp-checkpoint' );
			}
			$notices['clone_detected'] = array(
				'type'        => 'warning',
				'message'     => $message,
				'extra'       => '' !== $state['previous_path'] ? $state['previous_path'] : '',
				'dismissible' => true,
				'link'        => $reclaim->marker_install_id_matches( (string) $state['previous_path'] ) ? array( ReclaimActions::confirmation_url(), __( 'This is the same site: review and continue with the original directory', 'wp-checkpoint' ) ) : array(),
				'dismiss'     => __( 'Keep the new directory', 'wp-checkpoint' ),
			);
		}

		$reason = UninstallSetting::notice_reason();
		if ( '' !== $reason ) {
			$notices['uninstall_setting'] = array(
				'type'        => 'warning',
				'message'     => UninstallSetting::NOTICE_LEFTOVER === $reason
					? __( 'On multisite, the setting to delete data on uninstall is now network-wide and starts switched off. A site-level setting was found; please confirm the network-wide setting on the Settings tab.', 'wp-checkpoint' )
					: __( 'On multisite, the setting to delete data on uninstall is now network-wide and has been initialised to off. Please confirm it on the Settings tab.', 'wp-checkpoint' ),
				'extra'       => '',
				'dismissible' => true,
				'link'        => array(
					add_query_arg(
						array(
							'page' => Page::SLUG,
							'tab'  => 'settings',
						),
						admin_url( 'admin.php' )
					),
					__( 'Open the Settings tab', 'wp-checkpoint' ),
				),
			);
		}

		if ( ! empty( $state['auto_reclaimed'] ) && is_array( $state['auto_reclaimed'] ) ) {
			$notices['auto_reclaimed'] = array(
				'type'        => 'info',
				'message'     => __( 'A new release was deployed and WP Checkpoint continued with the original storage directory automatically (trusted deployment root).', 'wp-checkpoint' ),
				'extra'       => (string) $state['auto_reclaimed']['from'] . ' → ' . (string) $state['auto_reclaimed']['to'],
				'dismissible' => true,
			);
		}

		$verification = is_array( $state['verification'] ) ? $state['verification'] : array();
		$status       = isset( $verification['status'] ) ? $verification['status'] : '';
		$under_web    = in_array( $state['source'], array( Directories::SOURCE_CONTENT, Directories::SOURCE_CUSTOM ), true ) && '' !== Protection::url_for( (string) $state['path'] );

		if ( $under_web && Protection::STATUS_EXPOSED === $status ) {
			$notices['exposed'] = array(
				'type'        => 'error',
				'message'     => __( 'Backups in the storage directory can be downloaded by anyone. Your web server ignores the .htaccess file; add the rule below to your server configuration and reload it.', 'wp-checkpoint' ),
				'extra'       => $this->server_hint( (string) $state['path'] ),
				'dismissible' => true,
			);
		} elseif ( $under_web && Protection::STATUS_UNVERIFIED === $status ) {
			$notices['unverified'] = array(
				'type'        => 'warning',
				'message'     => __( 'WP Checkpoint could not confirm that the storage directory is protected from direct download. If your server is not Apache or LiteSpeed, add the rule below.', 'wp-checkpoint' ),
				'extra'       => $this->server_hint( (string) $state['path'] ),
				'dismissible' => true,
			);
		}

		return $notices;
	}

	/**
	 * Print the notices on the plugin page.
	 *
	 * @return void
	 */
	public function render(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'toplevel_page_' . Page::SLUG !== $screen->id || ! Guard::current_user_can() ) {
			return;
		}
		$dismissed = $this->dismissed();
		$token     = (string) $this->directories->state()['token'];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation state.
		$on_tools = isset( $_GET['tab'] ) && 'tools' === sanitize_key( wp_unslash( $_GET['tab'] ) );

		foreach ( $this->notices() as $id => $notice ) {
			if ( $notice['dismissible'] && isset( $dismissed[ $id ] ) && $dismissed[ $id ] === $token ) {
				continue;
			}
			// The Tools tab shows the same facts as rows with actions; avoid two copies of the server rule.
			if ( $on_tools && in_array( $id, array( 'exposed', 'unverified', 'clone_detected' ), true ) ) {
				continue;
			}
			?>
			<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> wpcheckpoint-notice">
				<p><?php echo esc_html( $notice['message'] ); ?></p>
				<?php if ( '' !== $notice['extra'] ) : ?>
					<pre class="wpcheckpoint-notice-extra"><?php echo esc_html( $notice['extra'] ); ?></pre>
				<?php endif; ?>
				<?php if ( ! empty( $notice['link'] ) ) : ?>
					<p><a class="button button-primary button-small" href="<?php echo esc_url( $notice['link'][0] ); ?>"><?php echo esc_html( $notice['link'][1] ); ?></a>
				<?php elseif ( $notice['dismissible'] ) : ?>
					<p>
				<?php endif; ?>
				<?php if ( $notice['dismissible'] ) : ?>
					<a class="button button-small" href="<?php echo esc_url( $this->dismiss_url( $id ) ); ?>"><?php echo esc_html( isset( $notice['dismiss'] ) ? $notice['dismiss'] : __( 'Dismiss', 'wp-checkpoint' ) ); ?></a></p>
				<?php endif; ?>
			</div>
			<?php
		}
	}

	/**
	 * The admin-post handler that records a dismissal.
	 *
	 * @return void
	 */
	public function dismiss(): void {
		Guard::require_admin_post( self::NONCE_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by require_admin_post() above.
		$id = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		$this->record_dismissal( $id );

		wp_safe_redirect( add_query_arg( 'page', Page::SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Store the dismissal for the current user; a clone notice is acknowledged in the state instead.
	 *
	 * @param string $id Notice id.
	 * @return void
	 */
	public function record_dismissal( string $id ): void {
		if ( ! array_key_exists( $id, $this->notices() ) ) {
			return;
		}
		if ( 'clone_detected' === $id ) {
			$this->directories->acknowledge_clone();
			return;
		}
		if ( 'auto_reclaimed' === $id ) {
			$this->directories->clear_auto_reclaimed();
			return;
		}
		$dismissed        = $this->dismissed();
		$dismissed[ $id ] = (string) $this->directories->state()['token'];
		update_user_meta( get_current_user_id(), self::USER_META, $dismissed );
	}

	/**
	 * Dismissals stored for the current user.
	 *
	 * @return array<string, string>
	 */
	private function dismissed(): array {
		$meta = get_user_meta( get_current_user_id(), self::USER_META, true );
		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * URL of the dismiss action.
	 *
	 * @param string $id Notice id.
	 * @return string
	 */
	private function dismiss_url( string $id ): string {
		return add_query_arg(
			array(
				'action'   => self::DISMISS_ACTION,
				'notice'   => $id,
				'_wpnonce' => Guard::nonce( self::NONCE_ACTION ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Configuration hint for the detected server.
	 *
	 * @param string $path Storage base directory.
	 * @return string
	 */
	private function server_hint( string $path ): string {
		$server = Protection::server();
		if ( 'apache' === $server || 'litespeed' === $server ) {
			return __( 'Apache/LiteSpeed: make sure the virtual host allows .htaccess files (AllowOverride Limit or All) for wp-content.', 'wp-checkpoint' );
		}
		return "# nginx\n" . Protection::nginx_snippet( basename( $path ) );
	}
}
