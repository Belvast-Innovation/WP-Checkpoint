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
			admin_url( 'admin.php' )
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
