<?php
/**
 * What the swap takes from the site it runs on.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Everything SwapStep takes from WordPress, in one place: the table prefix, whether the site is a network and its
 * network's id, this plugin's basename, the object cache's flush, and the swap's own texts. In WordPress (of_wordpress())
 * each is read when it is used, as before; a run made without WordPress (without_wordpress()) is given them, its texts
 * are English, and it cannot flush the object cache unless it is given a way to (the swap then stops before the flush
 * and leaves the rest to WP-CLI). Whether the run is made without WordPress (web()) also decides the swap's guards:
 * there, every way into a part of the swap that is not putting the site back leaves the job to WP-CLI.
 */
final class SwapHost {

	/**
	 * Whether the run is made without WordPress.
	 *
	 * @var bool
	 */
	private $web;

	/**
	 * Returns the table prefix of the network (or the site): function(): string.
	 *
	 * @var callable
	 */
	private $base_prefix;

	/**
	 * Returns whether the site is a network: function(): bool.
	 *
	 * @var callable
	 */
	private $multisite;

	/**
	 * Returns the network's id: function(): int.
	 *
	 * @var callable
	 */
	private $network_id;

	/**
	 * Returns this plugin's basename: function(): string.
	 *
	 * @var callable
	 */
	private $plugin;

	/**
	 * Flushes the object cache: function(): mixed (false when it says it could not); null when it cannot be done here.
	 *
	 * @var callable|null
	 */
	private $flush;

	/**
	 * Constructor.
	 *
	 * @param bool          $web         Whether the run is made without WordPress.
	 * @param callable      $base_prefix function(): string.
	 * @param callable      $multisite   function(): bool.
	 * @param callable      $network_id  function(): int.
	 * @param callable      $plugin      function(): string.
	 * @param callable|null $flush       function(): mixed, or null.
	 */
	private function __construct( bool $web, callable $base_prefix, callable $multisite, callable $network_id, callable $plugin, $flush ) {
		$this->web         = $web;
		$this->base_prefix = $base_prefix;
		$this->multisite   = $multisite;
		$this->network_id  = $network_id;
		$this->plugin      = $plugin;
		$this->flush       = is_callable( $flush ) ? $flush : null;
	}

	/**
	 * In WordPress: each read when it is used.
	 *
	 * @return self
	 */
	public static function of_wordpress(): self {
		return new self(
			false,
			static function (): string {
				global $wpdb;
				return (string) $wpdb->base_prefix;
			},
			static function (): bool {
				return is_multisite();
			},
			static function (): int {
				return is_multisite() ? (int) get_current_network_id() : 0;
			},
			static function (): string {
				return plugin_basename( WPCHECKPOINT_FILE );
			},
			static function () {
				return wp_cache_flush();
			}
		);
	}

	/**
	 * Without WordPress: given.
	 *
	 * @param string        $base_prefix The table prefix of the network (or the site): wp-config.php's $table_prefix.
	 * @param bool          $multisite   Whether the site is a network.
	 * @param int           $network_id  The network's id, as the swap recorded it (0 on a single site).
	 * @param string        $plugin      This plugin's basename.
	 * @param callable|null $flush       A way to flush the object cache, or null when there is none.
	 * @return self
	 */
	public static function without_wordpress( string $base_prefix, bool $multisite, int $network_id, string $plugin, $flush = null ): self {
		return new self(
			true,
			static function () use ( $base_prefix ): string {
				return $base_prefix;
			},
			static function () use ( $multisite ): bool {
				return $multisite;
			},
			static function () use ( $network_id ): int {
				return $network_id;
			},
			static function () use ( $plugin ): string {
				return $plugin;
			},
			$flush
		);
	}

	/**
	 * Whether the run is made without WordPress.
	 *
	 * @return bool
	 */
	public function web(): bool {
		return $this->web;
	}

	/**
	 * The table prefix of the network (or the site).
	 *
	 * @return string
	 */
	public function base_prefix(): string {
		return (string) call_user_func( $this->base_prefix );
	}

	/**
	 * Whether the site is a network.
	 *
	 * @return bool
	 */
	public function multisite(): bool {
		return (bool) call_user_func( $this->multisite );
	}

	/**
	 * The network's id (0 on a single site).
	 *
	 * @return int
	 */
	public function network_id(): int {
		return (int) call_user_func( $this->network_id );
	}

	/**
	 * This plugin's basename.
	 *
	 * @return string
	 */
	public function plugin(): string {
		return (string) call_user_func( $this->plugin );
	}

	/**
	 * Whether the object cache can be flushed here.
	 *
	 * @return bool
	 */
	public function can_flush(): bool {
		return null !== $this->flush;
	}

	/**
	 * Flush the object cache (can_flush() first).
	 *
	 * @return mixed What the flush returned (false when it says it could not).
	 * @throws \LogicException When it cannot be done here.
	 */
	public function flush() {
		if ( null === $this->flush ) {
			throw new \LogicException( 'The object cache cannot be flushed here.' );
		}
		return call_user_func( $this->flush );
	}

	/**
	 * "Putting the site back as it was".
	 *
	 * @return string
	 */
	public function putting_back(): string {
		return $this->web ? 'Putting the site back as it was' : __( 'Putting the site back as it was', 'wp-checkpoint' );
	}

	/**
	 * "The site is as it was before the restore".
	 *
	 * @return string
	 */
	public function as_before(): string {
		return $this->web ? 'The site is as it was before the restore' : __( 'The site is as it was before the restore', 'wp-checkpoint' );
	}
}
