<?php
/**
 * The step that lists the files a backup covers.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Files\Exclusions;
use WPCheckpoint\Files\FileScanner;
use WPCheckpoint\Files\PathKey;
use WPCheckpoint\Files\ScanRoots;

/**
 * Drives FileScanner one unit at a time and appends the lines to
 * files.index.jsonl in the job's work directory. The cursor holds the
 * scanner state and the index length at the last checkpoint; on resume
 * the index is truncated to that length first, so a unit that ran after
 * the last checkpoint and died is replayed without duplicate lines. When
 * the scan is done, scan.summary.json (counts, listed findings, warnings)
 * is written next to the index for the pre-flight and the manifest.
 *
 * The lines carry no hashes: the pack step computes them while it reads
 * each file, and the manifest step refuses an index without them.
 */
final class FileScanStep implements Step {

	const ID      = 'scan';
	const SUMMARY = 'scan.summary.json';

	/**
	 * Roots for the scanner.
	 *
	 * @var array<int, array{group: string, path: string, prefix: string, skip?: string[]}>
	 */
	private $roots;

	/**
	 * Exclusions.
	 *
	 * @var Exclusions
	 */
	private $exclusions;

	/**
	 * Warnings from resolving the roots, reported with the scan's.
	 *
	 * @var string[]
	 */
	private $root_warnings;

	/**
	 * Whether roots and exclusions come from plan.json in the work
	 * directory (the export job) instead of the constructor (tests).
	 *
	 * @var bool
	 */
	private $from_plan = false;

	/**
	 * Content chunk size: bounds the largest indexable file (IndexLine::max_indexable_bytes()).
	 *
	 * @var int
	 */
	private $chunk_bytes;

	/**
	 * Constructor. The job type resolves the roots (ScanRoots) and the
	 * exclusions from the job's options.
	 *
	 * @param array<int, array{group: string, path: string, prefix: string, skip?: string[]}> $roots         Roots.
	 * @param Exclusions                                                                      $exclusions    Exclusions.
	 * @param string[]                                                                        $root_warnings Warnings from ScanRoots::resolve().
	 * @param int                                                                             $chunk_bytes   Content chunk size (bounds the largest indexable file).
	 */
	public function __construct( array $roots, Exclusions $exclusions, array $root_warnings = array(), int $chunk_bytes = Manifest::DEFAULT_CHUNK ) {
		$this->roots         = $roots;
		$this->exclusions    = $exclusions;
		$this->root_warnings = $root_warnings;
		$this->chunk_bytes   = $chunk_bytes;
	}

	/**
	 * A step that takes its content groups and exclusion patterns from
	 * plan.json (written by PreflightStep) on every tick, resolving the
	 * roots against the job's storage directory.
	 *
	 * @return FileScanStep
	 *
	 * @param int $chunk_bytes Content chunk size (bounds the largest indexable file).
	 */
	public static function from_plan( int $chunk_bytes = Manifest::DEFAULT_CHUNK ): FileScanStep {
		$step            = new self( array(), new Exclusions( array(), array() ), array(), $chunk_bytes );
		$step->from_plan = true;
		return $step;
	}

