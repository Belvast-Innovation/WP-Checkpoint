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
# path. Used by `composer test:integration`, npm run test:integration and CI. (WPCHECKPOINT_TEST_PHPUNIT names
# another PHPUnit, for this script's own test.)
PLUGIN=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
PHPUNIT=${WPCHECKPOINT_TEST_PHPUNIT:-$PLUGIN/vendor/bin/phpunit}
CONFIG=$PLUGIN/phpunit.xml
[ -f "$CONFIG" ] || CONFIG=$PLUGIN/phpunit.xml.dist
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
