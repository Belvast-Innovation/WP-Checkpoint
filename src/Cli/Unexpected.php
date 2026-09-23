<?php
/**
 * Last line of defence for the WP-CLI commands.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a command's body and turns any exception it did not handle into one
 * cleaned line. PHP's default handler would print the message, the file and
 * a stack trace: server paths with the hosting account name, which users
 * paste into support requests. Catching each expected type at its call site
 * cannot cover the next unexpected one, so every command method runs
 * through guard().
 */
final class Unexpected {

	/**
	 * Run a command body.
	 *
	 * @param callable      $body  The command: function(): void.
	 * @param callable      $clean Text cleaner (JobPresenter::clean()).
	 * @param callable|null $fail  Reports the line and ends the command (default WP_CLI::error(), exit code 1).
	 * @return void
	 * @throws \Throwable WP-CLI's own exit exception, which is how its tests capture an exit.
	 */
	public static function guard( callable $body, callable $clean, $fail = null ): void {
		try {
			call_user_func( $body );
		} catch ( \Throwable $e ) {
			if ( self::is_cli_exit( $e ) ) {
				throw $e;
			}
			call_user_func( is_callable( $fail ) ? $fail : array( 'WP_CLI', 'error' ), self::message( $e, $clean ) );
		}
	}

	/**
	 * Whether an exception is WP-CLI's exit (not loaded outside WP-CLI).
	 *
	 * @param \Throwable $e Exception.
	 * @return bool
	 */
	private static function is_cli_exit( \Throwable $e ): bool {
		return is_a( $e, 'WP_CLI\\ExitException' );
	}

	/**
	 * The line for an unexpected exception: its class without the namespace
	 * and its message, cleaned. Never the file, the line or the trace.
	 *
	 * @param \Throwable $e     Exception.
	 * @param callable   $clean Text cleaner.
	 * @return string
	 */
	public static function message( \Throwable $e, callable $clean ): string {
		$class = get_class( $e );
		$short = false === strrpos( $class, '\\' ) ? $class : substr( $class, strrpos( $class, '\\' ) + 1 );
		return sprintf( 'Unexpected error (%s): %s', $short, (string) call_user_func( $clean, $e->getMessage() ) );
	}
}
