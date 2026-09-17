<?php
/**
 * Tools tab.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Admin\Tabs;

use WPCheckpoint\Admin\Tab;

defined( 'ABSPATH' ) || exit;

/**
 * Environment report, search and replace, troubleshooting (T004, T091, T093).
 */
final class ToolsTab implements Tab {

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
		echo '<p>' . esc_html__( 'Tools will appear here.', 'wp-checkpoint' ) . '</p>';
	}
}
