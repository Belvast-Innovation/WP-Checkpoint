<?php
/**
 * Keeps the storage directory out of reach over HTTP.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Writes index.php and .htaccess into a directory and verifies with a
 * loopback request whether the web server honours them.
 */
final class Protection {

	const STATUS_PROTECTED      = 'protected';
	const STATUS_EXPOSED        = 'exposed';
	const STATUS_UNVERIFIED     = 'unverified';
	const STATUS_NOT_APPLICABLE = 'not_applicable';

	/**
	 * Contents of index.php.
	 */
	const INDEX_PHP = "<?php\n// Silence is golden.\n";

	/**
	 * .htaccess denying everything, for Apache 2.4 and 2.2.
	 *
	 * @return string
	 */
	public static function htaccess(): string {
		return "# WP Checkpoint: this directory holds backups and logs. Deny all direct access.\n"
			. "<IfModule mod_authz_core.c>\n"
			. "\tRequire all denied\n"
			. "</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n"
			. "\tOrder deny,allow\n"
			. "\tDeny from all\n"
			. "</IfModule>\n";
	}

	/**
	 * Write the protection files into a directory (idempotent).
	 *
	 * @param string $dir Directory.
	 * @return bool False when a file could not be written.
	 */
	public static function write( string $dir ): bool {
		$ok    = true;
		$files = array(
			'index.php' => self::INDEX_PHP,
			'.htaccess' => self::htaccess(),
		);
		foreach ( $files as $name => $contents ) {
			$path = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $name;
			if ( is_file( $path ) && file_get_contents( $path ) === $contents ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- tiny local file.
				continue;
			}
			if ( false === file_put_contents( $path, $contents, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- plugin-owned directory.
				$ok = false;
			}
		}
		return $ok;
	}

	/**
	 * Nginx configuration that denies the directory.
	 *
	 * @param string $dir_basename Directory name under wp-content.
	 * @return string
	 */
	public static function nginx_snippet( string $dir_basename ): string {
		return 'location ~* /wp-content/' . preg_quote( $dir_basename, '/' ) . "/ {\n\tdeny all;\n\treturn 403;\n}";
	}

	/**
	 * Web server family from SERVER_SOFTWARE.
	 *
	 * @return string apache|nginx|litespeed|iis|unknown
	 */
	public static function server(): string {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
		$families = array(
			'nginx'         => 'nginx',
			'litespeed'     => 'litespeed',
			'apache'        => 'apache',
			'microsoft-iis' => 'iis',
		);
		foreach ( $families as $needle => $name ) {
			if ( false !== strpos( $software, $needle ) ) {
				return $name;
			}
		}
		return 'unknown';
	}

	/**
	 * Public URL of a path when it lies under the WordPress web tree.
	 *
	 * @param string $path Absolute path.
	 * @return string Empty when the path is not reachable over HTTP.
	 */
	public static function url_for( string $path ): string {
		$real = realpath( $path );
		if ( false === $real ) {
			return '';
		}
		$roots = array(
			array( WP_CONTENT_DIR, content_url() ),
			array( ABSPATH, site_url() ),
		);
		foreach ( $roots as list( $dir, $url ) ) {
			$real_dir = realpath( $dir );
			if ( false === $real_dir || ! Paths::is_same_or_inside( $real_dir, $real ) ) {
				continue;
			}
			$relative = ltrim( substr( Paths::normalize( $real ), strlen( rtrim( Paths::normalize( $real_dir ), '/' ) ) ), '/' );
			return rtrim( $url, '/' ) . '/' . str_replace( '%2F', '/', rawurlencode( $relative ) );
		}
		return '';
	}

	/**
	 * Verify with a loopback request that a probe file in $dir is not served.
	 *
	 * @param string $dir Directory under the web tree.
	 * @return array{status: string, code: int|null, message: string}
	 */
	public static function verify( string $dir ): array {
		$dir_url = self::url_for( $dir );
		if ( '' === $dir_url ) {
			return self::result( self::STATUS_NOT_APPLICABLE, null, __( 'The storage directory is outside the web root.', 'wp-checkpoint' ) );
		}

		$name  = 'probe-' . bin2hex( random_bytes( 8 ) ) . '.txt';
		$path  = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $name;
		$token = bin2hex( random_bytes( 16 ) );

		if ( false === file_put_contents( $path, $token, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- plugin-owned directory.
			return self::result( self::STATUS_UNVERIFIED, null, __( 'Could not write the probe file.', 'wp-checkpoint' ) );
		}

		try {
			$url      = rtrim( $dir_url, '/' ) . '/' . $name;
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 5,
					'redirection' => 0,
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false, $url ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, as used by WP_Site_Health.
					'headers'     => array( 'Cache-Control' => 'no-cache' ),
				)
			);
		} finally {
			wp_delete_file( $path );
		}

		if ( is_wp_error( $response ) ) {
			return self::result( self::STATUS_UNVERIFIED, null, $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( 200 === $code && false !== strpos( $body, $token ) ) {
			return self::result( self::STATUS_EXPOSED, $code, __( 'Files in the storage directory can be downloaded by anyone.', 'wp-checkpoint' ) );
		}
		if ( in_array( $code, array( 401, 403, 404 ), true ) ) {
			return self::result( self::STATUS_PROTECTED, $code, __( 'Direct access to the storage directory is blocked.', 'wp-checkpoint' ) );
		}
		// 200 with other content (a catch-all page, a WAF), 5xx, redirects: inconclusive.
		return self::result( self::STATUS_UNVERIFIED, $code, __( 'The loopback request returned an unexpected response.', 'wp-checkpoint' ) );
	}

	/**
	 * Build a result array.
	 *
	 * @param string   $status  Status constant.
	 * @param int|null $code    HTTP status code.
	 * @param string   $message Human readable detail.
	 * @return array{status: string, code: int|null, message: string}
	 */
	private static function result( string $status, $code, string $message ): array {
		return array(
			'status'  => $status,
			'code'    => $code,
			'message' => $message,
		);
	}
}
