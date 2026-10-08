<?php
/**
 * The target database cannot take part of the backup as it is.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * The database the restore writes to cannot take something the backup uses, as it is: retrying fails the same way on
 * this server, so the job fails for good (Runner::failure_of()). It carries what is missing (of which kind) and the
 * tables that use it; its message, made from these, names the first SHOWN_TABLES tables and how many there are in all,
 * and the Runner writes the full list to the job's log. Thrown by nothing yet: the restore's collation check (the
 * collation PR) is to be the first.
 */
final class TargetIncompatible extends \RuntimeException {

	/** Collations the server does not know. */
	const COLLATION = 'collation';

	/** How many of the tables the message names; the rest are counted. */
	const SHOWN_TABLES = 5;

	/**
	 * What is missing: one of the kinds above.
	 *
	 * @var string
	 */
	private $kind;

	/**
	 * The names that are missing.
	 *
	 * @var string[]
	 */
	private $missing;

	/**
	 * The backup's tables that use them, in the backup's order.
	 *
	 * @var string[]
	 */
	private $tables;

	/**
	 * Constructor.
	 *
	 * @param string   $kind    What is missing (COLLATION).
	 * @param string[] $missing The names that are missing.
	 * @param string[] $tables  The backup's tables that use them.
	 */
	public function __construct( string $kind, array $missing, array $tables ) {
		$this->kind    = $kind;
		$this->missing = self::names( $missing );
		$this->tables  = self::names( $tables );
		parent::__construct( self::summary( $this->kind, $this->missing, $this->tables ) );
	}

	/**
	 * What is missing (COLLATION).
	 *
	 * @return string
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * The names that are missing.
	 *
	 * @return string[]
	 */
	public function missing(): array {
		return $this->missing;
	}

	/**
	 * The backup's tables that use them.
	 *
	 * @return string[]
	 */
	public function tables(): array {
		return $this->tables;
	}

	/**
	 * The message: what the server cannot take, the first SHOWN_TABLES tables that use it and how many in all.
	 *
	 * @param string   $kind    What is missing.
	 * @param string[] $missing The names that are missing.
	 * @param string[] $tables  The tables that use them.
	 * @return string
	 */
	public static function summary( string $kind, array $missing, array $tables ): string {
		$count = count( $tables );
		$shown = implode( ', ', array_slice( $tables, 0, self::SHOWN_TABLES ) );
		if ( $count > self::SHOWN_TABLES ) {
			$shown = sprintf(
				/* translators: 1: the first table names, 2: how many tables there are in all */
				__( '%1$s and others (%2$d tables in all; the job log lists them all)', 'wp-checkpoint' ),
				$shown,
				$count
			);
		}
		return sprintf(
			/* translators: 1: what the database server cannot take (such as "the collation x"), 2: table names */
			__( 'This database server cannot take %1$s, which the backup uses. Tables that use it: %2$s.', 'wp-checkpoint' ),
			self::what( $kind, $missing ),
			'' === $shown ? __( 'none named', 'wp-checkpoint' ) : $shown
		);
	}

	/**
	 * What is missing, in words.
	 *
	 * @param string   $kind    What is missing.
	 * @param string[] $missing The names.
	 * @return string
	 */
	private static function what( string $kind, array $missing ): string {
		$names = implode( ', ', $missing );
		if ( self::COLLATION === $kind ) {
			/* translators: %s: collation names */
			return sprintf( _n( 'the collation %s', 'the collations %s', count( $missing ), 'wp-checkpoint' ), $names );
		}
		return $names;
	}

	/**
	 * Names as a list of distinct strings, in their order.
	 *
	 * @param array<mixed> $names Names.
	 * @return string[]
	 */
	private static function names( array $names ): array {
		return array_values( array_unique( array_map( 'strval', $names ) ) );
	}
}
