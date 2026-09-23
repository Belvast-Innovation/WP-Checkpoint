<?php
/**
 * The wp wpcheckpoint verify command.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Cli;

use WP_CLI;
use WPCheckpoint\Archive\ArchiveVerifier;
use WPCheckpoint\Archive\VerificationResult;
use WPCheckpoint\Jobs\JobPresenter;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Support\Directories;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies an archive from the command line. Only loaded under WP-CLI.
 */
final class VerifyCommand {

	const EXIT_PASSED             = 0;
	const EXIT_FAILED             = 1;
	const EXIT_INVALID            = 2;
	const EXIT_UNSUPPORTED_LAYOUT = 3;
	const EXIT_PASSED_PARTIAL     = 4;
	const EXIT_CHANGED            = 5;
	const EXIT_UNREADABLE         = 6;

	/**
	 * Seconds between two progress lines of the entry walk.
	 */
	const PROGRESS_SECONDS = 5;

	/**
	 * Presenter (its clean() pipeline).
	 *
	 * @var JobPresenter
	 */
	private $presenter;

	/**
	 * Storage directories (the work directory lives under tmp/).
	 *
	 * @var Directories
	 */
	private $directories;

	/**
	 * Constructor.
	 *
	 * @param JobPresenter $presenter   Presenter.
	 * @param Directories  $directories Directories.
	 */
	public function __construct( JobPresenter $presenter, Directories $directories ) {
		$this->presenter   = $presenter;
		$this->directories = $directories;
	}

	/**
	 * Verify an archive against its manifest.
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : The standalone manifest (.manifest.json), or the last volume to verify from its embedded copy.
	 *
	 * [--depth=<depth>]
	 * : structure checks the manifest, the volumes' presence and the sidecar indexes; full also hashes every volume and entry.
	 * ---
	 * default: full
	 * options:
	 *   - structure
	 *   - full
	 * ---
	 *
	 * [--format=<format>]
	 * : text or json.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * ## EXIT CODES
	 *
	 * 0 intact, 1 damaged, 2 manifest could not be read, 3 layout not supported by this verifier, 4 intact as far as checked (embedded copy or structure only), 5 archive changed during the run (verify again later), 6 this server could not read or write what the check needs.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		Unexpected::guard(
			function () use ( $args, $assoc_args ): void {
				$this->invoke_body( $args, $assoc_args );
			},
			array( $this->presenter, 'clean' )
		);
	}

	/**
	 * Run the verifier unit by unit, reporting the entry walk on standard
	 * error (standard output stays the result, JSON included): the count
	 * when PROGRESS_SECONDS have passed since the last line (by time, not by
	 * units: a slow disk is where a silent run looks stuck, a fast one would
	 * flood the terminal), and once, as soon as the first entries give a
	 * measure, how long a slow walk will take.
	 *
	 * @param ArchiveVerifier $verifier Verifier.
	 * @return \WPCheckpoint\Archive\VerificationResult
	 */
	private function run_with_progress( ArchiveVerifier $verifier ): \WPCheckpoint\Archive\VerificationResult {
		$last  = microtime( true );
		$noted = false;
		while ( $verifier->step() ) {
			$progress = $verifier->progress();
			if ( ArchiveVerifier::PHASE_CONTENTS !== $progress['phase'] || 0 === $progress['total'] ) {
				continue;
			}
			if ( $progress['slow'] && ! $noted && null !== $progress['seconds_left'] ) {
				$noted = true;
				fwrite( STDERR, sprintf( "This check will take about %d more minutes on this disk (measured on the first %d entries).\n", (int) ceil( $progress['seconds_left'] / 60 ), $progress['done'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- progress on standard error; standard output carries the result.
			}
			if ( microtime( true ) - $last >= self::PROGRESS_SECONDS ) {
				$last = microtime( true );
				fwrite( STDERR, sprintf( "Checked %d of %d entries.\n", $progress['done'], $progress['total'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see above.
			}
		}
		return $verifier->result();
	}

	/**
	 * Exit code for an outcome.
	 *
	 * @param string $outcome VerificationResult outcome.
	 * @return int
	 */
	public static function exit_code( string $outcome ): int {
		switch ( $outcome ) {
			case VerificationResult::PASSED:
				return self::EXIT_PASSED;
			case VerificationResult::PASSED_PARTIAL:
				return self::EXIT_PASSED_PARTIAL;
			case VerificationResult::INVALID:
				return self::EXIT_INVALID;
			case VerificationResult::UNSUPPORTED_LAYOUT:
				return self::EXIT_UNSUPPORTED_LAYOUT;
			case VerificationResult::CHANGED:
				return self::EXIT_CHANGED;
			case VerificationResult::UNREADABLE:
				return self::EXIT_UNREADABLE;
			default:
				return self::EXIT_FAILED;
		}
	}

	/**
	 * A private directory for the extracted indexes: under the storage tmp/
	 * directory when there is one, else the system temporary directory.
	 * Named by the residue catalogue, so a directory a killed process
	 * leaves behind is reaped after Residue::VERIFY_TTL.
	 *
	 * @return string
	 */
	private function make_work_dir(): string {
		$base = $this->directories->tmp();
		if ( '' === $base ) {
			$base = sys_get_temp_dir();
		}
		$dir = Residue::new_verify_dir( $base );
		if ( ! @mkdir( $dir, 0700 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- failure is reported below.
			WP_CLI::error( 'The work directory could not be created.' );
		}
		return $dir;
	}

	/**
	 * Remove the work directory and the extracted indexes.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private function remove_work_dir( string $dir ): void {
		$files = glob( $dir . DIRECTORY_SEPARATOR . '*' );
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best effort cleanup.
	}

	/**
	 * The body of __invoke(), run through Unexpected::guard().
	 *
	 * @param string[]             $args       Positional arguments (see __invoke()).
	 * @param array<string, mixed> $assoc_args Options (see __invoke()).
	 * @return void
	 */
	private function invoke_body( array $args, array $assoc_args ): void {
		$path = (string) $args[0];
		if ( ! is_file( $path ) ) {
			WP_CLI::error( 'No such file.' );
		}
		$depth    = (string) ( $assoc_args['depth'] ?? ArchiveVerifier::DEPTH_FULL );
		$work_dir = $this->make_work_dir();
		$error    = '';
		try {
			$verifier = ArchiveVerifier::open( $path, $work_dir, $depth );
			$result   = $this->run_with_progress( $verifier );
		} catch ( \InvalidArgumentException $e ) {
			$error = $e->getMessage();
		} catch ( \RuntimeException $e ) {
			$error = $e->getMessage();
		} finally {
			// WP_CLI::error() exits, and exit skips finally blocks: clean up before reporting.
			$this->remove_work_dir( $work_dir );
		}
		if ( '' !== $error || ! isset( $result ) ) {
			WP_CLI::error( $this->presenter->clean( $error ) );
		}
		$clean = array( $this->presenter, 'clean' );
		if ( 'json' === ( $assoc_args['format'] ?? 'text' ) ) {
			WP_CLI::line( (string) wp_json_encode( $result->to_array( $clean ) ) );
		} else {
			WP_CLI::line( $result->to_text( $clean ) );
		}
		WP_CLI::halt( self::exit_code( $result->outcome() ) );
	}
}
