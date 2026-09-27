<?php
/**
 * Whether a plugin file is this plugin, by what it says rather than where it is.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * Pure PHP. The restore stages the running copy of this plugin, never a
 * backup's (an older version would come back and could not finish the
 * swap), whatever directory a backup keeps it in. A backup's plugin
 * directory is this plugin when one of its top-level PHP files says so in
 * its first HEAD_BYTES: a plugin header with this plugin's name and its
 * text domain, or the definition of its version constant.
 */
final class PluginIdentity {

	/**
	 * Bytes of a file read for its header (WordPress reads 8 KiB, get_file_data()).
	 */
	const HEAD_BYTES = 8192;

	const TEXT_DOMAIN = 'wp-checkpoint';

	/**
	 * The name in the running plugin's header.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Constructor.
	 *
	 * @param string $name The running plugin's Plugin Name.
	 */
	public function __construct( string $name ) {
		$this->name = $name;
	}

	/**
	 * From the running plugin's main file.
	 *
	 * @param string $main_file Main file.
	 * @return PluginIdentity
	 */
	public static function running( string $main_file ): PluginIdentity {
		$handle = @fopen( $main_file, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- the plugin's own file.
		$head   = false === $handle ? '' : (string) fread( $handle, self::HEAD_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see above.
		if ( false !== $handle ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
		return new self( (string) self::header( $head, 'Plugin Name' ) );
	}

	/**
	 * How a file's head shows it is this plugin: "plugin header", "constant", or '' when it does not.
	 *
	 * @param string $head The file's first bytes.
	 * @return string
	 */
	public function basis( string $head ): string {
		$head = substr( $head, 0, self::HEAD_BYTES );
		$name = self::header( $head, 'Plugin Name' );
		if ( '' !== $this->name && $name === $this->name && self::TEXT_DOMAIN === self::header( $head, 'Text Domain' ) ) {
			return 'plugin header';
		}
		if ( 1 === preg_match( '/\bdefine\s*\(\s*[\'"]WPCHECKPOINT_VERSION[\'"]\s*,/', $head ) ) {
			return 'constant';
		}
		return '';
	}

	/**
	 * A header field's value, as WordPress reads it (get_file_data()); null when absent.
	 *
	 * @param string $head  File head.
	 * @param string $field Field name.
	 * @return string|null
	 */
	private static function header( string $head, string $field ) {
		$head = str_replace( "\r", "\n", $head );
		if ( 1 !== preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $field, '/' ) . ':(.*)$/mi', $head, $m ) ) {
			return null;
		}
		return trim( (string) preg_replace( '/\s*(?:\*\/|\?>).*/', '', $m[1] ) );
	}
}
