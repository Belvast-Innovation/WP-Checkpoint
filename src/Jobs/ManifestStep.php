<?php
/**
 * The step that closes the archive: indexes, manifest, self-check.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Archive\ArchiveVerifier;
use WPCheckpoint\Archive\ChunkHasher;
use WPCheckpoint\Archive\IndexAudit;
use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Archive\Packer;
use WPCheckpoint\Archive\VerificationResult;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages carry line numbers, table names and the verifier's cleaned report; the runner stores them through the redactor and the presenter cleans them before display.

/**
 * Units, in order, each resumable from the cursor's phase:
 *
 *  1. audit: every line of the packed files index has a content hash and
 *     the database index is in lockstep with the export summary
 *     (IndexAudit). Either failing fails the job before anything is
 *     written: an archive without content hashes would pass verification
 *     on sizes alone.
 *  2. finish: the manifest is assembled and its embedded copy written
 *     with the two indexes at the end of the last volume, which is
 *     sealed (Packer::finish(), idempotent on replay).
 *  3. blocks: the container hashes of the last volume, one block per unit.
 *  4. standalone: the full manifest (every volume) as <base>.manifest.json
 *     next to the volumes.
 *  5. verify: ArchiveVerifier at structure depth over what was written,
 *     one verifier step per unit. Anything but a clean structure pass
 *     fails the job with the verifier's own report: this is the writer
 *     and the reader disagreeing on the same bytes, a defect in the
 *     writer, and the report names the phase and the entry.
 *
 * Site facts (URLs, versions, charset) come in through the constructor so
 * the step is testable without WordPress; the export job supplies them.
 */
final class ManifestStep implements Step {

	const ID         = 'manifest';
	const VERIFY_DIR = 'verify';

	const SELF_CHECK_PREFIX = 'Self-check of the written archive failed. This is a defect in the backup writer, not in your data; please report it with the job log.';

	/**
	 * Site facts for the manifest: the keys Manifest requires under "site".
	 *
	 * @var array<string, mixed>
	 */
	private $site;

	/**
	 * Generator name and version.
	 *
	 * @var array{name: string, version: string}
	 */
	private $generator;

	/**
	 * Packer options (the same the pack step used).
	 *
	 * @var array<string, mixed>
	 */
	private $packer_options;

	/**
	 * Content chunk size.
	 *
	 * @var int
	 */
	private $chunk_bytes;

