#!/usr/bin/env bash
# The checks a commit waits on, one after another, each with pipefail: a step that is a pipeline fails when any
# command in it fails (not only the last: "lint | tail" passes without it whatever lint said), and the first step that
# fails ends the gate with that step's status; the steps after it are not run. Used as "bin/gate.sh && git commit ...".
#
# Usage: bin/gate.sh [<step> ...]. Each step is one shell command line. Without steps: the lint, the analysis and the
# unit suite. Each step's output goes to a file of its own in the test output directory (WPCHECKPOINT_TEST_OUTPUT, by
# default ../wp-checkpoint-test-output beside the repository), gate-<n>.txt, and its last lines are shown when it fails.
#
# Exit status: 0 when every step passed, else the status of the step that failed.

set -u
REPO="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${WPCHECKPOINT_TEST_OUTPUT:-$REPO/../wp-checkpoint-test-output}"
mkdir -p "$OUT" || exit 1
if [ "$#" -eq 0 ]; then
	set -- \
		'vendor/bin/phpcs' \
		'vendor/bin/phpstan analyse --memory-limit=1G --no-progress' \
		'WPCHECKPOINT_TEST_SUITE=unit vendor/bin/phpunit --testsuite unit'
fi
n=0
for step in "$@"; do
	n=$((n + 1))
	( cd "$REPO" && bash -o pipefail -c "$step" ) > "$OUT/gate-$n.txt" 2>&1
	status=$?
	if [ "$status" -ne 0 ]; then
		echo "gate: step $n failed with status $status: $step" >&2
		tail -n 20 "$OUT/gate-$n.txt" >&2
		exit "$status"
	fi
	echo "gate: step $n passed: $step"
done
exit 0
