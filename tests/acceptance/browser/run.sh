#!/usr/bin/env bash
# Backups tab acceptance in a real browser, on the wp-env development site.
#
#   MODULES=/path/with/node_modules tests/acceptance/browser/run.sh
#
# MODULES holds node_modules with playwright (the version of the image below)
# and axe-core: `npm install playwright@1.63.0 axe-core@4` in an empty directory.
# The browser runs in the Playwright image with the host network, so it
# reaches the site at http://localhost:9888. Development only: it deletes
# every backup and job of the development site.
set -euo pipefail

REPO="$(cd "$(dirname "$0")/../../.." && pwd)"
IMAGE="${IMAGE:-mcr.microsoft.com/playwright:v1.63.0-noble}"
: "${MODULES:?set MODULES to a directory with node_modules/playwright and node_modules/axe-core}"
export WP_ENV_PORT="${WP_ENV_PORT:-9888}" WP_ENV_TESTS_PORT="${WP_ENV_TESTS_PORT:-9889}"
cd "$REPO"

wp() { npx wp-env run cli --env-cwd=wp-content/plugins/wp-checkpoint wp "$@" 2>/dev/null; }
phase() { docker run --rm --network host -v "$MODULES:/w" -v "$REPO/tests/acceptance/browser:/s" -w /w -e NODE_PATH=/w/node_modules "$IMAGE" node /s/backups-tab.js "$@"; }
status() { wp wpcheckpoint job status "$1" | awk -F'\t' '$1=="status"{print $2}'; }

echo "== setup: no backups, no jobs, no estimate; a heavy directory that raises a question"
wp eval '
	global $wpdb;
	$dirs = WPCheckpoint\Plugin::instance()->directories();
	foreach ( glob( $dirs->backups() . "/*" ) as $f ) { if ( "index.php" !== basename( $f ) && is_file( $f ) ) { unlink( $f ); } }
	$wpdb->query( "DELETE FROM " . WPCheckpoint\Support\Schema::jobs_table() );
	WPCheckpoint\Support\Options::delete( WPCheckpoint\Backups\Estimate::OPTION );
	WPCheckpoint\Support\Options::delete( WPCheckpoint\Backups\Estimate::RATE_OPTION );
	WPCheckpoint\Jobs\Loopback::unschedule_all();
	$d = wp_upload_dir()["basedir"] . "/qa-heavy/node_modules/pkg";
	wp_mkdir_p( $d );
	foreach ( array( "one", "two" ) as $n ) { $h = fopen( "$d/$n.bin", "wb" ); ftruncate( $h, 30 * 1048576 ); fclose( $h ); }
	echo "ok\n";
'

phase login
# The generated acceptance tables (tests/acceptance/generate.php) are left out: they would only make it slow.
tables=$(wp db tables 'wp_acc_*' --all-tables --format=csv | tr -d '\r' || true)
echo "== S1a: empty state and the size estimate";   phase empty
echo "== S2-S4: create with options by keyboard, answer the question, highlighted result"; phase create "$tables"
echo "== S5: details, download, check";              phase details

echo "== closed page: start a database-only backup, close the page, let cron alone run it"
job=$(phase start-db "$tables" | sed -n 's/.*"job":"\([0-9]*\)".*/\1/p')
echo "job $job"
for i in $(seq 1 60); do
	s=$(status "$job"); echo "  cron run $i: $s"
	[ "$s" = completed ] && break
	# What a system cron does, without waiting for the minute: run this plugin's events.
	wp cron event run wpcheckpoint_job_tick >/dev/null || true
done
phase reopen "$job"

echo "== stalled: a job with no move for 11 minutes and nothing holding it"
job=$(phase start-db "$tables" | sed -n 's/.*"job":"\([0-9]*\)".*/\1/p')
wp eval "
	global \$wpdb;
	\$wpdb->update( WPCheckpoint\Support\Schema::jobs_table(), array( 'created_at' => time() - 660, 'updated_at' => time() - 660, 'progress_at' => time() - 660, 'lock_token' => '', 'locked_until' => 0 ), array( 'id' => $job ) );
	WPCheckpoint\Jobs\Loopback::unschedule( $job );
	echo \"ok\n\";
"
phase stalled "$job"

echo "== failed, final: the work directory is lost while the backup waits for its answer"
job=$(phase to-question | sed -n 's/.*"job":"\([0-9]*\)".*/\1/p')
wp eval "\$j = WPCheckpoint\Plugin::instance()->jobs()->find( $job ); \$w = WPCheckpoint\Jobs\Residue::work_dir( \$j->storage_path, \$j->id ); WPCheckpoint\Support\Deleter::delete_tree( dirname( \$w ), \$w, 100000 ); echo \"ok\n\";"
phase fail-answer "$job"
echo "== failed, temporary: the same block when the failure may pass"
wp eval "global \$wpdb; \$wpdb->update( WPCheckpoint\Support\Schema::jobs_table(), array( 'failure_kind' => 'temporary', 'work_expired_at' => 0 ), array( 'id' => $job ) ); echo \"ok\n\";"
phase failed "$job"
wp wpcheckpoint job cancel "$job" >/dev/null || true
wp eval "global \$wpdb; \$wpdb->delete( WPCheckpoint\Support\Schema::jobs_table(), array( 'id' => $job ) ); echo \"ok\n\";"

echo "== polling: a database-only backup with the large generated tables, driven by the browser"
phase polling

echo "== delete"; phase delete

echo "== teardown"
wp eval 'foreach ( WPCheckpoint\Plugin::instance()->job_actions()->active() as $j ) { WPCheckpoint\Plugin::instance()->job_actions()->cancel( $j->id ); } echo "ok\n";' || true
wp eval 'WPCheckpoint\Support\Deleter::delete_tree( wp_upload_dir()["basedir"], wp_upload_dir()["basedir"] . "/qa-heavy", 1000 ); echo "ok\n";' || true
