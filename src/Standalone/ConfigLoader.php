<?php
/**
 * Reads the database settings by running wp-config.php as WordPress does, without WordPress.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Standalone;

use WPCheckpoint\Support\HostFunctions;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are fixed text from Failure::MESSAGES, never HTML.

/**
 * The site's wp-config.php is found by wp-load.php's rule and run the way
 * WordPress runs it, with ABSPATH pointing at a directory whose
 * wp-settings.php is empty (stub/): the values come out of WordPress's own
 * code path (getenv(), included files, conditions, the official Docker
 * image's helpers), so they are WordPress's values by construction. Only
 * the database settings and $table_prefix are read; path constants are not
 * trusted, since ABSPATH is the stub. An entry that runs without WordPress
 * defines ABSPATH as the stub itself before loading any class (which is
 * also what lets these files refuse direct access the usual way).
 *
 * Running wp-config.php runs the site's code. The caller must have
 * authenticated the request before calling load(): a request that has not
 * passed authentication must never run any of the site's code (the restore
 * endpoint answers 404 unless a restore is in progress and the token is
 * well formed; the standalone restorer checks its one-time key first).
 *
 * WordPress itself cannot start: ABSPATH is the stub, so the real
 * wp-settings.php, if something loads it by a fixed path, fails at its first
 * require (an Error, or on PHP 7.4 a fatal error that ends the request).
 * Two checks make that a clear refusal: before running, the file is scanned
 * for a wp-settings.php loaded by anything but ABSPATH . 'wp-settings.php';
 * after running, add_action() and WPINC must not exist.
 *
 * What wp-config.php does to the request is undone afterwards, so the
 * caller's answer is its own: output buffers are trimmed back to the level
 * they had, headers and the status code (a redirect to HTTPS, a cookie, a
 * session) are put back as they were, error and exception handlers it
 * installed are removed, and the error display settings are restored. When
 * it ends the request, the same is done before $on_exit is called.
 *
 * The values are not proof of the right database on their own: callers
 * check the database itself after connecting (Connection::table_exists(),
 * row_exists()). When wp-config.php cannot be read on its own (a host's
 * include that needs WordPress), callers take settings from the person
 * instead (Credentials::from_values()).
 */
final class ConfigLoader {

	/**
	 * The request as it was before wp-config.php ran, while a load runs.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $before = null;

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
	public static function locate(
		#[\SensitiveParameter]
		string $abspath
	): string {
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
	public static function load(
		#[\SensitiveParameter]
		string $config,
		string $stub,
		$on_exit = null
	): Credentials {
		$stub = rtrim( $stub, '/\\' ) . '/';
		if ( ! is_file( $stub . 'wp-settings.php' ) ) {
			throw new Failure( Failure::STUB_MISSING );
		}
		if ( self::wordpress_loaded() || ( defined( 'ABSPATH' ) && ! self::same_directory( ABSPATH, $stub ) ) ) {
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
		$before       = self::snapshot();
		self::$before = $before;
		try {
			$vars = self::run( $config );
		} catch ( \Throwable $e ) {
			throw new Failure( Failure::CONFIG_ERROR );
		} finally {
			self::$before = null;
			self::restore( $before );
		}
		if ( self::wordpress_loaded() ) {
			throw new Failure( Failure::LOADS_WORDPRESS );
		}
		$local  = isset( $vars['table_prefix'] ) && is_string( $vars['table_prefix'] ) ? $vars['table_prefix'] : null;
		$global = isset( $GLOBALS['table_prefix'] ) && is_string( $GLOBALS['table_prefix'] ) ? $GLOBALS['table_prefix'] : null;
		if ( null !== $local && null !== $global && $local !== $global ) {
			// WordPress runs the file in the global scope, where both are one variable and the last assignment wins;
			// in this function's scope they are two, and which came last is not known: no guess.
			throw new Failure( Failure::BAD_PREFIX );
		}
		$values = array( 'prefix' => null !== $local ? $local : (string) $global );
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
	 * Reports a wp-config.php that ended the request while it ran, after
	 * putting the request back as it was.
	 *
	 * @return void
	 */
	public static function shutdown(): void {
		if ( null === self::$before ) {
			return;
		}
		$before       = self::$before;
		self::$before = null;
		self::restore( $before );
		if ( null !== self::$on_exit ) {
			call_user_func( self::$on_exit, new Failure( Failure::CONFIG_STOPPED ) );
		}
	}

