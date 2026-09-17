<?php
/**
 * Admin menu registration.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Admin;

use WPCheckpoint\Support\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the top-level "WP Checkpoint" menu and the plugin list action link.
 */
final class Menu {

	/**
	 * Page to render.
	 *
	 * @var Page
	 */
	private $page;

	/**
	 * Hook suffix returned by add_menu_page(), empty until registered.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Page $page Admin page.
	 */
	public function __construct( Page $page ) {
		$this->page = $page;
	}

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPCHECKPOINT_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Register the menu page.
	 *
	 * @return void
	 */
	public function add_menu_page(): void {
		$this->hook_suffix = (string) add_menu_page(
			__( 'WP Checkpoint', 'wp-checkpoint' ),
			__( 'WP Checkpoint', 'wp-checkpoint' ),
			Guard::capability(),
			Page::SLUG,
			array( $this->page, 'render' ),
			'dashicons-backup',
			75
		);
	}

	/**
	 * Hook suffix of the registered page, empty when not registered or hidden.
	 *
	 * @return string
	 */
	public function hook_suffix(): string {
		return $this->hook_suffix;
	}

	/**
	 * Add a "Settings" link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		if ( ! Guard::current_user_can() ) {
			return $links;
		}
		$url  = $this->page->tab_url( 'settings' );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'wp-checkpoint' ) . '</a>';
		array_unshift( $links, $link );
		return $links;
	}
}
