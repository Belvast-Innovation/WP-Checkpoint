#!/usr/bin/env bash
#
# Run a command in a wp-env container, trying again only when wp-env failed before it started the command because a
# connection it makes on every run (it asks api.wordpress.org about WordPress versions) was closed before it was
# established:
#
#   bin/wp-env-run.sh <arguments of npx wp-env run>
#   bin/wp-env-run.sh tests-cli --env-cwd=wp-content/plugins/wp-checkpoint sh -c '... sh bin/test-integration.sh ...'
#
# That failure ("Socket closed before the connection was established", ERR_SOCKET_CLOSED_BEFORE_CONNECTION) comes
# before wp-env says it is starting the command ("Starting '...' on the ... container"), so nothing ran: it is tried
# again after a wait. Any other failure, and any failure after the command started (whatever its output says), is
# not tried again: the command's own output stays as it was and its exit status is this script's.
#
# Standard error of wp-env and of the command is shown on standard output (the decision reads both, in order).
#
#   WPCHECKPOINT_WPENV_ATTEMPTS        tries at most (default 6)
#   WPCHECKPOINT_WPENV_RETRY_SECONDS   wait between tries (default 20)
#
# Exit status: the last try's (wp-env's, or the command's once it started); 69 when every try failed that way before
# the command started (nothing was run).

set -uo pipefail

attempts="${WPCHECKPOINT_WPENV_ATTEMPTS:-6}"
delay="${WPCHECKPOINT_WPENV_RETRY_SECONDS:-20}"
log="$(mktemp "${TMPDIR:-/tmp}/wpcheckpoint-wp-env-run.XXXXXX")" || exit 1
trap 'rm -f "$log"' EXIT

for (( i = 1; i <= attempts; i++ )); do
	npx wp-env run "$@" 2>&1 | tee "$log"
	status=${PIPESTATUS[0]}
	if [ "$status" -eq 0 ] || grep -q "Starting '" "$log" || ! grep -q 'ERR_SOCKET_CLOSED_BEFORE_CONNECTION' "$log"; then
		exit "$status"
	fi
	echo "wp-env did not start the command: a connection was closed before it was established (try $i of $attempts)." >&2
	if [ "$i" -lt "$attempts" ]; then
		sleep "$delay"
	fi
done
echo "wp-env never started the command ($attempts tries, each failed before it started); nothing was run." >&2
exit 69
