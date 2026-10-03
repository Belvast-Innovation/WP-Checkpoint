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
 * - PREFIX_REPORT: what the table prefix rewrite reported, copied and
 *   removed (PrefixRewriteStep), rewritten whole after each unit.
 * - SUSPECTS: where staging stood each time it continued after a takeover
 *   (the run that lost the job may have written on from there), rewritten
 *   whole; the final check hashes the files from there again.
 * - SWAP_CHECK_TREE: the directories the final check still has to count
 *   in the staged tree (one JSON line each, appended under a committed
 *   length).
 * - SWAP_LIVE: the site's tables as the final check listed them (a JSON
 *   string per line, appended under a committed length).
 * - SWAP_PLAN: which attempt of the swap plan the final check wrote, and
 *   how many entries it has, written whole once the plan is complete.
 * - INCOMING: the backup's tables another installation in the same
 *   database may use (IncomingTables), by kind, written whole by the
 *   preflight before it asks about them; the questions name this file.
 * - LINKED: the content groups whose directory is a link to a directory
 *   outside this site, with their targets (LinkedTargets), written whole by
 *   the files preflight before it asks about them; the question names this
 *   file.
 * - RECLAIM: what an earlier attempt of the restore recorded that a new one
 *   does not use (its temporary tables and staging roots), written whole by
 *   the preflight before anything on it is deleted (PreviousAttempt).
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

	const PREFIX_REPORT = 'restore-prefix-report.json';

	const SUSPECTS        = 'restore-stage-suspects.json';
	const SWAP_CHECK_TREE = 'restore-swap-check-tree.jsonl';
	const SWAP_PLAN       = 'restore-swap-plan.json';
	const SWAP_LIVE       = 'restore-swap-live.jsonl';

	const INCOMING = 'restore-incoming-tables.json';

	const RECLAIM = 'restore-reclaim.json';

	const LINKED = 'restore-linked-targets.json';

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
