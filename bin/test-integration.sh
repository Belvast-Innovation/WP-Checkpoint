#!/bin/sh
# The integration suite, run from a directory of its own: never from the plugin's directory, which wp-env mounts
# from the developer's repository (read and write). A path that resolves against the working directory by mistake
# (an empty or relative one) then lands in a directory made for this run, not in the repository.
#
#   sh bin/test-integration.sh [phpunit arguments]
#
# The run gets a directory of its own, "{TMPDIR}/wpcheckpoint-it.XXXXXX": "cwd" is its working directory and "tmp"
# its temporary directory (TMPDIR), so two runs in one container never see each other's temporary entries (the
# leftover check, tests/Fixtures/Leftovers.php, looks at this one only).
#
# Arguments go to PHPUnit. The paths in them are taken relative to the plugin's directory, as before: a test file or
# directory, and the values of the options that name one (--log-*, --testdox-html, --testdox-text, --testdox-xml,
# --coverage-*, --cache-result-file, --bootstrap, --prepend, --list-tests-xml, --dump-xdebug-filter, --whitelist).
# --include-path, a list, is passed as given: name its directories in full. The configuration is phpunit.xml when
# there is one, else phpunit.xml.dist; a -c of your own is not supported.
#
# Anything the suite leaves in its working directory is kept there and fails the run: a test wrote to a relative
# path. Used by `composer test:integration`, npm run test:integration and CI.
#
# One run at a time: runs share the tests database, and a second one's start (the core test library reinstalls the
# tables; the leftover check removes an earlier run's) breaks the first. The lock, /tmp/wpcheckpoint-integration.lock in
# the container every run executes in, holds the process ID and start time of the run that has it; another run is
# refused while that process is alive and is an integration run (its ID reused by something else does not count), and
# takes over a lock whose run has died. Refused, the script exits with status 75. (WPCHECKPOINT_TEST_PHPUNIT and
# WPCHECKPOINT_TEST_LOCK name another PHPUnit and another lock, for this script's own test.)
PLUGIN=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
PHPUNIT=${WPCHECKPOINT_TEST_PHPUNIT:-$PLUGIN/vendor/bin/phpunit}
CONFIG=$PLUGIN/phpunit.xml
[ -f "$CONFIG" ] || CONFIG=$PLUGIN/phpunit.xml.dist
LOCK=${WPCHECKPOINT_TEST_LOCK:-/tmp/wpcheckpoint-integration.lock}

# Whether process $1 is alive and an integration run.
running() {
	[ -n "$1" ] && kill -0 "$1" 2>/dev/null || return 1
	if [ -r "/proc/$1/cmdline" ]; then
		tr '\000' ' ' < "/proc/$1/cmdline" | grep -q 'test-integration' || return 1
	fi
	return 0
}
# Create the lock, only if there is none (noclobber: one of two runs creates it).
take() {
	( set -C; printf '%s\n%s\n' "$$" "$(date -u '+%Y-%m-%d %H:%M:%S UTC')" > "$LOCK" ) 2>/dev/null
}
if ! take; then
	HOLDER=$(sed -n 1p "$LOCK" 2>/dev/null)
	SINCE=$(sed -n 2p "$LOCK" 2>/dev/null)
	if running "$HOLDER"; then
		echo "Another integration run is in progress: process $HOLDER, started $SINCE (lock $LOCK). Runs share the tests database; wait until it ends, or stop it." >&2
		exit 75
	fi
	echo "Taking over the lock of an integration run that is no longer running: process ${HOLDER:-unknown}, started ${SINCE:-unknown}." >&2
	rm -f -- "$LOCK"
	if ! take; then
		echo "Another integration run took the lock meanwhile: $(sed -n 1p "$LOCK" 2>/dev/null), started $(sed -n 2p "$LOCK" 2>/dev/null)." >&2
		exit 75
	fi
fi
# Released when this run ends, if it is still this run's.
release() {
	if [ "$(sed -n 1p "$LOCK" 2>/dev/null)" = "$$" ]; then
		rm -f -- "$LOCK"
	fi
}
trap release EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

WORK=$(mktemp -d "${TMPDIR:-/tmp}/wpcheckpoint-it.XXXXXX") || exit 1
mkdir "$WORK/cwd" "$WORK/tmp" || exit 1

# A path relative to the plugin's directory, made absolute.
absolute() {
	case "$1" in
		/*) printf '%s' "$1" ;;
		*) printf '%s' "$PLUGIN/$1" ;;
	esac
}

ARGS=""
NEXT="" # What the argument before asked of this one: "path" (make it absolute) or "value" (leave it).
for arg in "$@"; do
	if [ "$NEXT" = path ]; then
		arg=$(absolute "$arg")
		NEXT=""
	elif [ "$NEXT" = value ]; then
		NEXT=""
	else
		case "$arg" in
			--log-*=* | --testdox-html=* | --testdox-text=* | --testdox-xml=* | --coverage-*=* | --cache-result-file=* | --bootstrap=* | --list-tests-xml=* | --dump-xdebug-filter=* | --whitelist=* | --prepend=*)
				arg="${arg%%=*}=$(absolute "${arg#*=}")"
				;;
			--log-* | --testdox-html | --testdox-text | --testdox-xml | --cache-result-file | --coverage-clover | --coverage-cobertura | --coverage-crap4j | --coverage-html | --coverage-php | --coverage-xml | --coverage-filter | --coverage-cache | --bootstrap | --list-tests-xml | --dump-xdebug-filter | --whitelist | --prepend)
				NEXT=path
				;;
			--filter | --group | --exclude-group | --testsuite | --covers | --uses | -d | --printer | --test-suffix | --order-by | --random-order-seed | --columns | --loader | --repeat | --extensions | --testdox-group | --testdox-exclude-group | --include-path | --default-time-limit | --atleast-version | -c | --configuration)
				NEXT=value
				;;
			-*) ;;
			*)
				# A test file or directory, named as from the plugin's directory.
				if [ -e "$PLUGIN/$arg" ]; then
					arg=$(absolute "$arg")
				fi
				;;
		esac
	fi
	ARGS="$ARGS '$(printf '%s' "$arg" | sed "s/'/'\\\\''/g")'"
done

cd "$WORK/cwd" || exit 1
eval "set -- $ARGS"
TMPDIR="$WORK/tmp" WPCHECKPOINT_TEST_RUN_TMP="$WORK/tmp" WPCHECKPOINT_TEST_SUITE=integration php "$PHPUNIT" -c "$CONFIG" --testsuite integration "$@"
STATUS=$?
cd / || exit 1
# The run's temporary directory goes (what the suite's own leftovers were, the leftover check has already failed
# and named); its working directory only when nothing was left in it.
case "$WORK" in
	*/wpcheckpoint-it.*) rm -rf -- "$WORK/tmp" ;;
esac
if ! rmdir "$WORK/cwd" 2>/dev/null; then
	echo "The suite left files in its working directory, $WORK/cwd (kept to be looked at): a test wrote to a relative path." >&2
	[ "$STATUS" -eq 0 ] && STATUS=1
elif ! rmdir "$WORK" 2>/dev/null; then
	echo "The run's temporary directory, $WORK/tmp, could not be removed entirely." >&2
fi
exit $STATUS
