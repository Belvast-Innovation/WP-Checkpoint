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
 *
 * Also here: the must-use plugins directory (where the swap puts its loader, Restore\Loader), what a run without
 * WordPress does when it stops before the flush (await_flush(): its caller records it for the maintenance file's
 * code), and what is said to the person at the terminal only (say(): WP-CLI's warning; it may name the system's users,
 * which the job's log and status never do).
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
	 * Returns the must-use plugins directory: function(): string.
	 *
	 * @var callable
	 */
	private $mu_dir;

	/**
	 * Told that the site is put back and only the flush is left: function(): void; null when no one is to be told.
	 *
	 * @var callable|null
	 */
	private $await;

	/**
	 * Constructor.
	 *
	 * @param bool          $web         Whether the run is made without WordPress.
	 * @param callable      $base_prefix function(): string.
	 * @param callable      $multisite   function(): bool.
	 * @param callable      $network_id  function(): int.
	 * @param callable      $plugin      function(): string.
	 * @param callable|null $flush       function(): mixed, or null.
	 * @param callable      $mu_dir      function(): string.
	 * @param callable|null $await       function(): void, or null.
	 */
	private function __construct( bool $web, callable $base_prefix, callable $multisite, callable $network_id, callable $plugin, $flush, callable $mu_dir, $await = null ) {
		$this->web         = $web;
		$this->base_prefix = $base_prefix;
		$this->multisite   = $multisite;
		$this->network_id  = $network_id;
		$this->plugin      = $plugin;
		$this->flush       = is_callable( $flush ) ? $flush : null;
		$this->mu_dir      = $mu_dir;
		$this->await       = is_callable( $await ) ? $await : null;
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
			},
			static function (): string {
				return \WPCheckpoint\Restore\Loader::dir( '' ); // WordPress has defined its content directory.
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
	 * @param string        $mu_dir      The must-use plugins directory ('' when it is not known: no loader is looked for).
	 * @param callable|null $await       Told that the site is put back and only the flush is left, or null.
	 * @return self
	 */
	public static function without_wordpress( string $base_prefix, bool $multisite, int $network_id, string $plugin, $flush = null, string $mu_dir = '', $await = null ): self {
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
			$flush,
			static function () use ( $mu_dir ): string {
				return $mu_dir;
			},
			$await
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
	 * The must-use plugins directory ('' when it is not known).
	 *
	 * @return string
	 */
	public function mu_dir(): string {
		return (string) call_user_func( $this->mu_dir );
	}

	/**
	 * The site is put back and only the flush of the object cache is left, which cannot be done here: whoever made the
	 * run is told (a run without WordPress records it in the visitor file). Nothing it throws goes further, but a
	 * NotHere.
	 *
	 * @return void
	 * @throws NotHere Rethrown unchanged.
	 */
	public function await_flush(): void {
		if ( null === $this->await ) {
			return;
		}
		try {
			call_user_func( $this->await );
		} catch ( NotHere $e ) {
			throw $e; // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- rethrown unchanged: the Runner's.
		} catch ( \Throwable $e ) {
			unset( $e ); // Not recorded: WP-CLI flushes and ends it.
		}
	}

	/**
	 * Say something to the person at the terminal, and nowhere else: under WP-CLI its warning; nothing otherwise (a
	 * run without WordPress, a request).
	 *
	 * @param string $text Text (it may name the system's users: it goes to no log).
	 * @return void
	 */
	public function say( string $text ): void {
		if ( ! $this->web && defined( 'WP_CLI' ) && constant( 'WP_CLI' ) && class_exists( '\WP_CLI', false ) ) {
			\WP_CLI::warning( $text );
		}
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
