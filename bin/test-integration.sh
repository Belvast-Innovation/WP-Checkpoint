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
# tables; the leftover check removes an earlier run's) breaks the first. The lock is a flock() on
# /tmp/wpcheckpoint-integration.lock in the container every run executes in, taken on a descriptor PHPUnit inherits:
# the kernel holds it while the run's shell or its PHPUnit is alive, and lets it go when both are gone, however they
# ended. The file names the run that has it (process ID and start time) and is emptied when the run ends: a name left
# in it is a run that ended without that (killed), and is said so when the next run takes the lock. Refused, the script
# exits with status 75. On any exit, a signal included, the lock is released and the run's directory cleaned up.
# (WPCHECKPOINT_TEST_PHPUNIT and WPCHECKPOINT_TEST_LOCK name another PHPUnit and another lock, for this script's own
# test.)
PLUGIN=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
PHPUNIT=${WPCHECKPOINT_TEST_PHPUNIT:-$PLUGIN/vendor/bin/phpunit}
CONFIG=$PLUGIN/phpunit.xml
[ -f "$CONFIG" ] || CONFIG=$PLUGIN/phpunit.xml.dist
LOCK=${WPCHECKPOINT_TEST_LOCK:-/tmp/wpcheckpoint-integration.lock}
HELD=""
WORK=""

# On any exit: the run's directory (its temporary directory goes; its working directory only when empty, else the run
# fails: a test wrote to a relative path), then the lock.
on_exit() {
	rc=$?
	trap - EXIT
	if [ -n "$WORK" ]; then
		cd / || :
		case "$WORK" in
			*/wpcheckpoint-it.*) rm -rf -- "$WORK/tmp" ;;
		esac
		if ! rmdir "$WORK/cwd" 2>/dev/null; then
			echo "The suite left files in its working directory, $WORK/cwd (kept to be looked at): a test wrote to a relative path." >&2
			[ "$rc" -eq 0 ] && rc=1
		elif ! rmdir "$WORK" 2>/dev/null; then
			echo "The run's temporary directory, $WORK/tmp, could not be removed entirely." >&2
		fi
	fi
	if [ -n "$HELD" ]; then
		: > "$LOCK" # This run's name out; the kernel lets the lock go as the descriptor closes.
	fi
	exit "$rc"
}
trap on_exit EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

if ! command -v flock >/dev/null 2>&1; then
	echo "flock is needed to keep integration runs from overlapping (util-linux, or BusyBox's flock)." >&2
	exit 1
fi
exec 9>>"$LOCK" || exit 1
if ! flock -n 9; then
	echo "Another integration run is in progress: process $(sed -n 1p "$LOCK" 2>/dev/null), started $(sed -n 2p "$LOCK" 2>/dev/null) (lock $LOCK). Runs share the tests database; wait until it ends, or stop it (its PHPUnit too, if its shell was killed)." >&2
	exit 75
fi
HELD=1
if [ -s "$LOCK" ]; then
	echo "Taking over the lock of an integration run that ended without releasing it: process $(sed -n 1p "$LOCK" 2>/dev/null), started $(sed -n 2p "$LOCK" 2>/dev/null)." >&2
fi
printf '%s\n%s\n' "$$" "$(date -u '+%Y-%m-%d %H:%M:%S UTC')" > "$LOCK"

WORK=$(mktemp -d "${TMPDIR:-/tmp}/wpcheckpoint-it.XXXXXX") || { WORK=""; exit 1; }
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
exit $?
