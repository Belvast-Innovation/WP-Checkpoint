<?php
/**
 * The files a restore's steps hand each other through the work directory.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * All of them live in the job's work directory, which the engine removes
 * with the job's temporary tables; none is ever read from anywhere else.
 *
 * - MANIFEST: the copy of the backup's manifest the verify step made; the
 *   only manifest later steps read.
 * - PLAN: the table plan (TablePlan::to_array()) and the restore's random
 *   part, written whole once by the preflight.
 * - INDEX: database.index.jsonl extracted from the last volume.
 * - DEFINITIONS: what each planned table's CREATE TABLE defines (one JSON
 *   line per table, appended by the preflight under a committed length).
 * - CONSTRAINTS: the names given to the imported tables' constraints and
 *   the names they are meant to end with (one JSON line per table).
 * - HEADS, CHUNKS: directories the preflight and the import extract chunks
 *   into, one at a time.
 */
final class RestoreFiles {

	const MANIFEST    = 'restore-manifest.json';
	const PLAN        = 'restore-plan.json';
	const INDEX       = 'restore-database.index.jsonl';
	const DEFINITIONS = 'restore-definitions.jsonl';
	const CONSTRAINTS = 'restore-constraints.jsonl';
	const HEADS       = 'restore-heads';
	const CHUNKS      = 'restore-chunks';

	/**
	 * A file or directory of the work directory.
	 *
	 * @param string $work Work directory.
	 * @param string $name One of the constants.
	 * @return string
	 */
	public static function path( string $work, string $name ): string {
		return $work . DIRECTORY_SEPARATOR . $name;
	}
}
