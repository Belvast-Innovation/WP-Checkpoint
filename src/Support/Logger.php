<?php
/**
 * Per-job log file with mandatory redaction.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

/**
 * Appends lines to one log file. There is exactly one write path and it
 * redacts the fully rendered line, so no field can bypass redaction.
 */
final class Logger {

	const DEBUG   = 'DEBUG';
	const INFO    = 'INFO';
	const WARNING = 'WARNING';
	const ERROR   = 'ERROR';

	/**
	 * Default size cap per log file (5 MB).
	 */
	const DEFAULT_MAX_BYTES = 5242880;

	/**
	 * Absolute path of the log file.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * Redactor applied to every line.
	 *
	 * @var Redactor
	 */
	private $redactor;

	/**
	 * Size cap in bytes.
	 *
	 * @var int
	 */
	private $max_bytes;

	/**
	 * Whether the cap was reached and the truncation marker written.
	 *
	 * @var bool
	 */
	private $truncated = false;

	/**
	 * Constructor.
	 *
	 * @param string   $path      Absolute path of the log file (created on first write).
	 * @param Redactor $redactor  Redactor.
	 * @param int      $max_bytes Size cap.
	 */
	public function __construct( string $path, Redactor $redactor, int $max_bytes = self::DEFAULT_MAX_BYTES ) {
		$this->path      = $path;
		$this->redactor  = $redactor;
		$this->max_bytes = $max_bytes;
	}

	/**
	 * Build a logger for a job inside the logs directory.
	 *
	 * @param string   $logs_dir Logs directory.
	 * @param string   $job_id   Job identifier; reduced to [a-z0-9-].
	 * @param Redactor $redactor Redactor.
	 * @return Logger
	 */
	public static function for_job( string $logs_dir, string $job_id, Redactor $redactor ): Logger {
		$safe = strtolower( (string) preg_replace( '/[^A-Za-z0-9-]+/', '-', $job_id ) );
		$safe = trim( $safe, '-' );
		if ( '' === $safe ) {
			$safe = 'job';
		}
		$name = 'job-' . $safe . '-' . bin2hex( random_bytes( 4 ) ) . '.log';
		return new self( rtrim( $logs_dir, '/\\' ) . DIRECTORY_SEPARATOR . $name, $redactor );
	}

	/**
	 * Path of the log file.
	 *
	 * @return string
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Log a debug line.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra data.
	 * @return void
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->log( self::DEBUG, $message, $context );
	}

	/**
	 * Log an info line.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra data.
	 * @return void
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( self::INFO, $message, $context );
	}

	/**
	 * Log a warning line.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra data.
	 * @return void
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( self::WARNING, $message, $context );
	}

	/**
	 * Log an error line.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra data.
	 * @return void
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( self::ERROR, $message, $context );
	}

	/**
	 * Render and write one line.
	 *
	 * @param string               $level   Level constant.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra data, JSON encoded.
	 * @return void
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		$line = '[' . gmdate( 'Y-m-d\TH:i:s\Z' ) . '] ' . $level . ' ' . str_replace( array( "\r", "\n" ), ' ', $message );
		if ( array() !== $context ) {
			$line .= ' ' . self::encode_context( $context );
		}
		$this->write( $line );
	}

	/**
	 * JSON encode context without escaping slashes or unicode (so redaction
	 * needles match) and with a safe fallback when encoding fails.
	 *
	 * @param array<string, mixed> $context Extra data.
	 * @return string
	 */
	public static function encode_context( array $context ): string {
		// Invalid UTF-8 would make json_encode() drop values (partial output); scrub first so nothing is lost.
		$context = Utf8::scrub_deep( $context );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure PHP class, also used where WordPress is not loaded.
		$json = json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
		if ( is_string( $json ) ) {
			return str_replace( array( "\r", "\n" ), ' ', $json );
		}
		// Encoding failed entirely (recursion, unsupported types): fall back to a
		// key list so the line stays useful without leaking raw values.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- see above.
		return '{"_unencodable_keys":' . json_encode( array_map( 'strval', array_keys( $context ) ) ) . '}';
	}

	/**
	 * The only place that touches the file: redact, then append.
	 *
	 * @param string $line Rendered line without trailing newline.
	 * @return void
	 */
	private function write( string $line ): void {
		if ( $this->truncated ) {
			return;
		}

		$line = $this->redactor->redact( Utf8::scrub( $line ) ) . "\n";

		clearstatcache( true, $this->path );
		$size = is_file( $this->path ) ? (int) filesize( $this->path ) : 0;
		if ( $size + strlen( $line ) > $this->max_bytes ) {
			$this->truncated = true;
			$line            = '[' . gmdate( 'Y-m-d\TH:i:s\Z' ) . '] WARNING Log size limit reached; further entries are dropped.' . "\n";
		}

		$handle = fopen( $this->path, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- append-only log stream.
		if ( false === $handle ) {
			return;
		}
		fwrite( $handle, $line ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see above.
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
	}
}
