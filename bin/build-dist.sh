#!/usr/bin/env bash
# Build the plugin package in build/wp-checkpoint, then check what was built.
# The package holds the paths in SHIP and nothing else from the repository.
# It is what `npm run check:plugin` and the Plugin Check CI job inspect.
#
# The check runs on the built tree itself, with the same list: every top-level
# entry is one of SHIP, and nowhere in it is a symbolic link, a vendor
# directory (the plugin has no Composer dependencies at runtime) or a name
# beginning with a dot (.claude, .git, .DS_Store). A package that fails the
# check is reported entry by entry and the script exits with status 1.
#
#   bash bin/build-dist.sh               build, then check the package
#   bash bin/build-dist.sh --check DIR   check an already built package only
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="${ROOT}/build/wp-checkpoint"

# What the plugin ships. A directory is shipped with everything in it, as it is.
SHIP=(wp-checkpoint.php uninstall.php readme.txt LICENSE src assets)

failed=0
refuse() {
	echo "Not allowed in the package: ${1} (${2})" >&2
	failed=1
}

# Check the package in $1; status 1 when anything in it is not allowed.
check_package() {
	local dir="$1" path name entry shipped
	if [ ! -d "${dir}" ] || [ -L "${dir}" ]; then
		echo "No package to check: ${dir}" >&2
		return 1
	fi
	failed=0
	shopt -s dotglob nullglob
	for path in "${dir}"/*; do
		name="${path##*/}"
		shipped=0
		for entry in "${SHIP[@]}"; do
			if [ "${name}" = "${entry}" ]; then
				shipped=1
			fi
		done
		if [ "${shipped}" -eq 0 ]; then
			refuse "${name}" "not one of the paths the plugin ships"
		fi
	done
	shopt -u dotglob nullglob
	while IFS= read -r -d '' path; do
		refuse "${path#"${dir}"/}" "a symbolic link"
	done < <(find "${dir}" -mindepth 1 -type l -print0)
	while IFS= read -r -d '' path; do
		refuse "${path#"${dir}"/}" "a vendor directory: the plugin has no Composer dependencies at runtime"
	done < <(find "${dir}" -mindepth 1 -name vendor -print0 -prune)
	while IFS= read -r -d '' path; do
		refuse "${path#"${dir}"/}" "a name beginning with a dot"
	done < <(find "${dir}" -mindepth 1 -name '.*' -print0 -prune)
	if [ "${failed}" -ne 0 ]; then
		echo "The package in ${dir} is not one to ship (see above)." >&2
		return 1
	fi
	return 0
}

if [ "${1:-}" = "--check" ]; then
	if [ -z "${2:-}" ]; then
		echo "Usage: bash bin/build-dist.sh --check DIR" >&2
		exit 2
	fi
	check_package "$2"
	echo "Checked ${2}"
	exit 0
fi

rm -rf "${DEST}"
mkdir -p "${DEST}"
for entry in "${SHIP[@]}"; do
	if [ ! -e "${ROOT}/${entry}" ] && [ ! -L "${ROOT}/${entry}" ]; then
		echo "Not in the repository, so the package cannot be built: ${entry}" >&2
		exit 1
	fi
	# Copied as it is: a link stays a link (and is refused below), nothing is followed.
	rsync -a "${ROOT}/${entry}" "${DEST}/"
done

check_package "${DEST}"
echo "Built ${DEST}"
