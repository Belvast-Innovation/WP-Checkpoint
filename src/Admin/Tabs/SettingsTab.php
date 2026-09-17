<?php
/**
 * Settings tab.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Admin\Tabs;

use WPCheckpoint\Admin\Settings;
use WPCheckpoint\Admin\Tab;
use WPCheckpoint\Support\Uninstaller;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings. Stored through the Settings API (options.php), which
 * performs its own capability and nonce checks.
 */
final class SettingsTab implements Tab {

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
	}
}
