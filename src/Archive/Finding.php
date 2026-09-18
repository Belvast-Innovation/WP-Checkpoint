<?php
/**
 * One thing the verifier found.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Archive;

/**
 * Kinds: missing (declared but absent), corrupt (present but wrong bytes),
 * malformed (structure the reader cannot accept), unverified (could not be
 * checked from this input), unsupported (the layout is not one this
 * verifier can check, which is not the same as damage), changed (the
 * archive was written to while it was being verified, so nothing read
 * after that is conclusive), environment (this server could not read or
 * write what the check needs; says nothing about the archive). Locations name
 * volumes by ordinal, never by file name (the name carries the site slug).
 */
final class Finding {

	const MISSING     = 'missing';
	const CORRUPT     = 'corrupt';
	const MALFORMED   = 'malformed';
	const UNVERIFIED  = 'unverified';
	const UNSUPPORTED = 'unsupported';
	const CHANGED     = 'changed';
	const ENVIRONMENT = 'environment';

	/**
	 * Fields.
	 *
	 * @var array<string, mixed>
	 */
	private $fields;

	/**
	 * Constructor.
	 *
	 * @param string               $phase   Phase.
	 * @param string               $kind    Kind constant.
	 * @param string               $message Fixed text.
	 * @param array<string, mixed> $where   volume (1-based), block (0-based), table, chunk, entry, line, field.
	 */
	public function __construct( string $phase, string $kind, string $message, array $where = array() ) {
		$this->fields = array(
			'phase'   => $phase,
			'kind'    => $kind,
			'message' => $message,
			'volume'  => isset( $where['volume'] ) ? (int) $where['volume'] : null,
			'block'   => isset( $where['block'] ) ? (int) $where['block'] : null,
			'table'   => isset( $where['table'] ) ? (string) $where['table'] : null,
			'chunk'   => isset( $where['chunk'] ) ? (int) $where['chunk'] : null,
			'entry'   => isset( $where['entry'] ) ? (string) $where['entry'] : null,
			'line'    => isset( $where['line'] ) ? (int) $where['line'] : null,
			'field'   => isset( $where['field'] ) ? (string) $where['field'] : null,
		);
	}

	/**
	 * From a stored array (the cursor).
	 *
	 * @param array<string, mixed> $data Fields.
	 * @return Finding
	 */
	public static function from_array( array $data ): Finding {
		return new self( (string) ( $data['phase'] ?? '' ), (string) ( $data['kind'] ?? self::CORRUPT ), (string) ( $data['message'] ?? '' ), $data );
	}

	/**
	 * Fields as stored in the verifier's state (not cleaned; for the cursor only).
	 *
	 * @return array<string, mixed>
	 */
	public function raw(): array {
		return $this->fields;
	}

	/**
	 * Kind.
	 *
	 * @return string
	 */
	public function kind(): string {
		return (string) $this->fields['kind'];
	}

	/**
	 * Fields, strings passed through $clean.
	 *
	 * @param callable $clean function( string ): string for every text field.
	 * @return array<string, mixed>
	 */
	public function to_array( callable $clean ): array {
		$out = $this->fields;
		foreach ( array( 'message', 'table', 'entry', 'field' ) as $key ) {
			if ( is_string( $out[ $key ] ) ) {
				$out[ $key ] = $clean( $out[ $key ] );
			}
		}
		return $out;
	}

	/**
	 * One line of text.
	 *
	 * @param callable $clean function( string ): string.
	 * @return string
	 */
	public function to_text( callable $clean ): string {
		// kind and phase are fixed vocabulary from this class and the verifier; only a forged cursor could
		// put other text there, and a forged cursor means database write access already (see ArchiveVerifier).
		$f     = $this->to_array( $clean );
		$where = array();
		// A block belongs to the entry when there is one (its content chunk), otherwise to the volume (its container chunk).
		$block = null !== $f['block'] ? ', block ' . $f['block'] : '';
		if ( null !== $f['volume'] ) {
			$where[] = 'volume ' . $f['volume'] . ( null === $f['entry'] ? $block : '' );
		}
		if ( null !== $f['table'] ) {
			$where[] = 'table ' . $f['table'] . ( null !== $f['chunk'] ? ', chunk ' . $f['chunk'] : '' );
		}
		if ( null !== $f['entry'] ) {
			$where[] = 'entry ' . $f['entry'] . $block;
		}
		if ( null !== $f['line'] ) {
			$where[] = 'line ' . $f['line'];
		}
		if ( null !== $f['field'] ) {
			$where[] = 'field ' . $f['field'];
		}
		return strtoupper( (string) $f['kind'] ) . ' [' . $f['phase'] . '] ' . $f['message'] . ( array() === $where ? '' : ' (' . implode( '; ', $where ) . ')' );
	}
}
