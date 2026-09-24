<?php
/**
 * Why reading the configuration or reaching the database failed, in words that are safe to show.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Standalone;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are fixed text from Failure::MESSAGES, never HTML.

/**
 * The message is chosen from a fixed list by the code, never built from
 * what went wrong: no constant value, user name, password, host, port,
 * database name, table prefix, path or text from the database server (which
 * names user@host) can reach it. English only: this runs without WordPress,
 * whose translations cannot be loaded without loading WordPress itself.
 */
final class Failure extends \RuntimeException {

	const NO_CONFIG        = 'no_config';
	const CONFIG_ERROR     = 'config_error';
	const CONFIG_STOPPED   = 'config_stopped';
	const LOADS_WORDPRESS  = 'loads_wordpress';
	const STUB_MISSING     = 'stub_missing';
	const MISSING_CONSTANT = 'missing_constant';
	const BAD_PREFIX       = 'bad_prefix';
	const BAD_HOST         = 'bad_host';
	const NO_MYSQLI        = 'no_mysqli';
	const UNREACHABLE      = 'unreachable';
	const ACCESS_DENIED    = 'access_denied';
	const UNKNOWN_DATABASE = 'unknown_database';
	const CHARSET          = 'charset';
	const QUERY            = 'query';
	const WRONG_DATABASE   = 'wrong_database';
	const OTHER            = 'other';

	/**
	 * Messages by code.
	 */
	const MESSAGES = array(
		self::NO_CONFIG        => 'wp-config.php was not found where WordPress looks for it.',
		self::CONFIG_ERROR     => 'wp-config.php could not be loaded on its own: it has a PHP error or needs WordPress to be loaded first.',
		self::CONFIG_STOPPED   => 'wp-config.php stopped the request while it was loaded.',
		self::LOADS_WORDPRESS  => 'wp-config.php loads WordPress by a fixed path, so it cannot be read without starting WordPress.',
		self::STUB_MISSING     => 'The files needed to read wp-config.php on their own are missing.',
		self::MISSING_CONSTANT => 'wp-config.php does not define %s.',
		self::BAD_PREFIX       => 'The table prefix in wp-config.php is missing or holds characters WordPress does not allow.',
		self::BAD_HOST         => 'The database host in wp-config.php cannot be read.',
		self::NO_MYSQLI        => 'PHP\'s mysqli extension is not available on this server.',
		self::UNREACHABLE      => 'The database server could not be reached.',
		self::ACCESS_DENIED    => 'The database rejected the user name or password.',
		self::UNKNOWN_DATABASE => 'The database does not exist or this user cannot use it.',
		self::CHARSET          => 'The database connection does not support the character set in wp-config.php.',
		self::QUERY            => 'The database did not answer a query.',
		self::WRONG_DATABASE   => 'wp-config.php points to a different database than the one this restore started in.',
		self::OTHER            => 'The database connection failed (MySQL error %d).',
	);

	/**
	 * Constants a MISSING_CONSTANT message may name.
	 */
	const NAMEABLE = array( 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST' );

	/**
	 * Code.
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * Constructor.
	 *
	 * @param string     $reason One of the constants.
	 * @param string|int $detail For MISSING_CONSTANT a name in NAMEABLE, for OTHER the MySQL error number; ignored otherwise.
	 */
	public function __construct( string $reason, $detail = '' ) {
		$reason       = isset( self::MESSAGES[ $reason ] ) ? $reason : self::OTHER;
		$this->reason = $reason;
		$message      = self::MESSAGES[ $reason ];
		if ( self::MISSING_CONSTANT === $reason ) {
			$message = sprintf( $message, in_array( $detail, self::NAMEABLE, true ) ? $detail : 'a database setting' );
		} elseif ( self::OTHER === $reason ) {
			$message = sprintf( $message, is_int( $detail ) ? $detail : 0 );
		}
		parent::__construct( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a fixed message.
	}

	/**
	 * Code.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}
}