	/**
	 * Step id.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Scan until the budget is spent or the walk is complete.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws TransientFailure When the index cannot be written (disk full, directory gone).
	 */
	public function run( JobContext $context ): StepResult {
		if ( $this->from_plan ) {
			$plan                = ExportPlan::read( $context->work_path(), ExportPlan::PLAN );
			$groups              = isset( $plan['groups'] ) && is_array( $plan['groups'] ) ? array_map( 'strval', $plan['groups'] ) : array();
			$resolved            = ScanRoots::resolve( $groups, $context->storage_path() );
			$this->roots         = $resolved['roots'];
			$this->root_warnings = $resolved['warnings'];
			$this->exclusions    = new Exclusions( isset( $plan['exclusions'] ) && is_array( $plan['exclusions'] ) ? array_map( 'strval', $plan['exclusions'] ) : array() );
		}
		$cursor  = $context->cursor();
		$state   = isset( $cursor['scan'] ) && is_array( $cursor['scan'] ) ? $cursor['scan'] : FileScanner::initial_state();
		$length  = isset( $cursor['bytes'] ) ? (int) $cursor['bytes'] : 0;
		$scanner = new FileScanner( $this->roots, $this->exclusions, PHP_INT_SIZE, $this->chunk_bytes );
		$path    = $context->work_path() . DIRECTORY_SEPARATOR . Manifest::FILES_INDEX;
		$handle  = $this->open_index( $path, $length );
		$since   = 0;
		try {
			while ( empty( $state['done'] ) ) {
				$written = 0;
				$state   = $scanner->scan_unit(
					$state,
					static function ( array $line ) use ( $handle, &$written ): void {
						$json = wp_json_encode( $line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
						if ( ! is_string( $json ) ) {
							throw new \RuntimeException( 'A file entry could not be encoded.' );
						}
						$bytes = fwrite( $handle, $json . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- stream write of the job's own index.
						if ( false === $bytes || strlen( $json ) + 1 !== $bytes ) {
							throw new TransientFailure( 'The file index could not be written.' );
						}
						$written += $bytes;
					}
				);
				$length += $written;
				$since  += $written;
				$cursor  = array(
					'scan'  => $state,
					'bytes' => $length,
				);
				if ( ! empty( $state['done'] ) ) {
					break;
				}
				if ( $context->should_checkpoint( $since ) ) {
					if ( ! fflush( $handle ) ) {
						throw new TransientFailure( 'The file index could not be flushed.' );
					}
					$context->checkpoint( $cursor, $this->percent( $state ), $this->message( $state ) );
					$since = 0;
				}
				if ( $context->should_stop() ) {
					return StepResult::progress( $cursor, $this->percent( $state ), $this->message( $state ) );
				}
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream write of the job's own index.
		}
		$this->write_summary( $context->work_path() . DIRECTORY_SEPARATOR . self::SUMMARY, $state );
		$this->log_summary( $context, $state );
		return StepResult::done( $this->message( $state ) );
	}

	/**
	 * Nothing to do: the index and summary live in the work directory,
	 * which the engine removes after the cancel (and after retention for a
	 * failed job); the step creates no tables or external resources.
	 *
	 * @param JobContext $context Context.
	 * @return void
	 */
	public function cleanup( JobContext $context ): void {
		unset( $context );
	}

	/**
	 * Open the index for appending at exactly $length bytes: a shorter or
	 * missing file is created, a longer one (a unit ran after the last
	 * checkpoint) is cut back so the replay does not duplicate lines.
	 *
	 * @param string $path   Index path.
	 * @param int    $length Length at the last checkpoint.
	 * @return resource
	 * @throws TransientFailure When the file cannot be opened or truncated.
	 */
	private function open_index( string $path, int $length ) {
		// Silenced: a warning would put the full path into the error log, bypassing the path masking.
		$handle = @fopen( $path, 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- see above.
		if ( false === $handle ) {
			throw new TransientFailure( 'The file index could not be opened.' );
		}
		$stat = fstat( $handle );
		$size = is_array( $stat ) ? (int) $stat['size'] : 0;
		if ( $size > $length && ! ftruncate( $handle, $length ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
			throw new TransientFailure( 'The file index could not be truncated.' );
		}
		if ( $size < $length ) {
			// The checkpoint claims more than the file holds: the work directory was tampered with or lost.
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
			throw new \RuntimeException( 'The file index is shorter than the last checkpoint recorded.' );
		}
		if ( 0 !== fseek( $handle, $length ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
			throw new TransientFailure( 'The file index could not be positioned.' );
		}
		return $handle;
	}

	/**
	 * Write the summary the pre-flight and the manifest read.
	 *
	 * @param string               $path  Summary path.
	 * @param array<string, mixed> $state Final scanner state.
	 * @return void
	 * @throws TransientFailure When the summary cannot be written.
	 */
	private function write_summary( string $path, array $state ): void {
		$summary = array(
			'counts'                  => $state['counts'],
			'lists'                   => $state['lists'],
			'warnings'                => array_merge( $this->root_warnings, $state['warnings'], PathKey::normalization_available() ? array() : array( self::normalization_warning() ) ),
			'normalization_available' => PathKey::normalization_available(),
			'exclusions'              => $this->exclusions->globs(),
		);
		$json    = wp_json_encode( $summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || false === @file_put_contents( $path, $json ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- small file in the job's own work directory; failure is thrown.
			throw new TransientFailure( 'The scan summary could not be written.' );
		}
	}

	/**
	 * What a user without the intl extension needs to know.
	 *
	 * @return string
	 */
	public static function normalization_warning(): string {
		return __( 'This server lacks the intl PHP extension, so file names that differ only in Unicode form could not be checked for duplicates; if the site has non-ASCII file names, some files may overwrite each other when restored on Windows or macOS.', 'wp-checkpoint' );
	}

	/**
	 * One log line per finished scan.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $state   Final state.
	 * @return void
	 */
	private function log_summary( JobContext $context, array $state ): void {
		$context->logger()->info( 'Scan finished', $state['counts'] );
		foreach ( array_merge( $this->root_warnings, $state['warnings'] ) as $warning ) {
			$context->logger()->warning( $warning );
		}
	}

	/**
	 * Coarse progress: roots finished over roots total.
	 *
	 * @param array<string, mixed> $state State.
	 * @return int
	 */
	private function percent( array $state ): int {
		$total = max( 1, count( $this->roots ) );
		return (int) min( 100, floor( 100 * (int) $state['root'] / $total ) );
	}

	/**
	 * Progress text (counts only; no paths).
	 *
	 * @param array<string, mixed> $state State.
	 * @return string
	 */
	private function message( array $state ): string {
		return sprintf(
			/* translators: 1: number of files, 2: number of directories */
			__( 'Listed %1$d files in %2$d directories', 'wp-checkpoint' ),
			(int) $state['counts']['files'],
			(int) $state['counts']['directories']
		);
	}
}
