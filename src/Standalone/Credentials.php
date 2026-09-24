<?php
/**
 * Database settings, held so that dumping them shows nothing.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Standalone;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are fixed text from Failure::MESSAGES, never HTML.

/**
 * The database settings and table prefix, from wp-config.php
 * (ConfigLoader), from WordPress when it is loaded, or typed in by the
 * person running a restore when wp-config.php cannot be read on its own.
 * The values live in a private static store keyed by the object, not on the
 * object: var_dump(), print_r(), var_export(), json_encode() and an (array)
 * cast show none of them, and the object cannot be serialized. The array
 * given to from_values() is marked #[\SensitiveParameter] (PHP 8.2+), and
 * entries that run without WordPress switch off exception arguments
 * (zend.exception_ignore_args), so a trace does not carry the password.
 */
final class Credentials {

	/**
	 * Values by object id.
	 *
	 * @var array<int, array{name: string, user: string, password: string, host: string, charset: string, collate: string, flags: int, prefix: string}>
	 */
	private static $store = array();

	/**
	 * Constructor.
	 *
	 * @param array{name: string, user: string, password: string, host: string, charset: string, collate: string, flags: int, prefix: string} $values Values.
	 */
	private function __construct( array $values ) {
		self::$store[ spl_object_id( $this ) ] = $values;
	}

	/**
	 * Settings given as values (typed in, or read by ConfigLoader).
	 *
	 * @param array<string, mixed> $values name, user, password, host (required); charset, collate, flags, prefix.
	 * @return self
	 * @throws Failure When a required value is missing or the prefix is not one WordPress allows.
	 */
	public static function from_values(
		#[\SensitiveParameter]
		array $values
	): self {
		foreach ( array(
			'name'     => 'DB_NAME',
			'user'     => 'DB_USER',
			'password' => 'DB_PASSWORD',
			'host'     => 'DB_HOST',
		) as $key => $constant ) {
			if ( ! isset( $values[ $key ] ) || ! is_string( $values[ $key ] ) || ( 'password' !== $key && '' === $values[ $key ] ) ) {
				throw new Failure( Failure::MISSING_CONSTANT, $constant );
			}
		}
		$prefix = isset( $values['prefix'] ) && is_string( $values['prefix'] ) ? $values['prefix'] : '';
		if ( ! self::valid_prefix( $prefix ) ) {
			throw new Failure( Failure::BAD_PREFIX );
		}
		return new self(
			array(
				'name'     => $values['name'],
				'user'     => $values['user'],
				'password' => $values['password'],
				'host'     => $values['host'],
				'charset'  => isset( $values['charset'] ) && is_string( $values['charset'] ) ? $values['charset'] : '',
				'collate'  => isset( $values['collate'] ) && is_string( $values['collate'] ) ? $values['collate'] : '',
				'flags'    => isset( $values['flags'] ) && is_int( $values['flags'] ) ? $values['flags'] : 0,
				'prefix'   => $prefix,
			)
		);
	}

	/**
	 * Settings of the WordPress already loaded in this request.
	 *
	 * @return self
	 * @throws Failure When WordPress is not loaded or a value is missing.
	 */
	public static function from_wordpress(): self {
		global $table_prefix;
		$values = array( 'prefix' => is_string( $table_prefix ) ? $table_prefix : '' );
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
		return self::from_values( $values );
	}

	/**
	 * Whether a table prefix is one WordPress accepts: letters, digits and underscores, not empty.
	 *
	 * @param string $prefix Prefix.
	 * @return bool
	 */
	public static function valid_prefix( string $prefix ): bool {
		return '' !== $prefix && strspn( $prefix, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_' ) === strlen( $prefix );
	}

	/**
	 * A value.
	 *
	 * @param string $key name, user, password, host, charset, collate or prefix.
	 * @return string
	 */
	public function get( string $key ): string {
		$values = self::$store[ spl_object_id( $this ) ] ?? array();
		return isset( $values[ $key ] ) && is_string( $values[ $key ] ) ? $values[ $key ] : '';
	}

	/**
	 * MYSQL_CLIENT_FLAGS.
	 *
	 * @return int
	 */
	public function flags(): int {
		return self::$store[ spl_object_id( $this ) ]['flags'] ?? 0;
	}

	/**
	 * Nothing to show.
	 *
	 * @return array<string, string>
	 */
	public function __debugInfo(): array {
		return array( 'credentials' => '[hidden]' );
	}

	/**
	 * Never copied: a copy would share nothing and hold nothing.
	 *
	 * @return void
	 * @throws \LogicException Always.
	 */
	public function __clone() {
		throw new \LogicException( 'Database settings are not copied.' );
	}

	// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- it only throws.
	/**
	 * Never serialized.
	 *
	 * @return array<string, mixed>
	 * @throws \LogicException Always.
	 */
	public function __serialize(): array {
		throw new \LogicException( 'Database settings are not serialized.' );
	}
	// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn

	/**
	 * Never unserialized.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return void
	 * @throws \LogicException Always.
	 */
	public function __unserialize( array $data ): void {
		unset( $data );
		throw new \LogicException( 'Database settings are not unserialized.' );
	}

	/**
	 * Forget the values with the object.
	 */
	public function __destruct() {
		$id = spl_object_id( $this );
		unset( self::$store[ $id ] );
	}
}
