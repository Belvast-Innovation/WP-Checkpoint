<?php
/**
 * The outcome of verifying an archive.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Archive;

/**
 * Derived from the verifier's state, never built by hand: a full pass
 * (PASSED) exists only when every phase ran on a standalone manifest and
 * nothing was found. An embedded manifest copy or a structure-only run can
 * reach PASSED_PARTIAL at best, so is_complete_pass() cannot be true for
 * them. CHANGED means the archive was written to during the run and
 * UNREADABLE that this server could not read or write what the check
 * needs; both are inconclusive and say nothing about the archive. Text and array forms take the cleaning callable
 * as a required argument: no report leaves without it.
 */
final class VerificationResult {

	const PASSED             = 'passed';
	const PASSED_PARTIAL     = 'passed_partial';
	const FAILED             = 'failed';
	const INVALID            = 'invalid';
	const UNSUPPORTED_LAYOUT = 'unsupported_layout';
	const CHANGED            = 'changed';
	const UNREADABLE         = 'unreadable';

	/**
	 * Advice when the layout is not this plugin's (entries reordered, data
	 * descriptors): another tool repacked the archive; the data is most
	 * likely fine and the original volumes are what to look for. The
	 * documentation section named here is the one the manual restore steps
	 * are published under.
	 */
	const REPACKED_ADVICE = 'Restore is refused because the contents could not be checked: the entries are not in the order this plugin writes them, or they were written by another tool. Your data is most likely fine. Use the original volume files the plugin produced, exactly as they are: do not repack them with another archive tool, and do not unzip them and zip them again. If those files are gone, follow the steps in the "Restoring a backup by hand" section of the documentation (extract every volume and import the SQL files).';

	/**
	 * Advice when a local header disagrees with the central directory: the
	 * archive is inconsistent inside. Another tool may have caused it, but
	 * so may an interrupted write or two processes writing at once (the
	 * plugin's own writer): the files the user holds may be the originals,
	 * so the advice is a new backup, not a search for other files.
	 */
	const INCONSISTENT_ADVICE = 'The archive is inconsistent inside: an entry\'s header disagrees with the archive\'s directory. This can happen when writing was interrupted or when two processes wrote the same backup at once. Do not rely on this backup; make a new one.';

	const REASON_EMBEDDED  = 'embedded_manifest';
	const REASON_STRUCTURE = 'structure_only';

	/**
	 * Outcome constant.
	 *
	 * @var string
	 */
	private $outcome;

	/**
	 * Why a pass is partial.
	 *
	 * @var string[]
	 */
	private $partial_reasons;

	/**
	 * Verifier state (counts, findings, phase).
	 *
	 * @var array<string, mixed>
	 */
	private $state;

	/**
	 * Findings kept (the first MAX_STORED_FINDINGS).
	 *
	 * @var Finding[]
	 */
	private $findings;

	/**
	 * Private: see from_state().
	 *
	 * @param string               $outcome  Outcome.
	 * @param string[]             $reasons  Partial reasons.
	 * @param array<string, mixed> $state    State.
	 * @param Finding[]            $findings Findings.
	 */
	private function __construct( string $outcome, array $reasons, array $state, array $findings ) {
		$this->outcome         = $outcome;
		$this->partial_reasons = $reasons;
		$this->state           = $state;
		$this->findings        = $findings;
	}

