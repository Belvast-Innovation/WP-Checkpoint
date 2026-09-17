<?php
/**
 * Plain-text environment report.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

/**
 * Renders checks as text and scrubs paths, host names and secrets. text()
 * is the only rendering entry point; its last step is the Redactor.
 */
final class Report {

	/**
	 * Build the report.
	 *
	 * @param Check[]               $checks   Checks in display order.
	 * @param Redactor              $redactor Redactor seeded with the installation secrets.
	 * @param array<string, string> $paths      Placeholder => absolute path (e.g. "{abspath}" => ABSPATH).
	 * @param array<string, string> $header     Extra header lines, label => value.
	 * @param string[]              $site_hosts Host names of the site (home and site URL); never included in the report.
	 * @return string
	 */
	public static function text( array $checks, Redactor $redactor, array $paths = array(), array $header = array(), array $site_hosts = array() ): string {
		$lines   = array( 'WP Checkpoint environment report' );
		$lines[] = 'Generated: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC';
		foreach ( $header as $label => $value ) {
			$lines[] = $label . ': ' . $value;
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

		$text = implode( "\n", $lines ) . "\n";
		$text = self::mask_paths( $text, $paths );
		$text = self::mask_hosts( $text, $site_hosts );

		return $redactor->redact( $text );
	}

	/**
	 * Replace the site's own host names with {site-host}. A "www." label is
	 * kept (www vs non-www differences matter); any other sub-domain label
	 * becomes {subdomain} so that site names of a sub-domain multisite
	 * network never appear. Scheme, port and path are kept. The host of every
	 * other URL becomes {external-host}.
	 *
	 * @param string   $text       Text.
	 * @param string[] $site_hosts Site host names, with or without "www.".
	 * @return string
	 */
	public static function mask_hosts( string $text, array $site_hosts ): string {
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
				'#(?<![\w.\-{])((?:[a-z0-9-]+\.)*?)(' . $alternation . ')(?![\w\-.]|\.[a-z])#i',
				static function ( array $m ): string {
					$prefix = strtolower( $m[1] );
					if ( '' === $prefix ) {
						return '{site-host}';
					}
					return 'www.' === $prefix ? 'www.{site-host}' : '{subdomain}.{site-host}';
				},
				$text
			);
			if ( null !== $result ) {
				$text = $result;
			}
		}

		$result = preg_replace_callback(
			'#\b([a-z][a-z0-9+.\-]*://)((?:[^/\s:@"\'<>]+(?::[^/\s@"\'<>]*)?@)?)([^/\s:"\'<>?\#]+)#i',
			static function ( array $m ): string {
				$host = $m[3];
				if ( false !== strpos( $host, '{' ) ) {
					return $m[0];
				}
				return $m[1] . $m[2] . '{external-host}';
			},
			$text
		);
		if ( null !== $result ) {
			$text = $result;
		}
		return $text;
	}

	/**
	 * Replace known directories with placeholders and hide account names in
	 * common home-directory layouts.
	 *
	 * @param string                $text  Text.
	 * @param array<string, string> $paths Placeholder => absolute path.
	 * @return string
	 */
	public static function mask_paths( string $text, array $paths ): string {
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
			$pattern = '#(?<![\\w/\\\\.\\-}])' . preg_quote( $form, '#' ) . '(?=$|[/\\\\\\s:"\',;)])#m';
			$result  = preg_replace( $pattern, $placeholder, $text );
			if ( null !== $result ) {
				$text = $result;
			}
		}

		$patterns = array(
			'#((?:^|[^\w/\\\\])(?:/home|/Users|/var/www/vhosts|/srv/users|/usr/home|/export/home)/)[^/\s"\':;,)]+#' => '$1***',
			'#((?:^|[^\w\\\\])[A-Za-z]:\\\\Users\\\\)[^\\\\\s"\':;,)]+#i' => '$1***',
			'#((?:^|[^\w/])[A-Za-z]:/Users/)[^/\s"\':;,)]+#i' => '$1***',
		);
		foreach ( $patterns as $pattern => $replacement ) {
			$result = preg_replace( $pattern, $replacement, $text );
			if ( null !== $result ) {
				$text = $result;
			}
		}
		return $text;
	}
}
