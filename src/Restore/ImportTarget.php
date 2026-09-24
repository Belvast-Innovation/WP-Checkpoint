<?php
/**
 * One table of a restore: its names and what its chunks must contain.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * What ChunkReader needs to judge and rewrite the statements of one table:
 * the table's name in the backup (every statement must be about it), its
 * temporary and final names and position (for the table name and the
 * constraint names), the temporary names of every other table the restore
 * creates (foreign keys to them are pointed at those), and, once the
 * table's CREATE TABLE has been read, the columns every INSERT must list.
 */
final class ImportTarget {

	/**
	 * Name in the backup.
	 *
	 * @var string
	 */
	public $table;

	/**
	 * Temporary name.
	 *
	 * @var string
	 */
	public $temporary;

	/**
	 * Final name.
	 *
	 * @var string
	 */
	public $final_name;

	/**
	 * Position in the backup (0-based).
	 *
	 * @var int
	 */
	public $number;

	/**
	 * Constraint names.
	 *
	 * @var ConstraintNames
	 */
	public $names;

	/**
	 * Temporary name of every table the restore creates, by its name in the backup.
	 *
	 * @var array<string, string>
	 */
	public $restored;

	/**
	 * Columns every INSERT must list, in order; null while unknown (the reader then accepts any list and reports it).
	 *
	 * @var string[]|null
	 */
	public $columns;

	/**
	 * Constructor.
	 *
	 * @param string                $table     Name in the backup.
	 * @param string                $temporary Temporary name.
	 * @param string                $final_name Final name.
	 * @param int                   $number    Position in the backup.
	 * @param ConstraintNames       $names     Constraint names.
	 * @param array<string, string> $restored  Temporary names by name in the backup.
	 * @param string[]|null         $columns   Columns every INSERT must list.
	 */
	public function __construct( string $table, string $temporary, string $final_name, int $number, ConstraintNames $names, array $restored, $columns = null ) {
		$this->table      = $table;
		$this->temporary  = $temporary;
		$this->final_name = $final_name;
		$this->number     = $number;
		$this->names      = $names;
		$this->restored   = $restored;
		$this->columns    = $columns;
	}
}
