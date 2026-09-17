<?php
/**
 * Plain-text environment report.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

/**
 * Renders checks as text and scrubs paths and secrets. text() is the only
 * rendering entry point; its last step is the Redactor.
 */
final class Report {

	/**
	 * Build the report.
	 *
	 * @param Check[]               $checks   Checks in display order.
	 * @param Redactor              $redactor Redactor seeded with the installation secrets.
	 * @param array<string, string> $paths    Placeholder => absolute path (e.g. "{abspath}" => ABSPATH).
	 * @param array<string, string> $header   Extra header lines, label => value.
	 * @return string
	 */
	public static function text( array $checks, Redactor $redactor, array $paths = array(), array $header = array() ): string {
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

		return $redactor->redact( $text );
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
