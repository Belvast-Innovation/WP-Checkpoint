<?php
/**
 * Reads the database settings by running wp-config.php as WordPress does, without WordPress.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Standalone;

defined( 'ABSPATH' ) || defined( 'WPCHECKPOINT_STANDALONE' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are fixed text from Failure::MESSAGES, never HTML.

/**
 * The site's wp-config.php is found by wp-load.php's rule and run the way WordPress
 * runs it, with ABSPATH pointing at a directory whose wp-settings.php is
 * empty (stub/): the values come out of WordPress's own code path
 * (getenv(), included files, conditions, the official Docker image's
 * helpers), so they are WordPress's values by construction. Only the
 * database settings and $table_prefix are read; path constants are not
 * trusted, since ABSPATH is the stub.
 *
 * Running wp-config.php runs the site's code. The caller must have
 * authenticated the request before calling load(): a request that has not
 * passed authentication must never run any of the site's code (the restore
 * endpoint answers 404 unless a restore is in progress and the token is
 * well formed; the standalone restorer checks its one-time key first).
 *
 * Two defences keep WordPress itself from starting: before running, the
 * file is scanned for a wp-settings.php loaded by anything but
 * "ABSPATH . 'wp-settings.php'"; after running, the result is checked
 * (add_action() and WPINC must not exist), which also catches a file it
 * includes loading WordPress by a fixed path. Output is discarded, PHP
 * errors are swallowed while it runs, a thrown error is a Failure, and a
 * wp-config.php that ends the request is reported through $on_exit.
 *
 * The values are not proof of the right database on their own: callers
 * check the database itself after connecting (Connection::table_exists(),
 * row_exists()). When wp-config.php cannot be read on its own (a host's
 * include that needs WordPress), callers take settings from the person
 * instead (Credentials::from_values()).
 */
final class ConfigLoader {

	/**
	 * Whether a load is running (read by the shutdown function).
	 *
	 * @var bool
	 */
	private static $loading = false;

	/**
	 * Called with a Failure when wp-config.php ends the request.
	 *
	 * @var callable|null
	 */
	private static $on_exit = null;

	/**
	 * Whether the shutdown function is registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * The directory shipped for ABSPATH (an empty wp-settings.php).
	 *
	 * @return string
	 */
	public static function stub_dir(): string {
		return __DIR__ . '/stub/';
	}

