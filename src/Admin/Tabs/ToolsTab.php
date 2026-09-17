<?php
/**
 * Tools tab.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Admin\Tabs;

use WPCheckpoint\Admin\EnvironmentActions;
use WPCheckpoint\Admin\Tab;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Check;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Environment;
use WPCheckpoint\Support\Guard;
use WPCheckpoint\Support\Protection;
use WPCheckpoint\Support\Report;

defined( 'ABSPATH' ) || exit;

/**
 * Environment report (T004); search and replace and troubleshooting come
 * later (T091, T093).
 */
final class ToolsTab implements Tab {

	/**
	 * Storage directories (injected so tests can use a fresh instance).
	 *
	 * @var Directories|null
	 */
	private $directories;

	/**
	 * Constructor.
	 *
	 * @param Directories|null $directories Storage directories; defaults to the plugin's instance.
	 */
	public function __construct( $directories = null ) {
		$this->directories = $directories instanceof Directories ? $directories : null;
	}

	/**
	 * URL slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'tools';
	}

	/**
	 * Tab label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Tools', 'wp-checkpoint' );
	}

	/**
	 * Tab content.
	 *
	 * @return void
	 */
	public function render(): void {
		$plugin      = Plugin::instance();
		$directories = null !== $this->directories ? $this->directories : $plugin->directories();
		$environment = new Environment( $directories );
		$checks      = $environment->checks();
		$summary     = Check::summarize( $checks );
		$report      = Report::text( $checks, $plugin->redactor(), $environment->report_paths(), array( 'Plugin' => WPCHECKPOINT_VERSION ), Environment::report_hosts() );
		$checked_at  = $environment->checked_at();
		$state       = $directories->state();

		$this->render_result_notice();
		?>
		<h2><?php esc_html_e( 'Environment', 'wp-checkpoint' ); ?></h2>
		<p class="wpcheckpoint-summary">
			<?php
			printf(
				/* translators: 1: ok count, 2: warning count, 3: error count */
				esc_html__( '%1$d ok, %2$d warnings, %3$d errors.', 'wp-checkpoint' ),
				(int) $summary[ Check::OK ],
				(int) $summary[ Check::WARNING ],
				(int) $summary[ Check::ERROR ]
			);
			if ( $checked_at > 0 ) {
				echo ' ';
				/* translators: %s: human time difference */
				printf( esc_html__( 'Loopback and database probed %s ago.', 'wp-checkpoint' ), esc_html( human_time_diff( $checked_at ) ) );
			}
			?>
		</p>

		<div class="wpcheckpoint-actions">
			<?php $this->render_action_form( EnvironmentActions::ACTION_RECHECK, EnvironmentActions::NONCE_RECHECK, __( 'Re-check', 'wp-checkpoint' ), EnvironmentActions::seconds_locked( 'recheck' ) ); ?>
			<?php $this->render_action_form( EnvironmentActions::ACTION_VERIFY, EnvironmentActions::NONCE_VERIFY, __( 'Re-verify directory protection', 'wp-checkpoint' ), EnvironmentActions::seconds_locked( 'verify' ) ); ?>
		</div>

		<?php
		$group = null;
		foreach ( $checks as $check ) {
			if ( $check->group !== $group ) {
				if ( null !== $group ) {
					echo '</tbody></table>';
				}
				$group = $check->group;
				echo '<table class="widefat striped wpcheckpoint-environment"><thead><tr><th colspan="3">' . esc_html( $this->group_label( $group ) ) . '</th></tr></thead><tbody>';
			}
			?>
			<tr class="wpcheckpoint-status-<?php echo esc_attr( $check->status ); ?>">
				<th scope="row"><?php echo esc_html( $check->label ); ?></th>
				<td><?php echo esc_html( $check->value ); ?></td>
				<td>
					<span class="wpcheckpoint-badge"><?php echo esc_html( $this->status_label( $check->status ) ); ?></span>
					<?php if ( '' !== $check->message ) : ?>
						<p class="description"><?php echo esc_html( $check->message ); ?></p>
					<?php endif; ?>
					<?php if ( '' !== $check->impact ) : ?>
						<p class="description"><em><?php echo esc_html( $check->impact ); ?></em></p>
					<?php endif; ?>
				</td>
			</tr>
			<?php
		}
		if ( null !== $group ) {
			echo '</tbody></table>';
		}

		$this->render_server_rule( $state );
		?>

		<h3><?php esc_html_e( 'Report for support requests', 'wp-checkpoint' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Paths are replaced by placeholders and credentials, keys and e-mail addresses are removed. The site address is not included.', 'wp-checkpoint' ); ?></p>
		<textarea id="wpcheckpoint-report" class="large-text code" rows="18" readonly><?php echo esc_textarea( $report ); ?></textarea>
		<p><button type="button" class="button" data-wpcheckpoint-copy="wpcheckpoint-report"><?php esc_html_e( 'Copy report', 'wp-checkpoint' ); ?></button></p>
		<?php
	}

	/**
	 * One-line notice after an action redirected back.
	 *
	 * @return void
	 */
	private function render_result_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect.
		$result   = isset( $_GET[ EnvironmentActions::RESULT_PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ EnvironmentActions::RESULT_PARAM ] ) ) : '';
		$messages = array(
			'rechecked' => array( 'success', __( 'Environment re-checked.', 'wp-checkpoint' ) ),
			'verified'  => array( 'success', __( 'Directory protection re-verified.', 'wp-checkpoint' ) ),
			'locked'    => array( 'warning', __( 'That check ran less than a minute ago; please wait before running it again.', 'wp-checkpoint' ) ),
		);
		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}
		list( $type, $text ) = $messages[ $result ];
		echo '<div class="notice notice-' . esc_attr( $type ) . ' inline"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * A one-button admin-post form.
	 *
	 * @param string $action  admin-post action.
	 * @param string $nonce   Nonce action (without prefix).
	 * @param string $label   Button label.
	 * @param int    $locked  Seconds until allowed again.
	 * @return void
	 */
	private function render_action_form( string $action, string $nonce, string $label, int $locked ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpcheckpoint-inline-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( Guard::nonce( $nonce ) ); ?>" />
			<button type="submit" class="button"<?php disabled( $locked > 0 ); ?>>
				<?php echo esc_html( $label ); ?>
				<?php if ( $locked > 0 ) : ?>
					<?php /* translators: %d: seconds */ ?>
					<?php echo esc_html( sprintf( __( '(available in %d s)', 'wp-checkpoint' ), $locked ) ); ?>
				<?php endif; ?>
			</button>
		</form>
		<?php
	}

	/**
	 * Server rule shown when the directory is exposed or unverified.
	 *
	 * @param array<string, mixed> $state Storage state.
	 * @return void
	 */
	private function render_server_rule( array $state ): void {
		$verification = is_array( $state['verification'] ) ? $state['verification'] : array();
		$status       = isset( $verification['status'] ) ? (string) $verification['status'] : '';
		if ( ! in_array( $status, array( Protection::STATUS_EXPOSED, Protection::STATUS_UNVERIFIED ), true ) || '' === (string) $state['path'] ) {
			return;
		}
		$server = Protection::server();
		if ( 'apache' === $server || 'litespeed' === $server ) {
			echo '<p class="description">' . esc_html__( 'Apache/LiteSpeed: make sure the virtual host allows .htaccess files (AllowOverride Limit or All) for wp-content.', 'wp-checkpoint' ) . '</p>';
			return;
		}
		?>
		<p class="description"><?php esc_html_e( 'nginx: add this rule to the server block, then reload nginx and use "Re-verify directory protection".', 'wp-checkpoint' ); ?></p>
		<pre class="code"><?php echo esc_html( Protection::nginx_snippet( basename( (string) $state['path'] ) ) ); ?></pre>
		<?php
	}

	/**
	 * Translated group heading.
	 *
	 * @param string $group Group id.
	 * @return string
	 */
	private function group_label( string $group ): string {
		$labels = array(
			Environment::GROUP_WORDPRESS => __( 'WordPress', 'wp-checkpoint' ),
			Environment::GROUP_PHP       => __( 'PHP', 'wp-checkpoint' ),
			Environment::GROUP_LIMITS    => __( 'Limits', 'wp-checkpoint' ),
			Environment::GROUP_DATABASE  => __( 'Database', 'wp-checkpoint' ),
			Environment::GROUP_STORAGE   => __( 'Storage', 'wp-checkpoint' ),
			Environment::GROUP_LOOPBACK  => __( 'Connectivity', 'wp-checkpoint' ),
			Environment::GROUP_SERVER    => __( 'Server', 'wp-checkpoint' ),
		);
		return isset( $labels[ $group ] ) ? $labels[ $group ] : ucfirst( $group );
	}

	/**
	 * Translated status label.
	 *
	 * @param string $status Status constant.
	 * @return string
	 */
	private function status_label( string $status ): string {
		$labels = array(
			Check::OK      => __( 'OK', 'wp-checkpoint' ),
			Check::WARNING => __( 'Warning', 'wp-checkpoint' ),
			Check::ERROR   => __( 'Error', 'wp-checkpoint' ),
			Check::INFO    => __( 'Info', 'wp-checkpoint' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}
}
