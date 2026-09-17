<?php
/**
 * Tools tab.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Admin\Tabs;

use WPCheckpoint\Admin\EnvironmentActions;
use WPCheckpoint\Admin\ReclaimActions;
use WPCheckpoint\Admin\Tab;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Check;
use WPCheckpoint\Support\CloneClassifier;
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
		$site_paths  = Environment::report_site_paths();
		$report      = Report::text(
			$checks,
			$plugin->redactor(),
			$environment->report_paths(),
			array( 'Plugin' => WPCHECKPOINT_VERSION ),
			Environment::report_hosts(),
			$site_paths['paths'],
			array(
				'coarse_site_paths' => $site_paths['coarse'],
				'network_root'      => $site_paths['network_root'],
			)
		);
		$checked_at  = $environment->checked_at();
		$state       = $directories->state();

		$this->render_result_notice();
		$this->render_reclaim_section( $directories );
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
		$detail   = get_site_transient( 'wpcheckpoint_reclaim_message_' . get_current_user_id() );
		$detail   = is_string( $detail ) ? $detail : '';
		$messages = array(
			'rechecked'      => array( 'success', __( 'Environment re-checked.', 'wp-checkpoint' ) ),
			'verified'       => array( 'success', __( 'Directory protection re-verified.', 'wp-checkpoint' ) ),
			'locked'         => array( 'warning', __( 'That check ran less than a minute ago; please wait before running it again.', 'wp-checkpoint' ) ),
			'reclaimed'      => array( 'success', '' === $detail ? __( 'The original storage directory is in use again.', 'wp-checkpoint' ) : $detail ),
			'reclaim_failed' => array( 'error', '' === $detail ? __( 'The original storage directory could not be reclaimed.', 'wp-checkpoint' ) : $detail ),
		);
		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}
		if ( in_array( $result, array( 'reclaimed', 'reclaim_failed' ), true ) ) {
			delete_site_transient( 'wpcheckpoint_reclaim_message_' . get_current_user_id() );
		}
		list( $type, $text ) = $messages[ $result ];
		echo '<div class="notice notice-' . esc_attr( $type ) . ' inline"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Confirmation block for reclaiming the original directory. Rendering
	 * changes nothing; the POST goes to ReclaimActions.
	 *
	 * @param Directories $directories Storage directories.
	 * @return void
	 */
	private function render_reclaim_section( Directories $directories ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only navigation flag.
		if ( ! isset( $_GET[ ReclaimActions::QUERY_FLAG ] ) || ! Guard::current_user_can() ) {
			return;
		}
		$reclaim = $directories->reclaim();
		$target  = $reclaim->target();
		if ( '' === $target ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'There is no storage directory waiting to be reclaimed.', 'wp-checkpoint' ) . '</p></div>';
			return;
		}
		$checks   = $reclaim->prechecks();
		$facts    = $checks['facts'];
		$verdict  = $reclaim->classify();
		$state    = $directories->state();
		$expected = ReclaimActions::expected_token( $target );
		$verdicts = array(
			CloneClassifier::DEPLOYMENT => __( 'The previous and the current WordPress directory are siblings under the same parent, which is how release-based deployments (Deployer, Capistrano, Trellis) work. The previous release may still exist on disk. If the old directory no longer serves this site, continuing with the original storage directory is the right choice.', 'wp-checkpoint' ),
			CloneClassifier::MOVED      => __( 'The previous WordPress directory no longer exists or no longer holds WordPress. This looks like a move or a migration; continuing with the original storage directory is usually right.', 'wp-checkpoint' ),
			CloneClassifier::CLONE      => __( 'The previous WordPress directory still exists and still holds WordPress somewhere else. This looks like a copy of the site; unless you know the other copy is gone, keep the new directory.', 'wp-checkpoint' ),
		);
		?>
		<div class="wpcheckpoint-reclaim">
			<h2><?php esc_html_e( 'Continue with the original storage directory?', 'wp-checkpoint' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<tr><th scope="row"><?php esc_html_e( 'Original directory', 'wp-checkpoint' ); ?></th><td><code><?php echo esc_html( $target ); ?></code></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Previous WordPress directory', 'wp-checkpoint' ); ?></th><td><code><?php echo esc_html( (string) $state['previous_abspath'] ); ?></code> <?php echo $verdict['previous_exists'] ? esc_html__( '(still exists)', 'wp-checkpoint' ) : esc_html__( '(no longer exists)', 'wp-checkpoint' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Current WordPress directory', 'wp-checkpoint' ); ?></th><td><code><?php echo esc_html( $directories->context()['abspath'] ); ?></code></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Assessment', 'wp-checkpoint' ); ?></th><td><?php echo esc_html( $verdicts[ $verdict['verdict'] ] ); ?> <strong><?php echo CloneClassifier::RECOMMEND_ORIGINAL === $verdict['recommendation'] ? esc_html__( 'Recommended: continue with the original directory.', 'wp-checkpoint' ) : esc_html__( 'Recommended: keep the new directory.', 'wp-checkpoint' ); ?></strong></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Backups in it', 'wp-checkpoint' ); ?></th><td><?php echo esc_html( (string) $facts['backups'] ); ?>
					<?php
					if ( $facts['latest_backup'] > 0 ) :
						?>
						(<?php /* translators: %s: human time difference */ echo esc_html( sprintf( __( 'newest %s ago', 'wp-checkpoint' ), human_time_diff( (int) $facts['latest_backup'] ) ) ); ?>)<?php endif; ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Log files', 'wp-checkpoint' ); ?></th><td><?php echo esc_html( (string) $facts['logs'] ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Owner marker install ID', 'wp-checkpoint' ); ?></th><td><?php echo $facts['install_id_matches'] ? esc_html__( 'matches this installation', 'wp-checkpoint' ) : esc_html__( 'belongs to another installation', 'wp-checkpoint' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Writable', 'wp-checkpoint' ); ?></th><td><?php echo $facts['writable'] ? esc_html__( 'yes', 'wp-checkpoint' ) : esc_html__( 'no', 'wp-checkpoint' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Job running in it', 'wp-checkpoint' ); ?></th><td><?php echo $facts['busy'] ? esc_html__( 'yes', 'wp-checkpoint' ) : esc_html__( 'no', 'wp-checkpoint' ); ?></td></tr>
				</tbody>
			</table>
			<?php if ( ! $checks['ok'] ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( implode( ' ', $checks['problems'] ) ); ?></p></div>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( ReclaimActions::ACTION_RECLAIM ); ?>" />
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( Guard::nonce( ReclaimActions::NONCE_RECLAIM ) ); ?>" />
					<p><label><input type="checkbox" name="wpcheckpoint_confirm" value="1" /> <?php esc_html_e( 'I understand that the other copy of this site (if any) will lose access to these backups.', 'wp-checkpoint' ); ?></label></p>
					<p><label for="wpcheckpoint_token"><?php /* translators: %s: directory name */ echo esc_html( sprintf( __( 'Type the random part of the directory name (%s) to confirm:', 'wp-checkpoint' ), basename( $target ) ) ); ?></label> <input type="text" id="wpcheckpoint_token" name="wpcheckpoint_token" autocomplete="off" size="<?php echo esc_attr( (string) max( 12, strlen( $expected ) ) ); ?>" /></p>
					<?php if ( '' !== $verdict['deploy_root'] ) : ?>
						<p><label><input type="checkbox" name="wpcheckpoint_trust_root" value="1" /> <?php /* translators: %s: directory */ echo esc_html( sprintf( __( 'This site uses release-directory deployments: from now on, when the WordPress directory changes to another folder under %s, continue with the original storage directory automatically.', 'wp-checkpoint' ), $verdict['deploy_root'] ) ); ?></label></p>
					<?php endif; ?>
					<p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Continue with the original directory', 'wp-checkpoint' ); ?></button>
						<a class="button" href="
						<?php
						echo esc_url(
							add_query_arg(
								array(
									'page' => \WPCheckpoint\Admin\Page::SLUG,
									'tab'  => 'tools',
								),
								admin_url( 'admin.php' )
							)
						);
						?>
												"><?php esc_html_e( 'Cancel', 'wp-checkpoint' ); ?></a>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
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