	/**
	 * Cleaner for the verifier's report (JobPresenter::clean or a stand-in).
	 *
	 * @var callable
	 */
	private $clean;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>                 $site           Site facts (Manifest's "site" keys).
	 * @param array{name: string, version: string} $generator      Generator.
	 * @param array<string, mixed>                 $packer_options Packer options.
	 * @param int                                  $chunk_bytes    Content chunk size.
	 * @param callable|null                        $clean          Report cleaner; identity when null.
	 */
	public function __construct( array $site, array $generator, array $packer_options = array(), int $chunk_bytes = Manifest::DEFAULT_CHUNK, $clean = null ) {
		$this->site           = $site;
		$this->generator      = $generator;
		$this->packer_options = PackStep::packer_options_for( $packer_options, $chunk_bytes );
		$this->chunk_bytes    = $chunk_bytes;
		$this->clean          = is_callable( $clean ) ? $clean : static function ( string $text ): string {
			return $text;
		};
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
	 * Run the units until done.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws TransientFailure When a file cannot be written.
	 * @throws \RuntimeException When an audit or the self-check fails.
	 */
	public function run( JobContext $context ): StepResult {
		$work    = $context->work_path();
		$cursor  = array_merge(
			array(
				'phase'    => 'audit',
				'verifier' => array(),
			),
			$context->cursor()
		);
		$plan    = ExportPlan::read( $work, ExportPlan::PLAN );
		$base    = (string) $plan['base'];
		$volumes = $work . DIRECTORY_SEPARATOR . PackStep::VOLUMES;

		if ( 'audit' === $cursor['phase'] ) {
			$this->audit( $work );
			$cursor['phase'] = 'finish';
			$context->checkpoint( $cursor, 20, __( 'Indexes audited', 'wp-checkpoint' ) );
		}
		if ( 'finish' === $cursor['phase'] ) {
			$packer = Packer::open( $volumes, $base, $this->packer_state( $context ), $this->packer_options );
			try {
				$manifest = $this->assemble( $work, $plan, $packer, true );
				$packer->finish(
					array(
						Manifest::DATABASE_INDEX => $work . DIRECTORY_SEPARATOR . Manifest::DATABASE_INDEX,
						Manifest::FILES_INDEX    => $work . DIRECTORY_SEPARATOR . PackStep::PACKED_INDEX,
					),
					$manifest,
					time()
				);
				$cursor['packer'] = $packer->state();
			} finally {
				$packer->close();
			}
			$cursor['phase'] = 'blocks';
			$context->checkpoint( $cursor, 40, __( 'Archive sealed', 'wp-checkpoint' ) );
		}
		if ( 'blocks' === $cursor['phase'] ) {
			$packer = Packer::open( $volumes, $base, $cursor['packer'], $this->packer_options );
			try {
				$more = $packer->has_unhashed_blocks();
				while ( $more ) {
					$packer->hash_next_block();
					$cursor['packer'] = $packer->state();
					$more             = $packer->has_unhashed_blocks();
					if ( $more && $context->should_stop() ) {
						return StepResult::progress( $cursor, 50, __( 'Hashing the last volume', 'wp-checkpoint' ) );
					}
					$context->checkpoint( $cursor, 50, __( 'Hashing the last volume', 'wp-checkpoint' ) );
				}
				$cursor['phase'] = 'standalone';
				$standalone      = $this->assemble( $work, $plan, $packer, false );
			} finally {
				$packer->close();
			}
			$this->write_standalone( $volumes . DIRECTORY_SEPARATOR . $base . '.manifest.json', $standalone );
			$cursor['phase'] = 'verify';
			$context->checkpoint( $cursor, 70, __( 'Manifest written', 'wp-checkpoint' ) );
		}
		if ( 'verify' === $cursor['phase'] ) {
			$dir = $work . DIRECTORY_SEPARATOR . self::VERIFY_DIR;
			if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700 ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- a warning would put the path into the error log.
				throw new TransientFailure( 'The self-check directory could not be created.' );
			}
			$verifier = ArchiveVerifier::open( $volumes . DIRECTORY_SEPARATOR . $base . '.manifest.json', $dir, ArchiveVerifier::DEPTH_STRUCTURE, is_array( $cursor['verifier'] ) ? $cursor['verifier'] : array() );
			while ( $verifier->step() ) {
				$cursor['verifier'] = $verifier->state();
				if ( $context->should_stop() ) {
					return StepResult::progress( $cursor, 85, __( 'Checking the written archive', 'wp-checkpoint' ) );
				}
				$context->checkpoint( $cursor, 85, __( 'Checking the written archive', 'wp-checkpoint' ) );
			}
			$result = $verifier->result();
			$this->judge( $result, $context );
			$cursor['phase'] = 'done';
		}
		$context->logger()->info( 'Manifest step finished', array( 'base' => $base ) );
		return StepResult::done( __( 'Archive checked', 'wp-checkpoint' ) );
	}

	/**
	 * Nothing to do: the self-check directory and the volumes live in the
	 * work directory, which the engine removes; no external resources.
	 *
	 * @param JobContext $context Context.
	 * @return void
	 */
	public function cleanup( JobContext $context ): void {
		unset( $context );
	}

	/**
	 * The packer state to continue from: this step's own cursor once the
	 * finish phase ran, before that the state the pack step wrote to
	 * packer.state.json (the cursor is wiped between steps).
	 *
	 * @param JobContext $context Context.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When the state is missing.
	 */
	private function packer_state( JobContext $context ): array {
		$cursor = $context->cursor();
		if ( isset( $cursor['packer'] ) && is_array( $cursor['packer'] ) && array() !== $cursor['packer'] ) {
			return $cursor['packer'];
		}
		return ExportPlan::read( $context->work_path(), PackStep::STATE );
	}

	/**
	 * Unit 1: both audits.
	 *
	 * @param string $work Work directory.
	 * @return void
	 * @throws \RuntimeException When an audit fails.
	 */
	private function audit( string $work ): void {
		$summary = ExportPlan::read( $work, DatabaseExportStep::SUMMARY );
		IndexAudit::database( $work . DIRECTORY_SEPARATOR . Manifest::DATABASE_INDEX, isset( $summary['tables'] ) && is_array( $summary['tables'] ) ? $summary['tables'] : array(), $this->chunk_bytes );
		IndexAudit::files( $work . DIRECTORY_SEPARATOR . PackStep::PACKED_INDEX, $this->chunk_bytes );
	}

	/**
	 * The manifest as JSON, validated by Manifest itself before it leaves.
	 *
	 * @param string               $work     Work directory.
	 * @param array<string, mixed> $plan     plan.json.
	 * @param Packer               $packer   Packer (for the volume entries).
	 * @param bool                 $embedded Embedded copy (without the last volume) or standalone.
	 * @return string
	 * @throws \RuntimeException When the manifest does not validate (a writer defect).
	 */
	private function assemble( string $work, array $plan, Packer $packer, bool $embedded ): string {
		$review   = ExportPlan::read( $work, ExportPlan::REVIEW );
		$active   = ExportPlan::effective( $plan, $review );
		$database = ExportPlan::read( $work, DatabaseExportStep::SUMMARY );
		$pack     = ExportPlan::read( $work, PackStep::SUMMARY );
		$files    = IndexAudit::files( $work . DIRECTORY_SEPARATOR . PackStep::PACKED_INDEX, $this->chunk_bytes );
		$warnings = array();
		foreach ( array( ExportPlan::PREFLIGHT, FileScanStep::SUMMARY, DatabaseExportStep::SUMMARY, PackStep::SUMMARY ) as $name ) {
			if ( ExportPlan::exists( $work, $name ) ) {
				$data = ExportPlan::read( $work, $name );
				foreach ( isset( $data['warnings'] ) && is_array( $data['warnings'] ) ? $data['warnings'] : array() as $warning ) {
					$warnings[] = (string) $warning;
				}
			}
		}
		foreach ( isset( $review['decisions']['notes'] ) && is_array( $review['decisions']['notes'] ) ? $review['decisions']['notes'] : array() as $note ) {
			$warnings[] = (string) $note;
		}
		$tables = array();
		foreach ( isset( $database['tables'] ) && is_array( $database['tables'] ) ? $database['tables'] : array() as $table ) {
			$tables[] = array(
				'name'   => (string) $table['name'],
				'rows'   => (int) $table['rows'],
				'bytes'  => (int) $table['bytes'],
				'chunks' => (int) $table['chunks'],
				'sha256' => (string) $table['sha256'],
			);
		}
		$document = array(
			'format'            => 'wpcheckpoint-archive',
			'format_version'    => 1,
			'required_features' => array(),
			'kind'              => 'backup',
			'trigger'           => 'manual',
			'created_at'        => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'generator'         => $this->generator,
			'site'              => $this->site,
			'hashing'           => array(
				'algorithm'          => 'sha256',
				'chunk_bytes'        => $this->chunk_bytes,
				'volume_chunk_bytes' => isset( $this->packer_options['volume_chunk_bytes'] ) ? (int) $this->packer_options['volume_chunk_bytes'] : Manifest::DEFAULT_VOLUME_CHUNK,
			),
			'contents'          => array(
				'database' => array() !== $tables,
				'files'    => $active['groups'],
			),
			'exclusions'        => array_values( array_merge( $active['exclusions'], $active['exclude_paths'] ) ),
			'database'          => array_merge(
				array( 'index' => self::content_entry( $work . DIRECTORY_SEPARATOR . Manifest::DATABASE_INDEX, Manifest::DATABASE_INDEX, $this->chunk_bytes ) ),
				isset( $database['exported'] ) && is_array( $database['exported'] ) ? array( 'exported' => $database['exported'] ) : array(),
				array( 'tables' => $tables )
			),
			'files'             => array(
				'count' => $files['count'],
				'bytes' => $files['bytes'],
				'index' => self::content_entry( $work . DIRECTORY_SEPARATOR . PackStep::PACKED_INDEX, Manifest::FILES_INDEX, $this->chunk_bytes ),
			),
			'volumes'           => $packer->volume_entries( $embedded ),
			'embedded'          => $embedded,
			'encryption'        => null,
			'warnings'          => array_values( array_unique( $warnings ) ),
		);
		$json     = json_encode( $document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure PHP class.
		if ( ! is_string( $json ) ) {
			throw new \RuntimeException( 'The manifest could not be encoded.' );
		}
		try {
			return Manifest::from_json( $json )->to_json(); // The reader's rules, before a byte is written.
		} catch ( \InvalidArgumentException $e ) {
			throw new \RuntimeException( 'The manifest the writer assembled does not pass the reader\'s validation (a defect in the backup writer, please report it): ' . $e->getMessage() );
		}
	}

	/**
	 * A content entry ({path, bytes, chunks?, sha256}) for a side index.
	 *
	 * @param string $path        File.
	 * @param string $entry_path  Entry name in the archive.
	 * @param int    $chunk_bytes Chunk size.
	 * @return array<string, mixed>
	 */
	private static function content_entry( string $path, string $entry_path, int $chunk_bytes ): array {
		$hash  = ChunkHasher::content_hash( $path, $chunk_bytes );
		$entry = array(
			'path'  => $entry_path,
			'bytes' => (int) $hash['bytes'],
		);
		if ( isset( $hash['chunks'] ) && is_array( $hash['chunks'] ) ) {
			$entry['chunks'] = $hash['chunks'];
		}
		$entry['sha256'] = (string) $hash['sha256'];
		return $entry;
	}

	/**
	 * Write the standalone manifest whole (temporary file and rename).
	 *
	 * @param string $path Path.
	 * @param string $json Manifest JSON.
	 * @return void
	 * @throws TransientFailure When it cannot be written.
	 */
	private function write_standalone( string $path, string $json ): void {
		$tmp     = $path . '.tmp';
		$written = @file_put_contents( $tmp, $json, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a warning would put the path into the error log; failure is thrown.
		if ( false === $written || strlen( $json ) !== $written || ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- see above.
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- best effort.
			throw new TransientFailure( 'The manifest could not be written.' );
		}
	}

	/**
	 * Only a clean structure pass is accepted.
	 *
	 * @param VerificationResult $result  Result.
	 * @param JobContext         $context Context (for the log).
	 * @return void
	 * @throws \RuntimeException Otherwise, with the verifier's cleaned report.
	 */
	private function judge( VerificationResult $result, JobContext $context ): void {
		$clean = $this->clean;
		if ( VerificationResult::PASSED_PARTIAL === $result->outcome() && array( VerificationResult::REASON_STRUCTURE ) === $result->partial_reasons() && 0 === $result->findings_total() ) {
			$context->logger()->info( 'Self-check passed', array( 'outcome' => $result->outcome() ) );
			return;
		}
		$report = $result->to_text( $clean );
		$context->logger()->error( 'Self-check failed', array( 'report' => $report ) );
		throw new \RuntimeException( self::SELF_CHECK_PREFIX . ' ' . str_replace( "\n", ' ', $report ) );
	}
}
