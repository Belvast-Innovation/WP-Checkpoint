<?php
/**
 * The last lines of a job log.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

/**
 * Reads at most a few kilobytes from the end of a log file with a stream
 * (never the whole file). Pure PHP; the caller scrubs, redacts and masks
 * the bytes before showing them.
 */
final class LogTail {

	const DEFAULT_MAX_BYTES = 16384;
	const DEFAULT_MAX_LINES = 50;

	/**
	 * Read the tail.
	 *
	 * @param string $path      Log file path.
	 * @param int    $max_bytes Bytes read from the end.
	 * @param int    $max_lines Lines kept.
	 * @return array{exists: bool, ok: bool, text: string} exists false = no file (text empty); ok false = read failed.
	 */
	public static function read( string $path, int $max_bytes = self::DEFAULT_MAX_BYTES, int $max_lines = self::DEFAULT_MAX_LINES ): array {
		if ( '' === $path || ! is_file( $path ) ) {
			return self::result( false, true, '' );
		}
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- stream read of the plugin's own log; failure is reported.
		if ( false === $handle ) {
			return self::result( true, false, '' );
		}
		try {
			$stats = fstat( $handle );
			$size  = is_array( $stats ) && isset( $stats['size'] ) ? (int) $stats['size'] : 0;
			$start = max( 0, $size - max( 1, $max_bytes ) );
			if ( $start > 0 && 0 !== fseek( $handle, $start ) ) {
				return self::result( true, false, '' );
			}
			$data = '';
			while ( ! feof( $handle ) ) {
				$chunk = fread( $handle, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see above.
				if ( false === $chunk ) {
					return self::result( true, false, '' );
				}
				$data .= $chunk;
				if ( strlen( $data ) > $max_bytes + 8192 ) {
					break;
				}
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
		$data = str_replace( "\r\n", "\n", $data );
		if ( $start > 0 ) {
			// The first line is almost certainly cut in the middle.
			$newline = strpos( $data, "\n" );
			$data    = false === $newline ? '' : substr( $data, $newline + 1 );
		}
		$lines = explode( "\n", rtrim( $data, "\n" ) );
		if ( count( $lines ) > $max_lines ) {
			$lines = array_slice( $lines, -$max_lines );
		}
		return self::result( true, true, implode( "\n", $lines ) );
	}

	/**
	 * Shape a result.
	 *
	 * @param bool   $exists File exists.
	 * @param bool   $ok     Read succeeded.
	 * @param string $text   Tail.
	 * @return array{exists: bool, ok: bool, text: string}
	 */
	private static function result( bool $exists, bool $ok, string $text ): array {
		return array(
			'exists' => $exists,
			'ok'     => $ok,
			'text'   => $text,
		);
	}
}