	/**
	 * The wp-config.php WordPress would load for a site: in ABSPATH, or one
	 * level above when that level is not another installation.
	 *
	 * @param string $abspath The site's WordPress directory.
	 * @return string
	 * @throws Failure When there is none.
	 */
	public static function locate( string $abspath ): string {
		$abspath = rtrim( $abspath, '/\\' ) . '/';
		if ( @file_exists( $abspath . 'wp-config.php' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir warns with the path.
			return $abspath . 'wp-config.php';
		}
		$above = dirname( $abspath );
		if ( @file_exists( $above . '/wp-config.php' ) && ! @file_exists( $above . '/wp-settings.php' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above; wp-load.php silences these too.
			return $above . '/wp-config.php';
		}
		throw new Failure( Failure::NO_CONFIG );
	}

	/**
	 * Run wp-config.php and read the database settings.
	 *
	 * @param string        $config  Path from locate().
	 * @param string        $stub    Directory holding an empty wp-settings.php, used as ABSPATH.
	 * @param callable|null $on_exit function( Failure $failure ): void, when wp-config.php ends the request.
	 * @return Credentials
	 * @throws Failure When it cannot be read on its own or does not give usable settings.
	 */
	public static function load( string $config, string $stub, $on_exit = null ): Credentials {
		$stub = rtrim( $stub, '/\\' ) . '/';
		if ( ! is_file( $stub . 'wp-settings.php' ) ) {
			throw new Failure( Failure::STUB_MISSING );
		}
		if ( self::wordpress_loaded() || ( defined( 'ABSPATH' ) && ABSPATH !== $stub ) ) {
			throw new Failure( Failure::LOADS_WORDPRESS ); // Running it now would start WordPress (or already has).
		}
		$code = @file_get_contents( $config ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a small local file; a warning would print the path.
		if ( ! is_string( $code ) ) {
			throw new Failure( Failure::NO_CONFIG );
		}
		if ( self::loads_settings_by_path( $code ) ) {
			throw new Failure( Failure::LOADS_WORDPRESS );
		}
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', $stub ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress's own constant, pointed at the stub so wp-config.php does not start WordPress.
		}
		self::$on_exit = is_callable( $on_exit ) ? $on_exit : null;
		if ( ! self::$registered ) {
			register_shutdown_function( array( self::class, 'shutdown' ) );
			self::$registered = true;
		}
		self::$loading = true;
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- warnings from the site's file would print paths.
			static function (): bool {
				return true;
			}
		);
		ob_start();
		try {
			$vars = self::run( $config );
		} catch ( \Throwable $e ) {
			throw new Failure( Failure::CONFIG_ERROR );
		} finally {
			ob_end_clean();
			restore_error_handler();
			self::$loading = false;
		}
		if ( self::wordpress_loaded() ) {
			throw new Failure( Failure::LOADS_WORDPRESS );
		}
		$values = array( 'prefix' => '' );
		if ( isset( $vars['table_prefix'] ) && is_string( $vars['table_prefix'] ) ) {
			$values['prefix'] = $vars['table_prefix'];
		} elseif ( isset( $GLOBALS['table_prefix'] ) && is_string( $GLOBALS['table_prefix'] ) ) {
			$values['prefix'] = $GLOBALS['table_prefix'];
		}
		foreach ( array(
			'name'     => 'DB_NAME',
			'user'     => 'DB_USER',
			'password' => 'DB_PASSWORD',
			'host'     => 'DB_HOST',
			'charset'  => 'DB_CHARSET',
			'collate'  => 'DB_COLLATE',
			'flags'    => 'MYSQL_CLIENT_FLAGS',
		) as $key => $constant ) {
			if ( defined( $constant ) ) {
				$values[ $key ] = constant( $constant );
			}
		}
		return Credentials::from_values( $values );
	}

	/**
	 * Reports a wp-config.php that ended the request while it ran.
	 *
	 * @return void
	 */
	public static function shutdown(): void {
		if ( ! self::$loading ) {
			return;
		}
		self::$loading = false;
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( null !== self::$on_exit ) {
			call_user_func( self::$on_exit, new Failure( Failure::CONFIG_STOPPED ) );
		}
	}

	/**
	 * Whether WordPress is loaded in this request (it may become so while wp-config.php runs).
	 *
	 * @phpstan-impure
	 * @return bool
	 */
	private static function wordpress_loaded(): bool {
		return function_exists( 'add_action' ) || defined( 'WPINC' );
	}

	/**
	 * Whether the file loads wp-settings.php by anything but ABSPATH . 'wp-settings.php'.
	 * Without the tokenizer the check after running still applies.
	 *
	 * @param string $code wp-config.php.
	 * @return bool
	 */
	private static function loads_settings_by_path( string $code ): bool {
		if ( ! function_exists( 'token_get_all' ) ) {
			return false;
		}
		$tokens = token_get_all( $code );
		$count  = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || ! in_array( $tokens[ $i ][0], array( T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE ), true ) ) {
				continue;
			}
			$expression = '';
			for ( $j = $i + 1; $j < $count && ';' !== $tokens[ $j ]; $j++ ) {
				if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				$expression .= is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];
			}
			if ( false === stripos( $expression, 'wp-settings.php' ) ) {
				continue;
			}
			$plain = trim( str_replace( array( '(', ')', '"' ), array( '', '', '\'' ), $expression ) );
			if ( 'ABSPATH.\'wp-settings.php\'' !== $plain ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Run the file in a scope of its own and return its variables.
	 *
	 * @param string $wpcheckpoint_config Path.
	 * @return array<string, mixed>
	 */
	private static function run( string $wpcheckpoint_config ): array {
		include $wpcheckpoint_config;
		unset( $wpcheckpoint_config );
		return get_defined_vars();
	}
}
