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
 * - HEADS, CHUNKS: directories the preflight and the import extract chunks
 *   into, one at a time.
 * - STAGING: where the files are staged (the site's directories as the
 *   files preflight resolved them, the groups staged, the random part of
 *   the staging roots' names), fixed once.
 * - KEYS: a directory of name-key buckets (NameClashes), appended under
 *   committed lengths.
 * - UNMAPPED: the backup's paths that belong to no content group and are
 *   not restored (one JSON line each, appended under a committed length).
 * - SOURCES: what the check checked (each volume's size and modification
 *   time, the depth), to judge a later hash mismatch by.
 * - STAGE_PLAN: which of the backup's plugin directories are this plugin
 *   (skipped), and which only carry its name (left out).
 * - REPORT: what staging did not write, and why (one JSON line each,
 *   appended under a committed length).
 * - PLUGIN_LIST: the running plugin's files to copy into the staged
 *   plugins, one JSON line each.
 */
final class RestoreFiles {

	const MANIFEST    = 'restore-manifest.json';
	const PLAN        = 'restore-plan.json';
	const INDEX       = 'restore-database.index.jsonl';
	const DEFINITIONS = 'restore-definitions.jsonl';
	const HEADS       = 'restore-heads';
	const CHUNKS      = 'restore-chunks';
	const STAGING     = 'restore-staging.json';
	const KEYS        = 'restore-keys';
	const UNMAPPED    = 'restore-unmapped.jsonl';
	const SOURCES     = 'restore-sources.json';
	const STAGE_PLAN  = 'restore-stage-plan.json';
	const REPORT      = 'restore-stage-report.jsonl';
	const PLUGIN_LIST = 'restore-plugin-files.jsonl';

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
