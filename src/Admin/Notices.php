<?php
/**
 * Storage notices shown on the plugin's own admin page.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Admin;

use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Guard;
use WPCheckpoint\Support\Protection;

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
	 * @return array<string, array{type: string, message: string, extra: string, dismissible: bool}>
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
			$notices['clone_detected'] = array(
				'type'        => 'warning',
				'message'     => __( 'This site looks like a clone or a migrated copy: the stored backup directory belongs to another installation. WP Checkpoint left it untouched and now uses a new directory.', 'wp-checkpoint' ),
				'extra'       => '' !== $state['previous_path'] ? $state['previous_path'] : '',
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

		foreach ( $this->notices() as $id => $notice ) {
			if ( $notice['dismissible'] && isset( $dismissed[ $id ] ) && $dismissed[ $id ] === $token ) {
				continue;
			}
			?>
			<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> wpcheckpoint-notice">
				<p><?php echo esc_html( $notice['message'] ); ?></p>
				<?php if ( '' !== $notice['extra'] ) : ?>
					<pre class="wpcheckpoint-notice-extra"><?php echo esc_html( $notice['extra'] ); ?></pre>
				<?php endif; ?>
				<?php if ( $notice['dismissible'] ) : ?>
					<p><a class="button button-small" href="<?php echo esc_url( $this->dismiss_url( $id ) ); ?>"><?php esc_html_e( 'Dismiss', 'wp-checkpoint' ); ?></a></p>
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
