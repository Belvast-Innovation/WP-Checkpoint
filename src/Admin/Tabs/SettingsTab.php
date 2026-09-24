<?php
/**
 * Settings tab.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Admin\Tabs;

use WPCheckpoint\Admin\Page;
use WPCheckpoint\Admin\ReclaimActions;
use WPCheckpoint\Admin\EnvironmentActions;
use WPCheckpoint\Admin\SettingsActions;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Guard;
use WPCheckpoint\Admin\Tab;
use WPCheckpoint\Support\UninstallSetting;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings. Saved through one admin-post action (SettingsActions) so
 * the same form works for site options and, on multisite, network options.
 */
final class SettingsTab implements Tab {

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
		return 'settings';
	}

	/**
	 * Tab label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Settings', 'wp-checkpoint' );
	}

	/**
	 * Settings form.
	 *
	 * @return void
	 */
	public function render(): void {
		$option  = UninstallSetting::OPTION;
		$checked = UninstallSetting::enabled();
		$this->render_saved_notice();
		?>
		<form method="post" action="<?php echo esc_url( Page::post_url() ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( SettingsActions::ACTION ); ?>" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( Guard::nonce( SettingsActions::NONCE_ACTION ) ); ?>" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Uninstall', 'wp-checkpoint' ); ?></th>
					<td>
						<label for="<?php echo esc_attr( $option ); ?>">
							<input type="checkbox" name="<?php echo esc_attr( $option ); ?>" id="<?php echo esc_attr( $option ); ?>" value="1" <?php checked( $checked ); ?> />
							<?php esc_html_e( 'Also delete all backups, checkpoints and settings when the plugin is uninstalled.', 'wp-checkpoint' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Off by default: deleting the plugin keeps your backups on the server so a reinstall can use them. Turn this on only if you are sure you no longer need them.', 'wp-checkpoint' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
		$this->render_trusted_root();
	}

	/**
	 * One-line confirmation after saving.
	 *
	 * @return void
	 */
	private function render_saved_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect.
		$result = isset( $_GET[ EnvironmentActions::RESULT_PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ EnvironmentActions::RESULT_PARAM ] ) ) : '';
		if ( SettingsActions::RESULT === $result ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Settings saved.', 'wp-checkpoint' ) . '</p></div>';
		}
	}

	/**
	 * Show and allow revoking the trusted deployment root.
	 *
	 * @return void
	 */
	private function render_trusted_root(): void {
		$directories = null !== $this->directories ? $this->directories : Plugin::instance()->directories();
		$root        = (string) $directories->state()['trusted_deploy_root'];
		if ( '' === $root ) {
			return;
		}
		?>
		<h3><?php esc_html_e( 'Release deployments', 'wp-checkpoint' ); ?></h3>
		<p>
			<?php /* translators: %s: directory */ echo esc_html( sprintf( __( 'When the WordPress directory changes to another folder under %s, the original storage directory is reused automatically.', 'wp-checkpoint' ), $root ) ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( Page::post_url() ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( ReclaimActions::ACTION_UNTRUST ); ?>" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( Guard::nonce( ReclaimActions::NONCE_UNTRUST ) ); ?>" />
			<button type="submit" class="button"><?php esc_html_e( 'Stop trusting this deployment root', 'wp-checkpoint' ); ?></button>
		</form>
		<?php
	}
}