	/**
	 * Derive the outcome from a finished verifier state.
	 *
	 * @param array<string, mixed> $state Verifier state.
	 * @return VerificationResult
	 * @throws \LogicException When the verifier has not finished.
	 */
	public static function from_state( array $state ): VerificationResult {
		if ( ArchiveVerifier::PHASE_DONE !== ( $state['phase'] ?? '' ) ) {
			throw new \LogicException( 'The verifier has not finished.' );
		}
		$findings = array();
		foreach ( (array) ( $state['findings'] ?? array() ) as $data ) {
			$findings[] = Finding::from_array( (array) $data );
		}
		$kinds   = (array) ( $state['kinds'] ?? array() );
		$damage  = (int) ( $kinds[ Finding::MISSING ] ?? 0 ) + (int) ( $kinds[ Finding::CORRUPT ] ?? 0 ) + (int) ( $kinds[ Finding::MALFORMED ] ?? 0 );
		$reasons = array();
		if ( ! empty( $state['invalid'] ) ) {
			$outcome = self::INVALID;
		} elseif ( ! empty( $state['unreadable'] ) ) {
			$outcome = self::UNREADABLE;
		} elseif ( ! empty( $state['changed'] ) ) {
			// Whatever was found before the change may describe bytes that no longer exist: inconclusive, not damaged.
			$outcome = self::CHANGED;
		} elseif ( $damage > 0 ) {
			$outcome = self::FAILED;
		} elseif ( (int) ( $kinds[ Finding::UNSUPPORTED ] ?? 0 ) > 0 ) {
			$outcome = self::UNSUPPORTED_LAYOUT;
		} else {
			if ( ! empty( $state['embedded'] ) ) {
				$reasons[] = self::REASON_EMBEDDED;
			}
			if ( ArchiveVerifier::DEPTH_FULL !== ( $state['depth'] ?? '' ) ) {
				$reasons[] = self::REASON_STRUCTURE;
			}
			$outcome = array() === $reasons ? self::PASSED : self::PASSED_PARTIAL;
		}
		return new self( $outcome, $reasons, $state, $findings );
	}

	/**
	 * Outcome constant.
	 *
	 * @return string
	 */
	public function outcome(): string {
		return $this->outcome;
	}

	/**
	 * True only for PASSED: every phase ran, standalone manifest, nothing found.
	 *
	 * @return bool
	 */
	public function is_complete_pass(): bool {
		return self::PASSED === $this->outcome;
	}

	/**
	 * Whether a restore from this archive must be refused. Damage of any
	 * kind refuses (a damaged sidecar index has no "skip" option); a layout
	 * this verifier cannot check refuses as well, because unverified is not
	 * verified, and so does a run the archive changed under. Whether an
	 * importer that looks entries up by name could accept an unsupported
	 * layout is a T030 design question; until it is answered this stays the
	 * safe default.
	 *
	 * @return bool
	 */
	public function restore_refused(): bool {
		return self::PASSED !== $this->outcome && self::PASSED_PARTIAL !== $this->outcome;
	}

	/**
	 * Why the pass is partial (empty unless PASSED_PARTIAL).
	 *
	 * @return string[]
	 */
	public function partial_reasons(): array {
		return $this->partial_reasons;
	}

	/**
	 * Counts by name.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		$out = array();
		foreach ( (array) ( $this->state['counts'] ?? array() ) as $key => $value ) {
			$out[ (string) $key ] = (int) $value;
		}
		return $out;
	}

	/**
	 * Findings kept, oldest first.
	 *
	 * @return Finding[]
	 */
	public function findings(): array {
		return $this->findings;
	}

	/**
	 * Total findings, including those not kept.
	 *
	 * @return int
	 */
	public function findings_total(): int {
		return (int) ( $this->state['findings_total'] ?? 0 );
	}

	/**
	 * Structured form. Volumes are ordinals, entries relative paths; no
	 * volume file names, no site fields, no absolute paths.
	 *
	 * @param callable $clean function( string ): string applied to every text.
	 * @return array<string, mixed>
	 */
	public function to_array( callable $clean ): array {
		$findings = array();
		foreach ( $this->findings as $finding ) {
			$findings[] = $finding->to_array( $clean );
		}
		return array(
			'outcome'          => $this->outcome,
			'complete'         => $this->is_complete_pass(),
			'restore_refused'  => $this->restore_refused(),
			'depth'            => (string) ( $this->state['depth'] ?? '' ),
			'embedded'         => ! empty( $this->state['embedded'] ),
			'partial_reasons'  => $this->partial_reasons,
			'stopped_at'       => isset( $this->state['stopped_at'] ) ? (string) $this->state['stopped_at'] : null,
			'unreadable_cause' => isset( $this->state['unreadable_cause'] ) ? (string) $this->state['unreadable_cause'] : null,
			'counts'           => $this->counts(),
			'findings'         => $findings,
			'findings_total'   => $this->findings_total(),
		);
	}

