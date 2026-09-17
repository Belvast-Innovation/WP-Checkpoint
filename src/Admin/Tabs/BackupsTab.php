<?php
/**
 * Backups tab.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Admin\Tabs;

use WPCheckpoint\Admin\Tab;

defined( 'ABSPATH' ) || exit;

/**
 * Create, download and restore backups (T040, T060).
 */
final class BackupsTab implements Tab {

	/**
	 * URL slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'backups';
	}

	/**
	 * Tab label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Backups', 'wp-checkpoint' );
	}

	/**
	 * Tab content.
	 *
	 * @return void
	 */
	public function render(): void {
		echo '<p>' . esc_html__( 'Backups will appear here.', 'wp-checkpoint' ) . '</p>';
	}
}
