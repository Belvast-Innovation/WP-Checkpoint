<?php
/**
 * Plain-text environment report.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

/**
 * Renders checks as text and scrubs paths, host names and secrets. text()
 * is the only rendering entry point: the text is made valid UTF-8 first (so
 * the /u patterns cannot fail on encoding), then masked, then redacted.
 */
final class Report {

	/**
	 * Test seam: runs on the text after path masking and may return null to
	 * simulate a failed masking step. Never set in production.
	 *
	 * @var callable|null
	 */
	private static $after_mask_paths_hook = null;

	/**
	 * Test seam setter.
	 *
	 * @internal
	 * @param callable|null $hook Receives the masked text; returning null means "masking failed".
	 * @return void
	 */
	public static function set_after_mask_paths_hook( $hook ): void {
		self::$after_mask_paths_hook = is_callable( $hook ) ? $hook : null;
	}

	/**
	 * Build the report.
	 *
	 * @param Check[]               $checks   Checks in display order.
	 * @param Redactor              $redactor Redactor seeded with the installation secrets.
	 * @param array<string, string> $paths      Placeholder => absolute path (e.g. "{abspath}" => ABSPATH).
	 * @param array<string, string> $header     Extra header lines, label => value.
	 * @param string[]              $site_hosts Host names of the site (home and site URL); never included in the report.
	 * @param string[]              $site_paths URL path prefixes of the site (and of every site in a sub-directory network).
	 * @param array<string, mixed>  $options    "coarse_site_paths" (bool) masks every first path segment after the
	 *                                          site host; "network_root" (string) is the network's own path prefix.
	 * @return string
	 */
	public static function text( array $checks, Redactor $redactor, array $paths = array(), array $header = array(), array $site_hosts = array(), array $site_paths = array(), array $options = array() ): string {
		$coarse       = ! empty( $options['coarse_site_paths'] );
		$network_root = isset( $options['network_root'] ) ? (string) $options['network_root'] : '';

		$lines   = array( 'WP Checkpoint environment report' );
		$lines[] = 'Generated: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC';
		foreach ( $header as $label => $value ) {
			$lines[] = $label . ': ' . $value;
		}
		if ( $coarse ) {
			$lines[] = 'Site paths: coarse (large network) - every first path segment after the site host is shown as {site-path}; it may be an ordinary page rather than a site, so the directory structure cannot be inferred from it.';
		}
		$summary = Check::summarize( $checks );
		$lines[] = sprintf( 'Summary: %d ok, %d warnings, %d errors, %d info', $summary[ Check::OK ], $summary[ Check::WARNING ], $summary[ Check::ERROR ], $summary[ Check::INFO ] );

		$group = null;
		foreach ( $checks as $check ) {
			if ( $check->group !== $group ) {
				$group   = $check->group;
				$lines[] = '';
				$lines[] = '[' . $group . ']';
			}
			$line = $check->label . ': ' . $check->value . ' (' . strtoupper( $check->status ) . ')';
			if ( '' !== $check->message ) {
				$line .= ' - ' . $check->message;
			}
			$lines[] = $line;
			if ( '' !== $check->impact ) {
				$lines[] = '  Impact: ' . $check->impact;
			}
		}

		$text = Utf8::scrub( implode( "\n", $lines ) . "\n" );
		$text = self::mask_paths( $text, $paths );
		if ( null !== $text && null !== self::$after_mask_paths_hook ) {
			$text = call_user_func( self::$after_mask_paths_hook, $text );
		}
		if ( null === $text ) {
			return self::failure_text();
		}
		$text = self::mask_hosts( $text, $site_hosts, $site_paths, $coarse, $network_root );
		if ( null === $text ) {
			return self::failure_text();
		}

		return $redactor->redact( $text );
	}

	/**
	 * Text returned instead of a report when a masking pattern failed.
	 *
	 * A regex failure (backtrack or recursion limit, invalid UTF-8) would
	 * otherwise let account names, host names and paths through, which the
	 * Redactor does not cover. Nothing of the report is emitted then.
	 *
	 * @return string
	 */
	public static function failure_text(): string {
		if ( function_exists( '__' ) ) {
			return __( 'The report could not be generated safely. Please share a screenshot of the environment table instead.', 'wp-checkpoint' );
		}
		return 'The report could not be generated safely. Please share a screenshot of the environment table instead.';
	}

