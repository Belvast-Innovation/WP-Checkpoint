#!/bin/sh
# The integration suite, run from a directory of its own: never from the plugin's directory, which wp-env mounts
# from the developer's repository (read and write). A path that resolves against the working directory by mistake
# (an empty or relative one) then lands in a directory made for this run, not in the repository.
#
#   sh bin/test-integration.sh [phpunit arguments]
#
# Arguments go to PHPUnit; a relative --log-junit path is taken relative to the plugin's directory, as before. Used
# by `composer test:integration`, npm run test:integration and CI. Anything the suite leaves in its working directory
# is kept there and fails the run: a test wrote to a relative path. (WPCHECKPOINT_TEST_PHPUNIT names another PHPUnit,
# for this script's own test.)
PLUGIN=$(cd "$(dirname "$0")/.." && pwd)
PHPUNIT=${WPCHECKPOINT_TEST_PHPUNIT:-$PLUGIN/vendor/bin/phpunit}
WORK=$(mktemp -d "${TMPDIR:-/tmp}/wpcheckpoint-it.XXXXXX") || exit 1

ARGS=""
NEXT=""
for arg in "$@"; do
	if [ -n "$NEXT" ]; then
		case "$arg" in /*) ;; *) arg="$PLUGIN/$arg" ;; esac
		NEXT=""
	fi
	case "$arg" in
		--log-junit) NEXT=1 ;;
		--log-junit=*)
			value=${arg#--log-junit=}
			case "$value" in /*) ;; *) arg="--log-junit=$PLUGIN/$value" ;; esac
			;;
	esac
	ARGS="$ARGS '$(printf '%s' "$arg" | sed "s/'/'\\\\''/g")'"
done

cd "$WORK" || exit 1
eval "set -- $ARGS"
WPCHECKPOINT_TEST_SUITE=integration php "$PHPUNIT" -c "$PLUGIN/phpunit.xml.dist" --testsuite integration "$@"
STATUS=$?
cd / || exit 1
if ! rmdir "$WORK" 2>/dev/null; then
	echo "The suite left files in its working directory, $WORK (kept to be looked at): a test wrote to a relative path." >&2
	[ "$STATUS" -eq 0 ] && STATUS=1
fi
exit $STATUS
