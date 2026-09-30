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
# path. Used by `composer test:integration`, npm run test:integration and CI. A run during which files under src/ or
# tests/ changed is declared not valid and exits with status 70, whatever the suite said.
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
NAMED=""
WORK=""
READY=""
BEFORE=""

# The code the run tests: a fingerprint of every file under src/ and tests/, by content and name. PHP loads a class
# when it is first used, so a file changed during a run mixes two versions of the code in one result. Fails when a
# file cannot be read: no fingerprint, rather than one that leaves that file out.
fingerprint() {
	( cd "$PLUGIN" && sums=$(find src tests -type f -exec md5sum {} +) && printf '%s\n' "$sums" | LC_ALL=C sort | md5sum | cut -c1-32 )
}

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
		if [ -z "$READY" ]; then
			rmdir "$WORK/cwd" "$WORK" 2>/dev/null # Never used: whatever was made of it goes quietly.
		elif ! rmdir "$WORK/cwd" 2>/dev/null; then
			echo "The suite left files in its working directory, $WORK/cwd (kept to be looked at): a test wrote to a relative path." >&2
			[ "$rc" -eq 0 ] && rc=1
		elif ! rmdir "$WORK" 2>/dev/null; then
			echo "The run's temporary directory, $WORK/tmp, could not be removed entirely." >&2
		fi
	fi
	if [ -n "$BEFORE" ]; then
		if ! AFTER=$(fingerprint); then
			echo "This run is not valid: the files under src/ and tests/ could not be read again at its end, so it cannot be shown that they stayed as they were." >&2
			rc=70
		elif [ "$AFTER" != "$BEFORE" ]; then
			echo "This run is not valid: files under src/ or tests/ changed while it ran, so its result mixes two versions of the code. Run it again on code that stays as it is." >&2
			rc=70
		fi
	fi
	if [ -n "$NAMED" ]; then
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
if ! command -v md5sum >/dev/null 2>&1; then
	echo "md5sum is needed to tell whether src/ and tests/ changed during the run (coreutils, or BusyBox's md5sum)." >&2
	exit 1
fi
# Tried in a subshell first: a redirection that fails on exec ends the shell itself.
if ( exec 9>>"$LOCK" ) 2>/dev/null; then
	exec 9>>"$LOCK"
	WRITABLE=1
elif ( exec 9<"$LOCK" ) 2>/dev/null; then
	exec 9<"$LOCK" # Another user's lock file: locked all the same, without this run's name in it.
	WRITABLE=""
else
	echo "The lock file $LOCK can be neither written nor read; runs cannot be kept from overlapping." >&2
	exit 1
fi
if ! flock -n 9; then
	HOLDER=$(sed -n 1p "$LOCK" 2>/dev/null)
	SINCE=$(sed -n 2p "$LOCK" 2>/dev/null)
	echo "Another integration run is in progress (lock $LOCK, which names process ${HOLDER:-unknown}, started ${SINCE:-unknown}; a run that has only just started may not have written its name yet). Runs share the tests database; wait until it ends, or stop it (its PHPUnit too, if its shell was killed)." >&2
	exit 75
fi
HELD=1
if [ -s "$LOCK" ]; then
	echo "Taking over the lock of an integration run that ended without releasing it: process $(sed -n 1p "$LOCK" 2>/dev/null), started $(sed -n 2p "$LOCK" 2>/dev/null). Its run directory (wpcheckpoint-it.*) may be left in its temporary directory." >&2
fi
if [ -n "$WRITABLE" ]; then
	printf '%s\n%s\n' "$$" "$(date -u '+%Y-%m-%d %H:%M:%S UTC')" > "$LOCK"
	NAMED=1
fi

WORK=$(mktemp -d "${TMPDIR:-/tmp}/wpcheckpoint-it.XXXXXX") || { WORK=""; exit 1; }
mkdir "$WORK/cwd" "$WORK/tmp" || exit 1
READY=1
BEFORE=$(fingerprint) || { BEFORE=""; echo "The files under src/ and tests/ could not all be read; the run could not tell whether they change during it." >&2; exit 1; }

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
