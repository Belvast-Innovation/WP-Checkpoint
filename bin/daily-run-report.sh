#!/usr/bin/env bash
# Says what the daily integration run in random order ended as, in one issue: opened by the first failed run, added to
# by the next ones, closed by the first run that passes. Run by the "report" job of .github/workflows/random-order.yml.
#
# The issue is this script's own only when all three hold: it was opened by the workflow's account (AUTHOR), it has
# the label kept for it (LABEL), and it has the title (TITLE). An issue anyone else opened under the same title, or
# one without the label, is never commented on and never closed: with no issue of its own, a failed run opens one.
#
# Environment: RESULT (the run's result), RUN_URL, RUN_ID, GH_REPO, GITHUB_SHA, and gh's GH_TOKEN. TITLE, LABEL and
# AUTHOR have the defaults below. Needs gh.
#
# Exit status: 0 when the issue is as the result asks, else the status of the command that failed.

set -u -o pipefail
: "${RESULT:?}" "${RUN_URL:?}" "${RUN_ID:?}" "${GH_REPO:?}" "${GITHUB_SHA:?}"
TITLE="${TITLE:-The daily integration run in random order fails on main}"
LABEL="${LABEL:-daily-run}"
AUTHOR="${AUTHOR:-github-actions[bot]}"

# Open issues with the label, asked of the server; then the author, the label and the title are compared here, on one
# line per issue: number, author, labels (comma between them), title. Pull requests are issues to this API: left out.
listed="$(gh api --paginate -X GET "repos/${GH_REPO}/issues" -f state=open -f labels="$LABEL" -f per_page=100 \
	--jq '.[] | select(has("pull_request") | not) | [.number, .user.login, (.labels | map(.name) | join(",")), .title] | @tsv')" || exit $?
number="$(printf '%s\n' "$listed" | TITLE="$TITLE" LABEL="$LABEL" AUTHOR="$AUTHOR" awk -F '\t' '
	$2 == ENVIRON["AUTHOR"] && $4 == ENVIRON["TITLE"] {
		count = split($3, labels, ",")
		for (i = 1; i <= count; i++) {
			if (labels[i] == ENVIRON["LABEL"]) { print $1; exit }
		}
	}')" || exit $?

if [ "$RESULT" = "success" ]; then
	if [ -n "$number" ]; then
		gh issue close "$number" --comment "The daily run passed on ${GITHUB_SHA}: ${RUN_URL}" || exit $?
	fi
	exit 0
fi

body="$(mktemp)" || exit 1
log="$(mktemp)" || exit 1
trap 'rm -f "$body" "$log"' EXIT
{
	echo "The daily integration run in random order ended as \"${RESULT}\" on ${GITHUB_SHA}."
	echo
	echo "Run: ${RUN_URL} (each job's summary names its seed; start the workflow by hand with it to repeat the order)."
	echo
	echo '```'
	# What failed, by the lines PHPUnit numbers and the seeds, job by job (the run is not over while this job runs:
	# its jobs' logs are read one by one); the whole log is in the run.
	for job in $(gh api "repos/${GH_REPO}/actions/runs/${RUN_ID}/jobs" --jq '.jobs[] | select(.conclusion == "failure") | .id'); do
		gh api "repos/${GH_REPO}/actions/jobs/${job}" --jq '.name'
		gh api "repos/${GH_REPO}/actions/jobs/${job}/logs" > "$log" || echo "  (its log could not be read)"
		grep -E 'SEED: [0-9]+|[0-9]+\) [A-Za-z\\]+Test::|Fatal error|Tests: [0-9]+' "$log" | sed -E 's/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:.]+Z //' | awk '!seen[$0]++' | head -n 30 | sed 's/^/  /' || true
	done
	echo '```'
} > "$body"

if [ -n "$number" ]; then
	gh issue comment "$number" --body-file "$body" || exit $?
else
	# The label is made when it is not there yet (and left as it is when it is: making it then fails, and the list
	# says whether it is there).
	if ! made="$(gh label create "$LABEL" --description "The daily integration run in random order" --color BFD4F2 2>&1)"; then
		labels="$(gh label list --search "$LABEL" --limit 100 --json name --jq '.[].name')" || exit $?
		if ! printf '%s\n' "$labels" | grep -xF -- "$LABEL" > /dev/null; then
			echo "The label $LABEL is not there and could not be made: $made" >&2
			exit 1
		fi
	fi
	gh issue create --title "$TITLE" --label "$LABEL" --body-file "$body" || exit $?
fi
