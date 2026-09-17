<?php
/**
 * Settings tab.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Admin\Tabs;

use WPCheckpoint\Admin\ReclaimActions;
use WPCheckpoint\Admin\Settings;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Guard;
use WPCheckpoint\Admin\Tab;
use WPCheckpoint\Support\Uninstaller;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings. Stored through the Settings API (options.php), which
 * performs its own capability and nonce checks.
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
		$option  = Uninstaller::OPTION_DELETE_DATA;
		$checked = Uninstaller::should_delete_data();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( Settings::GROUP ); ?>
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
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( ReclaimActions::ACTION_UNTRUST ); ?>" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( Guard::nonce( ReclaimActions::NONCE_UNTRUST ) ); ?>" />
			<button type="submit" class="button"><?php esc_html_e( 'Stop trusting this deployment root', 'wp-checkpoint' ); ?></button>
		</form>
		<?php
	}
}