	/**
	 * The parts of the request wp-config.php may change, and an error handler of ours.
	 *
	 * @return array<string, mixed>
	 */
	private static function snapshot(): array {
		$swallow   = static function (): bool {
			return true;
		};
		$exception = set_exception_handler( null );
		set_exception_handler( $exception );
		$before             = array(
			'level'     => ob_get_level(),
			'headers'   => headers_list(),
			'sent'      => headers_sent(),
			'status'    => http_response_code(),
			'ini'       => array(
				'display_errors' => ini_get( 'display_errors' ),
				'log_errors'     => ini_get( 'log_errors' ),
				'html_errors'    => ini_get( 'html_errors' ),
			),
			'reporting' => error_reporting(), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- reading the level to put it back.
			'session'   => function_exists( 'session_status' ) ? session_status() : 0,
			'handler'   => $swallow,
			'exception' => $exception,
		);
		$before['previous'] = set_error_handler( $swallow ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- warnings from the site's file would print paths.
		ob_start();
		return $before;
	}

	/**
	 * Put the request back as the snapshot has it.
	 *
	 * @param array<string, mixed> $before snapshot().
	 * @return void
	 */
	private static function restore( array $before ): void {
		// Error handlers: remove every one installed since ours, then ours; stop at the caller's own (wp-config.php
		// may have removed ours already, and nothing below it is ours to touch).
		for ( $i = 0; $i < 64; $i++ ) {
			$current = set_error_handler( null ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- reading the current handler.
			restore_error_handler();
			if ( $current === $before['previous'] || null === $current ) {
				break;
			}
			restore_error_handler();
			if ( $current === $before['handler'] ) {
				break;
			}
		}
		set_exception_handler( $before['exception'] );
		foreach ( $before['ini'] as $name => $value ) {
			if ( false !== $value ) {
				HostFunctions::ini_set( $name, (string) $value ); // Putting back what wp-config.php changed (a no-op where hosts disable ini_set()).
			}
		}
		error_reporting( (int) $before['reporting'] ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- putting back the caller's level, not changing it.
		while ( ob_get_level() > $before['level'] ) {
			ob_end_clean();
		}
		while ( ob_get_level() < $before['level'] ) {
			ob_start(); // Buffers it closed: the level is back, their content is not.
		}
		if ( function_exists( 'session_status' ) && PHP_SESSION_ACTIVE !== $before['session'] && PHP_SESSION_ACTIVE === session_status() ) {
			session_abort();
		}
		if ( ! headers_sent() ) {
			header_remove();
			foreach ( $before['headers'] as $header ) {
				header( $header, false );
			}
			if ( false !== $before['status'] ) {
				http_response_code( (int) $before['status'] );
			}
		}
	}

	/**
	 * Whether two paths name one directory (by their real paths: on Windows
	 * "D:\\a\\stub" and "D:/a/stub" are the same, and a symbolic link is
	 * its target).
	 *
	 * @param string $a Path.
	 * @param string $b Path.
	 * @return bool
	 */
	private static function same_directory( string $a, string $b ): bool {
		$real_a = realpath( $a );
		$real_b = realpath( $b );
		return false !== $real_a && $real_a === $real_b;
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
	 * Whether the file loads wp-settings.php by anything but ABSPATH and the
	 * file name. Without the tokenizer the check after running still applies.
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
				if ( is_array( $tokens[ $j ] ) && T_CLOSE_TAG === $tokens[ $j ][0] ) {
					break; // A closing tag ends the statement too.
				}
				if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				$expression .= is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];
			}
			if ( false === stripos( $expression, 'wp-settings.php' ) ) {
				continue;
			}
			$plain = trim( str_replace( array( '(', ')', '"' ), array( '', '', '\'' ), $expression ) );
			if ( ! in_array( $plain, array( 'ABSPATH.\'wp-settings.php\'', 'ABSPATH.\'/wp-settings.php\'' ), true ) ) {
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
	private static function run(
		#[\SensitiveParameter]
		string $wpcheckpoint_config
	): array {
		include $wpcheckpoint_config;
		unset( $wpcheckpoint_config );
		return get_defined_vars();
	}
}
