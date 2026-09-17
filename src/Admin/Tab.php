<?php
/**
 * Admin page tab contract.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Admin;

/**
 * One tab on the WP Checkpoint admin page.
 */
interface Tab {

	/**
	 * URL slug, lowercase letters and dashes only.
	 *
	 * @return string
	 */
	public function slug(): string;

	/**
	 * Translated label shown in the tab bar.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Print the tab content. Output must be escaped.
	 *
	 * @return void
	 */
	public function render(): void;
}