	/**
	 * Replace the site's own host names with {site-host}. A "www." label is
	 * kept (www vs non-www differences matter); any other sub-domain label
	 * becomes {subdomain} so that site names of a sub-domain multisite
	 * network never appear. Scheme, port and path are kept. The host of every
	 * other URL becomes {external-host}.
	 *
	 * URLs on the site's host whose path starts with one of $site_paths (the
	 * site's own sub-directory, or any site of a sub-directory network) get
	 * that prefix replaced by /{site-path}, matched on path-segment boundaries
	 * and longest first, so "/client" never matches "/clienta". Paths of
	 * external URLs are left alone.
	 *
	 * @param string   $text         Text.
	 * @param string[] $site_hosts   Site host names, with or without "www.".
	 * @param string[] $site_paths   Site path prefixes such as "/shop" or "/clienta/".
	 * @param bool     $coarse       Also mask every unknown first path segment (large networks).
	 * @param string   $network_root Network path prefix to keep in coarse mode ("" when the network is at "/").
	 * @return string|null Null when a pattern could not be applied (fail closed).
	 */
	public static function mask_hosts( string $text, array $site_hosts, array $site_paths = array(), bool $coarse = false, string $network_root = '' ) {
		$hosts = array();
		foreach ( $site_hosts as $host ) {
			$host = strtolower( trim( (string) $host ) );
			$host = (string) preg_replace( '/^www\./', '', $host );
			if ( '' !== $host ) {
				$hosts[ $host ] = true;
			}
		}
		if ( array() !== $hosts ) {
			$alternation = implode(
				'|',
				array_map(
					static function ( string $h ): string {
						return preg_quote( $h, '#' );
					},
					array_keys( $hosts )
				)
			);
			$result      = preg_replace_callback(
				'#(?<![\w.\-{])((?:[a-z0-9-]+\.)*?)(' . $alternation . ')(?![\w\-.]|\.[a-z])#iu',
				static function ( array $m ): string {
					$prefix = strtolower( $m[1] );
					if ( '' === $prefix ) {
						return '{site-host}';
					}
					return 'www.' === $prefix ? 'www.{site-host}' : '{subdomain}.{site-host}';
				},
				$text
			);
			if ( null === $result ) {
				return null;
			}
			$text = $result;
		}

		$result = preg_replace_callback(
			'#\b([a-z][a-z0-9+.\-]*://)((?:[^/\s:@"\'<>]+(?::[^/\s@"\'<>]*)?@)?)([^/\s:"\'<>?\#]+)#iu',
			static function ( array $m ): string {
				$host = $m[3];
				if ( false !== strpos( $host, '{' ) ) {
					return $m[0];
				}
				return $m[1] . $m[2] . '{external-host}';
			},
			$text
		);
		if ( null === $result ) {
			return null;
		}

		return self::mask_site_paths( $result, $site_paths, $coarse, $network_root );
	}

	/**
	 * Names WordPress refuses as sub-directory site paths, plus anything that
	 * ends in ".php": in coarse mode these first segments are left alone.
	 *
	 * @var string[]
	 */
	const RESERVED_SITE_NAMES = array( 'page', 'comments', 'blog', 'files', 'feed', 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'embed' );

