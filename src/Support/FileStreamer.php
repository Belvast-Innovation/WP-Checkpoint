<?php
/**
 * Streams a file to the client with HTTP Range support.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

/**
 * Pure planning of a download response plus chunked output.
 *
 * The plan() method decides status and byte window from the request headers and is
 * unit tested; stream() emits headers through a callable so tests can
 * capture them, then copies the byte window with fread().
 */
final class FileStreamer {

	/**
	 * Read chunk size (1 MB).
	 */
	const CHUNK_SIZE = 1048576;

	/**
	 * Decide the response for a file of $size bytes.
	 *
	 * Only a single byte range is honoured; multiple ranges are served as the
	 * full file (RFC 9110 allows ignoring Range). An If-Range that does not
	 * match the ETag also yields the full file.
	 *
	 * @param int         $size     File size in bytes.
	 * @param string|null $range    Range header value or null.
	 * @param string      $etag     Current entity tag.
	 * @param string|null $if_range If-Range header value or null.
	 * @return array{status: int, start: int, end: int, length: int}
	 */
	public static function plan( int $size, $range, string $etag, $if_range = null ): array {
		$full = array(
			'status' => 200,
			'start'  => 0,
			'end'    => max( 0, $size - 1 ),
			'length' => $size,
		);

		if ( ! is_string( $range ) || '' === $range ) {
			return $full;
		}
		if ( is_string( $if_range ) && '' !== $if_range && trim( $if_range ) !== $etag ) {
			return $full;
		}
		if ( ! preg_match( '/^\s*bytes\s*=\s*(.+)$/i', $range, $m ) ) {
			return $full;
		}
		$spec = trim( $m[1] );
		if ( false !== strpos( $spec, ',' ) ) {
			return $full;
		}
		if ( ! preg_match( '/^(\d*)-(\d*)$/', $spec, $m ) || ( '' === $m[1] && '' === $m[2] ) ) {
			return self::unsatisfiable( $size );
		}

		if ( '' === $m[1] ) {
			// Suffix range: last N bytes.
			$suffix = (int) $m[2];
			if ( 0 === $suffix || 0 === $size ) {
				return self::unsatisfiable( $size );
			}
			$start = max( 0, $size - $suffix );
			$end   = $size - 1;
		} else {
			$start = (int) $m[1];
			if ( $start >= $size ) {
				return self::unsatisfiable( $size );
			}
			$end = '' === $m[2] ? $size - 1 : min( (int) $m[2], $size - 1 );
			if ( $end < $start ) {
				return self::unsatisfiable( $size );
			}
		}

		return array(
			'status' => 206,
			'start'  => $start,
			'end'    => $end,
			'length' => $end - $start + 1,
		);
	}

	/**
	 * Entity tag derived from size and modification time.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	public static function etag( string $path ): string {
		return '"' . hash( 'sha256', filesize( $path ) . ':' . filemtime( $path ) ) . '"';
	}

	/**
	 * A safe attachment file name: basename only, quotes and control
	 * characters removed.
	 *
	 * @param string $name Proposed name.
	 * @return string
	 */
	public static function safe_filename( string $name ): string {
		$name = basename( str_replace( '\\', '/', $name ) );
		$name = (string) preg_replace( '/[\x00-\x1F\x7F"\\\\]+/', '', $name );
		$name = trim( $name, ". \t" );
		return '' === $name ? 'download' : $name;
	}

	/**
	 * Send headers and (unless HEAD) the byte window.
	 *
	 * @param string                                                $path   Real path of the file.
	 * @param string                                                $name   Attachment file name.
	 * @param array{status: int, start: int, end: int, length: int} $plan Result of plan().
	 * @param string                                                $method HTTP method.
	 * @param callable                                              $header Receives ( string $header_line, int|null $status_code ).
	 * @param resource                                              $out    Output stream to write to.
	 * @return bool False when the file could not be opened.
	 */
	public static function stream( string $path, string $name, array $plan, string $method, callable $header, $out ): bool {
		$size = (int) filesize( $path );

		$header( 'X-Content-Type-Options: nosniff', null );
		$header( 'Accept-Ranges: bytes', null );
		$header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', null );
		$header( 'Pragma: no-cache', null );
		$header( 'ETag: ' . self::etag( $path ), null );

		if ( 416 === $plan['status'] ) {
			$header( 'Content-Range: bytes */' . $size, 416 );
			return true;
		}

		$header( 'Content-Type: application/octet-stream', $plan['status'] );
		$header( 'Content-Disposition: attachment; filename="' . self::safe_filename( $name ) . '"', null );
		$header( 'Content-Length: ' . $plan['length'], null );
		if ( 206 === $plan['status'] ) {
			$header( 'Content-Range: bytes ' . $plan['start'] . '-' . $plan['end'] . '/' . $size, null );
		}

		if ( 'HEAD' === strtoupper( $method ) || 0 === $plan['length'] ) {
			return true;
		}

		$in = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming large files.
		if ( false === $in ) {
			return false;
		}
		try {
			if ( $plan['start'] > 0 && 0 !== fseek( $in, $plan['start'] ) ) {
				return false;
			}
			$remaining = $plan['length'];
			while ( $remaining > 0 && ! feof( $in ) ) {
				$chunk = fread( $in, min( self::CHUNK_SIZE, $remaining ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see above.
				if ( false === $chunk || '' === $chunk ) {
					break;
				}
				fwrite( $out, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see above.
				$remaining -= strlen( $chunk );
				if ( function_exists( 'flush' ) ) {
					flush();
				}
			}
			return 0 === $remaining;
		} finally {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
	}

	/**
	 * 416 plan.
	 *
	 * @param int $size File size.
	 * @return array{status: int, start: int, end: int, length: int}
	 */
	private static function unsatisfiable( int $size ): array {
		return array(
			'status' => 416,
			'start'  => 0,
			'end'    => max( 0, $size - 1 ),
			'length' => 0,
		);
	}
}