	/**
	 * Number of findings per kind (Finding::* constants), over all findings,
	 * not only the stored ones.
	 *
	 * @return array<string, int>
	 */
	public function kinds(): array {
		$out = array();
		foreach ( (array) ( $this->state['kinds'] ?? array() ) as $kind => $count ) {
			$out[ (string) $kind ] = (int) $count;
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Why the server could not check (EnvironmentFailure::cause()), or '' when it could.
	 *
	 * @return string
	 */
	public function unreadable_cause(): string {
		return (string) ( $this->state['unreadable_cause'] ?? '' );
	}

	/**
	 * Plain-text report. The first line is one of four fixed sentences.
	 *
	 * @param callable $clean function( string ): string applied to every text.
	 * @return string
	 */
	public function to_text( callable $clean ): string {
		$lines = array( self::headline( $this->outcome ) );
		foreach ( $this->partial_reasons as $reason ) {
			$lines[] = self::REASON_EMBEDDED === $reason
				? 'The manifest is the copy embedded in the last volume; that volume\'s own container hash was not checked. Verify from the standalone manifest for a full pass.'
				: 'Only the structure was checked (manifest, volumes, sidecar indexes, the order, names and sizes of the entries and their headers); volume containers and entry contents were not.';
		}
		// stopped_at and the count keys are fixed vocabulary set by the verifier, not text from the archive;
		// only a forged cursor could carry anything else there, which needs database write access already.
		if ( isset( $this->state['stopped_at'] ) ) {
			$lines[] = 'Verification stopped in phase "' . (string) $this->state['stopped_at'] . '".';
		}
		$next = self::next_step( $this->outcome, (string) ( $this->state['unreadable_cause'] ?? '' ) );
		if ( ! empty( $this->state['inconsistent'] ) ) {
			$next = trim( self::INCONSISTENT_ADVICE . ' ' . $next );
		}
		if ( '' !== $next ) {
			$lines[] = $next;
		}
		$counts = $this->counts();
		if ( array() !== $counts ) {
			$parts = array();
			foreach ( $counts as $key => $value ) {
				$parts[] = str_replace( '_', ' ', $key ) . ' ' . $value;
			}
			$lines[] = 'Checked: ' . implode( ', ', $parts ) . '.';
		}
		foreach ( $this->findings as $finding ) {
			$lines[] = $finding->to_text( $clean );
		}
		$more = $this->findings_total() - count( $this->findings );
		if ( $more > 0 ) {
			$lines[] = sprintf( '%d more finding(s) not listed.', $more );
		}
		return implode( "\n", $lines );
	}

	/**
	 * What the user should do next, for outcomes where that is not obvious.
	 *
	 * @param string $outcome Outcome.
	 * @param string $cause   For UNREADABLE: EnvironmentFailure's cause ('' when unknown).
	 * @return string Empty when there is nothing to add.
	 */
	public static function next_step( string $outcome, string $cause = '' ): string {
		switch ( $outcome ) {
			case self::UNSUPPORTED_LAYOUT:
				return self::REPACKED_ADVICE;
			case self::CHANGED:
				return 'Nothing read after the change is conclusive; the archive may still be being transferred or downloaded. Verify again once writing has finished.';
			case self::UNREADABLE:
				if ( EnvironmentFailure::ZLIB === $cause ) {
					return 'The archive itself is not in question: its compressed entries need the zlib extension, and this server\'s PHP does not have it. Verify or restore the backup on a server whose PHP has the zlib extension (or ask your host to enable it).';
				}
				if ( EnvironmentFailure::ACCESS === $cause ) {
					return 'The archive itself was not judged: this server cannot read one of its files. Check the permissions of the backup files and that their storage is healthy, then verify again.';
				}
				return 'The archive itself was not judged. Check the free disk space and the permissions of the plugin\'s storage directory, then verify again.';
			default:
				return '';
		}
	}

	/**
	 * The fixed first line.
	 *
	 * @param string $outcome Outcome.
	 * @return string
	 */
	public static function headline( string $outcome ): string {
		switch ( $outcome ) {
			case self::PASSED:
				return 'Archive is intact.';
			case self::PASSED_PARTIAL:
				return 'Archive is intact as far as it was checked.';
			case self::FAILED:
				return 'Archive is damaged.';
			case self::INVALID:
				return 'Archive could not be read.';
			case self::CHANGED:
				return 'Archive changed while it was being verified.';
			case self::UNREADABLE:
				return 'Archive could not be checked on this server.';
			default:
				return 'Archive could not be verified.';
		}
	}
}
