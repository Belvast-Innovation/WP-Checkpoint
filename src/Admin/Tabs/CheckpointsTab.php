<?php
/**
 * Checkpoints tab.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Admin\Tabs;

use WPCheckpoint\Admin\Tab;

defined( 'ABSPATH' ) || exit;

/**
 * Checkpoints taken before risky changes (T090).
 */
final class CheckpointsTab implements Tab {

	/**
	 * URL slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'checkpoints';
	}

	/**
	 * Tab label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Checkpoints', 'wp-checkpoint' );
	}

	/**
	 * Tab content.
	 *
	 * @return void
	 */
	public function render(): void {
		echo '<p>' . esc_html__( 'Checkpoints will appear here.', 'wp-checkpoint' ) . '</p>';
	}
}
