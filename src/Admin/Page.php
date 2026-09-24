<?php
/**
 * The WP Checkpoint admin page.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Admin;

use WPCheckpoint\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the tab bar and the active tab.
 */
final class Page {

	/**
	 * Menu slug and page query value.
	 */
	const SLUG = 'wp-checkpoint';

	/**
	 * Registered tabs.
	 *
	 * @var Tabs
	 */
	private $tabs;

	/**
	 * Constructor.
	 *
	 * @param Tabs $tabs Built-in tabs.
	 */
	public function __construct( Tabs $tabs ) {
		$this->tabs = $tabs;
	}

	/**
	 * Tabs after other plugins had a chance to add their own.
	 *
	 * @return Tabs
	 */
	public function tabs(): Tabs {
		/**
		 * Filters the tabs shown on the WP Checkpoint admin page.
		 *
		 * @param Tab[] $tabs Tabs in display order.
		 */
		$filtered = apply_filters( 'wpcheckpoint_admin_tabs', $this->tabs->all() );

		$tabs = new Tabs();
		foreach ( (array) $filtered as $tab ) {
			if ( $tab instanceof Tab ) {
				$tabs->add( $tab );
			}
		}
		return $tabs;
	}

	/**
	 * Slug requested through the URL, sanitized.
	 *
	 * @return string
	 */
	public function requested_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation state.
		if ( ! isset( $_GET['tab'] ) || ! is_string( $_GET['tab'] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		return sanitize_key( wp_unslash( $_GET['tab'] ) );
	}

	/**
	 * The admin.php the page lives under. On multisite the page is only in
	 * the network admin: a backup covers every site and a restore overwrites
	 * every site, so no single site's dashboard offers it (the REST routes and
	 * the admin-post handlers require the network capability either way).
	 *
	 * @return string
	 */
	public static function base_url(): string {
		return is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );
	}

	/**
	 * The admin-post.php the page's forms and download links go to. Core has
	 * no network admin-post.php; on multisite it is the one under the
	 * network's own address, the host and path the network admin itself is
	 * served from (network_admin_url() is built the same way), so the login
	 * cookie reaches it whatever site the link was printed on, also where the
	 * main site's address is mapped to another domain.
	 *
	 * @return string
	 */
	public static function post_url(): string {
		return is_multisite() ? network_site_url( 'wp-admin/admin-post.php', 'admin' ) : admin_url( 'admin-post.php' );
	}

	/**
	 * Whether a screen id is the plugin page's (network admin adds "-network").
	 *
	 * @param string $screen_id Screen id.
	 * @return bool
	 */
	public static function is_screen( string $screen_id ): bool {
		return in_array( $screen_id, array( 'toplevel_page_' . self::SLUG, 'toplevel_page_' . self::SLUG . '-network' ), true );
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $slug Tab slug.
	 * @return string
	 */
	public function tab_url( string $slug ): string {
		return add_query_arg(
			array(
				'page' => self::SLUG,
				'tab'  => $slug,
			),
			self::base_url()
		);
	}

	/**
	 * Print the page.
	 *
	 * @return void
	 */
	public function render(): void {
		Plugin::instance()->directories()->base();
		\WPCheckpoint\Support\Schema::ensure();
		Plugin::instance()->jobs()->maintenance();
		$tabs   = $this->tabs();
		$active = $tabs->resolve( $this->requested_tab() );
		?>
		<div class="wrap wpcheckpoint-wrap">
			<h1><?php esc_html_e( 'WP Checkpoint', 'wp-checkpoint' ); ?></h1>
			<?php settings_errors(); ?>
			<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Secondary menu', 'wp-checkpoint' ); ?>">
				<?php foreach ( $tabs->all() as $tab ) : ?>
					<?php $is_active = ( $active && $tab->slug() === $active->slug() ); ?>
					<a href="<?php echo esc_url( $this->tab_url( $tab->slug() ) ); ?>" class="nav-tab<?php echo $is_active ? ' nav-tab-active' : ''; ?>"<?php echo $is_active ? ' aria-current="page"' : ''; ?>>
						<?php echo esc_html( $tab->label() ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<div class="wpcheckpoint-tab-content">
				<?php
				if ( $active ) {
					$active->render();
				}
				?>
			</div>
		</div>
		<?php
	}
}
