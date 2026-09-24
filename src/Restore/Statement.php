<?php
/**
 * One statement of a chunk, judged and ready to run.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * Made by ChunkReader for the statement it has just read and handed to the
 * executor at once; nothing collects them.
 */
final class Statement {

	const SET    = 'set';
	const DROP   = 'drop';
	const CREATE = 'create';
	const INSERT = 'insert';

	/**
	 * Kind.
	 *
	 * @var string
	 */
	public $kind;

	/**
	 * The SQL to run (about the temporary table), without the semicolon.
	 *
	 * @var string
	 */
	public $sql;

	/**
	 * Offset in the chunk file just after the statement's semicolon.
	 *
	 * @var int
	 */
	public $end;

	/**
	 * INSERT: the columns it lists; CREATE: the stored columns it defines.
	 *
	 * @var string[]
	 */
	public $columns = array();

	/**
	 * INSERT: number of rows.
	 *
	 * @var int
	 */
	public $rows = 0;

	/**
	 * CREATE: the definition.
	 *
	 * @var CreateTable|null
	 */
	public $create;

	/**
	 * CREATE: the names given to its constraints (see ConstraintNames).
	 *
	 * @var array<int, array{kind: string, name: string, intended: string, shortened: bool}>
	 */
	public $constraints = array();

	/**
	 * Constructor.
	 *
	 * @param string $kind Kind.
	 * @param string $sql  SQL.
	 * @param int    $end  Offset after the semicolon.
	 */
	public function __construct( string $kind, string $sql, int $end ) {
		$this->kind = $kind;
		$this->sql  = $sql;
		$this->end  = $end;
	}
}