	/**
	 * Replace site path prefixes after a {site-host} (with or without a
	 * "www." / "{subdomain}." label and a port) by /{site-path}.
	 *
	 * In coarse mode a first segment that matches no known prefix is masked
	 * too (after the network root, when there is one), except WordPress
	 * reserved names and *.php files, which can never be a site.
	 *
	 * @param string   $text         Text in which hosts are already masked.
	 * @param string[] $site_paths   Site path prefixes.
	 * @param bool     $coarse       Coarse mode.
	 * @param string   $network_root Network path prefix ("" when the network is at "/").
	 * @return string|null Null when the pattern could not be applied (fail closed).
	 */
	public static function mask_site_paths( string $text, array $site_paths, bool $coarse = false, string $network_root = '' ) {
		$prefixes = array();
		foreach ( $site_paths as $path ) {
			$path = '/' . trim( str_replace( '\\', '/', (string) $path ), '/' );
			if ( '/' !== $path ) {
				$prefixes[ $path ] = true;
			}
		}
		if ( array() === $prefixes && ! $coarse ) {
			return $text;
		}
		$root     = '/' . trim( str_replace( '\\', '/', $network_root ), '/' );
		$root     = '/' === $root ? '' : $root;
		$prefixes = array_keys( $prefixes );
		usort(
			$prefixes,
			static function ( string $a, string $b ): int {
				return strlen( $b ) - strlen( $a );
			}
		);

		$result = preg_replace_callback(
			'#((?:www\.|\{subdomain\}\.)?\{site-host\}(?::\d+)?)(/[^\s"\'<>?\#]*)#u',
			static function ( array $m ) use ( $prefixes, $coarse, $root ): string {
				$path = $m[2];
				foreach ( $prefixes as $prefix ) {
					if ( $path === $prefix ) {
						return $m[1] . '/{site-path}';
					}
					if ( 0 === strpos( $path, $prefix . '/' ) ) {
						return $m[1] . '/{site-path}' . substr( $path, strlen( $prefix ) );
					}
				}
				if ( ! $coarse ) {
					return $m[0];
				}
				// Coarse: /root/<first>/rest -> /root/{site-path}/rest.
				$rest = $path;
				if ( '' !== $root ) {
					if ( $rest === $root || 0 !== strpos( $rest, $root . '/' ) ) {
						return $m[0];
					}
					$rest = substr( $rest, strlen( $root ) );
				}
				if ( ! preg_match( '#^/([^/]+)(/.*)?$#u', $rest, $seg ) ) {
					return $m[0];
				}
				$first = $seg[1];
				if ( in_array( strtolower( $first ), self::RESERVED_SITE_NAMES, true ) || 1 === preg_match( '/\\.php$/i', $first ) ) {
					return $m[0];
				}
				return $m[1] . $root . '/{site-path}' . ( isset( $seg[2] ) ? $seg[2] : '' );
			},
			$text
		);
		return $result;
	}

	/**
	 * Replace known directories with placeholders and hide account names in
	 * common home-directory layouts.
	 *
	 * @param string                $text  Text.
	 * @param array<string, string> $paths Placeholder => absolute path.
	 * @return string|null Null when a pattern could not be applied (fail closed).
	 */
	public static function mask_paths( string $text, array $paths ) {
		$replacements = array();
		foreach ( $paths as $placeholder => $path ) {
			$path = rtrim( (string) $path, '/\\' );
			if ( '' === $path ) {
				continue;
			}
			$forms = array_unique( array( $path, Paths::normalize( $path ), str_replace( '/', '\\', Paths::normalize( $path ) ) ) );
			foreach ( $forms as $form ) {
				if ( strlen( $form ) >= 2 ) {
					$replacements[ $form ] = $placeholder;
				}
			}
		}
		// Longest first so "{abspath}" wins over "{abspath-parent}".
		uksort(
			$replacements,
			static function ( string $a, string $b ): int {
				return strlen( $b ) - strlen( $a );
			}
		);
		foreach ( $replacements as $form => $placeholder ) {
			// Whole path components only: "/tmp" must not match inside "/home/x/tmp"
			// or right after an already inserted placeholder ("{abspath-parent}/tmp").
			$pattern = '#(?<![\\w/\\\\.\\-}])' . preg_quote( $form, '#' ) . '(?=$|[/\\\\\\s:"\',;)])#mu';
			$result  = preg_replace( $pattern, $placeholder, $text );
			if ( null === $result ) {
				return null;
			}
			$text = $result;
		}

		$patterns = array(
			'#((?:^|[^\w/\\\\])(?:/home|/Users|/var/www/vhosts|/srv/users|/usr/home|/export/home)/)[^/\s"\':;,)]+#u' => '$1***',
			'#((?:^|[^\w\\\\])[A-Za-z]:\\\\Users\\\\)[^\\\\\s"\':;,)]+#iu' => '$1***',
			'#((?:^|[^\w/])[A-Za-z]:/Users/)[^/\s"\':;,)]+#iu' => '$1***',
		);
		foreach ( $patterns as $pattern => $replacement ) {
			$result = preg_replace( $pattern, $replacement, $text );
			if ( null === $result ) {
				return null;
			}
			$text = $result;
		}
		return $text;
	}
}
