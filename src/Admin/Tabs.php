<?php
/**
 * Ordered tab registry.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Admin;

/**
 * Holds the tabs in display order and resolves the active one.
 *
 * Pure PHP so it can be unit tested; the WordPress filter that lets other
 * plugins add tabs is applied by the Page, not here.
 */
final class Tabs {

	/**
	 * Tabs keyed by slug, in insertion order.
	 *
	 * @var array<string, Tab>
	 */
	private $tabs = array();

	/**
	 * Add a tab. A tab with the same slug replaces the earlier one in place.
	 *
	 * @param Tab $tab Tab to add.
	 * @return void
	 */
	public function add( Tab $tab ): void {
		$this->tabs[ $tab->slug() ] = $tab;
	}

	/**
	 * All tabs in display order.
	 *
	 * @return Tab[]
	 */
	public function all(): array {
		return array_values( $this->tabs );
	}

	/**
	 * Whether a tab with this slug exists.
	 *
	 * @param string $slug Tab slug.
	 * @return bool
	 */
	public function has( string $slug ): bool {
		return isset( $this->tabs[ $slug ] );
	}

	/**
	 * Slug of the first tab, or an empty string when there are none.
	 *
	 * @return string
	 */
	public function default_slug(): string {
		foreach ( $this->tabs as $slug => $tab ) {
			return (string) $slug;
		}
		return '';
	}

	/**
	 * Resolve the tab to show for a requested slug.
	 *
	 * Unknown or empty slugs fall back to the first tab.
	 *
	 * @param string $requested Slug from the request.
	 * @return Tab|null Null only when no tabs are registered.
	 */
	public function resolve( string $requested ) {
		if ( '' !== $requested && isset( $this->tabs[ $requested ] ) ) {
			return $this->tabs[ $requested ];
		}
		$slug = $this->default_slug();
		return '' === $slug ? null : $this->tabs[ $slug ];
	}
}
